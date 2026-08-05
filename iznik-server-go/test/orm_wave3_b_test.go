package test

// Wave 3, batch B (plan section 7.3+): the upsert-shaped sites (INSERT IGNORE
// and INSERT ... ON DUPLICATE KEY UPDATE) in microvolunteering/
// microvolunteering.go, chat/chatroom.go, session/session.go, story/story.go,
// newsfeed/newsfeed.go, volunteering/volunteering.go, location/location.go,
// abtest/abtest.go, membership/membership.go, isochrone/isochrone.go,
// amp/amp.go, notification/notification.go, user/userEmails.go and
// noticeboard/noticeboard.go.
//
// Upsert conventions, pinned by ormharness/upsert_test.go before this batch
// was written (read that file first if this looks unfamiliar):
//   - INSERT IGNORE converts with clause.Insert{Modifier: "IGNORE"}, never
//     clause.OnConflict{DoNothing: true}. Our .Table(...) convention keeps
//     stmt.Schema nil, so the MySQL driver's DoNothing fallback (which only
//     fires when Schema is non-nil) never runs, and DoNothing would render a
//     dangling "ON DUPLICATE KEY UPDATE" with nothing after it - not valid SQL.
//   - INSERT ... ON DUPLICATE KEY UPDATE converts with
//     clause.OnConflict{DoUpdates: ...}.
//   - "col = VALUES(col)" needs its assignment Value to be
//     clause.Column{Table: "excluded", Name: "col"} - the MySQL driver
//     rewrites that specific shape to VALUES(col); a plain Go value would
//     bind instead, which is a different statement.
//
// SET-order care (gate (i)'s reasoning, extended to DoUpdates - see
// upsert_test.go's file comment and check-set-order.sh, which does not yet
// scan clause.Assignments(...) or clause.OnConflict literals, only
// Updates(map...)): clause.Assignments(map[string]interface{}{...}) sorts
// its keys alphabetically, same as Updates(map...). Two sites in abtest.go
// have an assignment whose expression reads a DIFFERENT column the same
// statement also assigns (rate reads shown/action), so alphabetical order
// would silently change which value rate is computed from. Both use an
// explicit ordered clause.Set literal instead of clause.Assignments(map...),
// preserving the original statement's exact SET order. Every other
// multi-assignment site here was checked by hand: either every value is
// independent of every other assigned column (reordering changes nothing),
// or the one cross-reference is a column referencing ITSELF (status's
// self-referencing IF(...), userid = userid, byuser via the excluded table),
// which is not order-dependent with respect to any of its statement's OTHER
// assignments.
//
// Two sites are left raw, not converted:
//   - newsfeed.go's Post/ConvertedToPost photo-carry (08d12a748d01):
//     INSERT ... SELECT. No GORM builder keeps an INSERT and its source
//     SELECT as one atomic statement.
//   - noticeboard.go's PatchNoticeboard newsfeed-entry insert (c4e30fd6a513):
//     the manifest marks it "dynamic": true - the position value is built by
//     fmt.Sprintf embedding lng/lat/SRID as literal text straight into the
//     SQL string (%f %f %d), not as bind parameters, so the recorded
//     goldenSql is the pre-format Go source text rather than real SQL. There
//     is nothing a rendered GORM statement could ever compare equal to.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- abtest/abtest.go: PostABTest --------------------------------------------

// 7e75c8eb601d: rate's expression reads shown, which this statement also
// assigns, so DoUpdates is an explicit ordered clause.Set (shown before
// rate) rather than clause.Assignments(map...), which would alphabetise to
// rate-before-shown and silently change what rate is computed from.
func TestWave3B_7e75c8eb601d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7e75c8eb601d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("abtest").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "shown"}, Value: gorm.Expr("shown + 1")},
				{Column: clause.Column{Name: "rate"}, Value: gorm.Expr("COALESCE(100 * action / shown, 0)")},
			},
		}).Create(map[string]interface{}{
			"uid": "u1", "variant": "A", "shown": gorm.Expr("1"), "action": gorm.Expr("0"), "rate": gorm.Expr("0"),
		})
	})
}

