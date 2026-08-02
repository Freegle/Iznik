package test

// Wave 1 tail, group B (plan section 7.3+, database-migration-evaluation-2026-07.md
// section 7): every remaining small wave-1 module not owned by another agent, pulled
// fresh from the manifest rather than from a fixed module list (some hinted module
// names did not exist or had no wave-1 raw sites; several modules turned up in the
// fresh query that were not in the original hint list either - address, browse,
// config, domain, housekeeper, item, logs, modtools, recommendations, rippling,
// systemlogs - and are covered here too since no other agent claimed them).
//
// Modules covered: abtest, address, aiimage, alert, amp, authority, browse, changes,
// config, domain, emailtracking, export, housekeeper, item, location, logs, misc,
// modconfig, modtools, rippling, spammers, sso, status, story, systemlogs, town,
// tryst, userdump.
//
// Each test names its site ID. The extractor only counts a site converted once a
// parity test bearing its ID exists and passes - see ormharness.AssertGoldenSQL's
// doc comment (golden.go) and plan 7.2's Gate 2.
//
// 73 raw-SQL sites were recorded across these modules in wave 1. 70 are converted
// below. Three were skipped and left on their original form - see the header
// comment above each skip site in production code for the reasoning:
//
//   - 22036e3caf64 (recommendations/stats.go, GetRecommendationFunnel): "source IN
//     ? AND timestamp >= ?" binds a variable-length Go slice (trackedSources) to
//     IN ? - length-dependent expansion, on the "skip and report" list. Also a hot
//     path (messages_likes, ~75M rows).
//   - f0543b22c8e8 (userdump/collect_db.go, existingTables) and 823b16eb87c6
//     (userdump/userdump.go, gatherEmails): both take a raw *sql.DB (not
//     *gorm.DB), threaded from buildDump through buildPlan/scanIDs/runDBSpec for
//     the dynamic-schema SQLite export builder - the same raw-DB architecture the
//     harness doc's "what stays raw" section calls out for userdump/sqlite.go.
//     Converting only these two of four sibling functions would split one
//     dynamic-schema dump pipeline across two connection-access patterns, and
//     needs a signature change (sql.DB -> gorm.DB) beyond a mechanical
//     db.Raw-to-GORM swap.
//
// Two conversions are worth flagging explicitly:
//
//   - c097d3e46f7f (authority.go, Search) has a genuinely runtime-bound
//     "LIMIT ?" in its golden (the original code parameterised the limit with a
//     real Go variable, not a hardcoded literal). This only became convertible
//     after AssertGoldenSQL started trying the unresolved render against the
//     golden before the resolved one (see golden.go and wave1-tail-a's header
//     comment in orm_wave1_tail_a_test.go, which hit and fixed the same gap).
//     Converted below using .Limit(limit) with the original's own variable.
//   - Three sites (2f3a9cd57a29 in location.go, 683a3a4c4854 in modconfig.go,
//     5dc370f37ed3 in logs.go) use .Pluck(...) in production. Pluck is not one
//     of AssertGoldenSQL's accepted terminals (Find, Count, Create, Delete,
//     Update, Take, First - golden.go), so their tests below use
//     .Select(...).Find(&dest) instead purely to trigger the render; Pluck sets
//     the same Select clause internally when exactly one column is requested
//     (gorm.io/gorm@v1.31.0 finisher_api.go's Pluck, chainable_api.go's
//     Distinct), so the rendered SQL is identical either way.
import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- abtest.go: GetABTest -----------------------------------------------------

func TestWave1TailB_b8d3220fdb2f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b8d3220fdb2f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("abtest").Where("uid = ? AND suggest = 1", "u").Order("rate DESC, RAND()").Find(&dest)
	})
}

// --- address.go: Patch / Delete ownership checks -------------------------------

func TestWave1TailB_c9552d1439f7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c9552d1439f7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_e5e485de6a36(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e5e485de6a36", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

// --- aiimage.go: ListReview / Regenerate / Accept / Suppress / Count -----------

func TestWave1TailB_f1f623a9631a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f1f623a9631a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").
			Select("id, name, COALESCE(externaluid, '') AS externaluid, status, regeneration_notes, pending_externaluid").
			Where("status IN ('rejected', 'regenerating')").
			Order("id DESC").
			Find(&dest)
	})
}

func TestWave1TailB_f77079a22d72(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f77079a22d72", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Select("name").Where("id = ? AND status IN ('rejected', 'regenerating')", 1).Find(&dest)
	})
}

