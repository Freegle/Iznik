package test

// Tier 3 shapes (plan 7.3 wave 5 / ORM keep-raw adversarial review, section
// 4): converts and proves 59 of the "runtime-varying: the statement has more
// than one possible rendered form" keep-raw sites - the optional-filter
// bucket, where the WHERE (or, for image.go/microvolunteering.go's
// literal-int-list sites, the whole statement) is assembled from a small,
// fixed set of bool/enum toggles rather than genuinely unbounded input.
//
// Every shape each site's ORIGINAL raw-SQL code could produce is declared in
// ormharness/shapes.json and covered here via AssertGoldenShapes, which fails
// unless the shapes named in this file and the shapes declared in shapes.json
// are exactly the same set (see the AssertGoldenShapes doc comment,
// ormharness/shapes.go, for why coverage is checked in both directions).
//
// Two sites this review's optional-filter bucket also covers -
// getHappinessMembers (3119115f3abe) and rippling analytics's stratumSQL
// sites - have a SECOND const-driven WHERE fragment the extractor missed the
// first time round, giving 4 real shapes each rather than 1; those are
// declared and covered the same way as every other shape here.
//
// rippling/metrics.go's reply_source_split (568a5645fba7) WAS converted,
// despite an earlier note here that it could not be: the shared
// ReplySourceSplitSQL string builder's attribution-channel CASE expression
// was pulled into its own helper, replySourceInnerFrom, which both
// ReplySourceSplitSQL (still the raw-SQL form's source of truth, still
// covered by rippling/metrics_test.go byte-for-byte) and this site's GORM
// chain now call - so the OUTER aggregation (fixed column names, no logic of
// its own) is what moved into Select/Group/Order, not a reimplementation of
// the tested part. See rippling/metrics.go for the production code.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/freegle/iznik-server-go/rippling"
	"gorm.io/gorm"
)

func find(tx *gorm.DB) *gorm.DB {
	var dest []map[string]interface{}
	return tx.Find(&dest)
}

// --- admin/admin.go: ListAdmins (site 3d5506803f0c) -------------------------

func TestTier3Shapes_3d5506803f0c(t *testing.T) {
	selectCols := "a.id, a.createdby, a.groupid, a.subject, a.text, a.ctatext, " +
		"a.ctalink, a.created, a.complete, a.heldby, a.pending, a.essential, a.template, a.editprotected"

	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins a").Select(selectCols)
	}

	ormharness.AssertGoldenShapes(t, "3d5506803f0c", []ormharness.Shape{
		{Name: "Admin_NoPending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid = ?", uint64(5)).Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Admin_Pending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid = ?", uint64(5)).Where("a.pending = 1").Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Admin_NotPending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid = ?", uint64(5)).Where("a.pending = 0").Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Mod_NoGroupid_NoPending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid IN (?)", []uint64{1, 2}).Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Mod_NoGroupid_Pending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid IN (?)", []uint64{1, 2}).Where("a.pending = 1").Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Mod_NoGroupid_NotPending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid IN (?)", []uint64{1, 2}).Where("a.pending = 0").Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Mod_WithGroupid_NoPending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid IN (?)", []uint64{1, 2}).Where("a.groupid = ?", uint64(5)).Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Mod_WithGroupid_Pending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid IN (?)", []uint64{1, 2}).Where("a.groupid = ?", uint64(5)).Where("a.pending = 1").Order("a.created DESC, a.id DESC"))
		}},
		{Name: "Mod_WithGroupid_NotPending", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("a.groupid IN (?)", []uint64{1, 2}).Where("a.groupid = ?", uint64(5)).Where("a.pending = 0").Order("a.created DESC, a.id DESC"))
		}},
	})
}

// --- chat/chatmessage.go: FetchChatMessages (site f557717fbfce) -------------

func TestTier3Shapes_f557717fbfce(t *testing.T) {
	modReview := "(reviewrejected = 0 OR userid = ?)"
	nonModReview := "(userid = ? OR (reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 " +
		"AND NOT EXISTS (SELECT 1 FROM rippling_held_replies rhr WHERE rhr.chatmsgid = chat_messages.id AND rhr.status <> 'released')))"

	build := func(mod, excl, desc, limit bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			review := modReview
			if !mod {
				review = nonModReview
			}
			whereSQL := "chatid = ? AND " + review
			whereArgs := []interface{}{uint64(1), uint64(2)}
			if !mod {
				whereSQL += " AND (users.deleted IS NULL OR users.id = ?)"
				whereArgs = append(whereArgs, uint64(2))
			}
			if excl {
				whereSQL += " AND chat_messages.id != ?"
				whereArgs = append(whereArgs, uint64(3))
			}

			t := tx.Table("chat_messages").
				Select("chat_messages.*, chat_images.archived, chat_images.externaluid AS imageuid, chat_images.externalmods AS imagemods").
				Joins("LEFT JOIN chat_images ON chat_images.chatmsgid = chat_messages.id").
				Joins("INNER JOIN users ON users.id = chat_messages.userid").
				Where(whereSQL, whereArgs...)
			if desc {
				t = t.Order("date DESC")
			} else {
				t = t.Order("date ASC")
			}
			if limit {
				t = t.Limit(10)
			}
			return find(t)
		}
	}

	ormharness.AssertGoldenShapes(t, "f557717fbfce", []ormharness.Shape{
		{Name: "Mod_Excl_Desc_Limit", Build: build(true, true, true, true)},
		{Name: "Mod_Excl_Desc_NoLimit", Build: build(true, true, true, false)},
		{Name: "Mod_Excl_Asc_Limit", Build: build(true, true, false, true)},
		{Name: "Mod_Excl_Asc_NoLimit", Build: build(true, true, false, false)},
		{Name: "Mod_NoExcl_Desc_Limit", Build: build(true, false, true, true)},
		{Name: "Mod_NoExcl_Desc_NoLimit", Build: build(true, false, true, false)},
		{Name: "Mod_NoExcl_Asc_Limit", Build: build(true, false, false, true)},
		{Name: "Mod_NoExcl_Asc_NoLimit", Build: build(true, false, false, false)},
		{Name: "NonMod_Excl_Desc_Limit", Build: build(false, true, true, true)},
		{Name: "NonMod_Excl_Desc_NoLimit", Build: build(false, true, true, false)},
		{Name: "NonMod_Excl_Asc_Limit", Build: build(false, true, false, true)},
		{Name: "NonMod_Excl_Asc_NoLimit", Build: build(false, true, false, false)},
		{Name: "NonMod_NoExcl_Desc_Limit", Build: build(false, false, true, true)},
		{Name: "NonMod_NoExcl_Desc_NoLimit", Build: build(false, false, true, false)},
		{Name: "NonMod_NoExcl_Asc_Limit", Build: build(false, false, false, true)},
		{Name: "NonMod_NoExcl_Asc_NoLimit", Build: build(false, false, false, false)},
	})
}

// --- chat/chatmessage.go: CreateChatMessage reach gate (site 67cd5e1cc4ec) --

func TestTier3Shapes_67cd5e1cc4ec(t *testing.T) {
	expr := "((ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?))))) " +
		"OR (ST_GeometryType(rr.outer_bound) = 'POINT' AND EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))))"

	ormharness.AssertGoldenShapes(t, "67cd5e1cc4ec", []ormharness.Shape{
		{Name: "Default", Build: func(tx *gorm.DB) *gorm.DB {
			args := []interface{}{-0.1, 51.5, 4326.0, -0.1, 51.5, 4326.0, -0.1, 51.5, 4326.0, -0.1, 51.5, 4326.0}
			return find(tx.Table("rippling_reach rr").
				Select("COUNT(*) AS reach_rows, COALESCE(MAX("+expr+"), 0) AS in_reach", args...).
				Where("rr.msgid = ?", uint64(1)))
		}},
	})
}

// --- chat/chatmessage.go: getChatMessagesForRoom (site 07113a2db28b) -------

func TestTier3Shapes_07113a2db28b(t *testing.T) {
	partFilter := "(chat_messages.userid = ? OR (chat_messages.reviewrequired = 0 AND chat_messages.reviewrejected = 0 AND chat_messages.processingsuccessful = 1))"
	modFilter := "(chat_messages.reviewrejected = 0 OR chat_messages.userid = ?)"

	build := func(part, ctx bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			filter := modFilter
			if part {
				filter = partFilter
			}
			whereSQL := "chat_messages.chatid = ? AND " + filter
			whereArgs := []interface{}{uint64(1), uint64(2)}
			if part {
				whereSQL += " AND (users.deleted IS NULL OR users.id = ?)"
				whereArgs = append(whereArgs, uint64(2))
			}
			if ctx {
				whereSQL += " AND chat_messages.id < ?"
				whereArgs = append(whereArgs, uint64(5))
			}

			t := tx.Table("chat_messages").
				Select("chat_messages.id, chat_messages.chatid, chat_messages.userid, "+
					"chat_messages.type, chat_messages.message, chat_messages.date, "+
					"chat_messages.refmsgid, chat_messages.reportreason").
				Joins("INNER JOIN users ON users.id = chat_messages.userid").
				Where(whereSQL, whereArgs...)
			return find(t.Order("chat_messages.id DESC").Limit(100))
		}
	}

	ormharness.AssertGoldenShapes(t, "07113a2db28b", []ormharness.Shape{
		{Name: "Participant_Ctx", Build: build(true, true)},
		{Name: "Participant_NoCtx", Build: build(true, false)},
		{Name: "Mod_Ctx", Build: build(false, true)},
		{Name: "Mod_NoCtx", Build: build(false, false)},
	})
}

// --- chat/chatroom.go: getModeratorChatIDs MOD2MOD (site 35023816be21) -----

func TestTier3Shapes_35023816be21(t *testing.T) {
	activeq := " AND (memberships.settings IS NULL OR LOCATE('\"active\"', memberships.settings) = 0 OR LOCATE('\"active\":1', memberships.settings) > 0)"
	tail := " AND chat_rooms.chattype = ? AND (chat_roster.status IS NULL OR chat_roster.status != ?) " +
		"AND chat_rooms.latestmessage >= ? AND (chat_rooms.msgvalid + chat_rooms.msginvalid = 0 OR chat_rooms.msgvalid > 0)"

	build := func(withActive bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "memberships.userid = ? AND memberships.role IN (?, ?)"
			args := []interface{}{uint64(1), "Moderator", "Owner"}
			if withActive {
				where += activeq
			}
			where += tail
			args = append(args, "Mod2Mod", "Closed", "2026-01-01")
			return find(tx.Table("chat_rooms").
				Select("DISTINCT chat_rooms.id").
				Joins("INNER JOIN memberships ON chat_rooms.groupid = memberships.groupid").
				Joins("LEFT JOIN chat_roster ON chat_roster.userid = ? AND chat_rooms.id = chat_roster.chatid", uint64(1)).
				Where(where, args...))
		}
	}

	ormharness.AssertGoldenShapes(t, "35023816be21", []ormharness.Shape{
		{Name: "NoSearch", Build: build(true)},
		{Name: "WithSearch", Build: build(false)},
	})
}