// 7e4882220657: same reasoning as 7e75c8eb601d, but rate reads action.
func TestWave3B_7e4882220657(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7e4882220657", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("abtest").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "action"}, Value: gorm.Expr("action + ?", 1)},
				{Column: clause.Column{Name: "rate"}, Value: gorm.Expr("COALESCE(100 * action / shown, 0)")},
			},
		}).Create(map[string]interface{}{
			"uid": "u1", "variant": "A", "shown": gorm.Expr("0"), "action": 1, "rate": gorm.Expr("0"),
		})
	})
}

// --- amp/amp.go: GetChatMessages ---------------------------------------------

func TestWave3B_a1ba47046192(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a1ba47046192", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking_clicks").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{
				"email_tracking_id": 1,
				"link_url":          gorm.Expr("'amp://render'"),
				"action":            gorm.Expr("'amp_render'"),
				"clicked_at":        gorm.Expr("NOW()"),
			})
	})
}

// --- chat/chatroom.go: handleRosterUpdate -----------------------------------

// 7db50195bb3c and 9c86a991eb7c are the same statement at two call sites (the
// BLOCKED and the default status branches), converted together: gate (h)
// refuses a half-converted pair, because converting one renumbers the
// survivor's site ID.
func TestWave3B_7db50195bb3c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7db50195bb3c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"status": "Blocked", "lastip": "1.2.3.4", "date": gorm.Expr("NOW()"),
			}),
		}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Blocked", "lastip": "1.2.3.4", "date": gorm.Expr("NOW()"),
		})
	})
}

func TestWave3B_9c86a991eb7c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9c86a991eb7c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"status": "Online", "lastip": "1.2.3.4", "date": gorm.Expr("NOW()"),
			}),
		}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Online", "lastip": "1.2.3.4", "date": gorm.Expr("NOW()"),
		})
	})
}

// e6d3316c800c: status's IF(status = ?, status, ?) reads "status" - the same
// key it is assigned to, not a different assigned column - so it is
// order-independent under the same-key exclusion the rest of this batch
// relies on.
func TestWave3B_e6d3316c800c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e6d3316c800c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"status": gorm.Expr("IF(status = ?, status, ?)", "Blocked", "Closed"),
				"lastip": "1.2.3.4", "date": gorm.Expr("NOW()"),
			}),
		}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Closed", "lastip": "1.2.3.4", "date": gorm.Expr("NOW()"),
		})
	})
}

// --- chat/chatroom.go: PutChatRoom (User2Mod and User2User roster inserts) --

// afaa0e49a541, e611588b2309 and 60aa69c60334 are the same statement at three
// call sites, converted together.
func TestWave3B_afaa0e49a541(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "afaa0e49a541", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "date"}, Value: clause.Column{Table: "excluded", Name: "date"}},
			},
		}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Online", "date": "2026-01-01 00:00:00",
		})
	})
}

func TestWave3B_e611588b2309(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e611588b2309", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "date"}, Value: clause.Column{Table: "excluded", Name: "date"}},
			},
		}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Online", "date": "2026-01-01 00:00:00",
		})
	})
}

func TestWave3B_60aa69c60334(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "60aa69c60334", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "date"}, Value: clause.Column{Table: "excluded", Name: "date"}},
			},
		}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Online", "date": "2026-01-01 00:00:00",
		})
	})
}

func TestWave3B_21c0c56448e8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "21c0c56448e8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"chatid": 1, "userid": 2, "status": "Online", "date": "2026-01-01 00:00:00"})
	})
}

// --- chat/chatroom.go: GetOrCreateUser2UserChat ------------------------------

// e71799673a73 and a70cf3624bdb are the same statement at two call sites
// (seeding both participants), converted together.
func TestWave3B_e71799673a73(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e71799673a73", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Online", "date": gorm.Expr("NOW()"),
		})
	})
}

func TestWave3B_a70cf3624bdb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a70cf3624bdb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "status": "Online", "date": gorm.Expr("NOW()"),
		})
	})
}

// --- isochrone/isochrone.go: ListIsochrones ----------------------------------

func TestWave3B_56093182f920(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "56093182f920", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "isochroneid"}, Value: clause.Column{Table: "excluded", Name: "isochroneid"}},
			},
		}).Create(map[string]interface{}{"userid": 1, "isochroneid": 2})
	})
}

// --- location/location.go: ExcludeLocation -----------------------------------

