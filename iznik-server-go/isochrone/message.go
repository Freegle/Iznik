package isochrone

import (
	"sort"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/browsecount"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/driving"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// BrowseDistanceUnlimited is the "no limit" sentinel for the nearby feed's distance filter,
// mirroring the client's Number.MAX_SAFE_INTEGER default for settings.browseMaxDistance. Any
// resolved limit at or above this means "do not filter — the server's own reach extent
// already governs", so the fast, unfiltered count/feed path is used.
const BrowseDistanceUnlimited = 9007199254740991 // Number.MAX_SAFE_INTEGER

// authorReachCapWhere is the OUTBOUND half of the distance preference - see
// utils.AuthorReachCapWhere for the full story. It lives in utils so that every reader of the
// reach universe (this feed, its unread-count badge, and browse-scoped message search) shares
// the one clause and cannot drift apart.
const authorReachCapWhere = utils.AuthorReachCapWhere

// reachCandidateRow is the intermediate scan target for both the reach arm
// and the viewer's-own-posts arm of Messages: a post's identity/visibility
// columns plus the raw ingredients (views, replies, reach polygon/origin)
// needed to compute its rippling relevance score. Both arms' queries alias
// their columns to exactly these names so one struct/one scoring path serves
// both.
type reachCandidateRow struct {
	Lat        float64 `gorm:"column:lat"`
	Lng        float64 `gorm:"column:lng"`
	ID         uint64  `gorm:"column:id"`
	Successful bool    `gorm:"column:successful"`
	Promised   bool    `gorm:"column:promised"`
	Groupid    uint64  `gorm:"column:groupid"`
	Type       string  `gorm:"column:type"`
	// Fromuser is the post's author (messages.fromuser). When it equals the viewer, the
	// post is flagged as theirs (MessageSummary.Mine) so the client can pin the viewer's
	// own recent posts to the top of every browse sort order (Discourse 9933).
	Fromuser uint64 `gorm:"column:fromuser"`
	// Arrival is the messages_spatial arrival, which the reach engine bumps
	// forward each time the post ripples into a new group — so it tracks "when
	// did this most recently expand", NOT the original post time. It feeds the
	// relevance score's freshness term (that IS what we want there) and the
	// server-side tie-break, but it must NOT drive "Newest posted": ordering by
	// it floats a days-old post to the top of the feed the moment its reach grows
	// again (Discourse 9844). Use Posted for anything member-facing about "when
	// was this posted".
	Arrival time.Time `gorm:"column:arrival"`
	// Posted is the ORIGINAL post arrival (messages.arrival), stable across
	// rippling. It is what the client's "Newest posted" sort and the card's time
	// badge mean, so exposing it lets the feed order agree with the badge.
	Posted time.Time `gorm:"column:posted"`
	// VisibleSince is the earliest this post could have been seen: the oldest arrival
	// across the groups it is live on. It is the ONE clock the feed uses - both the
	// "Newest posted" order and the card's time badge - so the list can never contradict
	// the dates printed on it.
	//
	// It moves for the two reasons a post legitimately becomes available later than it was
	// written: a repost (the giver re-offering it, which updates the group row) and a ripple
	// into a further group. Ordering by it means a repost lifts the post back up, which is
	// the point of reposting.
	//
	// LIMITATION: this is the oldest arrival across ALL the post's groups, not just the ones
	// this viewer can see, which would need their membership set threading into the query.
	VisibleSince time.Time `gorm:"column:visiblesince"`
	Unseen       bool      `gorm:"column:unseen"`
	Views        int64     `gorm:"column:views"`
	Replies      int64     `gorm:"column:replies"`
	// ReachLat/ReachLng/ReachWKT describe the post's rippling_reach row (the
	// origin the reach grew from, and the current reach polygon as WKT).
	// Empty/zero when the post has no reach row (own-posts arm only; the
	// main reach arm INNER JOINs rippling_reach so these are always
	// populated there) — ReachRadiusMetres falls back to the default extent.
	ReachLat float64 `gorm:"column:reach_lat"`
	ReachLng float64 `gorm:"column:reach_lng"`
	ReachWKT string  `gorm:"column:reach_wkt"`
	// ReachCells is the post's stored cell grid, selected ONLY on the
	// degraded path (reachProbe non-nil) where the SQL conjunct was just the
	// outer-bound superset and each row must be probed in Go.
	ReachCells []byte `gorm:"column:reach_cells"`
}

// blurredDistanceMiles blurs this post's real coordinates (roadblur.RoadBlur, deterministic —
// the same post always yields the same blurred point, and the SAME point the full message
// record exposes, so the feed summary and the card badge can never disagree) and returns the
// great-circle distance from the viewer to that BLURRED point, in miles, alongside the blurred
// point itself. This is the SINGLE place that computes "how far away is this post": both the
// feed (toSummary, for the exposed `distance` field and the score's `close` term) and the
// count's distance filter (nearbyCount) call it, so the badge and the list can never disagree
// about which posts are within a given limit — a real bug class this replaces (two
// independently-written distance calcs drifting at the boundary).
func (r reachCandidateRow) blurredDistanceMiles(viewerLat, viewerLng float64) (blurLat, blurLng, distanceMiles float64) {
	blurLat, blurLng = roadblur.RoadBlur(r.Lat, r.Lng, utils.BLUR_USER)
	distanceMiles = utils.Haversine(viewerLat, viewerLng, blurLat, blurLng)
	return
}

// toSummary scores this candidate and returns the client-facing
// MessageSummary. Privacy: the post's coordinates are blurred FIRST, and the
// exposed distance and the score's closeness term are both computed from
// the BLURRED point — never the real one — so neither field can be used to
// triangulate a post's true location any more precisely than the existing
// blurred lat/lng already allow. distanceMiles (Distance) and the metres
// figure fed into Score are the same underlying measurement, just converted,
// so the client's distance slider and the server's ordering agree.
func (r reachCandidateRow) toSummary(viewerLat, viewerLng float64, w ScoreWeights, env ScoreEnv, myid uint64) message.MessageSummary {
	blurLat, blurLng, distanceMiles := r.blurredDistanceMiles(viewerLat, viewerLng)
	distanceMetres := distanceMiles * milesToMetres

	reachMetres := ReachRadiusMetres(r.ReachLat, r.ReachLng, r.ReachWKT, env.DefaultReachM)

	ageHours := time.Since(r.Arrival).Hours()
	if ageHours < 0 {
		ageHours = 0
	}

	// Home-group anchoring is not yet implemented (mirrors the digest and
	// the /rippling preview, both of which pass homeGroup=false today; its
	// weight defaults to 0 so it has no effect either way).
	comps := Score(distanceMetres, reachMetres, ageHours, int(r.Views), int(r.Replies), false, w, env)

	return message.MessageSummary{
		ID:           r.ID,
		Successful:   r.Successful,
		Promised:     r.Promised,
		Groupid:      r.Groupid,
		Type:         r.Type,
		Arrival:      r.Arrival,
		Posted:       r.Posted,
		VisibleSince: r.VisibleSince,
		Lat:          blurLat,
		Lng:          blurLng,
		Unseen:       r.Unseen,
		Distance:     distanceMiles,
		Score:        comps.Total,
		Mine:         r.Fromuser != 0 && r.Fromuser == myid,
	}
}

// fetchReachCandidates runs the reach-arm query — open posts whose rippling-out reach
// polygon currently covers the viewer — and returns the raw scoring/distance ingredients for
// each. This is the SINGLE source of "what's in reach" for the nearby view: Messages (the
// feed, unseenOnly=false — every in-reach post, seen or not, since the client buckets on the
// `unseen` field) and nearbyCount's distance-filtered path (unseenOnly=true — matching the
// badge's existing "unseen only" semantics) both call it, so feed and count cannot drift on
// membership OR on the columns each candidate's score/distance is derived from.
func fetchReachCandidates(db *gorm.DB, myid uint64, latlng utils.LatLng, unseenOnly bool) []reachCandidateRow {
	// reach_wkt is the BOUNDING-BOX envelope of the reach's outer bound
	// (ST_Envelope), never an exact geometry. (The old exact display polygons
	// were up to ~1.25MB of WKT each, which shipped tens of MB per browse
	// load and timed clients out.) The WKT is only consumed by
	// ReachRadiusMetres (the score's 'close' term), which takes the farthest
	// vertex from the origin; the envelope's 5 points give that extent (a
	// small, uniform over-estimate) for ~100 bytes.
	// Visibility is unaffected: the WHERE still tests containment exactly
	// (spatial-index id list, or the outer-bound + cells-probe degraded
	// path - see reachContainmentSQL).
	var candidates []reachCandidateRow
	query, probe := reachCandidateQuery(db, myid, latlng, unseenOnly)
	query.
		Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
			"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
			"ms.msgtype AS type, m.fromuser AS fromuser, ms.arrival, m.arrival AS posted, "+
			"COALESCE((SELECT MIN(mgv.arrival) FROM messages_groups mgv WHERE mgv.msgid = ms.msgid AND mgv.deleted = 0), m.arrival) AS visiblesince, "+
			"CASE WHEN ml.msgid IS NULL AND ms.id > "+
			strconv.FormatUint(browseClearedWatermark(db, myid), 10)+
			" THEN 1 ELSE 0 END AS unseen, "+
			"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = ms.msgid AND mlv.type = ?), 0) AS views, "+
			"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = ms.msgid AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
			"rr.lat AS reach_lat, rr.lng AS reach_lng, "+reachExtentSelect(db, probe),
			utils.MESSAGE_LIKES_VIEW, utils.CHAT_MESSAGE_INTERESTED).
		Scan(&candidates)

	return filterProbed(candidates, probe)
}