// --- chat/chatroom.go: getModeratorChatIDs USER2MOD (site e99680f74b2e) ----

func TestTier3Shapes_e99680f74b2e(t *testing.T) {
	activeq := " AND (memberships.settings IS NULL OR LOCATE('\"active\"', memberships.settings) = 0 OR LOCATE('\"active\":1', memberships.settings) > 0)"
	tail := " AND chat_rooms.chattype = ? AND (chat_roster.status IS NULL OR chat_roster.status != ?) AND chat_rooms.latestmessage >= ?"

	inner := func(tx *gorm.DB, withActive bool) *gorm.DB {
		where := "memberships.userid = ? AND (memberships.role IN (?, ?) OR chat_rooms.user1 = ?)"
		args := []interface{}{uint64(1), "Moderator", "Owner", uint64(1)}
		if withActive {
			where += activeq
		}
		where += tail
		args = append(args, "User2Mod", "Closed", "2026-01-01")
		return tx.Table("chat_rooms").
			Select("chat_rooms.id").
			Joins("INNER JOIN memberships ON chat_rooms.groupid = memberships.groupid").
			Joins("LEFT JOIN chat_roster ON chat_roster.userid = ? AND chat_rooms.id = chat_roster.chatid", uint64(1)).
			Where(where, args...)
	}

	ormharness.AssertGoldenShapes(t, "e99680f74b2e", []ormharness.Shape{
		{Name: "NoSearch", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("(?) AS combined", inner(tx, true)).Select("DISTINCT id"))
		}},
		{Name: "WithSearch", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("(?) AS combined", inner(tx, false)).Select("DISTINCT id"))
		}},
	})
}

// --- comment/comment.go: Get (site f1e9e49a9c89) ----------------------------

func TestTier3Shapes_f1e9e49a9c89(t *testing.T) {
	build := func(grp, ctx, admin bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			whereSQL := ""
			var whereArgs []interface{}
			if grp {
				whereSQL += "groupid = ? AND "
				whereArgs = append(whereArgs, uint64(1))
			}
			if ctx {
				whereSQL += "users_comments.id < ? AND "
				whereArgs = append(whereArgs, uint64(2))
			}
			if !admin {
				whereSQL += "(groupid IN (?) OR users_comments.byuserid = ?) AND "
				whereArgs = append(whereArgs, []uint64{1, 2}, uint64(3))
			}
			whereSQL += "1=1"
			return find(tx.Table("users_comments").Where(whereSQL, whereArgs...).
				Order("users_comments.id DESC").Limit(10))
		}
	}

	ormharness.AssertGoldenShapes(t, "f1e9e49a9c89", []ormharness.Shape{
		{Name: "Group_Ctx_Admin", Build: build(true, true, true)},
		{Name: "Group_Ctx_Mod", Build: build(true, true, false)},
		{Name: "Group_NoCtx_Admin", Build: build(true, false, true)},
		{Name: "Group_NoCtx_Mod", Build: build(true, false, false)},
		{Name: "NoGroup_Ctx_Admin", Build: build(false, true, true)},
		{Name: "NoGroup_Ctx_Mod", Build: build(false, true, false)},
		{Name: "NoGroup_NoCtx_Admin", Build: build(false, false, true)},
		{Name: "NoGroup_NoCtx_Mod", Build: build(false, false, false)},
	})
}

// --- emailtracking/emailtracking.go: getBouncesEmailsStats (e1daa6fea45a) --
//
// All emailtracking.go Build functions below assemble their WHERE as a
// single string for ONE Where() call: GORM's clause.Where wraps any
// fragment containing "AND"/"OR" in an extra paren pair once there is more
// than one Where expression to combine (clause/where.go buildExprs), which
// would diverge from the golden copied from the original raw SQL text.

func TestTier3Shapes_e1daa6fea45a(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("bounces_emails").
			Select("COUNT(*) as total, " +
				"SUM(CASE WHEN permanent = 1 THEN 1 ELSE 0 END) as permanent, " +
				"SUM(CASE WHEN permanent = 0 THEN 1 ELSE 0 END) as temporary")
	}
	ormharness.AssertGoldenShapes(t, "e1daa6fea45a", []ormharness.Shape{
		{Name: "NoDateRange", Build: func(tx *gorm.DB) *gorm.DB { return find(base(tx).Where("reset = 0")) }},
		{Name: "WithDateRange", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("reset = 0 AND date BETWEEN ? AND ?", "2026-01-01", "2026-01-31 23:59:59"))
		}},
	})
}

// --- emailtracking/emailtracking.go: getAMPStats (4 sites) ------------------

func amptWhere(userCol string, emailType, dateRange bool) (string, []interface{}) {
	sql := "1=1 AND " + userCol + " IS NULL"
	var args []interface{}
	if emailType {
		sql += " AND email_type = ?"
		args = append(args, "Newsletter")
	}
	if dateRange {
		sql += " AND sent_at BETWEEN ? AND ?"
		args = append(args, "2026-01-01", "2026-01-31 23:59:59")
	}
	return sql, args
}

func ampeWhere(userCol string, emailType, dateRange bool) (string, []interface{}) {
	sql := "1=1 AND " + userCol + " IS NULL"
	var args []interface{}
	if emailType {
		sql += " AND email_type = ?"
		args = append(args, "Newsletter")
	}
	if dateRange {
		sql += " AND e.sent_at BETWEEN ? AND ?"
		args = append(args, "2026-01-01", "2026-01-31 23:59:59")
	}
	return sql, args
}

func TestTier3Shapes_52ecf7eb71ac(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking").
			Select("COUNT(*) as total, " +
				"SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened, " +
				"SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked, " +
				"SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces, " +
				"SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied, " +
				"SUM(CASE WHEN replied_via = 'amp' THEN 1 ELSE 0 END) as replied_via_amp, " +
				"SUM(CASE WHEN replied_via = 'email' THEN 1 ELSE 0 END) as replied_via_email, " +
				"SUM(CASE WHEN opened_via = 'amp' THEN 1 ELSE 0 END) as rendered").
			Joins("LEFT JOIN users ON email_tracking.userid = users.id")
	}
	build := func(emailType, dateRange bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			cond, args := amptWhere("users.tnuserid", emailType, dateRange)
			return find(base(tx).Where(cond+" AND has_amp = 1", args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "52ecf7eb71ac", []ormharness.Shape{
		{Name: "Base", Build: build(false, false)},
		{Name: "Type", Build: build(true, false)},
		{Name: "Date", Build: build(false, true)},
		{Name: "TypeDate", Build: build(true, true)},
	})
}

func TestTier3Shapes_c8a4c6cbcae8(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking").
			Select("COUNT(*) as total, " +
				"SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened, " +
				"SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked, " +
				"SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces, " +
				"SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied").
			Joins("LEFT JOIN users ON email_tracking.userid = users.id")
	}
	build := func(emailType, dateRange bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			cond, args := amptWhere("users.tnuserid", emailType, dateRange)
			return find(base(tx).Where(cond+" AND has_amp = 0", args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "c8a4c6cbcae8", []ormharness.Shape{
		{Name: "Base", Build: build(false, false)},
		{Name: "Type", Build: build(true, false)},
		{Name: "Date", Build: build(false, true)},
		{Name: "TypeDate", Build: build(true, true)},
	})
}

func TestTier3Shapes_ef321afa5b7a(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking_clicks c").
			Select("COUNT(DISTINCT CASE WHEN c.link_url LIKE '%/message/%' OR c.link_url LIKE '%/chat/%' OR c.link_url LIKE '%/chats/%' THEN c.email_tracking_id END) as reply_clicks, " +
				"COUNT(DISTINCT CASE WHEN c.link_url NOT LIKE '%/message/%' AND c.link_url NOT LIKE '%/chat/%' AND c.link_url NOT LIKE '%/chats/%' AND c.link_url NOT LIKE 'amp://%' THEN c.email_tracking_id END) as other_clicks").
			Joins("JOIN email_tracking e ON c.email_tracking_id = e.id").
			Joins("LEFT JOIN users u ON e.userid = u.id")
	}
	build := func(emailType, dateRange bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			cond, args := ampeWhere("u.tnuserid", emailType, dateRange)
			return find(base(tx).Where("e.has_amp = 1 AND "+cond, args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "ef321afa5b7a", []ormharness.Shape{
		{Name: "Base", Build: build(false, false)},
		{Name: "Type", Build: build(true, false)},
		{Name: "Date", Build: build(false, true)},
		{Name: "TypeDate", Build: build(true, true)},
	})
}

func TestTier3Shapes_b37a229bfb0b(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking_clicks c").
			Select("COUNT(DISTINCT CASE WHEN c.link_url LIKE '%/message/%' OR c.link_url LIKE '%/chat/%' OR c.link_url LIKE '%/chats/%' THEN c.email_tracking_id END) as reply_clicks, " +
				"COUNT(DISTINCT CASE WHEN c.link_url NOT LIKE '%/message/%' AND c.link_url NOT LIKE '%/chat/%' AND c.link_url NOT LIKE '%/chats/%' THEN c.email_tracking_id END) as other_clicks").
			Joins("JOIN email_tracking e ON c.email_tracking_id = e.id").
			Joins("LEFT JOIN users u ON e.userid = u.id")
	}
	build := func(emailType, dateRange bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			cond, args := ampeWhere("u.tnuserid", emailType, dateRange)
			return find(base(tx).Where("e.has_amp = 0 AND "+cond, args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "b37a229bfb0b", []ormharness.Shape{
		{Name: "Base", Build: build(false, false)},
		{Name: "Type", Build: build(true, false)},
		{Name: "Date", Build: build(false, true)},
		{Name: "TypeDate", Build: build(true, true)},
	})
}

// --- emailtracking/emailtracking.go: TimeSeries (2 sites) -------------------

func TestTier3Shapes_69f6acdc5a6b(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking FORCE INDEX (sent_at)").
			Select("DATE(sent_at) as date, COUNT(*) as sent, " +
				"SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened, " +
				"SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked, " +
				"SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces, " +
				"SUM(CASE WHEN has_amp = 1 THEN 1 ELSE 0 END) as amp_sent, " +
				"SUM(CASE WHEN has_amp = 1 AND opened_at IS NOT NULL THEN 1 ELSE 0 END) as amp_opened, " +
				"SUM(CASE WHEN has_amp = 1 AND clicked_at IS NOT NULL THEN 1 ELSE 0 END) as amp_clicked, " +
				"SUM(CASE WHEN has_amp = 1 AND bounced_at IS NOT NULL THEN 1 ELSE 0 END) as amp_linked_bounces, " +
				"SUM(CASE WHEN has_amp = 1 AND replied_at IS NOT NULL THEN 1 ELSE 0 END) as amp_replied, " +
				"SUM(CASE WHEN has_amp = 0 THEN 1 ELSE 0 END) as non_amp_sent, " +
				"SUM(CASE WHEN has_amp = 0 AND opened_at IS NOT NULL THEN 1 ELSE 0 END) as non_amp_opened, " +
				"SUM(CASE WHEN has_amp = 0 AND clicked_at IS NOT NULL THEN 1 ELSE 0 END) as non_amp_clicked, " +
				"SUM(CASE WHEN has_amp = 0 AND bounced_at IS NOT NULL THEN 1 ELSE 0 END) as non_amp_linked_bounces").
			Joins("LEFT JOIN users ON email_tracking.userid = users.id")
	}
	build := func(withType bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "users.tnuserid IS NULL AND sent_at BETWEEN ? AND ?"
			args := []interface{}{"2026-01-01", "2026-01-31 23:59:59"}
			if withType {
				where += " AND email_type = ?"
				args = append(args, "Newsletter")
			}
			return find(base(tx).Where(where, args...).Group("DATE(sent_at)").Order("date ASC"))
		}
	}
	ormharness.AssertGoldenShapes(t, "69f6acdc5a6b", []ormharness.Shape{
		{Name: "NoType", Build: build(false)},
		{Name: "WithType", Build: build(true)},
	})
}

func TestTier3Shapes_9d115fb3ebcd(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "9d115fb3ebcd", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("bounces_emails").
				Select("DATE(date) as date, COUNT(*) as total_bounces, "+
					"SUM(CASE WHEN permanent = 1 THEN 1 ELSE 0 END) as permanent_bounces, "+
					"SUM(CASE WHEN permanent = 0 THEN 1 ELSE 0 END) as temporary_bounces").
				Where("reset = 0 AND date BETWEEN ? AND ?", "2026-01-01", "2026-01-31 23:59:59").
				Group("DATE(date)"))
		}},
	})
}

