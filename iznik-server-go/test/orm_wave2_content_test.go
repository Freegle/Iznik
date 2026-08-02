package test

// Wave 2 (single-table writes), content modules (plan section 7.3+):
// volunteering opportunities and their groups/dates/images (volunteering/
// volunteering.go), moderator standard messages (stdmsg/stdmsg.go),
// newsfeed/ChitChat posts, likes, follows and notifications (newsfeed/
// newsfeed.go), community events and their groups/dates/images
// (communityevent/communityEvent.go), noticeboard checks and settings
// (noticeboard/noticeboard.go), AI-generated item images (aiimage/
// aiimage.go), member stories and their likes (story/story.go), and
// microvolunteering challenge responses and AI-image quorum actions
// (microvolunteering/microvolunteering.go).
//
// Write conventions, same as the rest of wave 2:
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed and
//     a soft-delete field cannot silently turn a DELETE into an UPDATE.
//   - gorm.Expr(...) for a literal or expression value (NOW(), NULL, a bare
//     0 or 1, or a quoted string literal such as 'rejected') rather than a
//     plain Go value, which would bind as "?" and diverge from a golden that
//     writes the literal inline.
//   - Table(...).Create(map[string]interface{}{...}) for INSERTs; a value
//     that must render as a SQL literal goes through gorm.Expr the same way
//     an UPDATE value would.
//   - db.Clauses(clause.Insert{Modifier: "IGNORE"}) for INSERT IGNORE.
//
// Four sites are left raw, each an INSERT whose caller needs the new row's
// id via sql.Result.LastInsertId() on the same connection that ran it:
// stdmsg.go's PostStdMsg (132b1f639e73), communityEvent.go's Create
// (45b8b0bc2060), volunteering.go's Create (0adadbabde5b), and story.go's
// CreateStory (f6190b74d8d5). GORM's map-Create id writeback for a
// schema-less Table()+map call is undocumented behaviour, not something to
// rely on for a fresh row id.
//
// Two more are left raw for a different reason: story.go's LikeStory
// (713e8b8dab08) and PostStory's "Like" case (0d3865cbb34e) are an
// INSERT IGNORE INTO users_stories_likes duplicate pair that belongs to
// wave 3, not this batch - the per-module duplicate scan surfaces every
// identical-statement group in a file regardless of which wave owns it, and
// these two are wave 3's, so converting them here would be out of scope.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- noticeboard/noticeboard.go: PostNoticeboard (action cases) ------------

func TestWave2Content_b22dc6f55660(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b22dc6f55660", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards_checks").Create(map[string]interface{}{
			"noticeboardid": 1,
			"userid":        2,
			"checkedat":     gorm.Expr("NOW()"),
			"refreshed":     gorm.Expr("1"),
			"inactive":      gorm.Expr("0"),
		})
	})
}

func TestWave2Content_4e32d794943f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4e32d794943f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).
			Updates(map[string]interface{}{"lastcheckedat": gorm.Expr("NOW()"), "active": gorm.Expr("1")})
	})
}

func TestWave2Content_8e072007de59(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8e072007de59", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards_checks").Create(map[string]interface{}{
			"noticeboardid": 1,
			"userid":        2,
			"checkedat":     gorm.Expr("NOW()"),
			"declined":      gorm.Expr("1"),
			"inactive":      gorm.Expr("0"),
		})
	})
}

func TestWave2Content_29fd88a76f02(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "29fd88a76f02", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards_checks").Create(map[string]interface{}{
			"noticeboardid": 1,
			"userid":        2,
			"checkedat":     gorm.Expr("NOW()"),
			"inactive":      gorm.Expr("1"),
		})
	})
}

func TestWave2Content_90267e07036b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "90267e07036b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).
			Updates(map[string]interface{}{"lastcheckedat": gorm.Expr("NOW()"), "active": gorm.Expr("0")})
	})
}

