package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the message module's core file:
// iznik-server-go/message/message.go. This is the largest single file in the
// migration (~95 raw-SQL sites). The module's smaller helper files
// (bulkItem.go, helper.go, bulkEdit.go, message_list.go, reach.go, sitemap.go)
// are a separate batch - see orm_wave1_message_helpers_test.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Nine sites from this file's wave-1 inventory are deliberately NOT converted
// and have no test here, all for the same reason: each binds a Go []uint64
// slice to a literal "IN ?" or "IN (?)" placeholder (one binds "NOT IN (?)").
// GORM's dry-run substitutes the slice element-by-element into that single
// "?", so the rendered SQL carries as many placeholders as the slice has
// elements, whereas the golden text records a single "?" - a real,
// length-dependent divergence the harness has no approvedDiff entry for yet.
// Same shape already skipped in the session, dashboard, group, membership,
// user and message-helpers modules; see those modules' wave 1 test files.
// Left as raw SQL:
//
//   - 340a0eccf392 (computeExpiresat): "id IN (?)" on `groups`, bound to the
//     message's group IDs.
//   - 99480793d36b (applyExpiry): same shape, different call site.
//   - 407bd1e3018a (applyExpiry): "refmsgid IN (?) GROUP BY refmsgid" on
//     chat_messages, bound to candidate message IDs.
//   - 069e96c5c43e (Search's applyBrowseFilters "Newest" sort): "id IN (?)"
//     on messages, bound to the current page of search result IDs.
//   - d17e1becbe03 (handleApprove): "groupid IN ?" (no parens) on
//     messages_groups, bound to the caller's authorised groups.
//   - 6c69b307a927 (handleReject): same shape, different call site.
//   - 048db12b9b08 (applyPatchMessageCore): "id IN (?) AND msgid = ?" on
//     messages_attachments, bound to the caller's kept-attachment IDs.
//   - ef712c65234c (heldByAnotherMod): "groupid IN ?" (no parens) on
//     messages_groups, bound to the caller's authorised groups.
//   - 1b80281ee67a (recordAIDeletions): "msgid = ? AND id NOT IN (?)" on
//     messages_attachments, bound to the caller's keep-list.
//
// message.go is the write path for posts, moderation actions and outcomes -
// WHERE-clause text, column lists and JOIN targets are checked verbatim by
// Layer 1 (this file), since a subtly different predicate here would change
// what a moderator or member can see or act on, not just cosmetics.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

// --- GetMessagesByIds: per-message parallel fetches -------------------------

func TestWave1MessageCore_00c95f356218(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "00c95f356218", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Select("groupid, msgid, arrival, collection, autoreposts, approvedby, heldby, spamtype, spamreason, contentcheck_checked_at, contentcheck_reasons, rippled_in").
			Where("msgid = ? AND deleted = 0", 1).Find(&dest)
	})
}

func TestWave1MessageCore_c8e73a6d5388(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c8e73a6d5388", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_proximity").Select("groupid, p, q").Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_2891d1ddef74(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2891d1ddef74", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("DISTINCT(chatid)").Where("refmsgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_06692bc664d7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "06692bc664d7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_edits").
			Select("id, oldsubject, newsubject, oldtext, newtext, reviewrequired, `timestamp` AS `timestamp`").
			Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", 1).
			Order("id DESC").Find(&dest)
	})
}

func TestWave1MessageCore_63845b4e7940(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "63845b4e7940", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid").
			Where("userid = ? AND role IN (?, ?) AND collection = ?", 1, "Moderator", "Owner", "Approved").
			Find(&dest)
	})
}

// --- GetMessagesByIds: worry-word mod-count check, checkWorryWords ---------

func TestWave1MessageCore_34290b40a9d1(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "34290b40a9d1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?) AND collection = ?", 1, "Moderator", "Owner", "Approved").Limit(1).Count(&dest)
	})
}

