package test

// Tier 2 of the keep-raw adversarial review, batch 2: chat/chatmessage.go's
// bare-EXISTS/UNION sites (BuildClauses={"SELECT"}, same mechanism as
// orm_tier2_test.go), plus the three genuine multi-table UPDATE...JOIN sites
// in session/merge.go and user/user.go handleMerge (Table()-verbatim JOIN
// text + explicit clause.Set, proven in
// ormharness/updatejoin_replace_test.go).
//
// These touch files chat/chatmessage.go and user/user.go, which an earlier
// wave's brief marked as another agent's exclusive territory. Converted
// anyway on this explicit Tier 2 assignment, after checking git status
// showed no live uncommitted work in either file at the time of editing
// (message/message.go DID show a live uncommitted diff and was left alone
// for that reason - see the report to team-lead).

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- chat/chatmessage.go: recordReplyAttribution (6 bare-EXISTS sites) -----

func TestTier2_848af7d73bfe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "848af7d73bfe", func(tx *gorm.DB) *gorm.DB {
		var dest int
		tx = tx.Table("messages_groups").Select(
			"EXISTS(SELECT 1 FROM messages_groups mg "+
				"INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ? "+
				"AND mem.collection = ? AND mem.added < NOW() - INTERVAL 300 SECOND "+
				"WHERE mg.msgid = ? AND mg.rippled_in = 0 AND mg.deleted = 0)",
			1, "Approved", 2)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier2_582f5b4bb7ce(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "582f5b4bb7ce", func(tx *gorm.DB) *gorm.DB {
		var dest int
		tx = tx.Table("rippling_reach_notified").Select(
			"EXISTS(SELECT 1 FROM rippling_reach_notified WHERE msgid = ? AND userid = ?)", 1, 2)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier2_fc0c6fd4f6df(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fc0c6fd4f6df", func(tx *gorm.DB) *gorm.DB {
		var dest int
		tx = tx.Table("messages_groups").Select(
			"EXISTS(SELECT 1 FROM messages_groups mg "+
				"INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ? "+
				"AND mem.collection = ? AND mem.added < mg.arrival "+
				"WHERE mg.msgid = ? AND mg.rippled_in = 1 AND mg.deleted = 0)",
			1, "Approved", 2)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier2_461f55d25b16(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "461f55d25b16", func(tx *gorm.DB) *gorm.DB {
		var dest int
		tx = tx.Table("messages_groups").Select(
			"EXISTS(SELECT 1 FROM messages_groups WHERE msgid = ? AND rippled_in = 1 AND deleted = 0)", 1)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier2_a4250aa0ada3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a4250aa0ada3", func(tx *gorm.DB) *gorm.DB {
		var dest int
		tx = tx.Table("rippling_reach").Select(
			"EXISTS(SELECT 1 FROM rippling_reach WHERE msgid = ?)", 1)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier2_4fc47623d055(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4fc47623d055", func(tx *gorm.DB) *gorm.DB {
		var dest int
		tx = tx.Table("messages_groups").Select(
			"EXISTS(SELECT 1 FROM messages_groups mg "+
				"INNER JOIN `groups` g ON g.id = mg.groupid "+
				"WHERE mg.msgid = ? AND mg.rippled_in = 0 AND mg.deleted = 0 "+
				"AND g.polyindex IS NOT NULL AND ST_GeometryType(g.polyindex) <> 'POINT' "+
				"AND ST_Contains(g.polyindex, ST_SRID(POINT(?, ?), ?)))",
			1, 0.1, 51.5, 4326)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

// --- chat/chatmessage.go: CreateChatMessage (1 UNION + 1 bare-EXISTS) ------

func TestTier2_33ad97a3417c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "33ad97a3417c", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		tx = tx.Table("chat_rooms").Select(
			"id FROM chat_rooms WHERE id = ? AND user1 = ? "+
				"UNION SELECT id FROM chat_rooms WHERE id = ? AND user2 = ? "+
				"UNION SELECT cr.id FROM chat_rooms cr "+
				"INNER JOIN memberships m ON m.groupid = cr.groupid AND m.userid = ? AND m.role IN (?, ?) "+
				"WHERE cr.id = ? AND cr.chattype = ?",
			1, 2, 1, 2, 2, "Moderator", "Owner", 1, "User2Mod")
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier2_a74101bfbfa2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a74101bfbfa2", func(tx *gorm.DB) *gorm.DB {
		var dest int
		tx = tx.Table("messages").Select(
			"EXISTS(SELECT 1 FROM messages WHERE id = ? AND deleted IS NULL)", 1)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

// --- session/merge.go: mergeChatRooms (genuine UPDATE...JOIN) --------------

func TestTier2_bf1cd8bf4627(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bf1cd8bf4627", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms surv JOIN chat_rooms lose ON lose.id = ?", 1).
			Clauses(clause.Set{
				{Column: clause.Column{Table: "surv", Name: "latestmessage"},
					Value: clause.Column{Table: "lose", Name: "latestmessage"}},
			}).
			Where("surv.id = ? AND lose.latestmessage IS NOT NULL AND (surv.latestmessage IS NULL OR surv.latestmessage < lose.latestmessage)", 2).
			Updates(map[string]interface{}{})
	})
}

// --- user/user.go: handleMerge (2 genuine UPDATE...JOIN, LEAST() twins) ----

func TestTier2_a79f147726d0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a79f147726d0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships m2 JOIN memberships m1 ON m1.userid = ? AND m1.groupid = m2.groupid", 1).
			Clauses(clause.Set{
				{Column: clause.Column{Table: "m2", Name: "added"}, Value: gorm.Expr("LEAST(m2.added, m1.added)")},
			}).
			Where("m2.userid = ? AND m2.groupid = ?", 2, 3).
			Updates(map[string]interface{}{})
	})
}

func TestTier2_5b72a0acad8e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5b72a0acad8e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users u2 JOIN users u1 ON u1.id = ?", 1).
			Clauses(clause.Set{
				{Column: clause.Column{Table: "u2", Name: "added"}, Value: gorm.Expr("LEAST(u2.added, u1.added)")},
			}).
			Where("u2.id = ?", 2).
			Updates(map[string]interface{}{})
	})
}

