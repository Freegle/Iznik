package test

// Wave 5, batch B (plan section 7.3+): triage of the remaining raw sites in
// authority/stats.go, membership/membership.go, microvolunteering/
// microvolunteering.go, session/session.go, dashboard/dashboard.go,
// newsfeed/newsfeed.go, rippling/{metrics,analytics}.go, isochrone/
// isochrone.go, message/{message_list,reach,bulkEdit,bulkItem,helper,groups,
// postmatches}.go, image/image.go, group/{group,groupWork,groupVolunteer}.go,
// authority/authority.go, systemlogs/systemlogs.go, user/{authMiddleware,
// namevalidation,userInfo}.go, amp/amp.go, modconfig/modconfig.go,
// story/story.go, embedding/store.go, changes/changes.go, authority/
// message.go, town/town.go, donations/donations.go, admin/admin.go,
// logs/logs.go, job/job.go, session/merge.go, spammers/spammers.go,
// noticeboard/noticeboard.go, comment/comment.go, team/team.go and
// browse/scroll.go.
//
// Unlike earlier waves this one is triage, not a conversion quota (plan
// 7.3): every raw site in this batch's files got a decision, but only 32 of
// the 130 turned out convertible with a passing Layer 1 test. The other 98
// went to tools/orm-migration/keep-raw.json with an id-pinned, mechanism-
// named reason - genuinely runtime-varying SQL, ST_*/MBR* spatial functions,
// INSERT ... SELECT, an INSERT whose generated id is read back on the same
// connection, REPLACE INTO (GORM's clause builder writes the "INSERT"
// keyword before Insert.Build() runs, so Modifier: "REPLACE" can only ever
// render "INSERT REPLACE INTO", never "REPLACE INTO" - checked against
// gorm.io/gorm's clause/insert.go and insert_test.go, not assumed), a bare
// SELECT EXISTS(...) with no top-level FROM (GORM's builder always emits a
// FROM once .Table() is called, so there is no way to construct a table-less
// SELECT through it), and one extractor false positive (browse/scroll.go's
// c.Query("start", "") - a Fiber URL-parameter getter, not a database call -
// misidentified by the extractor's "Query" method-name heuristic).
//
// The 32 here fall into three shapes, all built on precedent from earlier
// waves:
//   - Plain single-table/joined SELECTs and single-column UPDATE/DELETEs
//     (rippling/metrics.go, dashboard.go, bulkEdit.go, bulkItem.go,
//     namevalidation.go, userInfo.go, groupVolunteer.go, microvolunteering.go,
//     newsfeed.go) - .Table/.Select/.Joins/.Where/.Group/.Having/.Order/
//     .Limit chains, exactly as in waves 1-4.
//   - authority/stats.go's three GetStatsByAuthority aggregates read a
//     CREATE TEMPORARY TABLE pc populated earlier in the same function.
//     Confirmed safe to convert (not connection-scoping raw SQL): the whole
//     function pins one connection via database.DBConn.Clauses(dbresolver.
//     Write).Begin() and a writer() closure returning that same tx, and a
//     sibling query on the same pc table (site f3d569f16f75, wave 4) is
//     already converted through writer().Table("pc")..., proving GORM
//     builder calls on writer()/tx share the DDL's connection. Only the DDL
//     itself and the dynamic %f-weight queries stay raw, for unrelated
//     reasons.
//   - The derived-table trick, extended this batch to UNION-wrapped
//     subqueries: GORM's Table(name string, args ...interface{})
//     (chainable_api.go) passes name through verbatim (no quoting) whenever
//     it contains a space or a backtick, so an entire parenthesized
//     "(SELECT ... UNION SELECT ...) alias" can be given as the table name,
//     with its own bind args passed through Table()'s variadic args
//     (group/groupWork.go x2, membership.go, microvolunteering.go,
//     session.go).
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- authority/stats.go: GetStatsByAuthority ---------------------------------

func TestWave5B_296f97f33779(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "296f97f33779", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Joins("INNER JOIN chat_messages cm ON messages.id = cm.refmsgid AND cm.type = ?", 1).
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM messages_bulk_items bi WHERE bi.msgid = messages.id)",
				1, "Offer", "2026-01-01", "2026-01-31").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

func TestWave5B_9381e335515c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9381e335515c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Joins("INNER JOIN chat_messages cm ON messages.id = cm.refmsgid AND cm.type = ?", 1).
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM messages_bulk_items bi WHERE bi.msgid = messages.id) AND NOT EXISTS ( SELECT 1 FROM messages_bulk_items_interest WHERE msgid = messages.id AND userid = cm.userid )",
				1, "Offer", "2026-01-01", "2026-01-31").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

