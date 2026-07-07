package message

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"sort"
	"strconv"
	"time"
)

func Bounds(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)

	swlat, _ := strconv.ParseFloat(c.Query("swlat"), 32)
	swlng, _ := strconv.ParseFloat(c.Query("swlng"), 32)
	nelat, _ := strconv.ParseFloat(c.Query("nelat"), 32)
	nelng, _ := strconv.ParseFloat(c.Query("nelng"), 32)
	limit := c.Query("limit", "")
	limit64, _ := strconv.ParseUint(limit, 10, 64)

	msgs := []MessageSummary{}

	// The optional postvisibility property of a group indicates the area within which members must lie for a post
	// on that group to be visibile.
	var latlng utils.LatLng

	if myid > 0 {
		latlng = user.GetLatLng(myid)
	} else {
		// Not logged in.  Best guess is centre of wherever they're looking.
		latlng.Lat = float32((swlat + nelat)) / 2
		latlng.Lng = float32((swlng + nelng)) / 2
	}

	// The posts in the bounds that everyone can see: the spatial index, which the daily batch
	// prunes of expired posts.
	db.Raw(""+
		"SELECT ST_Y(point) AS lat, "+
		"ST_X(point) AS lng, "+
		"messages_spatial.msgid AS id, "+
		"messages_spatial.successful, "+
		"messages_spatial.promised, "+
		"messages_spatial.groupid, "+
		"messages_spatial.msgtype AS type, "+
		"messages_spatial.arrival, "+
		"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen "+
		"FROM messages_spatial "+
		"INNER JOIN `groups` ON groups.id = messages_spatial.groupid "+
		"LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ? "+
		"WHERE ST_Contains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), point) "+
		"AND (CASE WHEN postvisibility IS NULL OR ST_Contains(postvisibility, ST_SRID(POINT(?, ?),?)) THEN 1 ELSE 0 END) = 1",
		myid, utils.MESSAGE_LIKES_VIEW,
		swlng, swlat,
		swlng, nelat,
		nelng, nelat,
		nelng, swlat,
		swlng, swlat,
		utils.SRID,
		latlng.Lng,
		latlng.Lat,
		utils.SRID,
	).Scan(&msgs)

	// We also want to include our own messages, so that it is less obvious if a message is delayed for approval and
	// hasn't made it into messages_spatial yet. This arm queries the messages table directly, so it bypasses the
	// spatial-index pruning above; we therefore apply the same age-based expiry the My Posts endpoint uses so an
	// aged-out own post drops off browse at the same time it drops off My Posts (rather than lingering here within
	// the 90-day window until the daily batch inserts an outcome row).
	start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")

	ownMsgs := []MessageSummary{}
	db.Raw(""+
		"SELECT messages.lat, messages.lng, messages.id, "+
		"ANY_VALUE(CASE WHEN messages_outcomes.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
		"ANY_VALUE(CASE WHEN messages_promises.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
		"MIN(messages_groups.groupid) AS groupid, "+
		"messages.type,"+
		"MAX(messages_groups.arrival) AS arrival, "+
		"ANY_VALUE(CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END) AS unseen "+
		"FROM messages "+
		"INNER JOIN messages_groups ON messages_groups.msgid = messages.id "+
		"INNER JOIN `groups` ON groups.id = messages_groups.groupid "+
		"LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id "+
		"LEFT JOIN messages_promises ON messages_promises.msgid = messages.id "+
		"LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ? "+
		"WHERE fromuser = ? AND messages_groups.arrival >= ? AND "+
		"ST_Contains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), ST_SRID(POINT(messages.lng, messages.lat), ?)) "+
		"AND (CASE WHEN postvisibility IS NULL OR ST_Contains(postvisibility, ST_SRID(POINT(?, ?),?)) THEN 1 ELSE 0 END) = 1 "+
		"AND messages_outcomes.id IS NULL "+
		"GROUP BY messages.id",
		utils.OUTCOME_TAKEN,
		utils.OUTCOME_RECEIVED,
		myid, utils.MESSAGE_LIKES_VIEW,
		myid,
		start,
		swlng, swlat,
		swlng, nelat,
		nelng, nelat,
		nelng, swlat,
		swlng, swlat,
		utils.SRID,
		utils.SRID,
		latlng.Lng,
		latlng.Lat,
		utils.SRID,
	).Scan(&ownMsgs)

	// Drop own posts that have aged out, and note their ids so they don't linger via the spatial arm
	// either (before the daily batch has pruned their spatial row).
	activeOwn := filterExpiredMessages(db, ownMsgs)
	activeOwnIDs := make(map[uint64]bool, len(activeOwn))
	for _, m := range activeOwn {
		activeOwnIDs[m.ID] = true
	}
	expiredOwn := make(map[uint64]bool)
	for _, m := range ownMsgs {
		if !activeOwnIDs[m.ID] {
			expiredOwn[m.ID] = true
		}
	}

	merged := make([]MessageSummary, 0, len(msgs)+len(activeOwn))
	seen := make(map[uint64]bool, len(msgs)+len(activeOwn))
	for _, m := range msgs {
		if expiredOwn[m.ID] || seen[m.ID] {
			continue
		}
		seen[m.ID] = true
		merged = append(merged, m)
	}
	for _, m := range activeOwn {
		if seen[m.ID] {
			continue
		}
		seen[m.ID] = true
		merged = append(merged, m)
	}
	msgs = merged

	// Order to match the old combined SQL: unseen first, then most-recent arrival, then highest id.
	sort.SliceStable(msgs, func(i, j int) bool {
		if msgs[i].Unseen != msgs[j].Unseen {
			return msgs[i].Unseen
		}
		if !msgs[i].Arrival.Equal(msgs[j].Arrival) {
			return msgs[i].Arrival.After(msgs[j].Arrival)
		}
		return msgs[i].ID > msgs[j].ID
	})

	if limit64 > 0 && uint64(len(msgs)) > limit64 {
		msgs = msgs[:limit64]
	}

	for ix, r := range msgs {
		// Protect anonymity of poster a bit.
		msgs[ix].Lat, msgs[ix].Lng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
	}

	return c.JSON(msgs)
}
