package test

// Tier 2 of the keep-raw adversarial review (plans/active/orm-keepraw-
// adversarial-review.md): "apply a technique that already has a passing
// test in this repo" sites. Only the sites in files NOT owned by another
// wave's exclusive file set (chat/chatmessage.go, chat/chatroom.go,
// message/message.go, location/location.go, user/user.go,
// emailtracking/emailtracking.go) are converted here - the review's Tier 2
// list includes 20+ sites in those files, flagged back to team-lead rather
// than touched, since those files have other agents actively committing to
// them (see the recent LAST_INSERT_ID / insert-id commits on this branch).
//
// Two techniques, both proven elsewhere before being applied here:
//
//  1. Statement.BuildClauses = []string{"SELECT"} suppresses GORM's
//     automatic FROM injection (ormharness/bareexists_test.go proves the
//     mechanism against a synthetic statement). Used for bare SELECT
//     EXISTS(...)/ST_IsValid(...) with no top-level FROM, and for top-level
//     UNION statements with nothing wrapping them - the entire "SELECT ...
//     [UNION SELECT ...]" text goes to .Select() as one fragment, since no
//     other clause (FROM, ORDER BY) will be walked once BuildClauses is
//     restricted.
//  2. Per-field .Table().Where().Update(col, val), where col is a per-call
//     Go string constant (never caller-controlled) - already shipped in
//     message/helper.go itself (helper_batches "automode", line ~461)
//     before this file's closures adopted it. Tested via AssertGoldenShapes
//     because each closure is one call site with several fixed possible
//     column names, exactly the "shape" concept that mechanism exists for.
//
// Every test here would fail against the pre-conversion form: dropping the
// BuildClauses override reintroduces GORM's automatic FROM (proven by
// ormharness/bareexists_test.go's own control case), and a
// bad column name in the per-field Update sites renders a different SET
// target, which AssertGoldenShapes's underlying Canonical() comparison
// catches exactly like any other genuine mismatch.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- amp/amp.go: ValidateToken, validateBodyToken (bare EXISTS) ------------

func TestTier2_8ec21db34aa4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8ec21db34aa4", func(tx *gorm.DB) *gorm.DB {
		var dest bool
		tx = tx.Table("users").Select("EXISTS(SELECT 1 FROM users WHERE id = ?)", 1)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier2_faf666d9cb13(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "faf666d9cb13", func(tx *gorm.DB) *gorm.DB {
		var dest bool
		tx = tx.Table("users").Select("EXISTS(SELECT 1 FROM users WHERE id = ?)", 1)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

// --- changes/changes.go: GetChanges (top-level UNION, 6 arms) --------------

func TestTier2_19b190d43a85(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "19b190d43a85", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		tx = tx.Table("messages").Select(
			"id, deleted AS timestamp, 'Deleted' AS `type` FROM messages WHERE deleted > ? "+
				"UNION SELECT msgid AS id, timestamp, outcome AS `type` FROM messages_outcomes WHERE timestamp > ? "+
				"UNION SELECT messages_edits.msgid AS id, timestamp, 'Edited' AS `type` FROM messages_edits "+
				"INNER JOIN messages_groups ON messages_groups.msgid = messages_edits.msgid AND collection = ? WHERE timestamp > ? "+
				"UNION SELECT msgid AS id, promisedat AS timestamp, 'Promised' AS `type` FROM messages_promises WHERE promisedat > ? "+
				"UNION SELECT msgid AS id, timestamp, 'Reneged' AS `type` FROM messages_reneged WHERE timestamp > ? "+
				"UNION SELECT msgid AS id, arrival AS timestamp, 'ApprovedOrReposted' AS `type` FROM messages_groups "+
				"WHERE messages_groups.arrival > ? AND messages_groups.collection = ?",
			"2026-01-01", "2026-01-01", 1, "2026-01-01", "2026-01-01", "2026-01-01", "2026-01-01", 1)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

// --- modconfig/modconfig.go: listModConfigs (top-level UNION, 3 arms + ORDER BY) -

func TestTier2_e9ea468dab80(t *testing.T) {
	const cols = "id, name, createdby, fromname, ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, " +
		"ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, protected, messageorder, network, " +
		"coloursubj, subjreg, subjlen, `default`, chatread"

	ormharness.AssertGoldenSQL(t, "e9ea468dab80", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		tx = tx.Table("mod_configs").Select(
			cols+" FROM mod_configs WHERE createdby = ? "+
				"UNION "+
				"SELECT "+cols+" FROM mod_configs WHERE `default` = 1 "+
				"UNION "+
				"SELECT "+cols+" FROM mod_configs WHERE id IN ("+
				"SELECT m1.configid FROM memberships m1 "+
				"WHERE m1.configid IS NOT NULL AND m1.role IN (?, ?) "+
				"AND m1.groupid IN ("+
				"SELECT m2.groupid FROM memberships m2 "+
				"WHERE m2.userid = ? AND m2.role IN (?, ?)"+
				")"+
				") "+
				"ORDER BY name",
			1, "Moderator", "Owner", 1, "Moderator", "Owner")
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

// --- group/group.go: validateGeometry (bare scalar, no FROM at all) --------

func TestTier2_6d0982e798b5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6d0982e798b5", func(tx *gorm.DB) *gorm.DB {
		var dest map[string]interface{}
		tx = tx.Table("groups").Select("ST_IsValid(ST_GeomFromText(?))", "POINT(0 0)")
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

// --- message/helper.go: helperUpsertReplier's per-field "set" closure ------
// (site 7ba4875e8aec) - 16 possible columns, each its own declared shape.

func TestTier2_7ba4875e8aec(t *testing.T) {
	cols := []string{
		"chatid", "state", "collection_ok", "criteria_met", "transport_ok",
		"distance_miles", "is_connector", "related_to", "escalation_reason",
		"parked_reason", "next_action", "other_items_mentioned", "cooldown_until",
		"offerer_last_message_at", "last_processed_chatmsgid", "knowledge",
	}

	shapes := make([]ormharness.Shape, len(cols))
	for i, col := range cols {
		col := col // pin for the closure
		shapes[i] = ormharness.Shape{
			Name: col,
			Build: func(tx *gorm.DB) *gorm.DB {
				return tx.Table("helper_repliers").Where("id = ?", 1).Update(col, "x")
			},
		}
	}

	ormharness.AssertGoldenShapes(t, "7ba4875e8aec", shapes)
}

// --- message/helper.go: helperSetItemState's per-field "set" closure -------
// (site b48b319835d0) - 5 possible columns, each its own declared shape.

func TestTier2_b48b319835d0(t *testing.T) {
	cols := []string{"state", "qty_wanted", "qty_allocated", "score", "score_breakdown"}

	shapes := make([]ormharness.Shape, len(cols))
	for i, col := range cols {
		col := col // pin for the closure
		shapes[i] = ormharness.Shape{
			Name: col,
			Build: func(tx *gorm.DB) *gorm.DB {
				return tx.Table("helper_item_states").Where("id = ?", 1).Update(col, "x")
			},
		}
	}

	ormharness.AssertGoldenShapes(t, "b48b319835d0", shapes)
}
