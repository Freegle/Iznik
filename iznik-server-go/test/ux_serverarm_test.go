package test

import (
	"context"
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
)

// Server arms: the assignment function shared by the browser, the batch and this API. The vectors
// are pinned in iznik-nuxt3/tests/unit/composables/useExperiment.spec.js and
// iznik-batch/tests/Unit/Support/ExperimentBucketTest.php too, so a change that moves a bucket in
// one runtime fails all three suites. The rules table below is the same table as
// iznik-batch/tests/Feature/Ux/UxArmTest.php, case for case.
//
// Members on "digest-subject" with arms control [0,49] and items [50,99]:
//
//	member    1      2      3      5     12345  999999
//	bucket   87     77     72     88     29     68      without a salt
//	bucket   88     97      5      2     11     58      with the salt r2

func TestServerArmBucketMatchesTheSharedVectors(t *testing.T) {
	ids := []uint64{1, 2, 3, 5, 12345, 999999}
	vectors := []struct {
		name string
		salt string
		want []int
	}{
		{"confirm-reply", "", []int{44, 73, 77, 55, 1, 38}},
		{"digest-subject", "", []int{87, 77, 72, 88, 29, 68}},
		{"digest-subject", "r2", []int{88, 97, 5, 2, 11, 58}},
	}

	for _, v := range vectors {
		got := make([]int, len(ids))
		for i, id := range ids {
			got[i] = ux.ExperimentBucket(id, v.name, v.salt)
		}
		assert.Equal(t, v.want, got, fmt.Sprintf("%s with salt %q", v.name, v.salt))
	}
}

func TestServerArmBucketAgreesWithTheBrowserAndTheBatchOnTheirOwnVectors(t *testing.T) {
	// The values useExperiment.spec.js and ExperimentBucketTest.php pin for the older web names.
	assert.Equal(t, 89, ux.ExperimentBucket(123, "exp", ""))
	assert.Equal(t, 40, ux.ExperimentBucket(42, "give-hint", ""))
}

func TestServerArmBucketMovesWithTheSaltAndNotWithAnEmptyOne(t *testing.T) {
	// Member 5 on digest-subject: bucket 88 with no salt, 2 with the salt r2.
	assert.Equal(t, 88, ux.ExperimentBucket(5, "digest-subject", ""))
	assert.Equal(t, 2, ux.ExperimentBucket(5, "digest-subject", "r2"))
}

const serverArmName = "digest-subject"

// serverArmConfig writes the two config rows an arm is read from, flushes the copy, and puts back
// what was there when the test ends. haveSwitch false leaves no ux_server_arms row at all.
func serverArmConfig(t *testing.T, experiments string, switchValue string, haveSwitch bool) {
	t.Helper()
	db := database.DBConn

	var old []struct {
		Key   string
		Value string
	}
	db.Raw("SELECT `key` AS `key`, value AS value FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')").Scan(&old)
	db.Exec("DELETE FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')")
	if experiments != "" {
		db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_experiments', ?)", experiments)
	}
	if haveSwitch {
		db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_server_arms', ?)", switchValue)
	}
	ux.FlushServerArmCache()

	t.Cleanup(func() {
		db.Exec("DELETE FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')")
		for _, r := range old {
			db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?)", r.Key, r.Value)
		}
		ux.FlushServerArmCache()
	})
}

// serverArmWorld fixes the clock at 10:00 on 20 October 2026 and lists the names the tests use as
// the code's own switches. It returns the time in unix seconds.
func serverArmWorld(t *testing.T) int64 {
	t.Helper()
	now := time.Date(2026, 10, 20, 10, 0, 0, 0, time.UTC)
	t.Cleanup(ux.SetServerArmClockForTest(func() time.Time { return now }))
	t.Cleanup(ux.SetServerSwitchesForTest(map[string]ux.ServerSwitch{
		serverArmName: {Name: serverArmName},
		"bad name!":   {Name: "bad name!"},
		"constructor": {Name: "constructor"},
	}))

	return now.Unix()
}

func serverArmEntry(arms string, extra string) string {
	if extra != "" {
		extra = "," + extra
	}

	return `{"` + serverArmName + `":{"arms":` + arms + extra + `}}`
}

func TestServerArmRulesRunningEntries(t *testing.T) {
	now := serverArmWorld(t)
	until := func(d int64) string { return `"until":` + strconv.FormatInt(now+d, 10) }
	const arms = `{"control":[0,49],"items":[50,99]}`

	cases := []struct {
		name   string
		config string
		member uint64
		want   string
	}{
		{"member 1, bucket 87", serverArmEntry(arms, until(3600)), 1, "items"},
		{"member 2, bucket 77", serverArmEntry(arms, until(3600)), 2, "items"},
		{"member 3, bucket 72", serverArmEntry(arms, until(3600)), 3, "items"},
		{"member 5, bucket 88", serverArmEntry(arms, until(3600)), 5, "items"},
		{"member 12345, bucket 29", serverArmEntry(arms, until(3600)), 12345, "control"},
		{"member 999999, bucket 68", serverArmEntry(arms, until(3600)), 999999, "items"},
		{"member 1 with r2, bucket 88", serverArmEntry(arms, until(3600)+`,"salt":"r2"`), 1, "items"},
		{"member 2 with r2, bucket 97", serverArmEntry(arms, until(3600)+`,"salt":"r2"`), 2, "items"},
		{"member 3 with r2, bucket 5", serverArmEntry(arms, until(3600)+`,"salt":"r2"`), 3, "control"},
		{"member 5 with r2, bucket 2", serverArmEntry(arms, until(3600)+`,"salt":"r2"`), 5, "control"},
		{"member 12345 with r2, bucket 11", serverArmEntry(arms, until(3600)+`,"salt":"r2"`), 12345, "control"},
		{"member 999999 with r2, bucket 58", serverArmEntry(arms, until(3600)+`,"salt":"r2"`), 999999, "items"},

		{"until one second ahead serves", serverArmEntry(arms, until(1)), 3, "items"},
		{"until now is the end, not the last second", serverArmEntry(arms, until(0)), 3, ""},
		{"until a second ago is over", serverArmEntry(arms, until(-1)), 3, ""},
		{"no until is control when running", serverArmEntry(arms, ""), 3, ""},
		{"until exactly fifteen days ahead serves", serverArmEntry(arms, until(15*86400)), 3, "items"},
		{"until fifteen days and a second ahead is not believed", serverArmEntry(arms, until(15*86400+1)), 3, ""},
		{"until as a string is not a time", serverArmEntry(arms, `"until":"`+strconv.FormatInt(now+3600, 10)+`"`), 3, ""},
		{"until null is no until", serverArmEntry(arms, `"until":null`), 3, ""},

		{"the first arm that holds the bucket wins and an empty range holds nobody",
			serverArmEntry(`{"empty":[99,98],"first":[0,99],"second":[0,99]}`, until(3600)), 3, "first"},
		{"an entry whose only arms are empty is control", serverArmEntry(`{"b":[99,98]}`, until(3600)), 3, ""},
		{"a bucket outside every range is control", serverArmEntry(`{"items":[0,10]}`, until(3600)), 3, ""},
		{"whole numbers written with a fraction of zero count", serverArmEntry(`{"control":[0.0,49.0],"items":[50.0,99.0]}`, until(3600)), 3, "items"},
		{"a null salt is no salt", serverArmEntry(arms, until(3600)+`,"salt":null`), 3, "items"},
		{"a salt of sixteen characters is the most", serverArmEntry(arms, until(3600)+`,"salt":"abcdefghijklmnop"`), 3, "items"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			serverArmConfig(t, c.config, "1", true)
			assert.Equal(t, c.want, ux.ExperimentArm(database.DBConn, c.member, serverArmName))
		})
	}
}