// --- emailtracking/emailtracking.go: StatsByType (ecbedcafc048) ------------

func TestTier3Shapes_ecbedcafc048(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking FORCE INDEX (sent_at)").
			Select("email_type, COUNT(*) as total_sent, " +
				"SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened, " +
				"SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked, " +
				"SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces").
			Joins("LEFT JOIN users ON email_tracking.userid = users.id")
	}
	ormharness.AssertGoldenShapes(t, "ecbedcafc048", []ormharness.Shape{
		{Name: "NoDateRange", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("users.tnuserid IS NULL").Group("email_type").Order("total_sent DESC"))
		}},
		{Name: "WithDateRange", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("users.tnuserid IS NULL AND sent_at BETWEEN ? AND ?", "2026-01-01", "2026-01-31 23:59:59").
				Group("email_type").Order("total_sent DESC"))
		}},
	})
}

// --- emailtracking/emailtracking.go: TopClickedLinks (f6e4a52a0fb6) --------

func TestTier3Shapes_f6e4a52a0fb6(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking_clicks c").
			Select("c.link_url, COUNT(*) as click_count").
			Joins("JOIN email_tracking e ON c.email_tracking_id = e.id")
	}
	ormharness.AssertGoldenShapes(t, "f6e4a52a0fb6", []ormharness.Shape{
		{Name: "NoDateRange", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("1=1").Group("c.link_url").Order("click_count DESC"))
		}},
		{Name: "WithDateRange", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("1=1 AND c.clicked_at BETWEEN ? AND ?", "2026-01-01", "2026-01-31 23:59:59").
				Group("c.link_url").Order("click_count DESC"))
		}},
	})
}

// --- emailtracking/emailtracking.go: DigestClickPositions (2 sites) --------

func digestCohortWhere(explicitType bool, extra string) (string, []interface{}) {
	sql := "e.email_type LIKE 'UnifiedDigest%'"
	var args []interface{}
	if explicitType {
		sql = "e.email_type = ?"
		args = append(args, "UnifiedDigestDaily")
	}
	sql += " AND u.tnuserid IS NULL AND e.metadata IS NOT NULL " +
		"AND JSON_LENGTH(e.metadata, '$.post_msgids') > 0 AND e.sent_at BETWEEN ? AND ?"
	args = append(args, "2026-01-01", "2026-01-31 23:59:59")
	if extra != "" {
		sql += " AND " + extra
	}
	return sql, args
}

func TestTier3Shapes_284dfbc44866(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking e FORCE INDEX (sent_at)").
			Select("JSON_LENGTH(e.metadata, '$.post_msgids') AS num_posts, COUNT(*) AS cnt").
			Joins("LEFT JOIN users u ON e.userid = u.id")
	}
	build := func(explicitType bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where, args := digestCohortWhere(explicitType, "")
			return find(base(tx).Where(where, args...).Group("num_posts"))
		}
	}
	ormharness.AssertGoldenShapes(t, "284dfbc44866", []ormharness.Shape{
		{Name: "DefaultType", Build: build(false)},
		{Name: "ExplicitType", Build: build(true)},
	})
}

func TestTier3Shapes_dce69264c9c0(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking_clicks c").
			Select("c.link_position AS link_position, COUNT(DISTINCT c.email_tracking_id) AS emails_clicked, COUNT(*) AS clicks").
			Joins("JOIN email_tracking e ON c.email_tracking_id = e.id").
			Joins("LEFT JOIN users u ON e.userid = u.id")
	}
	build := func(explicitType bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where, args := digestCohortWhere(explicitType, "c.link_position REGEXP '^(post_[0-9]+|p[0-9]+)$'")
			return find(base(tx).Where(where, args...).Group("c.link_position"))
		}
	}
	ormharness.AssertGoldenShapes(t, "dce69264c9c0", []ormharness.Shape{
		{Name: "DefaultType", Build: build(false)},
		{Name: "ExplicitType", Build: build(true)},
	})
}

// --- embedding/store.go: fetchEntries (site 15d5998c44f2) -------------------

func TestTier3Shapes_15d5998c44f2(t *testing.T) {
	base := func(tx *gorm.DB, where string, args ...interface{}) *gorm.DB {
		return tx.Table("messages_embeddings me").
			Select("me.msgid, m.fromuser, me.subject_embedding, me.body_embedding, "+
				"ms.groupid, ms.msgtype, ST_Y(ms.point) as lat, ST_X(ms.point) as lng, "+
				"m.subject, ms.arrival").
			Joins("INNER JOIN messages_spatial ms ON ms.msgid = me.msgid").
			Joins("INNER JOIN messages m ON m.id = me.msgid").
			Where(where, args...)
	}
	ormharness.AssertGoldenShapes(t, "15d5998c44f2", []ormharness.Shape{
		{Name: "NoExtra", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, "ms.successful = 0 AND ms.promised = 0"))
		}},
		{Name: "WithMsgidIn", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, "ms.successful = 0 AND ms.promised = 0 AND me.msgid IN (?)", []int64{1, 2, 3}))
		}},
	})
}

// --- image/image.go: doRotate (2 sites) -------------------------------------

var doRotateTables = []struct {
	name, table, idcol string
}{
	{"Message", "messages_attachments", "msgid"},
	{"Group", "groups_images", "groupid"},
	{"Newsletter", "newsletters_images", "articleid"},
	{"CommunityEvent", "communityevents_images", "eventid"},
	{"Volunteering", "volunteering_images", "opportunityid"},
	{"ChatMessage", "chat_images", "chatmsgid"},
	{"User", "users_images", "userid"},
	{"Newsfeed", "newsfeed_images", "newsfeedid"},
	{"Story", "users_stories_images", "storyid"},
	{"Noticeboard", "noticeboards_images", "noticeboardid"},
}

func TestTier3Shapes_6f9c3996f035(t *testing.T) {
	shapes := make([]ormharness.Shape, len(doRotateTables))
	for i, e := range doRotateTables {
		e := e
		shapes[i] = ormharness.Shape{Name: e.name, Build: func(tx *gorm.DB) *gorm.DB {
			var dest uint64
			return tx.Table("`"+e.table+"`").Select("`"+e.idcol+"`").Where("id = ?", uint64(1)).Find(&dest)
		}}
	}
	ormharness.AssertGoldenShapes(t, "6f9c3996f035", shapes)
}

func TestTier3Shapes_2ad46344c8b2(t *testing.T) {
	shapes := make([]ormharness.Shape, len(doRotateTables))
	for i, e := range doRotateTables {
		e := e
		shapes[i] = ormharness.Shape{Name: e.name, Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`"+e.table+"`").Where("id = ?", uint64(1)).Update("externalmods", `{"rotate":90}`)
		}}
	}
	ormharness.AssertGoldenShapes(t, "2ad46344c8b2", shapes)
}

// doCreate's two raw INSERTs split on cfg.HasContentType: every typeConfigs
// entry except Message has a NOT NULL contenttype column, so site
// 1571f00a4ce8 (WITH contenttype) is reachable from the 9 non-Message
// entries and site b0445c89f59e (WITHOUT it) only from Message - see
// image.go's typeConfigs and doCreate.
func TestTier3Shapes_1571f00a4ce8(t *testing.T) {
	var shapes []ormharness.Shape
	for _, e := range doRotateTables {
		if e.name == "Message" {
			continue // no contenttype column: covered by TestTier3Shapes_b0445c89f59e below
		}
		e := e
		shapes = append(shapes, ormharness.Shape{Name: e.name, Build: func(tx *gorm.DB) *gorm.DB {
			row := map[string]interface{}{
				e.idcol:        uint64(1),
				"externaluid":  "abc123",
				"externalmods": "{}",
				"hash":         "deadbeef",
				"contenttype":  gorm.Expr("'image/jpeg'"),
			}
			return tx.Table("`" + e.table + "`").Create(row)
		}})
	}
	ormharness.AssertGoldenShapes(t, "1571f00a4ce8", shapes)
}

func TestTier3Shapes_b0445c89f59e(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "b0445c89f59e", []ormharness.Shape{
		{Name: "Message", Build: func(tx *gorm.DB) *gorm.DB {
			row := map[string]interface{}{
				"msgid":        uint64(1),
				"externaluid":  "abc123",
				"externalmods": "{}",
				"hash":         "deadbeef",
			}
			return tx.Table("`messages_attachments`").Create(row)
		}},
	})
}

// --- location/location.go: SearchLocations typeahead + Typeahead -----------

func locationTypeaheadBase(tx *gorm.DB) *gorm.DB {
	return tx.Table("locations l1").
		Select("l1.id, l1.name, l1.areaid, l1.lat, l1.lng, l1.type, l2.name as areaname, l2.lat as arealat, l2.lng as arealng").
		Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
		Where("l1.name LIKE ?", "SW1%")
}

func TestTier3Shapes_b262bf75df3c(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "b262bf75df3c", []ormharness.Shape{
		{Name: "PostcodeOnly", Build: func(tx *gorm.DB) *gorm.DB {
			return find(locationTypeaheadBase(tx).Where("l1.type = 'Postcode'").Where("l1.name LIKE '% %'").Limit(10))
		}},
		{Name: "AnyType", Build: func(tx *gorm.DB) *gorm.DB {
			return find(locationTypeaheadBase(tx).Where("l1.name LIKE '% %'").Limit(10))
		}},
	})
}

