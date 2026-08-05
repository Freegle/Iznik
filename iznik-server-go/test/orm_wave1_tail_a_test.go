package test

// Wave 1 tail, group A (plan section 7.3+, database-migration-evaluation-2026-07.md
// section 7), covering seven small modules together:
// iznik-server-go/{shortlink,simulation,comment,isochrone,stdmsg,team,visualise}.
//
// Each test names its site ID. The extractor only counts a site converted once
// a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// 39 raw-SQL sites were recorded for these seven modules in wave 1. 38 are
// converted here. One was skipped and left on db.Raw - see the header comment
// above the skip site in production code for the reasoning:
//
//   - fb45e5ba61ec (comment.go, getSingle): "... AND groupid IN (?)" binds a
//     variable-length Go slice (modGroupIDs) to IN (?) - length-dependent
//     expansion, on the "skip and report" list.
//
// d613d8d0e239 and 3323eb1d5b7e (visualise.go, GetVisualise) were briefly
// skipped for the same "golden ends in a dynamic LIMIT ?" reason, but that
// turned out to be a genuine harness gap rather than an unconvertible site:
// ormharness.resolveLimitOffset (golden.go) used to rewrite any rendered
// LIMIT/OFFSET placeholder to a literal number unconditionally, which could
// never match a golden that itself kept "LIMIT ?" because the original code
// parameterised the limit with a real Go variable. AssertGoldenSQL now tries
// the unresolved render against the golden first and the resolved render
// second, so both forms of golden are satisfiable; both sites are converted
// below using .Limit(limit) with the same variable the original passed.
import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- comment.go: getSingle (isAdmin branch) ----------------------------------

func TestWave1TailA_55738aa5637a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "55738aa5637a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_comments").Where("id = ?", 1).Find(&dest)
	})
}

// --- comment.go: canModerate --------------------------------------------------

func TestWave1TailA_6d0d83bfc10d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6d0d83bfc10d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").Find(&dest)
	})
}

// --- comment.go: canModerateComment -------------------------------------------

func TestWave1TailA_255c7f92cc07(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "255c7f92cc07", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_comments").Select("groupid").Where("id = ?", 1).Find(&dest)
	})
}

// --- comment.go: flagOthers ---------------------------------------------------

func TestWave1TailA_2bc13e60bc01(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2bc13e60bc01", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid").Where("userid = ? AND groupid != ?", 1, 2).Find(&dest)
	})
}

// --- comment.go: Edit ----------------------------------------------------------

func TestWave1TailA_9c9df615ba74(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9c9df615ba74", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_comments").Select("userid, groupid").Where("id = ?", 1).Find(&dest)
	})
}

// --- isochrone.go: ListIsochrones auto-create fallback ------------------------

func TestWave1TailA_cedb6ee252fe(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "cedb6ee252fe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("lastlocation").Where("id = ? AND lastlocation IS NOT NULL", 1).Find(&dest)
	})
}

// --- isochrone.go: EnsureIsochroneExists ---------------------------------------

func TestWave1TailA_d646a78aab13(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d646a78aab13", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Select("lat, lng").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailA_b405451d2644(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b405451d2644", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones").Select("id").Where("locationid = ? AND transport = ? AND minutes = ?", 1, "Walk", 15).
			Order("id DESC").Limit(1).Find(&dest)
	})
}

// --- isochrone.go: CreateIsochrone location check ------------------------------

func TestWave1TailA_0ea54cb9b6d9(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "0ea54cb9b6d9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Where("id = ?", 1).Count(&dest)
	})
}

// --- isochrone.go: DeleteIsochrone ownership check ------------------------------

func TestWave1TailA_781fa9ba9257(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "781fa9ba9257", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Where("id = ? AND userid = ?", 1, 2).Count(&dest)
	})
}

// --- shortlink.go: GetShortlink -------------------------------------------------

func TestWave1TailA_23b1c7ff40ab(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "23b1c7ff40ab", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlinks").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailA_8ce94884434d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8ce94884434d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlink_clicks").Select("DATE(timestamp) AS date, COUNT(*) AS count").Where("shortlinkid = ?", 1).
			Group("date").Order("date ASC").Find(&dest)
	})
}