func TestServerArmRulesAdoptedEntries(t *testing.T) {
	now := serverArmWorld(t)
	const adopted = `{"items":[0,89],"control":[90,99]}`

	cases := []struct {
		name   string
		config string
		member uint64
		want   string
	}{
		{"a past until is ignored", serverArmEntry(adopted, `"adopted":true,"until":`+strconv.FormatInt(now-99999, 10)), 3, "items"},
		{"no until is fine", serverArmEntry(adopted, `"adopted":true`), 3, "items"},
		{"an until far ahead is ignored", serverArmEntry(adopted, `"adopted":true,"until":`+strconv.FormatInt(now+40*86400, 10)), 3, "items"},
		{"the salt is kept, or adoption would move everyone", serverArmEntry(adopted, `"adopted":true,"salt":"r2"`), 2, "control"},
		{"without the salt the same member is in the adopted arm", serverArmEntry(adopted, `"adopted":true`), 2, "items"},
		{"only the boolean true adopts", serverArmEntry(`{"items":[0,99]}`, `"adopted":"true","until":`+strconv.FormatInt(now-5, 10)), 3, ""},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			serverArmConfig(t, c.config, "1", true)
			assert.Equal(t, c.want, ux.ExperimentArm(database.DBConn, c.member, serverArmName))
		})
	}
}

func TestServerArmRulesAnEntryThatIsNotUnderstoodIsControl(t *testing.T) {
	now := serverArmWorld(t)
	until := `"until":` + strconv.FormatInt(now+3600, 10)

	cases := map[string]string{
		"no arms":                      `{"digest-subject":{` + until + `}}`,
		"arms null":                    `{"digest-subject":{"arms":null,` + until + `}}`,
		"arms a list":                  `{"digest-subject":{"arms":[[0,49]],` + until + `}}`,
		"arms empty":                   `{"digest-subject":{"arms":{},` + until + `}}`,
		"more than eight arms":         `{"digest-subject":{"arms":{"a":[0,1],"b":[2,3],"c":[4,5],"d":[6,7],"e":[8,9],"f":[10,11],"g":[12,13],"h":[14,15],"i":[16,17]},` + until + `}}`,
		"an arm name with a space":     `{"digest-subject":{"arms":{"bad arm":[0,99]},` + until + `}}`,
		"an arm name of 33 characters": `{"digest-subject":{"arms":{"` + strings.Repeat("a", 33) + `":[0,99]},` + until + `}}`,
		"a range above 99":             `{"digest-subject":{"arms":{"b":[0,100]},` + until + `}}`,
		"a range below 0":              `{"digest-subject":{"arms":{"b":[-1,99]},` + until + `}}`,
		"a range of one number":        `{"digest-subject":{"arms":{"b":[0]},` + until + `}}`,
		"a range of three numbers":     `{"digest-subject":{"arms":{"b":[0,1,2]},` + until + `}}`,
		"a range of strings":           `{"digest-subject":{"arms":{"b":["0","99"]},` + until + `}}`,
		"a range with a fraction":      `{"digest-subject":{"arms":{"b":[0.5,99]},` + until + `}}`,
		"a range that is an object":    `{"digest-subject":{"arms":{"b":{"from":0,"to":99}},` + until + `}}`,
		"the old flat shape":           `{"digest-subject":{"control":[0,49],"b":[50,99]}}`,
		"an entry that is a string":    `{"digest-subject":"x"}`,
		"an entry that is a list":      `{"digest-subject":[1,2]}`,
		"an entry that is null":        `{"digest-subject":null}`,
		"the whole row a list":         `[{"digest-subject":{"arms":{"b":[0,99]},` + until + `}}]`,
		"the whole row not JSON":       `{not json`,
		"one bad arm among good ones":  `{"digest-subject":{"arms":{"control":[0,49],"bad arm":[50,99]},` + until + `}}`,
	}

	for name, config := range cases {
		t.Run(name, func(t *testing.T) {
			serverArmConfig(t, config, "1", true)
			assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, serverArmName))
		})
	}

	t.Run("a row that is empty", func(t *testing.T) {
		serverArmConfig(t, "", "1", true)
		assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, serverArmName))
	})
}

