package test

// Wave 1 close-out (plan section 7.3+, database-migration-evaluation-2026-07.md
// section 7): the last raw sites across the message, dashboard, membership,
// session, group, user, recommendations and userdump modules that were left
// raw earlier in wave 1 purely because they bind a Go []uint64 slice to a
// literal "IN ?" / "IN (?)" placeholder.
//
// That was a real blocker at the time: GORM's dry-run expands a slice
// argument to "IN (?,?,?,...)", one placeholder per element, while the
// golden SQL records the source text's single "?". AssertGoldenSQL
// (ormharness/golden.go) now collapses placeholder IN lists on both sides
// of the comparison before failing, because the OLD db.Raw call expanded
// the slice identically - the executed SQL always matched, only the
// recorded golden differed, being captured from source text before
// expansion. That is what makes every site below convertible; see
// golden.go's collapseInLists doc comment for the detail, and
// orm_wave1_tail_a_test.go's 9494e3480fa0/a7496f46878c pair for the
// pattern this batch follows.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Four sites in this same "IN slice" family were deliberately NOT converted
// and have no test here:
//
//   - 70e22550dba3 (ormshadow/shadow.go CurrentBatchState) - not an IN-slice
//     site at all (single scalar lookup); left alone because ormshadow is
//     harness infrastructure, not a migration target.
//   - 22036e3caf64 (recommendations/stats.go Stats) - a hot path over
//     messages_likes (~75M rows) carrying a `/*+ MAX_EXECUTION_TIME(10000) */`
//     optimiser hint that a missing index needs to degrade gracefully on;
//     GORM has no way to attach a hint comment to Select/Where, and dropping
//     it would let a slow query hang the request instead of degrading it.
//   - f0543b22c8e8 (userdump/collect_db.go existingTables) and
//     823b16eb87c6 (userdump/userdump.go gatherEmails) - both take a raw
//     *sql.DB, not *gorm.DB; converting needs a signature change across the
//     whole userdump pipeline, which is a redesign, not a conversion.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- dashboard/dashboard.go -------------------------------------------------

func TestWave1Close_db58dba433f8(t *testing.T) {
	// GetDashboard: is the caller a moderator of any of the requested groups.
	var dest int64
	ormharness.AssertGoldenSQL(t, "db58dba433f8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND role IN (?, ?) AND groupid IN ?", 1, "Moderator", "Owner", []uint64{1, 2, 3}).
			Count(&dest)
	})
}

// 770ce1ca6e09 and 382a6666f320 are textually identical ("new members in
// range" count) and sit in the same file: GetDashboard's legacy-style branch
// and getRecentCounts. Converting only one of a pair like that renumbers the
// survivor's site ID, because the ID is hashed from (file, SQL, occurrence
// index) - so they were converted together (ratchet gate h).

func TestWave1Close_770ce1ca6e09(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "770ce1ca6e09", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("groupid IN ? AND added >= ? AND added <= ?", []uint64{1, 2, 3}, "2026-01-01", "2026-02-01").
			Count(&dest)
	})
}

func TestWave1Close_382a6666f320(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "382a6666f320", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("groupid IN ? AND added >= ? AND added <= ?", []uint64{1, 2, 3}, "2026-01-01", "2026-02-01").
			Count(&dest)
	})
}

func TestWave1Close_42a6b16c1a09(t *testing.T) {
	// getMessageBreakdown: per-group Offer/Wanted JSON breakdown rows.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "42a6b16c1a09", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("stats").Select("breakdown").
			Where("type = 'MessageBreakdown' AND groupid IN ? AND date >= ? AND date <= ?",
				[]uint64{1, 2, 3}, "2026-01-01", "2026-02-01").
			Find(&dest)
	})
}

func TestWave1Close_114e3d2c526c(t *testing.T) {
	// getStatsTimeSeries: pre-computed per-day stats for a dashboard panel.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "114e3d2c526c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("stats").Select("date, SUM(count) AS count").
			Where("type = ? AND groupid IN ? AND date >= ? AND date <= ?",
				"Activity", []uint64{1, 2, 3}, "2026-01-01", "2026-02-01").
			Group("date").Order("date ASC").Find(&dest)
	})
}

// --- group/groupWork.go -----------------------------------------------------

func TestWave1Close_1de7b48d433b(t *testing.T) {
	// GetGroupWork: pending-member counts per group.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1de7b48d433b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid, COUNT(*) as count").
			Where("groupid IN ? AND collection = ?", []uint64{1, 2, 3}, "Approved").
			Group("groupid").Find(&dest)
	})
}

func TestWave1Close_de52b33ad2c2(t *testing.T) {
	// GetGroupWork: pending-admin-application counts per group.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "de52b33ad2c2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Select("groupid, COUNT(DISTINCT id) as count").
			Where("groupid IN ? AND complete IS NULL AND pending = 1 AND heldby IS NULL", []uint64{1, 2, 3}).
			Group("groupid").Find(&dest)
	})
}

