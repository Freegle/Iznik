package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the session module's batch:
// iznik-server-go/session/session.go, facebook.go, google.go, social_auth.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Five sites from session.go's wave-1 inventory are deliberately NOT
// converted and have no test here: d947b0e5819b, 719d174ca4a7, ef15aa1e20c6,
// 3a6e42ab9746 and 1d7035c837c8 (all in GetSession's work-count block, lines
// ~1109-1164). All five bind a Go []uint64 group-ID slice to a literal
// "groupid IN (?)" placeholder; GORM's dry-run substitutes the slice
// element-by-element into that same "?", so the rendered SQL carries as many
// placeholders as the slice has elements, whereas the golden text records a
// single "?" - a real, length-dependent divergence the harness has no
// approvedDiff entry for yet. Same shape already skipped in the dashboard,
// group and membership modules; see those modules' wave 1 test files. They
// are left as raw SQL.
//
// Session code handles auth and login. WHERE-clause text and column lists
// are checked verbatim by Layer 1 (this file) - a subtly different predicate
// here would be a security-relevant bug, not a cosmetic one.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- facebook.go / google.go: skip re-saving the profile picture if one already exists ---

func TestWave1Session_de1ea2acb8f7(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "de1ea2acb8f7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").Where("userid = ?", 1).Count(&dest)
	})
}

func TestWave1Session_9257e777c710(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "9257e777c710", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").Where("userid = ?", 1).Count(&dest)
	})
}

// --- FetchEmailHealth: moderator/admin work badge email alerts ---

func TestWave1Session_4889ff2231e7(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "4889ff2231e7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").
			Where("platform = 0 AND date >= DATE_SUB(NOW(), INTERVAL 2 HOUR)").
			Count(&dest)
	})
}

func TestWave1Session_8dae60aef8bb(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "8dae60aef8bb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking").
			Where("sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)").
			Count(&dest)
	})
}

// --- handleLostPassword: native vs. social-only account check ---

func TestWave1Session_0c1bcc6cd9ae(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "0c1bcc6cd9ae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Where("userid = ? AND type = ?", 1, "Native").Count(&dest)
	})
}

func TestWave1Session_59679104df61(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "59679104df61", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Where("userid = ? AND type IN (?, ?)", 1, "Google", "Facebook").Count(&dest)
	})
}

// --- handleUnsubscribe: preferred email lookup ---

func TestWave1Session_c9d2261c0bbb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c9d2261c0bbb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", 1).
			Order("preferred DESC, id ASC").Limit(1).Find(&dest)
	})
}

// --- getOrCreateLoginKey: existing auto-login key lookup ---

func TestWave1Session_86d397f1f396(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "86d397f1f396", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?", 1, "Link").
			Limit(1).Find(&dest)
	})
}

// --- handleLinkLogin: userid+key auto-login ---

func TestWave1Session_3ae0e2e69ab8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3ae0e2e69ab8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id").Where("id = ?", 1).Limit(1).Find(&dest)
	})
}

func TestWave1Session_c82f1f1c2cd7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c82f1f1c2cd7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?", 1, "Link").
			Limit(1).Find(&dest)
	})
}

// --- handleForget: partner-authenticated and self-service account deletion ---

func TestWave1Session_ea22d033db71(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ea22d033db71", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("id").Where("`key` = ?", "partner").Find(&dest)
	})
}

func TestWave1Session_9543ef288345(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9543ef288345", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("ljuserid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Session_7db99b93d6cd(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7db99b93d6cd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").
			Limit(1).Find(&dest)
	})
}

func TestWave1Session_f091c05b08dd(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "f091c05b08dd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("userid = ? AND collection IN (?, ?)", 1, "Spammer", "PendingAdd").
			Count(&dest)
	})
}

// --- GetSession: kill-switch config lookup ---

func TestWave1Session_421ba6305db6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "421ba6305db6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("config").Select("value").Where("`key` = ?", "app_min_webversion").Find(&dest)
	})
}

