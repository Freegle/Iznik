package main

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// These tests run every loader against a real source database on each engine: a scratch database
// holding the tables the loaders read, shaped as production has them, seeded with the same rows.
// Each engine must give the index the same contents, so the expectations are written once.
//
// Both engines are required by default. The service is configured with the MYSQL_* and PGSQL_*
// variables, and the tests read the same ones. SPATIAL_TEST_ENGINES=postgres (or mysql) runs one
// engine only; that is a choice made by name, never a silent skip when a database is missing.
//
// The R-tree keeps bounding boxes as 32-bit floats, rounded outward, so box assertions allow 1e-4.
//
// The scratch database is dropped and rebuilt for each test and dropped when the run ends.

const scratchDatabase = "spatial_loader_test"

var (
	// Times as the fixtures write them: one before and one after the delta horizon.
	tsOld   = "2026-11-12 09:00:00"
	tsNew   = "2026-11-12 11:00:00"
	tsLater = "2026-11-12 12:00:00"
	horizon = time.Date(2026, 11, 12, 10, 0, 0, 0, time.UTC)
)

func requestedEngines(t *testing.T) []string {
	t.Helper()
	v := strings.TrimSpace(os.Getenv("SPATIAL_TEST_ENGINES"))
	if v == "" {
		return []string{engineMySQL, enginePostgres}
	}
	var out []string
	for _, e := range strings.Split(v, ",") {
		e = strings.ToLower(strings.TrimSpace(e))
		require.Contains(t, []string{engineMySQL, enginePostgres}, e, "SPATIAL_TEST_ENGINES holds mysql and postgres")
		out = append(out, e)
	}
	return out
}

// forEachEngine runs fn as a subtest per engine, each with a freshly seeded scratch database.
func forEachEngine(t *testing.T, fn func(t *testing.T, e *engineDB)) {
	t.Helper()
	for _, name := range requestedEngines(t) {
		t.Run(name, func(t *testing.T) {
			fn(t, newEngineDB(t, name))
		})
	}
}

// engineDB is the source database under test, opened the way the service opens it.
type engineDB struct {
	t    *testing.T
	name string
	db   *sql.DB
	dl   sourceDialect
}

func testGetenv(engine, database string) func(key, def string) string {
	return func(key, def string) string {
		switch key {
		case "SPATIAL_SOURCE_ENGINE":
			return engine
		case "MYSQL_DBNAME", "PGSQL_DATABASE":
			if database != "" {
				return database
			}
		}
		return getenv(key, def)
	}
}

var (
	scratchOnce    sync.Map // engine -> *sync.Once
	scratchCreated sync.Map // engine -> bool
)

// TestMain drops the scratch databases once the run is over.
func TestMain(m *testing.M) {
	code := m.Run()
	scratchCreated.Range(func(k, _ any) bool {
		if admin, err := openSource(testGetenv(k.(string), "")); err == nil {
			dropScratch(admin)
			admin.Close()
		}
		return true
	})
	os.Exit(code)
}

func dropScratch(admin *sql.DB) {
	if dialectOf(admin).engine() == enginePostgres {
		_, _ = admin.Exec(`DROP DATABASE IF EXISTS ` + scratchDatabase + ` WITH (FORCE)`)
		return
	}
	_, _ = admin.Exec("DROP DATABASE IF EXISTS " + scratchDatabase)
}

// unreachable remembers an engine that did not answer, so one missing database fails each test
// at once and not after a minute apiece.
var unreachable sync.Map // engine -> error

// waitFor pings until the database answers, as the stack's own start-up does.
func waitFor(t *testing.T, db *sql.DB, engine string) {
	t.Helper()
	if err, ok := unreachable.Load(engine); ok {
		t.Fatalf("the %s source database is not reachable: %v", engine, err)
	}
	deadline := time.Now().Add(60 * time.Second)
	var err error
	for {
		if err = db.Ping(); err == nil {
			return
		}
		if time.Now().After(deadline) {
			unreachable.Store(engine, err)
			t.Fatalf("the %s source database is not reachable: %v\n"+
				"These tests need both engines; SPATIAL_TEST_ENGINES=<engine> runs one.", engine, err)
		}
		time.Sleep(2 * time.Second)
	}
}

func newEngineDB(t *testing.T, name string) *engineDB {
	t.Helper()

	admin, err := openSource(testGetenv(name, ""))
	require.NoError(t, err)
	t.Cleanup(func() { admin.Close() })
	waitFor(t, admin, name)

	once, _ := scratchOnce.LoadOrStore(name, &sync.Once{})
	once.(*sync.Once).Do(func() {
		dropScratch(admin)
		_, err := admin.Exec("CREATE DATABASE " + scratchDatabase)
		require.NoError(t, err)
		scratchCreated.Store(name, true)
	})

	db, err := openSource(testGetenv(name, scratchDatabase))
	require.NoError(t, err)
	t.Cleanup(func() { db.Close() })

	e := &engineDB{t: t, name: name, db: db, dl: dialectOf(db)}
	require.Equal(t, name, e.dl.engine())
	if name == enginePostgres {
		e.exec(`CREATE EXTENSION IF NOT EXISTS postgis`)
		e.exec(`CREATE EXTENSION IF NOT EXISTS citext`)
	}
	e.rebuildTables()

	// The reach probes run once per process and describe one database.
	resetReachProbes()
	t.Cleanup(resetReachProbes)
	return e
}

