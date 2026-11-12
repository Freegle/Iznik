package message

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"gorm.io/gorm"
)

// Layer 1 of the parity harness: each converted site renders the SQL it had as raw text.
// These need no database. docs/developers/reference/parity-harness.md says how to add a site.

func TestParityGolden_MyGroupIDs(t *testing.T) {
	var ids []uint64
	parity.AssertGoldenSQL(t, "21a2d3732404", func(tx *gorm.DB) *gorm.DB {
		return myGroupIDsQuery(tx, 5).Find(&ids)
	})
}

func TestParityGolden_ViewedMessageIDs(t *testing.T) {
	var ids []uint64
	parity.AssertGoldenSQL(t, "09f9c28c58ac", func(tx *gorm.DB) *gorm.DB {
		return viewedMessageIDsQuery(tx, []uint64{1, 2, 3}, 5, "View").Find(&ids)
	})
}

func TestParityGolden_LogGroupJoined(t *testing.T) {
	parity.AssertGoldenSQL(t, "24ca594ef8a7", func(tx *gorm.DB) *gorm.DB {
		return logGroupJoined(tx, 10, 5)
	})
}

// The PostgreSQL form of the same chains. The canonicaliser strips quotes, so this does not show
// that "user" is quoted; TestParityResult_LogGroupJoined does, by running it.
func TestParityDialect_PostgresForms(t *testing.T) {
	var ids []uint64
	parity.AssertDialectSQL(t, parity.Postgres,
		"SELECT msgid FROM messages_likes WHERE userid = ? AND type = ? AND msgid IN (?)",
		func(tx *gorm.DB) *gorm.DB { return viewedMessageIDsQuery(tx, []uint64{1, 2, 3}, 5, "View").Find(&ids) })

	parity.AssertDialectSQL(t, parity.Postgres,
		"INSERT INTO logs (timestamp, type, subtype, groupid, user, byuser) VALUES (NOW(), ?, ?, ?, ?, ?)",
		func(tx *gorm.DB) *gorm.DB { return logGroupJoined(tx, 10, 5) })
}