func TestWave5B_7c9ca3ba712a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7c9ca3ba712a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) AS count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ? AND outcome IN (?, ?) AND NOT EXISTS (SELECT 1 FROM messages_bulk_items WHERE msgid = messages.id)",
				1, "2026-01-01", "2026-01-31", "Taken", "Received").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

// --- rippling/metrics.go: attributionCaptureFrom / Metrics --------------------

func TestWave5B_8240fc74654f(t *testing.T) {
	var dest string
	ormharness.AssertGoldenSQL(t, "8240fc74654f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_reply_attribution").
			Select("COALESCE(DATE_FORMAT(MIN(replied_at), '%Y-%m-%d'), '')").
			Where("in_origin_catchment IS NOT NULL OR in_reach IS NOT NULL OR client_source IS NOT NULL").
			Find(&dest)
	})
}

func TestWave5B_bd7c7b0064fb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "bd7c7b0064fb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_event_metrics").
			Select("DATE_FORMAT(day, '%Y-%m-%d') AS day, event, count").
			Where("day >= CURDATE() - INTERVAL 30 DAY").
			Order("day DESC, event").
			Find(&dest)
	})
}

func TestWave5B_8aaa3043bf0c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8aaa3043bf0c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_hotspots").
			Select("DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, area_type, area_id, COALESCE(area_name, '') AS area_name, metric, value, baseline, deviation, direction, severity").
			Where("detected_at >= NOW() - INTERVAL 30 DAY").
			Order("(severity = 'alert') DESC, ABS(deviation) DESC").
			Limit(100).
			Find(&dest)
	})
}

func TestWave5B_d0a9e5f17cff(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d0a9e5f17cff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_params").
			Select("ons_category, max_minutes, COALESCE(rationale, '') AS rationale, DATE_FORMAT(proposed_at, '%Y-%m-%d %H:%i') AS proposed_at").
			Where("status = 'proposed'").
			Order("ons_category").
			Find(&dest)
	})
}

func TestWave5B_72175873186c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "72175873186c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_live_metrics").
			Select("DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, metric, value, sample_size").
			Where("stratum_type = 'overall' AND period_type = 'weekly' AND period_start >= CURDATE() - INTERVAL 14 DAY").
			Order("period_start DESC, metric").
			Find(&dest)
	})
}

func TestWave5B_7059261a513c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7059261a513c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_held_replies").
			Select("status, COUNT(*) AS count, COALESCE(AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(releasedat, NOW())) / 3600.0), 0) AS median_hold_hours").
			Group("status").
			Order("status").
			Find(&dest)
	})
}

func TestWave5B_939fde07a522(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "939fde07a522", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_algorithm_metrics").
			Select("DATE_FORMAT(week_start, '%Y-%m-%d') AS week_start, curve, pairs_total, pairs_in_time, pairs_late, COALESCE(reply_p50_hours, 0) AS reply_p50_hours, COALESCE(reply_p75_hours, 0) AS reply_p75_hours").
			Where("`group` = 'all'").
			Order("week_start DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- dashboard/dashboard.go: getPopularPosts / getUsersPosting /
// --- getModeratorsActive / getDonations --------------------------------------

func TestWave5B_d9ea74465694(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d9ea74465694", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages m").
			Select("(SELECT COUNT(*) FROM messages_likes WHERE msgid = m.id AND type = ?) AS views, m.id, m.subject", 1).
			Where("m.arrival >= ? AND m.arrival <= ? AND m.deleted IS NULL", "2026-01-01", "2026-01-31").
			Order("views DESC").
			Limit(5).
			Find(&dest)
	})
}

func TestWave5B_041e44afbfe5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "041e44afbfe5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups mg").
			Select("(SELECT COUNT(*) FROM messages_likes WHERE msgid = mg.msgid AND type = ?) AS views, mg.msgid AS id, MIN(m.subject) AS subject", 1).
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where("mg.arrival >= ? AND mg.arrival <= ? AND mg.groupid IN (?) AND mg.collection = ? AND mg.rippled_in = 0",
				"2026-01-01", "2026-01-31", []uint64{1, 2}, "Approved").
			Group("mg.msgid").
			Order("views DESC").
			Limit(5).
			Find(&dest)
	})
}

