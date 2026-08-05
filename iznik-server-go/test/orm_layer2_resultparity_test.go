package test

// Layer 2 of plan 7.2: result parity on the seeded database.
//
// Layer 1 proves two statements have the same SQL text. That is necessary and
// not sufficient. Text parity cannot see a collation difference, a NULL that
// becomes a zero, an implicit cast, or a column the driver hands back with a
// different type - all of which change what a caller actually receives. Layer 2
// runs both statements for real and compares the rows.
//
// Until now Layer 2 covered three sites, all from the wave 0 pilot, while 982
// sites had been converted on Layer 1 evidence alone. These are the converted
// SELECTs whose SQL carries no bind parameters, which makes them the set that
// can be run as-is with no fixture assumptions: the same statement, no args,
// both ways. Sites taking parameters need values chosen per site to return
// meaningful rows, and are not covered here - see the coverage note in
// plans/active/orm-migration-v2-api.md.
//
// The replacement chain deliberately stops SHORT of a terminal call, because
// AssertResultParity invokes Rows() itself. That is the opposite convention to
// AssertGoldenSQL, which requires a terminal. Where the original chain ended in
// Count, the aggregate lived in the terminal rather than the chain, so
// Select("COUNT(*)") replaces it - otherwise dropping the terminal would
// quietly turn SELECT COUNT(*) into SELECT *.

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// chat/chatmessage.go
func TestLayer2_1bd8efb9de20(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT domain FROM spam_whitelist_links WHERE count >= 3 AND LENGTH(domain) > 5 AND domain NOT LIKE '%linkedin%' AND domain NOT LIKE '%goo.gl%' AND domain NOT LIKE '%bit.ly%' AND domain NOT LIKE '%tinyurl%'", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("spam_whitelist_links").
				Select("domain").
				Where("count >= 3 AND LENGTH(domain) > 5 AND domain NOT LIKE '%linkedin%' AND domain NOT LIKE '%goo.gl%' AND domain NOT LIKE '%bit.ly%' AND domain NOT LIKE '%tinyurl%'")
		})
}

// alert/alert.go
func TestLayer2_28fbc7fe399f(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT id, createdby, groupid, `from`, `to`, subject, text, html, askclick, tryhard, complete, created FROM alerts ORDER BY created DESC", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("alerts").
				Select("id, createdby, groupid, `from`, `to`, subject, text, html, askclick, tryhard, complete, created").
				Order("created DESC")
		})
}

// housekeeper/housekeeper.go
func TestLayer2_2c37feb50055(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT * FROM housekeeper_tasks ORDER BY task_key", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("housekeeper_tasks").Order("task_key")
		})
}

// rippling/attribution.go
func TestLayer2_33d8780a286d(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rippling_reply_attribution' AND COLUMN_NAME = 'attribution'", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("information_schema.COLUMNS").
				Select("COUNT(*)").
				Where("TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rippling_reply_attribution' AND COLUMN_NAME = 'attribution'")
		})
}

// rippling/reachbounds.go
func TestLayer2_417a0b24d8d6(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'outer_bound'", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("information_schema.COLUMNS").
				Select("COUNT(*)").
				Where("table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'outer_bound'")
		})
}

// session/session.go
func TestLayer2_4889ff2231e7(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM chat_messages WHERE platform = 0 AND date >= DATE_SUB(NOW(), INTERVAL 2 HOUR)", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("chat_messages").
				Select("COUNT(*)").
				Where("platform = 0 AND date >= DATE_SUB(NOW(), INTERVAL 2 HOUR)")
		})
}

// session/session.go
func TestLayer2_504611abf531(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM helper_repliers WHERE state = 'ESCALATED'", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("helper_repliers").
				Select("COUNT(*)").Where("state = 'ESCALATED'")
		})
}

// shortlink/shortlink.go
func TestLayer2_5604e1f583b4(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT * FROM shortlinks ORDER BY LOWER(name) ASC", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("shortlinks").Order("LOWER(name) ASC")
		})
}

// session/session.go
func TestLayer2_58f7851e95e9(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM cron_job_status WHERE last_exit_code IS NOT NULL AND last_exit_code != 0", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("cron_job_status").
				Select("COUNT(*)").Where("last_exit_code IS NOT NULL AND last_exit_code != 0")
		})
}

// item/impact.go
func TestLayer2_6ae9df9b7bcc(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT CASE WHEN simplename IS NOT NULL THEN simplename ELSE name END AS name, weight FROM weights", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("weights").Select("CASE WHEN simplename IS NOT NULL THEN simplename ELSE name END AS name, weight")
		})
}

// session/session.go
func TestLayer2_6f6fca850fd6(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM cron_job_status", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("cron_job_status").Select("COUNT(*)")
		})
}

// aiimage/aiimage.go
func TestLayer2_701f9b6a7b6e(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM ai_images WHERE status IN ('rejected', 'regenerating')", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("ai_images").
				Select("COUNT(*)").Where("status IN ('rejected', 'regenerating')")
		})
}

// simulation/simulation.go
func TestLayer2_76e0992276ef(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT id, name, description, created, completed, parameters, filters, message_count, metrics, status FROM simulation_message_isochrones_runs WHERE status = 'completed' ORDER BY created DESC LIMIT 100", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("simulation_message_isochrones_runs").
				Select("id, name, description, created, completed, parameters, filters, message_count, metrics, status").
				Where("status = 'completed'").Order("created DESC").Limit(100)
		})
}

