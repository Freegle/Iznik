package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the user module's batch:
// iznik-server-go/user/{auth,partner,relevantoff,systemrole,userComment,
// userEmails,userInfo,user}.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Seven sites from this module's wave-1 inventory are deliberately NOT
// converted and have no test here:
//
//   - 13e645ea19f0 (auth.go), 37c205978f1f (userEmails.go) and
//     34292c12c516 (userInfo.go): the recorded goldenSql for each ends in a
//     literal trailing semicolon, carried over verbatim from the original
//     hand-written query string. GORM's clause builder never emits a
//     trailing semicolon, so no achievable .Table()/.Where() chain
//     canonicalizes equal to these goldens without a reviewer-approved
//     diff (manifest.Site.ApprovedDiff) or an extractor fix that trims
//     trailing semicolons before recording goldenSql - both out of this
//     batch's scope (tools/orm-migration is parent-owned). Flagged to the
//     parent; thirty sites across the whole manifest share this shape.
//   - bccf664d6580, 6155d59a26ec (userComment.go), 889877ad8183 and
//     a25ca71cf6cc (user.go): all bind a Go []uint64 slice to a literal
//     "IN ?" (or "IN (?)") placeholder. GORM's dry-run substitutes the
//     slice element-by-element into that placeholder, so the rendered SQL
//     carries as many "?" as the slice has elements, whereas the golden
//     records a single placeholder - a real, length-dependent divergence
//     the harness has no approvedDiff entry for yet. Same shape already
//     skipped in the session, dashboard, group and membership modules; see
//     those modules' wave 1 test files. Left as raw SQL.
//
// User code handles accounts, emails and permissions. WHERE-clause text and
// column lists are checked verbatim by Layer 1 (this file) - a subtly
// different predicate here would be a security-relevant bug, not a
// cosmetic one.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- auth.go: LoveJunk partner lookups --------------------------------------

func TestWave1User_aaaa357f296a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "aaaa357f296a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("partner").Where("`key`= ?", 1).Find(&dest)
	})
}

func TestWave1User_90690330271c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "90690330271c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("ljuserid = ?", 1).Find(&dest)
	})
}

// --- partner.go: partner-key validation, TN/email matching -----------------

func TestWave1User_c3ce5cbe967b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c3ce5cbe967b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("id, partner, `domain`").Where("`key` = ?", 1).Find(&dest)
	})
}

func TestWave1User_20d8eda3a578(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "20d8eda3a578", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id").Where("tnuserid = ?", 1).Find(&dest)
	})
}

func TestWave1User_d8f691613a70(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d8f691613a70", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ?", "a@b.c").Find(&dest)
	})
}

func TestWave1User_63574fcf7b8a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "63574fcf7b8a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser, fromaddr").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_45d0fd83ed8a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "45d0fd83ed8a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_keys").Select("id").Where("partner LIKE ?", "%x%").Limit(1).Find(&dest)
	})
}

// --- relevantoff.go: one-click matched-posts unsubscribe, key check --------

func TestWave1User_74e53dad60bf(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "74e53dad60bf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?", 1, "Link").Limit(1).Find(&dest)
	})
}

// --- systemrole.go: SyncSystemRole (User<->Moderator reconciliation) -------

func TestWave1User_132e1b9b2d4a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "132e1b9b2d4a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("systemrole").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_21d2f5cc09d7(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "21d2f5cc09d7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Count(&dest)
	})
}

// --- userEmails.go: internal-email generation, display-name lookup ---------

func TestWave1User_b4656108f05f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b4656108f05f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ? AND email LIKE ?", 1, "%-1@users.ilovefreegle.org").
			Order("preferred DESC").Limit(1).Find(&dest)
	})
}

func TestWave1User_3698e5590b2a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3698e5590b2a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", 1).Find(&dest)
	})
}

// --- userInfo.go: reneged/replytime/ratings/area-name lookups --------------

func TestWave1User_9088f1449d32(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9088f1449d32", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_reneged").Select("COUNT(DISTINCT(messages_reneged.msgid)) AS reneged").
			Where("userid = ? AND timestamp > ?", 1, "2026-01-01").Find(&dest)
	})
}

func TestWave1User_7c2a4a892c17(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7c2a4a892c17", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_replytime").Select("replytime").Where("userid = ?", 1).Find(&dest)
	})
}

func TestWave1User_3c5d23f03c54(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3c5d23f03c54", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ratings").Select("rating").Where("rater = ? AND ratee = ? AND timestamp >= ?", 1, 2, "2026-01-01").Find(&dest)
	})
}