// --- membership/membership.go -----------------------------------------------

func TestWave1Close_193930b7821a(t *testing.T) {
	// getRelatedMembers: per-user login counts, used to flag never-logged-in accounts.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "193930b7821a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("userid, COUNT(*) as count").
			Where("userid IN ?", []uint64{1, 2, 3}).Group("userid").Find(&dest)
	})
}

func TestWave1Close_4d11adfa11a8(t *testing.T) {
	// getHappinessMembers: display-name lookup for a batch of user IDs.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4d11adfa11a8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id, fullname").Where("id IN ?", []uint64{1, 2, 3}).Find(&dest)
	})
}

func TestWave1Close_6110f539c46b(t *testing.T) {
	// getHappinessMembers: preferred-email lookup for the same batch of users.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6110f539c46b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid, email, preferred").
			Where("userid IN ?", []uint64{1, 2, 3}).Order("preferred DESC").Find(&dest)
	})
}

// --- message/message_list.go ------------------------------------------------

func TestWave1Close_1aa7cdd2a963(t *testing.T) {
	// ListMessagesMT: pagination cursor for a cross-posted message, keyed on the
	// MAX arrival across the queried groups (see message_list.go's comment on why
	// that must not be an arbitrary group's arrival via LIMIT 1).
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1aa7cdd2a963", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("MAX(arrival)").
			Where("msgid = ? AND groupid IN ? AND deleted = 0", 1, []uint64{1, 2, 3}).
			Find(&dest)
	})
}

// --- message/message.go -----------------------------------------------------

// 340a0eccf392 and 99480793d36b are textually identical (group settings
// lookup by ID) and sit in the same file: computeExpiresat and applyExpiry.
// Converted together for the same reason as the dashboard pair above
// (ratchet gate h).

func TestWave1Close_340a0eccf392(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "340a0eccf392", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("id, settings").Where("id IN ?", []uint64{1, 2, 3}).Find(&dest)
	})
}

func TestWave1Close_99480793d36b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "99480793d36b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("id, settings").Where("id IN ?", []uint64{1, 2, 3}).Find(&dest)
	})
}

func TestWave1Close_407bd1e3018a(t *testing.T) {
	// applyExpiry: latest chat-message date referencing each candidate post.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "407bd1e3018a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("refmsgid, MAX(date) AS latest").
			Where("refmsgid IN ?", []uint64{1, 2, 3}).Group("refmsgid").Find(&dest)
	})
}

func TestWave1Close_069e96c5c43e(t *testing.T) {
	// Search's applyBrowseFilters "Newest" sort: original post time for the
	// current page of search result IDs.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "069e96c5c43e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("id, arrival").Where("id IN ?", []uint64{1, 2, 3}).Find(&dest)
	})
}

func TestWave1Close_d17e1becbe03(t *testing.T) {
	// handleApprove: was this message flagged as spam on any authorised group.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d17e1becbe03", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("spamtype").
			Where("msgid = ? AND groupid IN ? AND spamtype IS NOT NULL", 1, []uint64{1, 2, 3}).
			Limit(1).Find(&dest)
	})
}

func TestWave1Close_6c69b307a927(t *testing.T) {
	// handleReject: which of the caller's authorised groups still have this
	// message pending (Discourse 9815 - must not move an already-actioned row).
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6c69b307a927", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").
			Where("msgid = ? AND groupid IN ? AND collection = ? AND deleted = 0", 1, []uint64{1, 2, 3}, "Pending").
			Find(&dest)
	})
}

func TestWave1Close_048db12b9b08(t *testing.T) {
	// applyPatchMessageCore: external-mod state for the caller's kept attachments.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "048db12b9b08", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Select("id, COALESCE(externalmods, '') AS externalmods").
			Where("id IN ? AND msgid = ?", []uint64{1, 2, 3}, 1).
			Find(&dest)
	})
}

func TestWave1Close_ef712c65234c(t *testing.T) {
	// heldByAnotherMod: is this message held by a DIFFERENT moderator on any of
	// the caller's authorised groups (holds are per-group).
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ef712c65234c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("heldby").
			Where("msgid = ? AND groupid IN ? AND heldby IS NOT NULL AND heldby != ? AND deleted = 0",
				1, []uint64{1, 2, 3}, 5).
			Limit(1).Find(&dest)
	})
}

func TestWave1Close_1b80281ee67a(t *testing.T) {
	// recordAIDeletions: attachments being removed by the new keep-list
	// ("NOT IN" is still a slice-bound IN list - the collapse regex matches
	// the "IN (...)" operand regardless of a preceding NOT).
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1b80281ee67a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").
			Select("id, COALESCE(externaluid, '') AS externaluid, externalmods").
			Where("msgid = ? AND id NOT IN ?", 1, []uint64{1, 2, 3}).
			Find(&dest)
	})
}

// --- session/session.go -----------------------------------------------------