func TestWave2Content_fc070851caba(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fc070851caba", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards_checks").Create(map[string]interface{}{
			"noticeboardid": 1,
			"userid":        2,
			"checkedat":     gorm.Expr("NOW()"),
			"comments":      "Refreshed",
			"inactive":      gorm.Expr("0"),
		})
	})
}

// --- noticeboard/noticeboard.go: PatchNoticeboard ---------------------------

func TestWave2Content_231d70e1fa28(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "231d70e1fa28", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Update("name", "X")
	})
}

func TestWave2Content_96d656eb95cb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "96d656eb95cb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Update("lat", 51.5)
	})
}

func TestWave2Content_86722b0b3cff(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "86722b0b3cff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Update("lng", -0.1)
	})
}

func TestWave2Content_0c12eb5cf095(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0c12eb5cf095", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Update("description", "X")
	})
}

func TestWave2Content_b4e8507b4bf0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b4e8507b4bf0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Update("active", true)
	})
}

func TestWave2Content_86acdf5e502f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "86acdf5e502f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Update("lastcheckedat", "2026-01-01 00:00:00")
	})
}

func TestWave2Content_42bec0874800(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "42bec0874800", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards_images").Where("id = ?", 1).Update("noticeboardid", 2)
	})
}

// --- noticeboard/noticeboard.go: DeleteNoticeboard --------------------------

func TestWave2Content_1689d28e9c22(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1689d28e9c22", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Delete(nil)
	})
}

// --- stdmsg/stdmsg.go: PostStdMsg -------------------------------------------

// 46c5fb361ea2 and cef43692b937 are the same statement at two call sites
// (PostStdMsg and PatchStdMsg), converted together: gate (h) refuses a
// half-converted pair, because converting one renumbers the survivor's site
// ID. The same applies to the five pairs immediately below it.
func TestWave2Content_46c5fb361ea2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "46c5fb361ea2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("action", "X")
	})
}

func TestWave2Content_116e92a68ac4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "116e92a68ac4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("subjpref", "X")
	})
}

func TestWave2Content_46bebb6b38be(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "46bebb6b38be", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("subjsuff", "X")
	})
}

func TestWave2Content_8ad8c1589208(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8ad8c1589208", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("body", "X")
	})
}

func TestWave2Content_948c386a078b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "948c386a078b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("rarelyused", 1)
	})
}

func TestWave2Content_2ba672ef4292(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2ba672ef4292", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("autosend", 1)
	})
}

// --- stdmsg/stdmsg.go: PatchStdMsg ------------------------------------------

func TestWave2Content_0d06dc492d55(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0d06dc492d55", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("title", "X")
	})
}

func TestWave2Content_cef43692b937(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cef43692b937", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("action", "X")
	})
}

func TestWave2Content_f29e07819b1a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f29e07819b1a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("subjpref", "X")
	})
}

func TestWave2Content_f9fc836339e6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f9fc836339e6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("subjsuff", "X")
	})
}

func TestWave2Content_5e8a95612260(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5e8a95612260", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("body", "X")
	})
}

func TestWave2Content_82fe128d30d3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "82fe128d30d3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("rarelyused", 1)
	})
}

func TestWave2Content_6a8c185c8d22(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6a8c185c8d22", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("autosend", 1)
	})
}

func TestWave2Content_8e96c309ddf2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8e96c309ddf2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("newmodstatus", "X")
	})
}

func TestWave2Content_7ab15f7bfb8f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7ab15f7bfb8f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("newdelstatus", "X")
	})
}

func TestWave2Content_4dcf8ff38c9f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4dcf8ff38c9f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("edittext", "X")
	})
}

// 2379cd419502: `insert` is a MySQL reserved word in source, quoted with
// backticks in the golden. GORM quotes every identifier regardless, and the
// canonicaliser strips quoting either way, so the plain column name matches.
func TestWave2Content_2379cd419502(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2379cd419502", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Update("insert", "X")
	})
}

