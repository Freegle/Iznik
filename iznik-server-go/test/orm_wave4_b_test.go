package test

// Wave 4, batch B (plan section 7.3+): multi-table SELECTs (joins) in
// user/user.go, group/groupWork.go, volunteering/volunteering.go,
// user/userInfo.go, dashboard/dashboard.go, newsfeed/newsfeed.go,
// emailtracking/emailtracking.go, message/helper.go,
// microvolunteering/microvolunteering.go, chat/chatroom.go,
// modconfig/modconfig.go, notification/notification.go,
// group/groupMessages.go, team/team.go, message/bulkItem.go,
// donations/giftaid.go, job/job.go, session/merge.go, message/bulkEdit.go,
// isochrone/isochrone.go, rippling/metrics.go, spammers/spammers.go and
// amp/amp.go.
//
// READ ormharness/join_test.go FIRST (this batch was written after doing so):
// a table alias survives unquoted ("users u", never "`users u`"); multiple
// Joins render in call order, since a later ON clause routinely names an
// earlier join's alias; a Where written before a Joins still renders after
// it, because GORM assembles by clause, not call order; a bind inside an ON
// clause keeps its position relative to the WHERE's own binds; DISTINCT goes
// inside Select(...) rather than a separate Distinct() call; WHERE/GROUP
// BY/HAVING ordering is handled by Group() and Having().
//
// A join's failure mode is not a syntax error - it is the right columns from
// the wrong rows. Every ON clause here was checked character by character
// against the recorded golden, in particular the direction of each equality
// and which alias owns which column; nothing was rewritten into GORM
// association syntax.
//
// Two conversion notes worth recording:
//   - A raw table name that is also a MySQL reserved word (`groups`, `jobs`)
//     is passed to .Table(...) pre-quoted with backticks in the literal Go
//     string, e.g. db.Table("`groups`") or db.Table("`groups` g"). GORM's
//     Table() takes the RAW string verbatim whenever it contains a space or a
//     backtick (chainable_api.go), so this reproduces the golden's quoting
//     exactly rather than relying on GORM to quote a plain "groups".
//   - A handful of sites bind a value inside the SELECT list itself (e.g.
//     newsfeed.go's fetchSingle, site 6cdce430c158, binds
//     users.newsfeedmodstatus = ? in a CASE expression it selects). Select's
//     own (query string, args...) signature takes binds the same way
//     Where/Joins do, and GORM assembles the SELECT clause before FROM/JOIN/
//     WHERE regardless of call order, so the placeholder lands in the right
//     place in the rendered text.
//
// All 70 of this batch's sites converted; none needed to stay raw (no
// LAST_INSERT_ID(...) and no INSERT ... SELECT among them - this batch is
// reads only).
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- amp/amp.go: getUserInfo --------------------------------------------------

func TestWave4B_f98509337e6d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f98509337e6d", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users u").
			Select("u.id, u.fullname, u.firstname, u.lastname, ui.id AS imageid, ui.url AS imageurl, ue.email AS email").
			Joins("LEFT JOIN users_images ui ON ui.userid = u.id").
			Joins("LEFT JOIN users_emails ue ON ue.userid = u.id").
			Where("u.id = ?", 1).
			Order("ui.default DESC, ui.id ASC, ue.preferred DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- chat/chatroom.go: GetCommonGroups / handleReportNoGroup ----------------

func TestWave4B_22b6e811aaa9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "22b6e811aaa9", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("`groups` g").
			Select("g.id, COALESCE(NULLIF(g.namefull, ''), g.nameshort) AS namedisplay").
			Joins("INNER JOIN memberships m1 ON m1.groupid = g.id AND m1.userid = ?", 1).
			Joins("INNER JOIN memberships m2 ON m2.groupid = g.id AND m2.userid = ?", 2).
			Order("namedisplay").
			Find(&dest)
	})
}

func TestWave4B_8f117eb67b75(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "8f117eb67b75", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships m1").
			Select("COUNT(*)").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m2.userid = ?", 1, 2).
			Count(&dest)
	})
}

// --- isochrone/isochrone.go: EditIsochrone -----------------------------------

func TestWave4B_96a263f84449(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "96a263f84449", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("isochrones_users").
			Select("isochrones.locationid, isochrones_users.userid, isochrones.transport").
			Joins("INNER JOIN isochrones ON isochrones.id = isochrones_users.isochroneid").
			Where("isochrones_users.id = ?", 1).
			Find(&dest)
	})
}

// --- team/team.go: getVolunteers ----------------------------------------------

