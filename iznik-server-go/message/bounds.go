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

	// Groups used to be able to restrict visibility to viewers inside a moderator-drawn
	// postvisibility polygon. That's gone: how far a freegler sees is now their own choice,
	// via the distance slider and the rippling reach model, and a group can't override it.

	// The posts in the bounds that everyone can see: the spatial index, which the daily batch
	// prunes of expired posts.
	// ORM migration site f4e3b1a23446 (Tier 1 spatial review, round 2).
	db.Table("messages_spatial").
		Select("ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, "+
			"messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, "+
			"messages_spatial.msgtype AS type, messages_spatial.arrival, "+
			"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen").
		// The groups join no longer filters on visibility, but is kept so that a post whose
		// group has been deleted doesn't show up.
		Joins("INNER JOIN `groups` ON groups.id = messages_spatial.groupid").
		Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
		Where("ST_Contains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), point)",
			swlng, swlat, swlng, nelat, nelng, nelat, nelng, swlat, swlng, swlat, utils.SRID).
		Scan(&msgs)

	// We also want to include our own messages, so that it is less obvious if a message is delayed for approval and
	// hasn't made it into messages_spatial yet. This arm queries the messages table directly, so it bypasses the
	// spatial-index pruning above; we therefore apply the same age-based expiry the My Posts endpoint uses so an
	// aged-out own post drops off browse at the same time it drops off My Posts (rather than lingering here within
	// the 90-day window until the daily batch inserts an outcome row).
	start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")

	ownMsgs := []MessageSummary{}
	// ORM migration site 72fd7dc3ca1e (Tier 1 spatial review, round 2). Bind
	// order mirrors clause build order: Select's own two binds (the IN list),
	// then the bound messages_likes Joins ON clause, then Where.
	db.Table("messages").
		Select("messages.lat, messages.lng, messages.id, "+
			"ANY_VALUE(CASE WHEN messages_outcomes.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
			"ANY_VALUE(CASE WHEN messages_promises.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
			"MIN(messages_groups.groupid) AS groupid, "+
			"messages.type,"+
			"MAX(messages_groups.arrival) AS arrival, "+
			"ANY_VALUE(CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END) AS unseen",
			utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED).
		Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
		Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid").
		Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
		Joins("LEFT JOIN messages_promises ON messages_promises.msgid = messages.id").
		Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
		Where("fromuser = ? AND messages_groups.arrival >= ? AND "+
			"ST_Contains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), ST_SRID(POINT(messages.lng, messages.lat), ?)) "+
			"AND messages_outcomes.id IS NULL",
			myid, start, swlng, swlat, swlng, nelat, nelng, nelat, nelng, swlat, swlng, swlat, utils.SRID, utils.SRID).
		Group("messages.id").
		Scan(&ownMsgs)

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
