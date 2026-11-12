package admin

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"gorm.io/gorm"
)

// Layer 2 of the parity harness. Ids start at parity.ID(4000).
func TestParityResult_UneditedCopies(t *testing.T) {
	fx := parity.Fixture{
		parity.Table("admins",
			parity.Row{"id": parity.ID(4001), "subject": "Hello", "text": "Body"},
			parity.Row{"id": parity.ID(4002), "parentid": parity.ID(4001), "subject": "Hello", "text": "Body"},
			parity.Row{"id": parity.ID(4003), "parentid": parity.ID(4001), "subject": "Hello", "text": "Body, edited"},
			parity.Row{"id": parity.ID(4004), "parentid": parity.ID(4001), "subject": "Hello", "text": "Body", "ctatext": "Click"},
		),
	}

	parity.AssertResultParity(t, "e9497a5d363f", fx, func(db *gorm.DB) any {
		return uneditedCopies(db, []uint64{parity.ID(4002), parity.ID(4003), parity.ID(4004)})
	}, parity.Expect(map[uint64]bool{parity.ID(4002): true}))
}