// --- stdmsg/stdmsg.go: DeleteStdMsg -----------------------------------------

func TestWave2Content_3157418b1d37(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3157418b1d37", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Delete(nil)
	})
}

// --- story/story.go: CreateStory ---------------------------------------------

func TestWave2Content_cd54b640d303(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cd54b640d303", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories_images").Where("id = ?", 1).Update("storyid", 2)
	})
}

// --- story/story.go: UpdateStory ---------------------------------------------

func TestWave2Content_1e5a7a00c4ae(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1e5a7a00c4ae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Where("id = ?", 1).Update("public", 1)
	})
}

func TestWave2Content_3e4a98f99f9a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3e4a98f99f9a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Where("id = ?", 1).Update("headline", "X")
	})
}

func TestWave2Content_df4d580584c7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "df4d580584c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Where("id = ?", 1).Update("story", "X")
	})
}

func TestWave2Content_d2a597ecda56(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d2a597ecda56", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Where("id = ?", 1).
			Updates(map[string]interface{}{"reviewed": 1, "reviewedby": 2})
	})
}

func TestWave2Content_2036212f3e48(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2036212f3e48", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Where("id = ?", 1).
			Updates(map[string]interface{}{"newsletterreviewed": 1, "newsletterreviewedby": 2})
	})
}

func TestWave2Content_f79c7ee2991c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f79c7ee2991c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Where("id = ?", 1).Update("newsletter", 1)
	})
}

// --- story/story.go: UnlikeStory / PostStory (Unlike case) -----------------

// 171408a9703d and 941fa556061a are the same statement at two call sites
// (UnlikeStory and PostStory's Unlike case), converted together.
func TestWave2Content_171408a9703d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "171408a9703d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories_likes").Where("storyid = ? AND userid = ?", 1, 2).Delete(nil)
	})
}

func TestWave2Content_941fa556061a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "941fa556061a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories_likes").Where("storyid = ? AND userid = ?", 1, 2).Delete(nil)
	})
}

// --- story/story.go: DeleteStory ---------------------------------------------

func TestWave2Content_74b27430dc33(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "74b27430dc33", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_stories").Where("id = ?", 1).Delete(nil)
	})
}

// --- newsfeed/newsfeed.go: Post - Love case ---------------------------------

func TestWave2Content_72c7371e4220(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "72c7371e4220", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Create(map[string]interface{}{
			"fromuser":   1,
			"touser":     2,
			"type":       "LovedPost",
			"newsfeedid": 3,
		})
	})
}

// --- newsfeed/newsfeed.go: Post - Unlove case -------------------------------

func TestWave2Content_20d3333b6657(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "20d3333b6657", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_likes").Where("newsfeedid = ? AND userid = ?", 1, 2).Delete(nil)
	})
}

// --- newsfeed/newsfeed.go: Post - Seen case ---------------------------------

func TestWave2Content_8a70c0aa1832(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8a70c0aa1832", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Where("touser = ? AND newsfeedid = ?", 1, 2).
			Update("seen", gorm.Expr("1"))
	})
}

// --- newsfeed/newsfeed.go: Post - Follow case -------------------------------

func TestWave2Content_75b3dfc075ca(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "75b3dfc075ca", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_unfollow").Where("userid = ? AND newsfeedid = ?", 1, 2).Delete(nil)
	})
}

// --- newsfeed/newsfeed.go: Post - Report case -------------------------------

func TestWave2Content_8d982395eb1f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8d982395eb1f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).Update("reviewrequired", gorm.Expr("1"))
	})
}

// --- newsfeed/newsfeed.go: Post - Hide / ConvertedToPost --------------------

// 67632eee8567 and 3efb8e22cd38 are the same statement at two call sites
// (the Hide action and ConvertedToPost's hide-the-copy step), converted
// together.
func TestWave2Content_67632eee8567(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "67632eee8567", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).
			Updates(map[string]interface{}{"hidden": gorm.Expr("NOW()"), "hiddenby": 2})
	})
}