func TestWave5B_57978ae039a2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "57978ae039a2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").
			Select("COUNT(*) AS count, messages.fromuser").
			Where("id IN (SELECT msgid FROM messages_groups WHERE messages_groups.arrival >= ? AND messages_groups.arrival <= ? AND groupid IN (?)) AND messages.arrival >= ? AND messages.arrival <= ?",
				"2026-01-01", "2026-01-31", []uint64{1, 2}, "2026-01-01", "2026-01-31").
			Group("messages.fromuser").
			Order("count DESC").
			Limit(5).
			Find(&dest)
	})
}

func TestWave5B_285861d7b37c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "285861d7b37c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Select("userid, (SELECT messages_groups.approvedat FROM messages_groups WHERE messages_groups.approvedby = memberships.userid AND messages_groups.groupid = memberships.groupid ORDER BY messages_groups.approvedat DESC LIMIT 1) AS lastactive").
			Where("groupid IN (?) AND role IN (?, ?)", []uint64{1, 2}, "Moderator", "Owner").
			Having("lastactive IS NOT NULL").
			Find(&dest)
	})
}

func TestWave5B_93937ea4340f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "93937ea4340f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_donations").
			Select("SUM(GrossAmount) AS count, DATE(timestamp) AS date").
			Where("userid IN (SELECT DISTINCT userid FROM memberships WHERE groupid IN (?)) AND timestamp >= ? AND timestamp <= ?",
				[]uint64{1, 2}, "2026-01-01", "2026-01-31").
			Group("date").
			Order("date ASC").
			Find(&dest)
	})
}

// --- group/groupVolunteer.go: GetGroupVolunteers ------------------------------

func TestWave5B_ddc552673df8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ddc552673df8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Select("memberships.userid AS id, ui.id AS profileid, ui.url AS url, ui.archived, ui.externaluid, ui.externalmods, "+
				"CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname, "+
				"CASE WHEN JSON_EXTRACT(users.settings, '$.showmod') IS NULL THEN 1 ELSE JSON_EXTRACT(users.settings, '$.showmod') END AS showmod, "+
				"users.lastaccess, memberships.added, memberships.role, memberships.settings, "+
				"(SELECT ue.email FROM users_emails ue WHERE ue.userid = memberships.userid AND ue.preferred = 1 LIMIT 1) AS email").
			Joins("LEFT JOIN users_images ui ON ui.id = ( SELECT MAX(ui2.id) minid FROM users_images ui2 WHERE ui2.userid = memberships.userid )").
			Joins("INNER JOIN users ON users.id = memberships.userid").
			Where("groupid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").
			Find(&dest)
	})
}

// --- group/groupWork.go: GetGroupWork -----------------------------------------

func TestWave5B_2fb19be4ef0b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2fb19be4ef0b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("(SELECT ur.user1, m.groupid FROM users_related ur "+
			"INNER JOIN memberships m ON m.userid = ur.user1 "+
			"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User' "+
			"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User' "+
			"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0 "+
			"UNION "+
			"SELECT ur.user1, m.groupid FROM users_related ur "+
			"INNER JOIN memberships m ON m.userid = ur.user2 "+
			"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User' "+
			"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User' "+
			"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0) t",
			[]uint64{1, 2}, []uint64{1, 2}).
			Select("groupid, COUNT(*) as count").
			Group("groupid").
			Find(&dest)
	})
}

func TestWave5B_e9cc5186c0a5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e9cc5186c0a5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("(SELECT DISTINCT cm.id, "+
			"COALESCE("+
			"  (SELECT m1.groupid FROM memberships m1 INNER JOIN `groups` g ON m1.groupid = g.id AND g.type = 'Freegle' "+
			"   WHERE m1.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) "+
			"   AND m1.groupid IN ? LIMIT 1), "+
			"  (SELECT m2.groupid FROM memberships m2 INNER JOIN `groups` g2 ON m2.groupid = g2.id AND g2.type = 'Freegle' "+
			"   WHERE m2.userid = cm.userid AND m2.groupid IN ? LIMIT 1)"+
			") as groupid, "+
			"(cmh.userid IS NOT NULL) as held "+
			"FROM chat_messages cm "+
			"INNER JOIN chat_rooms cr ON cr.id = cm.chatid "+
			"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id "+
			"WHERE cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? "+
			"AND ("+
			"  EXISTS (SELECT 1 FROM memberships m3 INNER JOIN `groups` g3 ON m3.groupid = g3.id AND g3.type = 'Freegle' "+
			"   WHERE m3.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND m3.groupid IN ?) "+
			"  OR (NOT EXISTS (SELECT 1 FROM memberships m4 INNER JOIN `groups` g4 ON m4.groupid = g4.id AND g4.type = 'Freegle' "+
			"   WHERE m4.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)) "+
			"   AND EXISTS (SELECT 1 FROM memberships m5 INNER JOIN `groups` g5 ON m5.groupid = g5.id AND g5.type = 'Freegle' "+
			"   WHERE m5.userid = cm.userid AND m5.groupid IN ?))"+
			")) sub",
			[]uint64{1, 2}, []uint64{1, 2}, "2026-01-01", []uint64{1, 2}, []uint64{1, 2}).
			Select("groupid, COUNT(*) as count, held").
			Where("groupid IS NOT NULL").
			Group("groupid, held").
			Find(&dest)
	})
}

