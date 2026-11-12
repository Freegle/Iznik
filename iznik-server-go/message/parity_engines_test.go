package message

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// Layer 2 of the parity harness: the same call gives the same rows on MySQL and on PostgreSQL.
// Skipped when an engine is not there. Ids start at parity.ID(1000) so these tests keep clear of
// the other packages' fixtures.

type loggedJoin struct {
	Type    string
	Subtype string
	Groupid uint64
	User    uint64
	Byuser  uint64
}

func parityGroup(n int) parity.Row {
	return parity.Row{"id": parity.ID(n), "polyindex": parity.Geometry("POINT(-3.2 55.9)")}
}

func TestParityResult_MyGroupIDs(t *testing.T) {
	fx := parity.Fixture{
		parity.Table("users", parity.Row{"id": parity.ID(1001)}, parity.Row{"id": parity.ID(1002)}),
		parity.Table("groups", parityGroup(1100), parityGroup(1101)),
		parity.Table("memberships",
			parity.Row{"id": parity.ID(1010), "userid": parity.ID(1001), "groupid": parity.ID(1100)},
			parity.Row{"id": parity.ID(1011), "userid": parity.ID(1001), "groupid": parity.ID(1101)},
			parity.Row{"id": parity.ID(1012), "userid": parity.ID(1002), "groupid": parity.ID(1100)},
		),
	}

	parity.AssertResultParity(t, "21a2d3732404", fx, func(db *gorm.DB) any {
		return myGroupIDs(db, parity.ID(1001))
	}, parity.Expect([]uint64{parity.ID(1100), parity.ID(1101)}))
}

func TestParityResult_ViewedMessageIDs(t *testing.T) {
	fx := parity.Fixture{
		parity.Table("users", parity.Row{"id": parity.ID(1001)}, parity.Row{"id": parity.ID(1002)}),
		parity.Table("messages",
			parity.Row{"id": parity.ID(1050), "fromuser": parity.ID(1002), "message": "x"},
			parity.Row{"id": parity.ID(1051), "fromuser": parity.ID(1002), "message": "x"},
			parity.Row{"id": parity.ID(1052), "fromuser": parity.ID(1002), "message": "x"},
		),
		parity.Table("messages_likes",
			parity.Row{"msgid": parity.ID(1050), "userid": parity.ID(1001), "type": "View"},
			parity.Row{"msgid": parity.ID(1051), "userid": parity.ID(1001), "type": "Love"},
			parity.Row{"msgid": parity.ID(1052), "userid": parity.ID(1002), "type": "View"},
		),
	}

	parity.AssertResultParity(t, "09f9c28c58ac", fx, func(db *gorm.DB) any {
		return viewedMessageIDs(db, []uint64{parity.ID(1050), parity.ID(1051), parity.ID(1052)}, parity.ID(1001), "View")
	}, parity.Expect(map[uint64]bool{parity.ID(1050): true}))
}

// The statement writes to logs.user, a word PostgreSQL reserves. The chain quotes it on both
// engines, and the read back quotes it the same way.
func TestParityResult_LogGroupJoined(t *testing.T) {
	fx := parity.Fixture{
		parity.Table("users", parity.Row{"id": parity.ID(1001)}),
		parity.Table("groups", parityGroup(1100)),
	}

	parity.AssertResultParity(t, "24ca594ef8a7", fx, func(db *gorm.DB) any {
		logGroupJoined(db, parity.ID(1100), parity.ID(1001))

		// Select with plain names on a table without a model writes them as they are, so a bare
		// user would read as PostgreSQL's current_user and answer "root". clause.Column quotes.
		//
		// Rows are read into a struct and not a map: MySQL's text protocol hands a map every column as
		// bytes, and a struct is scanned to the same types on both engines.
		cols := []clause.Column{{Name: "type"}, {Name: "subtype"}, {Name: "groupid"}, {Name: "user"}, {Name: "byuser"}}
		var rows []loggedJoin
		db.Table("logs").Clauses(clause.Select{Columns: cols}).
			Where(map[string]any{"user": parity.ID(1001)}).Find(&rows)
		return rows
	}, parity.Expect([]loggedJoin{{
		Type: "Group", Subtype: "Joined", Groupid: parity.ID(1100), User: parity.ID(1001), Byuser: parity.ID(1001),
	}}))
}