func TestWave4B_2937feaa1317(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2937feaa1317", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships").
			Select("DISTINCT memberships.userid, users.firstname, users.lastname, users.fullname, users.added, users.settings").
			Joins("INNER JOIN `groups` ON `groups`.id = memberships.groupid AND memberships.role IN (?, ?)", "Moderator", "Owner").
			Joins("INNER JOIN users ON users.id = memberships.userid").
			Where("`groups`.type = ?", "Freegle").
			Find(&dest)
	})
}

// --- message/bulkItem.go: handleBulkInterestState ----------------------------

func TestWave4B_2fb353882afe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2fb353882afe", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_bulk_items bi").
			Select("bi.msgid, m.fromuser").
			Joins("INNER JOIN messages m ON m.id = bi.msgid").
			Where("bi.id = ?", 1).
			Find(&dest)
	})
}

// --- donations/giftaid.go: ListGiftAid ---------------------------------------

func TestWave4B_380a2b8e3fbc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "380a2b8e3fbc", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("giftaid").
			Select("giftaid.*, SUM(users_donations.GrossAmount) AS donations").
			Joins("LEFT JOIN users_donations ON users_donations.userid = giftaid.userid").
			Where("giftaid.reviewed IS NULL AND giftaid.deleted IS NULL AND giftaid.period != 'Declined'").
			Group("giftaid.userid").
			Order("giftaid.timestamp DESC").
			Find(&dest)
	})
}

// --- job/job.go: GetJob --------------------------------------------------------

func TestWave4B_6535a765873b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6535a765873b", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("`jobs`").
			Select("jobs.id, jobs.url, jobs.title, jobs.location, jobs.body, jobs.job_reference, jobs.category, jobs.cpc, jobs.clickability, ai_images.externaluid").
			Joins("LEFT JOIN ai_images ON ai_images.name = jobs.canonical_title").
			Where("jobs.id = ? AND visible = 1", 1).
			Find(&dest)
	})
}

// --- session/merge.go: mergeChatRooms -----------------------------------------

func TestWave4B_721c27468383(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "721c27468383", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_rooms lr").
			Select("lr.id AS loser_id, MIN(sr.id) AS survivor_id").
			Joins(`JOIN chat_rooms sr ON sr.chattype = lr.chattype AND ( (sr.user1 = ? AND sr.user2 = IF(lr.user1 = ?, lr.user2, lr.user1)) OR (sr.user2 = ? AND sr.user1 = IF(lr.user1 = ?, lr.user2, lr.user1)))`,
				1, 2, 1, 2).
			Where("lr.user1 = ? OR lr.user2 = ?", 2, 2).
			Group("lr.id").
			Find(&dest)
	})
}

// --- message/bulkEdit.go: bulkEditItems ---------------------------------------

func TestWave4B_7f1d565c4908(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7f1d565c4908", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_bulk_item_attachments bia").
			Select("bia.bulkitemid, ma.id, ma.archived, COALESCE(ma.externaluid, '') AS externaluid, COALESCE(ma.externalmods, '') AS externalmods").
			Joins("INNER JOIN messages_attachments ma ON ma.id = bia.attachmentid").
			Where("ma.msgid = ?", 1).
			Order("ma.`primary` DESC, ma.id ASC").
			Find(&dest)
	})
}

// --- rippling/metrics.go: Metrics (groups section) ----------------------------

func TestWave4B_a046c8fa9413(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a046c8fa9413", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("rippling_reach rr").
			Select("DISTINCT g.id AS id, g.nameshort AS name").
			Joins("JOIN messages_groups mg ON mg.msgid = rr.msgid AND mg.rippled_in = 0 AND mg.deleted = 0").
			Joins("JOIN `groups` g ON g.id = mg.groupid").
			Where("rr.created_at >= ? AND rr.created_at < ?", "2026-01-01", "2026-02-01").
			Order("g.nameshort").
			Find(&dest)
	})
}

// --- spammers/spammers.go: ExportSpammers -------------------------------------

func TestWave4B_b524187a3675(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b524187a3675", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("spam_users").
			Select("spam_users.id, spam_users.added, reason, email").
			Joins("INNER JOIN users_emails ON spam_users.userid = users_emails.userid").
			Where("collection = ?", "Spammer").
			Find(&dest)
	})
}

// --- modconfig/modconfig.go: canSee / GetModConfig ----------------------------

func TestWave4B_37e5bdcc1ee6(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "37e5bdcc1ee6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships m1").
			Select("COUNT(*)").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m1.role IN (?, ?) AND m2.configid = ? AND m2.role IN (?, ?)",
				1, "Moderator", "Owner", 2, "Moderator", "Owner").
			Count(&dest)
	})
}

