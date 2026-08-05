package firstreply

import (
	"os"
	"strconv"

	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// The first-reply passthrough.
//
// A reply from someone the post's ripple has not reached yet is normally held:
// the message is created, but the poster is not told about it until the reach
// catches up. That is the right trade on a post that already has replies -
// local-first ordering is worth a delay to someone further away.
//
// It is the wrong trade on a post that has NO replies. The person being held is
// inside the reach the post will EVENTUALLY have, so they were always going to be
// allowed to reply; holding them changes when the poster hears, not whether. And
// a poster who hears nothing has no way to tell a delayed first reply from no
// interest at all, which is the state 44% of rippled posts end up in.
//
// So: a post's first reply is never held, provided the replier is inside the
// post's eventual reach. Everything after that behaves exactly as before.
//
// Mirrors App\Services\Ripple\RippleReplyService::qualifiesForFirstReplyPassthrough
// on the batch side, which governs the email and TrashNothing reply paths. The two
// have to agree, because which door a reply came through is not something the
// poster knows or should care about.

// Enabled reports whether the passthrough is switched on. Two separate switches so
// the whole first-reply feature can be turned off in one go without losing the
// per-lever control.
func Enabled() bool {
	return envTrue("FIRSTREPLY_ENABLED") && envTrue("FIRSTREPLY_PASSTHROUGH_ENABLED")
}

func envTrue(name string) bool {
	v := os.Getenv(name)
	return v == "true" || v == "1"
}

// maxExistingRepliers is how many distinct repliers a post may already have and
// still get the passthrough. Kept in step with
// freegle.firstreply.passthrough.max_existing_repliers; 1 means only the very
// first reply. An unset or unparseable value falls back to the default rather
// than to zero, which would silently disable the feature.
func maxExistingRepliers() int {
	if v := os.Getenv("FIRSTREPLY_PASSTHROUGH_MAX_REPLIERS"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			return n
		}
	}
	return 1
}

// ShouldPassThrough reports whether an out-of-current-reach reply to refmsgid from
// (lng, lat) should be delivered immediately instead of held.
//
// Deliberately conservative at every step: switched off, no max_polygon populated
// yet, a query error, or a post that already has repliers all mean "no", which
// leaves the existing hold in place. Being wrong in that direction costs a delay;
// being wrong the other way would deliver a reply the reach never covers.
func ShouldPassThrough(db *gorm.DB, refmsgid uint64, lng, lat float64) bool {
	if !Enabled() {
		return false
	}

	// Distinct repliers, not messages: one person sending three messages has not
	// made the post any less silent. The poster's own messages on their own post
	// do not count either.
	var repliers int64
	if err := db.Raw(
		"SELECT COUNT(DISTINCT cm.userid) FROM chat_messages cm "+
			"JOIN messages m ON m.id = cm.refmsgid "+
			"WHERE cm.refmsgid = ? AND cm.type = ? AND cm.userid <> m.fromuser",
		refmsgid, utils.CHAT_MESSAGE_INTERESTED).Scan(&repliers).Error; err != nil {
		return false
	}

	// The reply being created is not in the table yet, so this counts everyone
	// who replied BEFORE this one.
	if repliers >= int64(maxExistingRepliers()) {
		return false
	}

	// max_polygon is populated by the firstreply:maxreach batch pass and is NULL
	// until it gets there (and on any deploy that predates the migration), so a
	// missing column or value degrades to the existing hold behaviour.
	var within int
	if err := db.Raw(
		"SELECT COALESCE(MAX(ST_Contains(max_polygon, ST_SRID(POINT(?, ?), ?))), 0) "+
			"FROM rippling_reach WHERE msgid = ? AND max_polygon IS NOT NULL",
		lng, lat, utils.SRID, refmsgid).Scan(&within).Error; err != nil {
		return false
	}

	return within == 1
}