// --- membership/membership.go: getRelatedMembers ------------------------------

func TestWave5B_70059ac9a4bb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "70059ac9a4bb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("(SELECT users_related.id, user1, user2 FROM users_related "+
			"INNER JOIN memberships ON users_related.user1 = memberships.userid "+
			"INNER JOIN users u1 ON users_related.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User' "+
			"INNER JOIN users u2 ON users_related.user2 = u2.id AND u2.deleted IS NULL "+
			"WHERE user1 < user2 AND notified = 0 AND memberships.groupid IN ? "+
			"UNION "+
			"SELECT users_related.id, user1, user2 FROM users_related "+
			"INNER JOIN memberships ON users_related.user2 = memberships.userid "+
			"INNER JOIN users u1 ON users_related.user1 = u1.id AND u1.deleted IS NULL "+
			"INNER JOIN users u2 ON users_related.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User' "+
			"WHERE user1 < user2 AND notified = 0 AND memberships.groupid IN ?) t",
			[]uint64{1, 2}, []uint64{1, 2}).
			Select("DISTINCT id, user1, user2").
			Order("id DESC").
			Limit(100).
			Find(&dest)
	})
}

// --- message/bulkEdit.go: resolveBulkEditToken / recomputeBulkAvailableNow ----

func TestWave5B_95a87268e014(t *testing.T) {
	var dest uint64
	ormharness.AssertGoldenSQL(t, "95a87268e014", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_access a").
			Select("a.msgid").
			Joins("INNER JOIN messages m ON m.id = a.msgid AND m.deleted IS NULL").
			Where("a.edittoken = ? AND a.edittoken <> '' AND EXISTS (SELECT 1 FROM messages_bulk_items bi WHERE bi.msgid = a.msgid)", "tok").
			Limit(1).
			Find(&dest)
	})
}

func TestWave5B_56dfb1008d60(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "56dfb1008d60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").
			Where("id = ?", 1).
			Update("availablenow", gorm.Expr("(SELECT COALESCE(SUM(quantity), 0) FROM messages_bulk_items WHERE msgid = ? AND available = 1)", 1))
	})
}

// --- message/bulkItem.go: ingestBulkItemPhotos --------------------------------

func TestWave5B_086a07072de1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "086a07072de1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items bi").
			Select("bi.id, bi.photourl").
			Where("bi.msgid = ? AND bi.photourl IS NOT NULL AND bi.photourl != '' AND NOT EXISTS (SELECT 1 FROM messages_bulk_item_attachments x WHERE x.bulkitemid = bi.id)", 1).
			Find(&dest)
	})
}

// --- microvolunteering/microvolunteering.go: GetChallenge helpers -------------

func TestWave5B_39233a746ed4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "39233a746ed4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("(SELECT id, name FROM items WHERE LENGTH(name) > 2 ORDER BY popularity DESC LIMIT 300) t").
			Select("DISTINCT id, name AS term").
			Order("RAND()").
			Limit(10).
			Find(&dest)
	})
}