func TestTier3Shapes_71f1772f4a99(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "71f1772f4a99", []ormharness.Shape{
		{Name: "PostcodeOnly", Build: func(tx *gorm.DB) *gorm.DB {
			return find(locationTypeaheadBase(tx).Where("l1.type = 'Postcode'").Where("l1.name LIKE '% %'").Limit(10))
		}},
		{Name: "AnyType", Build: func(tx *gorm.DB) *gorm.DB {
			return find(locationTypeaheadBase(tx).Where("l1.name LIKE '% %'").Limit(10))
		}},
	})
}

// --- membership/membership.go: GetMemberships (5 sites) + siblings ---------

const membershipSelectCols = "m.id, m.userid, m.groupid, m.role, m.collection, m.added, m.heldby, " +
	"u.fullname, u.firstname, u.lastname, m.settings, " +
	"m.emailfrequency, m.ourPostingStatus, m.eventsallowed, m.volunteeringallowed, " +
	"b.date AS bandate, b.byuser AS bannedby, " +
	"m.reviewrequestedat, m.reviewedat, m.reviewreason, u.engagement, u.bouncing"

func membershipBase(tx *gorm.DB) *gorm.DB {
	return tx.Table("memberships m").
		Select(membershipSelectCols).
		Joins("JOIN users u ON u.id = m.userid").
		Joins("LEFT JOIN users_banned b ON b.userid = m.userid AND b.groupid = m.groupid")
}

func membershipFilterWhere(filter string) string {
	switch filter {
	case "Notes":
		return " AND EXISTS (SELECT 1 FROM users_comments uc WHERE uc.userid = m.userid AND uc.groupid = m.groupid)"
	case "ModTeam":
		return " AND m.role IN ('Owner', 'Moderator')"
	case "Bouncing":
		return " AND u.bouncing = 1"
	}
	return ""
}

func membershipGroupWhere(allGroups bool) (string, []interface{}) {
	if allGroups {
		return "m.groupid IN (SELECT groupid FROM memberships WHERE userid = ? AND role IN ('Moderator', 'Owner') AND collection = 'Approved')", []interface{}{uint64(1)}
	}
	return "m.groupid = ?", []interface{}{uint64(5)}
}

func TestTier3Shapes_2c3b155f346b(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned b").
			Select("b.userid, b.groupid, 'Member' AS role, 'Banned' AS collection, "+
				"b.date AS added, b.date AS bandate, b.byuser AS bannedby, "+
				"u.fullname, u.firstname, u.lastname, u.engagement, "+
				"b.userid AS id, NULL AS heldby, NULL AS settings, "+
				"0 AS emailfrequency, 'DEFAULT' AS ourPostingStatus, 0 AS eventsallowed, 0 AS volunteeringallowed, "+
				"NULL AS reviewrequestedat, NULL AS reviewedat, NULL AS reviewreason").
			Joins("JOIN users u ON u.id = b.userid").
			Where("b.groupid = ?", uint64(5))
	}
	ormharness.AssertGoldenShapes(t, "2c3b155f346b", []ormharness.Shape{
		{Name: "NoContext", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Order("b.userid DESC").Limit(100))
		}},
		{Name: "WithContext", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx).Where("b.userid < ?", uint64(9)).Order("b.userid DESC").Limit(100))
		}},
	})
}

func membershipFilterShapes(build func(allGroups bool, filter string) func(tx *gorm.DB) *gorm.DB) []ormharness.Shape {
	names := []struct {
		label     string
		allGroups bool
		filter    string
	}{
		{"OneGroup_NoFilter", false, ""}, {"OneGroup_Notes", false, "Notes"},
		{"OneGroup_ModTeam", false, "ModTeam"}, {"OneGroup_Bouncing", false, "Bouncing"},
		{"AllGroups_NoFilter", true, ""}, {"AllGroups_Notes", true, "Notes"},
		{"AllGroups_ModTeam", true, "ModTeam"}, {"AllGroups_Bouncing", true, "Bouncing"},
	}
	shapes := make([]ormharness.Shape, len(names))
	for i, n := range names {
		shapes[i] = ormharness.Shape{Name: n.label, Build: build(n.allGroups, n.filter)}
	}
	return shapes
}

func TestTier3Shapes_836dc8807739(t *testing.T) {
	build := func(allGroups bool, filter string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			groupWhere, groupArgs := membershipGroupWhere(allGroups)
			where := groupWhere + " AND m.collection = ?" + membershipFilterWhere(filter) + " AND m.userid = ?"
			args := append(append([]interface{}{}, groupArgs...), "Approved", uint64(3))
			return find(membershipBase(tx).Where(where, args...).Order("m.added DESC").Limit(100))
		}
	}
	ormharness.AssertGoldenShapes(t, "836dc8807739", membershipFilterShapes(build))
}

func TestTier3Shapes_5f742c0fcf1f(t *testing.T) {
	build := func(allGroups bool, filter string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			groupWhere, groupArgs := membershipGroupWhere(allGroups)
			where := groupWhere + " AND m.collection = ?" + membershipFilterWhere(filter) +
				" AND (u.fullname LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR ue.email LIKE ?)"
			args := append(append([]interface{}{}, groupArgs...), "Approved", "%a%", "%a%", "%a%", "%a%")
			return find(membershipBase(tx).Joins("LEFT JOIN users_emails ue ON ue.userid = m.userid").
				Where(where, args...).Group("m.id").Order("m.added DESC").Limit(100))
		}
	}
	ormharness.AssertGoldenShapes(t, "5f742c0fcf1f", membershipFilterShapes(build))
}

func TestTier3Shapes_bbc55cf96110(t *testing.T) {
	names := []struct {
		label     string
		allGroups bool
		filter    string
		ctx       bool
	}{
		{"OneGroup_NoFilter_NoCtx", false, "", false}, {"OneGroup_NoFilter_Ctx", false, "", true},
		{"OneGroup_Notes_NoCtx", false, "Notes", false}, {"OneGroup_Notes_Ctx", false, "Notes", true},
		{"OneGroup_ModTeam_NoCtx", false, "ModTeam", false}, {"OneGroup_ModTeam_Ctx", false, "ModTeam", true},
		{"OneGroup_Bouncing_NoCtx", false, "Bouncing", false}, {"OneGroup_Bouncing_Ctx", false, "Bouncing", true},
		{"AllGroups_NoFilter_NoCtx", true, "", false}, {"AllGroups_NoFilter_Ctx", true, "", true},
		{"AllGroups_Notes_NoCtx", true, "Notes", false}, {"AllGroups_Notes_Ctx", true, "Notes", true},
		{"AllGroups_ModTeam_NoCtx", true, "ModTeam", false}, {"AllGroups_ModTeam_Ctx", true, "ModTeam", true},
		{"AllGroups_Bouncing_NoCtx", true, "Bouncing", false}, {"AllGroups_Bouncing_Ctx", true, "Bouncing", true},
	}
	shapes := make([]ormharness.Shape, len(names))
	for i, n := range names {
		n := n
		shapes[i] = ormharness.Shape{Name: n.label, Build: func(tx *gorm.DB) *gorm.DB {
			groupWhere, groupArgs := membershipGroupWhere(n.allGroups)
			where := groupWhere + " AND m.collection = ?" + membershipFilterWhere(n.filter)
			args := append(append([]interface{}{}, groupArgs...), "Approved")
			if n.ctx {
				where += " AND m.id < ?"
				args = append(args, uint64(9))
			}
			return find(membershipBase(tx).Where(where, args...).Order("m.id DESC").Limit(100))
		}}
	}
	ormharness.AssertGoldenShapes(t, "bbc55cf96110", shapes)
}

func TestTier3Shapes_5f6ca1b9022f(t *testing.T) {
	build := func(allGroups bool, filter string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			groupWhere, groupArgs := membershipGroupWhere(allGroups)
			where := groupWhere + " AND m.collection = ?" + membershipFilterWhere(filter)
			args := append(append([]interface{}{}, groupArgs...), "Approved")
			return find(tx.Table("memberships m").
				Select("COUNT(DISTINCT m.userid)").
				Joins("JOIN users u ON u.id = m.userid").
				Where(where, args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "5f6ca1b9022f", membershipFilterShapes(build))
}

func TestTier3Shapes_fdd14a1656c7(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "fdd14a1656c7", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			return find(membershipBase(tx).
				Where("m.groupid IN ? AND m.reviewrequestedat IS NOT NULL AND (m.reviewedat IS NULL OR m.reviewrequestedat > m.reviewedat)", []uint64{1, 2}).
				Order("m.userid DESC").Limit(100))
		}},
	})
}

func TestTier3Shapes_3119115f3abe(t *testing.T) {
	build := func(happinessExtra string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "mo.timestamp > ? AND mo.comments IS NOT NULL AND mo.comments NOT IN (?)"
			args := []interface{}{"2026-01-01", []string{"Auto-generated"}}
			if happinessExtra != "" {
				where += " AND " + happinessExtra
			}
			where += " AND mg.arrival > ?"
			args = append(args, "2026-01-01")
			return find(tx.Table("messages_outcomes mo").
				Select("mo.id, mo.timestamp, mo.msgid, mo.outcome, mo.happiness, mo.comments, mo.reviewed, "+
					"m.fromuser, mg.groupid, m.subject").
				Joins("INNER JOIN messages_groups mg ON mg.msgid = mo.msgid AND mg.groupid IN (?) AND mg.rippled_in = 0", []uint64{1, 2}).
				Joins("INNER JOIN messages m ON m.id = mo.msgid").
				Where(where, args...).
				Order("mo.reviewed ASC, mo.timestamp DESC, mo.id DESC").Limit(100))
		}
	}
	ormharness.AssertGoldenShapes(t, "3119115f3abe", []ormharness.Shape{
		{Name: "NoFilter", Build: build("")},
		{Name: "Happy", Build: build("mo.happiness = 'Happy'")},
		{Name: "Unhappy", Build: build("mo.happiness = 'Unhappy'")},
		{Name: "Fine", Build: build("(mo.happiness IS NULL OR mo.happiness = 'Fine')")},
	})
}

func TestTier3Shapes_1a000d04649b(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "1a000d04649b", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("ratings").
				Select("ratings.id, ratings.rater, ratings.ratee, ratings.rating, ratings.reason, "+
					"ratings.text, ratings.visible, ratings.timestamp, ratings.reviewrequired, "+
					"m1.groupid, "+
					"CASE WHEN u1.fullname IS NOT NULL THEN u1.fullname ELSE CONCAT(u1.firstname, ' ', u1.lastname) END AS raterdisplayname, "+
					"CASE WHEN u2.fullname IS NOT NULL THEN u2.fullname ELSE CONCAT(u2.firstname, ' ', u2.lastname) END AS rateedisplayname").
				Joins("INNER JOIN memberships m1 ON m1.userid = ratings.rater").
				Joins("INNER JOIN memberships m2 ON m2.userid = ratings.ratee").
				Joins("INNER JOIN users u1 ON ratings.rater = u1.id").
				Joins("INNER JOIN users u2 ON ratings.ratee = u2.id").
				Where("ratings.timestamp >= ?", "2026-01-01").
				Where("m1.groupid IN (?)", []uint64{1, 2}).
				Where("m2.groupid IN (?)", []uint64{1, 2}).
				Where("m1.groupid = m2.groupid").
				Where("ratings.rating IS NOT NULL").
				Group("ratings.id").
				Order("ratings.timestamp DESC"))
		}},
	})
}