func resetReachProbes() {
	gridColsOnce = sync.Once{}
	gridColsPresent = false
	unionColPresent = false
}

// exec runs a statement written with ? placeholders.
func (e *engineDB) exec(query string, args ...any) {
	e.t.Helper()
	_, err := e.db.Exec(e.dl.rebind(query), args...)
	require.NoError(e.t, err, query)
}

// geom is a geometry value from WKT. MySQL's production columns are SRID 3857 (labelled, but
// holding degrees) except the locations ones, which have no SRID. PostgreSQL's are all 4326.
func (e *engineDB) geom(wkt string, projected bool) string {
	if e.name == enginePostgres {
		return fmt.Sprintf("ST_SetSRID(ST_GeomFromText('%s'), 4326)", wkt)
	}
	if projected {
		return fmt.Sprintf("ST_GeomFromText('%s', 3857)", wkt)
	}
	return fmt.Sprintf("ST_GeomFromText('%s')", wkt)
}

func (e *engineDB) jsonParam() string {
	if e.name == enginePostgres {
		return "CAST(? AS jsonb)"
	}
	return "CAST(? AS JSON)"
}

var scratchTables = []string{
	"locations", "locations_excluded", "messages_spatial", "messages_promises", "newsfeed",
	"users_approxlocs", "groups", "jobs", "rippling_reach",
}

func (e *engineDB) rebuildTables() {
	e.t.Helper()
	for _, tbl := range scratchTables {
		e.exec("DROP TABLE IF EXISTS " + e.dl.quote(tbl))
	}
	ddl := mysqlScratchSchema
	if e.name == enginePostgres {
		ddl = postgresScratchSchema
	}
	for _, stmt := range ddl {
		e.exec(stmt)
	}
	e.seed()
}

// The tables and columns the loaders read, with production's types. The PostgreSQL ones are the
// converter's output for these tables (iznik-batch/database/postgres/baseline.sql); the MySQL
// ones are the migrated schema's.
var mysqlScratchSchema = []string{
	"CREATE TABLE locations (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, " +
		"type ENUM('Road','Polygon','Line','Point','Postcode') NOT NULL, geometry GEOMETRY DEFAULT NULL, " +
		"ourgeometry GEOMETRY DEFAULT NULL, `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) " +
		"DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
	"CREATE TABLE locations_excluded (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, locationid BIGINT NOT NULL)",
	"CREATE TABLE messages_spatial (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, msgid BIGINT NOT NULL, " +
		"point GEOMETRY NOT NULL SRID 3857, successful TINYINT NOT NULL DEFAULT 0, groupid BIGINT, " +
		"msgtype ENUM('Offer','Taken','Wanted','Received','Admin','Other') DEFAULT NULL, modified TIMESTAMP NULL DEFAULT NULL)",
	"CREATE TABLE messages_promises (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, msgid BIGINT NOT NULL)",
	"CREATE TABLE newsfeed (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, " +
		"type ENUM('Message','Story','Alert') NOT NULL DEFAULT 'Message', userid BIGINT, replyto BIGINT, " +
		"position GEOMETRY NOT NULL SRID 3857, deleted TIMESTAMP NULL DEFAULT NULL, modified TIMESTAMP NULL DEFAULT NULL)",
	"CREATE TABLE users_approxlocs (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, userid BIGINT NOT NULL, " +
		"lat DECIMAL(10,6) NOT NULL, lng DECIMAL(10,6) NOT NULL, position GEOMETRY NOT NULL SRID 3857)",
	"CREATE TABLE `groups` (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, nameshort VARCHAR(80), " +
		"publish TINYINT NOT NULL DEFAULT 1, listable TINYINT NOT NULL DEFAULT 1, polyindex GEOMETRY NOT NULL SRID 3857)",
	"CREATE TABLE jobs (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255), city VARCHAR(256), " +
		"company VARCHAR(256), bodyhash VARCHAR(32), cpc DECIMAL(4,4), geometry GEOMETRY NOT NULL SRID 3857, " +
		"seenat TIMESTAMP NULL DEFAULT NULL, visible TINYINT NOT NULL DEFAULT 0)",
	"CREATE TABLE rippling_reach (msgid BIGINT NOT NULL PRIMARY KEY, " +
		"status ENUM('expanding','stopped','done','held') NOT NULL DEFAULT 'expanding', polygon_cells MEDIUMBLOB, " +
		"origin_union_secs FLOAT, reach_labels MEDIUMBLOB, overflow_cells JSON DEFAULT NULL, " +
		"has_overflow TINYINT(1) GENERATED ALWAYS AS ((overflow_cells IS NOT NULL)) VIRTUAL, " +
		"updated_at TIMESTAMP NULL DEFAULT NULL)",
}