func TestWave1Close_d947b0e5819b(t *testing.T) {
	// GetSession: pending-member count across the caller's mod groups.
	var dest int64
	ormharness.AssertGoldenSQL(t, "d947b0e5819b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("groupid IN ? AND collection = ?", []uint64{1, 2, 3}, "Pending").
			Count(&dest)
	})
}

func TestWave1Close_719d174ca4a7(t *testing.T) {
	// GetSession: unheld spam members in active groups (red badge).
	var dest int64
	ormharness.AssertGoldenSQL(t, "719d174ca4a7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("groupid IN ? AND reviewrequestedat IS NOT NULL "+
				"AND (reviewedat IS NULL OR reviewrequestedat > reviewedat) AND heldby IS NULL",
				[]uint64{1, 2, 3}).
			Count(&dest)
	})
}

func TestWave1Close_ef15aa1e20c6(t *testing.T) {
	// GetSession: held spam members in active groups (blue badge).
	var dest int64
	ormharness.AssertGoldenSQL(t, "ef15aa1e20c6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("groupid IN ? AND reviewrequestedat IS NOT NULL "+
				"AND (reviewedat IS NULL OR reviewrequestedat > reviewedat) AND heldby IS NOT NULL",
				[]uint64{1, 2, 3}).
			Count(&dest)
	})
}

func TestWave1Close_3a6e42ab9746(t *testing.T) {
	// GetSession: all spam members in inactive groups (blue badge, no held split).
	var dest int64
	ormharness.AssertGoldenSQL(t, "3a6e42ab9746", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("groupid IN ? AND reviewrequestedat IS NOT NULL "+
				"AND (reviewedat IS NULL OR reviewrequestedat > reviewedat)",
				[]uint64{1, 2, 3}).
			Count(&dest)
	})
}

func TestWave1Close_1d7035c837c8(t *testing.T) {
	// GetSession: pending admin applications across the caller's active groups.
	var dest int64
	ormharness.AssertGoldenSQL(t, "1d7035c837c8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").
			Where("groupid IN ? AND complete IS NULL AND pending = 1 AND heldby IS NULL", []uint64{1, 2, 3}).
			Count(&dest)
	})
}

// --- user/auth.go ------------------------------------------------------------

func TestWave1Close_22a2af60d1b3(t *testing.T) {
	// GetLoveJunkUser: resolve a partner's approximate postcode-prefix location.
	// Not an IN-slice site - this one is a plain LIKE lookup that simply had not
	// been reached by an earlier wave 1 batch.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "22a2af60d1b3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("id").
			Where("name LIKE ? AND type = ?", "SW1%", "Postcode").
			Limit(1).Find(&dest)
	})
}

// --- user/user.go -------------------------------------------------------------

func TestWave1Close_889877ad8183(t *testing.T) {
	// HasWiderReview: does the caller moderate any group with widerchatreview set.
	var dest int64
	ormharness.AssertGoldenSQL(t, "889877ad8183", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").
			Where("id IN ? AND JSON_EXTRACT(settings, '$.widerchatreview') = 1", []uint64{1, 2, 3}).
			Count(&dest)
	})
}

func TestWave1Close_a25ca71cf6cc(t *testing.T) {
	// enrichUserForModtools: unread modmail count, scoped to the caller's mod groups.
	var dest int64
	ormharness.AssertGoldenSQL(t, "a25ca71cf6cc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_modmails").
			Where("userid = ? AND groupid IN ?", 1, []uint64{1, 2, 3}).
			Count(&dest)
	})
}

// --- user/userComment.go ------------------------------------------------------

func TestWave1Close_bccf664d6580(t *testing.T) {
	// GetComments: moderator comments for a batch of users.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "bccf664d6580", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_comments").Where("userid IN ?", []uint64{1, 2, 3}).
			Order("date DESC").Find(&dest)
	})
}

func TestWave1Close_6155d59a26ec(t *testing.T) {
	// GetComments: display-name lookup for the comments' "by" users.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6155d59a26ec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id, fullname").Where("id IN ?", []uint64{1, 2, 3}).Find(&dest)
	})
}

// --- user/userEmails.go -------------------------------------------------------

func TestWave1Close_f3bad72ed518(t *testing.T) {
	// getEmails: a user's own email addresses, preferred first.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f3bad72ed518", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("id, added, bounced, preferred, email").
			Where("userid = ?", 1).
			Order("preferred DESC, email ASC").Find(&dest)
	})
}

// --- user/userInfo.go ---------------------------------------------------------

func TestWave1Close_7069b88d6489(t *testing.T) {
	// GetUserInfo: rating counts by star value, for ratings visible to the caller.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7069b88d6489", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ratings").Select("COUNT(*) AS count, rating").
			Where("ratee = ? AND timestamp >= ? AND (tn_rating_id IS NOT NULL OR rater = ? OR visible = 1)",
				1, "2026-01-01", 1).
			Group("rating").Find(&dest)
	})
}