// reachExtentSelect picks how the reach extent (reach_wkt, the score's
// 'close' denominator) is read, and whether the row carries its cells for the
// degraded probe. While the legacy polygon exists its envelope is used,
// exactly as before; afterwards the OUTER BOUND's envelope stands in - the
// same extent within the bound's 0.002-degree buffer, read from a ~19KB
// simplified vector instead of a megabyte polygon, and only ever consumed as
// a radius over-estimate (ReachRadiusMetres).
func reachExtentSelect(db *gorm.DB, probe *reachProbe) string {
	sel := reachEnvelopeExpr(db) + " AS reach_wkt"
	if probe != nil {
		sel += ", rr.polygon_cells AS reach_cells"
	}
	return sel
}

// reachEnvelopeExpr is the reach-extent envelope: the outer bound's
// envelope (a stored superset of the reach, as designed).
func reachEnvelopeExpr(db *gorm.DB) string {
	return "ST_AsText(ST_Envelope(rr.outer_bound))"
}

// filterProbed applies the degraded-path cells probe to fetched candidates;
// a nil probe returns them untouched.
func filterProbed(cands []reachCandidateRow, probe *reachProbe) []reachCandidateRow {
	if probe == nil {
		return cands
	}
	kept := cands[:0]
	for _, c := range cands {
		if probe.keep(c.ID, c.ReachCells) {
			c.ReachCells = nil // not needed downstream; drop the blob early
			kept = append(kept, c)
		}
	}
	return kept
}

// reachCandidatePoints is the count-shaped slice of the reach arm: the SAME membership
// (joins + WHERE, via reachCandidateQuery) as the feed, but selecting ONLY the post
// coordinates the blurred-Haversine distance filter needs. The badge poll runs this every
// 60s per active member with a saved browseMaxDistance, and it used to run the FULL
// fetchReachCandidates - per-row views/replies correlated subqueries and the polygon
// envelope, none of which a COUNT consumes - at ~849ms a call, a steady CPU tax on the
// write node (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md finding 2).
func reachCandidatePoints(db *gorm.DB, myid uint64, latlng utils.LatLng) []reachCandidateRow {
	var candidates []reachCandidateRow
	query, probe := reachCandidateQuery(db, myid, latlng, true)
	sel := "ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, ms.msgid AS id"
	if probe != nil {
		sel += ", rr.polygon_cells AS reach_cells"
	}
	query.Select(sel).Scan(&candidates)
	return filterProbed(candidates, probe)
}