func TestWave1TailA_87c8a0b19cab(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "87c8a0b19cab", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlinks").Where("groupid = ?", 1).Order("LOWER(name) ASC").Find(&dest)
	})
}

func TestWave1TailA_5604e1f583b4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5604e1f583b4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlinks").Order("LOWER(name) ASC").Find(&dest)
	})
}

// --- shortlink.go: PostShortlink -------------------------------------------------

func TestWave1TailA_d9283245db83(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d9283245db83", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlinks").Select("id").Where("name LIKE ?", "x").Find(&dest)
	})
}

// --- shortlink.go: resolveShortlinkURL --------------------------------------------

func TestWave1TailA_4c3e63baca48(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4c3e63baca48", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("nameshort, external, onhere").Where("id = ?", 1).Find(&dest)
	})
}

// --- shortlinkhttp.go: RedirectShortlink -------------------------------------------

func TestWave1TailA_ae66a83e23cf(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ae66a83e23cf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlinks").Where("name LIKE ?", "x").Find(&dest)
	})
}

// --- simulation.go: listRuns ----------------------------------------------------

func TestWave1TailA_76e0992276ef(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "76e0992276ef", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("simulation_message_isochrones_runs").
			Select("id, name, description, created, completed, parameters, filters, message_count, metrics, status").
			Where("status = 'completed'").Order("created DESC").Limit(100).Find(&dest)
	})
}

// --- simulation.go: getRun -------------------------------------------------------

func TestWave1TailA_13c70937febc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "13c70937febc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("simulation_message_isochrones_runs").
			Select("id, name, description, created, completed, parameters, filters, message_count, metrics, status").
			Where("id = ?", 1).Find(&dest)
	})
}

// --- simulation.go: getMessage ---------------------------------------------------

func TestWave1TailA_38f8814d52b3(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "38f8814d52b3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("simulation_message_isochrones_messages").Where("runid = ?", 1).Count(&dest)
	})
}

func TestWave1TailA_9f95a2d88fb9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9f95a2d88fb9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("simulation_message_isochrones_messages").
			Select("id, runid, sequence, msgid, subject, lat, lng, groupid").
			Where("runid = ? AND sequence = ?", 1, 2).Find(&dest)
	})
}

func TestWave1TailA_af92875f9273(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "af92875f9273", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("simulation_message_isochrones_expansions").
			Select("id, sim_msgid, sequence, minutes, users, lat, lng").
			Where("sim_msgid = ?", 1).Order("sequence ASC").Find(&dest)
	})
}

func TestWave1TailA_ed5e8175ef79(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ed5e8175ef79", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("simulation_message_isochrones_users").
			Select("id, sim_msgid, userid, lat, lng").
			Where("sim_msgid = ?", 1).Find(&dest)
	})
}

// --- stdmsg.go: canModifyConfig ---------------------------------------------------

func TestWave1TailA_a1df6eefdf33(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a1df6eefdf33", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select("createdby").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailA_22a63b0ac626(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "22a63b0ac626", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select("protected").Where("id = ?", 1).Find(&dest)
	})
}

// --- stdmsg.go: GetStdMsg ----------------------------------------------------------

func TestWave1TailA_ae1076412fce(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ae1076412fce", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Where("id = ?", 1).Find(&dest)
	})
}

// --- stdmsg.go: PatchStdMsg / DeleteStdMsg (same query, two call sites) ------------

func TestWave1TailA_102535ad9bab(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "102535ad9bab", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Select("configid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1TailA_d6c28a45c7b1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d6c28a45c7b1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Select("configid").Where("id = ?", 1).Find(&dest)
	})
}

// --- team.go: GetTeam by name --------------------------------------------------------

func TestWave1TailA_95dc122ad363(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "95dc122ad363", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Select("id").Where("name LIKE ?", "x").Find(&dest)
	})
}

// --- team.go: GetTeam members --------------------------------------------------------

func TestWave1TailA_7fa3d8451353(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7fa3d8451353", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams_members").Select("userid, description, added, nameoverride, imageoverride").
			Where("teamid = ?", 1).Find(&dest)
	})
}

func TestWave1TailA_e0154f7f2b3c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e0154f7f2b3c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, CONCAT(COALESCE(firstname,''), ' ', COALESCE(lastname,'')), 'Unknown')").
			Where("id = ?", 1).Find(&dest)
	})
}