var postgresScratchSchema = []string{
	"CREATE TABLE locations (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, name citext NOT NULL, " +
		"type text NOT NULL, geometry geometry(Geometry,4326), ourgeometry geometry(Geometry,4326), " +
		"timestamp timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL, " +
		"CONSTRAINT locations_type_check CHECK (type IN ('Road', 'Polygon', 'Line', 'Point', 'Postcode')))",
	"CREATE TABLE locations_excluded (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, locationid bigint NOT NULL)",
	"CREATE TABLE messages_spatial (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, msgid bigint NOT NULL, " +
		"point geometry(Geometry,4326) NOT NULL, successful smallint DEFAULT 0 NOT NULL, groupid bigint, msgtype text, " +
		"modified timestamptz, CONSTRAINT messages_spatial_msgtype_check CHECK " +
		"(msgtype IN ('Offer', 'Taken', 'Wanted', 'Received', 'Admin', 'Other')))",
	"CREATE TABLE messages_promises (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, msgid bigint NOT NULL)",
	"CREATE TABLE newsfeed (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, " +
		"type text DEFAULT 'Message' NOT NULL, userid bigint, replyto bigint, position geometry(Geometry,4326) NOT NULL, " +
		"deleted timestamptz, modified timestamptz, " +
		"CONSTRAINT newsfeed_type_check CHECK (type IN ('Message', 'Story', 'Alert')))",
	"CREATE TABLE users_approxlocs (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, userid bigint NOT NULL, " +
		"lat numeric(10,6) NOT NULL, lng numeric(10,6) NOT NULL, position geometry(Geometry,4326) NOT NULL)",
	"CREATE TABLE groups (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, nameshort citext, " +
		"publish smallint DEFAULT 1 NOT NULL, listable smallint DEFAULT 1 NOT NULL, polyindex geometry(Geometry,4326) NOT NULL)",
	"CREATE TABLE jobs (id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL PRIMARY KEY, title citext, city varchar(256), " +
		"company varchar(256), bodyhash varchar(32), cpc numeric(4,4), geometry geometry(Geometry,4326) NOT NULL, " +
		"seenat timestamptz, visible smallint DEFAULT 0 NOT NULL)",
	"CREATE TABLE rippling_reach (msgid bigint NOT NULL PRIMARY KEY, " +
		"status text DEFAULT 'expanding' NOT NULL, polygon_cells bytea, origin_union_secs real, reach_labels bytea, " +
		"overflow_cells jsonb, has_overflow smallint GENERATED ALWAYS AS (((overflow_cells is not null))::int) STORED, " +
		"updated_at timestamptz, CONSTRAINT rippling_reach_status_check CHECK (status IN ('expanding', 'stopped', 'done', 'held')))",
}

// Polygons and rings the fixtures use, counter-clockwise.
const (
	wktBalham   = "POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))"           // 0.01 square degrees
	wktSW12     = "POLYGON((-0.105 51.495, -0.095 51.495, -0.095 51.505, -0.105 51.505, -0.105 51.495))" // 1e-4
	wktDistrict = "POLYGON((-0.305 51.395, -0.295 51.395, -0.295 51.405, -0.305 51.405, -0.305 51.395))"
	// Postcode geometries are polygons: the loaders take a centroid, and MySQL's ST_Centroid is
	// defined for polygons. The override's centroid is (-0.20, 51.55).
	wktOtherPostcode    = "POLYGON((-0.505 51.595, -0.495 51.595, -0.495 51.605, -0.505 51.605, -0.505 51.595))"
	wktOverride         = "POLYGON((-0.205 51.545, -0.195 51.545, -0.195 51.555, -0.205 51.555, -0.205 51.545))"
	wktExcludedPostcode = "POLYGON((-2.245 53.475, -2.235 53.475, -2.235 53.485, -2.245 53.485, -2.245 53.475))"
	wktExcluded         = "POLYGON((0.05 51.45, 0.15 51.45, 0.15 51.55, 0.05 51.55, 0.05 51.45))"
	wktHuge             = "POLYGON((1 52, 2 52, 2 53, 1 53, 1 52))" // 1 square degree: over the area cap
	wktGroupA           = "POLYGON((-0.2 51.4, 0 51.4, 0 51.6, -0.2 51.6, -0.2 51.4))"
	wktGroupB           = "POLYGON((0.1 51.4, 0.3 51.4, 0.3 51.6, 0.1 51.6, 0.1 51.4))"
	wktJob1             = "POLYGON((-1.60 53.78, -1.50 53.78, -1.50 53.82, -1.60 53.82, -1.60 53.78))"
	wktJob2             = "POLYGON((-1.10 53.94, -1.00 53.94, -1.00 53.98, -1.10 53.98, -1.10 53.94))"
	wktRingA            = "POLYGON((0 0, 0.003 0, 0.003 0.003, 0 0.003, 0 0))"
	wktRingB            = "POLYGON((0.01 0.01, 0.013 0.01, 0.013 0.013, 0.01 0.013, 0.01 0.01))"
	wktReachA           = "POLYGON((0 0, 0.01 0, 0.01 0.01, 0 0.01, 0 0))"
	wktReachB           = "POLYGON((0.02 0.02, 0.03 0.02, 0.03 0.03, 0.02 0.03, 0.02 0.02))"
	wktReachC           = "POLYGON((0.04 0.04, 0.05 0.04, 0.05 0.05, 0.04 0.05, 0.04 0.04))"
)

func overflowJSON(t *testing.T, rings map[string]string) string {
	t.Helper()
	// Lane paths are "$.a.b" or `$.a."1"`; the document nests them the same way.
	doc := map[string]any{}
	for path, wkt := range rings {
		parts := strings.Split(strings.TrimPrefix(path, "$."), ".")
		cur := doc
		for i, p := range parts {
			p = strings.Trim(p, `"`)
			if i == len(parts)-1 {
				cur[p] = ringCellsB64(t, wkt)
				break
			}
			next, ok := cur[p].(map[string]any)
			if !ok {
				next = map[string]any{}
				cur[p] = next
			}
			cur = next
		}
	}
	b, err := json.Marshal(doc)
	require.NoError(t, err)
	return string(b)
}