// --- message/groups.go: Groups (site bc6d923b540d) --------------------------

func TestTier3Shapes_bc6d923b540d(t *testing.T) {
	union := " UNION SELECT lat, lng, messages.id, " +
		"ANY_VALUE(CASE WHEN messages_outcomes.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, " +
		"ANY_VALUE(CASE WHEN messages_promises.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, " +
		"ANY_VALUE(messages_groups.groupid) AS groupid, " +
		"messages.type, " +
		"MAX(messages_groups.arrival) AS arrival, " +
		"messages.arrival AS posted, " +
		"ANY_VALUE(CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END) AS unseen " +
		"FROM messages " +
		"INNER JOIN messages_groups ON messages_groups.msgid = messages.id " +
		"LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id " +
		"LEFT JOIN messages_promises ON messages_promises.msgid = messages.id " +
		"LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ? " +
		"WHERE fromuser = ? AND messages_groups.arrival >= ? " +
		"AND messages_outcomes.id IS NULL " +
		"GROUP BY messages.id) t"

	selectHead := "SELECT ST_Y(point) AS lat, " +
		"ST_X(point) AS lng, " +
		"messages_spatial.msgid AS id, " +
		"messages_spatial.successful, " +
		"messages_spatial.promised, " +
		"messages_spatial.groupid, " +
		"messages_spatial.msgtype AS type, " +
		"messages_spatial.arrival, " +
		"m.arrival AS posted, " +
		"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen " +
		"FROM messages_spatial " +
		"INNER JOIN messages m ON m.id = messages_spatial.msgid " +
		"LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ? " +
		"WHERE 1=1 "

	ormharness.AssertGoldenShapes(t, "bc6d923b540d", []ormharness.Shape{
		{Name: "SpecificGroup", Build: func(tx *gorm.DB) *gorm.DB {
			filter := "AND EXISTS (SELECT 1 FROM messages_groups mg WHERE mg.msgid = messages_spatial.msgid " +
				"AND mg.groupid = 5 AND mg.collection = 'Approved' AND mg.deleted = 0) "
			derived := "(" + selectHead + filter + union
			return find(tx.Table(derived,
				uint64(1), "View", "Taken", "Received", uint64(1), "View", uint64(1), "2026-01-01").
				Select("*").Order("unseen DESC, arrival DESC, id DESC"))
		}},
		{Name: "CombinedBrowse", Build: func(tx *gorm.DB) *gorm.DB {
			filter := "AND EXISTS (SELECT 1 FROM messages_groups mg INNER JOIN memberships mem ON mem.groupid = mg.groupid " +
				"WHERE mg.msgid = messages_spatial.msgid AND mem.userid = ? AND mg.collection = 'Approved' AND mg.deleted = 0) "
			derived := "(" + selectHead + filter + union
			return find(tx.Table(derived,
				uint64(1), "View", uint64(1), "Taken", "Received", uint64(1), "View", uint64(1), "2026-01-01").
				Select("*").Order("unseen DESC, arrival DESC, id DESC"))
		}},
	})
}

// --- message/message.go: GetMessagesByIds (site 08bb471351a0) --------------

func TestTier3Shapes_08bb471351a0(t *testing.T) {
	base := "messages.id, messages.arrival, messages.date, messages.fromuser, " +
		"messages.subject, messages.type, textbody, lat, lng, availablenow, availableinitially, locationid, " +
		"deliverypossible, deadline, heldby, messages.source, messages.sourceheader, messages.fromaddr, messages.fromip, messages.fromcountry, messages.tnpostid, "

	build := func(isMod bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			cols := base
			if isMod {
				cols += "messages.message, "
			}
			cols += "CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen"

			// Single WHERE string, single Where() call - matching
			// message/message.go's production consolidation - so GORM
			// doesn't wrap the base condition in an extra paren pair
			// when a second AND-ed condition is appended (clause/where.go
			// buildExprs).
			whereSQL := "messages.id = ? AND messages.deleted IS NULL"
			whereArgs := []interface{}{uint64(2)}
			if !isMod {
				whereSQL += " AND users.deleted IS NULL"
			}

			t := tx.Table("messages").
				Select(cols).
				Joins("LEFT JOIN users ON users.id = messages.fromuser").
				Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ?", uint64(1), "View").
				Where(whereSQL, whereArgs...)
			// Find, not First: matches production (message/message.go).
			// First() on a Table()-only query with no registered Model
			// fails outright trying to resolve its implicit "ORDER BY
			// <primary key>" (Schema is nil - "model value required",
			// statement.go), not just a golden-text mismatch. See
			// group/group.go's GetGroup (site 2811b4d3acf7) for the
			// established fix this mirrors.
			return find(t)
		}
	}

	ormharness.AssertGoldenShapes(t, "08bb471351a0", []ormharness.Shape{
		{Name: "NonMod", Build: build(false)},
		{Name: "Mod", Build: build(true)},
	})
}

// --- message/message.go: GetMessagesForUser (2 sites) -----------------------

const gmfuSelectBase = "messages.lat, messages.lng, messages.id, messages_groups.groupid, messages_groups.collection, messages.type, messages_groups.arrival, messages.date, " +
	"messages_spatial.id AS spatialid, " +
	"EXISTS(SELECT id FROM messages_outcomes WHERE messages_outcomes.msgid = messages.id) AS hasoutcome, " +
	"EXISTS(SELECT id FROM messages_outcomes WHERE messages_outcomes.msgid = messages.id AND outcome IN (?, ?)) AS successful, " +
	"EXISTS(SELECT id FROM messages_promises WHERE messages_promises.msgid = messages.id) AS promised, "

const gmfuWhereTail = "fromuser = ? AND messages.deleted IS NULL AND users.deleted IS NULL AND messages_groups.deleted = 0 AND " +
	"messages_groups.rippled_in = 0 AND messages.type IN (?, ?)"

func TestTier3Shapes_2de07c2af78b(t *testing.T) {
	build := func(active bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			t := tx.Table("messages").
				Select(gmfuSelectBase+"0 AS unseen", "Taken", "Received").
				Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
				Joins("INNER JOIN users ON users.id = messages.fromuser").
				Joins("LEFT JOIN messages_spatial ON messages_spatial.msgid = messages.id").
				Where(gmfuWhereTail, uint64(1), "Offer", "Wanted")
			if active {
				t = t.Having("((hasoutcome = 0 AND spatialid IS NOT NULL) OR messages_groups.collection IN ('Pending', 'Rejected'))")
			}
			return find(t.Order("unseen DESC, messages_groups.arrival DESC"))
		}
	}
	ormharness.AssertGoldenShapes(t, "2de07c2af78b", []ormharness.Shape{
		{Name: "Inactive", Build: build(false)},
		{Name: "Active", Build: build(true)},
	})
}

func TestTier3Shapes_bca1186d1ea4(t *testing.T) {
	build := func(active bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			t := tx.Table("messages").
				Select(gmfuSelectBase+"NOT EXISTS(SELECT msgid FROM messages_likes WHERE messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ?) AS unseen",
					"Taken", "Received", uint64(2), "View").
				Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
				Joins("INNER JOIN users ON users.id = messages.fromuser")
			if active {
				t = t.Joins("INNER JOIN messages_spatial ON messages_spatial.msgid = messages.id")
			} else {
				t = t.Joins("LEFT JOIN messages_spatial ON messages_spatial.msgid = messages.id")
			}
			t = t.Where(gmfuWhereTail, uint64(1), "Offer", "Wanted")
			if active {
				t = t.Having("hasoutcome = 0")
			}
			return find(t.Order("unseen DESC, messages_groups.arrival DESC"))
		}
	}
	ormharness.AssertGoldenShapes(t, "bca1186d1ea4", []ormharness.Shape{
		{Name: "Inactive", Build: build(false)},
		{Name: "Active", Build: build(true)},
	})
}

// --- message/message_list.go: ListMessages (site bfe25b4914e8) -------------

func TestTier3Shapes_bfe25b4914e8(t *testing.T) {
	base := func(tx *gorm.DB, where string, args ...interface{}) *gorm.DB {
		return tx.Table("messages_groups mg").
			Select("DISTINCT mg.msgid").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where(where, args...)
	}
	const prefix = "mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser IS NOT NULL"
	ormharness.AssertGoldenShapes(t, "bfe25b4914e8", []ormharness.Shape{
		{Name: "NoFromuser_NoCtx", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, prefix, []uint64{1, 2}, "Approved").Order("mg.arrival DESC, mg.msgid DESC").Limit(20))
		}},
		{Name: "Fromuser_NoCtx", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, prefix+" AND m.fromuser = ?", []uint64{1, 2}, "Approved", uint64(3)).
				Order("mg.arrival DESC, mg.msgid DESC").Limit(20))
		}},
		{Name: "NoFromuser_Ctx", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, prefix+" AND (mg.arrival < ? OR (mg.arrival = ? AND mg.msgid < ?))",
				[]uint64{1, 2}, "Approved", "2026-01-01", "2026-01-01", uint64(9)).
				Order("mg.arrival DESC, mg.msgid DESC").Limit(20))
		}},
		{Name: "Fromuser_Ctx", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, prefix+" AND m.fromuser = ? AND (mg.arrival < ? OR (mg.arrival = ? AND mg.msgid < ?))",
				[]uint64{1, 2}, "Approved", uint64(3), "2026-01-01", "2026-01-01", uint64(9)).
				Order("mg.arrival DESC, mg.msgid DESC").Limit(20))
		}},
	})
}

// --- message/reach.go: ReachBlockedSet (site ff9be67577e8) ------------------

func TestTier3Shapes_ff9be67577e8(t *testing.T) {
	expr := "((ST_GeometryType(rr.outer_bound) <> 'POINT' AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
		"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
		"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?))))) " +
		"OR (ST_GeometryType(rr.outer_bound) = 'POINT' AND EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))))"

	ormharness.AssertGoldenShapes(t, "ff9be67577e8", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			whereArgs := []interface{}{[]uint64{1, 2}, -0.1, 51.5, 4326.0, -0.1, 51.5, 4326.0, -0.1, 51.5, 4326.0, -0.1, 51.5, 4326.0}
			return find(tx.Table("rippling_reach rr").
				Select("rr.msgid").
				Where("rr.msgid IN (?) AND NOT "+expr, whereArgs...))
		}},
	})
}

// --- microvolunteering/microvolunteering.go: GetChallenge search-term ------