// ClearCount clears the member's browse unread count in one call.
//
// It exists because there was no way to clear the count except to scroll every post into
// view: "Mark seen" could only name the posts the browser had loaded, and the ordinary
// backlog is ~1,000 (Discourse 10055). It deliberately does NOT write messages_likes View
// rows - the member has not viewed these posts, and a View row is an impression that feeds
// the view count posters see and the recommendation funnels. It moves one watermark instead.
//
// The watermark is the highest messages_spatial row that exists right now, so everything
// currently in any feed falls under it, and anything appearing afterwards gets a higher id
// and counts normally. It is view-independent on purpose: "I have cleared up to here" does
// not depend on whether they were looking at nearby or mygroups.
//
// @Summary Clear the browse unread count
// @Description Marks the member's whole browse feed as cleared, without needing the client to enumerate posts.
// @Tags messages
// @Success 200 {object} map[string]interface{} "Success response"
// @Failure 401 {object} map[string]string "Not logged in"
// @Router /messages/clearcount [post]
func ClearCount(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// MAX over the primary key, so this is an index lookup rather than a scan.
	var highest uint64
	db.Table("messages_spatial").Select("COALESCE(MAX(id), 0)").Row().Scan(&highest)

	database.RetryExec(db,
		"INSERT INTO browse_cleared (userid, spatialid) VALUES (?, ?) "+
			"ON DUPLICATE KEY UPDATE spatialid = ?, timestamp = NOW()",
		myid, highest, highest)

	// The badge is remembered for a few seconds (see the browsecount package). The drop to
	// zero is how the member knows this worked, so forget it rather than let it stand.
	browsecount.Invalidate(myid)

	return c.JSON(fiber.Map{
		"success": true,
	})
}

// browseClearedWatermark is the messages_spatial.id this member has cleared their browse
// count up to, or 0 if they never have - an absent row is the right state for everyone who
// has not pressed the button.
//
// Clearing moves this rather than writing a messages_likes View row per post: a View row is
// an impression, feeding the view count posters see and the recommendation funnels, and the
// ordinary member is sitting on ~1,000 unseen posts they have plainly not looked at.
//
// The axis is messages_spatial.id, not arrival and not msgid, because both of those are
// stamped when the post was WRITTEN: a post Pending when the member cleared and approved
// afterwards carries a backdated value, would fall under the watermark, and would never be
// counted again. The spatial row is created when the post enters the feed.
func browseClearedWatermark(db *gorm.DB, myid uint64) uint64 {
	var cleared uint64

	if myid > 0 {
		// No row leaves cleared at its zero value, which is what "cleared nothing" means.
		db.Table("browse_cleared").Select("spatialid").Where("userid = ?", myid).Row().Scan(&cleared)
	}

	return cleared
}

// reachCandidateQuery composes the reach arm's FROM/JOINs/WHERE - the single definition of
// "which open posts is this viewer inside the reach of". The feed (fetchReachCandidates),
// the distance-limited badge walk (reachCandidatePoints) and the fast unlimited badge COUNT
// (nearbyCount) all build on it, so none of them can drift on membership: a post the feed
// shows is a post the badge counts, held-for-moderation reaches stay out of both, and the
// author's outbound cap binds everywhere.
func reachCandidateQuery(db *gorm.DB, myid uint64, latlng utils.LatLng, unseenOnly bool) (*gorm.DB, *reachProbe) {
	unseenFilter := ""
	if unseenOnly {
		// Unseen = no impression AND above the cleared watermark. Inlined rather than bound
		// because whereArgs below is positional and this fragment precedes reachWhere.
		unseenFilter = "AND ml.msgid IS NULL AND ms.id > " +
			strconv.FormatUint(browseClearedWatermark(db, myid), 10) + " "
	}

	reachWhere, pointArgs, probe := reachOrOverflowSQL(db, myid, latlng.Lng, latlng.Lat)

	// Two independent shape axes -
	// unseenFilter (a plain bool toggle) and reachWhere (a live-DB-gated
	// choice between the legacy exact-polygon test and the sandwich-bounds
	// form, see reachContainmentSQL/rippling.ReachBoundsReady) - are composed
	// exactly as the original raw SQL was: one concatenated WHERE string,
	// passed to a SINGLE Where() call. Splitting this into multiple Where()
	// calls would trip GORM's own clause.Where wrapping (clause/where.go
	// buildExprs): once more than one Where expression is being combined, any
	// fragment whose text contains "AND"/"OR" gets wrapped in its own extra
	// paren pair - which both reachWhere and authorReachCapWhere do - so it
	// would diverge from the golden. Proven (both reachWhere shapes) by the
	// retired ormharness's reachcap_test.go and test/orm_batchc_test.go (both
	// removed in d22ba1d6c).
	whereSQL := "ms.successful = 0 " +
		unseenFilter +
		// held = the reach was frozen because the origin copy was pulled back
		// to Pending (member reports / Back to Pending). Every batch-side reach
		// consumer already skips held rows; without this filter the reported
		// post kept appearing in the nearby browse feed (Discourse 9862).
		"AND rr.status != 'held' " +
		reachWhere +
		// Outbound cap: only show this post to viewers within the author's chosen distance.
		authorReachCapWhere
	whereArgs := append([]interface{}{}, pointArgs...)
	whereArgs = append(whereArgs, BrowseDistanceUnlimited, latlng.Lat, latlng.Lng, latlng.Lat)

	query := db.Table("messages_spatial ms").
		// JOIN messages for the ORIGINAL post arrival (m.arrival). ms.arrival is
		// the ripple-bumped spatial arrival, so it can't stand in for "posted".
		Joins("INNER JOIN messages m ON m.id = ms.msgid").
		// users au = the post AUTHOR, for the outbound distance cap below.
		Joins("INNER JOIN users au ON au.id = m.fromuser").
		Joins("INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid").
		Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", myid, utils.MESSAGE_LIKES_VIEW)

	return query.Where(whereSQL, whereArgs...), probe
}

// markPinned flags any summary in res whose msgid has a messages_pinned row (a paid
// bulk-offer clearance). It only MARKS; the caller floats pinned posts to the top when
// it sorts. Because it operates on the already-visibility-filtered result set, a post is
// only ever pinned-to-top when it already qualifies to appear on the feed ("if it would
// appear anywhere"). The ids come from our own rows (never user input), so the IN list is
// built directly. Fails safe: if messages_pinned is absent the scan yields nothing.
func markPinned(db *gorm.DB, res []message.MessageSummary) {
	if len(res) == 0 {
		return
	}
	ids := make([]uint64, len(res))
	for i, m := range res {
		ids[i] = m.ID
	}
	var pinnedIDs []uint64
	// The
	// ids used to be formatted as decimal text and joined directly into the
	// SQL string - a variable number of literal integers, which the
	// extractor could never fold to one fixed golden no matter how good its
	// constant-folding gets (the count depends on a runtime slice length,
	// not a Go constant). Passing them as a bound []uint64 slice instead
	// makes the SOURCE TEXT a single fixed "msgid IN ?" - GORM/the driver
	// expand it to N placeholders at execution time, not in the source - so
	// this is now the same "clean IN-list, list is the only variable part"
	// shape already proven elsewhere (message/reach.go's ReachBlockedSet,
	// site 7e7e69fa2f85). Strictly safer too: a msgid can never need escaping
	// now.
	db.Table("messages_pinned").
		Select("msgid").
		Where("msgid IN ?", ids).
		Scan(&pinnedIDs)
	if len(pinnedIDs) == 0 {
		return
	}
	pinned := make(map[uint64]bool, len(pinnedIDs))
	for _, id := range pinnedIDs {
		pinned[id] = true
	}
	for i := range res {
		if pinned[res[i].ID] {
			res[i].Pinned = true
		}
	}
}