func (e *engineDB) seed() {
	t := e.t
	t.Helper()

	// locations: 1 area; 2 a postcode with a polygon; 3 a postcode with a polygon override; 4 a
	// district (no space); 5 an excluded area; 6 an area over the size cap; 7 an excluded postcode;
	// 8 a place that is only a point.
	loc := func(id int, name, typ, geometry, our, ts string) {
		e.exec(fmt.Sprintf("INSERT INTO locations (id, name, type, geometry, ourgeometry, %s) VALUES (?, ?, ?, %s, %s, ?)",
			e.dl.quote("timestamp"), geometry, our), id, name, typ, ts)
	}
	none := "NULL"
	loc(1, "Balham", "Polygon", e.geom(wktBalham, false), none, tsNew)
	loc(2, "ZZ12 3AB", "Postcode", e.geom(wktSW12, false), none, tsOld)
	loc(3, "ZZ6 1AB", "Postcode", e.geom(wktOtherPostcode, false), e.geom(wktOverride, false), tsNew)
	loc(4, "ZZ12", "Postcode", e.geom(wktDistrict, false), none, tsOld)
	loc(5, "Excluded", "Polygon", e.geom(wktExcluded, false), none, tsNew)
	loc(6, "Huge", "Polygon", e.geom(wktHuge, false), none, tsNew)
	loc(7, "ZZ1 1AA", "Postcode", e.geom(wktExcludedPostcode, false), none, tsNew)
	loc(8, "Pinpoint", "Polygon", e.geom("POINT(-0.60 51.70)", false), none, tsNew)
	e.exec("INSERT INTO locations_excluded (locationid) VALUES (5)")
	e.exec("INSERT INTO locations_excluded (locationid) VALUES (7)")

	// messages_spatial: 101 promised; 102 plain; 103 successful; 104 no type.
	msg := func(msgid int, wkt string, successful int, groupid int, msgtype any, modified string) {
		e.exec(fmt.Sprintf("INSERT INTO messages_spatial (msgid, point, successful, groupid, msgtype, modified) VALUES (?, %s, ?, ?, ?, ?)",
			e.geom(wkt, true)), msgid, successful, groupid, msgtype, modified)
	}
	msg(101, "POINT(-0.10 51.50)", 0, 11, "Offer", tsOld)
	msg(102, "POINT(0.20 51.60)", 0, 12, "Wanted", tsOld)
	msg(103, "POINT(0.30 51.70)", 1, 12, "Offer", tsNew)
	msg(104, "POINT(0.40 51.80)", 0, 13, nil, tsNew)
	e.exec("INSERT INTO messages_promises (msgid) VALUES (101)")

	// newsfeed: 201 plain; 202 a reply; 203 deleted; 204 no user.
	nf := func(id int, typ string, userid, replyto any, wkt string, deleted any, modified string) {
		e.exec(fmt.Sprintf("INSERT INTO newsfeed (id, type, userid, replyto, position, deleted, modified) VALUES (?, ?, ?, ?, %s, ?, ?)",
			e.geom(wkt, true)), id, typ, userid, replyto, deleted, modified)
	}
	nf(201, "Message", 7, nil, "POINT(-0.10 51.50)", nil, tsOld)
	nf(202, "Message", 7, 201, "POINT(-0.11 51.51)", nil, tsOld)
	nf(203, "Story", 8, nil, "POINT(-0.12 51.52)", tsNew, tsNew)
	nf(204, "Alert", nil, nil, "POINT(-0.13 51.53)", nil, tsNew)

	for _, u := range []struct {
		id       int
		lat, lng float64
	}{{301, 51.5, -0.1}, {302, 51.6, -0.2}} {
		e.exec(fmt.Sprintf("INSERT INTO users_approxlocs (userid, lat, lng, position) VALUES (?, ?, ?, %s)",
			e.geom(fmt.Sprintf("POINT(%v %v)", u.lng, u.lat), true)), u.id, u.lat, u.lng)
	}

	// groups: 401 and 404 are served; 402 is unpublished; 403 is unlisted.
	grp := func(id int, name string, publish, listable int, wkt string) {
		e.exec(fmt.Sprintf("INSERT INTO %s (id, nameshort, publish, listable, polyindex) VALUES (?, ?, ?, ?, %s)",
			e.dl.quote("groups"), e.geom(wkt, true)), id, name, publish, listable)
	}
	grp(401, "Alpha", 1, 1, wktGroupA)
	grp(402, "Hidden", 0, 1, wktGroupA)
	grp(403, "Unlisted", 1, 0, wktGroupA)
	grp(404, "Beta", 1, 1, wktGroupB)

	// jobs: 501 servable; 502 not visible; 503 under the cpc floor; 504 servable with no text.
	job := func(id int, title, city, company, bodyhash any, cpc float64, wkt string, seenat string, visible int) {
		e.exec(fmt.Sprintf("INSERT INTO jobs (id, title, city, company, bodyhash, cpc, geometry, seenat, visible) VALUES (?, ?, ?, ?, ?, ?, %s, ?, ?)",
			e.geom(wkt, true)), id, title, city, company, bodyhash, cpc, seenat, visible)
	}
	job(501, "Driver", "Leeds", "Acme", "h1", 0.12, wktJob1, tsNew, 1)
	job(502, "Cook", "York", nil, nil, 0.12, wktJob2, tsNew, 0)
	job(503, "Nurse", "Hull", "Co", "h3", 0.05, wktJob1, tsNew, 1)
	job(504, nil, nil, nil, nil, 0.20, wktJob2, tsOld, 1)

	// rippling_reach: 601 expanding with two rings; 602 held; 603 done with a cluster ring and a
	// label but its grid still present; 604 retired (label and union seconds); 605 held with a ring.
	reach := func(msgid int, status string, cells any, labels any, ous any, overflow any, updated string) {
		e.exec(fmt.Sprintf("INSERT INTO rippling_reach (msgid, status, polygon_cells, reach_labels, origin_union_secs, overflow_cells, updated_at) "+
			"VALUES (?, ?, ?, ?, ?, %s, ?)", e.jsonParam()), msgid, status, cells, labels, ous, overflow, updated)
	}
	reach(601, "expanding", cellsBlobOf(t, wktReachA), nil, nil,
		overflowJSON(t, map[string]string{"$.rural.dense": wktRingA, `$.fairness."1"`: wktRingB}), tsNew)
	reach(602, "held", cellsBlobOf(t, wktReachB), nil, nil, nil, tsNew)
	reach(603, "done", cellsBlobOf(t, wktReachB), []byte{1}, nil,
		overflowJSON(t, map[string]string{"$.cluster.w1": wktRingA}), tsOld)
	reach(604, "done", cellsBlobOf(t, wktReachC), []byte{1}, 5.0, nil, tsNew)
	reach(605, "held", cellsBlobOf(t, wktReachC), nil, nil,
		overflowJSON(t, map[string]string{"$.rural.sparse": wktRingA}), tsNew)
}

