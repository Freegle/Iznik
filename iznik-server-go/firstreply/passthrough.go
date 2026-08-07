package firstreply

import (
	"os"
	"strconv"
	"sync"

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

// rolloutPercent is the share of POSTS in the trial, 0-100, matching
// freegle.firstreply.rollout_percent on the batch side. Both doors have to bucket
// posts identically or a post would be in the trial for an emailed reply and out
// of it for an in-app one, which would make the arms meaningless.
//
// Defaults to 0 for the same reason the batch side does: forgetting to set it
// costs a quiet run, where the opposite default costs an unplanned full rollout.
func rolloutPercent() int {
	if v := os.Getenv("FIRSTREPLY_ROLLOUT_PERCENT"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			if n < 0 {
				return 0
			}
			if n > 100 {
				return 100
			}
			return n
		}
	}
	return 0
}

// inRollout buckets on msgid % 100, exactly as App\Services\FirstReply\Rollout
// does, so a post is in or out for its whole life and on both paths.
func inRollout(msgid uint64) bool {
	p := rolloutPercent()
	if p >= 100 {
		return true
	}
	if p <= 0 {
		return false
	}
	return int(msgid%100) < p
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

	// Trial arm: a post outside the rollout behaves exactly as it did before any
	// of this existed, which is what makes it a usable control.
	if !inRollout(refmsgid) {
		return false
	}

	// Distinct repliers, not messages: one person sending three messages has not
	// made the post any less silent. The poster's own messages on their own post
	// do not count either.
	var repliers int64
	if err := db.Table("chat_messages cm").
		Joins("JOIN messages m ON m.id = cm.refmsgid").
		Where("cm.refmsgid = ? AND cm.type = ? AND cm.userid <> m.fromuser",
			refmsgid, utils.CHAT_MESSAGE_INTERESTED).
		Distinct("cm.userid").
		Count(&repliers).Error; err != nil {
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
	if err := db.Table("rippling_reach").
		Select("COALESCE(MAX(ST_Contains(max_polygon, ST_SRID(POINT(?, ?), ?))), 0)",
			lng, lat, utils.SRID).
		Where("msgid = ? AND max_polygon IS NOT NULL", refmsgid).
		Scan(&within).Error; err != nil {
		return false
	}

	return within == 1
}

// SystemUserID is the id of the Freegle account, resolved once from its
// well-known address and cached for the life of the process.
//
// Exists so the client can be told that a chat is with Freegle rather than with
// a person. That matters for the header: most of what Freegle says is not a
// conversation, so offering to rate, block or report it is nonsense, and the
// "this is automated" note belongs in the header once rather than on every
// single message.
//
// Returns 0 when the account does not exist yet (it is created lazily by the
// batch app on first use), which callers treat as "no chat is a Freegle chat".
var systemUserID uint64
var systemUserOnce sync.Once

func SystemUserID(db *gorm.DB) uint64 {
	systemUserOnce.Do(func() {
		email := os.Getenv("FIRSTREPLY_SYSTEM_USER_EMAIL")
		if email == "" {
			email = "freegle@ilovefreegle.org"
		}

		var id uint64
		db.Table("users_emails").Select("userid").Where("email = ?", email).Limit(1).Scan(&id)
		systemUserID = id
	})

	return systemUserID
}