func TestServerArmRulesASaltThatIsPresentAndMalformedDropsTheEntry(t *testing.T) {
	now := serverArmWorld(t)
	salts := map[string]string{
		"a space":              `"bad salt"`,
		"17 characters":        `"abcdefghijklmnopq"`,
		"an empty string":      `""`,
		"a number":             `5`,
		"true":                 `true`,
		"a list":               `["r2"]`,
		"an object":            `{"r":2}`,
		"a symbol":             `"r2!"`,
		"a newline at the end": `"r2\n"`,
	}

	for name, salt := range salts {
		t.Run(name+", running", func(t *testing.T) {
			serverArmConfig(t, serverArmEntry(`{"control":[0,49],"items":[50,99]}`, `"until":`+strconv.FormatInt(now+3600, 10)+`,"salt":`+salt), "1", true)
			assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, serverArmName))
		})
		t.Run(name+", adopted", func(t *testing.T) {
			serverArmConfig(t, serverArmEntry(`{"items":[0,99]}`, `"adopted":true,"salt":`+salt), "1", true)
			assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, serverArmName))
		})
	}
}

func TestServerArmRulesANameThatIsNotSafeIsNeverServed(t *testing.T) {
	now := serverArmWorld(t)
	entry := `{"arms":{"items":[0,99]},"until":` + strconv.FormatInt(now+3600, 10) + `}`
	serverArmConfig(t, `{"bad name!":`+entry+`,"constructor":`+entry+`,"digest-subject":`+entry+`}`, "1", true)

	assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, "bad name!"))
	assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, "constructor"))
	assert.Equal(t, "items", ux.ExperimentArm(database.DBConn, 3, serverArmName))
}

func TestServerArmRulesOnlyOneSwitchesServerArmsOn(t *testing.T) {
	now := serverArmWorld(t)
	config := serverArmEntry(`{"control":[0,49],"items":[50,99]}`, `"until":`+strconv.FormatInt(now+3600, 10))

	off := []struct {
		name string
		sw   string
		have bool
	}{
		{"no row", "", false},
		{"0", "0", true},
		{"true", "true", true},
		{"on", "on", true},
		{"an empty value", "", true},
		{"yes", "yes", true},
		{"01", "01", true},
		{"2", "2", true},
	}
	for _, c := range off {
		t.Run(c.name+" is off", func(t *testing.T) {
			serverArmConfig(t, config, c.sw, c.have)
			assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, serverArmName))
		})
	}

	t.Run("1 is on", func(t *testing.T) {
		serverArmConfig(t, config, "1", true)
		assert.Equal(t, "items", ux.ExperimentArm(database.DBConn, 3, serverArmName))
	})
	t.Run("one with spaces round it is still one", func(t *testing.T) {
		serverArmConfig(t, config, " 1\n", true)
		assert.Equal(t, "items", ux.ExperimentArm(database.DBConn, 3, serverArmName))
	})
}

func TestServerArmRulesANameWithNoSwitchInThisCodeIsControl(t *testing.T) {
	now := serverArmWorld(t)
	entry := `{"arms":{"items":[0,99]},"until":` + strconv.FormatInt(now+3600, 10) + `}`
	serverArmConfig(t, `{"unlisted-test":`+entry+`,"digest-subject":`+entry+`}`, "1", true)

	assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, "unlisted-test"))
	assert.Equal(t, "items", ux.ExperimentArm(database.DBConn, 3, serverArmName))
}

func TestServerArmRulesAMemberWithNoIdIsControl(t *testing.T) {
	now := serverArmWorld(t)
	serverArmConfig(t, serverArmEntry(`{"items":[0,99]}`, `"until":`+strconv.FormatInt(now+3600, 10)), "1", true)

	assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 0, serverArmName))
}

func TestServerArmCopyIsKeptForAMinuteAndFlushForgetsIt(t *testing.T) {
	now := serverArmWorld(t)
	clock := time.Unix(now, 0).UTC()
	t.Cleanup(ux.SetServerArmClockForTest(func() time.Time { return clock }))
	serverArmConfig(t, serverArmEntry(`{"control":[0,49],"items":[50,99]}`, `"until":`+strconv.FormatInt(now+3600, 10)), "1", true)
	db := database.DBConn

	assert.Equal(t, "items", ux.ExperimentArm(db, 3, serverArmName))

	// The switch is turned off. For 59 seconds this process still holds the copy it read.
	db.Exec("UPDATE config SET value = '0' WHERE `key` = 'ux_server_arms'")
	clock = clock.Add(59 * time.Second)
	assert.Equal(t, "items", ux.ExperimentArm(db, 3, serverArmName))

	// At a minute it reads again and acts as control.
	clock = clock.Add(1 * time.Second)
	assert.Equal(t, "", ux.ExperimentArm(db, 3, serverArmName))

	// FlushServerArmCache makes the next call read straight away.
	db.Exec("UPDATE config SET value = '1' WHERE `key` = 'ux_server_arms'")
	assert.Equal(t, "", ux.ExperimentArm(db, 3, serverArmName), "still the copy")
	ux.FlushServerArmCache()
	assert.Equal(t, "items", ux.ExperimentArm(db, 3, serverArmName))

	// A clock that goes back reads again.
	db.Exec("UPDATE config SET value = '0' WHERE `key` = 'ux_server_arms'")
	clock = clock.Add(-30 * time.Second)
	assert.Equal(t, "", ux.ExperimentArm(db, 3, serverArmName))
}

func TestServerArmBothRowsAreReadInOneQuery(t *testing.T) {
	now := serverArmWorld(t)
	serverArmConfig(t, serverArmEntry(`{"control":[0,49],"items":[50,99]}`, `"until":`+strconv.FormatInt(now+3600, 10)), "1", true)

	var queries []string
	counting := database.DBConn.Session(&gorm.Session{Logger: &queryRecorder{sql: &queries}})
	for _, member := range []uint64{3, 5, 1, 12345} {
		ux.ExperimentArm(counting, member, serverArmName)
	}

	reads := 0
	for _, q := range queries {
		if strings.Contains(q, "FROM `config`") {
			reads++
			assert.Contains(t, q, "`key` IN (")
			assert.Contains(t, q, "ux_experiments")
			assert.Contains(t, q, "ux_server_arms")
		}
	}
	assert.Equal(t, 1, reads, "one query, and then the copy for the rest")
}

func TestServerArmAnExperimentThatIsNotListedAsksNothing(t *testing.T) {
	serverArmWorld(t)
	var queries []string
	counting := database.DBConn.Session(&gorm.Session{Logger: &queryRecorder{sql: &queries}})

	ux.ExperimentArm(counting, 3, "unlisted-test")

	assert.Empty(t, queries)
}

