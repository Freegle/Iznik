package test

// Wave 2 (single-table writes), tail batch (plan section 7.3+): the last 26
// files with raw wave-2 sites remaining after the earlier content and
// chat/group batches. Covers user addresses (address/address.go), moderator
// alerts (alert/alert.go), AMP email actions (amp/amp.go), user merges
// (merge/merge.go), trysts (tryst/tryst.go), teams (team/team.go), member
// comments (comment/comment.go), isochrones (isochrone/isochrone.go), spam
// reports (spammers/spammers.go), notifications (notification/notification.go),
// traffic-source tracking (src/src.go), the background task queue
// (queue/queue.go), image attachment ratings (image/image.go), shortlink click
// counting (shortlink/shortlinkhttp.go), housekeeper task completion
// (housekeeper/housekeeper.go), browse scroll-depth tracking
// (browse/scroll.go), and a location rename (location/location.go).
//
// Write conventions, same as the rest of wave 2:
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed and
//     a soft-delete field cannot silently turn a DELETE into an UPDATE.
//   - gorm.Expr(...) for a literal or expression value (NOW(), a bare 0/1, a
//     quoted string literal, clicks + 1, or COALESCE(...)) rather than a
//     plain Go value, which would bind as "?" and diverge from a golden that
//     writes the literal/expression inline.
//   - Table(...).Create(map[string]interface{}{...}) for INSERTs that do not
//     need the new row's id.
//
// New for this batch: check-set-order.sh (plan gate (i)). GORM sorts
// Updates(map[...]) keys alphabetically, which is invisible to the golden-SQL
// harness (normaliseColumnOrder sorts both sides), but matters when one
// assignment's expression names another assigned column - MySQL evaluates a
// SET list left to right, so the order changes what gets written. One site
// was left raw for exactly this reason: spammers.go's PatchSpammer
// (2c2dda80b557) sets heldat from a CASE expression whose placeholder is
// bound from the same `heldby` Go variable as the heldby column's own
// assignment; check-set-order.sh's textual scan treats that as a
// cross-reference, and the statement's original SET order (collection,
// reason, byuserid, heldby, heldat) is not what GORM's alphabetical sort
// would produce, so the check does not clear it. Reported to the batch owner
// rather than converted or worked around.
//
// Sites left raw because the INSERT exists specifically to call
// sql.Result.LastInsertId() on the same connection that ran it (this batch's
// files each had at most one, already covered by the reasoning in
// orm_wave2_content_test.go and orm_wave2_chatgroup_test.go for the sites
// that repeat across batches): alert.go's PostAlert (54e869591bc4), amp.go's
// PostChatReply (58cb0a225ebe), merge.go's CreateMerge (4d2e18cf27e3),
// team.go's CreateTeam (02f8f0aee316), comment.go's Create (40de8b0d3f98),
// message/helper.go's helperCreateProposal/insertHelperChat/helperSendAction
// (7394291c903d, d3790f53ec52, 7ec60cde5d39), message/bulkItem.go's
// setBulkItems/ingestBulkItemPhotos (faa9018435a3, fb88302d28cb),
// message/message.go's JoinAndPost (1590b130f529), volunteering.go's Create
// (0adadbabde5b), stdmsg.go's PostStdMsg (132b1f639e73), chat/chatroom.go's
// handleNudge (1f5798e8214d), shortlink.go's Create (322b611c86cc),
// communityEvent.go's Create (45b8b0bc2060), and story.go's CreateStory
// (f6190b74d8d5).
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- address/address.go: Update ---------------------------------------------

func TestWave2Tail_8d9bcc14ce4f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8d9bcc14ce4f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Where("id = ?", 1).Update("instructions", "X")
	})
}

func TestWave2Tail_47ad28590287(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "47ad28590287", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Where("id = ?", 1).Update("lat", 51.5)
	})
}

func TestWave2Tail_339b4832d0ee(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "339b4832d0ee", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Where("id = ?", 1).Update("lng", -0.1)
	})
}

func TestWave2Tail_34929e307f19(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "34929e307f19", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Where("id = ?", 1).Update("pafid", 2)
	})
}

// --- address/address.go: Delete ---------------------------------------------

func TestWave2Tail_57a5e9a51661(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "57a5e9a51661", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Where("id = ?", 1).Delete(nil)
	})
}

// --- alert/alert.go: RecordAlert ---------------------------------------------

func TestWave2Tail_053433aae0e7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "053433aae0e7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("alerts_tracking").Where("id = ?", 1).
			Updates(map[string]interface{}{"responded": gorm.Expr("NOW()"), "response": gorm.Expr("'Clicked'")})
	})
}

// --- amp/amp.go: recordAmpReplyTracking -------------------------------------

func TestWave2Tail_44deaf6c0e25(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "44deaf6c0e25", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking").Where("id = ?", 1).
			Updates(map[string]interface{}{"replied_at": gorm.Expr("NOW()"), "replied_via": gorm.Expr("'amp'")})
	})
}