// --- team.go: GetTeam list all --------------------------------------------------------

func TestWave1TailA_7f14b1d8b5c5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7f14b1d8b5c5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Order("LOWER(name) ASC").Find(&dest)
	})
}

// --- team.go: getUserProfile -----------------------------------------------------------

func TestWave1TailA_10f0be0a062b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "10f0be0a062b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").Select("id").Where("userid = ?", 1).Order("id DESC").Limit(1).Find(&dest)
	})
}

// --- visualise.go: GetVisualise keyset-paginated query --------------------------------------
//
// Both goldens end "...LIMIT ?" because the original code parameterises LIMIT
// with the request's limit variable, not a hardcoded number - see this file's
// header comment. .Limit(5) below renders "LIMIT ?" pre-resolution, which
// AssertGoldenSQL now tries against the golden before falling back to the
// resolved literal form.

func TestWave1TailA_d613d8d0e239(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d613d8d0e239", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("visualise").
			Select("id, msgid, attid, fromuser, touser, fromlat, fromlng, tolat, tolng, distance, timestamp").
			Where("id < ? AND fromlat BETWEEN ? AND ? AND fromlng BETWEEN ? AND ?", 1, 2.0, 3.0, 4.0, 5.0).
			Order("id DESC").Limit(5).Find(&dest)
	})
}

func TestWave1TailA_3323eb1d5b7e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3323eb1d5b7e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("visualise").
			Select("id, msgid, attid, fromuser, touser, fromlat, fromlng, tolat, tolng, distance, timestamp").
			Where("fromlat BETWEEN ? AND ? AND fromlng BETWEEN ? AND ?", 2.0, 3.0, 4.0, 5.0).
			Order("id DESC").Limit(5).Find(&dest)
	})
}

// --- visualise.go: attachment info -------------------------------------------------------

func TestWave1TailA_ef5ef50abd33(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ef5ef50abd33", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Select("id, archived, externaluid, externalmods").Where("id = ?", 1).Find(&dest)
	})
}

// --- visualise.go: others who replied ------------------------------------------------------

func TestWave1TailA_e4507adb61a2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e4507adb61a2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("DISTINCT userid").Where("refmsgid = ? AND userid != ? AND userid != ?", 1, 2, 3).Find(&dest)
	})
}

func TestWave1TailA_817db6feee45(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "817db6feee45", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("CASE WHEN settings IS NOT NULL AND JSON_VALID(settings) "+
			"THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.lat')), 0) ELSE 0 END AS lat, "+
			"CASE WHEN settings IS NOT NULL AND JSON_VALID(settings) "+
			"THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.lng')), 0) ELSE 0 END AS lng").
			Where("id = ?", 1).Find(&dest)
	})
}

// The two sites below bind a Go slice to IN. GORM renders "IN (?,?,?)" for a
// three-element slice while the golden records the source text ("IN ?" or
// "IN (?)"). The old db.Raw call expanded the slice identically, so the
// executed SQL always matched; only the recorded golden differs, being
// captured before expansion. AssertGoldenSQL now collapses placeholder IN
// lists on both sides, which is what makes these convertible.

func TestWave1TailA_9494e3480fa0(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9494e3480fa0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("id, poly, polyofficial").
			Where("id IN ?", []uint64{1, 2, 3}).Find(&dest)
	})
}

func TestWave1TailA_fb45e5ba61ec(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fb45e5ba61ec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_comments").
			Where("id = ? AND groupid IN ?", 1, []uint64{2, 3}).Find(&dest)
	})
}

// a7496f46878c is textually identical to 9494e3480fa0 and sits in the same
// file. Converting only one of a pair like that renumbers the survivor's site
// ID, because the ID is hashed from (file, SQL, occurrence index): the second
// statement becomes the first, inherits its ID, and quietly inherits its parity
// test too. They are converted together for that reason, and ratchet gate (h)
// now refuses the split state.
func TestWave1TailA_a7496f46878c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a7496f46878c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("id, poly, polyofficial").
			Where("id IN ?", []uint64{1, 2, 3}).Find(&dest)
	})
}