// 47dfb0a3eebe: golden's text column is the literal 'Newsfeed entry hidden',
// so it goes through gorm.Expr rather than as a bind.
func TestWave2Content_47dfb0a3eebe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "47dfb0a3eebe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"text":      gorm.Expr("'Newsfeed entry hidden'"),
		})
	})
}

// --- newsfeed/newsfeed.go: Post - Unhide case -------------------------------

// a32e0ffcd9e2: golden clears both columns with a literal NULL, so both go
// through gorm.Expr rather than as binds.
func TestWave2Content_a32e0ffcd9e2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a32e0ffcd9e2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).
			Updates(map[string]interface{}{"hidden": gorm.Expr("NULL"), "hiddenby": gorm.Expr("NULL")})
	})
}

func TestWave2Content_e96850e959f9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e96850e959f9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"text":      gorm.Expr("'Newsfeed entry unhidden'"),
		})
	})
}

func TestWave2Content_3efb8e22cd38(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3efb8e22cd38", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).
			Updates(map[string]interface{}{"hidden": gorm.Expr("NOW()"), "hiddenby": 2})
	})
}

// d58a719af7e5: golden's text column is a dynamic fmt.Sprintf string, so
// unlike its sibling log inserts above it stays a plain bind.
func TestWave2Content_d58a719af7e5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d58a719af7e5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"text":      "ChitChat post 4 posted as message 5 for the member",
		})
	})
}

// --- newsfeed/newsfeed.go: Post - AttachToThread case -----------------------

func TestWave2Content_359a8dec20e2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "359a8dec20e2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).Update("replyto", 2)
	})
}

func TestWave2Content_750cbc27385c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "750cbc27385c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"text":      gorm.Expr("'Newsfeed entry attached to thread'"),
		})
	})
}

// --- newsfeed/newsfeed.go: bumpThread ---------------------------------------

func TestWave2Content_bd0d10b12d60(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bd0d10b12d60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).Update("timestamp", gorm.Expr("NOW()"))
	})
}

// --- newsfeed/newsfeed.go: notifyThreadContributors / createRefer ----------

// 3c78baa8b628 and c56bbfcef4f1 are the same statement at two call sites
// (notifyThreadContributors and createRefer), converted together.
func TestWave2Content_3c78baa8b628(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3c78baa8b628", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Create(map[string]interface{}{
			"fromuser":   1,
			"touser":     2,
			"type":       gorm.Expr("'CommentOnYourPost'"),
			"newsfeedid": 3,
		})
	})
}

func TestWave2Content_c56bbfcef4f1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c56bbfcef4f1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Create(map[string]interface{}{
			"fromuser":   1,
			"touser":     2,
			"type":       gorm.Expr("'CommentOnYourPost'"),
			"newsfeedid": 3,
		})
	})
}

// --- newsfeed/newsfeed.go: Edit ---------------------------------------------

func TestWave2Content_c6ae40a34425(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c6ae40a34425", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).Update("message", "X")
	})
}

// --- newsfeed/newsfeed.go: Delete -------------------------------------------

func TestWave2Content_b9777a2b2dc3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b9777a2b2dc3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Where("id = ?", 1).
			Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "deletedby": 2})
	})
}

func TestWave2Content_c1dcf61cde69(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c1dcf61cde69", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Where("newsfeedid = ?", 1).Delete(nil)
	})
}

// --- communityevent/communityEvent.go: Update - settable attributes --------

func TestWave2Content_8e45bcb23b19(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8e45bcb23b19", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("title", "X")
	})
}

func TestWave2Content_df65cbf75e01(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "df65cbf75e01", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("location", "X")
	})
}

func TestWave2Content_2f79186b42bf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2f79186b42bf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("pending", true)
	})
}