// brokenServerArmDB is a handle on a database that is not there: every query fails at once.
func brokenServerArmDB(t *testing.T) *gorm.DB {
	t.Helper()
	db, err := gorm.Open(mysql.New(mysql.Config{
		DSN:                       "nobody:nothing@tcp(127.0.0.1:1)/nodb",
		SkipInitializeWithVersion: true,
	}), &gorm.Config{DisableAutomaticPing: true})
	require.NoError(t, err)

	return db
}

func TestServerArmAReadErrorIsControlAndIsNotAskedAgainForAMinute(t *testing.T) {
	now := serverArmWorld(t)
	clock := time.Unix(now, 0).UTC()
	t.Cleanup(ux.SetServerArmClockForTest(func() time.Time { return clock }))
	serverArmConfig(t, serverArmEntry(`{"control":[0,49],"items":[50,99]}`, `"until":`+strconv.FormatInt(now+3600, 10)), "1", true)

	assert.Equal(t, "", ux.ExperimentArm(brokenServerArmDB(t), 3, serverArmName))

	// The database is back, but the failure is remembered for the minute.
	assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, serverArmName))
	clock = clock.Add(time.Minute)
	assert.Equal(t, "items", ux.ExperimentArm(database.DBConn, 3, serverArmName))
}

func TestServerArmNoDatabaseIsControl(t *testing.T) {
	serverArmWorld(t)

	assert.Equal(t, "", ux.ExperimentArm(nil, 3, serverArmName))
}

// queryRecorder is a GORM logger that keeps the SQL of every statement it sees.
type queryRecorder struct {
	sql *[]string
}

func (q *queryRecorder) LogMode(_ logger.LogLevel) logger.Interface          { return q }
func (q *queryRecorder) Info(_ context.Context, _ string, _ ...interface{})  {}
func (q *queryRecorder) Warn(_ context.Context, _ string, _ ...interface{})  {}
func (q *queryRecorder) Error(_ context.Context, _ string, _ ...interface{}) {}
func (q *queryRecorder) Trace(_ context.Context, _ time.Time, fc func() (string, int64), _ error) {
	s, _ := fc()
	*q.sql = append(*q.sql, s)
}

// ----------------------------------------------------------------------------- exposures

// exposureRows returns the rows RecordExposure wrote for an experiment, oldest first.
func exposureRows(experiment string) []map[string]interface{} {
	var rows []map[string]interface{}
	database.DBConn.Raw("SELECT *, UNIX_TIMESTAMP(exposed_at) AS exposed_unix FROM ux_experiment_exposures WHERE experiment = ? ORDER BY id", experiment).Scan(&rows)

	return rows
}

func exposureClean(t *testing.T, experiment string) {
	t.Helper()
	database.DBConn.Exec("DELETE FROM ux_experiment_exposures WHERE experiment = ?", experiment)
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM ux_experiment_exposures WHERE experiment = ?", experiment) })
}

func TestExposureRecordsOneRowWithTheUTCTimeAndIdsOnly(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)
	stratum := "in"

	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "promise", 31, &stratum, map[string]any{"msgid": 555, "hours": 20}))

	rows := exposureRows(name)
	require.Len(t, rows, 1)
	assert.Equal(t, "items", rows[0]["arm"])
	assert.EqualValues(t, 7, rows[0]["userid"])
	assert.Equal(t, "promise", rows[0]["subject_type"])
	assert.EqualValues(t, 31, rows[0]["subject_id"])
	assert.Equal(t, "in", rows[0]["stratum"])
	assert.JSONEq(t, `{"msgid":555,"hours":20}`, fmt.Sprint(rows[0]["detail"]))

	stamped, err := strconv.ParseFloat(fmt.Sprint(rows[0]["exposed_unix"]), 64)
	require.NoError(t, err)
	assert.InDelta(t, float64(time.Now().Unix()), stamped, 10, "the time is UTC and is now, so a zone mistake of an hour would show")
}

func TestExposureWithNoStratumOrDetailStoresNulls(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)

	require.NoError(t, ux.RecordExposure(database.DBConn, name, "control", 7, "email", 31, nil, nil))

	rows := exposureRows(name)
	require.Len(t, rows, 1)
	assert.Nil(t, rows[0]["stratum"])
	assert.Nil(t, rows[0]["detail"])
	assert.Equal(t, "control", rows[0]["arm"], "control is written too")
}

func TestExposureTwiceForOneSubjectGivesOneRowAndTheFirstStays(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)

	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "email", 31, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "email", 31, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "control", 7, "email", 31, nil, nil))

	rows := exposureRows(name)
	require.Len(t, rows, 1)
	assert.Equal(t, "items", rows[0]["arm"])

	// A different member, subject or type is a row of its own.
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 8, "email", 31, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "email", 32, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "push", 31, nil, nil))
	assert.Len(t, exposureRows(name), 4)
}

func TestExposureWithTheArmEmptyOrNoMemberWritesNothing(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)

	require.NoError(t, ux.RecordExposure(database.DBConn, name, "", 7, "email", 31, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 0, "email", 31, nil, nil))

	assert.Empty(t, exposureRows(name))
}

func TestExposureAValueLongerThanItsColumnIsSkippedNotCut(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)
	long := strings.Repeat("x", 17)

	require.NoError(t, ux.RecordExposure(database.DBConn, name, long, 7, "email", 31, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, long, 31, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "email", 31, &long, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, strings.Repeat("n", 65), "items", 7, "email", 31, nil, nil))
	assert.Empty(t, exposureRows(name))
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures WHERE userid = 7 AND experiment LIKE 'nnnn%'").Scan(&n)
	assert.EqualValues(t, 0, n)

	sixteen := strings.Repeat("a", 16)
	require.NoError(t, ux.RecordExposure(database.DBConn, name, sixteen, 7, sixteen, 31, &sixteen, nil))
	assert.Len(t, exposureRows(name), 1, "exactly sixteen is kept")
}