// --- user/user.go: handleMerge's 41-statement "simpleUpdates" batch --------
// (site d5ecca066b1b). Never actually runtime-varying - every statement runs
// unconditionally, so the golden.go extractor's "{{built at runtime: u.sql}}"
// was an artefact of a slice+loop shape, not real dynamism. Each of the 41
// is its own declared shape for this one site id.

func TestTier2_d5ecca066b1b(t *testing.T) {
	entries := []struct {
		name     string
		table    string
		ignore   bool
		setCol   string
		whereCol string
	}{
		{"locations_excluded.userid", "locations_excluded", false, "userid", "userid"},
		{"spam_users.userid", "spam_users", true, "userid", "userid"},
		{"spam_users.byuserid", "spam_users", true, "byuserid", "byuserid"},
		{"users_addresses.userid", "users_addresses", true, "userid", "userid"},
		{"users_comments.userid", "users_comments", false, "userid", "userid"},
		{"users_comments.byuserid", "users_comments", false, "byuserid", "byuserid"},
		{"users_donations.userid", "users_donations", true, "userid", "userid"},
		{"users_images.userid", "users_images", true, "userid", "userid"},
		{"users_invitations.userid", "users_invitations", true, "userid", "userid"},
		{"users_nearby.userid", "users_nearby", true, "userid", "userid"},
		{"users_notifications.fromuser", "users_notifications", true, "fromuser", "fromuser"},
		{"users_notifications.touser", "users_notifications", true, "touser", "touser"},
		{"users_nudges.fromuser", "users_nudges", true, "fromuser", "fromuser"},
		{"users_nudges.touser", "users_nudges", true, "touser", "touser"},
		{"users_push_notifications.userid", "users_push_notifications", true, "userid", "userid"},
		{"users_requests.userid", "users_requests", true, "userid", "userid"},
		{"users_requests.completedby", "users_requests", true, "completedby", "completedby"},
		{"users_searches.userid", "users_searches", true, "userid", "userid"},
		{"newsfeed.userid", "newsfeed", true, "userid", "userid"},
		{"messages_reneged.userid", "messages_reneged", true, "userid", "userid"},
		{"users_stories.userid", "users_stories", true, "userid", "userid"},
		{"users_stories_likes.userid", "users_stories_likes", true, "userid", "userid"},
		{"users_stories_requested.userid", "users_stories_requested", true, "userid", "userid"},
		{"users_thanks.userid", "users_thanks", true, "userid", "userid"},
		{"modnotifs.userid", "modnotifs", true, "userid", "userid"},
		{"teams_members.userid", "teams_members", true, "userid", "userid"},
		{"users_aboutme.userid", "users_aboutme", true, "userid", "userid"},
		{"ratings.rater", "ratings", true, "rater", "rater"},
		{"ratings.ratee", "ratings", true, "ratee", "ratee"},
		{"users_replytime.userid", "users_replytime", true, "userid", "userid"},
		{"messages_promises.userid", "messages_promises", true, "userid", "userid"},
		{"messages_by.userid", "messages_by", true, "userid", "userid"},
		{"trysts.user1", "trysts", true, "user1", "user1"},
		{"trysts.user2", "trysts", true, "user2", "user2"},
		{"isochrones_users.userid", "isochrones_users", true, "userid", "userid"},
		{"microactions.userid", "microactions", true, "userid", "userid"},
		{"volunteering.userid", "volunteering", false, "userid", "userid"},
		{"volunteering.deletedby", "volunteering", false, "deletedby", "deletedby"},
		{"volunteering.heldby", "volunteering", false, "heldby", "heldby"},
		{"communityevents.userid", "communityevents", false, "userid", "userid"},
		{"communityevents.heldby", "communityevents", false, "heldby", "heldby"},
	}

	shapes := make([]ormharness.Shape, len(entries))
	for i, e := range entries {
		e := e // pin for the closure
		shapes[i] = ormharness.Shape{
			Name: e.name,
			Build: func(tx *gorm.DB) *gorm.DB {
				if e.ignore {
					return tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table(e.table).Where(e.whereCol+" = ?", 1).Update(e.setCol, 2)
				}
				return tx.Table(e.table).Where(e.whereCol+" = ?", 1).Update(e.setCol, 2)
			},
		}
	}

	ormharness.AssertGoldenShapes(t, "d5ecca066b1b", shapes)
}
