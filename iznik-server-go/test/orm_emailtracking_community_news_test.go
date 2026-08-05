package test

// isCommunityNewsItemURL (emailtracking/emailtracking.go): flagged as a
// fast-follow rather than converted during PR #1230's merge-conflict
// resolution (feat/orm-migration-v2 vs master). The function itself is
// unrelated to any of that merge's ten conflicted hunks - master added it
// alongside its other changes to the file - but converting new sites wasn't
// this pass's job, so it was left keep-raw with a note pointing here.
//
// A trivial single-table SELECT COUNT(*), same shape as the ~551 sites
// converted in Wave 1. Each test names its site ID: the extractor only
// counts a site converted once a parity test bearing its ID exists and
// passes - see ormharness.AssertGoldenSQL's doc comment (golden.go) and plan
// 7.2's Gate 2.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestEmailtrackingCommunityNews_8c6d59a41036(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "8c6d59a41036", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("community_news_items").Where("url = ?", "u").Count(&dest)
	})
}