func TestWave4B_4f2e91887c4f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4f2e91887c4f", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships m1").
			Select("m2.userid, m2.groupid").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m1.role IN (?, ?) AND m2.configid = ? AND m2.role IN (?, ?) AND m2.userid != ?",
				1, "Moderator", "Owner", 2, "Moderator", "Owner", 1).
			Limit(1).
			Find(&dest)
	})
}

// --- notification/notification.go: Count / List -------------------------------

func TestWave4B_6fc15cf6168f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6fc15cf6168f", func(tx *gorm.DB) *gorm.DB {
		var dest []int64
		return tx.Table("users_notifications").
			Select("COUNT(*) AS count").
			Joins("LEFT JOIN spam_users ON spam_users.userid = users_notifications.fromuser AND collection IN (?, ?)", "PendingAdd", "Spammer").
			Where("touser = ? AND timestamp >= ? AND seen = 0 AND spam_users.id IS NULL", 1, "2026-01-01").
			Find(&dest)
	})
}

func TestWave4B_56662fe97c21(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "56662fe97c21", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_notifications").
			Select("*").
			Joins("LEFT JOIN spam_users ON spam_users.userid = users_notifications.fromuser AND collection IN (?, ?)", "PendingAdd", "Spammer").
			Where("touser = ? AND timestamp >= ? AND spam_users.id IS NULL", 1, "2026-01-01").
			Order("users_notifications.id DESC").
			Find(&dest)
	})
}

// --- group/groupMessages.go: GetGroupMessages / GetGroupMessageSummaries ----

func TestWave4B_860c3fb17af3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "860c3fb17af3", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("messages_groups").
			Select("messages_groups.msgid").
			Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_groups.msgid").
			Joins("INNER JOIN messages ON messages.id = messages_groups.msgid").
			Joins("INNER JOIN users ON users.id = messages.fromuser").
			Where("groupid = ? AND messages_groups.arrival >= ? AND (collection = ? OR (messages.fromuser = ? AND collection != ?)) AND messages_groups.deleted = 0 AND users.deleted IS NULL AND (messages_outcomes.id IS NULL OR messages_outcomes.outcome IN (?, ?))",
				1, "2026-01-01", "Approved", 2, "Rejected", "Taken", "Received").
			Order("messages_groups.arrival DESC").
			Find(&dest)
	})
}

func TestWave4B_e5a3017e0a69(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e5a3017e0a69", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_groups").
			Select("messages.id, messages.subject, messages_groups.arrival").
			Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_groups.msgid").
			Joins("INNER JOIN messages ON messages.id = messages_groups.msgid").
			Joins("INNER JOIN users ON users.id = messages.fromuser").
			Where("groupid = ? AND messages_groups.arrival >= ? AND collection = ? AND messages_groups.deleted = 0 AND users.deleted IS NULL AND messages.deleted IS NULL AND messages_outcomes.id IS NULL AND messages.type IN (?, ?)",
				1, "2026-01-01", "Approved", "Offer", "Wanted").
			Order("messages_groups.arrival DESC").
			Limit(200).
			Find(&dest)
	})
}

// --- microvolunteering/microvolunteering.go: GetChallenge / PostResponse ----

func TestWave4B_41b78139b026(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "41b78139b026", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("memberships").
			Select("groupid").
			Joins("INNER JOIN `groups` ON memberships.groupid = `groups`.id").
			Where("userid = ? AND type = ?", 1, "Freegle").
			Find(&dest)
	})
}

func TestWave4B_f272e5ec73c0(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "f272e5ec73c0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Select("COUNT(*)").
			Joins("INNER JOIN memberships ON memberships.groupid = messages_groups.groupid AND memberships.userid = ?", 1).
			Joins("INNER JOIN messages ON messages.id = messages_groups.msgid").
			Where("messages_groups.msgid = ? AND messages_groups.deleted = 0 AND COALESCE(messages.fromuser, 0) != ? AND messages.deleted IS NULL", 2, 1).
			Count(&dest)
	})
}

func TestWave4B_ec2405eade43(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "ec2405eade43", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").
			Select("COUNT(*)").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages_attachments.msgid AND messages_groups.deleted = 0").
			Joins("INNER JOIN memberships ON memberships.groupid = messages_groups.groupid AND memberships.userid = ?", 1).
			Joins("INNER JOIN messages ON messages.id = messages_attachments.msgid").
			Where("messages_attachments.id = ? AND messages.deleted IS NULL", 2).
			Count(&dest)
	})
}

// --- message/helper.go: msgidForReplier / msgidForProposal / GetHelper / GetHelperEscalated ---

func TestWave4B_e6d298a3c77e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e6d298a3c77e", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("helper_repliers r").
			Select("b.msgid").
			Joins("INNER JOIN helper_batches b ON b.id = r.batchid").
			Where("r.id = ?", 1).
			Find(&dest)
	})
}