func TestWave2Tail_83bf328b1b50(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "83bf328b1b50", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking_clicks").Create(map[string]interface{}{
			"email_tracking_id": 1,
			"link_url":          "amp://reply",
			"link_position":     "amp_reply_form",
			"action":            gorm.Expr("'amp_reply'"),
			"clicked_at":        gorm.Expr("NOW()"),
		})
	})
}

// --- amp/amp.go: GetChatMessages ---------------------------------------------

// 7c20a8b4293a: opened_at's COALESCE(opened_at, NOW()) references its OWN
// column, not another key in the same map, so it is order-independent -
// check-set-order.sh only flags a value that names a DIFFERENT assigned key.
func TestWave2Tail_7c20a8b4293a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7c20a8b4293a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("email_tracking").Where("id = ?", 1).Updates(map[string]interface{}{
			"opened_at":  gorm.Expr("COALESCE(opened_at, NOW())"),
			"opened_via": gorm.Expr("'amp'"),
		})
	})
}

// --- amp/amp.go: PostChatReply / processDigestReply -------------------------

// 0a874cdbfabf and 6e0285470b75 are the same statement at two call sites
// (PostChatReply and processDigestReply), converted together: gate (h)
// refuses a half-converted pair, because converting one renumbers the
// survivor's site ID.
func TestWave2Tail_0a874cdbfabf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0a874cdbfabf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("latestmessage", gorm.Expr("NOW()"))
	})
}

func TestWave2Tail_6e0285470b75(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6e0285470b75", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("latestmessage", gorm.Expr("NOW()"))
	})
}

// --- merge/merge.go: CreateMerge / DeleteMerge -------------------------------

// 6c13f84896c6 and f1b4641550c1 are the same statement at two call sites
// (CreateMerge and DeleteMerge), converted together.
func TestWave2Tail_6c13f84896c6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6c13f84896c6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_related").
			Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", 1, 2, 2, 1).
			Update("notified", gorm.Expr("1"))
	})
}

func TestWave2Tail_f1b4641550c1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f1b4641550c1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_related").
			Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", 1, 2, 2, 1).
			Update("notified", gorm.Expr("1"))
	})
}

// --- merge/merge.go: PostMerge ------------------------------------------------

func TestWave2Tail_2b580119e4e3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2b580119e4e3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("merges").Where("id = ?", 1).Update("accepted", gorm.Expr("NOW()"))
	})
}

func TestWave2Tail_a4b133b08878(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a4b133b08878", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("merges").Where("id = ?", 1).Update("rejected", gorm.Expr("NOW()"))
	})
}

// --- tryst/tryst.go: PatchTryst -----------------------------------------------

func TestWave2Tail_31ca1c3e3e7a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "31ca1c3e3e7a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Update("arrangedfor", "2026-01-01 12:00:00")
	})
}

// --- tryst/tryst.go: PostTryst -------------------------------------------------

func TestWave2Tail_a692d161ad11(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a692d161ad11", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Update("user1confirmed", gorm.Expr("NOW()"))
	})
}

func TestWave2Tail_e7862c3da563(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e7862c3da563", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Update("user2confirmed", gorm.Expr("NOW()"))
	})
}

func TestWave2Tail_4d017739e5ae(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4d017739e5ae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Update("user1declined", gorm.Expr("NOW()"))
	})
}

func TestWave2Tail_b6d802da0733(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b6d802da0733", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Update("user2declined", gorm.Expr("NOW()"))
	})
}

// --- team/team.go: PatchTeam - update team attributes ------------------------

func TestWave2Tail_e3656e6c44b5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e3656e6c44b5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Where("id = ?", 1).Update("description", "X")
	})
}

func TestWave2Tail_f33669b723a8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f33669b723a8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Where("id = ?", 1).Update("wikiurl", "https://example.org")
	})
}

func TestWave2Tail_c3c9a6a6b11f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c3c9a6a6b11f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams_members").Where("userid = ? AND teamid = ?", 1, 2).Delete(nil)
	})
}

// --- comment/comment.go: flagOthers -------------------------------------------

func TestWave2Tail_eda36bc0f75a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "eda36bc0f75a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("groupid = ? AND userid = ?", 1, 2).
			Updates(map[string]interface{}{"reviewreason": "X", "reviewrequestedat": "2026-01-01 12:00"})
	})
}

// --- comment/comment.go: Edit -------------------------------------------------

// 408704240109: the flag column's value references "flag" - itself, the same
// key it is assigned to - not a DIFFERENT assigned column, so it is
// order-independent under check-set-order.sh's same-key exclusion.
func TestWave2Tail_408704240109(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "408704240109", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_comments").Where("id = ?", 1).Updates(map[string]interface{}{
			"user1":    "a",
			"user2":    "b",
			"user3":    "c",
			"user4":    "d",
			"user5":    "e",
			"user6":    "f",
			"user7":    "g",
			"user8":    "h",
			"user9":    "i",
			"user10":   "j",
			"user11":   "k",
			"flag":     gorm.Expr("COALESCE(?, flag)", true),
			"byuserid": 2,
			"reviewed": gorm.Expr("NOW()"),
		})
	})
}

// --- comment/comment.go: Delete -----------------------------------------------