// --- helpers to read an index back ---

func indexIDs(t *testing.T, idx *Index) []int64 {
	t.Helper()
	m, err := idx.ExtIDs()
	require.NoError(t, err)
	ids := make([]int64, 0, len(m))
	for id := range m {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
	return ids
}

func newTestIndex(t *testing.T) *Index {
	t.Helper()
	idx, err := CreateIndex(":memory:")
	require.NoError(t, err)
	t.Cleanup(func() { idx.Close() })
	return idx
}

func itemOf(t *testing.T, idx *Index, id int64) *Item {
	t.Helper()
	it, err := idx.GetByExtID(id)
	require.NoError(t, err)
	require.NotNil(t, it, "item %d missing", id)
	return it
}

// --- the tests ---

// A fresh connection to PostgreSQL reads timestamps as UTC, which the loaders' literals assume.
func TestSourceEngines_ConnectionIsUTC(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		if e.name != enginePostgres {
			return
		}
		var tz string
		require.NoError(t, e.db.QueryRow(`SHOW timezone`).Scan(&tz))
		assert.Equal(t, "UTC", tz)
	})
}

func TestSourceEngines_LocationsLoad(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		require.NoError(t, (&LocationsDataset{}).Load(e.db, idx))

		// 8 is a point, 5 and 7 are excluded, 6 is over the area cap, 4 is a district but still a location.
		assert.Equal(t, []int64{1, 2, 3, 4}, indexIDs(t, idx))

		balham := itemOf(t, idx, 1)
		assert.InDelta(t, 0.01, balham.Area, 1e-9)
		assert.InDelta(t, -0.15, balham.MinLng, 1e-4)
		assert.InDelta(t, -0.05, balham.MaxLng, 1e-4)
		assert.InDelta(t, 51.45, balham.MinLat, 1e-4)
		assert.InDelta(t, 51.55, balham.MaxLat, 1e-4)
		assert.Equal(t, map[string]any{"name": "Balham", "type": "Polygon"}, balham.Extra)
		assert.NotEmpty(t, balham.WKB)

		sw12 := itemOf(t, idx, 2)
		assert.InDelta(t, 1e-4, sw12.Area, 1e-9)
		assert.Equal(t, "Postcode", sw12.Extra["type"])

		// The index answers the nearest-location question from what was loaded.
		res, err := (&LocationsDataset{}).Query(idx, QueryParams{Lng: -0.1, Lat: 51.5, Limit: 1})
		require.NoError(t, err)
		require.Len(t, res, 1)
		assert.EqualValues(t, 2, res[0].ID, "the postcode is the smallest polygon around the point")
	})
}

func TestSourceEngines_LocationsDelta(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		// An excluded location already in the index is removed by the delta.
		require.NoError(t, InsertItems(idx, []Item{{ExtID: 5}}, nil))

		require.NoError(t, (&LocationsDataset{}).ApplyDelta(e.db, idx, horizon))

		// Only 1 and 3 are new, polygonal, in range and not excluded.
		assert.Equal(t, []int64{1, 3}, indexIDs(t, idx))

		// A change after the horizon is picked up, one before it is not.
		e.exec(fmt.Sprintf("UPDATE locations SET name = ?, %s = ? WHERE id = 1", e.dl.quote("timestamp")), "Balham Common", tsLater)
		e.exec(fmt.Sprintf("UPDATE locations SET name = ?, %s = ? WHERE id = 2", e.dl.quote("timestamp")), "ZZ12 3AB edited", tsOld)
		require.NoError(t, (&LocationsDataset{}).ApplyDelta(e.db, idx, horizon))
		assert.Equal(t, []int64{1, 3}, indexIDs(t, idx))
		assert.Equal(t, "Balham Common", itemOf(t, idx, 1).Extra["name"])
	})
}

