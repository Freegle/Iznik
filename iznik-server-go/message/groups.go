package message

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"strconv"
	"time"
)

func Groups(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	msgs := []MessageSummary{}

	gid, _ := strconv.ParseUint(c.Params("id"), 10, 64)

	start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")

	// Build the EXISTS fragment for the spatial arm to filter by group membership
	// without relying on messages_spatial.groupid (which stores only ONE group for
	// multi-group messages).
	var spatialGroupFilter string
	var spatialArgs []interface{}

	if gid > 0 {
		// Specific group: message must be approved in that group.
		// gid is safe to inline (parsed from uint64).
		spatialGroupFilter = " AND EXISTS (" +
			"SELECT 1 FROM messages_groups mg " +
			"WHERE mg.msgid = messages_spatial.msgid " +
			"AND mg.groupid = " + strconv.FormatUint(gid, 10) + " " +
			"AND mg.collection = 'Approved' " +
			"AND mg.deleted = 0" +
			") "
		// Placeholders for spatial arm: ?(likes userid), ?(likes type)
		spatialArgs = []interface{}{myid, utils.MESSAGE_LIKES_VIEW}
	} else {
		// Combined browse (gid=0): message must be approved in some group the viewer belongs to.
		spatialGroupFilter = " AND EXISTS (" +
			"SELECT 1 FROM messages_groups mg " +
			"INNER JOIN memberships mem ON mem.groupid = mg.groupid " +
			"WHERE mg.msgid = messages_spatial.msgid " +
			"AND mem.userid = ? " +
			"AND mg.collection = 'Approved' " +
			"AND mg.deleted = 0" +
			") "
		// Placeholders for spatial arm: ?(likes userid), ?(likes type), ?(mem.userid)
		spatialArgs = []interface{}{myid, utils.MESSAGE_LIKES_VIEW, myid}
	}

	// We want to include our own messages, so that it is less obvious if a message is delayed for approval and
	// hasn't made it into messages_spatial yet.
	// The own-messages arm joins messages_groups and can produce one row per group for a cross-posted
	// message; GROUP BY messages.id + MAX(arrival) collapses those into a single row.
	db.Raw("SELECT * FROM ("+
		"SELECT ST_Y(point) AS lat, "+
		"ST_X(point) AS lng, "+
		"messages_spatial.msgid AS id, "+
		"messages_spatial.successful, "+
		"messages_spatial.promised, "+
		"messages_spatial.groupid, "+
		"messages_spatial.msgtype AS type, "+
		"messages_spatial.arrival, "+
		// posted = the ORIGINAL post time (messages.arrival), stable across rippling.
		// The client's "Newest posted" sort keys on posted; messages_spatial.arrival is
		// ripple-BUMPED, so without posted the mygroups feed fell back to it and the
		// selected sort appeared not to be applied (Discourse 9844, mygroups variant).
		// Mirrors the nearby/reach feed (isochrone/message.go).
		"m.arrival AS posted, "+
		"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen "+
		"FROM messages_spatial "+
		"INNER JOIN messages m ON m.id = messages_spatial.msgid "+
		"LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ? "+
		"WHERE 1=1 "+spatialGroupFilter+
		"UNION "+
		"SELECT lat, lng, messages.id, "+
		"ANY_VALUE(CASE WHEN messages_outcomes.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
		"ANY_VALUE(CASE WHEN messages_promises.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
		"ANY_VALUE(messages_groups.groupid) AS groupid, "+
		"messages.type, "+
		"MAX(messages_groups.arrival) AS arrival, "+
		"messages.arrival AS posted, "+
		"ANY_VALUE(CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END) AS unseen "+
		"FROM messages "+
		"INNER JOIN messages_groups ON messages_groups.msgid = messages.id "+
		"LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id "+
		"LEFT JOIN messages_promises ON messages_promises.msgid = messages.id "+
		"LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ? "+
		"WHERE fromuser = ? AND messages_groups.arrival >= ? "+
		"AND messages_outcomes.id IS NULL "+
		"GROUP BY messages.id "+
		") t "+
		"ORDER BY unseen DESC, arrival DESC, id DESC;",
		append(spatialArgs,
			utils.OUTCOME_TAKEN,
			utils.OUTCOME_RECEIVED,
			myid, utils.MESSAGE_LIKES_VIEW,
			myid,
			start)...).Scan(&msgs)

	// Viewer location for the per-post distance. GetLatLng reads settings.mylocation.
	latlng := user.GetLatLng(myid)
	viewerLat, viewerLng := float64(latlng.Lat), float64(latlng.Lng)
	hasLoc := latlng.Lat != 0 || latlng.Lng != 0

	for ix, r := range msgs {
		// Protect anonymity of poster a bit.
		blurLat, blurLng := utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
		msgs[ix].Lat, msgs[ix].Lng = blurLat, blurLng

		// Per-post distance in miles from the viewer to the BLURRED point, so the "All my
		// communities" browse view can be narrowed by the client's distance slider. Computed
		// from the blurred coords (never the real ones), matching the reach feed's privacy
		// approach so it can't triangulate the post any more precisely than lat/lng already do.
		// Left at 0 when the viewer has no known location - the slider is hidden client-side
		// then. Without this the feed returned distance 0 for EVERY post, and since 0 <= any
		// slider value the list and map were never filtered (the reported mygroups slider no-op).
		if hasLoc {
			msgs[ix].Distance = utils.Haversine(viewerLat, viewerLng, blurLat, blurLng)
		}
	}

	return c.JSON(msgs)
}