// Messages renders the browse feed. The endpoint is still mounted at /isochrone/message
// (kept for client back-compat), but the default 'nearby' view is now driven by the
// rippling-out REACH model, not per-user isochrones: a post is "nearby" when its grown
// reach polygon currently covers the viewer's location.
func Messages(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// The 'mygroups' browse view shows posts from the user's member groups only — the same
	// universe Count uses for that view — so the nav badge/divider count matches what the feed
	// renders and "Mark seen" can actually clear it. (The default 'nearby' view below is the
	// location/reach feed.) Without this the list always returned the location feed while
	// Count branched to member groups, leaving a non-clearable count for mygroups users.
	if effectiveBrowseView(c, db, myid) == "mygroups" {
		return myGroupsMessages(c, db, myid)
	}

	res := []message.MessageSummary{}

	latlng := user.GetLatLng(myid)

	// 'nearby' browse (the default view): show posts whose rippling-out reach polygon
	// currently covers the viewer's location — the reach model's read-side test
	// ST_Contains(reach, viewer). This replaces the older per-user isochrone-containment
	// selection: "nearby" now means each post's grown reach has reached you, not that a
	// stored travel-time polygon around you contains the post. A post with no reach row is
	// simply not in the location view yet (it stays visible via the 'mygroups' view);
	// ensuring every browsable post has a reach row is the reach engine's job, not this handler's.
	// Reach is drive-time-derived, so this respects geography (estuaries, coastlines) that a
	// straight-line radius would get wrong.
	if latlng.Lat != 0 || latlng.Lng != 0 {
		viewerLat, viewerLng := float64(latlng.Lat), float64(latlng.Lng)
		weights := LoadScoreWeights()
		env := LoadScoreEnv()

		// Views/replies mirror UnifiedDigestService::getPostsForUser's subqueries
		// exactly (SUM of 'View' like counts; approved 'Interested' chat replies), so
		// the browse feed's 'budget' (underexposure) term agrees with the digest's.
		// reach_lat/reach_lng/reach_wkt are the post's rippling_reach row — the reach
		// engine's growth origin and its current polygon — used to derive the
		// per-post reach radius (ReachRadiusMetres) that anchors the 'close' term.
		// The feed wants every in-reach post (seen or not — the client buckets on
		// `unseen`), so unseenOnly is false; nearbyCount uses the same helper with
		// unseenOnly=true so the two can never disagree on what "in reach" means.
		reachCands := fetchReachCandidates(db, myid, latlng, false)
		// One batched routing call resolves every candidate's road-aware blur;
		// the per-row calls inside toSummary are then cache hits.
		blurCoords := make([][2]float64, 0, len(reachCands))
		for _, cand := range reachCands {
			blurCoords = append(blurCoords, [2]float64{float64(cand.Lat), float64(cand.Lng)})
		}
		roadblur.RoadBlurPrewarm(blurCoords, utils.BLUR_USER)
		for _, cand := range reachCands {
			res = append(res, cand.toSummary(viewerLat, viewerLng, weights, env, myid))
		}
		// Road drive metrics for the whole feed in ONE routing call, so
		// "Closest" is road-ordered from the first paint with no client
		// round trips and no later re-sort (the full records carry the
		// same values for the same blurred points).
		addSummaryRoadMetrics(viewerLat, viewerLng, res)

		// Pre-warm the browse-search reach cache with the membership we just computed:
		// the search's reach arm is this same predicate, and members search moments
		// after loading the feed, so this makes their FIRST search fast instead of
		// re-running the containment (see message.PrimeReachUniverse).
		reachIDs := make([]uint64, 0, len(reachCands))
		for _, cand := range reachCands {
			reachIDs = append(reachIDs, cand.ID)
		}
		message.PrimeReachUniverse(myid, viewerLat, viewerLng, reachIDs)

		// Include the viewer's own recent open posts regardless of reach, so a poster still
		// sees their own post immediately — including while it is awaiting approval, so it is
		// less obvious that a post is delayed for moderation (and before the reach engine has
		// given a brand-new post its first reach row). LEFT JOINed to rippling_reach (rather
		// than the INNER JOIN above) because a brand-new/pending post may not have a reach row
		// yet; ReachRadiusMetres falls back to the configured default extent in that case.
		start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")
		var ownCandidates []reachCandidateRow
		// Bind order
		// mirrors clause build order: SELECT's own binds (OUTCOME_TAKEN/
		// RECEIVED, then the two subqueries' MESSAGE_LIKES_VIEW/
		// CHAT_MESSAGE_INTERESTED) land first, then the bound Joins ON clause
		// (myid, MESSAGE_LIKES_VIEW), then Where (myid, start,
		// COLLECTION_PENDING) - the same order the original literal string
		// passed its args in.
		ownQuery := db.Table("messages m").
			Select("m.lat, m.lng, m.id, "+
				"ANY_VALUE(CASE WHEN mo.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
				"ANY_VALUE(CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
				"ANY_VALUE(mg.groupid) AS groupid, m.type, m.fromuser AS fromuser, "+
				"MAX(mg.arrival) AS arrival, m.arrival AS posted, "+
				// MIN where arrival takes MAX: the earliest group landing is when this first
				// became available, which is what the feed orders and dates by.
				"MIN(mg.arrival) AS visiblesince, "+
				"ANY_VALUE(CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END) AS unseen, "+
				"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = m.id AND mlv.type = ?), 0) AS views, "+
				"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = m.id AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
				"ANY_VALUE(COALESCE(rr.lat, 0)) AS reach_lat, ANY_VALUE(COALESCE(rr.lng, 0)) AS reach_lng, "+
				"ANY_VALUE(COALESCE("+reachEnvelopeExpr(db)+", '')) AS reach_wkt",
				utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED,
				utils.MESSAGE_LIKES_VIEW, utils.CHAT_MESSAGE_INTERESTED).
			Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id").
			Joins("LEFT JOIN messages_outcomes mo ON mo.msgid = m.id").
			Joins("LEFT JOIN messages_promises mp ON mp.msgid = m.id").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = m.id AND ml.userid = ? AND ml.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
			Joins("LEFT JOIN rippling_reach rr ON rr.msgid = m.id")
		ownQuery.
			// Match My Posts' active-set exactly (message.go's HAVING clause): an
			// Approved own post only counts as live while it is still in
			// messages_spatial. Once it is pruned from spatial - expired, withdrawn,
			// deleted, or taken - it must drop off browse at the same moment it drops
			// off My Posts, not linger here for up to OPEN_AGE days (approved, no
			// outcome row yet, arrival still within the window) because this own-posts
			// arm queries the messages table directly and so bypasses spatial pruning.
			// A PENDING post is never in spatial, so keep showing it via this arm - that
			// is its whole point: the poster sees their post immediately while it awaits
			// moderation, so it's less obvious a post is delayed. A REJECTED post is
			// deliberately NOT shown here - a poster seeing their own rejected post linger in
			// the browse feed is wrong (Discourse 9808); it belongs only in My Posts.
			Where("m.fromuser = ? AND mg.arrival >= ? AND mo.id IS NULL "+
				"AND (EXISTS (SELECT 1 FROM messages_spatial ms WHERE ms.msgid = m.id) "+
				"OR mg.collection = ?)", myid, start, utils.COLLECTION_PENDING).
			Group("m.id").
			Scan(&ownCandidates)

		// Apply the SAME age-based expiry the My Posts endpoint uses, so a poster's
		// own post that has aged out of its group's display window doesn't keep
		// showing on browse after My Posts has already hidden it as old. Convert the
		// own candidates to summaries, then drop the expired ones.
		ownSummaries := make([]message.MessageSummary, 0, len(ownCandidates))
		for _, cand := range ownCandidates {
			ownSummaries = append(ownSummaries, cand.toSummary(viewerLat, viewerLng, weights, env, myid))
		}
		activeOwn := message.FilterExpiredSummaries(db, ownSummaries)

		// Any own post that expired must not linger on the feed even if the reach arm
		// (messages_spatial, not yet pruned by the daily batch) also surfaced it, so
		// remove expired own posts from the reach-arm results too.
		activeOwnIDs := make(map[uint64]bool, len(activeOwn))
		for _, m := range activeOwn {
			activeOwnIDs[m.ID] = true
		}
		expiredOwn := make(map[uint64]bool)
		for _, cand := range ownCandidates {
			if !activeOwnIDs[cand.ID] {
				expiredOwn[cand.ID] = true
			}
		}
		if len(expiredOwn) > 0 {
			kept := res[:0]
			for _, m := range res {
				if !expiredOwn[m.ID] {
					kept = append(kept, m)
				}
			}
			res = kept
		}

		// De-dupe: an own post already surfaced by the reach arm must not appear twice.
		seen := make(map[uint64]bool, len(res))
		for _, m := range res {
			seen[m.ID] = true
		}
		for _, m := range activeOwn {
			if !seen[m.ID] {
				res = append(res, m)
			}
		}

		// Order by rippling relevance score, descending — the 'close' term already
		// captures "posts near me first" (the old pinClosestTwo hack this replaces),
		// plus freshness/underexposure/anchor signals the pin didn't consider at all.
		// Stable so equal-score ties beyond the arrival tie-break below keep their
		// (reach-arm-then-own-arm, otherwise DB-order) relative position.
		// A pinned post (a paid bulk-offer clearance) floats to the very top whenever it
		// already qualifies to appear here — ahead of the relevance score. This only reorders
		// within the already reach-filtered set, so it never pins a post that wouldn't appear.
		markPinned(db, res)
		sort.SliceStable(res, func(i, j int) bool {
			if res[i].Pinned != res[j].Pinned {
				return res[i].Pinned
			}
			if res[i].Score != res[j].Score {
				return res[i].Score > res[j].Score
			}
			return res[i].Arrival.After(res[j].Arrival)
		})

		// Apply the SAME distance filter the unread count uses (nearbyCount ->
		// resolveMaxDistance, which reads ?maxDistance= else the member's saved
		// browseMaxDistance). Without this the feed returned every in-reach post
		// regardless of the member's distance preference while the count honoured it, so
		// the unread badge (e.g. 3) and the unseen posts the client shows above its
		// "You're up to date" divider (e.g. 9) drifted apart. Own posts have a blurred
		// distance of ~0 from the viewer (it's their own location) so they always pass;
		// only far reach posts drop. We match nearbyCount exactly — no pinned exemption —
		// so the two never disagree; a pinned clearance beyond the slider is out of scope
		// for that viewer just as it is uncounted.
		maxDist := resolveMaxDistance(c, db, myid)
		if maxDist < BrowseDistanceUnlimited {
			kept := res[:0]
			for _, m := range res {
				if m.Distance <= maxDist {
					kept = append(kept, m)
				}
			}
			res = kept
		}
	}

	return c.JSON(res)
}