func TestWave4B_5ccbc340f539(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5ccbc340f539", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("helper_proposals p").
			Select("b.msgid").
			Joins("INNER JOIN helper_batches b ON b.id = p.batchid").
			Where("p.id = ?", 1).
			Find(&dest)
	})
}

func TestWave4B_3ad68615e84c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3ad68615e84c", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("helper_item_states s").
			Select("s.id, s.replierid, s.bulkitemid, s.state, s.qty_wanted, s.qty_allocated, s.score, s.score_breakdown").
			Joins("INNER JOIN helper_repliers r ON r.id = s.replierid").
			Where("r.batchid = ?", 1).
			Find(&dest)
	})
}

func TestWave4B_b7f56ac149be(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b7f56ac149be", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("helper_repliers r").
			Select("r.id, r.batchid, b.msgid, b.offereruserid, r.userid, r.chatid, r.escalation_reason, m.subject").
			Joins("INNER JOIN helper_batches b ON b.id = r.batchid").
			Joins("INNER JOIN messages m ON m.id = b.msgid").
			Where("r.state = 'ESCALATED'").
			Order("r.id DESC").
			Find(&dest)
	})
}

// --- emailtracking/emailtracking.go: ReengageEffectiveness -------------------

func TestWave4B_db84f5bddc5b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "db84f5bddc5b", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("reengage r").
			Select("COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
			Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
			Where("r.sentat BETWEEN ? AND ?", "2026-01-01", "2026-02-01").
			Find(&dest)
	})
}

func TestWave4B_b8401fd16dd1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b8401fd16dd1", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("reengage r").
			Select("r.stage AS stage, COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
			Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
			Where("r.sentat BETWEEN ? AND ?", "2026-01-01", "2026-02-01").
			Group("r.stage").
			Order("r.stage ASC").
			Find(&dest)
	})
}

func TestWave4B_37d4ff3aedb7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "37d4ff3aedb7", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("reengage r").
			Select("r.arm AS arm, COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
			Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
			Where("r.sentat BETWEEN ? AND ? AND r.arm IS NOT NULL", "2026-01-01", "2026-02-01").
			Group("r.arm").
			Order("r.arm ASC").
			Find(&dest)
	})
}

func TestWave4B_639cf671aa39(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "639cf671aa39", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("reengage r").
			Select("r.volunteer_source AS source, COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
			Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
			Where("r.sentat BETWEEN ? AND ? AND r.volunteer_source IS NOT NULL", "2026-01-01", "2026-02-01").
			Group("r.volunteer_source").
			Order("r.volunteer_source ASC").
			Find(&dest)
	})
}

// --- newsfeed/newsfeed.go: fetchSingle / createPost / Post (Report case) ----

// 6cdce430c158: users.newsfeedmodstatus = ? is a bind inside the SELECT list
// itself, not the WHERE - Select's own (query, args...) form carries it the
// same way Where/Joins do, per join_test.go.
func TestWave4B_6cdce430c158(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6cdce430c158", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("newsfeed").
			Select("newsfeed.*, newsfeed_images.archived AS imagearchived, newsfeed_images.externaluid AS imageuid, newsfeed_images.externalmods AS imagemods, "+
				"(CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, "+
				"CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname, "+
				"CASE WHEN systemrole IN (?, ?, ?) THEN CASE WHEN JSON_EXTRACT(users.settings, '$.showmod') IS NULL THEN 1 ELSE JSON_EXTRACT(users.settings, '$.showmod') END ELSE 0 END AS showmod",
				"Suppressed", "Moderator", "Support", "Admin").
			Joins("LEFT JOIN users ON users.id = newsfeed.userid").
			Joins("LEFT JOIN newsfeed_images ON newsfeed.imageid = newsfeed_images.id").
			Where("newsfeed.id = ?", 1).
			Find(&dest)
	})
}

func TestWave4B_68ee4370bd9a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "68ee4370bd9a", func(tx *gorm.DB) *gorm.DB {
		var dest []string
		return tx.Table("users").
			Select("COALESCE(l2.name, '')").
			Joins("LEFT JOIN locations l1 ON users.lastlocation = l1.id").
			Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
			Where("users.id = ?", 1).
			Find(&dest)
	})
}

func TestWave4B_28acafc7c5a8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "28acafc7c5a8", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("users u").
			Select("u.fullname, ue.email").
			Joins("LEFT JOIN users_emails ue ON ue.userid = u.id").
			Where("u.id = ?", 1).
			Order("ue.preferred DESC, ue.id ASC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave4B_9f3a14137203(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9f3a14137203", func(tx *gorm.DB) *gorm.DB {
		var dest []string
		return tx.Table("users").
			Select("l2.name").
			Joins("LEFT JOIN locations l1 ON users.lastlocation = l1.id").
			Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
			Where("users.id = ?", 1).
			Find(&dest)
	})
}