// 666504e10980 and 59411a155371 are the same statement at two call sites (the
// requested location and its byname siblings), converted together.
func TestWave3B_666504e10980(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "666504e10980", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations_excluded").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"locationid": 1, "groupid": 2, "userid": 3})
	})
}

func TestWave3B_59411a155371(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "59411a155371", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations_excluded").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"locationid": 1, "groupid": 2, "userid": 3})
	})
}

// --- membership/membership.go: PostMemberships / DeleteMemberships (ban) ----

// dfc985e8ea67 and d788d299a578 are the same statement at two call sites
// (PostMemberships's Ban action and DeleteMemberships), converted together.
func TestWave3B_dfc985e8ea67(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dfc985e8ea67", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "byuser"}, Value: clause.Column{Table: "excluded", Name: "byuser"}},
				{Column: clause.Column{Name: "date"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{"userid": 1, "groupid": 2, "byuser": 3})
	})
}

func TestWave3B_d788d299a578(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d788d299a578", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "byuser"}, Value: clause.Column{Table: "excluded", Name: "byuser"}},
				{Column: clause.Column{Name: "date"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{"userid": 1, "groupid": 2, "byuser": 3})
	})
}

// --- microvolunteering/microvolunteering.go: RecordReportVerdict ------------

func TestWave3B_062b91c70acc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "062b91c70acc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"result": gorm.Expr("'Reject'"), "msgcategory": gorm.Expr("'ShouldntBeHere'"), "comments": "x", "version": 1,
			}),
		}).Create(map[string]interface{}{
			"actiontype": "CheckMessage", "userid": 1, "msgid": 2,
			"result": gorm.Expr("'Reject'"), "msgcategory": gorm.Expr("'ShouldntBeHere'"),
			"comments": "x", "version": 1, "score_negative": gorm.Expr("0"),
		})
	})
}

// --- microvolunteering/microvolunteering.go: RecordAIAttachmentDeletion / ForceRejectAIImage ---

// c2b7425e88e0 and 98a9897d62e5 are the same statement at two call sites,
// converted together.
func TestWave3B_c2b7425e88e0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c2b7425e88e0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"result": gorm.Expr("'Reject'"), "version": 1}),
		}).Create(map[string]interface{}{
			"actiontype": "AIImageReview", "userid": 1, "aiimageid": 2,
			"result": gorm.Expr("'Reject'"), "version": 1, "score_negative": gorm.Expr("0"),
		})
	})
}

func TestWave3B_98a9897d62e5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "98a9897d62e5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"result": gorm.Expr("'Reject'"), "version": 1}),
		}).Create(map[string]interface{}{
			"actiontype": "AIImageReview", "userid": 1, "aiimageid": 2,
			"result": gorm.Expr("'Reject'"), "version": 1, "score_negative": gorm.Expr("0"),
		})
	})
}

// --- microvolunteering/microvolunteering.go: PostResponse -------------------

func TestWave3B_e78fcf444c47(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e78fcf444c47", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"result": "Approve", "comments": "x", "version": 1, "msgcategory": "Other",
			}),
		}).Create(map[string]interface{}{
			"actiontype": "CheckMessage", "userid": 1, "msgid": 2,
			"result": "Approve", "msgcategory": "Other", "comments": "x", "version": 1, "score_negative": gorm.Expr("0"),
		})
	})
}

// 4bc6d0615816: "userid = userid" is a self-reference no-op, order-independent
// with respect to "version".
func TestWave3B_4bc6d0615816(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4bc6d0615816", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"userid": gorm.Expr("userid"), "version": 1}),
		}).Create(map[string]interface{}{
			"actiontype": "SearchTerm", "userid": 1, "item1": 2, "item2": 3,
			"version": 1, "result": gorm.Expr("'Approve'"), "score_negative": gorm.Expr("0"),
		})
	})
}

func TestWave3B_f82ee651d4b9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f82ee651d4b9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"actiontype": "PhotoRotate", "userid": 1, "rotatedimage": 2,
			"result": "Reject", "version": 3, "score_negative": gorm.Expr("0"),
		})
	})
}

func TestWave3B_6dadb189bddc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6dadb189bddc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"result": "Approve", "containspeople": 1, "version": 2,
			}),
		}).Create(map[string]interface{}{
			"actiontype": "AIImageReview", "userid": 1, "aiimageid": 2,
			"result": "Approve", "containspeople": 1, "version": 2, "score_negative": gorm.Expr("0"),
		})
	})
}

