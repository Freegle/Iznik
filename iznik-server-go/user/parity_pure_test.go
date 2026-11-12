package user

import (
	"testing"

	"github.com/freegle/iznik-server-go/parity"
	"gorm.io/gorm"
)

// The converted statement binds the 0 that the raw one wrote inline. The recorded approved diff in
// tools/orm-migration/manifest.json says so, and this test fails if anything else differs.
func TestParityGolden_StopDigestEmails(t *testing.T) {
	parity.AssertGoldenSQL(t, "665bf9ca18a5", func(tx *gorm.DB) *gorm.DB {
		return stopDigestEmails(tx, 5)
	})
}