// --- dashboard/dashboard.go: GetDashboard / getRecentCounts / getUsersReplying / getHappiness ---

func TestWave4B_11558c0c6cd0(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "11558c0c6cd0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").
			Select("COUNT(DISTINCT messages.id)").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Where("messages_groups.arrival >= ? AND messages_groups.arrival <= ? AND groupid IN (?)", "2026-01-01", "2026-02-01", []uint64{1, 2}).
			Count(&dest)
	})
}

func TestWave4B_bce3018a21d7(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "bce3018a21d7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").
			Select("COUNT(DISTINCT messages.id)").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Where("messages_groups.arrival >= ? AND messages_groups.arrival <= ? AND groupid IN (?) AND messages.arrival >= ? AND messages.arrival <= ?",
				"2026-01-01", "2026-02-01", []uint64{1, 2}, "2026-01-01", "2026-02-01").
			Count(&dest)
	})
}

func TestWave4B_4a0837c4271f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4a0837c4271f", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_messages").
			Select("COUNT(*) AS count, chat_messages.userid").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = chat_messages.refmsgid").
			Where("messages_groups.arrival >= ? AND messages_groups.arrival <= ? AND groupid IN (?) AND chat_messages.type = ?",
				"2026-01-01", "2026-02-01", []uint64{1, 2}, "Interested").
			Group("chat_messages.userid").
			Order("count DESC").
			Limit(5).
			Find(&dest)
	})
}

func TestWave4B_d313fe02593e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d313fe02593e", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_outcomes").
			Select("COUNT(*) AS count, happiness").
			Joins("INNER JOIN messages ON messages.id = messages_outcomes.msgid").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages_outcomes.msgid").
			Where("timestamp >= ? AND timestamp <= ? AND messages_groups.groupid IN (?) AND happiness IS NOT NULL", "2026-01-01", "2026-02-01", []uint64{1, 2}).
			Group("happiness").
			Order("count DESC").
			Find(&dest)
	})
}

// --- user/userInfo.go: GetUserInfo / GetPublicLocationForUser ----------------

func TestWave4B_48b377098859(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "48b377098859", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_messages cm").
			Select("COUNT(DISTINCT cm.refmsgid) AS count, m.type AS msgtype").
			Joins("INNER JOIN messages m ON m.id = cm.refmsgid").
			Where("cm.userid = ? AND cm.date > ? AND cm.refmsgid IS NOT NULL AND cm.type = ?", 1, "2026-01-01", "Interested").
			Group("m.type").
			Find(&dest)
	})
}

func TestWave4B_fe953b2b8ed1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fe953b2b8ed1", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("messages_by").
			Select("COUNT(DISTINCT messages_by.msgid) AS collected").
			Joins("INNER JOIN messages ON messages.id = messages_by.msgid").
			Joins("INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id AND messages.type = ? AND chat_messages.type = ?", "Offer", "Interested").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Where("chat_messages.userid = ? AND messages_by.userid = ? AND messages_by.userid != messages.fromuser AND messages_groups.arrival >= ?", 1, 1, "2026-01-01").
			Find(&dest)
	})
}

func TestWave4B_ae4e50c81157(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ae4e50c81157", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages").
			Select("COUNT(DISTINCT messages.id) AS count, messages.type, messages_outcomes.outcome").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Where("fromuser = ? AND messages.arrival > ? AND collection = ? AND messages_groups.deleted = 0", 1, "2026-01-01", "Approved").
			Group("messages.type, messages_outcomes.outcome").
			Find(&dest)
	})
}

func TestWave4B_60fa78a45347(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "60fa78a45347", func(tx *gorm.DB) *gorm.DB {
		var dest string
		return tx.Table("users u").
			Select("l2.name").
			Joins("INNER JOIN locations l1 ON l1.id = u.lastlocation").
			Joins("INNER JOIN locations l2 ON l2.id = l1.areaid").
			Where("u.id = ? AND u.lastlocation IS NOT NULL", 1).
			Limit(1).
			Find(&dest)
	})
}

func TestWave4B_1056062f1f8f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1056062f1f8f", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("memberships m").
			Select("m.groupid, COALESCE(g.namefull, g.nameshort) AS groupname").
			Joins("INNER JOIN `groups` g ON g.id = m.groupid").
			Where("m.userid = ? AND m.collection = ?", 1, "Approved").
			Order("m.added DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- volunteering/volunteering.go: List / ListGroup / isModerator ------------

func TestWave4B_ae3b224706e7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ae3b224706e7", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("volunteering").
			Select("DISTINCT volunteering.id").
			Joins("INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
			Where("groupid IN (?) AND volunteering.deleted = 0 AND pending = 1", []uint64{1, 2}).
			Order("id DESC").
			Find(&dest)
	})
}