func TestTier3Shapes_80c36f2da91e(t *testing.T) {
	build := func(specificGroup bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "memberships.userid = ?"
			args := []interface{}{uint64(1)}
			if specificGroup {
				where += " AND memberships.groupid = ?"
				args = append(args, uint64(5))
			}
			where += " AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.wordmatch') = 1)"
			return find(tx.Table("memberships").
				Select("COUNT(*)").
				Joins("INNER JOIN `groups` ON memberships.groupid = `groups`.id").
				Where(where, args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "80c36f2da91e", []ormharness.Shape{
		{Name: "SpecificGroup", Build: build(true)},
		{Name: "AllGroups", Build: build(false)},
	})
}

// --- microvolunteering/microvolunteering.go: getPendingMessageChallenge ----

func TestTier3Shapes_309561e40e15(t *testing.T) {
	where := "messages_groups.groupid IN (?) AND DATE(messages.arrival) = CURDATE() AND fromuser != ? " +
		"AND microvolunteering = 1 AND messages.deleted IS NULL AND microactions.id IS NULL " +
		"AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.approvedmessages') = 1) " +
		"AND collection = ? AND autoreposts = 0"
	ormharness.AssertGoldenShapes(t, "309561e40e15", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			// Find, not First: First appends an implicit "ORDER BY
			// <primary key>" (finisher_api.go) after the explicit
			// Order() above, which this site's golden - copied from
			// the original raw SQL - does not have.
			return find(tx.Table("messages_groups").
				Select("messages_groups.msgid").
				Joins("INNER JOIN messages ON messages.id = messages_groups.msgid").
				Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid").
				Joins("LEFT JOIN microactions ON microactions.msgid = messages_groups.msgid AND microactions.userid = ?", uint64(1)).
				Where(where, []uint64{1, 2}, uint64(1), "Pending").
				Order("messages_groups.arrival ASC").Limit(1))
		}},
	})
}

// --- microvolunteering/microvolunteering.go: getApprovedMessageChallenge ---

func TestTier3Shapes_bde82a974f05(t *testing.T) {
	where := "messages_groups.groupid IN (?) AND DATE(messages.arrival) = CURDATE() AND fromuser != ? " +
		"AND microvolunteering = 1 AND messages_outcomes.id IS NULL AND messages.deleted IS NULL AND microactions.id IS NULL " +
		"AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.approvedmessages') = 1) " +
		"AND collection = ? AND autoreposts = 0"
	ormharness.AssertGoldenShapes(t, "bde82a974f05", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			// Find, not First: see comment in TestTier3Shapes_309561e40e15.
			return find(tx.Table("messages_spatial").
				Select("messages_spatial.msgid, "+
					"(SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid) AS reviewcount, "+
					"(SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid AND result = ?) AS approvalcount",
					"Approve").
				Joins("INNER JOIN messages_groups ON messages_spatial.msgid = messages_groups.msgid").
				Joins("INNER JOIN messages ON messages.id = messages_spatial.msgid").
				Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid").
				Joins("LEFT JOIN microactions ON microactions.msgid = messages_spatial.msgid AND microactions.userid = ?", uint64(1)).
				Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_spatial.msgid").
				Where(where, []uint64{1, 2}, uint64(1), "Approved").
				Having("approvalcount < ? AND reviewcount < ?", 2, 2).
				Order("messages_groups.arrival ASC").Limit(1))
		}},
	})
}

// --- microvolunteering/microvolunteering.go: getPhotoRotateChallenge -------

func TestTier3Shapes_ff5193d35cf8(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "ff5193d35cf8", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("messages_groups").
				Select("messages_attachments.id, "+
					"(SELECT COUNT(*) AS count FROM microactions WHERE rotatedimage = messages_attachments.id) AS reviewcount").
				Joins("INNER JOIN messages_attachments ON messages_attachments.msgid = messages_groups.msgid").
				Joins("LEFT JOIN microactions ON microactions.rotatedimage = messages_attachments.id AND userid = ?", uint64(1)).
				Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid AND microvolunteering = 1 AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.photorotate') = 1)").
				Where("arrival >= ? AND groupid IN (?) AND microactions.id IS NULL", "2026-01-01", []uint64{1, 2}).
				Having("reviewcount < ?", 2).
				Order("RAND()").Limit(9))
		}},
	})
}

// --- microvolunteering/microvolunteering.go: listMicroActions --------------

func TestTier3Shapes_3762cb36efcf(t *testing.T) {
	base := func(tx *gorm.DB, where string, args ...interface{}) *gorm.DB {
		return tx.Table("microactions").
			Select("DISTINCT microactions.*").
			Joins("INNER JOIN memberships ON memberships.userid = microactions.userid").
			Where(where, args...)
	}
	ormharness.AssertGoldenShapes(t, "3762cb36efcf", []ormharness.Shape{
		{Name: "NoContext", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, "memberships.groupid IN (?) AND microactions.timestamp >= ?", []uint64{1, 2}, "1970-01-01").
				Order("microactions.id DESC").Limit(10))
		}},
		{Name: "WithContext", Build: func(tx *gorm.DB) *gorm.DB {
			return find(base(tx, "memberships.groupid IN (?) AND microactions.timestamp >= ? AND microactions.id < ?",
				[]uint64{1, 2}, "1970-01-01", uint64(9)).
				Order("microactions.id DESC").Limit(10))
		}},
	})
}

// --- newsfeed/newsfeed.go: RecentNonAlertNewsfeedIDs (site d80ab5badcb6) ---

func TestTier3Shapes_d80ab5badcb6(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "d80ab5badcb6", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("newsfeed").
				Select("id").
				Where("id IN (?) AND type != ? AND `timestamp` >= ? AND userid != ?", []int64{1, 2}, "Alert", "2026-01-01", uint64(3)))
		}},
	})
}

// --- rippling/analytics.go: stratumSQL sites (6 sites, 4 shapes each) ------

var rippleStrata = []struct {
	name, sql string
}{
	{"All", ""},
	{"Rural", " AND rr.total_freeglers < 1700"},
	{"Suburban", " AND rr.total_freeglers >= 1700 AND rr.total_freeglers < 3800"},
	{"Dense", " AND rr.total_freeglers >= 3800"},
}

func TestTier3Shapes_f382f9bfe80b(t *testing.T) {
	shapes := make([]ormharness.Shape, len(rippleStrata))
	for i, st := range rippleStrata {
		st := st
		shapes[i] = ormharness.Shape{Name: st.name, Build: func(tx *gorm.DB) *gorm.DB {
			inner := "(SELECT rr.msgid " +
				"FROM rippling_reach rr " +
				"JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer' " +
				"WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0" + st.sql +
				" AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)" +
				" AND EXISTS(SELECT 1 FROM chat_messages c WHERE c.refmsgid = rr.msgid AND c.type = 'Interested')" +
				" ORDER BY RAND() LIMIT ?" +
				") samp"
			return find(tx.Table(inner, "2026-01-01", "2026-01-31", 250).
				Select("samp.msgid, ml.lat AS plat, ml.lng AS plng, ul.lat AS rlat, ul.lng AS rlng, "+
					"DATE_FORMAT(cm.date, '%Y-%m-%d') AS day, "+
					"EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = samp.msgid AND mb.userid = cm.userid) AS taker, "+
					"(NOT EXISTS(SELECT 1 FROM messages_groups og "+
					"INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = cm.userid "+
					"AND mem.collection = 'Approved' AND mem.added < og.arrival "+
					"WHERE og.msgid = samp.msgid AND og.rippled_in = 0 AND og.deleted = 0)) AS rippled").
				Joins("JOIN messages m ON m.id = samp.msgid").
				Joins("JOIN locations ml ON ml.id = m.locationid AND ml.lat IS NOT NULL").
				Joins("JOIN chat_messages cm ON cm.refmsgid = samp.msgid AND cm.type = 'Interested' AND cm.date >= ? AND cm.date < ?", "2026-01-01", "2026-01-31").
				Joins("JOIN users u ON u.id = cm.userid").
				Joins("JOIN locations ul ON ul.id = u.lastlocation AND ul.lat IS NOT NULL").
				Order("samp.msgid"))
		}}
	}
	ormharness.AssertGoldenShapes(t, "f382f9bfe80b", shapes)
}

func TestTier3Shapes_2def63211a50(t *testing.T) {
	shapes := make([]ormharness.Shape, len(rippleStrata))
	for i, st := range rippleStrata {
		st := st
		shapes[i] = ormharness.Shape{Name: st.name, Build: func(tx *gorm.DB) *gorm.DB {
			inner := "(SELECT rr.total_freeglers AS freeglers, " +
				"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested') AS nreplies, " +
				"EXISTS(SELECT 1 FROM chat_messages c2 WHERE c2.refmsgid = rr.msgid AND c2.type = 'Interested' " +
				"AND c2.date <= rr.created_at + INTERVAL 36 HOUR) AS replied_36h, " +
				"EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid) AS taken " +
				"FROM rippling_reach rr " +
				"JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer' " +
				"WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0" + st.sql +
				" AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)" +
				") d"
			return find(tx.Table(inner, "2026-01-01", "2026-01-31").
				Select("COUNT(*) AS posts, SUM(replied_36h) AS replied36h, SUM(nreplies > 0) AS replied_ever, " +
					"SUM(taken) AS taken, SUM(nreplies) AS total_replies, AVG(freeglers) AS mean_freeglers"))
		}}
	}
	ormharness.AssertGoldenShapes(t, "2def63211a50", shapes)
}

func TestTier3Shapes_62cea0b491c9(t *testing.T) {
	shapes := make([]ormharness.Shape, len(rippleStrata))
	for i, st := range rippleStrata {
		st := st
		shapes[i] = ormharness.Shape{Name: st.name, Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("rippling_held_replies hr").
				Select("COUNT(*)").
				Joins("JOIN rippling_reach rr ON rr.msgid = hr.msgid AND rr.total_freeglers > 0"+st.sql).
				Joins("JOIN messages m ON m.id = hr.msgid AND m.type = 'Offer'").
				Where("hr.created_at >= ? AND hr.created_at < ? AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = hr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)", "2026-01-01", "2026-01-31"))
		}}
	}
	ormharness.AssertGoldenShapes(t, "62cea0b491c9", shapes)
}

func TestTier3Shapes_1dee90c3c378(t *testing.T) {
	shapes := make([]ormharness.Shape, len(rippleStrata))
	for i, st := range rippleStrata {
		st := st
		shapes[i] = ormharness.Shape{Name: st.name, Build: func(tx *gorm.DB) *gorm.DB {
			inner := "(SELECT rr.created_at AS created, rr.total_freeglers AS freeglers, " +
				"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested' " +
				"AND cm.date <= rr.created_at + INTERVAL 36 HOUR) AS nreplies, " +
				"EXISTS(SELECT 1 FROM chat_messages c2 WHERE c2.refmsgid = rr.msgid AND c2.type = 'Interested' " +
				"AND c2.date <= rr.created_at + INTERVAL 36 HOUR) AS replied, " +
				"EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid " +
				"AND mb.timestamp <= rr.created_at + INTERVAL 14 DAY) AS taken " +
				"FROM rippling_reach rr " +
				"JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer' " +
				"WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0" + st.sql +
				" AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)" +
				") d"
			return find(tx.Table(inner, "2026-01-01", "2026-01-31").
				Select("DATE_FORMAT(created, '%Y-%m-%d') AS day, COUNT(*) AS posts, " +
					"100 * SUM(replied) / COUNT(*) AS replied_pct, 100 * SUM(taken) / COUNT(*) AS taken_pct, " +
					"SUM(nreplies) / COUNT(*) AS mean_replies, AVG(freeglers) AS mean_freeglers").
				Group("day").Order("day"))
		}}
	}
	ormharness.AssertGoldenShapes(t, "1dee90c3c378", shapes)
}