func TestSourceEngines_PostcodesLoadAndDelta(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		require.NoError(t, (&PostcodesDataset{}).Load(e.db, idx))

		// Full postcodes only: 2 and 3. 4 is a district, 7 is excluded, the areas are not postcodes.
		assert.Equal(t, []int64{2, 3}, indexIDs(t, idx))

		sw12 := itemOf(t, idx, 2)
		assert.InDelta(t, -0.10, sw12.MinLng, 1e-4)
		assert.InDelta(t, -0.10, sw12.MaxLng, 1e-4)
		assert.InDelta(t, 51.50, sw12.MinLat, 1e-4)
		assert.InDelta(t, 51.50, sw12.MaxLat, 1e-4)
		assert.Nil(t, sw12.WKB)
		assert.Nil(t, sw12.Extra)

		// The override geometry wins over the original.
		e1 := itemOf(t, idx, 3)
		assert.InDelta(t, -0.20, e1.MinLng, 1e-4)
		assert.InDelta(t, 51.55, e1.MinLat, 1e-4)

		delta := newTestIndex(t)
		require.NoError(t, (&PostcodesDataset{}).ApplyDelta(e.db, delta, horizon))
		assert.Equal(t, []int64{3}, indexIDs(t, delta), "only the postcode touched since the horizon")

		res, err := (&PostcodesDataset{}).Query(idx, QueryParams{Lng: -0.1, Lat: 51.5, Limit: 1})
		require.NoError(t, err)
		require.Len(t, res, 1)
		assert.EqualValues(t, 2, res[0].ID)
	})
}

func TestSourceEngines_PostcodeLookup(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		saved := placesDB
		placesDB = e.db
		t.Cleanup(func() { placesDB = saved })

		f := lookupPostcode("ZZ12 3AB")
		require.NotNil(t, f)
		assert.InDelta(t, -0.10, f.Geometry.Coordinates[0], 1e-9)
		assert.InDelta(t, 51.50, f.Geometry.Coordinates[1], 1e-9)
		assert.Equal(t, "ZZ12 3AB", f.Properties.Name)
		assert.EqualValues(t, 2, f.Properties.OsmID)

		// Postcodes match without regard to case, on both engines.
		assert.NotNil(t, lookupPostcode("zz12 3ab"))

		// The override geometry is the one used.
		f = lookupPostcode("ZZ6 1AB")
		require.NotNil(t, f)
		assert.InDelta(t, -0.20, f.Geometry.Coordinates[0], 1e-9)

		assert.Nil(t, lookupPostcode("ZZ1 1AA"), "an excluded postcode is not offered")
		assert.Nil(t, lookupPostcode("ZZ99 9ZZ"))
	})
}

func TestSourceEngines_MessagesLoadAndDelta(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		require.NoError(t, (&MessagesDataset{}).Load(e.db, idx))
		assert.Equal(t, []int64{101, 102, 104}, indexIDs(t, idx), "103 is successful")

		promised := itemOf(t, idx, 101)
		assert.InDelta(t, -0.10, promised.MinLng, 1e-4)
		assert.InDelta(t, 51.50, promised.MinLat, 1e-4)
		assert.Equal(t, map[string]any{"msgtype": "Offer", "groupid": float64(11), "promised": float64(1)}, promised.Extra)
		assert.Equal(t, float64(0), itemOf(t, idx, 102).Extra["promised"])
		assert.Equal(t, "", itemOf(t, idx, 104).Extra["msgtype"], "a missing type is the empty string")

		// The delta upserts what changed since the horizon and removes what became successful.
		delta := newTestIndex(t)
		require.NoError(t, InsertItems(delta, []Item{{ExtID: 103}}, nil))
		require.NoError(t, (&MessagesDataset{}).ApplyDelta(e.db, delta, horizon))
		assert.Equal(t, []int64{104}, indexIDs(t, delta))
	})
}

func TestSourceEngines_NewsfeedLoadAndDelta(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		require.NoError(t, (&NewsfeedDataset{}).Load(e.db, idx))
		assert.Equal(t, []int64{201, 204}, indexIDs(t, idx), "202 is a reply, 203 is deleted")
		assert.Equal(t, map[string]any{"type": "Message", "userid": float64(7)}, itemOf(t, idx, 201).Extra)
		assert.Equal(t, map[string]any{"type": "Alert", "userid": float64(0)}, itemOf(t, idx, 204).Extra)

		delta := newTestIndex(t)
		require.NoError(t, InsertItems(delta, []Item{{ExtID: 203}}, nil))
		require.NoError(t, (&NewsfeedDataset{}).ApplyDelta(e.db, delta, horizon))
		assert.Equal(t, []int64{204}, indexIDs(t, delta), "the deleted entry is removed, the new one added")
	})
}

func TestSourceEngines_UserApproxLocsLoad(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		require.NoError(t, (&UserApproxLocsDataset{}).Load(e.db, idx))
		assert.Equal(t, []int64{301, 302}, indexIDs(t, idx))
		it := itemOf(t, idx, 301)
		assert.InDelta(t, -0.1, it.MinLng, 1e-4)
		assert.InDelta(t, 51.5, it.MinLat, 1e-4)
		assert.InDelta(t, 51.5, it.Extra["lat"], 1e-9)
		assert.InDelta(t, -0.1, it.Extra["lng"], 1e-9)
	})
}