func TestWave2Tail_c7d4d6e14e0d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c7d4d6e14e0d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_comments").Where("id = ?", 1).Delete(nil)
	})
}

// --- isochrone/isochrone.go: HealPointIsochrones / EditIsochrone ------------

// efe4a0639f44 and ba1bbcbc0b5d are the same statement at two call sites
// (HealPointIsochrones and EditIsochrone), converted together.
func TestWave2Tail_efe4a0639f44(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "efe4a0639f44", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Where("id = ?", 1).Update("isochroneid", 2)
	})
}

func TestWave2Tail_ba1bbcbc0b5d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ba1bbcbc0b5d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Where("id = ?", 1).Update("isochroneid", 2)
	})
}

// --- isochrone/isochrone.go: EditIsochrone (duplicate cleanup) / DeleteIsochrone ---

// 53769722a855 and eddf7acf7d4d are the same statement at two call sites
// (EditIsochrone's duplicate-key cleanup and DeleteIsochrone), converted
// together.
func TestWave2Tail_53769722a855(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "53769722a855", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2Tail_eddf7acf7d4d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "eddf7acf7d4d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Where("id = ?", 1).Delete(nil)
	})
}

// --- spammers/spammers.go: PostSpammer ----------------------------------------

func TestWave2Tail_284c8dddea5c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "284c8dddea5c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ? AND systemrole = ?", 1, "User").
			Update("newsfeedmodstatus", "Suppressed")
	})
}

// --- spammers/spammers.go: DeleteSpammer --------------------------------------

func TestWave2Tail_cd86450dea5a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cd86450dea5a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("id = ?", 1).Delete(nil)
	})
}

// --- notification/notification.go: Seen / AllSeen ----------------------------

func TestWave2Tail_44a7f7cc40fc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "44a7f7cc40fc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Where("touser = ? AND id = ?", 1, 2).
			Update("seen", gorm.Expr("1"))
	})
}

func TestWave2Tail_be340be71e2b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "be340be71e2b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Where("touser = ?", 1).Update("seen", gorm.Expr("1"))
	})
}

// --- src/src.go: recordSource -------------------------------------------------

func TestWave2Tail_0e7a8f66e4c4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0e7a8f66e4c4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs_src").Create(map[string]interface{}{
			"src":     "campaign",
			"userid":  1,
			"session": "sess-1",
		})
	})
}

func TestWave2Tail_352b3c03f2f7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "352b3c03f2f7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ? AND source IS NULL", 1).Update("source", "campaign")
	})
}

// --- queue/queue.go: QueueTask -------------------------------------------------

func TestWave2Tail_1a41e1c07178(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1a41e1c07178", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_merge",
			"data":      "{}",
		})
	})
}

// --- image/image.go: Post (raterecognise) -------------------------------------

func TestWave2Tail_30517f1cbffd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "30517f1cbffd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments_recognise").Where("attid = ?", 1).Update("rating", "5")
	})
}

// --- shortlink/shortlinkhttp.go: RedirectShortlink -----------------------------

func TestWave2Tail_a2ca92d61766(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a2ca92d61766", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlinks").Where("id = ?", 1).Update("clicks", gorm.Expr("clicks + 1"))
	})
}

// --- housekeeper/housekeeper.go: CompleteTask -----------------------------------

func TestWave2Tail_aae1a88f63f1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "aae1a88f63f1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("housekeeper_tasks").Where("task_key = ? AND placeholder = 1", "x").
			Updates(map[string]interface{}{
				"last_run_at":  gorm.Expr("NOW()"),
				"last_status":  gorm.Expr("'success'"),
				"last_summary": gorm.Expr("'Marked done manually'"),
				"updated_at":   gorm.Expr("NOW()"),
			})
	})
}

// --- browse/scroll.go: RecordScrollDepth (legacy, no session) -----------------

func TestWave2Tail_b90542fe5559(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b90542fe5559", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("browse_scroll_depth").Create(map[string]interface{}{
			"userid":          1,
			"max_position":    5,
			"items_available": 10,
			"context":         "browse",
		})
	})
}

// --- location/location.go: Update (name/canon) ----------------------------------

func TestWave2Tail_cf7be3980e03(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cf7be3980e03", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Where("id = ?", 1).
			Updates(map[string]interface{}{"name": "X", "canon": "x"})
	})
}

// --- spammers/spammers.go: PatchSpammer -------------------------------------

// This one was left raw by the batch on the strength of a false positive from
// check-set-order.sh. The "?" inside the heldat CASE is a bind fed from a Go
// variable named heldby, not a reference to the heldby column being assigned
// alongside it, so the SET order is not load-bearing and GORM's alphabetical
// sort is harmless. The checker was scanning gorm.Expr's bind arguments as well
// as its SQL; it now scans only the SQL literal.
func TestWave2Tail_2c2dda80b557(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2c2dda80b557", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("id = ?", 1).
			Updates(map[string]interface{}{
				"collection": "Spammer",
				"reason":     "because",
				"byuserid":   1,
				"heldby":     1,
				"heldat":     gorm.Expr("CASE WHEN ? IS NOT NULL THEN NOW() ELSE NULL END", 1),
			})
	})
}
