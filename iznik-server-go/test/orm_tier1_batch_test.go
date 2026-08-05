package test

// Batch C of the ORM migration (team-lead review, third pass): 21 sites
// regenerated fresh from a manifest one minute old, spanning "INSERT whose
// generated id is read back", "Multi-table UPDATE/DELETE ... JOIN" and
// "Top-level UNION" categories. Several turned out mislabelled the same way
// earlier batches were - swept into a category by sharing a function with a
// genuinely-blocked site, not by their own SQL shape.
//
// Sites converted here, one test each:
//   - 8c15ce918aa2  message/message.go PutMessageAs - the exact shape
//     ormharness/updatejoin_replace_test.go's TestUpdateJoin_TwoJoinsWithColumnValues
//     already pinned.
//   - 42f88b0d5032  location/location.go UpdateLocation's oldGeom query -
//     mislabelled "Top-level UNION" (ST_UNION() is a geometry function, not
//     a SQL UNION); same fmt.Sprintf-folded-SRID technique as this
//     function's other two sites. Approved diff, not a plain golden (see
//     approved-diffs.json).
//   - 1c2cfaaab39b, 57f83af68a60  chat/chatroom.go's two chat_roster INSERT
//     IGNORE statements - outside the row-locked *sql.Tx in the same
//     function (GetOrCreateUser2ModChat), discard their result, read no id.
//   - f06281f794b9  tryst/tryst.go's chat-exists COUNT - an ordinary
//     literal-WHERE query swept in by sharing a function with CreateTryst's
//     genuinely-raw ODKU insert (938d9dc56c71, left alone).
//   - 20dfce4d2228  spammers/spammers.go PostSpammer - REPLACE INTO +
//     map-Create "@id" read-back.
//   - 47417e0f74d7  location/location.go CreateLocation - map-Create +
//     approved diff (SRID fold).
//   - 8cbeeeb7e32f  group/group.go CreateGroup - map-Create + approved diff
//     (SRID fold).
//   - 62a2f6fa4bdb  chat/chatmessage.go's held-by user lookup inside
//     getReviewQueue - was a literal (non-bind) IN-list, same shape
//     markPinned had before its round-2 fix; swept in by sharing a function
//     with the review-queue UNION itself.
//   - 941509171a6e  user/user.go PatchUser's settings UPDATE - a genuine
//     2-shape site (ProcessSettingsUpdate appends at most one extra
//     "lastlocation = ?" clause), not the N-independent-fields kind
//     PatchModConfig/PatchSession are.
//   - 99713f48c505  message/message.go handleRevertEdits - a genuine
//     3-shape site (SubjectOnly/TextbodyOnly/Both), bounded by the function's
//     own guard that at least one is set.
//
// NOT converted, left raw with an accurate reason (unchanged from what was
// already recorded, or corrected where the category label was wrong):
//   - 65fde41159df, 69ed53a55edc, 2451a0b54d63 (chatroom.go
//     GetOrCreateUser2ModChat's SELECT ... FOR UPDATE / INSERT / UPDATE) -
//     genuinely share a row-locked raw *sql.Tx; converting one in isolation
//     would change lock scope.
//   - 938d9dc56c71 (tryst.go CreateTryst's ODKU insert) - the pre-existing
//     "arrangedat = NOW() with no id-forcing" bug the adversarial review
//     names explicitly; a mechanical port would reproduce it.
//   - ce0d84b77df2 (database/insert.go ExecInsertGetID) - a generic wrapper
//     with several call sites and no fixed shape of its own; the SQL belongs
//     to (and should be converted at) each call site, not the wrapper.
//   - e9f2c662be69 (message/message.go applyPatchMessageCore) - 8
//     independently-optional fields (2^8 = 256 shapes), well past this
//     wave's largest declared shape list; flagged rather than attempted.
//   - 7653c7a2e4ed (message/message.go ClipReachForRejectedGroup's other
//     UPDATE ... JOIN) - already correctly reasoned before this batch: a
//     genuine 2-shape site gated by a live-DB rippling.ReachBoundsReady
//     check, not touched here.
//   - 1571f00a4ce8, b0445c89f59e (image/image.go doCreate) - table/column
//     name driven by a 10-entry map; bounded but not attempted this round.
//   - 537a2764efde (chatroom.go listChats) - stays raw by team-lead's
//     explicit decision; not touched.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- message/message.go: PutMessageAs's location-denormalisation UPDATE --

func TestTier1Batch_8c15ce918aa2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8c15ce918aa2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages m JOIN users u ON u.id = ? JOIN locations l ON l.id = COALESCE(m.locationid, u.lastlocation)", 5).
			Clauses(clause.Set{
				{Column: clause.Column{Table: "m", Name: "locationid"}, Value: clause.Column{Table: "l", Name: "id"}},
				{Column: clause.Column{Table: "m", Name: "lat"}, Value: clause.Column{Table: "l", Name: "lat"}},
				{Column: clause.Column{Table: "m", Name: "lng"}, Value: clause.Column{Table: "l", Name: "lng"}},
			}).
			Where("m.id = ? AND (m.lat IS NULL OR m.lng IS NULL)", 900).
			Updates(map[string]interface{}{})
	})
}

// --- location/location.go: UpdateLocation's oldGeom query ----------------