// e150408f2d3e: golden clears heldby with a literal NULL, so it goes through
// gorm.Expr rather than as a bind.
func TestWave2Content_e150408f2d3e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e150408f2d3e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).
			Updates(map[string]interface{}{"pending": false, "heldby": gorm.Expr("NULL")})
	})
}

func TestWave2Content_f719a23dedc3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f719a23dedc3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("contactname", "X")
	})
}

func TestWave2Content_b554bdcafe03(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b554bdcafe03", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("contactphone", "X")
	})
}

func TestWave2Content_1af3dbf05d6b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1af3dbf05d6b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("contactemail", "X")
	})
}

func TestWave2Content_2a3bc76d4017(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2a3bc76d4017", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("contacturl", "X")
	})
}

func TestWave2Content_0c2736a9cf30(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0c2736a9cf30", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("description", "X")
	})
}

// --- communityevent/communityEvent.go: Update - action switch --------------

func TestWave2Content_2951a7ffab4d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2951a7ffab4d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_groups").Where("eventid = ? AND groupid = ?", 1, 2).Delete(nil)
	})
}

func TestWave2Content_7e814fd678a7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7e814fd678a7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_dates").Create(map[string]interface{}{
			"eventid": 1,
			"start":   "2026-01-01",
			"end":     "2026-01-02",
		})
	})
}

func TestWave2Content_1a34f23b47dc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1a34f23b47dc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_dates").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2Content_68bba2319103(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "68bba2319103", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_images").Where("id = ?", 1).Update("eventid", 2)
	})
}

func TestWave2Content_ef582e5c1fb3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ef582e5c1fb3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("heldby", 2)
	})
}

func TestWave2Content_d2ab18538fec(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d2ab18538fec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("heldby", gorm.Expr("NULL"))
	})
}

// --- communityevent/communityEvent.go: Delete -------------------------------

func TestWave2Content_1a05317673e7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1a05317673e7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Where("id = ?", 1).Update("deleted", gorm.Expr("1"))
	})
}

// --- volunteering/volunteering.go: Update - settable attributes ------------

func TestWave2Content_fecdb8962d43(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fecdb8962d43", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("title", "X")
	})
}

func TestWave2Content_50c0868c92b5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "50c0868c92b5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("location", "X")
	})
}

func TestWave2Content_07c0237d777f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "07c0237d777f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("online", true)
	})
}

func TestWave2Content_ed8f1877c12d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ed8f1877c12d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("pending", true)
	})
}

// 4266b747f8a4: same reasoning as communityevent's e150408f2d3e above.
func TestWave2Content_4266b747f8a4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4266b747f8a4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).
			Updates(map[string]interface{}{"pending": false, "heldby": gorm.Expr("NULL")})
	})
}

func TestWave2Content_4c66c27b96e8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4c66c27b96e8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("contactname", "X")
	})
}

func TestWave2Content_d9f1684bf3f5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d9f1684bf3f5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("contactphone", "X")
	})
}

func TestWave2Content_867f9cdc3507(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "867f9cdc3507", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("contactemail", "X")
	})
}

func TestWave2Content_0843893a3fbd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0843893a3fbd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("contacturl", "X")
	})
}

func TestWave2Content_bf8ac50709c6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bf8ac50709c6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("description", "X")
	})
}

func TestWave2Content_64fac395f93e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "64fac395f93e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("timecommitment", "X")
	})
}

// --- volunteering/volunteering.go: Update - action switch -------------------

func TestWave2Content_5eaf662c8a88(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5eaf662c8a88", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_groups").Where("volunteeringid = ? AND groupid = ?", 1, 2).Delete(nil)
	})
}

func TestWave2Content_2c08313624b0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2c08313624b0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_dates").Create(map[string]interface{}{
			"volunteeringid": 1,
			"start":          "2026-01-01",
			"end":            "2026-01-02",
			"applyby":        "2026-01-01",
		})
	})
}

func TestWave2Content_efa4579b0ab9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "efa4579b0ab9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_dates").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2Content_61789e80dec9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "61789e80dec9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_images").Where("id = ?", 1).Update("opportunityid", 2)
	})
}