// rippling/metrics.go
func TestLayer2_7a72ebd3ef4b(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT source, status, COUNT(*) AS count FROM rippling_held_replies GROUP BY source, status ORDER BY source, status", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("rippling_held_replies").
				Select("source, status, COUNT(*) AS count").
				Group("source, status").
				Order("source, status")
		})
}

// rippling/metrics.go
func TestLayer2_7b13019b71cf(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT '' AS day, event, COALESCE(SUM(count), 0) AS count FROM rippling_event_metrics GROUP BY event ORDER BY event", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("rippling_event_metrics").
				Select("'' AS day, event, COALESCE(SUM(count), 0) AS count").
				Group("event").
				Order("event")
		})
}

// dashboard/dashboard.go
func TestLayer2_7b7dd94d9688(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT id FROM `groups` WHERE publish = 1 AND onhere = 1", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("groups").Select("id").
				Where("publish = 1 AND onhere = 1")
		})
}

// team/team.go
func TestLayer2_7f14b1d8b5c5(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT * FROM teams ORDER BY LOWER(name) ASC", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("teams").Order("LOWER(name) ASC")
		})
}

// chat/chatmessage.go
func TestLayer2_839103c648fe(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT keyword AS word, match_mode AS type, action, exclude FROM concern_keywords WHERE match_mode IN ('literal', 'regex') AND action IN ('block', 'flag') AND scope = 'global' AND category != 'allowed' AND LENGTH(TRIM(keyword)) > 0", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("concern_keywords").
				Select("keyword AS word, match_mode AS type, action, exclude").
				Where("match_mode IN ('literal', 'regex') AND action IN ('block', 'flag') AND scope = 'global' AND category != 'allowed' AND LENGTH(TRIM(keyword)) > 0")
		})
}

// session/session.go
func TestLayer2_8dae60aef8bb(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM email_tracking WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("email_tracking").
				Select("COUNT(*)").
				Where("sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")
		})
}

// authority/stats.go
func TestLayer2_a496537bc045(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT SUM(popularity * weight) / SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("items").Select("SUM(popularity * weight) / SUM(popularity) AS average").Where("weight IS NOT NULL AND weight != 0")
		})
}

// status/status.go
func TestLayer2_a631a85cab7d(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT value FROM config WHERE `key` = 'deploy.laravel_commit'", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("config").Select("value").Where("`key` = 'deploy.laravel_commit'")
		})
}

// message/message.go
func TestLayer2_a7c513f07242(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT id, keyword, CASE category WHEN 'substance_regulated' THEN 'Regulated' WHEN 'substance_reportable' THEN 'Reportable' WHEN 'substance_medicine' THEN 'Medicine' WHEN 'review' THEN 'Review' WHEN 'allowed' THEN 'Allowed' ELSE 'Review' END AS type FROM concern_keywords WHERE match_mode = 'fuzzy' AND scope = 'global'", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("concern_keywords").
				Select("id, keyword, CASE category " +
					"WHEN 'substance_regulated' THEN 'Regulated' " +
					"WHEN 'substance_reportable' THEN 'Reportable' " +
					"WHEN 'substance_medicine' THEN 'Medicine' " +
					"WHEN 'review' THEN 'Review' " +
					"WHEN 'allowed' THEN 'Allowed' " +
					"ELSE 'Review' END AS type").
				Where("match_mode = 'fuzzy' AND scope = 'global'")
		})
}

// noticeboard/noticeboard.go
func TestLayer2_b62e9af236c5(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT id, name, lat, lng FROM noticeboards WHERE name IS NOT NULL AND active = 1", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("noticeboards").Select("id, name, lat, lng").Where("name IS NOT NULL AND active = 1")
		})
}

// session/session.go
func TestLayer2_cae162df80a1(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM giftaid WHERE reviewed IS NULL AND deleted IS NULL AND period != 'Declined'", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("giftaid").
				Select("COUNT(*)").Where("reviewed IS NULL AND deleted IS NULL AND period != 'Declined'")
		})
}

// item/impact.go
func TestLayer2_d25398c4a0b6(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT SUM(popularity*weight)/SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("items").Select("SUM(popularity*weight)/SUM(popularity) AS average").Where("weight IS NOT NULL AND weight != 0")
		})
}

// aiimage/aiimage.go
func TestLayer2_f1f623a9631a(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT id, name, COALESCE(externaluid, '') AS externaluid, status, regeneration_notes, pending_externaluid FROM ai_images WHERE status IN ('rejected', 'regenerating') ORDER BY id DESC", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("ai_images").
				Select("id, name, COALESCE(externaluid, '') AS externaluid, status, regeneration_notes, pending_externaluid").
				Where("status IN ('rejected', 'regenerating')").
				Order("id DESC")
		})
}

// session/session.go
func TestLayer2_fbf8a34131ef(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT COUNT(*) FROM housekeeper_tasks WHERE enabled = 1 AND placeholder = 0 AND ( last_status = 'failure' OR last_run_at IS NULL OR last_run_at < DATE_SUB(NOW(), INTERVAL interval_hours HOUR) )", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("housekeeper_tasks").
				Select("COUNT(*)").
				Where("enabled = 1 AND placeholder = 0 AND ( last_status = 'failure' OR last_run_at IS NULL OR last_run_at < DATE_SUB(NOW(), INTERVAL interval_hours HOUR) )")
		})
}