func TestTier1Batch_42f88b0d5032(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "42f88b0d5032", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("locations").
			Select(`ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS old_geometry,
				CASE WHEN ST_Intersects(
					CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END,
					ST_GeomFromText(?, 3857))
				THEN ST_AsText(ST_UNION(
					CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END,
					ST_GeomFromText(?, 3857)))
				ELSE NULL
				END AS unioned`,
				"POLYGON((0 0,1 0,1 1,0 1,0 0))", "POLYGON((0 0,1 0,1 1,0 1,0 0))").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- chat/chatroom.go: GetOrCreateUser2ModChat's chat_roster INSERT IGNOREs ---

func TestTier1Batch_1c2cfaaab39b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1c2cfaaab39b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"chatid": 1, "userid": 2})
	})
}

func TestTier1Batch_57f83af68a60(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "57f83af68a60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"chatid": 1, "userid": 3})
	})
}

// --- tryst/tryst.go: PutTryst's chat-exists check -------------------------

func TestTier1Batch_f06281f794b9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f06281f794b9", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_rooms").
			Select("COUNT(*)").
			Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", 1, 2, 2, 1).
			Find(&dest)
	})
}

// --- spammers/spammers.go: PostSpammer's REPLACE INTO ---------------------

func TestTier1Batch_20dfce4d2228(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "20dfce4d2228", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"userid":     1,
				"collection": "Spammer",
				"reason":     "test",
				"byuserid":   2,
				"heldby":     gorm.Expr("NULL"),
				"heldat":     gorm.Expr("NULL"),
			})
	})
}

// --- location/location.go: CreateLocation's INSERT ------------------------

func TestTier1Batch_47417e0f74d7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "47417e0f74d7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Create(map[string]interface{}{
			"name":       "Test",
			"type":       gorm.Expr("'Polygon'"),
			"geometry":   gorm.Expr("ST_GeomFromText(?, 3857)", "POLYGON((0 0,1 0,1 1,0 1,0 0))"),
			"canon":      "test",
			"popularity": gorm.Expr("0"),
		})
	})
}

// --- group/group.go: CreateGroup's INSERT ---------------------------------

func TestTier1Batch_8cbeeeb7e32f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8cbeeeb7e32f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Create(map[string]interface{}{
			"nameshort": "Test",
			"namefull":  "Test",
			"type":      "Freegle",
			"publish":   gorm.Expr("1"),
			"onhere":    gorm.Expr("1"),
			"polyindex": gorm.Expr("ST_GeomFromText('POINT(0 0)', 3857)"),
		})
	})
}

// --- chat/chatmessage.go: getReviewQueue's held-by user lookup -----------

func TestTier1Batch_62a2f6fa4bdb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "62a2f6fa4bdb", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users u").
			Select("u.id, u.fullname AS name, "+
				"(SELECT e.email FROM users_emails e WHERE e.userid = u.id AND e.preferred = 1 LIMIT 1) AS email").
			Where("u.id IN ?", []uint64{1, 2, 3}).
			Find(&dest)
	})
}

// --- user/user.go: PatchUser's settings UPDATE, 2 shapes -----------------

func TestTier1BatchShapes_941509171a6e(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users")
	}
	ormharness.AssertGoldenShapes(t, "941509171a6e", []ormharness.Shape{
		{Name: "NoLastLocation", Build: func(tx *gorm.DB) *gorm.DB {
			return base(tx).Clauses(clause.Set{
				{Column: clause.Column{Name: "settings"}, Value: gorm.Expr("JSON_MERGE_PATCH(COALESCE(settings, '{}'), CAST(? AS JSON))", "{}")},
			}).Where("id = ?", 1).Updates(map[string]interface{}{})
		}},
		{Name: "WithLastLocation", Build: func(tx *gorm.DB) *gorm.DB {
			return base(tx).Clauses(clause.Set{
				{Column: clause.Column{Name: "lastlocation"}, Value: 42},
				{Column: clause.Column{Name: "settings"}, Value: gorm.Expr("JSON_MERGE_PATCH(COALESCE(settings, '{}'), CAST(? AS JSON))", "{}")},
			}).Where("id = ?", 1).Updates(map[string]interface{}{})
		}},
	})
}

// --- message/message.go: handleRevertEdits's restore UPDATE, 3 shapes ----

func TestTier1BatchShapes_99713f48c505(t *testing.T) {
	base := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages")
	}
	ormharness.AssertGoldenShapes(t, "99713f48c505", []ormharness.Shape{
		{Name: "SubjectOnly", Build: func(tx *gorm.DB) *gorm.DB {
			return base(tx).Clauses(clause.Set{
				{Column: clause.Column{Name: "editedby"}, Value: gorm.Expr("NULL")},
				{Column: clause.Column{Name: "subject"}, Value: "Old subject"},
			}).Where("id = ?", 1).Updates(map[string]interface{}{})
		}},
		{Name: "TextbodyOnly", Build: func(tx *gorm.DB) *gorm.DB {
			return base(tx).Clauses(clause.Set{
				{Column: clause.Column{Name: "editedby"}, Value: gorm.Expr("NULL")},
				{Column: clause.Column{Name: "textbody"}, Value: "Old text"},
			}).Where("id = ?", 1).Updates(map[string]interface{}{})
		}},
		{Name: "Both", Build: func(tx *gorm.DB) *gorm.DB {
			return base(tx).Clauses(clause.Set{
				{Column: clause.Column{Name: "editedby"}, Value: gorm.Expr("NULL")},
				{Column: clause.Column{Name: "subject"}, Value: "Old subject"},
				{Column: clause.Column{Name: "textbody"}, Value: "Old text"},
			}).Where("id = ?", 1).Updates(map[string]interface{}{})
		}},
	})
}