// 31c33cd10585: golden sets both renewed = NOW() and expired = 0 as literals.
func TestWave2Content_31c33cd10585(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "31c33cd10585", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).
			Updates(map[string]interface{}{"renewed": gorm.Expr("NOW()"), "expired": gorm.Expr("0")})
	})
}

func TestWave2Content_ed33579ad786(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ed33579ad786", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("expired", gorm.Expr("1"))
	})
}

func TestWave2Content_d453c74c2969(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d453c74c2969", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("heldby", 2)
	})
}

func TestWave2Content_00a14dc95872(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "00a14dc95872", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).Update("heldby", gorm.Expr("NULL"))
	})
}

// --- volunteering/volunteering.go: Delete -----------------------------------

func TestWave2Content_15b7dd2cc0aa(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "15b7dd2cc0aa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Where("id = ?", 1).
			Updates(map[string]interface{}{"deleted": gorm.Expr("1"), "deletedby": 2})
	})
}

// --- aiimage/aiimage.go: Regenerate ------------------------------------------

func TestWave2Content_267affbdac01(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "267affbdac01", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Update("regeneration_notes", "X")
	})
}

func TestWave2Content_96a7eba3a018(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "96a7eba3a018", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Update("status", gorm.Expr("'regenerating'"))
	})
}

// 1eb5aa6248d2, b528277cdc9b and f8044e167f2b are the same statement at
// three call sites within Regenerate (the generation, duotone and upload
// error paths), converted together: a half-converted group renumbers the
// survivors' site IDs, so gate (h) refuses the split state.
func TestWave2Content_1eb5aa6248d2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1eb5aa6248d2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Update("status", gorm.Expr("'rejected'"))
	})
}

func TestWave2Content_b528277cdc9b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b528277cdc9b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Update("status", gorm.Expr("'rejected'"))
	})
}

func TestWave2Content_f8044e167f2b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f8044e167f2b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Update("status", gorm.Expr("'rejected'"))
	})
}

func TestWave2Content_94d2aaa6a692(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "94d2aaa6a692", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).
			Updates(map[string]interface{}{"pending_externaluid": "uid-1", "status": gorm.Expr("'regenerating'")})
	})
}

// --- aiimage/aiimage.go: Accept ----------------------------------------------

func TestWave2Content_2882b7e01f7c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2882b7e01f7c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Updates(map[string]interface{}{
			"externaluid":         "uid-1",
			"pending_externaluid": gorm.Expr("NULL"),
			"regeneration_notes":  gorm.Expr("NULL"),
			"status":              gorm.Expr("'active'"),
		})
	})
}

// 928518b21213, 9aec98330507 and b8c45eacd1d6 are the same statement at
// three call sites (Accept, KeepCurrent and Suppress), converted together.
func TestWave2Content_928518b21213(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "928518b21213", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Where("aiimageid = ? AND actiontype = 'AIImageReview'", 1).Delete(nil)
	})
}

func TestWave2Content_d36eef2965b0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d36eef2965b0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Where("externaluid = ?", "old-uid").Update("externaluid", "new-uid")
	})
}

// --- aiimage/aiimage.go: KeepCurrent ------------------------------------------

func TestWave2Content_8f3096d6a203(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8f3096d6a203", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Updates(map[string]interface{}{
			"pending_externaluid": gorm.Expr("NULL"),
			"regeneration_notes":  gorm.Expr("NULL"),
			"status":              gorm.Expr("'active'"),
		})
	})
}

func TestWave2Content_9aec98330507(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9aec98330507", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Where("aiimageid = ? AND actiontype = 'AIImageReview'", 1).Delete(nil)
	})
}

// --- aiimage/aiimage.go: Suppress ---------------------------------------------