func TestWave1MessageCore_a7c513f07242(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a7c513f07242", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("concern_keywords").
			Select("id, keyword, CASE category " +
				"WHEN 'substance_regulated' THEN 'Regulated' " +
				"WHEN 'substance_reportable' THEN 'Reportable' " +
				"WHEN 'substance_medicine' THEN 'Medicine' " +
				"WHEN 'review' THEN 'Review' " +
				"WHEN 'allowed' THEN 'Allowed' " +
				"ELSE 'Review' END AS type").
			Where("match_mode = 'fuzzy' AND scope = 'global'").
			Find(&dest)
	})
}

func TestWave1MessageCore_38e4378c556a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "38e4378c556a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.spammers.worrywords'))").Where("id = ?", 1).Find(&dest)
	})
}

// --- GetMessagesForUser (My Posts) / applyExpiry ----------------------------

func TestWave1MessageCore_ea8ac823591e(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "ea8ac823591e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Where("userid = ?", 1).Count(&dest)
	})
}

// --- Search: own-group scope, browse-scoped distance/sort ------------------

func TestWave1MessageCore_5b2671cef1e2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5b2671cef1e2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid").Where("userid = ? AND collection = ?", 1, "Approved").Find(&dest)
	})
}

func TestWave1MessageCore_2d6fc9322004(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2d6fc9322004", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").
			Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseMaxDistance')), ''), "+
				"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseSort')), '')").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- message_mod.go merge: log helpers, group/location resolution ----------

func TestWave1MessageCore_d2c3cf7730e5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d2c3cf7730e5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("COALESCE(messageid, '')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_7f4bcd4462a9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7f4bcd4462a9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").Where("msgid = ?", 1).Limit(1).Find(&dest)
	})
}

func TestWave1MessageCore_6dc0e76ccbe6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6dc0e76ccbe6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_e92aea693cb8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e92aea693cb8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("name").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_e193cd51dd32(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e193cd51dd32", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("JSON_UNQUOTE(JSON_EXTRACT(settings, ?))", "$.keywords.OFFER").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_ef1680989d03(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ef1680989d03", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser, subject").Where("id = ?", 1).Find(&dest)
	})
}

// --- handleApprove -----------------------------------------------------------

func TestWave1MessageCore_5fd102e62bbb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5fd102e62bbb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("spamtype").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_e7b972789539(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e7b972789539", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("type").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_a62a5627e340(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "a62a5627e340", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Where("msgid = ?", 1).Count(&dest)
	})
}

// --- handleReject / handleDeleteMessage / handleSpam: remaining-groups count

func TestWave1MessageCore_8691c5d048fd(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "8691c5d048fd", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(dbresolver.Write).Table("messages_groups").Where("msgid = ? AND deleted = 0", 1).Count(&dest)
	})
}

func TestWave1MessageCore_0f6519bac21b(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "0f6519bac21b", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(dbresolver.Write).Table("messages_groups").Where("msgid = ? AND deleted = 0", 1).Count(&dest)
	})
}

func TestWave1MessageCore_cf6f9c8db1e0(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "cf6f9c8db1e0", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(dbresolver.Write).Table("messages_groups").Where("msgid = ? AND deleted = 0", 1).Count(&dest)
	})
}

// --- handleApproveEdits / handleRevertEdits ---------------------------------

func TestWave1MessageCore_979752536169(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "979752536169", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_edits").Select("id, newsubject, newtext").
			Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", 1).
			Order("id DESC").Limit(1).Find(&dest)
	})
}

func TestWave1MessageCore_d334352c7913(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d334352c7913", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_edits").Select("oldsubject, oldtext").
			Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", 1).
			Order("id DESC").Limit(1).Find(&dest)
	})
}

// --- handlePartnerConsent ----------------------------------------------------

func TestWave1MessageCore_39e58fbf81cf(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "39e58fbf81cf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("id").Where("partner = ?", "x").Find(&dest)
	})
}

// --- handleRejectToDraft / handleJoinAndPost / JoinAndPostAs ----------------

func TestWave1MessageCore_a059f6dfd643(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a059f6dfd643", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_f18aee3ea90f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f18aee3ea90f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_13f4d3014fcc(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "13f4d3014fcc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ?", 1).Count(&dest)
	})
}

