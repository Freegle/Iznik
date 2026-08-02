package test

// Wave 2 (single-table writes), message module.
//
// These twelve conversions were made by a batch that died to an API error
// before it wrote its tests. The production edits themselves were complete,
// parsed cleanly and used the patterns the Wave 2 pilot proved, so they were
// kept rather than discarded; the tests below are what make them provable
// under Gate 2, which is the part that was missing. Nothing here is taken on
// trust: each render is compared against the recorded golden.
//
// Note the write conventions, which differ from the read waves:
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed and
//     a soft-delete field cannot silently turn a DELETE into an UPDATE.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- bulkEdit.go ----------------------------------------------------------

func TestWave2Message_7b9310de870b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7b9310de870b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").
			Where("id = ? AND msgid = ?", 1, 2).Update("available", 1)
	})
}

func TestWave2Message_74516dda0fb7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "74516dda0fb7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").
			Where("id = ? AND msgid = ?", 1, 2).Update("quantity", 3)
	})
}

// --- bulkItem.go ----------------------------------------------------------

// 0a3ffbf56687 and dcb4fcaa8406 are the same statement at two call sites, so
// they are converted together: gate (h) refuses a half-converted pair, because
// converting one renumbers the survivor's site ID.
func TestWave2Message_0a3ffbf56687(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0a3ffbf56687", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).
			Update("latestmessage", gorm.Expr("NOW()"))
	})
}

func TestWave2Message_dcb4fcaa8406(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dcb4fcaa8406", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).
			Update("latestmessage", gorm.Expr("NOW()"))
	})
}

func TestWave2Message_b51c93157bcf(t *testing.T) {
	// The caller checks result.RowsAffected, which .Table().Update() still
	// populates, so that behaviour is unchanged.
	ormharness.AssertGoldenSQL(t, "b51c93157bcf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").
			Where("bulkitemid = ? AND userid = ?", 1, 2).Update("state", "X")
	})
}

func TestWave2Message_e42129621a05(t *testing.T) {
	// gorm.Expr keeps the GREATEST(...) expression and its bind, rather than
	// GORM treating the whole thing as a literal value.
	ormharness.AssertGoldenSQL(t, "e42129621a05", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Where("id = ?", 1).
			Update("quantity", gorm.Expr("GREATEST(0, quantity - ?)", 2))
	})
}

func TestWave2Message_3904aaac34be(t *testing.T) {
	// The golden sets a literal 0, so the value goes through gorm.Expr rather
	// than as a bind, which would have rendered "available = ?".
	ormharness.AssertGoldenSQL(t, "3904aaac34be", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").
			Where("id = ? AND quantity = 0", 1).Update("available", gorm.Expr("0"))
	})
}

func TestWave2Message_85e640a5f97f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "85e640a5f97f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").
			Where("id = ? AND (msgid IS NULL OR msgid = ?)", 1, 2).Update("msgid", 3)
	})
}

func TestWave2Message_2cacf9e0d85d(t *testing.T) {
	// NOT IN bound to a slice. The harness collapses placeholder IN lists on
	// both sides, since db.Raw expanded the slice identically.
	ormharness.AssertGoldenSQL(t, "2cacf9e0d85d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").
			Where("msgid = ? AND id NOT IN ?", 1, []uint64{2, 3}).Delete(nil)
	})
}

func TestWave2Message_d022d4abb6ce(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d022d4abb6ce", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Message_6acdc6efc5f0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6acdc6efc5f0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_slots").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Message_c29f7adf11f7(t *testing.T) {
	// A map-valued Create emits columns alphabetically. Here that happens to
	// match the handwritten list (msgid, position, slot), so no approved diff
	// is needed; most INSERTs will not be so lucky.
	ormharness.AssertGoldenSQL(t, "c29f7adf11f7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_slots").Create(map[string]interface{}{
			"msgid":    1,
			"position": 2,
			"slot":     "a",
		})
	})
}