func TestTier3Shapes_791c683326a8(t *testing.T) {
	shapes := make([]ormharness.Shape, len(rippleStrata))
	for i, st := range rippleStrata {
		st := st
		shapes[i] = ormharness.Shape{Name: st.name, Build: func(tx *gorm.DB) *gorm.DB {
			inner := "(SELECT " +
				"(NOT EXISTS(SELECT 1 FROM messages_groups og " +
				"INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = cm.userid " +
				"AND mem.collection = 'Approved' AND mem.added < og.arrival " +
				"WHERE og.msgid = cm.refmsgid AND og.rippled_in = 0 AND og.deleted = 0)) AS rippled, " +
				"EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = cm.refmsgid AND mb.userid = cm.userid) AS is_taker, " +
				"EXISTS(SELECT 1 FROM rippling_reply_attribution rra " +
				"WHERE rra.msgid = cm.refmsgid AND rra.userid = cm.userid " +
				"AND rra.attribution IN ('ripple_notified','ripple_group','ripple_reach')) AS client_rippled " +
				"FROM chat_messages cm " +
				"JOIN rippling_reach rr ON rr.msgid = cm.refmsgid AND rr.total_freeglers > 0" + st.sql +
				" JOIN messages m ON m.id = cm.refmsgid AND m.type = 'Offer'" +
				" WHERE cm.type = 'Interested' AND cm.date >= ? AND cm.date < ?" +
				" AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = cm.refmsgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)" +
				") d"
			return find(tx.Table(inner, "2026-01-01", "2026-01-31").
				Select("COUNT(*) AS replies, SUM(rippled) AS rippled_replies, SUM(is_taker) AS takers, " +
					"SUM(rippled AND is_taker) AS rippled_takers, SUM(client_rippled) AS client_rippled"))
		}}
	}
	ormharness.AssertGoldenShapes(t, "791c683326a8", shapes)
}

func TestTier3Shapes_8cdbd33d1052(t *testing.T) {
	shapes := make([]ormharness.Shape, len(rippleStrata))
	for i, st := range rippleStrata {
		st := st
		shapes[i] = ormharness.Shape{Name: st.name, Build: func(tx *gorm.DB) *gorm.DB {
			inner := "(SELECT rr.msgid " +
				"FROM rippling_reach rr " +
				"JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer' " +
				"WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0" + st.sql +
				" AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)" +
				" AND EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid)" +
				" AND EXISTS(SELECT 1 FROM chat_messages c WHERE c.refmsgid = rr.msgid AND c.type = 'Interested')" +
				" AND NOT EXISTS(" +
				"SELECT 1 FROM chat_messages ch " +
				"INNER JOIN messages_groups og ON og.msgid = ch.refmsgid AND og.rippled_in = 0 AND og.deleted = 0 " +
				"INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = ch.userid " +
				"AND mem.collection = 'Approved' AND mem.added < og.arrival " +
				"WHERE ch.refmsgid = rr.msgid AND ch.type = 'Interested')" +
				") x"
			return find(tx.Table(inner, "2026-01-01", "2026-01-31").Select("COUNT(*)"))
		}}
	}
	ormharness.AssertGoldenShapes(t, "8cdbd33d1052", shapes)
}

// --- rippling/metrics.go: Metrics client_source_summary (10ee37c98574) -----

func TestTier3Shapes_10ee37c98574(t *testing.T) {
	srcGroupJoin := " JOIN messages_groups mg ON mg.msgid = rra.msgid AND mg.groupid = ? AND mg.rippled_in = 0 AND mg.deleted = 0"
	ormharness.AssertGoldenShapes(t, "10ee37c98574", []ormharness.Shape{
		{Name: "NoGroup", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("rippling_reply_attribution rra").
				Select("COALESCE(rra.client_source, '(not reported)') AS source, COUNT(*) AS count").
				Where("rra.replied_at >= ? AND rra.replied_at < ?", "2026-01-01", "2026-01-31").
				Group("source").Order("count DESC"))
		}},
		{Name: "WithGroup", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("rippling_reply_attribution rra"+srcGroupJoin, uint64(1)).
				Select("COALESCE(rra.client_source, '(not reported)') AS source, COUNT(*) AS count").
				Where("rra.replied_at >= ? AND rra.replied_at < ?", "2026-01-01", "2026-01-31").
				Group("source").Order("count DESC"))
		}},
	})
}

// --- rippling/metrics.go: Metrics reply_source_split (568a5645fba7) --------
//
// 4 shapes: wide (whether the graded-attribution column is read) crossed
// with whether ?groupid= scopes the window to one origin group. The
// attribution-channel CASE expression itself is not reproduced here - both
// this chain and ReplySourceSplitSQL call the shared
// rippling.ReplySourceInnerFrom, so what is being proven is that the OUTER
// aggregation (Select/Group/Order) reproduces the statement
// ReplySourceSplitSQL builds around whatever ReplySourceInnerFrom returns,
// not a hand-copied re-derivation of the CASE logic that could silently
// drift from it.
func TestTier3Shapes_568a5645fba7(t *testing.T) {
	srcGroupJoin := " JOIN messages_groups mg ON mg.msgid = rra.msgid AND mg.groupid = ? AND mg.rippled_in = 0 AND mg.deleted = 0"
	selectCols := "day, COUNT(*) AS replies, SUM(bucket = 'home') AS home, SUM(bucket = 'ripple_notified') AS ripple_notified, " +
		"SUM(bucket = 'ripple_group') AS ripple_group, SUM(bucket = 'ripple_reach') AS ripple_reach, " +
		"SUM(bucket = 'organic_local') AS organic_local, SUM(bucket = 'unknown') AS unknown"
	build := func(wide bool, srcGroup string, args ...interface{}) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table(rippling.ReplySourceInnerFrom(wide, srcGroup), args...).
				Select(selectCols).
				Group("day").Order("day DESC"))
		}
	}
	ormharness.AssertGoldenShapes(t, "568a5645fba7", []ormharness.Shape{
		{Name: "NarrowNoGroup", Build: build(false, "", "2026-01-01", "2026-02-01")},
		{Name: "WideNoGroup", Build: build(true, "", "2026-01-01", "2026-02-01")},
		{Name: "NarrowWithGroup", Build: build(false, srcGroupJoin, uint64(1), "2026-01-01", "2026-02-01")},
		{Name: "WideWithGroup", Build: build(true, srcGroupJoin, uint64(1), "2026-01-01", "2026-02-01")},
	})
}

// --- session/session.go: GetSession chatReviewSQL (site f43d5f680ef9) ------

func TestTier3Shapes_f43d5f680ef9(t *testing.T) {
	tail := "AND (" +
		"  (cr.chattype = ? AND cr.groupid IN ?) " +
		"  OR " +
		"  (cr.chattype = ? AND " +
		"    EXISTS (SELECT 1 FROM memberships m " +
		"      INNER JOIN `groups` g ON m.groupid = g.id AND g.type = ? " +
		"      WHERE m.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND m.groupid IN ?)) " +
		"  OR " +
		"  (cr.chattype = ? AND " +
		"    NOT EXISTS (SELECT 1 FROM memberships m " +
		"      INNER JOIN `groups` g ON m.groupid = g.id AND g.type = ? " +
		"      WHERE m.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)) " +
		"    AND EXISTS (SELECT 1 FROM memberships m " +
		"      INNER JOIN `groups` g ON m.groupid = g.id AND g.type = ? " +
		"      WHERE m.userid = cm.userid AND m.groupid IN ?))" +
		")"
	build := func(heldFilter string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? " + heldFilter + " " + tail
			args := []interface{}{"2026-01-01",
				"User2Mod", []uint64{1, 2},
				"User2User", "Freegle", []uint64{1, 2},
				"User2User", "Freegle", "Freegle", []uint64{1, 2}}
			return find(tx.Table("chat_messages cm").
				Select("COUNT(DISTINCT cm.id)").
				Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
				Joins("INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL").
				Joins("LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id").
				Where(where, args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "f43d5f680ef9", []ormharness.Shape{
		{Name: "NotHeld", Build: build("AND cmh.userid IS NULL")},
		{Name: "Held", Build: build("AND cmh.userid IS NOT NULL")},
	})
}

// --- session/session.go: GetSession wider chat review (2 sites) ------------

func sessionWiderBase(tx *gorm.DB, where string, args ...interface{}) *gorm.DB {
	return tx.Table("chat_messages cm").
		Select("COUNT(DISTINCT cm.id)").
		Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
		Joins("INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL").
		Joins("LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id").
		Joins("INNER JOIN memberships m ON m.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)").
		Joins("INNER JOIN `groups` g ON m.groupid = g.id AND g.type = 'Freegle'").
		Where(where, args...)
}

const sessionWiderWhere = "cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? AND cmh.id IS NULL " +
	"AND JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 AND (cm.reportreason IS NULL OR cm.reportreason != 'User')"

func TestTier3Shapes_3f3696f3bba4(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "3f3696f3bba4", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			recipientExpr := "(CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)"
			where := sessionWiderWhere + " AND NOT EXISTS (SELECT 1 FROM memberships m2 WHERE m2.userid = " + recipientExpr + " AND m2.groupid IN (?))"
			return find(sessionWiderBase(tx, where, "2026-01-01", []uint64{1, 2}))
		}},
	})
}

func TestTier3Shapes_76555fe088e5(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "76555fe088e5", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			return find(sessionWiderBase(tx, sessionWiderWhere, "2026-01-01"))
		}},
	})
}

// --- spammers/spammers.go: GetSpammers (site d64650fb9560) -----------------

