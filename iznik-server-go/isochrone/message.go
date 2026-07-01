package isochrone

import (
	"fmt"
	"math"
	"sort"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

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
		db.Raw(
			"SELECT ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
				"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
				"ms.msgtype AS type, ms.arrival, "+
				"CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END AS unseen "+
				"FROM messages_spatial ms "+
				"INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid "+
				"LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ? "+
				"WHERE ms.successful = 0 "+
				"AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?))",
			myid, utils.MESSAGE_LIKES_VIEW,
			latlng.Lng, latlng.Lat, utils.SRID,
		).Scan(&res)

		// Include the viewer's own recent open posts regardless of reach, so a poster still
		// sees their own post immediately — including while it is awaiting approval, so it is
		// less obvious that a post is delayed for moderation (and before the reach engine has
		// given a brand-new post its first reach row).
		start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")
		var ownMsgs []message.MessageSummary
		db.Raw(
			"SELECT m.lat, m.lng, m.id, "+
				"ANY_VALUE(CASE WHEN mo.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
				"ANY_VALUE(CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
				"ANY_VALUE(mg.groupid) AS groupid, m.type, "+
				"MAX(mg.arrival) AS arrival, "+
				"ANY_VALUE(CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END) AS unseen "+
				"FROM messages m "+
				"INNER JOIN messages_groups mg ON mg.msgid = m.id "+
				"LEFT JOIN messages_outcomes mo ON mo.msgid = m.id "+
				"LEFT JOIN messages_promises mp ON mp.msgid = m.id "+
				"LEFT JOIN messages_likes ml ON ml.msgid = m.id AND ml.userid = ? AND ml.type = ? "+
				"WHERE m.fromuser = ? AND mg.arrival >= ? AND mo.id IS NULL "+
				"GROUP BY m.id",
			utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED,
			myid, utils.MESSAGE_LIKES_VIEW, myid, start,
		).Scan(&ownMsgs)

		// De-dupe: an own post already surfaced by the reach arm must not appear twice.
		seen := make(map[uint64]bool, len(res))
		for _, m := range res {
			seen[m.ID] = true
		}
		for _, m := range ownMsgs {
			if !seen[m.ID] {
				res = append(res, m)
			}
		}

		// Pin the two posts nearest the viewer to the top of the feed, then leave the
		// rest of the order unchanged. Reduces "I keep seeing posts far away" complaints
		// while preserving the existing ordering for everything below the top two.
		// Computed on the real coords, before they are blurred below.
		res = pinClosestTwo(res, float64(latlng.Lat), float64(latlng.Lng))

		for ix, r := range res {
			res[ix].Lat, res[ix].Lng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
		}
	}

	return c.JSON(res)
}

// pinClosestTwo moves the two posts nearest the viewer to the front (nearest first)
// and keeps every other post in its existing relative order. No-op when the viewer
// has no location or there are two or fewer posts. Posts without coordinates sort to
// the back so they are never pinned. Pure (does not mutate the input order in place
// beyond producing a reordered copy) so it is straightforward to unit-test.
func pinClosestTwo(res []message.MessageSummary, lat, lng float64) []message.MessageSummary {
	if len(res) <= 2 || (lat == 0 && lng == 0) {
		return res
	}

	type distIdx struct {
		idx  int
		dist float64
	}
	dists := make([]distIdx, len(res))
	for i, m := range res {
		d := math.MaxFloat64
		if m.Lat != 0 || m.Lng != 0 {
			d = utils.Haversine(lat, lng, m.Lat, m.Lng)
		}
		dists[i] = distIdx{idx: i, dist: d}
	}
	sort.SliceStable(dists, func(a, b int) bool { return dists[a].dist < dists[b].dist })

	first, second := dists[0].idx, dists[1].idx
	out := make([]message.MessageSummary, 0, len(res))
	out = append(out, res[first], res[second])
	for i, m := range res {
		if i != first && i != second {
			out = append(out, m)
		}
	}
	return out
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
		count = nearbyCount(myid)
	}

	return c.JSON(fiber.Map{
		"count": count,
	})
}

// nearbyCount is the unseen-post count for the 'nearby' browse view. It mirrors the
// reach-based feed in Messages — open posts whose rippling reach covers the viewer and
// which they have not yet viewed — so the nav badge stays in lock-step with the list and
// "Mark seen" can drain it to zero.
func nearbyCount(myid uint64) uint64 {
	db := database.DBConn

	var count uint64 = 0
	latlng := user.GetLatLng(myid)

	if latlng.Lat != 0 || latlng.Lng != 0 {
		db.Raw("SELECT COUNT(DISTINCT ms.msgid) "+
			"FROM messages_spatial ms "+
			"INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid "+
			"LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ? "+
			"WHERE ms.successful = 0 AND ml.msgid IS NULL "+
			"AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?))",
			myid, utils.MESSAGE_LIKES_VIEW, latlng.Lng, latlng.Lat, utils.SRID).Scan(&count)
	}

	return count
}