func TestWave1TailB_db12a9f0ba25(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "db12a9f0ba25", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Select("COALESCE(externaluid, '') AS externaluid, pending_externaluid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_e6f234781920(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e6f234781920", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_701f9b6a7b6e(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "701f9b6a7b6e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("status IN ('rejected', 'regenerating')").Count(&dest)
	})
}

// --- alert.go: GetAlert / ListAlerts --------------------------------------------

func TestWave1TailB_1b28d8692d77(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1b28d8692d77", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("alerts").
			Select("id, createdby, groupid, `from`, `to`, subject, text, html, askclick, tryhard, complete, created").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1TailB_69fd80c0297d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "69fd80c0297d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("alerts_tracking").
			Select("response, COUNT(*) AS count").
			Where("alertid = ? AND response IS NOT NULL", 1).
			Group("response").
			Find(&dest)
	})
}

func TestWave1TailB_40675ee7a91d(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "40675ee7a91d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("alerts_tracking").Where("alertid = ?", 1).Count(&dest)
	})
}

func TestWave1TailB_28fbc7fe399f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "28fbc7fe399f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("alerts").
			Select("id, createdby, groupid, `from`, `to`, subject, text, html, askclick, tryhard, complete, created").
			Order("created DESC").
			Find(&dest)
	})
}

// --- amp.go: chat_roster membership checks --------------------------------------

func TestWave1TailB_0e6418b09480(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0e6418b09480", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Select("userid").Where("chatid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1TailB_f726d20766fe(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f726d20766fe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Select("userid").Where("chatid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

// --- authority.go: Search / authority/stats.go: average weight -----------------

func TestWave1TailB_c097d3e46f7f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c097d3e46f7f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("authorities").Select("id, name, area_code").Where("name LIKE ?", "%x%").Limit(10).Find(&dest)
	})
}

func TestWave1TailB_a496537bc045(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a496537bc045", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("items").Select("SUM(popularity * weight) / SUM(popularity) AS average").Where("weight IS NOT NULL AND weight != 0").Find(&dest)
	})
}

// --- browse/scroll.go: scroll-depth histogram -----------------------------------

func TestWave1TailB_44d646441e3f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "44d646441e3f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("browse_scroll_depth").
			Select("max_position, COUNT(*) AS cnt").
			Where("created_at BETWEEN ? AND ?", "2026-01-01", "2026-01-31").
			Group("max_position").
			Find(&dest)
	})
}

// --- changes.go: GetChanges ------------------------------------------------------

func TestWave1TailB_baf96c48b316(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "baf96c48b316", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("id").Where("`key` = ?", "k").Find(&dest)
	})
}

func TestWave1TailB_9e34a0df6578(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9e34a0df6578", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id, lastupdated").Where("lastupdated IS NOT NULL AND lastupdated >= ?", "2026-01-01 00:00:00").Find(&dest)
	})
}

func TestWave1TailB_82b4c19c846e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "82b4c19c846e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ratings").
			Select("id, rater, ratee, rating, timestamp, visible, tn_rating_id, text, reason").
			Where("timestamp >= ? AND visible = 1", "2026-01-01 00:00:00").
			Find(&dest)
	})
}

// --- config/config.go: Get -------------------------------------------------------

func TestWave1TailB_1f790095e709(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1f790095e709", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("config").Where("`key` = ?", "k").Find(&dest)
	})
}

// --- domain/domain.go: GetDomain --------------------------------------------------

func TestWave1TailB_403e1cd2de43(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "403e1cd2de43", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("domains_common").Select("id").Where("domain LIKE ?", "d").Find(&dest)
	})
}

func TestWave1TailB_b8fd8e1ebb61(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b8fd8e1ebb61", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("domains_common").
			Select("domain").
			Where("damlevlim(domain, ?, LENGTH(?)) < 3", "d", "d").
			Order("count DESC").
			Limit(5).
			Find(&dest)
	})
}

// --- emailtracking.go: UserEmails / ReengageEffectiveness -------------------------

func TestWave1TailB_5335567292aa(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5335567292aa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ? AND backwards IS NULL", "e").Limit(1).Find(&dest)
	})
}

func TestWave1TailB_39805074ce3d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "39805074ce3d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id").Where("email = ?", "e").Limit(1).Find(&dest)
	})
}