func TestWave5B_8c2181ff22ae(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8c2181ff22ae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images ai").
			Select("ai.id, ai.name, ai.externaluid, ai.usage_count").
			Joins("LEFT JOIN microactions ma ON ma.aiimageid = ai.id AND ma.userid = ? AND ma.actiontype = ?", 1, "aiimagereview").
			Where("ai.externaluid IS NOT NULL AND ai.externaluid != '' AND ai.status = 'active' AND ma.id IS NULL AND (SELECT COUNT(*) FROM microactions WHERE aiimageid = ai.id AND actiontype = ?) < ?",
				"aiimagereview", 3).
			Order("ai.usage_count DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave5B_9f06198e9799(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9f06198e9799", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("eee_classified_attachments ec").
			Select("ma_att.id AS attid, m.id AS msgid, ma_att.externaluid, m.subject").
			Joins("INNER JOIN messages_attachments ma_att ON ma_att.id = ec.attid").
			Joins("INNER JOIN messages m ON m.id = ec.messageid").
			Where("m.deleted IS NULL AND ma_att.externaluid IS NOT NULL AND ma_att.externaluid != '' AND NOT EXISTS ( SELECT 1 FROM microactions ma WHERE ma.eee_attachment_id = ma_att.id AND ma.userid = ? AND ma.actiontype = ? ) AND (SELECT COUNT(*) FROM microactions WHERE eee_attachment_id = ma_att.id AND actiontype = ?) < ?",
				1, "eeelabel", "eeelabel", 3).
			Order("ec.classified_at DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- newsfeed/newsfeed.go: HandleNewsfeedAction / createPost ------------------

func TestWave5B_3c4828e01db8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3c4828e01db8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").
			Where("touser = ? AND (newsfeedid = ? OR newsfeedid IN (SELECT id FROM newsfeed WHERE replyto = ?))", 1, 2, 2).
			Delete(nil)
	})
}

func TestWave5B_aa142f16e0e0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "aa142f16e0e0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").
			Where("touser = ? AND (newsfeedid = ? OR newsfeedid IN (SELECT id FROM newsfeed WHERE replyto = ?))", 1, 2, 2).
			Update("seen", gorm.Expr("1"))
	})
}

// --- session/session.go: GetSession / handleForget ----------------------------

func TestWave5B_0a6ca0656195(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0a6ca0656195", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").
			Select("(CASE WHEN ((users.systemrole != ? OR EXISTS(SELECT id FROM users_donations WHERE userid = ? AND users_donations.timestamp >= ?) OR EXISTS(SELECT id FROM microactions WHERE userid = ? AND microactions.timestamp >= ?)) AND (CASE WHEN JSON_EXTRACT(users.settings, '$.hidesupporter') IS NULL THEN 0 ELSE JSON_EXTRACT(users.settings, '$.hidesupporter') END) = 0) THEN 1 ELSE 0 END) AS supporter, (SELECT MAX(timestamp) FROM users_donations WHERE userid = ?) AS donated, (SELECT type FROM users_donations WHERE userid = ? ORDER BY timestamp DESC LIMIT 1) AS donatedtype",
				"User", 1, "2026-01-01", 1, "2026-01-01", 1, 1).
			Where("users.id = ?", 1).
			Find(&dest)
	})
}

func TestWave5B_fc02dfb79aa4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fc02dfb79aa4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Where("msgid IN (SELECT id FROM messages WHERE fromuser = ?)", 1).
			Update("deleted", gorm.Expr("1"))
	})
}

func TestWave5B_09515916c939(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "09515916c939", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("(SELECT ur.user1 FROM users_related ur "+
			"INNER JOIN memberships m ON m.userid = ur.user1 "+
			"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = ? "+
			"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = ? "+
			"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0 "+
			"UNION "+
			"SELECT ur.user1 FROM users_related ur "+
			"INNER JOIN memberships m ON m.userid = ur.user2 "+
			"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = ? "+
			"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = ? "+
			"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0) t",
			"User", "User", []uint64{1, 2}, "User", "User", []uint64{1, 2}).
			Select("COUNT(*)").
			Find(&dest)
	})
}

// --- user/namevalidation.go: IsNameExempt -------------------------------------

func TestWave5B_745ae2e2abc8(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "745ae2e2abc8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users u").
			Select("u.systemrole, IF(EXISTS(SELECT 1 FROM memberships m WHERE m.userid = u.id AND m.role IN (?, ?)), 1, 0) AS is_mod",
				"Owner", "Moderator").
			Where("u.id = ?", 1).
			Find(&dest)
	})
}

// --- user/userInfo.go: GetUserInfo --------------------------------------------

func TestWave5B_cd31abc88595(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "cd31abc88595", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_expected").
			Select("COUNT(*) AS expectedreply").
			Joins("INNER JOIN users ON users.id = users_expected.expectee").
			Joins("INNER JOIN chat_messages ON chat_messages.id = users_expected.chatmsgid").
			Where("expectee = ? AND chat_messages.date >= ? AND replyexpected = 1 AND replyreceived = 0 AND TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) >= ?",
				1, "2026-01-01", 30).
			Find(&dest)
	})
}