func TestWave1MessageCore_723e51eac6fa(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "723e51eac6fa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("deadline").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_d6c3c8c5d969(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d6c3c8c5d969", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("type").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_827d50d57ce1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "827d50d57ce1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_ba6d71c24a84(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ba6d71c24a84", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser, type").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_e65762538bd7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e65762538bd7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_drafts").Select("groupid").Where("msgid = ?", 1).Limit(1).Find(&dest)
	})
}

func TestWave1MessageCore_a091fd2b5c70(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "a091fd2b5c70", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Where("userid = ? AND groupid = ?", 1, 2).Count(&dest)
	})
}

func TestWave1MessageCore_3dc4a29c5a25(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3dc4a29c5a25", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("ourPostingStatus").Where("userid = ? AND groupid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageCore_b9a754e772dc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b9a754e772dc", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(dbresolver.Write).Table("messages").Select("COALESCE(subject, '')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_526cc19cb280(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "526cc19cb280", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(dbresolver.Write).Table("messages").Select("COALESCE(subject, '')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_2130e21f0b14(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2130e21f0b14", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_ebecd2af79d5(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "ebecd2af79d5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Where("userid = ? AND type = ?", 1, "Native").Count(&dest)
	})
}

// --- applyPatchMessageCore ---------------------------------------------------

func TestWave1MessageCore_e3301eb339aa(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e3301eb339aa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_08ad9caedb06(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "08ad9caedb06", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("subject, COALESCE(textbody, '') as textbody, COALESCE(type, '') as type, locationid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_25afac519443(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "25afac519443", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_items").Select("itemid AS id").Where("msgid = ?", 1).Order("itemid").Find(&dest)
	})
}

func TestWave1MessageCore_2eaf557d8474(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2eaf557d8474", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Select("id").Where("msgid = ?", 1).Order("id").Find(&dest)
	})
}

func TestWave1MessageCore_ac4303c03968(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ac4303c03968", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("id").Where("name = ?", "x").Limit(1).Find(&dest)
	})
}

func TestWave1MessageCore_5b7a006dd0a5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5b7a006dd0a5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("lat, lng").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_b0e8a24902a4(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "b0e8a24902a4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Count(&dest)
	})
}

func TestWave1MessageCore_69c531931cef(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "69c531931cef", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("items").Select("id").Where("name = ?", "x").Find(&dest)
	})
}

func TestWave1MessageCore_fbd1e554cd4f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fbd1e554cd4f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("type").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_4ec2a62331da(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4ec2a62331da", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("subject, COALESCE(textbody, '') as textbody, COALESCE(type, '') as type, locationid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_cc1f21bf05ba(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "cc1f21bf05ba", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_items").Select("itemid AS id").Where("msgid = ?", 1).Order("itemid").Find(&dest)
	})
}

func TestWave1MessageCore_bfcd6ddc13f4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "bfcd6ddc13f4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Select("id").Where("msgid = ?", 1).Order("id").Find(&dest)
	})
}

// --- DeleteMessageEndpoint / PatchMessageByTN / PostMessage (tnpostid) ------

func TestWave1MessageCore_83ea416c84f8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "83ea416c84f8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("collection").Where("msgid = ? AND groupid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageCore_7841a4655468(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7841a4655468", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("COALESCE(JSON_EXTRACT(settings, '$.moderated'), 0), COALESCE(JSON_EXTRACT(settings, '$.closed'), 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_8e27b9da4155(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8e27b9da4155", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("ourPostingStatus").Where("userid = ? AND groupid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageCore_c194980d6996(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c194980d6996", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("id").Where("tnpostid = ?", "x").Find(&dest)
	})
}

func TestWave1MessageCore_2af4c6ba26f8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2af4c6ba26f8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("id").Where("tnpostid = ?", "x").Find(&dest)
	})
}

// --- findOrCreateUserForDraft / ResolveOnBehalfPosting / PutMessageAs ------

func TestWave1MessageCore_ae4f64b8a15d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ae4f64b8a15d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_e572ba63d232(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e572ba63d232", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ?", "x").Limit(1).Find(&dest)
	})
}