func TestWave3B_9b0560d85c4d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9b0560d85c4d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"eee_condition": "Good", "eee_weight": "1kg", "eee_size": "Small", "version": 1,
			}),
		}).Create(map[string]interface{}{
			"actiontype": "EEELabel", "userid": 1, "eee_attachment_id": 2,
			"eee_condition": "Good", "eee_weight": "1kg", "eee_size": "Small",
			"result": gorm.Expr("'Approve'"), "version": 1, "score_negative": gorm.Expr("0"),
		})
	})
}

func TestWave3B_6602f9905a74(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6602f9905a74", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"actiontype": "Invite", "userid": 1, "version": 2, "result": gorm.Expr("'Approve'"),
		})
	})
}

// --- newsfeed/newsfeed.go: Post - Love case ----------------------------------

func TestWave3B_520be22ce5db(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "520be22ce5db", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_likes").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"newsfeedid": 1, "userid": 2})
	})
}

// --- notification/notification.go: List --------------------------------------

func TestWave3B_b0bc94eb01e8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b0bc94eb01e8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_active").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"userid": 1, "timestamp": "2026-01-01 12:00:00"})
	})
}

// --- session/session.go: PatchSession (password / push notification) -------

func TestWave3B_69fb1ebb3a73(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "69fb1ebb3a73", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"credentials": "h", "salt": "s"}),
		}).Create(map[string]interface{}{
			"userid": 1, "type": "Native", "uid": "1", "credentials": "h", "salt": "s",
		})
	})
}

func TestWave3B_5fb6e8fa85fd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5fb6e8fa85fd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_push_notifications").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"userid": 1, "type": "Web", "apptype": "User"}),
		}).Create(map[string]interface{}{
			"userid": 1, "type": "Web", "subscription": "sub", "apptype": "User",
		})
	})
}

// --- session/session.go: handleRelated ---------------------------------------

func TestWave3B_39a4f93e1455(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "39a4f93e1455", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_related").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"user1": 1, "user2": 2})
	})
}

// --- session/session.go: GetSession -------------------------------------------

func TestWave3B_b4d495e2284e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b4d495e2284e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_builddates").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"timestamp": gorm.Expr("NOW()"), "webversion": "1.0", "appversion": "2.0",
			}),
		}).Create(map[string]interface{}{"userid": 1, "webversion": "1.0", "appversion": "2.0"})
	})
}

// --- story/story.go: createStoryNewsfeedEntry --------------------------------

func TestWave3B_9263f0bb43fb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9263f0bb43fb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Create(map[string]interface{}{
			"type":           gorm.Expr("'Story'"),
			"userid":         1,
			"storyid":        2,
			"position":       gorm.Expr("ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), ?)", -0.1, 51.5, 27700),
			"hidden":         gorm.Expr("NULL"),
			"deleted":        gorm.Expr("NULL"),
			"reviewrequired": gorm.Expr("0"),
			"pinned":         gorm.Expr("0"),
		})
	})
}

// --- story/story.go: LikeStory / PostStory (Like case) -----------------------

// 713e8b8dab08 and 0d3865cbb34e are the same statement at two call sites,
// converted together.
func TestWave3B_713e8b8dab08(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "713e8b8dab08", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories_likes").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"storyid": 1, "userid": 2})
	})
}

func TestWave3B_0d3865cbb34e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0d3865cbb34e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories_likes").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"storyid": 1, "userid": 2})
	})
}

// --- user/userEmails.go: GetOrCreateInternalEmail ----------------------------

func TestWave3B_ba1bd193532a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ba1bd193532a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid":       1,
			"email":        "x@users.ilovefreegle.org",
			"preferred":    gorm.Expr("0"),
			"added":        gorm.Expr("NOW()"),
			"validatetime": gorm.Expr("NOW()"),
		})
	})
}

// --- volunteering/volunteering.go: Create / Update (AddGroup) ---------------

// c77cdc1a1f5f and 316bb6807874 are the same statement at two call sites,
// converted together.
func TestWave3B_c77cdc1a1f5f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c77cdc1a1f5f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"volunteeringid": 1, "groupid": 2})
	})
}

func TestWave3B_316bb6807874(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "316bb6807874", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"volunteeringid": 1, "groupid": 2})
	})
}