// effectiveBrowseView resolves the browse view for this request: an explicit ?browseView= wins,
// otherwise the user's saved setting, otherwise "nearby". Defaulting to the user's setting (rather
// than always "nearby") keeps the count and feed correct even when a client path omits or mis-sends
// the param — the cause of mygroups users seeing a stuck "nearby" count they couldn't clear.
func effectiveBrowseView(c *fiber.Ctx, db *gorm.DB, myid uint64) string {
	if bv := c.Query("browseView", ""); bv != "" {
		return bv
	}
	var setting string
	// COALESCE to '' so users who have never set browseView (JSON_EXTRACT -> SQL NULL) scan cleanly
	// into the non-nullable string instead of erroring "converting NULL to string is unsupported"
	// on every such request. NULL still falls through to the "nearby" default below.
	db.Table("users").
		Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseView')), '')").
		Where("id = ?", myid).
		Scan(&setting)
	if setting == "mygroups" {
		return "mygroups"
	}
	return "nearby"
}

// myGroupsMsgIDs returns the open (successful=0) message ids in the user's member groups — the
// shared universe for the 'mygroups' browse view, so Messages (the feed) and Count (the badge)
// agree and "Mark seen" can drain the count.
//
// Membership is tested via messages_groups (a post's FULL group set), NOT
// messages_spatial.groupid, which stores only ONE group per post and so mis-attributes
// rippled/cross-posted messages — the same reason the feed (message.Groups), popular-posts and
// edit-queue queries all filter on messages_groups. Using spatial.groupid here left two bugs:
// a post rippled INTO a member group (its spatial row points at the non-member origin) was
// missed, and a spatial row still pointing at a member group after the post was
// removed/retracted there was counted but absent from the feed — a residual Mark seen could
// never clear.
func myGroupsMsgIDs(db *gorm.DB, myid uint64) []uint64 {
	var ids []uint64
	db.Table("messages_spatial ms").
		Select("DISTINCT ms.msgid").
		Where("ms.successful = 0 "+
			"AND EXISTS (SELECT 1 FROM messages_groups mg "+
			"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
			"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
			"AND mg.collection = 'Approved' AND mg.deleted = 0)", myid).
		Scan(&ids)
	return ids
}

