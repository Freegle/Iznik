package recommendations

import (
	"context"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// statsDeadline bounds the whole Stats request. Each chunked statement is
// individually capped with MAX_EXECUTION_TIME, but a wide window issues many
// statements, and c.Context() alone would never fire (fasthttp only cancels it
// on server shutdown) - so without this an abandoned request would keep
// stepping through the remaining day-windows to completion.
const statsDeadline = 60 * time.Second

// trackedSources are the recommendation surfaces whose funnel we report. The
// similar-posts strip tags its impressions "similar_posts"; the wanted→offer
// panel tags "wanted_match".
var trackedSources = []string{"similar_posts", "wanted_match"}

// The two impression tags the message-page strip writes, one per arm of the random
// holdout (SimilarPosts.vue): holdoutShownSource when a member is shown the strip,
// holdoutControlSource when the same eligible view is held out and shown nothing.
// Recording both is what lets the comparison below split the population, now that arm
// assignment is a per-view RNG rather than anything derivable from the user id.
const holdoutShownSource = "similar_posts"
const holdoutControlSource = "similar_posts_holdout"

// DailyPoint is one day's funnel for a source.
type DailyPoint struct {
	Date        string `json:"date"`
	Impressions int64  `json:"impressions"`
	Clicks      int64  `json:"clicks"`
	Replies     int64  `json:"replies"`
}

// SourceStats is the whole-window funnel for one recommendation source.
type SourceStats struct {
	Source            string       `json:"source"`
	Impressions       int64        `json:"impressions"`
	Clicks            int64        `json:"clicks"`
	CTR               float64      `json:"ctr"`
	AttributedReplies int64        `json:"attributedReplies"`
	Daily             []DailyPoint `json:"daily"`
}

// HoldoutStats compares reply behaviour of the holdout (a random ~10% of eligible
// logged-in, non-mod views that are shown nothing) against the members who were shown
// the similar-posts strip, so we can read whether showing recommendations changes
// reply rate. Both arms are drawn from the same eligible population - a view that
// actually had recommendations to show - and each records an impression, so the two
// cohorts come straight from those recorded tags rather than from the user id.
//
// Known caveat: the holdout is only enforced for the similar-posts strip
// (SimilarPosts.vue). WantedMatches.vue has no holdout check, so holdout members
// still see the wanted-to-offer panel and any replies it produces land in the
// holdout's totals. wanted_match is 176 impressions all-time so the effect is
// currently negligible, but the control group is not strictly clean until that
// component gates on the holdout too.
type HoldoutStats struct {
	HoldoutUsers        int64   `json:"holdoutUsers"`
	HoldoutReplies      int64   `json:"holdoutReplies"`
	HoldoutRepliesPerU  float64 `json:"holdoutRepliesPerUser"`
	ShownUsers          int64   `json:"shownUsers"`
	ShownReplies        int64   `json:"shownReplies"`
	ShownRepliesPerUser float64 `json:"shownRepliesPerUser"`
}

// funnelRow is one (day, source) group from the Q12 impressions/clicks scan
// of messages_likes, whether read from a single statement or merged from
// per-day chunks.
type funnelRow struct {
	D           string `gorm:"column:d"`
	Source      string `gorm:"column:source"`
	Impressions int64  `gorm:"column:impressions"`
	Clicks      int64  `gorm:"column:clicks"`
}

// replyRow is one (day, source) group from the Q6 attributed-replies scan.
type replyRow struct {
	D       string `gorm:"column:d"`
	Source  string `gorm:"column:source"`
	Replies int64  `gorm:"column:replies"`
}

// windowBounds is a half-open [Start, End) timestamp range, pre-formatted for
// use as SQL bind parameters, spanning at most one calendar day.
type windowBounds struct {
	Start string
	End   string
}

// Stats returns the recommendation funnel (impressions → clicks → attributed
// replies) per source, plus the holdout comparison, for the ModTools sysadmin
// "Recommendations" tab.
//
// A recommendation impression is a source-tagged messages_likes View row (the
// card was shown). A click is that row reaching pageview=1 (the card was
// opened). An attributed reply is an Interested chat message to the opened post
// by the same user within 7 days of the click.
//
// GET /api/modtools/recommendations/stats?days=30 — Support or Admin only.
func Stats(c *fiber.Ctx) error {
	ctx, cancel := context.WithTimeout(c.Context(), statsDeadline)
	defer cancel()
	db := database.DBConn.WithContext(ctx)

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	days := c.QueryInt("days", 30)
	if days < 1 {
		days = 30
	}
	if days > 365 {
		days = 365
	}
	now := time.Now()
	sinceTime := now.AddDate(0, 0, -days)
	since := sinceTime.Format("2006-01-02 15:04:05")

	// These queries aggregate messages_likes (~75M rows). They are fast ONLY with
	// the messages_likes_source_ts_user (source, timestamp, userid) index; without
	// it the DISTINCT-userid cohort picks a plan that walks the whole userid index
	// and hangs the request. Cap each with MAX_EXECUTION_TIME so a missing index
	// degrades the panel (an aborted query leaves its slice empty and sets
	// `degraded`) rather than timing the whole request out.
	degraded := false

	// Q12 and Q6 below both filter messages_likes mostly on source+timestamp,
	// which messages_likes_source_ts_user covers up to pageview/type, so every
	// matching row still needs a non-covering lookup for those two columns. Over
	// the full requested window (up to 365 days) that lookup count alone is
	// enough to turn one statement into a multi-minute scan that stalls Galera's
	// applier. Both are therefore run as one statement PER CALENDAR DAY
	// (dayWindows) instead of one statement over the whole window - each
	// statement's matching-row count is then capped at a single day's worth -
	// and the per-day (date, source) rows are merged back together in Go
	// (mergeFunnelRows/mergeReplyRows). Because a window never straddles a
	// calendar day, merging is a safety net, not the source of correctness: each
	// window already contributes exactly the rows a single unchunked query would
	// group under that day.
	windows := dayWindows(sinceTime, now)

	// Per-day impressions + clicks per source.
	// NOT converted here (raw): master replaced the single unchunked GORM
	// query (site 22036e3caf64) with a per-calendar-day chunked loop over
	// `windows` - see the comment above this block on why (an unchunked scan
	// over up to 365 days was enough to stall Galera's applier). Re-doing
	// the ORM conversion per-chunk was not attempted under a merge; left raw
	// for a properly tested follow-up.
	var funnelRows []funnelRow
	for _, w := range windows {
		var chunk []funnelRow
		if err := db.Raw(`SELECT /*+ MAX_EXECUTION_TIME(10000) */ DATE(timestamp) d, source,
		               COUNT(*) impressions,
		               SUM(pageview = 1) clicks
		        FROM messages_likes
		        WHERE type = 'View' AND source IN ? AND timestamp >= ? AND timestamp < ?
		        GROUP BY d, source`, trackedSources, w.Start, w.End).Scan(&chunk).Error; err != nil {
			// Fail the whole metric, not just this day: a partially-summed
			// funnel would look complete while silently missing days. Empty
			// + degraded is the documented contract for an aborted query.
			degraded = true
			funnelRows = nil
			break
		}
		funnelRows = append(funnelRows, chunk...)
	}
	funnelRows = mergeFunnelRows(funnelRows)

	// Per-day attributed replies per source: an opened (pageview=1) tagged view
	// followed within 7 days by an Interested reply to that post by the same user.
	// NOT converted here (raw): same reasoning as funnelRows above - master
	// replaced the single unchunked GORM query (site eba122e9abad) with a
	// per-day chunked loop.
	var replyRows []replyRow
	for _, w := range windows {
		var chunk []replyRow
		if err := db.Raw(`SELECT /*+ MAX_EXECUTION_TIME(10000) */ DATE(ml.timestamp) d, ml.source, COUNT(DISTINCT cm.id) replies
		        FROM messages_likes ml
		        JOIN chat_messages cm ON cm.refmsgid = ml.msgid AND cm.userid = ml.userid
		             AND cm.type = 'Interested'
		             AND cm.date BETWEEN ml.timestamp AND ml.timestamp + INTERVAL 7 DAY
		        WHERE ml.source IN ? AND ml.pageview = 1 AND ml.timestamp >= ? AND ml.timestamp < ?
		        GROUP BY d, ml.source`, trackedSources, w.Start, w.End).Scan(&chunk).Error; err != nil {
			// As above: all-or-nothing per metric.
			degraded = true
			replyRows = nil
			break
		}
		replyRows = append(replyRows, chunk...)
	}
	replyRows = mergeReplyRows(replyRows)

	// Assemble per-source aggregates keyed by (source -> date -> point).
	bySource := make(map[string]*SourceStats)
	for _, s := range trackedSources {
		bySource[s] = &SourceStats{Source: s, Daily: []DailyPoint{}}
	}
	dayIndex := make(map[string]map[string]*DailyPoint) // source -> date -> point

	point := func(source, date string) *DailyPoint {
		if dayIndex[source] == nil {
			dayIndex[source] = make(map[string]*DailyPoint)
		}
		p := dayIndex[source][date]
		if p == nil {
			p = &DailyPoint{Date: date}
			dayIndex[source][date] = p
		}
		return p
	}

	for _, r := range funnelRows {
		if bySource[r.Source] == nil {
			continue
		}
		p := point(r.Source, r.D)
		p.Impressions = r.Impressions
		p.Clicks = r.Clicks
		bySource[r.Source].Impressions += r.Impressions
		bySource[r.Source].Clicks += r.Clicks
	}
	for _, r := range replyRows {
		if bySource[r.Source] == nil {
			continue
		}
		p := point(r.Source, r.D)
		p.Replies = r.Replies
		bySource[r.Source].AttributedReplies += r.Replies
	}

	// Rebuild each source's Daily slice from the point index (sorted by date) and
	// compute CTR.
	out := make([]SourceStats, 0, len(trackedSources))
	for _, s := range trackedSources {
		ss := bySource[s]
		daily := make([]DailyPoint, 0, len(dayIndex[s]))
		for _, p := range dayIndex[s] {
			daily = append(daily, *p)
		}
		sortDaily(daily)
		ss.Daily = daily
		if ss.Impressions > 0 {
			ss.CTR = float64(ss.Clicks) / float64(ss.Impressions) * 100
		}
		out = append(out, *ss)
	}

	// Holdout comparison, over the two recorded arms of the random holdout. A member is
	// in the shown cohort if any of their eligible views recorded the shown tag, and in
	// the holdout cohort only if every one was held out (MAX(...) = 0) - so a member who
	// was shown on one post and held out on another counts as shown, and the arms stay
	// disjoint. Only views that actually had recommendations record either tag, so both
	// sides are the like-with-like population the strip gates.
	//
	// The two sides are aggregated independently rather than joined directly.
	// messages_likes LEFT JOIN chat_messages on userid alone, with no message
	// correlation, fans every view row out across every Interested message that user
	// sent; aggregating replies per user first keeps the join small and correct.
	//
	// The cohort is computed once as a CTE and then JOINed into chat_messages
	// BEFORE the reply-count GROUP BY, rather than aggregating all of
	// chat_messages first and joining the (much smaller) cohort on afterwards.
	// chat_messages has no index led by `type`, so an unconditional
	// `GROUP BY userid` over it must fully materialize via a full scan of the
	// whole userid_2 (userid, date, refmsgid, type) index before the cohort
	// restriction can be applied. Joining the cohort in first lets MySQL seek
	// userid_2 per cohort user instead (ref access, cohort is historically a few
	// thousand users at most vs. tens of millions of chat_messages rows).
	var cohortRows []struct {
		Holdout int   `gorm:"column:holdout"`
		Users   int64 `gorm:"column:users"`
		Replies int64 `gorm:"column:replies"`
	}
	// NOT converted here (raw): master replaced the derived-table LEFT JOIN
	// (site c9e06d962d78) with a CTE that joins the cohort into chat_messages
	// BEFORE the reply-count GROUP BY - see the comment above this block for
	// why (lets MySQL seek chat_messages per cohort user via userid_2 instead
	// of a full scan). Re-doing the .Table()-text conversion for the CTE form
	// was not attempted under a merge; left raw for a properly tested
	// follow-up.
	if err := db.Raw(`WITH cohort AS (
	            SELECT userid, MAX(source = ?) = 0 holdout FROM messages_likes
	            WHERE source IN (?, ?) AND timestamp >= ?
	            GROUP BY userid)
	        SELECT /*+ MAX_EXECUTION_TIME(10000) */ cohort.holdout,
	               COUNT(*) users,
	               COALESCE(SUM(r.replies), 0) replies
	        FROM cohort
	        LEFT JOIN (SELECT cm.userid, COUNT(*) replies FROM chat_messages cm
	                   JOIN cohort ON cohort.userid = cm.userid
	                   WHERE cm.type = 'Interested' AND cm.date >= ?
	                   GROUP BY cm.userid) r ON r.userid = cohort.userid
	        GROUP BY cohort.holdout`, holdoutShownSource, holdoutShownSource, holdoutControlSource, since, since).Scan(&cohortRows).Error; err != nil {
		degraded = true
	}

	var holdout HoldoutStats
	for _, r := range cohortRows {
		if r.Holdout == 1 {
			holdout.HoldoutUsers = r.Users
			holdout.HoldoutReplies = r.Replies
		} else {
			holdout.ShownUsers = r.Users
			holdout.ShownReplies = r.Replies
		}
	}
	if holdout.HoldoutUsers > 0 {
		holdout.HoldoutRepliesPerU = float64(holdout.HoldoutReplies) / float64(holdout.HoldoutUsers)
	}
	if holdout.ShownUsers > 0 {
		holdout.ShownRepliesPerUser = float64(holdout.ShownReplies) / float64(holdout.ShownUsers)
	}

	return c.JSON(fiber.Map{
		"ret":     0,
		"status":  "Success",
		"days":    days,
		"sources": out,
		"holdout": holdout,
		// True when a query hit MAX_EXECUTION_TIME (the messages_likes stats index
		// is not deployed yet); the figures above are then partial/empty. The panel
		// can surface this rather than showing the aborted counts as real zeros.
		"degraded": degraded,
	})
}

// sortDaily orders points ascending by date (simple insertion sort — daily
// windows are small, <= 365 points).
func sortDaily(points []DailyPoint) {
	for i := 1; i < len(points); i++ {
		for j := i; j > 0 && points[j-1].Date > points[j].Date; j-- {
			points[j-1], points[j] = points[j], points[j-1]
		}
	}
}

// dayWindows splits [since, until) into consecutive calendar-day-aligned
// windows, so DATE(timestamp) is constant within each window and a query run
// per window can never split one calendar day's rows across two chunks. That
// alignment holds regardless of whether this process's timezone matches the
// DB session's: every interior boundary is a midnight wall-clock value, which
// formats to a "... 00:00:00" string, and MySQL both compares these bounds and
// evaluates DATE(timestamp) in the same session timezone - so each window
// covers exactly one session-timezone calendar day whatever day Go thinks it
// is. (This matters because the attributed-replies query dedups COUNT(DISTINCT)
// per window; a day straddling two windows could double-count.) The first
// window is clipped to `since` (which need not itself sit on a day boundary);
// the last is clipped to `until`. Returns nil if since is not before until.
func dayWindows(since, until time.Time) []windowBounds {
	if !since.Before(until) {
		return nil
	}
	const layout = "2006-01-02 15:04:05"
	loc := since.Location()
	dayStart := time.Date(since.Year(), since.Month(), since.Day(), 0, 0, 0, 0, loc)

	var windows []windowBounds
	for dayStart.Before(until) {
		next := dayStart.AddDate(0, 0, 1)
		start := dayStart
		if start.Before(since) {
			start = since
		}
		end := next
		if end.After(until) {
			end = until
		}
		windows = append(windows, windowBounds{
			Start: start.Format(layout),
			End:   end.Format(layout),
		})
		dayStart = next
	}
	return windows
}

// mergeFunnelRows combines per-window funnel rows into at most one row per
// (date, source), summing impressions and clicks. Each window is day-aligned
// so it already contributes at most one row per source, but merging by key
// (rather than a plain concat) keeps the result correct - and matching what a
// single unchunked query would return - even if that ever stops being true.
func mergeFunnelRows(rows []funnelRow) []funnelRow {
	type key struct{ d, source string }
	merged := make(map[key]*funnelRow, len(rows))
	order := make([]key, 0, len(rows))
	for _, r := range rows {
		k := key{r.D, r.Source}
		if existing, ok := merged[k]; ok {
			existing.Impressions += r.Impressions
			existing.Clicks += r.Clicks
		} else {
			rc := r
			merged[k] = &rc
			order = append(order, k)
		}
	}
	out := make([]funnelRow, 0, len(order))
	for _, k := range order {
		out = append(out, *merged[k])
	}
	return out
}

// mergeReplyRows combines per-window reply rows into at most one row per
// (date, source), summing replies. See mergeFunnelRows for why merging by key
// (rather than a plain concat) is the safe default even though day-aligned
// windows only ever produce one row per key each.
func mergeReplyRows(rows []replyRow) []replyRow {
	type key struct{ d, source string }
	merged := make(map[key]*replyRow, len(rows))
	order := make([]key, 0, len(rows))
	for _, r := range rows {
		k := key{r.D, r.Source}
		if existing, ok := merged[k]; ok {
			existing.Replies += r.Replies
		} else {
			rc := r
			merged[k] = &rc
			order = append(order, k)
		}
	}
	out := make([]replyRow, 0, len(order))
	for _, k := range order {
		out = append(out, *merged[k])
	}
	return out
}
