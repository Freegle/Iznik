package test

// Research task: the "INSERT whose generated id is read back" keep-raw
// category included three sites inside chat/chatroom.go's
// GetOrCreateUser2ModChat that share one raw *sql.Tx (db.DB().Begin()): a
// SELECT ... FOR UPDATE, a following UPDATE (existing-chat path), and a
// following INSERT (new-chat path) whose sql.Result.LastInsertId() reports
// the id the lock protected. The keep-raw reason argued that converting any
// one of the three in isolation would either escape the lock's transaction
// or force the whole function onto db.Transaction() as a "per-site
// redesign, not a mechanical conversion".
//
// That turned out to be overstated: db.Transaction(func(tx *gorm.DB) error)
// is exactly the mechanical replacement. gorm.io/plugin/dbresolver's
// per-statement routing callbacks (switchSource/switchReplica/switchGuess)
// all no-op once db.Statement.ConnPool is already a transaction (its
// isTransaction check), so every statement run via the callback's tx -
// clause.Locking{Strength: "UPDATE"} for the lock, a plain Update for the
// UPDATE, gorm.WithResult() via .Clauses(res) for the INSERT, the same
// idiom the other 11 id-read-back sites in this codebase already use - stays
// on the ONE connection db.Transaction()'s Begin() opened. That connection
// is always the source/write host: dbresolver only reroutes Query/Create/
// Update/Delete/Raw callbacks, never Begin() itself. Confirmed empirically
// against two real, distinguishable MySQL hosts (a throwaway "source" and
// "replica" pair) before converting - not just reasoned from GORM's source.
//
// See chat/chatroom.go's GetOrCreateUser2ModChat for the converted function.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

func TestInsertIdReadback_65fde41159df(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "65fde41159df", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").
			Clauses(clause.Locking{Strength: "UPDATE"}).
			Select("id").
			Where("user1 = ? AND groupid = ? AND chattype = ?", 1, 2, 3).
			Find(&dest)
	})
}

func TestInsertIdReadback_2451a0b54d63(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2451a0b54d63", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("latestmessage", gorm.Expr("NOW()"))
	})
}

func TestInsertIdReadback_69ed53a55edc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "69ed53a55edc", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("chat_rooms").Clauses(res).Create(map[string]interface{}{
			"user1": 1, "groupid": 2, "chattype": 3, "latestmessage": gorm.Expr("NOW()"),
		})
	})
}