func TestWave4B_b8a2b5386625(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b8a2b5386625", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("volunteering").
			Select("volunteering.id").
			Joins("LEFT JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
			Where("volunteering_groups.groupid IS NULL AND volunteering.deleted = 0 AND pending = 1").
			Order("volunteering.id DESC").
			Find(&dest)
	})
}

func TestWave4B_d0bfb252647d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d0bfb252647d", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("volunteering").
			Select("DISTINCT volunteering.id").
			Joins("LEFT JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
			Joins("LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid").
			Joins("LEFT JOIN users ON volunteering.userid = users.id").
			Where("volunteering_groups.groupid IS NULL AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND volunteering.deleted = 0 AND expired = 0 AND (pending = 0 OR volunteering.userid = ?) AND users.deleted IS NULL",
				"2026-01-01", "2026-01-01", 1).
			Order("volunteering.id DESC").
			Limit(20).
			Find(&dest)
	})
}

func TestWave4B_2b14fe1be4fe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2b14fe1be4fe", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("volunteering").
			Select("DISTINCT volunteering.id").
			Joins("INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
			Joins("LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid").
			Joins("LEFT JOIN users ON volunteering.userid = users.id").
			Where("groupid IN (?) AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND volunteering.deleted = 0 AND expired = 0 AND (pending = 0 OR volunteering.userid = ?) AND users.deleted IS NULL",
				[]uint64{1, 2}, "2026-01-01", "2026-01-01", 1).
			Order("id DESC").
			Limit(20).
			Find(&dest)
	})
}

func TestWave4B_3890b30e46d9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3890b30e46d9", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("volunteering").
			Select("DISTINCT volunteering.id").
			Joins("LEFT JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
			Joins("LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid").
			Joins("LEFT JOIN users ON volunteering.userid = users.id").
			Where("groupid = ? AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND volunteering.deleted = 0 AND expired = 0 AND pending = 0 AND users.deleted IS NULL",
				1, "2026-01-01", "2026-01-01").
			Order("id DESC").
			Find(&dest)
	})
}

func TestWave4B_f02d5f03bfc1(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "f02d5f03bfc1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships m").
			Select("COUNT(*)").
			Joins("INNER JOIN volunteering_groups vg ON vg.groupid = m.groupid").
			Where("vg.volunteeringid = ? AND m.userid = ? AND m.collection = ? AND m.role IN (?, ?)", 1, 2, "Approved", "Moderator", "Owner").
			Count(&dest)
	})
}

// --- group/groupWork.go: GetGroupWork ------------------------------------------

func TestWave4B_31e336f98156(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "31e336f98156", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_groups mg").
			Select("mg.groupid, COUNT(*) as count, (mg.heldby IS NOT NULL) as held").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND (mg.contentcheck_checked_at IS NOT NULL OR mg.heldby IS NOT NULL)",
				[]uint64{1, 2}, "Pending").
			Group("mg.groupid, held").
			Find(&dest)
	})
}

func TestWave4B_f749b5bc26ed(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f749b5bc26ed", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_groups mg").
			Select("mg.groupid, COUNT(*) as count").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL", []uint64{1, 2}, "Spam").
			Group("mg.groupid").
			Find(&dest)
	})
}

func TestWave4B_45bb8a4c8133(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "45bb8a4c8133", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships m").
			Select("m.groupid, COUNT(*) as count, (m.heldby IS NOT NULL) as held").
			Joins("INNER JOIN users u ON u.id = m.userid").
			Where("m.groupid IN ? AND m.reviewrequestedat IS NOT NULL AND (m.reviewedat IS NULL OR m.reviewrequestedat > m.reviewedat)", []uint64{1, 2}).
			Group("m.groupid, held").
			Find(&dest)
	})
}

func TestWave4B_083af5c9e0a1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "083af5c9e0a1", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("communityevents ce").
			Select("ceg.groupid, COUNT(DISTINCT ce.id) as count").
			Joins("INNER JOIN communityevents_groups ceg ON ceg.eventid = ce.id").
			Joins("INNER JOIN communityevents_dates ced ON ced.eventid = ce.id").
			Where("ceg.groupid IN ? AND ce.pending = 1 AND ce.deleted = 0 AND ced.end >= NOW()", []uint64{1, 2}).
			Group("ceg.groupid").
			Find(&dest)
	})
}

