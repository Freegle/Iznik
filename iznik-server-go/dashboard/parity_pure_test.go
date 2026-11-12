package dashboard

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"gorm.io/gorm"
)

func TestParityGolden_RepliesForBatch(t *testing.T) {
	var rows []refUserCount
	parity.AssertGoldenSQL(t, "70fe8fe8c804", func(tx *gorm.DB) *gorm.DB {
		return repliesForBatchQuery(tx, []uint64{1, 2, 3}).Find(&rows)
	})
}
