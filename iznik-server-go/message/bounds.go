package message

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"sort"
	"strconv"
	"time"
)

// boundsLikesChunk bounds how many msgids go into a single messages_likes IN (...) lookup used
// to compute "unseen" for the spatial arm of Bounds(). A viewport-scale polygon only matches a
// handful of messages_spatial rows, but the no-location myGroupsBoundingBox fallback (unioning
// every group a member belongs to) can match most of the table, so this keeps that IN list - and
// the statement - short regardless of how large the match set gets.
const boundsLikesChunk = 1000

// chunkWindows splits ids into consecutive windows of at most size, preserving order. Pure and
// DB-free so it can be unit tested directly; size <= 0 is treated as "everything in one window"
// rather than looping forever.
func chunkWindows(ids []uint64, size int) [][]uint64 {
	if len(ids) == 0 {
		return nil
	}
	if size <= 0 {
		size = len(ids)
	}

	windows := make([][]uint64, 0, (len(ids)+size-1)/size)
	for start := 0; start < len(ids); start += size {
		end := start + size
		if end > len(ids) {
			end = len(ids)
		}
		windows = append(windows, ids[start:end])
	}
	return windows
}

// viewedMessageIDs returns the subset of ids that have a messages_likes row for (userid, type),
// batching the lookup in boundsLikesChunk-sized IN (...) queries. Only the existing `userid`
// secondary index on messages_likes is needed (InnoDB appends the clustering key
// (msgid,userid,type) to it, so this is a covering scan) - no new index required.
func viewedMessageIDs(db *gorm.DB, ids []uint64, userid uint64, likeType string) map[uint64]bool {
	viewed := make(map[uint64]bool, len(ids))

	for _, chunk := range chunkWindows(ids, boundsLikesChunk) {
		var chunkIDs []uint64
		db.Raw("SELECT msgid FROM messages_likes WHERE userid = ? AND type = ? AND msgid IN (?)",
			userid, likeType, chunk).Scan(&chunkIDs)

		for _, id := range chunkIDs {
			viewed[id] = true
		}
	}

	return viewed
}

// applyUnseen sets the Unseen flag on msgs to match the old LEFT JOIN's CASE semantics: unseen
// when there is no messages_likes row for (msgid, myid, type). viewed is nil/empty for an
// anonymous caller (myid == 0), which correctly makes every post unseen here too, since a nil map
// read is always false - exactly what the old LEFT JOIN produced, as its ON clause could never
// match userid = 0.
func applyUnseen(msgs []MessageSummary, viewed map[uint64]bool) {
	for ix := range msgs {
		msgs[ix].Unseen = !viewed[msgs[ix].ID]
	}
}

// ownMessageIDs returns the subset of ids the given user wrote, batched in the same
// chunk size as viewedMessageIDs. It reads only the messages table's own primary key and
// fromuser, so it is a cheap way to answer "which of these are mine" for a list we have
// already decided to return.
func ownMessageIDs(db *gorm.DB, ids []uint64, userid uint64) map[uint64]bool {
	own := make(map[uint64]bool, len(ids))

	for _, chunk := range chunkWindows(ids, boundsLikesChunk) {
		var chunkIDs []uint64
		db.Raw("SELECT id FROM messages WHERE fromuser = ? AND id IN (?)", userid, chunk).Scan(&chunkIDs)

		for _, id := range chunkIDs {
			own[id] = true
		}
	}

	return own
}

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
	// NOT converted here (raw, keep-raw reason recorded in the manifest):
	// master removed the LEFT JOIN messages_likes + CASE this site used to
	// compute "unseen" inline - see the comment below this query for why
	// (it turned a wide, no-location bounding box into tens of thousands of
	// per-row lookups into the 86M-row messages_likes table; unseen is now
	// computed separately via viewedMessageIDs/applyUnseen). Re-merging a
	// GORM chain for the simplified form is plausible but was not attempted
	// here: this is a merge-conflict resolution, not a fresh conversion, and
	// this exact site was already flagged for spatial review once before
	// (see the site history this replaces). Left for a properly tested
	// re-conversion attempt rather than guessed at under a merge.
	db.Raw(""+
		"SELECT ST_Y(point) AS lat, "+
		"ST_X(point) AS lng, "+
		"messages_spatial.msgid AS id, "+
		"messages_spatial.successful, "+
		"messages_spatial.promised, "+
		"messages_spatial.groupid, "+
		"messages_spatial.msgtype AS type, "+
		"messages_spatial.arrival "+
		"FROM messages_spatial "+
		// The groups join no longer filters on visibility, but is kept so that a post whose
		// group has been deleted doesn't show up.
		"INNER JOIN `groups` ON groups.id = messages_spatial.groupid "+
		"WHERE ST_Contains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), point)",
		swlng, swlat,
		swlng, nelat,
		nelng, nelat,
		nelng, swlat,
		swlng, swlat,
		utils.SRID,
	).Scan(&msgs)

	// unseen used to come from a LEFT JOIN against messages_likes on every matched
	// messages_spatial row. For a viewport-scale polygon that's a handful of rows and is cheap,
	// but the no-location myGroupsBoundingBox fallback can send a polygon spanning most of the
	// member's country, which matched most of messages_spatial and turned into tens of thousands
	// of per-row point lookups into the 86M-row messages_likes table on every call. Computing it
	// here instead, via one batched lookup scoped to just the ids this call matched, keeps the
	// same result without that fan-out.
	var viewed map[uint64]bool
	if myid != 0 {
		ids := make([]uint64, len(msgs))
		for ix, m := range msgs {
			ids[ix] = m.ID
		}
		viewed = viewedMessageIDs(db, ids, myid, utils.MESSAGE_LIKES_VIEW)
	}
	applyUnseen(msgs, viewed)

	// We also want to include our own messages, so that it is less obvious if a message is delayed for approval and
	// hasn't made it into messages_spatial yet. This arm queries the messages table directly, so it bypasses the
	// spatial-index pruning above; we therefore apply the same age-based expiry the My Posts endpoint uses so an
	// aged-out own post drops off browse at the same time it drops off My Posts (rather than lingering here within
	// the 90-day window until the daily batch inserts an outcome row).
	start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")

	ownMsgs := []MessageSummary{}
	// Bind
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

	// Flag the viewer's own posts, as the other browse feeds do (message.Groups,
	// isochrone.Messages): the client pins them above the feed, and this endpoint is what
	// browse switches to the moment the member moves the map - so without this a member's
	// own post was pinned until they panned, then vanished into the list.
	//
	// Asked as its own batched lookup over the ids we are actually returning, rather than
	// taken from the own arm above: that arm carries its own bounds test, OPEN_AGE window and
	// outcome filter, so an own post that reached this list via the SPATIAL arm alone would
	// not be in it and would go unflagged. Authorship is not a property of which arm found
	// the post.
	if myid != 0 {
		ids := make([]uint64, len(msgs))
		for ix, m := range msgs {
			ids[ix] = m.ID
		}
		own := ownMessageIDs(db, ids, myid)
		for ix := range msgs {
			if own[msgs[ix].ID] {
				msgs[ix].Mine = true
			}
		}
	}

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