func TestWave4B_1f888c4d9a0a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1f888c4d9a0a", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("volunteering v").
			Select("vg.groupid, COUNT(DISTINCT v.id) as count").
			Joins("INNER JOIN volunteering_groups vg ON vg.volunteeringid = v.id").
			Joins("LEFT JOIN volunteering_dates vd ON vd.volunteeringid = v.id").
			Where("vg.groupid IN ? AND v.pending = 1 AND v.deleted = 0 AND v.expired = 0 AND (vd.end IS NULL OR vd.end >= NOW())", []uint64{1, 2}).
			Group("vg.groupid").
			Find(&dest)
	})
}

func TestWave4B_7233641f67ad(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7233641f67ad", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_edits me").
			Select("mg.groupid, COUNT(DISTINCT me.msgid) as count").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = me.msgid").
			Where("mg.groupid IN ? AND me.reviewrequired = 1 AND me.approvedat IS NULL AND me.revertedat IS NULL AND me.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY) AND mg.deleted = 0 AND mg.rippled_in = 0",
				[]uint64{1, 2}).
			Group("mg.groupid").
			Find(&dest)
	})
}

func TestWave4B_1f1e8962edcb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1f1e8962edcb", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_outcomes mo").
			Select("mg.groupid, COUNT(DISTINCT mo.id) as count").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = mo.msgid").
			Where("mo.timestamp >= ? AND mg.arrival >= ? AND mg.groupid IN ? AND mg.rippled_in = 0 "+
				"AND mo.comments IS NOT NULL AND mo.comments != '' "+
				"AND mo.comments != 'Sorry, this is no longer available.' "+
				"AND mo.comments != 'Thanks, this has now been taken.' "+
				"AND mo.comments != 'Thanks, I''m no longer looking for this.' "+
				"AND mo.comments != 'Sorry, this has now been taken.' "+
				"AND mo.comments != 'Thanks for the interest, but this has now been taken.' "+
				"AND mo.comments != 'Thanks, these have now been taken.' "+
				"AND mo.comments != 'Thanks, this has now been received.' "+
				"AND mo.comments != 'Withdrawn on user unsubscribe' "+
				"AND mo.comments != 'Auto-Expired' "+
				"AND (mo.happiness = 'Happy' OR mo.happiness IS NULL) "+
				"AND mo.reviewed = 0",
				"2026-01-01", "2026-01-01", []uint64{1, 2}).
			Group("mg.groupid").
			Find(&dest)
	})
}

func TestWave4B_a9a8c24df5e0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a9a8c24df5e0", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_messages cm").
			Select("m1.groupid, COUNT(DISTINCT cm.id) as count").
			Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
			Joins("LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id").
			Joins("INNER JOIN memberships m1 ON m1.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)").
			Joins("INNER JOIN `groups` g ON m1.groupid = g.id AND g.type = 'Freegle'").
			Where("cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? AND JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 AND cmh.id IS NULL AND (cm.reportreason IS NULL OR cm.reportreason != 'User')",
				"2026-01-01").
			Group("m1.groupid").
			Find(&dest)
	})
}

// --- user/user.go: enrichUserForModtools / handleRatingReviewed / GetUserByEmail / PatchUser / handleMerge / GetUserApplied / GetUserMembershipHistory / GetUserById / GetProfileRecord / GetLatLng ---

func TestWave4B_cc0b627a2955(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cc0b627a2955", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships_history mh").
			Select("DISTINCT g.lat, g.lng").
			Joins("INNER JOIN `groups` g ON mh.groupid = g.id").
			Where("mh.userid = ? AND DATEDIFF(NOW(), mh.added) <= 31 AND g.publish = 1 AND g.onmap = 1 AND g.lat != 0 AND g.lng != 0", 1).
			Find(&dest)
	})
}

func TestWave4B_3ff417c41f7c(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "3ff417c41f7c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ratings r").
			Select("COUNT(*)").
			Joins("JOIN memberships m1 ON m1.userid = r.ratee").
			Joins("JOIN memberships m2 ON m2.groupid = m1.groupid AND m2.userid = ?", 1).
			Where("r.id = ? AND m2.role IN (?, ?)", 2, "Moderator", "Owner").
			Count(&dest)
	})
}

// eebe799ec26b, cfcce3676000 and 15c4586ac31e are the same statement at three
// call sites (GetUserByEmail and handleMerge's two email lookups), converted
// together: a half-converted group renumbers the survivors' site IDs, so
// gate (h) refuses the split state.
func TestWave4B_eebe799ec26b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "eebe799ec26b", func(tx *gorm.DB) *gorm.DB {
		var dest uint64
		return tx.Table("users").
			Select("users.id").
			Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
			Where("users_emails.email = ? AND users.deleted IS NULL", "a@example.org").
			Limit(1).
			Find(&dest)
	})
}

