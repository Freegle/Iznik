package test

// tryst/tryst.go CreateTryst's ON DUPLICATE KEY UPDATE bug fix + conversion.
// The site was kept raw as 938d9dc56c71 with a reason that was itself wrong
// (it claimed GORM could not surface LastInsertId for an ODKU insert). The
// real, and separately live, defect was that the ODKU clause carried no
// "id = LAST_INSERT_ID(id)" forcing, so on a duplicate-key hit MySQL left
// LAST_INSERT_ID unset and this endpoint handed callers {"id": 0}. See
// test/tryst_test.go's TestCreateTrystDuplicateReturnsExistingID for the
// DB-backed proof of the fixed behaviour through the real HTTP handler.
//
// WHY THIS TEST NAMES 938d9dc56c71 AND NOT THE REHASHED ID.
// A site ID hashes path plus normalised SQL, so changing the SQL text
// renumbers it: the fixed statement extracts as b0e6f29b54bd. But that ID
// describes a "fixed but still raw" statement which never existed in any
// committed tree - the fix and the conversion landed in one edit. Adding a
// manifest entry for it would mean writing a record of raw SQL that was never
// in the code, which is the one thing the inventory must never contain: the
// manifest's whole claim is that it is generated from what is really there.
//
// So the site keeps the identity it actually had. 938d9dc56c71 is this call
// site, its golden is the buggy statement that genuinely was in the tree, and
// the divergence between that golden and what we now render is recorded as
// this site's approvedDiff - which is exactly what an approved diff is for: a
// reviewed, written-down reason that the new SQL differs from the old. Here
// the reason is a deliberate behaviour change rather than a rendering
// artefact, and the diff entry says so in those words.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

func TestTrystFix_938d9dc56c71(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "938d9dc56c71", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("trysts").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "arrangedat"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"user1": 1, "user2": 2, "arrangedfor": "2038-01-19T03:14:06+00:00",
		})
	})
}