func TestSourceEngines_GroupsLoad(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		require.NoError(t, (&GroupsDataset{}).Load(e.db, idx))
		assert.Equal(t, []int64{401, 404}, indexIDs(t, idx))

		a := itemOf(t, idx, 401)
		assert.InDelta(t, 0.04, a.Area, 1e-9)
		assert.InDelta(t, -0.2, a.MinLng, 1e-4)
		assert.InDelta(t, 0.0, a.MaxLng, 1e-4)
		assert.Equal(t, map[string]any{"nameshort": "Alpha"}, a.Extra)

		// The WKB the loader stored rasterises, which is what the cell queries do with it.
		_, err := groupCells(401, a.WKB)
		require.NoError(t, err)
	})
}

func TestSourceEngines_JobsLoadAndDelta(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		idx := newTestIndex(t)
		require.NoError(t, (&JobsDataset{}).Load(e.db, idx))
		assert.Equal(t, []int64{501, 504}, indexIDs(t, idx), "502 is not visible, 503 is under the floor")

		j := itemOf(t, idx, 501)
		assert.InDelta(t, 0.004, j.Area, 1e-9)
		assert.Equal(t, "Driver", j.Extra["title"])
		assert.Equal(t, "Leeds", j.Extra["city"])
		assert.Equal(t, "Acme", j.Extra["company"])
		assert.Equal(t, "h1", j.Extra["bodyhash"])
		assert.InDelta(t, 0.12, j.Extra["cpc"], 1e-9)
		blank := itemOf(t, idx, 504)
		assert.Equal(t, "", blank.Extra["title"])
		assert.Equal(t, "", blank.Extra["bodyhash"])

		delta := newTestIndex(t)
		require.NoError(t, InsertItems(delta, []Item{{ExtID: 502}, {ExtID: 503}}, nil))
		require.NoError(t, (&JobsDataset{}).ApplyDelta(e.db, delta, horizon))
		assert.Equal(t, []int64{501}, indexIDs(t, delta), "the rows that stopped being servable are removed")
	})
}

// The drift check reads MAX(seenat) as text and compares it as a string, so the two engines must
// give the same fixed form, and a WhatJobs-style swap must be seen on both.
func TestSourceEngines_JobsDrift(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		d := &JobsDataset{}
		max, err := d.jobsMaxSeen(e.db)
		require.NoError(t, err)
		assert.Equal(t, tsNew, max)

		live, err := d.liveCount(e.db)
		require.NoError(t, err)
		assert.EqualValues(t, 2, live)

		idx := newTestIndex(t)
		require.NoError(t, d.Load(e.db, idx))
		require.NoError(t, d.NoteReconciled(e.db))
		need, _, err := d.CheckDrift(e.db, idx)
		require.NoError(t, err)
		assert.False(t, need)

		e.exec("UPDATE jobs SET seenat = ?", tsLater)
		need, reason, err := d.CheckDrift(e.db, idx)
		require.NoError(t, err)
		require.True(t, need)
		assert.Contains(t, reason, "swap detected")
		assert.Contains(t, reason, tsLater)

		// An empty table gives an empty maximum, not an error.
		e.exec("DELETE FROM jobs")
		max, err = (&JobsDataset{}).jobsMaxSeen(e.db)
		require.NoError(t, err)
		assert.Equal(t, "", max)
	})
}

func TestSourceEngines_ReachLoadAndDelta(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		d := &ReachDataset{}
		idx := newTestIndex(t)
		require.NoError(t, d.Load(e.db, idx))
		// 602 and 605 are held; 604 is retired (a label and union seconds).
		assert.Equal(t, []int64{601, 603}, indexIDs(t, idx))
		a := itemOf(t, idx, 601)
		assert.Equal(t, cellsBlobOf(t, wktReachA), a.WKB, "the item blob is the stored grid, byte for byte")
		assert.Equal(t, "expanding", a.Extra["status"])
		assert.InDelta(t, 0.0, a.MinLng, 1e-3)

		// The grid answers a containment question.
		in, partial, err := d.Containing(idx, 0.005, 0.005)
		require.NoError(t, err)
		assert.Equal(t, []int64{601}, in)
		assert.Empty(t, partial)

		// The delta drops what became held or retired, rebuilds what changed, and the reconcile
		// adds the row the delta window did not cover.
		delta := newTestIndex(t)
		require.NoError(t, InsertItems(delta, []Item{{ExtID: 602}, {ExtID: 604}}, nil))
		require.NoError(t, d.ApplyDelta(e.db, delta, horizon))
		assert.Equal(t, []int64{601, 603}, indexIDs(t, delta))

		// A reconcile with nothing to compare against is refused, not treated as an empty source.
		e.exec("DELETE FROM rippling_reach")
		err = d.ApplyDelta(e.db, delta, horizon)
		require.Error(t, err)
		assert.Contains(t, err.Error(), "refusing to reconcile")
	})
}