func TestTier3Shapes_d64650fb9560(t *testing.T) {
	names := []struct {
		label                  string
		coll, ctx, uid, search bool
	}{
		{"NoColl_NoCtx_NoUid_NoSearch", false, false, false, false},
		{"NoColl_NoCtx_NoUid_Search", false, false, false, true},
		{"NoColl_NoCtx_Uid_NoSearch", false, false, true, false},
		{"NoColl_NoCtx_Uid_Search", false, false, true, true},
		{"NoColl_Ctx_NoUid_NoSearch", false, true, false, false},
		{"NoColl_Ctx_NoUid_Search", false, true, false, true},
		{"NoColl_Ctx_Uid_NoSearch", false, true, true, false},
		{"NoColl_Ctx_Uid_Search", false, true, true, true},
		{"Coll_NoCtx_NoUid_NoSearch", true, false, false, false},
		{"Coll_NoCtx_NoUid_Search", true, false, false, true},
		{"Coll_NoCtx_Uid_NoSearch", true, false, true, false},
		{"Coll_NoCtx_Uid_Search", true, false, true, true},
		{"Coll_Ctx_NoUid_NoSearch", true, true, false, false},
		{"Coll_Ctx_NoUid_Search", true, true, false, true},
		{"Coll_Ctx_Uid_NoSearch", true, true, true, false},
		{"Coll_Ctx_Uid_Search", true, true, true, true},
	}
	shapes := make([]ormharness.Shape, len(names))
	for i, n := range names {
		n := n
		shapes[i] = ormharness.Shape{Name: n.label, Build: func(tx *gorm.DB) *gorm.DB {
			t := tx.Table("spam_users").
				Select("DISTINCT spam_users.*").
				Joins("INNER JOIN users ON spam_users.userid = users.id")

			where := "1=1"
			var args []interface{}
			if n.coll {
				where += " AND spam_users.collection = ?"
				args = append(args, "Pending")
			}
			if n.ctx {
				where += " AND spam_users.id < ?"
				args = append(args, uint64(9))
			}
			if n.uid {
				where += " AND spam_users.userid = ?"
				args = append(args, uint64(3))
			}
			if n.search {
				t = t.Joins("LEFT JOIN users_emails ON users_emails.userid = spam_users.userid")
				where += " AND (users_emails.email LIKE ? OR users.fullname LIKE ?)"
				args = append(args, "%a%", "%a%")
			}
			return find(t.Where(where, args...).Order("spam_users.id DESC").Limit(10))
		}}
	}
	ormharness.AssertGoldenShapes(t, "d64650fb9560", shapes)
}

// --- story/story.go: List (site 0ca4810292dc) -------------------------------

func TestTier3Shapes_0ca4810292dc(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "0ca4810292dc", []ormharness.Shape{
		{Name: "Authority", Build: func(tx *gorm.DB) *gorm.DB {
			var dest []uint64
			where := "reviewed = ? AND public = ? AND users_stories.userid IS NOT NULL AND users.deleted IS NULL " +
				"AND locations.lat IS NOT NULL " +
				"AND ST_Contains((SELECT polygon FROM authorities WHERE id = ?), ST_SRID(POINT(locations.lng, locations.lat), ?))"
			return tx.Table("users_stories").
				Select("DISTINCT users_stories.id").
				Joins("INNER JOIN users ON users.id = users_stories.userid").
				Joins("LEFT JOIN locations ON locations.id = users.lastlocation").
				Where(where, "1", "1", uint64(1), 4326).
				Order("date DESC").Limit(100).Pluck("id", &dest)
		}},
		{Name: "Review_NoNewsletterFilter", Build: func(tx *gorm.DB) *gorm.DB {
			var dest []uint64
			where := "reviewed = ? AND users_stories.userid IS NOT NULL AND users.deleted IS NULL " +
				"AND users_stories.date > ? AND memberships.groupid IN (?) AND memberships.collection = ?"
			return tx.Table("users_stories").
				Select("DISTINCT users_stories.id").
				Joins("INNER JOIN users ON users.id = users_stories.userid").
				Joins("INNER JOIN memberships ON memberships.userid = users_stories.userid").
				Where(where, "0", "2026-01-01", []uint64{1, 2}, "Approved").
				Order("date DESC").Limit(100).Pluck("id", &dest)
		}},
		{Name: "Review_NewsletterFilter", Build: func(tx *gorm.DB) *gorm.DB {
			var dest []uint64
			where := "reviewed = ? AND users_stories.userid IS NOT NULL AND users.deleted IS NULL " +
				"AND users_stories.date > ? AND memberships.groupid IN (?) AND memberships.collection = ? " +
				"AND newsletterreviewed = ?"
			return tx.Table("users_stories").
				Select("DISTINCT users_stories.id").
				Joins("INNER JOIN users ON users.id = users_stories.userid").
				Joins("INNER JOIN memberships ON memberships.userid = users_stories.userid").
				Where(where, "0", "2026-01-01", []uint64{1, 2}, "Approved", "1").
				Order("date DESC").Limit(100).Pluck("id", &dest)
		}},
		{Name: "Plain_NoNewsletterFilter", Build: func(tx *gorm.DB) *gorm.DB {
			var dest []uint64
			return tx.Table("users_stories").
				Select("users_stories.id").
				Joins("INNER JOIN users ON users.id = users_stories.userid").
				Where("reviewed = ? AND public = ? AND userid IS NOT NULL AND users.deleted IS NULL", "1", "1").
				Order("date DESC").Limit(100).Pluck("id", &dest)
		}},
		{Name: "Plain_NewsletterFilter", Build: func(tx *gorm.DB) *gorm.DB {
			var dest []uint64
			where := "reviewed = ? AND public = ? AND userid IS NOT NULL AND users.deleted IS NULL AND newsletterreviewed = ?"
			return tx.Table("users_stories").
				Select("users_stories.id").
				Joins("INNER JOIN users ON users.id = users_stories.userid").
				Where(where, "1", "1", "1").
				Order("date DESC").Limit(100).Pluck("id", &dest)
		}},
	})
}

// --- user/authMiddleware.go: NewAuthMiddleware (2 sites) -------------------

func TestTier3Shapes_4853849663f1(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "4853849663f1", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			// Find, not First: see comment in TestTier3Shapes_309561e40e15.
			return find(tx.Table("sessions").
				Select("users.id, users.lastaccess, users.systemrole").
				Joins("INNER JOIN users ON users.id = sessions.userid").
				Where("sessions.id = ? AND users.id = ?", "abc", uint64(1)).
				Limit(1))
		}},
	})
}

func TestTier3Shapes_e04bf70e7bee(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "e04bf70e7bee", []ormharness.Shape{
		{Name: "Fixed", Build: func(tx *gorm.DB) *gorm.DB {
			// Find, not First: see comment in TestTier3Shapes_309561e40e15.
			return find(tx.Table("sessions").
				Select("users.id, users.lastaccess, users.systemrole").
				Joins("INNER JOIN users ON users.id = sessions.userid").
				Where("sessions.id = ? AND users.id = ?", "abc", uint64(1)).
				Limit(1))
		}},
	})
}

// --- user/user.go: GetUserById (site e0558c2c039d) --------------------------

func TestTier3Shapes_e0558c2c039d(t *testing.T) {
	base := "users.id, firstname, lastname, fullname, lastaccess, users.added, systemrole, relevantallowed, newslettersallowed, marketingconsent, trustlevel, bouncing, deleted, forgotten, source, engagement, " +
		"chatmodstatus, newsfeedmodstatus, tnuserid, ljuserid, "
	tail := "CASE WHEN systemrole IN (?, ?, ?) AND JSON_EXTRACT(users.settings, '$.showmod') IS NULL THEN 1 ELSE JSON_EXTRACT(users.settings, '$.showmod') END AS showmod"

	ormharness.AssertGoldenShapes(t, "e0558c2c039d", []ormharness.Shape{
		{Name: "NoSettings", Build: func(tx *gorm.DB) *gorm.DB {
			// Find, not First: matches production (user/user.go).
			// First() on a Table()-only query with no registered Model
			// fails outright trying to resolve its implicit "ORDER BY
			// <primary key>" (Schema is nil - "model value required",
			// statement.go), not just a golden-text mismatch (this
			// golden also has neither ORDER BY nor LIMIT). See
			// group/group.go's GetGroup (site 2811b4d3acf7) for the
			// established fix this mirrors.
			return find(tx.Table("users").
				Select(base+tail, "Moderator", "Support", "Admin").
				Where("users.id = ?", uint64(1)))
		}},
		{Name: "WithSettings", Build: func(tx *gorm.DB) *gorm.DB {
			return find(tx.Table("users").
				Select(base+"settings, "+tail, "Moderator", "Support", "Admin").
				Where("users.id = ?", uint64(1)))
		}},
	})
}

// --- user/user.go: GetUserReplies (site 395499023142) -----------------------

func TestTier3Shapes_395499023142(t *testing.T) {
	// Both forms build a single WHERE string (not a second chained
	// .Where() call) so GORM doesn't wrap the base condition in an
	// extra pair of parentheses when a second Where is ANDed on.
	build := func(msgtype string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "cm.userid = ? AND cm.date > ? AND cm.refmsgid IS NOT NULL AND cm.type = ?"
			args := []interface{}{uint64(1), "2026-01-01", "Interested"}
			if msgtype != "" {
				where += " AND m.type = ?"
				args = append(args, msgtype)
			}
			return find(tx.Table("chat_messages cm").
				Select("DISTINCT m.id, m.subject, m.type, mg.arrival, mo.outcome").
				Joins("INNER JOIN messages m ON m.id = cm.refmsgid").
				Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id").
				Joins("LEFT JOIN messages_outcomes mo ON mo.msgid = m.id").
				Where(where, args...).
				Order("mg.arrival DESC").Limit(100))
		}
	}
	ormharness.AssertGoldenShapes(t, "395499023142", []ormharness.Shape{
		{Name: "NoType", Build: build("")},
		{Name: "WithType", Build: build("Offer")},
	})
}

// --- donations/donations.go: GetDonations (site 31fea9e6f321) --------------

func TestTier3Shapes_31fea9e6f321(t *testing.T) {
	build := func(withGroup bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "timestamp >= DATE_FORMAT(NOW(), '%Y-%m-01') AND Payer != ? AND Payer != ?"
			args := []interface{}{"ppgfukpay@paypalgivingfund.org", "paypal.msb@tipalti.com"}
			t := tx.Table("users_donations").Select("COALESCE(SUM(GrossAmount), 0) AS raised")
			if withGroup {
				t = t.Joins("INNER JOIN memberships ON users_donations.userid = memberships.userid AND memberships.groupid = ?", "5")
			}
			// Find, not Scan: Scan is rejected under dry-run
			// (ormharness/golden.go); production keeps Scan for its
			// single-aggregate-value destination.
			return find(t.Where(where, args...))
		}
	}
	ormharness.AssertGoldenShapes(t, "31fea9e6f321", []ormharness.Shape{
		{Name: "NoGroup", Build: build(false)},
		{Name: "WithGroup", Build: build(true)},
	})
}

// --- message/search.go: SearchByMsgID (site a5e382bd3536) -------------------

func TestTier3Shapes_a5e382bd3536(t *testing.T) {
	build := func(withGroups bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			where := "messages_spatial.msgid = ?"
			args := []interface{}{uint64(1)}
			if withGroups {
				where += " AND EXISTS (SELECT 1 FROM messages_groups mg WHERE mg.msgid = messages_spatial.msgid AND mg.groupid IN (?) AND mg.collection = 'Approved' AND mg.deleted = 0)"
				args = append(args, []uint64{5, 7})
			}
			return find(tx.Table("messages_spatial").
				Select("messages_spatial.msgid, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype AS type, ST_Y(point) AS lat, ST_X(point) AS lng").
				Where(where, args...).
				Limit(1))
		}
	}
	ormharness.AssertGoldenShapes(t, "a5e382bd3536", []ormharness.Shape{
		{Name: "NoGroups", Build: build(false)},
		{Name: "WithGroups", Build: build(true)},
	})
}
