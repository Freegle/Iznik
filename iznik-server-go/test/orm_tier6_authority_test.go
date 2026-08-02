package test

// Tier 6 of the ORM migration keep-raw adversarial review: GetStatsByAuthority
// (authority/stats.go) had three weight queries whose WHERE/JOIN structure was
// ordinary but whose SELECT list spliced a live-recomputed average via
// fmt.Sprintf("%f", avg) rather than a bind - so the manifest's recorded
// goldenSql has a literal unresolved "%f" that no render could ever match.
//
// These three sites carry an approvedDiff instead of matching goldenSql
// directly (see ormharness/manifest.json), because the conversion is a
// deliberate, evidenced behaviour change: moving avg onto a real bind
// discards the up-to-6-decimal-place truncation fmt.Sprintf("%f", ...) was
// silently applying. The mathematical bound on that difference (and why it is
// negligible for this endpoint) is proven in
// authority/stats_precision_test.go, not assumed here.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

func TestTier6Authority_6d50d3895aa7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6d50d3895aa7", func(tx *gorm.DB) *gorm.DB {
		avg := 1.234567
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, SUM(COALESCE(weight, ?)) AS weight", avg).
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Joins("INNER JOIN messages_items mi ON messages.id = mi.msgid").
			Joins("INNER JOIN items i ON mi.itemid = i.id").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ? AND outcome IN (?, ?) AND NOT EXISTS (SELECT 1 FROM messages_bulk_items WHERE msgid = messages.id)",
				utils.LOCATION_TYPE_POSTCODE, "2026-01-01", "2026-01-31 23:59:59", utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED).
			Group("PartialPostcode").
			Order("locations.name").
			Find(&[]map[string]interface{}{})
	})
}

func TestTier6Authority_f281cfe83025(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f281cfe83025", func(tx *gorm.DB) *gorm.DB {
		avg := 1.234567
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, SUM(COALESCE(NULLIF(i.weight, 0), ?) * bi.quantity) AS weight", avg).
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items bi ON bi.msgid = messages.id AND bi.available = 0").
			Joins("LEFT JOIN items i ON i.name = bi.name").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				utils.LOCATION_TYPE_POSTCODE, "2026-01-01", "2026-01-31 23:59:59").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&[]map[string]interface{}{})
	})
}

func TestTier6Authority_3ecb2fba572f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3ecb2fba572f", func(tx *gorm.DB) *gorm.DB {
		avg := 1.234567
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, SUM(COALESCE(NULLIF(i.weight, 0), ?) * mbii.quantity) AS weight", avg).
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items_interest mbii ON mbii.msgid = messages.id AND mbii.state = 'Collected'").
			Joins("INNER JOIN messages_bulk_items bi ON bi.id = mbii.bulkitemid").
			Joins("LEFT JOIN items i ON i.name = bi.name").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				utils.LOCATION_TYPE_POSTCODE, "2026-01-01", "2026-01-31 23:59:59").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&[]map[string]interface{}{})
	})
}