func TestWave2Content_c7ce92f07464(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c7ce92f07464", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ?", 1).Updates(map[string]interface{}{
			"status":              gorm.Expr("'suppressed'"),
			"pending_externaluid": gorm.Expr("NULL"),
			"regeneration_notes":  gorm.Expr("NULL"),
		})
	})
}

func TestWave2Content_b8c45eacd1d6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b8c45eacd1d6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Where("aiimageid = ? AND actiontype = 'AIImageReview'", 1).Delete(nil)
	})
}

// --- microvolunteering/microvolunteering.go: getInviteChallenge ------------

// d4ce9c3f1fc1: golden sets version, comments and score_negative as
// literals (4, 'Ask to invite' and 0), so they go through gorm.Expr rather
// than as binds.
func TestWave2Content_d4ce9c3f1fc1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d4ce9c3f1fc1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Create(map[string]interface{}{
			"actiontype":     "Invite",
			"userid":         1,
			"version":        gorm.Expr("4"),
			"comments":       gorm.Expr("'Ask to invite'"),
			"score_negative": gorm.Expr("0"),
		})
	})
}

// --- microvolunteering/microvolunteering.go: RespondToChallenge (CheckMessage case) ---

// 0e09727e66aa: the WHERE clause's CONCAT(...) call is part of the query
// shape, not a value to bind, so it stays inline in the Where string
// alongside the one placeholder it does carry; seen = 1 is a literal.
func TestWave2Content_0e09727e66aa(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0e09727e66aa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").
			Where("touser = ? AND url LIKE CONCAT('/microvolunteering/message/', ?) AND type = 'Exhort'", 1, 2).
			Update("seen", gorm.Expr("1"))
	})
}

// --- microvolunteering/microvolunteering.go: ModFeedback --------------------

func TestWave2Content_c5c083c3dc6e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c5c083c3dc6e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Where("id = ?", 1).Updates(map[string]interface{}{
			"modfeedback":    "Looks good",
			"score_positive": 1.0,
			"score_negative": 0.0,
		})
	})
}

// --- microvolunteering/microvolunteering.go: SendForReviewAllGroups --------

func TestWave2Content_5092091807c2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5092091807c2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND collection = ?", 1, "Approved").
			Updates(map[string]interface{}{"collection": "Pending", "spamreason": "Members think there is something wrong with this message."})
	})
}

// --- microvolunteering/microvolunteering.go: FreezeReachIfOriginPending ----

// 328303c750b3: golden sets status = 'held' and next_expansion_at = NULL as
// literals, and the WHERE clause's status <> 'held' is a literal comparison
// rather than a bind, so it stays inline in the Where string.
func TestWave2Content_328303c750b3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "328303c750b3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_reach").Where("msgid = ? AND status <> 'held'", 1).
			Updates(map[string]interface{}{"status": gorm.Expr("'held'"), "next_expansion_at": gorm.Expr("NULL")})
	})
}

// --- microvolunteering/microvolunteering.go: ForceRejectAIImage / checkAIImageRejectQuorum ---

// 92faccbe5a21 and c1b4117a14d4 are the same statement at two call sites
// (ForceRejectAIImage and checkAIImageRejectQuorum), converted together.
func TestWave2Content_92faccbe5a21(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "92faccbe5a21", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ? AND status = 'active'", 1).Update("status", gorm.Expr("'rejected'"))
	})
}

func TestWave2Content_c1b4117a14d4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c1b4117a14d4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ? AND status = 'active'", 1).Update("status", gorm.Expr("'rejected'"))
	})
}

// --- microvolunteering/microvolunteering.go: checkAIImageSuppressQuorum ----

// d62b9f1b747c: golden's IN ('active','rejected') is a literal list, not
// placeholders, so collapseInLists leaves it untouched and it stays inline
// in the Where string exactly as written.
func TestWave2Content_d62b9f1b747c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d62b9f1b747c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ai_images").Where("id = ? AND status IN ('active','rejected')", 1).
			Update("status", gorm.Expr("'suppressed'"))
	})
}