// The probe for the grid columns reads the live schema: without origin_union_secs a labelled row
// is retired only once its grid has gone, and without polygon_cells the dataset is empty.
func TestSourceEngines_ReachColumnProbes(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		e.exec("ALTER TABLE rippling_reach DROP COLUMN origin_union_secs")
		resetReachProbes()
		idx := newTestIndex(t)
		require.NoError(t, (&ReachDataset{}).Load(e.db, idx))
		assert.Equal(t, []int64{601, 603, 604}, indexIDs(t, idx), "604 keeps its grid, so it is not retired")

		e.exec("ALTER TABLE rippling_reach DROP COLUMN polygon_cells")
		resetReachProbes()
		empty := newTestIndex(t)
		require.NoError(t, (&ReachDataset{}).Load(e.db, empty))
		assert.Empty(t, indexIDs(t, empty), "the dataset serves empty once the grid columns are dropped")
		require.NoError(t, (&ReachDataset{}).ApplyDelta(e.db, empty, horizon), "and its delta is a no-op")
	})
}

func TestSourceEngines_ReachOverflowLoadAndDelta(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		d := &ReachOverflowDataset{}
		idx := newTestIndex(t)
		require.NoError(t, d.Load(e.db, idx))

		// 601 carries two rings (dense = lane 1, fairness "1" = lane 4); 603 one (cluster.w1 = 8);
		// 605 is held, so its ring is not indexed.
		want := []int64{
			encodeOverflowExtID(601, 1),
			encodeOverflowExtID(601, 4),
			encodeOverflowExtID(603, 8),
		}
		assert.Equal(t, want, indexIDs(t, idx))
		it := itemOf(t, idx, encodeOverflowExtID(601, 4))
		assert.EqualValues(t, 601, it.Extra["msgid"])
		assert.Equal(t, `$.fairness."1"`, it.Extra["lane"])

		// A point inside the ring is admitted by the lane it belongs to and only that one.
		in, _, err := d.ContainingFiltered(idx, 0.0015, 0.0015, []string{"$.rural.dense"})
		require.NoError(t, err)
		assert.Equal(t, []int64{601}, in)
		in, _, err = d.ContainingFiltered(idx, 0.0015, 0.0015, []string{`$.fairness."1"`})
		require.NoError(t, err)
		assert.Empty(t, in, "the fairness ring is elsewhere")
		in, _, err = d.ContainingFiltered(idx, 0.0115, 0.0115, []string{`$.fairness."1"`})
		require.NoError(t, err)
		assert.Equal(t, []int64{601}, in)

		// The delta clears a held post's lanes and rewrites the changed post's.
		delta := newTestIndex(t)
		require.NoError(t, InsertItems(delta, []Item{{ExtID: encodeOverflowExtID(605, 3)}}, nil))
		require.NoError(t, d.ApplyDelta(e.db, delta, horizon))
		assert.Equal(t, []int64{encodeOverflowExtID(601, 1), encodeOverflowExtID(601, 4)}, indexIDs(t, delta))
	})
}

// A post that loses its rings drops out of has_overflow and is cleaned up by the reconcile.
func TestSourceEngines_ReachOverflowReconcileDropsPostsWithoutRings(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		d := &ReachOverflowDataset{}
		idx := newTestIndex(t)
		require.NoError(t, d.Load(e.db, idx))

		e.exec("UPDATE rippling_reach SET overflow_cells = NULL, updated_at = ? WHERE msgid = 603", tsOld)
		require.NoError(t, d.ApplyDelta(e.db, idx, horizon))
		assert.Equal(t, []int64{encodeOverflowExtID(601, 1), encodeOverflowExtID(601, 4)}, indexIDs(t, idx))
	})
}

// The whole path the service runs: the server builds an index from the source and the dataset
// answers a nearest query from it.
func TestSourceEngines_ServerRebuild(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		srv := newServer(e.db, t.TempDir(), []Dataset{&MessagesDataset{}, &JobsDataset{}, &GroupsDataset{}})
		for _, name := range []string{"messages", "jobs", "groups"} {
			require.NoError(t, srv.rebuild(name), name)
		}

		state, ok := srv.getDataset("messages")
		require.True(t, ok)
		require.NoError(t, state.withIndex(func(idx *Index) error {
			res, err := state.ds.Query(idx, QueryParams{Lng: -0.1, Lat: 51.5, Limit: 1})
			require.NoError(t, err)
			require.Len(t, res, 1)
			assert.EqualValues(t, 101, res[0].ID)
			return nil
		}))

		state, _ = srv.getDataset("groups")
		require.NoError(t, state.withIndex(func(idx *Index) error {
			res, err := state.ds.Query(idx, QueryParams{Lng: -0.1, Lat: 51.5, Limit: 1})
			require.NoError(t, err)
			require.Len(t, res, 1)
			assert.EqualValues(t, 401, res[0].ID)
			return nil
		}))

		// The index files are on disk, as in production.
		matches, err := filepath.Glob(filepath.Join(srv.idxDir, "*.db"))
		require.NoError(t, err)
		assert.Len(t, matches, 3)
	})
}

// Bytes survive the round trip through each engine's binary type.
func TestSourceEngines_BinaryRoundTrip(t *testing.T) {
	forEachEngine(t, func(t *testing.T, e *engineDB) {
		want := cellsBlobOf(t, wktReachA)
		var got []byte
		require.NoError(t, srcQueryRow(e.db, "SELECT polygon_cells FROM rippling_reach WHERE msgid = ?", 601).Scan(&got))
		assert.True(t, bytes.Equal(want, got))
	})
}