func TestWave1MessageCore_16996cb70b7a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "16996cb70b7a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").
			Select("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.id')) AS locationid, "+
				"JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.name')) AS locationname, "+
				"JSON_EXTRACT(settings, '$.mylocation.lat') AS lat, "+
				"JSON_EXTRACT(settings, '$.mylocation.lng') AS lng").
			Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_fd38d5ea2fee(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fd38d5ea2fee", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("COALESCE(NULLIF(namefull, ''), nameshort)").Where("id = ?", 1).Find(&dest)
	})
}

// --- PutMessageAs -------------------------------------------------------------

func TestWave1MessageCore_99ab4931eb71(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "99ab4931eb71", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).Count(&dest)
	})
}

func TestWave1MessageCore_f27f6eb4514a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f27f6eb4514a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("ourPostingStatus").Where("userid = ? AND groupid = ?", 1, 2).Limit(1).Find(&dest)
	})
}

// --- heldByAnotherMod ---------------------------------------------------------

func TestWave1MessageCore_6f82b3e99673(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6f82b3e99673", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").Where("id = ?", 1).Find(&dest)
	})
}

// --- handlePromise / handleRenege / handleOutcome / canModifyMessage -------

func TestWave1MessageCore_e5ceef3a6f57(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e5ceef3a6f57", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_4586a4a811cc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4586a4a811cc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_4cf3495665db(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4cf3495665db", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("type").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_5d37e8172d6a(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "5d37e8172d6a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND collection = ?", 1, "Pending").Count(&dest)
	})
}

func TestWave1MessageCore_53149c259199(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "53149c259199", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").Where("msgid = ? AND collection = ? AND deleted = 0", 1, "Pending").Find(&dest)
	})
}

func TestWave1MessageCore_c12962753bc4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c12962753bc4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_d1bc5f852d18(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "d1bc5f852d18", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Where("msgid = ?", 1).Count(&dest)
	})
}

func TestWave1MessageCore_9e6d82bfbfce(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "9e6d82bfbfce", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").
			Where("msgid = ? AND (outcome = ? OR (outcome = ? AND comments = 'Auto-expired'))", 1, "Expired", "Withdrawn").
			Count(&dest)
	})
}

func TestWave1MessageCore_281d2eb9b9ea(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "281d2eb9b9ea", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("availablenow").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_8e7c1be72a0b(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "8e7c1be72a0b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND collection = ? AND deleted = 0", 1, "Approved").Count(&dest)
	})
}

func TestWave1MessageCore_5f946a956d5e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5f946a956d5e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").Where("msgid = ? AND collection = ? AND deleted = 0", 1, "Pending").Find(&dest)
	})
}

func TestWave1MessageCore_741a4ad08f86(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "741a4ad08f86", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_16a09cc57bec(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "16a09cc57bec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

// --- handleAddBy / handleRemoveBy / handleView / createSystemChatMessage ---

func TestWave1MessageCore_c3fffce8330c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c3fffce8330c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Select("id, count").Where("msgid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageCore_58739d4576aa(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "58739d4576aa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Select("id, count").Where("msgid = ? AND userid IS NULL", 1).Find(&dest)
	})
}

func TestWave1MessageCore_abfe0ce94a1f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "abfe0ce94a1f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Select("id, count").Where("msgid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageCore_90561e7ff3e7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "90561e7ff3e7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Select("id, count").Where("msgid = ? AND userid IS NULL", 1).Find(&dest)
	})
}

func TestWave1MessageCore_42938303502c(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "42938303502c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_likes").Where("msgid = ? AND userid = ? AND type = 'View' AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)", 1, 2).Count(&dest)
	})
}

func TestWave1MessageCore_0c301f910767(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0c301f910767", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", 1, 2, 2, 1).Limit(1).Find(&dest)
	})
}

// --- recordAIDeletions --------------------------------------------------------

func TestWave1MessageCore_87fd51e20996(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "87fd51e20996", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Select("id, COALESCE(externaluid, '') AS externaluid, externalmods").Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageCore_517c14158408(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "517c14158408", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Select("id").Where("externaluid = ?", "x").Limit(1).Find(&dest)
	})
}