func TestExposureDetailThatHoldsMoreThanIdsIsRefused(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)

	refused := []map[string]any{
		{"address": "1 High Street"},
		{"ids": []int{1, 2}},
		{"who": map[string]any{"a": 1}},
		{"MsgId": 5},
		{strings.Repeat("k", 33): 5},
	}
	for i, detail := range refused {
		require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "email", uint64(100+i), nil, detail))
	}
	assert.Empty(t, exposureRows(name))

	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "email", 1, nil, map[string]any{"msgid": 5, "rate": 0.25, "held": true, "none": nil}))
	rows := exposureRows(name)
	require.Len(t, rows, 1)
	assert.JSONEq(t, `{"msgid":5,"rate":0.25,"held":true,"none":null}`, fmt.Sprint(rows[0]["detail"]))
}

func TestExposureIsWrittenInTheTransactionOfTheThingDecided(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)

	tx := database.DBConn.Begin()
	require.NoError(t, ux.RecordExposure(tx, name, "items", 7, "promise", 31, nil, nil))
	tx.Rollback()
	assert.Empty(t, exposureRows(name), "a rolled back promise leaves no exposure")

	tx = database.DBConn.Begin()
	require.NoError(t, ux.RecordExposure(tx, name, "items", 7, "promise", 31, nil, nil))
	require.NoError(t, tx.Commit().Error)
	assert.Len(t, exposureRows(name), 1)
}

func TestExposureAFailureIsReturnedAndDoesNotPanic(t *testing.T) {
	name := uniquePrefix("expo")

	err := ux.RecordExposure(brokenServerArmDB(t), name, "items", 7, "email", 31, nil, nil)

	assert.Error(t, err)
}

// ----------------------------------------------------------------------------- merging two accounts

func TestExposureMergeMovesTheRowsToTheSurvivingAccount(t *testing.T) {
	prefix := uniquePrefix("expmerge")
	_, adminToken := mergeAdminSetup(t, prefix)
	id1 := CreateTestUser(t, prefix+"_u1", "User") // merged away
	id2 := CreateTestUser(t, prefix+"_u2", "User") // survives
	exposureClean(t, prefix)

	require.NoError(t, ux.RecordExposure(database.DBConn, prefix, "items", id1, "email", 1, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, prefix, "items", id1, "email", 2, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, prefix, "control", id2, "email", 3, nil, nil))

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var onSurvivor, onMergedAway int64
	database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures WHERE experiment = ? AND userid = ?", prefix, id2).Scan(&onSurvivor)
	database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures WHERE experiment = ? AND userid = ?", prefix, id1).Scan(&onMergedAway)
	assert.EqualValues(t, 3, onSurvivor)
	assert.EqualValues(t, 0, onMergedAway)
}

func TestExposureMergeLeavesARowThatClashesWhereItIsWithoutAnError(t *testing.T) {
	prefix := uniquePrefix("expmergeclash")
	_, adminToken := mergeAdminSetup(t, prefix)
	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	exposureClean(t, prefix)

	// The surviving account already has a row for subject 1, so the other account's row cannot move.
	require.NoError(t, ux.RecordExposure(database.DBConn, prefix, "items", id2, "email", 1, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, prefix, "items", id1, "email", 1, nil, nil))
	require.NoError(t, ux.RecordExposure(database.DBConn, prefix, "items", id1, "email", 2, nil, nil))

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var onSurvivor, onMergedAway int64
	database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures WHERE experiment = ? AND userid = ?", prefix, id2).Scan(&onSurvivor)
	database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures WHERE experiment = ? AND userid = ?", prefix, id1).Scan(&onMergedAway)
	assert.EqualValues(t, 2, onSurvivor, "the row that could move did")
	assert.EqualValues(t, 1, onMergedAway, "the clashing row stayed")
}

func TestExposureMergeWorksWhenTheTableIsMissing(t *testing.T) {
	prefix := uniquePrefix("expmergemissing")
	_, adminToken := mergeAdminSetup(t, prefix)
	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	db := database.DBConn
	require.NoError(t, db.Exec("RENAME TABLE ux_experiment_exposures TO ux_experiment_exposures_hidden").Error)
	t.Cleanup(func() { db.Exec("RENAME TABLE ux_experiment_exposures_hidden TO ux_experiment_exposures") })

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2), "a merge must not depend on an analytics table")

	// The merge ran: the merged account no longer has its emails.
	var emails int64
	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ?", id1).Scan(&emails)
	assert.EqualValues(t, 0, emails)
}

// ----------------------------------------------------------------------------- corpse control

// Every ux.ExperimentArm(db, id, "<name>") in the Go API must be in ux.ServerSwitches with the day it was
// opened, and every listed switch must still have a call. The name, the last argument, must be a string
// literal so this check can see it. It is the same rule as the browser's
// (iznik-nuxt3/tests/unit/uxExperimentsManifest.spec.js) and the batch's
// (iznik-batch/tests/Unit/Ux/UxSwitchManifestTest.php).
//
// An entry older than 14 days only warns: a missed cleanup must be loud, not a reason to turn every
// other change red. One older than 30 days, unless its KeepUntil is still ahead, fails the build.
//
// The scan is unit tested on sample source as well as run over the tree, so a tree found empty cannot
// mean it checks nothing.

const (
	serverSwitchWarnAfterDays = 14
	serverSwitchFailAfterDays = 30
	uxPackagePath             = "github.com/freegle/iznik-server-go/ux"
)

var (
	serverSwitchNameRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	serverSwitchDateRe = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)
)

type serverSwitchCall struct {
	name string // empty when the name is not a string literal
	file string
}

// serverSwitchCallsIn returns the calls to ux.ExperimentArm in some Go source. Comments are not code,
// so an example in one is not a call. The package is found by its import path, so an alias is seen.
func serverSwitchCallsIn(src, file string) ([]serverSwitchCall, error) {
	f, err := parser.ParseFile(token.NewFileSet(), file, src, 0)
	if err != nil {
		return nil, err
	}

	local := "ux"
	for _, imp := range f.Imports {
		if p, _ := strconv.Unquote(imp.Path.Value); p == uxPackagePath && imp.Name != nil {
			local = imp.Name.Name
		}
	}

	var calls []serverSwitchCall
	ast.Inspect(f, func(n ast.Node) bool {
		call, ok := n.(*ast.CallExpr)
		if !ok {
			return true
		}
		sel, ok := call.Fun.(*ast.SelectorExpr)
		if !ok || sel.Sel.Name != "ExperimentArm" {
			return true
		}
		if x, ok := sel.X.(*ast.Ident); !ok || x.Name != local {
			return true
		}

		name := ""
		if len(call.Args) > 0 {
			if lit, ok := call.Args[len(call.Args)-1].(*ast.BasicLit); ok && lit.Kind == token.STRING {
				if s, err := strconv.Unquote(lit.Value); err == nil && serverSwitchNameRe.MatchString(s) {
					name = s
				}
			}
		}
		calls = append(calls, serverSwitchCall{name: name, file: file})

		return true
	})

	return calls, nil
}

