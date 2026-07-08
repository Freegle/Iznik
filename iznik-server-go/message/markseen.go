package message

import (
	"sort"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// markSeenChunk bounds how many message ids go into a single INSERT statement. "Mark seen" on a
// busy browse feed can pass hundreds of ids; one INSERT per id (the old behaviour) meant one DB
// round-trip per unread post, so the call scaled linearly with the unread count and felt slow.
// Batching into multi-row INSERTs collapses that to a handful of round-trips.
//
// The chunk is kept moderate rather than "all in one" deliberately for Galera (Percona XtraDB
// Cluster): a multi-row INSERT is a single transaction, so a certification conflict rolls the
// WHOLE statement back. A moderate chunk bounds that blast radius and the lock footprint while
// still cutting round-trips ~100x for a typical feed. It also stays well under max_allowed_packet
// and the placeholder limit.
const markSeenChunk = 100

// MarkSeenRequest is the request body for marking messages as seen.
type MarkSeenRequest struct {
	IDs []uint64 `json:"ids"`
}

// MarkSeen marks messages as seen (viewed) by the authenticated user.
// @Summary Mark messages as seen
// @Description Records that the authenticated user has viewed the specified messages.
// @Tags messages
// @Accept json
// @Produce json
// @Param ids body []int true "Array of message IDs to mark as seen"
// @Success 200 {object} map[string]interface{} "Success response"
// @Failure 400 {object} map[string]string "Invalid request or empty IDs"
// @Failure 401 {object} map[string]string "Not logged in"
// @Router /messages/markseen [post]
func MarkSeen(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req MarkSeenRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if len(req.IDs) == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Message IDs required")
	}

	db := database.DBConn

	// Sort + de-duplicate the ids. De-dup guards against a caller repeating an id (which would
	// otherwise appear twice in one multi-row INSERT and hit the ON DUPLICATE KEY UPDATE against
	// its own earlier row within the same statement). Sorting makes every mark-seen acquire row
	// locks in a consistent (ascending) order, so two concurrent same-user mark-seens can't
	// deadlock by grabbing the same rows in opposite orders.
	ids := dedupeSortedIDs(req.IDs)

	// Insert a 'View' record per message (marks it "seen" for list de-duplication).
	// pageview=0 marks this as a list-scroll impression, distinct from a genuine
	// page-open (handleView writes 1). It is set only when this INSERT *creates* the
	// row; the ON DUPLICATE KEY UPDATE deliberately does NOT touch pageview, so an
	// existing genuine view (1) or legacy/unknown row (NULL) is never downgraded.
	//
	// The rows are batched into multi-row INSERTs (markSeenChunk at a time) rather than one
	// statement per id, so marking a large unread feed as seen is a few round-trips instead of
	// one per post. The ON DUPLICATE KEY UPDATE clause is per-conflicting-row, so batching
	// preserves the exact per-message semantics of the old loop.
	for start := 0; start < len(ids); start += markSeenChunk {
		end := start + markSeenChunk
		if end > len(ids) {
			end = len(ids)
		}
		chunk := ids[start:end]

		// RetryExec retries transient connection/WSREP errors (Galera node not yet synced). It
		// does NOT retry deadlocks/lock-timeouts - a Galera certification conflict rolls the whole
		// multi-row statement back. If that happens we fall back to per-row inserts for this chunk
		// so the rest still land: each is its own tiny autocommit transaction that certifies
		// independently (exactly the old behaviour), degrading gracefully instead of losing the
		// whole chunk. In practice this is rare: mark-seen rows are keyed (msgid, userid, type),
		// so different users never conflict; only concurrent same-user mark-seens can.
		if err := insertViewBatch(db, chunk, myid); err != nil && database.IsDeadlockOrLockTimeout(err) {
			for _, msgID := range chunk {
				_ = database.RetryExec(db,
					"INSERT INTO messages_likes (msgid, userid, type, pageview) VALUES (?, ?, ?, 0) "+
						"ON DUPLICATE KEY UPDATE timestamp = NOW(), count = count + 1",
					msgID, myid, utils.MESSAGE_LIKES_VIEW)
			}
		}
	}

	return c.JSON(fiber.Map{
		"success": true,
	})
}

// insertViewBatch inserts (or bumps) a 'View' row for every msgid in the chunk in a single
// multi-row INSERT ... ON DUPLICATE KEY UPDATE, retrying transient connection/WSREP errors.
func insertViewBatch(db *gorm.DB, chunk []uint64, myid uint64) error {
	placeholders := make([]string, len(chunk))
	args := make([]interface{}, 0, len(chunk)*3)
	for i, msgID := range chunk {
		placeholders[i] = "(?, ?, ?, 0)"
		args = append(args, msgID, myid, utils.MESSAGE_LIKES_VIEW)
	}
	return database.RetryExec(db,
		"INSERT INTO messages_likes (msgid, userid, type, pageview) VALUES "+
			strings.Join(placeholders, ",")+
			" ON DUPLICATE KEY UPDATE timestamp = NOW(), count = count + 1",
		args...)
}

// dedupeSortedIDs returns the ids sorted ascending with duplicates removed.
func dedupeSortedIDs(in []uint64) []uint64 {
	if len(in) < 2 {
		return in
	}
	sorted := make([]uint64, len(in))
	copy(sorted, in)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i] < sorted[j] })
	out := sorted[:1]
	for _, v := range sorted[1:] {
		if v != out[len(out)-1] {
			out = append(out, v)
		}
	}
	return out
}
