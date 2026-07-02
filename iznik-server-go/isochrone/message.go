package isochrone

import (
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
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

// reachCandidateRow is the intermediate scan target for both the reach arm
// and the viewer's-own-posts arm of Messages: a post's identity/visibility
// columns plus the raw ingredients (views, replies, reach polygon/origin)
// needed to compute its rippling relevance score. Both arms' queries alias
// their columns to exactly these names so one struct/one scoring path serves
// both.
type reachCandidateRow struct {
	Lat        float64   `gorm:"column:lat"`
	Lng        float64   `gorm:"column:lng"`
	ID         uint64    `gorm:"column:id"`
	Successful bool      `gorm:"column:successful"`
	Promised   bool      `gorm:"column:promised"`
	Groupid    uint64    `gorm:"column:groupid"`
	Type       string    `gorm:"column:type"`
	Arrival    time.Time `gorm:"column:arrival"`
	Unseen     bool      `gorm:"column:unseen"`
	Views      int64     `gorm:"column:views"`
	Replies    int64     `gorm:"column:replies"`
	// ReachLat/ReachLng/ReachWKT describe the post's rippling_reach row (the
	// origin the reach grew from, and the current reach polygon as WKT).
	// Empty/zero when the post has no reach row (own-posts arm only; the
	// main reach arm INNER JOINs rippling_reach so these are always
	// populated there) — ReachRadiusMetres falls back to the default extent.
	ReachLat float64 `gorm:"column:reach_lat"`
	ReachLng float64 `gorm:"column:reach_lng"`
	ReachWKT string  `gorm:"column:reach_wkt"`
}

// blurredDistanceMiles blurs this post's real coordinates (utils.Blur, deterministic — the
// same post always yields the same blurred point) and returns the great-circle distance from
// the viewer to that BLURRED point, in miles, alongside the blurred point itself. This is the
// SINGLE place that computes "how far away is this post": both the feed (toSummary, for the
// exposed `distance` field and the score's `close` term) and the count's distance filter
// (nearbyCount) call it, so the badge and the list can never disagree about which posts are
// within a given limit — a real bug class this replaces (two independently-written distance
// calcs drifting at the boundary).
func (r reachCandidateRow) blurredDistanceMiles(viewerLat, viewerLng float64) (blurLat, blurLng, distanceMiles float64) {
	blurLat, blurLng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
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
func (r reachCandidateRow) toSummary(viewerLat, viewerLng float64, w ScoreWeights, env ScoreEnv) message.MessageSummary {
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
		ID:         r.ID,
		Successful: r.Successful,
		Promised:   r.Promised,
		Groupid:    r.Groupid,
		Type:       r.Type,
		Arrival:    r.Arrival,
		Lat:        blurLat,
		Lng:        blurLng,
		Unseen:     r.Unseen,
		Distance:   distanceMiles,
		Score:      comps.Total,
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
	unseenFilter := ""
	if unseenOnly {
		unseenFilter = "AND ml.msgid IS NULL "
	}

	var candidates []reachCandidateRow
	db.Raw(
		"SELECT ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
			"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
			"ms.msgtype AS type, ms.arrival, "+
			"CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END AS unseen, "+
			"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = ms.msgid AND mlv.type = ?), 0) AS views, "+
			"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = ms.msgid AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
			"rr.lat AS reach_lat, rr.lng AS reach_lng, ST_AsText(rr.polygon) AS reach_wkt "+
			"FROM messages_spatial ms "+
			"INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid "+
			"LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ? "+
			"WHERE ms.successful = 0 "+
			unseenFilter+
			"AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?))",
		utils.MESSAGE_LIKES_VIEW, utils.CHAT_MESSAGE_INTERESTED,
		myid, utils.MESSAGE_LIKES_VIEW,
		latlng.Lng, latlng.Lat, utils.SRID,
	).Scan(&candidates)

	return candidates
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
	ids := make([]string, len(res))
	for i, m := range res {
		ids[i] = strconv.FormatUint(m.ID, 10)
	}
	var pinnedIDs []uint64
	db.Raw("SELECT msgid FROM messages_pinned WHERE msgid IN (" +
		strings.Join(ids, ",") + ")").Scan(&pinnedIDs)
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
		for _, cand := range fetchReachCandidates(db, myid, latlng, false) {
			res = append(res, cand.toSummary(viewerLat, viewerLng, weights, env))
		}

		// Include the viewer's own recent open posts regardless of reach, so a poster still
		// sees their own post immediately — including while it is awaiting approval, so it is
		// less obvious that a post is delayed for moderation (and before the reach engine has
		// given a brand-new post its first reach row). LEFT JOINed to rippling_reach (rather
		// than the INNER JOIN above) because a brand-new/pending post may not have a reach row
		// yet; ReachRadiusMetres falls back to the configured default extent in that case.
		start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")
		var ownCandidates []reachCandidateRow
		db.Raw(
			"SELECT m.lat, m.lng, m.id, "+
				"ANY_VALUE(CASE WHEN mo.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
				"ANY_VALUE(CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
				"ANY_VALUE(mg.groupid) AS groupid, m.type, "+
				"MAX(mg.arrival) AS arrival, "+
				"ANY_VALUE(CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END) AS unseen, "+
				"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = m.id AND mlv.type = ?), 0) AS views, "+
				"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = m.id AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
				"ANY_VALUE(COALESCE(rr.lat, 0)) AS reach_lat, ANY_VALUE(COALESCE(rr.lng, 0)) AS reach_lng, "+
				"ANY_VALUE(COALESCE(ST_AsText(rr.polygon), '')) AS reach_wkt "+
				"FROM messages m "+
				"INNER JOIN messages_groups mg ON mg.msgid = m.id "+
				"LEFT JOIN messages_outcomes mo ON mo.msgid = m.id "+
				"LEFT JOIN messages_promises mp ON mp.msgid = m.id "+
				"LEFT JOIN messages_likes ml ON ml.msgid = m.id AND ml.userid = ? AND ml.type = ? "+
				"LEFT JOIN rippling_reach rr ON rr.msgid = m.id "+
				"WHERE m.fromuser = ? AND mg.arrival >= ? AND mo.id IS NULL "+
				"GROUP BY m.id",
			utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED,
			utils.MESSAGE_LIKES_VIEW, utils.CHAT_MESSAGE_INTERESTED,
			myid, utils.MESSAGE_LIKES_VIEW, myid, start,
		).Scan(&ownCandidates)

		// Apply the SAME age-based expiry the My Posts endpoint uses, so a poster's
		// own post that has aged out of its group's display window doesn't keep
		// showing on browse after My Posts has already hidden it as old. Convert the
		// own candidates to summaries, then drop the expired ones.
		ownSummaries := make([]message.MessageSummary, 0, len(ownCandidates))
		for _, cand := range ownCandidates {
			ownSummaries = append(ownSummaries, cand.toSummary(viewerLat, viewerLng, weights, env))
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
	db.Raw("SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseView')), '') FROM users WHERE id = ?", myid).Scan(&setting)
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
	db.Raw("SELECT DISTINCT ms.msgid FROM messages_spatial ms "+
		"WHERE ms.successful = 0 "+
		"AND EXISTS (SELECT 1 FROM messages_groups mg "+
		"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
		"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
		"AND mg.collection = 'Approved' AND mg.deleted = 0)", myid).Scan(&ids)
	return ids
}

// myGroupsMessages renders the 'mygroups' browse feed: posts from the viewer's member groups,
// with the unseen flag, blurred. No location/postvisibility/reach filtering — the viewer is a
// member, and Count's mygroups branch is unfiltered too, so feed and badge stay in lock-step.
func myGroupsMessages(c *fiber.Ctx, db *gorm.DB, myid uint64) error {
	res := []message.MessageSummary{}
	msgIDs := myGroupsMsgIDs(db, myid)

	if len(msgIDs) > 0 {
		placeholders := make([]string, len(msgIDs))
		args := make([]any, len(msgIDs)+2)
		args[0] = myid
		args[1] = utils.MESSAGE_LIKES_VIEW
		for i, id := range msgIDs {
			placeholders[i] = "?"
			args[i+2] = id
		}
		db.Raw(fmt.Sprintf(
			"SELECT ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
				"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
				"ms.msgtype AS type, ms.arrival, "+
				"CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END AS unseen "+
				"FROM messages_spatial ms "+
				"LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ? "+
				"WHERE ms.msgid IN (%s)",
			strings.Join(placeholders, ",")),
			args...).Scan(&res)
	}

	for ix, r := range res {
		res[ix].Lat, res[ix].Lng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
	}

	// Float any pinned post (a paid bulk-offer clearance) to the top of the member-group
	// feed too. This view has no relevance score, so pinned-first is the only reordering.
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

	if browseView == "mygroups" {
		// Test membership via messages_groups (the post's full group set), not
		// messages_spatial.groupid — which stores only ONE group per post and mis-attributes
		// rippled/cross-posted messages (see myGroupsMsgIDs). This EXISTS matches the mygroups
		// feed (message.Groups / myGroupsMsgIDs), so feed == badge and "Mark seen" drains to
		// zero instead of sticking on rows the feed never renders.
		db.Raw("SELECT COUNT(DISTINCT ms.msgid) FROM messages_spatial ms "+
			"LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ? "+
			"WHERE ms.successful = 0 AND ml.msgid IS NULL "+
			"AND EXISTS (SELECT 1 FROM messages_groups mg "+
			"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
			"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
			"AND mg.collection = 'Approved' AND mg.deleted = 0)", myid, utils.MESSAGE_LIKES_VIEW, myid).Scan(&count)
	} else {
		count = nearbyCount(myid, resolveMaxDistance(c, db, myid))
	}

	return c.JSON(fiber.Map{
		"count": count,
	})
}

// resolveMaxDistance returns the viewer's effective nearby-feed distance limit in miles: an
// explicit ?maxDistance= query param wins (so the browse page can force a fresh value right
// after a slider change), otherwise the viewer's saved settings.browseMaxDistance (so the
// app-wide navbar badge honours the slider automatically without every call site having to
// pass it), otherwise BrowseDistanceUnlimited (no limit — the server's own reach extent
// governs, as before the distance slider existed).
func resolveMaxDistance(c *fiber.Ctx, db *gorm.DB, myid uint64) float64 {
	if q := c.Query("maxDistance", ""); q != "" {
		if v, err := strconv.ParseFloat(q, 64); err == nil {
			return v
		}
	}

	var raw string
	// COALESCE to '' for the same reason as effectiveBrowseView: users who have never set
	// browseMaxDistance scan cleanly into the non-nullable string instead of erroring.
	db.Raw("SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseMaxDistance')), '') FROM users WHERE id = ?", myid).Scan(&raw)
	if raw != "" {
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
// it) to skip the per-post distance computation entirely and use the original, fast COUNT
// query — the common case, since most members leave the slider at "no limit".
func nearbyCount(myid uint64, maxDistanceMiles float64) uint64 {
	db := database.DBConn

	var count uint64 = 0
	latlng := user.GetLatLng(myid)

	if latlng.Lat == 0 && latlng.Lng == 0 {
		return count
	}

	if maxDistanceMiles >= BrowseDistanceUnlimited {
		db.Raw("SELECT COUNT(DISTINCT ms.msgid) "+
			"FROM messages_spatial ms "+
			"INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid "+
			"LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ? "+
			"WHERE ms.successful = 0 AND ml.msgid IS NULL "+
			"AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?))",
			myid, utils.MESSAGE_LIKES_VIEW, latlng.Lng, latlng.Lat, utils.SRID).Scan(&count)
		return count
	}

	viewerLat, viewerLng := float64(latlng.Lat), float64(latlng.Lng)
	for _, cand := range fetchReachCandidates(db, myid, latlng, true) {
		_, _, distanceMiles := cand.blurredDistanceMiles(viewerLat, viewerLng)
		if distanceMiles <= maxDistanceMiles {
			count++
		}
	}

	return count
}