func TestWave4B_cfcce3676000(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cfcce3676000", func(tx *gorm.DB) *gorm.DB {
		var dest uint64
		return tx.Table("users").
			Select("users.id").
			Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
			Where("users_emails.email = ? AND users.deleted IS NULL", "a@example.org").
			Limit(1).
			Find(&dest)
	})
}

func TestWave4B_15c4586ac31e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "15c4586ac31e", func(tx *gorm.DB) *gorm.DB {
		var dest uint64
		return tx.Table("users").
			Select("users.id").
			Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
			Where("users_emails.email = ? AND users.deleted IS NULL", "a@example.org").
			Limit(1).
			Find(&dest)
	})
}

// 4ccf389828b7 and 18a18b50e638 are the same statement at two call sites in
// PatchUser (the newsfeedmodstatus mod check and the general target-user
// check), converted together.
func TestWave4B_4ccf389828b7(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "4ccf389828b7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships m1").
			Select("COUNT(*)").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m2.userid = ? AND m1.role IN (?, ?)", 1, 2, "Owner", "Moderator").
			Count(&dest)
	})
}

func TestWave4B_18a18b50e638(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "18a18b50e638", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships m1").
			Select("COUNT(*)").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m2.userid = ? AND m1.role IN (?, ?)", 1, 2, "Owner", "Moderator").
			Count(&dest)
	})
}

func TestWave4B_96b1fd8dbd45(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "96b1fd8dbd45", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships_history mh").
			Select("mh.groupid, g.nameshort, COALESCE(g.namefull, '') AS namefull, COALESCE(g.namefull, g.nameshort) AS namedisplay, mh.added").
			Joins("INNER JOIN `groups` g ON g.id = mh.groupid").
			Where("mh.userid = ? AND DATEDIFF(NOW(), mh.added) <= 31 AND g.publish = 1 AND g.onmap = 1", 1).
			Order("mh.added DESC").
			Find(&dest)
	})
}

func TestWave4B_0c7fcde60aa8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0c7fcde60aa8", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("logs l").
			Select("l.timestamp, l.subtype AS type, l.groupid, g.nameshort, COALESCE(g.namefull, '') AS namefull, COALESCE(g.namefull, g.nameshort) AS namedisplay, COALESCE(l.text,'') AS text").
			Joins("INNER JOIN `groups` g ON g.id = l.groupid").
			Where("l.user = ? AND l.type = 'Group' AND l.subtype IN ('Joined','Approved','Rejected','Applied','Left')", 1).
			Order("l.id DESC").
			Find(&dest)
	})
}

func TestWave4B_13b31d721901(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "13b31d721901", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("bounces_emails be").
			Select("be.reason, be.date").
			Joins("INNER JOIN users_emails ue ON ue.id = be.emailid").
			Where("ue.userid = ?", 1).
			Order("be.id DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave4B_540382023ce6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "540382023ce6", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("users_images ui").
			Select("ui.id AS profileid, ui.url AS url, ui.archived, ui.externaluid, ui.externalmods, CASE WHEN JSON_EXTRACT(settings, '$.useprofile') IS NULL THEN 1 ELSE JSON_EXTRACT(settings, '$.useprofile') END AS useprofile").
			Joins("INNER JOIN users ON users.id = ui.userid").
			Where("userid = ?", 1).
			Order("ui.id DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave4B_783eedfa7e23(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "783eedfa7e23", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("users").
			Select("users.id, locations.lat AS lastlat, locations.lng as lastlng, "+
				"CAST(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.lat') AS DECIMAL(10,6)) AS mylat,"+
				"CAST(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.lng') AS DECIMAL(10,6)) as mylng").
			Joins("LEFT JOIN locations ON locations.id = users.lastlocation").
			Joins("LEFT JOIN spam_users ON spam_users.userid = users.id").
			Where("users.id = ?", 1).
			Find(&dest)
	})
}

func TestWave4B_1a851f316859(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1a851f316859", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("locations").
			Select("messages.fromuser AS id, locations.lat AS lastlat, locations.lng AS lastlng").
			Joins("INNER JOIN messages ON messages.locationid = locations.id").
			Where("messages.fromuser = ?", 1).
			Order("arrival DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave4B_532b0ba4ba45(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "532b0ba4ba45", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		return tx.Table("`groups`").
			Select("groups.id, groups.lat AS lastlat, groups.lng AS lastlng").
			Joins("INNER JOIN memberships ON groups.id = memberships.groupid").
			Where("memberships.userid = ?", 1).
			Order("added DESC").
			Limit(1).
			Find(&dest)
	})
}
