package admin

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"gorm.io/gorm"
)

func TestParityGolden_UneditedCopies(t *testing.T) {
	var rows []uint64
	parity.AssertGoldenSQL(t, "e9497a5d363f", func(tx *gorm.DB) *gorm.DB {
		return uneditedCopiesQuery(tx, []uint64{1, 2}).Find(&rows)
	})
}