// myGroupsMessages renders the 'mygroups' browse feed: posts from the viewer's member groups.
// Membership (not location/reach) decides what shows — the viewer is a member, and Count's
// mygroups branch counts the same universe, so feed and badge stay in lock-step. Each post is
// still scored and distance-stamped exactly like the nearby feed (reachCandidateRow.toSummary)
// whenever the viewer has a location, so the "New to you" relevance sort and the distance slider
// work in this view too — member-group posts have no reach row of their own, so the reach radius
// falls back to the configured default extent (ReachRadiusMetres), the same fallback the nearby
// view's own-posts arm uses.
func myGroupsMessages(c *fiber.Ctx, db *gorm.DB, myid uint64) error {
	res := []message.MessageSummary{}
	msgIDs := myGroupsMsgIDs(db, myid)

	if len(msgIDs) > 0 {
		// Select the same scoring/distance ingredients as the nearby arm (fetchReachCandidates):
		// per-post views and reply counts, plus the post's reach row (LEFT JOINed — a member-group
		// post need not have one) so toSummary can derive its distance and relevance score.
		//
		// msgIDs is bound directly
		// as a slice via "IN ?" - it always travelled as real bind values
		// (fmt.Sprintf only built the "?,?,?,..." placeholder-count text
		// itself), so this needed only the native GORM IN-list form, not a
		// behaviour change.
		var candidates []reachCandidateRow
		mgQuery := db.Table("messages_spatial ms").
			Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
				"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
				"ms.msgtype AS type, m.fromuser AS fromuser, ms.arrival, m.arrival AS posted, "+
				"COALESCE((SELECT MIN(mgv.arrival) FROM messages_groups mgv WHERE mgv.msgid = ms.msgid AND mgv.deleted = 0), m.arrival) AS visiblesince, "+
				"CASE WHEN ml.msgid IS NULL AND ms.id > "+
				strconv.FormatUint(browseClearedWatermark(db, myid), 10)+
				" THEN 1 ELSE 0 END AS unseen, "+
				"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = ms.msgid AND mlv.type = ?), 0) AS views, "+
				"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = ms.msgid AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
				"COALESCE(rr.lat, 0) AS reach_lat, COALESCE(rr.lng, 0) AS reach_lng, COALESCE("+reachEnvelopeExpr(db)+", '') AS reach_wkt",
				utils.MESSAGE_LIKES_VIEW, utils.CHAT_MESSAGE_INTERESTED).
			// JOIN messages for the ORIGINAL post arrival (m.arrival), stable across
			// rippling — see the reach arm above.
			Joins("INNER JOIN messages m ON m.id = ms.msgid").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
			Joins("LEFT JOIN rippling_reach rr ON rr.msgid = ms.msgid")
		mgQuery.
			Where("ms.msgid IN ?", msgIDs).
			Scan(&candidates)

		latlng := user.GetLatLng(myid)
		if latlng.Lat != 0 || latlng.Lng != 0 {
			viewerLat, viewerLng := float64(latlng.Lat), float64(latlng.Lng)
			weights := LoadScoreWeights()
			env := LoadScoreEnv()
			blurCoords := make([][2]float64, 0, len(candidates))
			for _, cand := range candidates {
				blurCoords = append(blurCoords, [2]float64{float64(cand.Lat), float64(cand.Lng)})
			}
			roadblur.RoadBlurPrewarm(blurCoords, utils.BLUR_USER)
			for _, cand := range candidates {
				res = append(res, cand.toSummary(viewerLat, viewerLng, weights, env, myid))
			}
			addSummaryRoadMetrics(viewerLat, viewerLng, res)
			// Rippling relevance order (score desc, arrival tie-break), mirroring the nearby
			// arm, so the client's "New to you" sort has a meaningful score to rank on.
			sort.SliceStable(res, func(i, j int) bool {
				if res[i].Score != res[j].Score {
					return res[i].Score > res[j].Score
				}
				return res[i].Arrival.After(res[j].Arrival)
			})
		} else {
			// No known location: distance/score can't be measured, so keep the prior behaviour
			// (blurred coords, Distance/Score left at zero). The distance slider is hidden
			// client-side without a location, so nothing here depends on Distance.
			prewarmCandidateBlur(candidates)
			for _, cand := range candidates {
				blurLat, blurLng := roadblur.RoadBlur(cand.Lat, cand.Lng, utils.BLUR_USER)
				res = append(res, message.MessageSummary{
					ID:           cand.ID,
					Successful:   cand.Successful,
					Promised:     cand.Promised,
					Groupid:      cand.Groupid,
					Type:         cand.Type,
					Arrival:      cand.Arrival,
					Posted:       cand.Posted,
					VisibleSince: cand.VisibleSince,
					Lat:          blurLat,
					Lng:          blurLng,
					Unseen:       cand.Unseen,
					Mine:         cand.Fromuser != 0 && cand.Fromuser == myid,
				})
			}
		}
	}

	// Float any pinned post (a paid bulk-offer clearance) to the top of the member-group
	// feed, above the relevance order.
	markPinned(db, res)
	sort.SliceStable(res, func(i, j int) bool {
		return res[i].Pinned && !res[j].Pinned
	})

	return c.JSON(res)
}

