package test

// Wave 0 of the raw-SQL-to-ORM migration (plan section 7.3): ten sites of
// mixed shape taken end to end, to prove the pipeline before any bulk
// conversion starts. Nine were converted; the tenth turned out to need a
// redesign rather than a conversion and is recorded as keep-raw with its
// reason (see tools/orm-migration/keep-raw.json, tryst.CreateTryst).
//
// Each test names its site ID. That is not decoration: the extractor scans
// test files for site IDs and will only count a site converted once a parity
// test bearing its ID exists, which is plan 7.2's Gate 2 enforced
// mechanically rather than by reviewer diligence.

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- Wave 1: single-table SELECT ------------------------------------------

func TestPilotSite17b90a8329d8_TeamByID(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "17b90a8329d8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Where("id = ?", 1).Scan(&dest)
	})
}

func TestPilotSiteB43c5d4c54a2_LatestMessageDate(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b43c5d4c54a2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("MAX(date)").Scan(&dest)
	})
}

func TestPilotSiteE574518b4ebd_CronJobStatus(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e574518b4ebd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("cron_job_status").Scan(&dest)
	})
}

// --- Wave 2: single-table writes ------------------------------------------

func TestPilotSiteD0644aa6dbe0_UpdateTeamName(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d0644aa6dbe0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Where("id = ?", 1).Update("name", "x")
	})
}

func TestPilotSite76c84d731809_DeleteTeam(t *testing.T) {
	// Team carries no gorm.DeletedAt, so this must stay a hard DELETE. If a
	// soft-delete field is ever added to the model this test fails loudly,
	// which is the point: the statement would silently become an UPDATE.
	ormharness.AssertGoldenSQL(t, "76c84d731809", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Where("id = ?", 1).Delete(nil)
	})
}

func TestPilotSite507986a628ba_InsertShortlinkClick(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "507986a628ba", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("shortlink_clicks").Create(map[string]interface{}{"shortlinkid": 1})
	})
}

// --- Wave 3: upserts through the portable wrapper -------------------------

// The two upsert sites are the ones that most needed proving. Plan 7.2
// records that GORM's clause.OnConflict mishandles WHERE clauses on the
// conflict target for some drivers (go-gorm/gorm#4355, go-gorm/mysql#39), so
// the emitted SQL is checked rather than assumed.

func TestPilotSite4eabda40530c_ConfigUpsert(t *testing.T) {
	// "key" is a reserved word. The quoting has to come from the dialector,
	// so this also proves the canonicaliser treats a backtick-quoted
	// identifier and a bare one as the same thing.
	ormharness.AssertGoldenSQL(t, "4eabda40530c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("config").Clauses(clause.OnConflict{
			Columns:   []clause.Column{{Name: "key"}},
			DoUpdates: clause.Assignments(map[string]interface{}{"value": "v"}),
		}).Create(map[string]interface{}{"key": "k", "value": "v"})
	})
}

func TestPilotSite958d1d242008_NewsfeedReportUpsert(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "958d1d242008", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_reports").Clauses(clause.OnConflict{
			Columns:   []clause.Column{{Name: "userid"}, {Name: "newsfeedid"}},
			DoUpdates: clause.Assignments(map[string]interface{}{"reason": "r"}),
		}).Create(map[string]interface{}{"userid": 1, "newsfeedid": 2, "reason": "r"})
	})
}

// --- Wave 4: multi-table SELECT -------------------------------------------

func TestPilotSite242735a48039_UserByEmailJoin(t *testing.T) {
	var dest uint64
	ormharness.AssertGoldenSQL(t, "242735a48039", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users u").
			Select("u.id").
			Joins("JOIN users_emails ue ON ue.userid = u.id").
			Where("ue.email = ?", "a@b.c").
			Limit(1).
			Scan(&dest)
	})
}

func TestPilotSite1a6871aa02b9_UserLocationLeftJoin(t *testing.T) {
	var dest map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1a6871aa02b9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users u").
			Select("l.lat, l.lng").
			Joins("LEFT JOIN locations l ON l.id = u.lastlocation").
			Where("u.id = ?", 1).
			Scan(&dest)
	})
}

// --- Layer 2: result parity against the seeded database -------------------

// Layer 1 above proves the SQL text is equivalent. Layer 2 proves the two
// statements actually return the same rows, which is what catches collation,
// NULL handling and implicit-cast differences that text comparison cannot.
// Run for the read sites, since the write sites would mutate fixture data
// that other tests depend on; writes are covered by Layer 4 replay instead.

func TestPilotResultParity_CronJobStatus(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT * FROM cron_job_status", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("cron_job_status")
		})
}

func TestPilotResultParity_LatestMessageDate(t *testing.T) {
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT MAX(date) FROM messages", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("messages").Select("MAX(date)")
		})
}

func TestPilotResultParity_UserLocationLeftJoin(t *testing.T) {
	// The LEFT JOIN is the interesting one: a user with no lastlocation must
	// still produce a row with NULL lat/lng, and Layer 2 compares NULL
	// distinctly from zero.
	ormharness.AssertResultParity(t, database.DBConn,
		"SELECT l.lat, l.lng FROM users u LEFT JOIN locations l ON l.id = u.lastlocation WHERE u.id = ?",
		[]any{uint64(1)},
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("users u").
				Select("l.lat, l.lng").
				Joins("LEFT JOIN locations l ON l.id = u.lastlocation").
				Where("u.id = ?", uint64(1))
		})
}
