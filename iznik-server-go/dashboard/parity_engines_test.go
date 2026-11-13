package dashboard

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// Layer 2 of the parity harness. Ids start at parity.ID(2000).
func TestParityResult_RepliesForMessages(t *testing.T) {
	fx := parity.Fixture{
		parity.Table("users", parity.Row{"id": parity.ID(2001)}, parity.Row{"id": parity.ID(2002)}, parity.Row{"id": parity.ID(2003)}),
		parity.Table("chat_rooms", parity.Row{"id": parity.ID(2070), "user1": parity.ID(2001), "user2": parity.ID(2003)}),
		parity.Table("messages",
			parity.Row{"id": parity.ID(2050), "fromuser": parity.ID(2003), "message": "x"},
			parity.Row{"id": parity.ID(2051), "fromuser": parity.ID(2003), "message": "x"},
		),
		parity.Table("chat_messages",
			parity.Row{"id": parity.ID(2080), "chatid": parity.ID(2070), "userid": parity.ID(2001), "refmsgid": parity.ID(2050), "type": utils.CHAT_MESSAGE_INTERESTED},
			parity.Row{"id": parity.ID(2081), "chatid": parity.ID(2070), "userid": parity.ID(2001), "refmsgid": parity.ID(2050), "type": utils.CHAT_MESSAGE_INTERESTED},
			parity.Row{"id": parity.ID(2082), "chatid": parity.ID(2070), "userid": parity.ID(2002), "refmsgid": parity.ID(2051), "type": utils.CHAT_MESSAGE_INTERESTED},
			parity.Row{"id": parity.ID(2083), "chatid": parity.ID(2070), "userid": parity.ID(2002), "refmsgid": parity.ID(2050), "type": utils.CHAT_MESSAGE_DEFAULT},
		),
	}

	parity.AssertResultParity(t, "70fe8fe8c804", fx, func(db *gorm.DB) any {
		return repliesForMessages(db, map[uint64]int{parity.ID(2050): 1, parity.ID(2051): 1})
	}, parity.Expect([]refUserCount{
		{Refmsgid: parity.ID(2050), Userid: parity.ID(2001), Count: 2},
		{Refmsgid: parity.ID(2051), Userid: parity.ID(2002), Count: 1},
	}))
}