func Count(c *fiber.Ctx) error {
	db := database.DBConn
	myid := user.WhoAmI(c)

	var count uint64 = 0

	browseView := effectiveBrowseView(c, db, myid)
	maxDistance := resolveMaxDistance(c, db, myid)

	// Reuse a recent answer where there is one. Marking posts seen clears it, so the badge
	// still drops to zero the moment the viewer does that - see the browsecount package for
	// why this is cached at all and what it deliberately does not delay.
	if cached, ok := browsecount.Get(myid, browseView, maxDistance); ok {
		return c.JSON(fiber.Map{
			"count": cached,
		})
	}

	if browseView == "mygroups" {
		count = myGroupsCount(db, myid, maxDistance)
	} else {
		count = nearbyCount(myid, maxDistance)
	}

	browsecount.Put(myid, browseView, maxDistance, count)

	return c.JSON(fiber.Map{
		"count": count,
	})
}

// myGroupsCountUnfiltered is the plain unseen-post count for the 'mygroups' browse view: open,
// unviewed posts in the viewer's member groups. Membership is tested via messages_groups (the
// post's full group set), not messages_spatial.groupid — which stores only ONE group per post and
// mis-attributes rippled/cross-posted messages (see myGroupsMsgIDs). This EXISTS matches the
// mygroups feed (message.Groups / myGroupsMsgIDs), so feed == badge and "Mark seen" drains to zero
// instead of sticking on rows the feed never renders.
func myGroupsCountUnfiltered(db *gorm.DB, myid uint64) uint64 {
	var count uint64 = 0
	db.Table("messages_spatial ms").
		Select("COUNT(DISTINCT ms.msgid)").
		Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
		Where("ms.successful = 0 AND ml.msgid IS NULL AND ms.id > "+
			strconv.FormatUint(browseClearedWatermark(db, myid), 10)+" "+
			"AND EXISTS (SELECT 1 FROM messages_groups mg "+
			"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
			"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
			"AND mg.collection = 'Approved' AND mg.deleted = 0)", myid).
		Scan(&count)
	return count
}

// myGroupsCount is the unseen-post count for the 'mygroups' browse view. maxDistanceMiles narrows
// it to posts within that many miles of the viewer using the SAME blurred-coordinate Haversine the
// feed exposes as `distance` (reachCandidateRow.blurredDistanceMiles), so the nav badge tracks the
// distance-filtered list exactly. BrowseDistanceUnlimited (the common case — most members leave the
// slider at "no limit") skips the per-post distance work and uses the fast unfiltered COUNT.
func myGroupsCount(db *gorm.DB, myid uint64, maxDistanceMiles float64) uint64 {
	if maxDistanceMiles >= BrowseDistanceUnlimited {
		return myGroupsCountUnfiltered(db, myid)
	}

	latlng := user.GetLatLng(myid)
	if latlng.Lat == 0 && latlng.Lng == 0 {
		// No location to measure from — the slider can't be set without one, so this is a
		// defensive fallback: count everything (as if unlimited) rather than zero the badge.
		return myGroupsCountUnfiltered(db, myid)
	}

	// Distance-limited path: enumerate the same unseen member-group posts (with coordinates) the
	// unfiltered count covers, and keep those within maxDistance of the viewer's blurred-point
	// Haversine — the same measure the feed uses — so badge and list agree at the boundary.
	viewerLat, viewerLng := float64(latlng.Lat), float64(latlng.Lng)
	var candidates []reachCandidateRow
	db.Table("messages_spatial ms").
		Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, ms.msgid AS id").
		Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
		Where("ms.successful = 0 AND ml.msgid IS NULL AND ms.id > "+
			strconv.FormatUint(browseClearedWatermark(db, myid), 10)+" "+
			"AND EXISTS (SELECT 1 FROM messages_groups mg "+
			"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
			"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
			"AND mg.collection = 'Approved' AND mg.deleted = 0)", myid).
		Scan(&candidates)

	var count uint64 = 0
	prewarmCandidateBlur(candidates)
	for _, cand := range candidates {
		_, _, distanceMiles := cand.blurredDistanceMiles(viewerLat, viewerLng)
		if distanceMiles <= maxDistanceMiles {
			count++
		}
	}
	return count
}

// resolveMaxDistance returns the viewer's effective nearby-feed distance limit in miles: an
// explicit ?maxDistance= query param wins (so the browse page can force a fresh value right
// after a slider change), otherwise the viewer's saved settings.browseMaxDistance (so the
// app-wide navbar badge honours the slider automatically without every call site having to
// pass it), otherwise their band default, otherwise BrowseDistanceUnlimited (no limit — the
// server's own reach extent governs, as before the distance slider existed).
//
// browseReachMaxDistance is the INBOUND-ONLY band default (browse:backfill-max-distance). It is
// deliberately a separate key from browseMaxDistance, which the member sets themselves and which
// also caps how far away OTHER people see THEIR posts (utils.AuthorReachCapWhere). Writing a
// default into that key would silently apply an outbound cap nobody asked for: a city member's
// band radius is ~4.8 miles, so every post they made would stop reaching anyone beyond it - the
// opposite of the point, which is to let posts travel while holding each RECIPIENT to the
// distance their own surroundings justify.
func resolveMaxDistance(c *fiber.Ctx, db *gorm.DB, myid uint64) float64 {
	if q := c.Query("maxDistance", ""); q != "" {
		if v, err := strconv.ParseFloat(q, 64); err == nil {
			return v
		}
	}

	var row struct {
		Chosen  string `gorm:"column:chosen"`
		Default string `gorm:"column:banddefault"`
	}
	// COALESCE to '' for the same reason as effectiveBrowseView: users who have never set
	// either key scan cleanly into the non-nullable strings instead of erroring.
	db.Table("users").
		Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseMaxDistance')), '') AS chosen, "+
			"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseReachMaxDistance')), '') AS banddefault").
		Where("id = ?", myid).
		Scan(&row)

	// An explicit choice always wins, including one that is wider than the band default:
	// a member who dragged the slider has said what they want. An unparseable value is
	// treated as no value and falls through, matching message.go's browse-scoped search
	// and the Laravel DistancePreferenceFilter - all three must agree or the feed, its
	// badge and search would disagree about the same member.
	for _, raw := range []string{row.Chosen, row.Default} {
		if raw == "" {
			continue
		}
		if v, err := strconv.ParseFloat(raw, 64); err == nil {
			return v
		}
	}

	return BrowseDistanceUnlimited
}