func TestWave1User_f66d831e859e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f66d831e859e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.area'), '$.name'))").
			Where("id = ? AND settings IS NOT NULL", 1).Find(&dest)
	})
}

// --- user.go: InventName / GetActiveModGroupIDs -----------------------------

func TestWave1User_55990571c6db(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "55990571c6db", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", 1).Order("preferred DESC, id ASC").Limit(1).Find(&dest)
	})
}

func TestWave1User_4a82fa03a4f8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4a82fa03a4f8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Select("groupid").
			Where("userid = ? AND role IN (?, ?) AND collection = ? AND (settings IS NULL OR JSON_EXTRACT(settings, '$.active') IS NULL OR JSON_EXTRACT(settings, '$.active') != 0)",
				1, "Moderator", "Owner", "Approved").
			Find(&dest)
	})
}

// --- user.go: GetUser display-name/spam/aboutme/donations/giftaid/login ----

func TestWave1User_4302277d901e(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "4302277d901e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?)", 1, "Owner", "Moderator").Count(&dest)
	})
}

func TestWave1User_abc4c428b605(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "abc4c428b605", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_aboutme").Where("userid = ?", 1).Order("timestamp DESC").Limit(1).Find(&dest)
	})
}

func TestWave1User_52fc732564c3(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "52fc732564c3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Select("id, userid, byuserid, collection, reason, added").
			Where("userid = ?", 1).Order("id ASC").Limit(1).Find(&dest)
	})
}

func TestWave1User_495e8285da0a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "495e8285da0a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_searches").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_c6a69a9f0597(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c6a69a9f0597", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_1bd00b213b09(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1bd00b213b09", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_push_notifications").Select("MAX(lastsent)").Where("userid = ?", 1).Find(&dest)
	})
}

func TestWave1User_9c488a43fc54(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9c488a43fc54", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Select("COUNT(DISTINCT text)").
			Where("user = ? AND type = ? AND subtype = ? AND timestamp >= NOW() - INTERVAL 90 DAY", 1, "User", "PostcodeChange").
			Find(&dest)
	})
}

func TestWave1User_192b411b543b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "192b411b543b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.name'))").
			Where("id = ? AND settings IS NOT NULL", 1).Find(&dest)
	})
}

func TestWave1User_8c4b9a825e10(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8c4b9a825e10", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("name").Where("id = ?", 1).Find(&dest)
	})
}

// 753270f0ca22 binds four values inside a computed ORDER BY expression,
// which the plain Order(string) chainable cannot carry bind vars for
// (chainable_api.go's Order only accepts clause.OrderBy/OrderByColumn/string,
// and a bare string is spliced in raw with no Vars attached). Routed through
// clause.OrderBy{Expression: gorm.Expr(...)} instead, which is the same
// clause.Expr machinery Where() and Raw() use, so its "?" placeholders are
// bound and resolved back into the render exactly like every other bind.
func TestWave1User_753270f0ca22(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "753270f0ca22", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("name").
			Where("type = ? AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?", "Postcode", 1.0, 2.0, 3.0, 4.0).
			Order(clause.OrderBy{Expression: gorm.Expr("((lat - ?)*(lat - ?) + (lng - ?)*(lng - ?)) ASC", 1.0, 1.0, 2.0, 2.0)}).
			Limit(1).
			Find(&dest)
	})
}

func TestWave1User_76ca6fde32ec(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "76ca6fde32ec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_donations").Select("id, userid, timestamp, GrossAmount, source, TransactionType, giftaidconsent").
			Where("userid = ?", 1).Order("timestamp DESC").Find(&dest)
	})
}

func TestWave1User_756ee9a859a6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "756ee9a859a6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Select("id, userid, timestamp, period").
			Where("userid = ? AND deleted IS NULL", 1).Limit(1).Find(&dest)
	})
}

func TestWave1User_252dfa1bc658(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "252dfa1bc658", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("credentials").Where("userid = ? AND type = 'Link'", 1).Limit(1).Find(&dest)
	})
}

// --- user.go: AddMembership ban check ---------------------------------------

func TestWave1User_93d100d45d54(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "93d100d45d54", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Select("userid").Where("userid = ? AND groupid = ?", 1, 2).Find(&dest)
	})
}

// --- user.go: handleEngaged / handleAddEmail / handleRemoveEmail / PutUser -

func TestWave1User_e3fbf45b1fee(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e3fbf45b1fee", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("engage").Select("mailid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_9fcec938f21c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9fcec938f21c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("id, userid").Where("email = ?", "a@b.c").Limit(1).Find(&dest)
	})
}