// scanServerSwitchTree reads every Go file under root that could call ux.ExperimentArm. It leaves out
// the file that defines it, tests, and the top-level test directory, which are not callers.
func scanServerSwitchTree(root string) (calls []serverSwitchCall, files int, err error) {
	err = filepath.WalkDir(root, func(path string, d os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		rel, _ := filepath.Rel(root, path)
		rel = filepath.ToSlash(rel)
		if d.IsDir() {
			switch d.Name() {
			case "vendor", "node_modules", ".git":
				return filepath.SkipDir
			}
			if rel == "test" {
				return filepath.SkipDir
			}

			return nil
		}
		if !strings.HasSuffix(rel, ".go") {
			return nil
		}
		files++
		if strings.HasSuffix(rel, "_test.go") || rel == "ux/serverarm.go" {
			return nil
		}
		b, readErr := os.ReadFile(path)
		if readErr != nil {
			return readErr
		}
		if !strings.Contains(string(b), "ExperimentArm(") {
			return nil
		}
		found, parseErr := serverSwitchCallsIn(string(b), rel)
		if parseErr != nil {
			return fmt.Errorf("%s: %w", rel, parseErr)
		}
		calls = append(calls, found...)

		return nil
	})

	return calls, files, err
}

func serverSwitchAge(opened string, today time.Time) int {
	t, _ := time.Parse("2006-01-02", opened)

	return int(today.Sub(t).Hours() / 24)
}

func serverSwitchKept(s ux.ServerSwitch, today time.Time) bool {
	if s.KeepUntil == "" {
		return false
	}
	t, err := time.Parse("2006-01-02", s.KeepUntil)

	return err == nil && !t.Before(today.Truncate(24*time.Hour))
}

func sortedSwitchNames(list map[string]ux.ServerSwitch) []string {
	names := make([]string, 0, len(list))
	for n := range list {
		names = append(names, n)
	}
	sort.Strings(names)

	return names
}

// serverSwitchWarnings are the entries past the soft limit, which only warn.
func serverSwitchWarnings(list map[string]ux.ServerSwitch, today time.Time) []string {
	var out []string
	for _, name := range sortedSwitchNames(list) {
		s := list[name]
		if !serverSwitchDateRe.MatchString(s.Opened) {
			continue
		}
		if age := serverSwitchAge(s.Opened, today); age > serverSwitchWarnAfterDays && !serverSwitchKept(s, today) {
			out = append(out, fmt.Sprintf("%s: opened %s, %d days ago. Clean it up (it fails the build at %d days) or set KeepUntil with a reason.", name, s.Opened, age, serverSwitchFailAfterDays))
		}
	}

	return out
}

// serverSwitchProblems is what is wrong with the list and the calls.
func serverSwitchProblems(list map[string]ux.ServerSwitch, calls []serverSwitchCall, today time.Time) []string {
	var problems []string
	for _, name := range sortedSwitchNames(list) {
		s := list[name]
		if s.Name != name {
			problems = append(problems, fmt.Sprintf("%s: the entry is keyed by one name and says it is %q", name, s.Name))
		}
		if !serverSwitchDateRe.MatchString(s.Opened) {
			problems = append(problems, name+": Opened must be YYYY-MM-DD")

			continue
		}
		if age := serverSwitchAge(s.Opened, today); age > serverSwitchFailAfterDays && !serverSwitchKept(s, today) {
			problems = append(problems, fmt.Sprintf("%s: opened %s, %d days ago, past %d; clean it up or set KeepUntil with a reason", name, s.Opened, age, serverSwitchFailAfterDays))
		}
	}

	called := map[string]bool{}
	for _, c := range calls {
		if c.name == "" {
			problems = append(problems, c.file+": a ux.ExperimentArm call whose name is not a string literal, so ux.ServerSwitches cannot track it")

			continue
		}
		called[c.name] = true
		if _, ok := list[c.name]; !ok {
			problems = append(problems, fmt.Sprintf("%s: used in %s but not in ux.ServerSwitches", c.name, c.file))
		}
	}
	for _, name := range sortedSwitchNames(list) {
		if !called[name] {
			problems = append(problems, fmt.Sprintf("%s: in ux.ServerSwitches but no ux.ExperimentArm(db, id, %q) left in the code; remove the entry", name, name))
		}
	}

	return problems
}

func callsIn(t *testing.T, src string) []string {
	t.Helper()
	calls, err := serverSwitchCallsIn("package p\n"+src, "p.go")
	require.NoError(t, err)
	out := make([]string, 0, len(calls))
	for _, c := range calls {
		out = append(out, c.name)
	}

	return out
}

func TestServerSwitchesTheCheckerFindsACallInEachOfItsSpellings(t *testing.T) {
	assert.Equal(t, []string{"give-hint"}, callsIn(t, `func f() { _ = ux.ExperimentArm(db, 7, "give-hint") }`))
	assert.Equal(t, []string{"a_b"}, callsIn(t, "func f() { _ = ux.ExperimentArm(db, 7, `a_b`) }"))
	assert.Equal(t, []string{"x"}, callsIn(t, "func f() {\n\tarm := ux.ExperimentArm(\n\t\tdatabase.DBConn,\n\t\tuint64(msg.Fromuser),\n\t\t\"x\",\n\t)\n\t_ = arm\n}"))
	assert.Equal(t, []string{"x"}, callsIn(t, `func f() { if ux.ExperimentArm(h(1, 2), id(3), "x") == "items" { } }`), "arguments with brackets and commas in them")
	assert.Equal(t, []string{"a", "b", "a"}, callsIn(t, `func f() { ux.ExperimentArm(db, 1, "a"); ux.ExperimentArm(db, 2, "b"); ux.ExperimentArm(db, 3, "a") }`))
}