// nearbyCount is the unseen-post count for the 'nearby' browse view. It mirrors the
// reach-based feed in Messages — open posts whose rippling reach covers the viewer and
// which they have not yet viewed — so the nav badge stays in lock-step with the list and
// "Mark seen" can drain it to zero.
//
// maxDistanceMiles narrows the count to posts within that many miles of the viewer, using the
// SAME blurred-coordinate Haversine distance the feed exposes as `distance`
// (reachCandidateRow.blurredDistanceMiles) — so the badge matches the client's distance-
// filtered list exactly at the boundary. Pass BrowseDistanceUnlimited (or anything at or above
// it) to skip the per-post distance computation entirely and use the original, fast COUNT query.
//
// The unlimited path used to be the common case, because most members never touched the slider.
// It no longer is: browse:backfill-max-distance gives every member their density band's radius,
// and only the sparse band (whose cap IS the reach ceiling) resolves to unlimited — so roughly
// two thirds of members now take the distance-limited path below. That path deliberately stays
// in Go rather than SQL: the filter must use the BLURRED coordinates the feed exposes, or the
// badge and the list would disagree at the boundary, which is the bug class this replaced.
func nearbyCount(myid uint64, maxDistanceMiles float64) uint64 {
	db := database.DBConn

	var count uint64 = 0
	latlng := user.GetLatLng(myid)

	if latlng.Lat == 0 && latlng.Lng == 0 {
		return count
	}

	// Reach containment via the spatial server: the geometry test that was
	// 95-98% of this query's cost is answered from the spatial index's
	// rasters, and SQL only runs keyed lookups over the returned ids. Any
	// failure falls through to the degraded path below.
	spatialIn, spatialPartial, useSpatial := spatialReachIDs(db, latlng)

	// The rasters answer only the committed reach. The feed additionally admits
	// via the viewer's overflow ring (reachOrOverflowSQL), so the badge must ask
	// the same question or it undercounts what the feed shows — the exact
	// badge/feed disagreement this whole path exists to prevent.
	//
	// Asked as ids, off the bbox side table, for the reason spelled out in
	// fromIDsWhere: the ring test itself cannot go into this WHERE without
	// unbounding it. Only in spatial mode — the SQL fallback below resolves its
	// own rings inside reachOrOverflowSQL, and this lookup is two indexed
	// queries we would otherwise be paying for twice.
	var ringAdmitted []uint64
	if useSpatial {
		ringAdmitted = viewerAdmittedMsgids(db, myid, latlng.Lat, latlng.Lng)
	}

	if maxDistanceMiles >= BrowseDistanceUnlimited {
		// Viewer sets no inbound limit: one COUNT over the shared reach-arm membership,
		// which also carries the OUTBOUND author cap and the held-for-moderation filter
		// - so this fast path can never disagree with the feed about which posts exist
		// to be counted.
		if useSpatial {
			// Zero raster ids does not mean zero for a ring viewer: their ring
			// can admit posts the committed reach does not cover.
			if len(spatialIn)+len(spatialPartial) == 0 && len(ringAdmitted) == 0 {
				return 0
			}
			reachCandidateQueryFromIDs(db, myid, latlng, spatialIn, spatialPartial, ringAdmitted).
				Select("COUNT(DISTINCT ms.msgid)").
				Scan(&count)
			return count
		}
		countQuery, probe := reachCandidateQuery(db, myid, latlng, true)
		if probe != nil {
			// Degraded path: the SQL conjunct is only the outer-bound
			// superset, so a bare COUNT would over-count. Count what survives
			// the cells probe instead - same rows the feed would render.
			var cands []reachCandidateRow
			countQuery.
				Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, ms.msgid AS id, rr.polygon_cells AS reach_cells").
				Scan(&cands)
			return uint64(len(filterProbed(cands, probe)))
		}
		countQuery.
			Select("COUNT(DISTINCT ms.msgid)").
			Scan(&count)
		return count
	}

	// Distance-limited path: same membership again, but only the coordinates come back -
	// the per-post blurred-Haversine filter below needs nothing else, and the full
	// fetchReachCandidates row (views/replies subqueries, polygon envelope) made this
	// badge poll ~849ms a call for no benefit.
	var cands []reachCandidateRow
	if useSpatial {
		// Same ring caveat as the fast COUNT above: empty raster buckets do not
		// mean an empty candidate set for a ring viewer.
		if len(spatialIn)+len(spatialPartial) == 0 && len(ringAdmitted) == 0 {
			return 0
		}
		reachCandidateQueryFromIDs(db, myid, latlng, spatialIn, spatialPartial, ringAdmitted).
			Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, ms.msgid AS id").
			Scan(&cands)
	} else {
		cands = reachCandidatePoints(db, myid, latlng)
	}

	viewerLat, viewerLng := float64(latlng.Lat), float64(latlng.Lng)
	prewarmCandidateBlur(cands)
	for _, cand := range cands {
		_, _, distanceMiles := cand.blurredDistanceMiles(viewerLat, viewerLng)
		if distanceMiles <= maxDistanceMiles {
			count++
		}
	}

	return count
}

// prewarmCandidateBlur resolves every candidate's road-aware blur in one
// batched routing call, so per-row blurredDistanceMiles calls are cache hits
// instead of one network round trip each.
func prewarmCandidateBlur(cands []reachCandidateRow) {
	coords := make([][2]float64, 0, len(cands))
	for _, c := range cands {
		coords = append(coords, [2]float64{float64(c.Lat), float64(c.Lng)})
	}
	roadblur.RoadBlurPrewarm(coords, utils.BLUR_USER)
}

// addSummaryRoadMetrics fills Roadmins/Roadmiles on feed summaries with ONE
// routing call from the viewer to every post's blurred point. Best-effort:
// on any routing failure the fields stay nil and clients fall back to
// crow-flies (and to their own batched lookup, which also fails soft).
func addSummaryRoadMetrics(viewerLat, viewerLng float64, res []message.MessageSummary) {
	if len(res) == 0 {
		return
	}
	targets := make([]driving.Target, 0, len(res))
	for ix := range res {
		if res[ix].Lat != 0 || res[ix].Lng != 0 {
			targets = append(targets, driving.Target{
				ID:  int64(ix),
				Lat: res[ix].Lat,
				Lng: res[ix].Lng,
			})
		}
	}
	for _, r := range driving.FetchDriveMetrics(roadblur.RoutingURL(), viewerLat, viewerLng, targets) {
		if r.Mins != nil && r.ID >= 0 && int(r.ID) < len(res) {
			res[r.ID].Roadmins = r.Mins
			res[r.ID].Roadmiles = r.Miles
		}
	}
}