func TestWave1TailB_9db29fd9c43a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9db29fd9c43a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("reengage r").
			Select("r.segment AS segment, COUNT(*) AS sent, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
			Where("r.sentat BETWEEN ? AND ? AND r.segment IS NOT NULL", "2026-01-01", "2026-01-31").
			Group("r.segment").
			Order("r.segment ASC").
			Find(&dest)
	})
}

// --- export.go: PostExport / GetExport --------------------------------------------

func TestWave1TailB_b6628daeeaf7(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "b6628daeeaf7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_exports").Where("userid = ? AND completed IS NULL", 1).Count(&dest)
	})
}

func TestWave1TailB_407acfe2232b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "407acfe2232b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_exports").
			Select("id, userid, requested, started, completed, data").
			Where("userid = ? AND id = ? AND tag = ?", 1, 2, "t").
			Find(&dest)
	})
}

func TestWave1TailB_796077395eca(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "796077395eca", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_exports").Where("id < ? AND completed IS NULL", 1).Count(&dest)
	})
}

// --- housekeeper/housekeeper.go: ListTasks ----------------------------------------

func TestWave1TailB_2c37feb50055(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2c37feb50055", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("housekeeper_tasks").Order("task_key").Find(&dest)
	})
}

// --- item/impact.go: loadStandardWeights / findExactItemWeight / populationAverageWeight

func TestWave1TailB_6ae9df9b7bcc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6ae9df9b7bcc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("weights").Select("CASE WHEN simplename IS NOT NULL THEN simplename ELSE name END AS name, weight").Find(&dest)
	})
}

func TestWave1TailB_e9988d77269d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e9988d77269d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("items").
			Select("name, weight").
			Where("name = ? AND weight IS NOT NULL AND weight != 0", "n").
			Limit(1).
			Find(&dest)
	})
}

func TestWave1TailB_d25398c4a0b6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d25398c4a0b6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("items").Select("SUM(popularity*weight)/SUM(popularity) AS average").Where("weight IS NOT NULL AND weight != 0").Find(&dest)
	})
}

// --- item/reusebenefit.go: LoadCPIData --------------------------------------------

func TestWave1TailB_346c71c939e2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "346c71c939e2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("config").Select("value").Where("`key` = ?", "k").Limit(1).Find(&dest)
	})
}

// --- location.go: Resolve / UpdateLocation / ExcludeLocation ----------------------

func TestWave1TailB_845387e20e9b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "845387e20e9b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").
			Select("id, name, type, lat, lng, areaid").
			Where("name = ?", "n").
			Order("FIELD(type, 'Polygon', 'Postcode', 'Road', 'Line', 'Point'), id").
			Limit(1).
			Find(&dest)
	})
}

func TestWave1TailB_72908ace6717(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "72908ace6717", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("name").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_f987324c1334(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f987324c1334", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("name").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_2f3a9cd57a29(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2f3a9cd57a29", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("id").Where("name = ? AND id != ?", "n", 1).Find(&dest)
	})
}

// --- logs/logs.go: moderated-groups lookup ----------------------------------------

func TestWave1TailB_5dc370f37ed3(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5dc370f37ed3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Find(&dest)
	})
}

// --- misc/illustration.go: GetIllustration cache check -----------------------------

func TestWave1TailB_cb7eb47d0076(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "cb7eb47d0076", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Select("externaluid").Where("name = ?", "n").Find(&dest)
	})
}

// --- modconfig/modconfig.go: GetModConfig using-list / DeleteModConfig in-use ------

func TestWave1TailB_683a3a4c4854(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "683a3a4c4854", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships m").
			Distinct("m.userid").
			Where("m.configid = ? AND m.role IN (?, ?)", 1, "Moderator", "Owner").
			Limit(10).
			Find(&dest)
	})
}

func TestWave1TailB_8a1e00c30243(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "8a1e00c30243", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("configid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Count(&dest)
	})
}

// --- modtools/modconfig.go: GetModConfig single config + stdmsgs -------------------

func TestWave1TailB_f719d0218049(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f719d0218049", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_494b5692c466(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "494b5692c466", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("configid = ?", 1).Order("id").Find(&dest)
	})
}

// --- rippling/attribution.go: AttributionSchemaReady --------------------------------

func TestWave1TailB_33d8780a286d(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "33d8780a286d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("information_schema.COLUMNS").
			Where("TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rippling_reply_attribution' AND COLUMN_NAME = 'attribution'").
			Count(&dest)
	})
}