// --- GetSession: parallel user/email/session/aboutme fetches ---

func TestWave1Session_0773b72a917c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0773b72a917c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id, fullname, firstname, lastname, systemrole, settings, lastaccess, added, lastlocation, onholidaytill, source, deleted, forgotten, trustlevel, permissions, marketingconsent, bouncing, relevantallowed, newslettersallowed, engagement AS engagementlevel").
			Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Session_238e8011f646(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "238e8011f646", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("id, email, preferred, validated, bounced").
			Where("userid = ?", 1).Order("preferred DESC").Find(&dest)
	})
}

func TestWave1Session_6a4184c2e662(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6a4184c2e662", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").Select("id, series, token").
			Where("id = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1Session_a5d3eac7f1b6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a5d3eac7f1b6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").Select("id, series, token").
			Where("userid = ?", 1).Limit(1).Find(&dest)
	})
}

func TestWave1Session_37ba31ef8bb0(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "37ba31ef8bb0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_aboutme").Select("text, timestamp").
			Where("userid = ?", 1).Order("timestamp DESC").Limit(1).Find(&dest)
	})
}

// --- GetSession: SpamAdmin-only spammer pending counts ---

func TestWave1Session_ef5ca788cc9e(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "ef5ca788cc9e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("collection = ?", "PendingAdd").Count(&dest)
	})
}

func TestWave1Session_e1b746a11157(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "e1b746a11157", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("collection = ?", "PendingRemove").Count(&dest)
	})
}

// --- GetSession: escalated helper conversations (Clearance permission) ---

func TestWave1Session_504611abf531(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "504611abf531", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_repliers").Where("state = 'ESCALATED'").Count(&dest)
	})
}

// --- GetSession: gift aid awaiting review (global) ---

func TestWave1Session_cae162df80a1(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "cae162df80a1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("reviewed IS NULL AND deleted IS NULL AND period != 'Declined'").Count(&dest)
	})
}

// --- GetSession: housekeeping / cron job admin badges ---

func TestWave1Session_fbf8a34131ef(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "fbf8a34131ef", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("housekeeper_tasks").
			Where("enabled = 1 AND placeholder = 0 AND ( last_status = 'failure' OR last_run_at IS NULL OR last_run_at < DATE_SUB(NOW(), INTERVAL interval_hours HOUR) )").
			Count(&dest)
	})
}

func TestWave1Session_58f7851e95e9(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "58f7851e95e9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("cron_job_status").Where("last_exit_code IS NOT NULL AND last_exit_code != 0").Count(&dest)
	})
}

func TestWave1Session_6f6fca850fd6(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "6f6fca850fd6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("cron_job_status").Count(&dest)
	})
}

// --- GetSession: me.lat/me.lng fallback location lookup ---

func TestWave1Session_501faa48bf9b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "501faa48bf9b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("name, lat, lng").Where("id = ?", 1).Find(&dest)
	})
}

// --- PatchSession: email confirmation via validatekey ---

func TestWave1Session_cec96ac6422d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "cec96ac6422d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("id, userid, email").
			Where("validatekey = ? AND (validatetime IS NULL OR validatetime > NOW() - INTERVAL 7 DAY)", "key").
			Find(&dest)
	})
}

// --- DeleteSession: resolve series for the authenticating session row ---

func TestWave1Session_21b921f5f200(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "21b921f5f200", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").Select("series").Where("id = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

// --- socialMatchOrCreate (social_auth.go): social-login match, TN-user guard ---

func TestWave1Session_304e1cc5f2e5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "304e1cc5f2e5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("userid").Where("type = ? AND uid = ?", "Google", "123").
			Limit(1).Find(&dest)
	})
}

func TestWave1Session_64602d672727(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "64602d672727", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("tnuserid").Where("id = ?", 1).Find(&dest)
	})
}
