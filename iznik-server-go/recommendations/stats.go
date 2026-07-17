package recommendations

import (
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// trackedSources are the recommendation surfaces whose funnel we report. The
// similar-posts strip tags its impressions "similar_posts"; the wanted→offer
// panel tags "wanted_match".
var trackedSources = []string{"similar_posts", "wanted_match"}

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

// HoldoutStats compares reply behaviour of the 10% holdout (userid % 10 == 0,
// who never see recommendations) against everyone else, over message-page-active
// users, so we can read whether showing recommendations changes reply rate.
type HoldoutStats struct {
	HoldoutUsers        int64   `json:"holdoutUsers"`
	HoldoutReplies      int64   `json:"holdoutReplies"`
	HoldoutRepliesPerU  float64 `json:"holdoutRepliesPerUser"`
	ShownUsers          int64   `json:"shownUsers"`
	ShownReplies        int64   `json:"shownReplies"`
	ShownRepliesPerUser float64 `json:"shownRepliesPerUser"`
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
	db := database.DBConn

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
	since := time.Now().AddDate(0, 0, -days).Format("2006-01-02 15:04:05")

	// Per-day impressions + clicks per source.
	var funnelRows []struct {
		D           string `gorm:"column:d"`
		Source      string `gorm:"column:source"`
		Impressions int64  `gorm:"column:impressions"`
		Clicks      int64  `gorm:"column:clicks"`
	}
	db.Raw(`SELECT DATE(timestamp) d, source,
	               COUNT(*) impressions,
	               SUM(pageview = 1) clicks
	        FROM messages_likes
	        WHERE type = 'View' AND source IN ? AND timestamp >= ?
	        GROUP BY d, source`, trackedSources, since).Scan(&funnelRows)

	// Per-day attributed replies per source: an opened (pageview=1) tagged view
	// followed within 7 days by an Interested reply to that post by the same user.
	var replyRows []struct {
		D       string `gorm:"column:d"`
		Source  string `gorm:"column:source"`
		Replies int64  `gorm:"column:replies"`
	}
	db.Raw(`SELECT DATE(ml.timestamp) d, ml.source, COUNT(DISTINCT cm.id) replies
	        FROM messages_likes ml
	        JOIN chat_messages cm ON cm.refmsgid = ml.msgid AND cm.userid = ml.userid
	             AND cm.type = 'Interested'
	             AND cm.date BETWEEN ml.timestamp AND ml.timestamp + INTERVAL 7 DAY
	        WHERE ml.source IN ? AND ml.pageview = 1 AND ml.timestamp >= ?
	        GROUP BY d, ml.source`, trackedSources, since).Scan(&replyRows)

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

	// Holdout comparison.
	var cohortRows []struct {
		Holdout int   `gorm:"column:holdout"`
		Users   int64 `gorm:"column:users"`
		Replies int64 `gorm:"column:replies"`
	}
	db.Raw(`SELECT (ml.userid % 10 = 0) holdout,
	               COUNT(DISTINCT ml.userid) users,
	               COUNT(DISTINCT cm.id) replies
	        FROM messages_likes ml
	        LEFT JOIN chat_messages cm ON cm.userid = ml.userid AND cm.type = 'Interested'
	             AND cm.date >= ?
	        WHERE ml.type = 'View' AND ml.userid IS NOT NULL AND ml.timestamp >= ?
	        GROUP BY holdout`, since, since).Scan(&cohortRows)

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