func TestServerSwitchesTheCheckerFollowsAnImportAlias(t *testing.T) {
	calls, err := serverSwitchCallsIn("package p\nimport uxpkg \"github.com/freegle/iznik-server-go/ux\"\nfunc f() { uxpkg.ExperimentArm(db, 1, \"aliased\") }", "p.go")
	require.NoError(t, err)
	require.Len(t, calls, 1)
	assert.Equal(t, "aliased", calls[0].name)

	// A different package with a function of the same name is not ours.
	assert.Empty(t, callsIn(t, `func f() { other.ExperimentArm(db, 1, "x") }`))
}

func TestServerSwitchesTheCheckerIgnoresAComment(t *testing.T) {
	assert.Empty(t, callsIn(t, "// ux.ExperimentArm(db, 1, \"doc-example\")\n/* ux.ExperimentArm(db, 1, \"block\") */\nfunc f() {}"))
	assert.Empty(t, callsIn(t, "func f() { _ = \"ux.ExperimentArm(db, 1, \\\"in-a-string\\\")\" }"))
}

func TestServerSwitchesTheCheckerIgnoresOtherFunctions(t *testing.T) {
	assert.Empty(t, callsIn(t, `func f() { ux.RecordExposure(tx, "x", "items", 1, "email", 1, nil, nil); ux.ExperimentBucket(1, "x", ""); ExperimentArm(db, 1, "x") }`))
}

func TestServerSwitchesTheCheckerFlagsANameThatIsNotALiteral(t *testing.T) {
	assert.Equal(t, []string{""}, callsIn(t, `func f() { ux.ExperimentArm(db, 1, name) }`))
	assert.Equal(t, []string{""}, callsIn(t, `func f() { ux.ExperimentArm(db, 1, ConfirmReply) }`))
	assert.Equal(t, []string{""}, callsIn(t, `func f() { ux.ExperimentArm(db, 1, "a"+b) }`))
	assert.Equal(t, []string{""}, callsIn(t, `func f() { ux.ExperimentArm(db, 1, "bad name") }`), "nor is a name the engine would not accept")
	assert.Equal(t, []string{""}, callsIn(t, `func f() { ux.ExperimentArm() }`))
}

func TestServerSwitchesTheCheckerRefusesSourceItCannotRead(t *testing.T) {
	_, err := serverSwitchCallsIn("package p\nfunc f( {", "broken.go")

	assert.Error(t, err)
}

func switchList(entries ...ux.ServerSwitch) map[string]ux.ServerSwitch {
	out := map[string]ux.ServerSwitch{}
	for _, e := range entries {
		out[e.Name] = e
	}

	return out
}

func TestServerSwitchesTheListAndTheCalls(t *testing.T) {
	today := time.Date(2026, 10, 14, 12, 0, 0, 0, time.UTC)
	call := func(names ...string) []serverSwitchCall {
		var out []serverSwitchCall
		for _, n := range names {
			out = append(out, serverSwitchCall{name: n, file: "a.go"})
		}

		return out
	}

	t.Run("a fresh listed switch passes", func(t *testing.T) {
		assert.Empty(t, serverSwitchProblems(switchList(ux.ServerSwitch{Name: "x", Opened: "2026-10-10"}), call("x"), today))
	})
	t.Run("a call with no entry fails", func(t *testing.T) {
		assert.Equal(t, []string{"x: used in a.go but not in ux.ServerSwitches"}, serverSwitchProblems(switchList(), call("x"), today))
	})
	t.Run("an entry with no call left fails", func(t *testing.T) {
		p := serverSwitchProblems(switchList(ux.ServerSwitch{Name: "gone", Opened: "2026-10-10"}), nil, today)
		require.Len(t, p, 1)
		assert.Contains(t, p[0], `no ux.ExperimentArm(db, id, "gone") left in the code`)
	})
	t.Run("an entry past the hard limit fails", func(t *testing.T) {
		p := serverSwitchProblems(switchList(ux.ServerSwitch{Name: "old", Opened: "2026-09-01"}), call("old"), today)
		require.Len(t, p, 1)
		assert.Contains(t, p[0], "past 30")
	})
	t.Run("between 14 and 30 days warns but does not fail", func(t *testing.T) {
		list := switchList(ux.ServerSwitch{Name: "late", Opened: "2026-09-25"})
		assert.Empty(t, serverSwitchProblems(list, call("late"), today))
		w := serverSwitchWarnings(list, today)
		require.Len(t, w, 1)
		assert.Contains(t, w[0], "19 days")
	})
	t.Run("the edges of the limits", func(t *testing.T) {
		at := func(opened string) map[string]ux.ServerSwitch {
			return switchList(ux.ServerSwitch{Name: "x", Opened: opened})
		}
		assert.Empty(t, serverSwitchWarnings(at("2026-09-30"), today), "14 days is not yet past the soft limit")
		assert.Len(t, serverSwitchWarnings(at("2026-09-29"), today), 1, "15 days is")
		assert.Empty(t, serverSwitchProblems(at("2026-09-14"), call("x"), today), "30 days is not yet past the hard limit")
		assert.Len(t, serverSwitchProblems(at("2026-09-13"), call("x"), today), 1, "31 days is")
	})
	t.Run("a fresh or deliberately kept entry does not warn", func(t *testing.T) {
		list := switchList(ux.ServerSwitch{Name: "new", Opened: "2026-10-10"}, ux.ServerSwitch{Name: "kept", Opened: "2026-09-01", KeepUntil: "2026-11-01"})
		assert.Empty(t, serverSwitchWarnings(list, today))
	})
	t.Run("kept until a future date passes and until the past does not", func(t *testing.T) {
		assert.Empty(t, serverSwitchProblems(switchList(ux.ServerSwitch{Name: "old", Opened: "2026-09-01", KeepUntil: "2026-11-01"}), call("old"), today))
		assert.Len(t, serverSwitchProblems(switchList(ux.ServerSwitch{Name: "old", Opened: "2026-09-01", KeepUntil: "2026-10-01"}), call("old"), today), 1)
	})
	t.Run("a bad date, a mismatched key and a name that is not a literal fail", func(t *testing.T) {
		assert.Equal(t, []string{"x: Opened must be YYYY-MM-DD"}, serverSwitchProblems(switchList(ux.ServerSwitch{Name: "x", Opened: "last week"}), call("x"), today))
		keyed := serverSwitchProblems(map[string]ux.ServerSwitch{"x": {Name: "y", Opened: "2026-10-10"}}, call("x"), today)
		require.Len(t, keyed, 1)
		assert.Contains(t, keyed[0], `the entry is keyed by one name and says it is "y"`)
		p := serverSwitchProblems(switchList(), []serverSwitchCall{{name: "", file: "b.go"}}, today)
		require.Len(t, p, 1)
		assert.Contains(t, p[0], "b.go: a ux.ExperimentArm call whose name is not a string literal")
	})
}

