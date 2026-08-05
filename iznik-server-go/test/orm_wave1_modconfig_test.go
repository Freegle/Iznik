package test

// Wave 1 tail (plan section 7.3), modconfig module: iznik-server-go/
// modconfig/modconfig.go. These seven sites were invisible until the
// extractor learned to resolve Go string constants when folding a golden -
// GetModConfig et al. build their SELECT list from the package-level
// configColumns / stdMsgColumns constants ("SELECT "+configColumns+" FROM
// mod_configs WHERE id = ?"), and until that resolution landed the folded
// golden was a "{{expr}}" hole rather than real SQL, so these were
// misclassified as dynamic wave 5. They are in fact simple single-table
// SELECTs, no different in shape from any other wave-1 site.
//
// The conversions keep using the same configColumns / stdMsgColumns
// constants in .Select(...) rather than inlining the column list, so the
// column set has one source of truth in production; only the test hardcodes
// the resolved list, matching the goldenSql the manifest now records.
//
// mod_stdmsgs.`insert` and mod_configs.`default` are both reserved words:
// the constants already carry the backticks the golden expects, and no
// special handling is needed beyond passing them through unchanged.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

const modconfigColumns = "id, name, createdby, fromname, ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, protected, messageorder, network, coloursubj, subjreg, subjlen, `default`, chatread"

const modStdMsgColumns = "id, configid, title, action, subjpref, subjsuff, body, rarelyused, autosend, newmodstatus, newdelstatus, edittext, `insert`"

// --- GetModConfig: single config fetch ---------------------------------------

func TestWave1Modconfig_c1e1f7ddeb2d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c1e1f7ddeb2d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select(modconfigColumns).Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Modconfig_2e87012dbaed(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2e87012dbaed", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Select(modStdMsgColumns).Where("configid = ?", 1).Find(&dest)
	})
}

// --- listModConfigs: admin/support "all configs" branch ----------------------

func TestWave1Modconfig_2d4f322cfd3f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2d4f322cfd3f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select(modconfigColumns).Order("name").Find(&dest)
	})
}

// --- PostModConfig: copy-from-existing-config source lookups -----------------

func TestWave1Modconfig_87d36c5d843f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "87d36c5d843f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select(modconfigColumns).Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Modconfig_a74b7b022b84(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a74b7b022b84", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Select(modStdMsgColumns).Where("configid = ?", 1).Find(&dest)
	})
}

// --- PatchModConfig: load config before applying the patch --------------------

func TestWave1Modconfig_4bbe84a257f3(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4bbe84a257f3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select(modconfigColumns).Where("id = ?", 1).Find(&dest)
	})
}

// --- DeleteModConfig: load config before checking it can be deleted -----------

func TestWave1Modconfig_9fc1bbefed72(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9fc1bbefed72", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select(modconfigColumns).Where("id = ?", 1).Find(&dest)
	})
}