func TestWave1User_d0b3ffa72211(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d0b3ffa72211", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ? AND userid = ?", "a@b.c", 1).Find(&dest)
	})
}

func TestWave1User_c4e9446f37d9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c4e9446f37d9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ?", "a@b.c").Limit(1).Find(&dest)
	})
}

// --- user.go: CheckLocationChangeVelocity / ProcessSettingsUpdate ----------

func TestWave1User_7bd383b9cd2d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7bd383b9cd2d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Select("COUNT(DISTINCT text)").
			Where("user = ? AND type = ? AND subtype = ? AND timestamp >= NOW() - INTERVAL 24 HOUR", 1, "User", "PostcodeChange").
			Find(&dest)
	})
}

func TestWave1User_5262aa3b4dc8(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "5262aa3b4dc8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Count(&dest)
	})
}

func TestWave1User_bf72f4509ca5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "bf72f4509ca5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("lastlocation").Where("id = ?", 1).Find(&dest)
	})
}

// --- user.go: PatchUser trustlevel / LimboUser ------------------------------

func TestWave1User_8c8162284405(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8c8162284405", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("systemrole").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_120f5981fbeb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "120f5981fbeb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Limit(1).Find(&dest)
	})
}

func TestWave1User_571cbd577434(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "571cbd577434", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Limit(1).Find(&dest)
	})
}

func TestWave1User_a17cb4d40d9c(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "a17cb4d40d9c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("userid = ? AND collection IN (?, ?)", 1, "Spammer", "PendingAdd").Count(&dest)
	})
}

// --- user.go: LogGroupLeftForApprovedMemberships ----------------------------

func TestWave1User_3fe8736f1d10(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3fe8736f1d10", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid").Where("userid = ? AND collection = ?", 1, "Approved").Find(&dest)
	})
}

// --- user.go: handleMerge -----------------------------------------------------

func TestWave1User_872f93ca55c3(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "872f93ca55c3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ? AND preferred = 1", 1).Count(&dest)
	})
}

func TestWave1User_f20fcf2bf293(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f20fcf2bf293", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("id, groupid, role, added, configid, settings, heldby").
			Where("userid = ?", 1).Find(&dest)
	})
}

func TestWave1User_8ce3d260bb10(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8ce3d260bb10", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("id, groupid, role, added, configid, settings, heldby").
			Where("userid = ? AND groupid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1User_cd441c119872(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "cd441c119872", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, chattype, user1, user2, groupid, latestmessage").
			Where("(user1 = ? OR user2 = ?) AND chattype IN ('User2User','User2Mod')", 1, 1).Find(&dest)
	})
}

func TestWave1User_49504f08b237(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "49504f08b237", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").
			Where("user1 = ? AND groupid = ? AND chattype = 'User2Mod'", 1, 2).Find(&dest)
	})
}

func TestWave1User_3e09ec1599a1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3e09ec1599a1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").
			Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", 1, 2, 2, 1).Find(&dest)
	})
}

func TestWave1User_f60915fd693a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f60915fd693a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname, firstname, lastname, yahooid, systemrole, added, tnuserid").
			Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_7fe526e2a805(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7fe526e2a805", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname, firstname, lastname, yahooid, systemrole, added, tnuserid").
			Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1User_4bb399eac601(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4bb399eac601", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Select("groupid").Where("userid = ?", 1).Find(&dest)
	})
}

func TestWave1User_8c44fcd5dae1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8c44fcd5dae1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Select("id, period").Where("userid IN (?, ?)", 1, 2).Find(&dest)
	})
}

// --- user.go: mod-tools per-user detail endpoints ---------------------------

func TestWave1User_d0d274882c8a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d0d274882c8a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, chattype, user1, user2, COALESCE(groupid, 0) AS groupid, latestmessage AS lastdate").
			Where("(user1 = ? OR user2 = ?)", 1, 1).Order("latestmessage DESC").Find(&dest)
	})
}

func TestWave1User_3099aa977394(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3099aa977394", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs_emails").Select("id, timestamp, eximid, `from`, `to`, subject, status").
			Where("userid = ?", 1).Order("id DESC").Limit(100).Find(&dest)
	})
}

func TestWave1User_056be9212962(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "056be9212962", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("id, timestamp, message, hidden, hiddenby, deleted, deletedby").
			Where("userid = ?", 1).Order("id DESC").Find(&dest)
	})
}

func TestWave1User_4cd946036f5f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4cd946036f5f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("id, userid, type, added, lastaccess").
			Where("userid = ?", 1).Order("lastaccess DESC").Limit(50).Find(&dest)
	})
}