func TestServerSwitchesTheTreeScanFindsACallThatIsThereAndSkipsWhatIsNotACaller(t *testing.T) {
	root := t.TempDir()
	write := func(rel, src string) {
		require.NoError(t, os.MkdirAll(filepath.Dir(filepath.Join(root, rel)), 0o755))
		require.NoError(t, os.WriteFile(filepath.Join(root, rel), []byte(src), 0o644))
	}
	write("chat/chat.go", "package chat\nimport \"github.com/freegle/iznik-server-go/ux\"\nfunc f() { ux.ExperimentArm(nil, 1, \"probe-switch\") }\n")
	write("chat/careless.go", "package chat\nfunc g(name string) { ux.ExperimentArm(nil, 1, name) }\n")
	write("ux/serverarm.go", "package ux\nfunc ExperimentArm() {}\nfunc h() { ux.ExperimentArm(nil, 1, \"the-owners-own-example\") }\n")
	write("chat/chat_test.go", "package chat\nfunc TestX() { ux.ExperimentArm(nil, 1, \"in-a-test\") }\n")
	write("test/thing.go", "package test\nfunc g() { ux.ExperimentArm(nil, 1, \"in-the-test-dir\") }\n")
	write("vendor/x/x.go", "package x\nfunc g() { ux.ExperimentArm(nil, 1, \"vendored\") }\n")
	write("chat/notes.txt", "ux.ExperimentArm(nil, 1, \"not-go\")")

	calls, files, err := scanServerSwitchTree(root)

	require.NoError(t, err)
	assert.Equal(t, 4, files, "the four Go files outside the test directory and vendor were seen; the test file and the file that defines the call were not read for calls")
	require.Len(t, calls, 2)
	names := []string{calls[0].name, calls[1].name}
	sort.Strings(names)
	assert.Equal(t, []string{"", "probe-switch"}, names)
}

func TestServerSwitchesEveryCallInTheCodeIsListedNoneIsDeadNoneIsTooOldAndEveryNameIsALiteral(t *testing.T) {
	calls, files, err := scanServerSwitchTree("..")

	require.NoError(t, err)
	assert.Greater(t, files, 100, "the scan found the tree: an empty one would check nothing")
	assert.Empty(t, serverSwitchProblems(ux.ServerSwitches, calls, time.Now().UTC()))
}

func TestServerSwitchesAnExperimentAddedLaterWithItsEntryAndItsCallPassesOverTheRealTree(t *testing.T) {
	// The change that adds the first experiment adds its entry to ux.ServerSwitches and its ux.ExperimentArm call in
	// the same commit. The check has to pass for that change, whatever the list held before it.
	today := time.Date(2026, 10, 14, 12, 0, 0, 0, time.UTC)
	calls, _, err := scanServerSwitchTree("..")
	require.NoError(t, err)
	list := map[string]ux.ServerSwitch{}
	for name, entry := range ux.ServerSwitches {
		list[name] = entry
	}
	list["added-later"] = ux.ServerSwitch{Name: "added-later", Opened: "2026-10-10", Channel: "server", Type: "later", Arms: []string{"control", "b"}}
	withCall := append(append([]serverSwitchCall{}, calls...), serverSwitchCall{name: "added-later", file: "chat/later.go"})

	assert.Empty(t, serverSwitchProblems(list, withCall, today))
	alone := serverSwitchProblems(list, calls, today)
	require.Len(t, alone, 1, "and the entry alone, with no call, is still found")
	assert.Contains(t, alone[0], "added-later")
}

func TestServerSwitchesSaysSoWithoutFailingAboutAnEntryLeftPast14Days(t *testing.T) {
	for _, w := range serverSwitchWarnings(ux.ServerSwitches, time.Now().UTC()) {
		t.Logf("UX experiment cleanup overdue: %s", w)
	}
}

func TestServerSwitchesAnExperimentNotListedIsControlWhateverTheConfigSays(t *testing.T) {
	now := serverArmWorld(t)
	serverArmConfig(t, `{"unlisted-one":{"arms":{"items":[0,99]},"until":`+strconv.FormatInt(now+3600, 10)+`}}`, "1", true)

	assert.Equal(t, "", ux.ExperimentArm(database.DBConn, 3, "unlisted-one"))
}

func TestExposureDetailThatCannotBeWrittenAsJSONIsReturnedAsAnErrorAndWritesNothing(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)

	// A float passes the check on the kind of value, and JSON has no way to write NaN.
	err := ux.RecordExposure(database.DBConn, name, "items", 7, "email", 1, nil, map[string]any{"rate": math.NaN()})

	assert.Error(t, err)
	assert.Empty(t, exposureRows(name))
}

func TestExposureDetailMayHoldAnyKindOfWholeNumber(t *testing.T) {
	name := uniquePrefix("expo")
	exposureClean(t, name)

	require.NoError(t, ux.RecordExposure(database.DBConn, name, "items", 7, "email", 1, nil, map[string]any{
		"a": int8(1), "b": int16(2), "c": int32(3), "d": int64(4), "e": uint8(5), "f": uint16(6), "g": uint32(7), "h": uint64(8), "i": uint(9), "j": float32(0.5),
	}))

	rows := exposureRows(name)
	require.Len(t, rows, 1)
	assert.JSONEq(t, `{"a":1,"b":2,"c":3,"d":4,"e":5,"f":6,"g":7,"h":8,"i":9,"j":0.5}`, fmt.Sprint(rows[0]["detail"]))
}