// --- rippling/reachbounds.go: ReachBoundsReady ---------------------------------------

func TestWave1TailB_417a0b24d8d6(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "417a0b24d8d6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("information_schema.COLUMNS").
			Where("table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'outer_bound'").
			Count(&dest)
	})
}

// --- rippling/metrics.go: totals / held_reply_by_source sections --------------------

func TestWave1TailB_7b13019b71cf(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7b13019b71cf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_event_metrics").
			Select("'' AS day, event, COALESCE(SUM(count), 0) AS count").
			Group("event").
			Order("event").
			Find(&dest)
	})
}

func TestWave1TailB_7a72ebd3ef4b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7a72ebd3ef4b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_held_replies").
			Select("source, status, COUNT(*) AS count").
			Group("source, status").
			Order("source, status").
			Find(&dest)
	})
}

// --- spammers.go: GetSpammers / PostSpammer / PatchSpammer / ExportSpammers ----------

func TestWave1TailB_4d467f8ef688(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4d467f8ef688", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("id").Where("`key` = ?", "k").Find(&dest)
	})
}

func TestWave1TailB_3a1a2fda0491(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "3a1a2fda0491", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("userid = ?", 1).Count(&dest)
	})
}

func TestWave1TailB_b35f73621756(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b35f73621756", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Select("collection, reason, byuserid, heldby").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_4e48616b5b66(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4e48616b5b66", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_9f33a7d0a5b1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9f33a7d0a5b1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("id").Where("`key` = ?", "k").Find(&dest)
	})
}

// --- sso/discourse.go: validateDiscourseSession user detail lookups -----------------

func TestWave1TailB_2edbbcc133b4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2edbbcc133b4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_0e811eecaa3a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0e811eecaa3a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", 1).Order("preferred DESC").Limit(1).Find(&dest)
	})
}

func TestWave1TailB_5ad089d40930(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5ad089d40930", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").Select("url").Where("userid = ?", 1).Order("id DESC").Limit(1).Find(&dest)
	})
}

func TestWave1TailB_fc671dfc8f18(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fc671dfc8f18", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("systemrole").Where("id = ?", 1).Find(&dest)
	})
}

// --- status/status.go: GetVersion Laravel commit lookup -------------------------------

func TestWave1TailB_a631a85cab7d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a631a85cab7d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("config").Select("value").Where("`key` = 'deploy.laravel_commit'").Find(&dest)
	})
}

// --- story.go: canModStory / PatchStory before-and-after state -----------------------

func TestWave1TailB_bedf24ad52b9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "bedf24ad52b9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_cb4e17982644(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "cb4e17982644", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").
			Select("reviewed, public, userid, COALESCE(fromnewsfeed, 0) AS fromnewsfeed").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1TailB_88a8f87abcde(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "88a8f87abcde", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").
			Select("reviewed, public, userid, COALESCE(fromnewsfeed, 0) AS fromnewsfeed").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- systemlogs/systemlogs.go: RequireModeratorMiddleware / canViewGroupLogs ---------

func TestWave1TailB_85edeab31954(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "85edeab31954", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Count(&dest)
	})
}

func TestWave1TailB_70ea6178db24(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "70ea6178db24", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ? AND role IN (?, ?)", 1, 2, "Moderator", "Owner").
			Count(&dest)
	})
}

// --- town.go: reachable-towns bounding box --------------------------------------------

func TestWave1TailB_ed5b9c0716a2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ed5b9c0716a2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("towns").
			Select("id, name, lat, lng").
			Where("lat IS NOT NULL AND lng IS NOT NULL AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?", 1.0, 2.0, 3.0, 4.0).
			Order("id").
			Find(&dest)
	})
}

// --- tryst.go: GetTryst / PatchTryst / PostTryst / DeleteTryst -------------------------

func TestWave1TailB_eb5bf7b5109a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "eb5bf7b5109a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_488c92a4f115(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "488c92a4f115", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("(user1 = ? OR user2 = ?) AND arrangedfor >= NOW()", 1, 1).Find(&dest)
	})
}

func TestWave1TailB_5ab7247c7c0c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5ab7247c7c0c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_f01a084039fd(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f01a084039fd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailB_ae0478d1c57d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ae0478d1c57d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Find(&dest)
	})
}

// --- userdump/userdump.go: GetUserDump target-user existence check ---------------------

func TestWave1TailB_3777f46262ba(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "3777f46262ba", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Count(&dest)
	})
}
