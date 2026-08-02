package test

// Tier 6 of the ORM migration keep-raw adversarial review: five sites splice a
// member's or noticeboard's lat/lng into a WKT string via
// fmt.Sprintf("POINT(%f %f)", ...) before embedding it in raw SQL text. The
// review flagged a "%f-truncation vs full float64 precision" decision for
// each of these. Unlike authority/stats.go's avg (orm_tier6_authority_test.go),
// these sites make ZERO precision decision: the WKT text is built exactly as
// before (same fmt.Sprintf call, unchanged), and the only thing that moves is
// WHERE that already-formatted string goes - into a genuine ST_GeomFromText
// bind argument via gorm.Expr, rather than spliced into the SQL text. The
// numeric formatting is byte-for-byte identical to the pre-conversion
// behaviour, so there is no equivalence question to prove here.
//
// Each of these five carries an approvedDiff rather than matching goldenSql
// directly: the old goldenSql has a literal unresolved "%f"/"%s"/"{{expr}}"
// with nothing to compare against, AND the approvedDiff itself must be
// written in the exact alphabetical column order GORM's map-Create produces
// (ormharness's normaliseColumnOrder reorder-and-compare fallback applies to
// goldenSql, not to approvedDiff - see golden.go's assertRenderedSQL, where
// the approvedDiff check compares Canonical(approvedDiff) against the raw
// rendered canonical directly, as the last of several fallbacks).
//
// f961504c334d and 90b0f0bb3029 each have a second, independent shape axis
// (hidden: "NULL" or "NOW()", chosen by a moderation-status/spam check) that
// existed before this conversion and is untouched by it. Only the hidden=NULL
// shape is proven here; the hidden=NOW() shape was never separately golden-
// tested before this conversion either, so this is not a regression in
// coverage - just an honest note that it remains open, same as any other
// pre-existing untested branch.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestTier6Coordinates_10bcbd6a6404(t *testing.T) {
	// newsfeed/newsfeed.go createRefer
	ormharness.AssertGoldenSQL(t, "10bcbd6a6404", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"type":     "ReferToOffer",
			"userid":   1,
			"replyto":  2,
			"msgid":    gorm.Expr("NULLIF(?, 0)", uint64(3)),
			"position": gorm.Expr("ST_GeomFromText(?, ?)", "POINT(-3.188267 55.953251)", 3857),
		}
		return tx.Table("newsfeed").Create(row)
	})
}

func TestTier6Coordinates_f961504c334d(t *testing.T) {
	// newsfeed/newsfeed.go createPost (hidden=NULL shape - see file doc comment)
	ormharness.AssertGoldenSQL(t, "f961504c334d", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"type":     gorm.Expr("'Message'"),
			"userid":   1,
			"imageid":  nil,
			"replyto":  nil,
			"message":  "Hello",
			"position": gorm.Expr("ST_GeomFromText(?, ?)", "POINT(-3.188267 55.953251)", 3857),
			"hidden":   gorm.Expr("NULL"),
			"location": (*string)(nil),
		}
		return tx.Table("newsfeed").Create(row)
	})
}

func TestTier6Coordinates_90b0f0bb3029(t *testing.T) {
	// newsfeed/create.go CreateNewsfeedEntry (hidden=NULL shape - see file doc comment)
	ormharness.AssertGoldenSQL(t, "90b0f0bb3029", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"type":           "CommunityEvent",
			"userid":         1,
			"groupid":        2,
			"eventid":        (*uint64)(nil),
			"volunteeringid": (*uint64)(nil),
			"position":       gorm.Expr("ST_GeomFromText(?, ?)", "POINT(-3.188267 55.953251)", 3857),
			"location":       (*string)(nil),
			"hidden":         gorm.Expr("NULL"),
			"deleted":        gorm.Expr("NULL"),
			"reviewrequired": gorm.Expr("0"),
			"pinned":         gorm.Expr("0"),
		}
		return tx.Table("newsfeed").Create(row)
	})
}

func TestTier6Coordinates_42bb2fc5fe91(t *testing.T) {
	// noticeboard/noticeboard.go PostNoticeboard
	ormharness.AssertGoldenSQL(t, "42bb2fc5fe91", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"name":          "A noticeboard",
			"lat":           55.953251,
			"lng":           -3.188267,
			"position":      gorm.Expr("ST_GeomFromText(?, ?)", "POINT(-3.188267 55.953251)", 3857),
			"added":         gorm.Expr("NOW()"),
			"addedby":       1,
			"description":   "Description",
			"active":        true,
			"lastcheckedat": gorm.Expr("NOW()"),
		}
		return tx.Table("noticeboards").Create(row)
	})
}

func TestTier6Coordinates_c4e30fd6a513(t *testing.T) {
	// noticeboard/noticeboard.go PatchNoticeboard
	ormharness.AssertGoldenSQL(t, "c4e30fd6a513", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"type":     gorm.Expr("'Noticeboard'"),
			"userid":   1,
			"message":  `{"id":2,"name":"A noticeboard"}`,
			"added":    gorm.Expr("NOW()"),
			"position": gorm.Expr("ST_GeomFromText(?, ?)", "POINT(-3.188267 55.953251)", 3857),
		}
		return tx.Table("newsfeed").Create(row)
	})
}
