package test

import (
	"fmt"
	"math"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"sync"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/router"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// TestApp wraps fiber.App to override the default Test timeout from 1s to 30s.
type TestApp struct {
	*fiber.App
}

// Test shadows fiber.App.Test with a 30-second default timeout instead of 1s.
// Fiber's default 1s is too tight for CI environments under load.
func (a *TestApp) Test(req *http.Request, msTimeout ...int) (*http.Response, error) {
	if len(msTimeout) == 0 {
		msTimeout = []int{30000}
	}
	return a.App.Test(req, msTimeout...)
}

var app *TestApp

func init() {
	// Set environment variables needed for tests
	os.Setenv("LOVEJUNK_PARTNER_KEY", "testkey123")
	// Isolate image-delivery URL building from the environment. The apiv2
	// container sets UPLOADS for runtime (the dev value points at local tusd);
	// tests must not depend on that, nor on the production default. Pin an
	// obviously-fake test host and assert image URLs against it (uploads.test).
	os.Setenv("UPLOADS", "https://uploads.test/")

	app = &TestApp{fiber.New()}
	app.Use(user.NewAuthMiddleware(user.Config{}))
	database.InitDatabase()

	// Ensure required reference data exists for location tests
	setupLocationTestData()

	// Verify required tables exist (created by Laravel migrations in iznik-batch)
	verifyRequiredTables()

	// Set up swagger routes BEFORE other API routes (same as main.go)
	// Handle swagger redirect - redirect exact /swagger path to /swagger/index.html
	app.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/index.html", 302)
	})

	// Serve swagger static files from swagger directory
	// Use absolute path to ensure it works regardless of where tests are run from
	swaggerPath := "/app/swagger"
	if _, err := os.Stat(swaggerPath); os.IsNotExist(err) {
		// Fallback to relative path if absolute doesn't exist (for local development)
		swaggerPath = "../swagger"
	}
	app.Static("/swagger", swaggerPath, fiber.Static{
		Index: "index.html",
	})

	// Set up all other API routes
	router.SetupRoutes(app.App)

	// NOTE: Tests now create their own data using factory functions in testUtils.go
	// Location reference data is set up by setupLocationTestData()
}

// setupLocationTestData ensures the required reference data exists for location tests
// This data is idempotent - safe to run multiple times
func setupLocationTestData() {
	db := database.DBConn

	// Create area location first (for areaname in ClosestPostcode query)
	db.Exec(`INSERT INTO locations (id, osm_id, name, type, osm_place, gridid, postcodeid, areaid, canon, popularity, osm_amenity, osm_shop, maxdimension, lat, lng, timestamp, geometry)
		VALUES (999999, '999999', 'Edinburgh', 'Point', 0, NULL, NULL, NULL, 'edinburgh', 0, 0, 0, '0.1', '55.957571', '-3.205333', '2016-08-23 06:01:25',
		ST_GeomFromText('POINT(-3.205333 55.957571)', 4326))
		ON DUPLICATE KEY UPDATE name='Edinburgh', type='Point'`)

	// Edinburgh postcode area for TestClosest, TestTypeahead, TestLatLng
	// These are real UK postcodes needed for the location API tests
	db.Exec(`INSERT INTO locations (id, osm_id, name, type, osm_place, gridid, postcodeid, areaid, canon, popularity, osm_amenity, osm_shop, maxdimension, lat, lng, timestamp, geometry)
		VALUES (1687412, '189543628', 'SA65 9ET', 'Postcode', 0, NULL, NULL, 999999, 'sa659et', 0, 0, 0, '0.002916', '52.006292', '-4.939858', '2016-08-23 06:01:25',
		ST_GeomFromText('POINT(-4.939858 52.006292)', 4326))
		ON DUPLICATE KEY UPDATE areaid=999999`)

	// Edinburgh postcodes for typeahead tests (EH3 area)
	db.Exec(`INSERT INTO locations (id, osm_id, name, type, osm_place, gridid, postcodeid, areaid, canon, popularity, osm_amenity, osm_shop, maxdimension, lat, lng, timestamp, geometry)
		VALUES (1000001, '100001', 'EH3 6SS', 'Postcode', 0, NULL, NULL, 999999, 'eh36ss', 0, 0, 0, '0.002916', '55.957571', '-3.205333', '2016-08-23 06:01:25',
		ST_GeomFromText('POINT(-3.205333 55.957571)', 4326))
		ON DUPLICATE KEY UPDATE areaid=999999`)

	db.Exec(`INSERT INTO locations (id, osm_id, name, type, osm_place, gridid, postcodeid, areaid, canon, popularity, osm_amenity, osm_shop, maxdimension, lat, lng, timestamp, geometry)
		VALUES (1000002, '100002', 'EH3 5AA', 'Postcode', 0, NULL, NULL, 999999, 'eh35aa', 0, 0, 0, '0.002916', '55.958000', '-3.206000', '2016-08-23 06:01:25',
		ST_GeomFromText('POINT(-3.206000 55.958000)', 4326))
		ON DUPLICATE KEY UPDATE areaid=999999`)

	// locations_spatial entries for spatial queries (used by ClosestPostcode)
	// Note: locations_spatial uses SRID 3857 (Web Mercator)
	db.Exec(`INSERT IGNORE INTO locations_spatial (locationid, geometry)
		VALUES (1687412, ST_GeomFromText('POINT(-4.939858 52.006292)', 3857))`)
	db.Exec(`INSERT IGNORE INTO locations_spatial (locationid, geometry)
		VALUES (1000001, ST_GeomFromText('POINT(-3.205333 55.957571)', 3857))`)
	db.Exec(`INSERT IGNORE INTO locations_spatial (locationid, geometry)
		VALUES (1000002, ST_GeomFromText('POINT(-3.206000 55.958000)', 3857))`)

	// ClosestPostcode now queries the spatial KNN with no MySQL fallback, but the
	// spatial server's index isn't reliably populated in the test environment (it
	// builds from the DB at startup, before this test data exists — see
	// jobs_test.go). Point the spatial client at a deterministic in-process mock.
	ensureSpatialMock()

	// PAF addresses for TestAddresses (linked to location 1687412)
	db.Exec(`INSERT IGNORE INTO paf_addresses (id, postcodeid, udprn) VALUES (102367696, 1687412, 50464672)`)

	// LoveJunk partner key for TestCreateChatMessageLoveJunk
	db.Exec("INSERT IGNORE INTO partners_keys (partner, `key`) VALUES ('lovejunk', 'testkey123')")
}

var spatialMockOnce sync.Once

// ensureSpatialMock points the spatial client (SPATIAL_KNN_URL) at an in-process
// server that answers /v1/postcodes/knn with the nearest of the test postcodes
// and returns empty for every other dataset. This keeps ClosestPostcode
// deterministic without depending on a live, populated spatial server, which the
// test environment doesn't provide.
func ensureSpatialMock() {
	spatialMockOnce.Do(func() {
		type pc struct {
			id       int64
			lng, lat float64
		}
		pts := []pc{
			{1000001, -3.205333, 55.957571},
			{1000002, -3.206000, 55.958000},
			{1687412, -4.939858, 52.006292},
		}
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Content-Type", "application/json")
			if strings.Contains(r.URL.Path, "/postcodes/knn") {
				lat, _ := strconv.ParseFloat(r.URL.Query().Get("lat"), 64)
				lng, _ := strconv.ParseFloat(r.URL.Query().Get("lng"), 64)
				best, bestD := int64(0), math.MaxFloat64
				for _, p := range pts {
					d := (p.lng-lng)*(p.lng-lng) + (p.lat-lat)*(p.lat-lat)
					if d < bestD {
						bestD, best = d, p.id
					}
				}
				fmt.Fprintf(w, `{"results":[{"id":%d,"distance":%g}]}`, best, bestD)
				return
			}
			w.Write([]byte(`{"results":[],"ids":[]}`))
		}))
		os.Setenv("SPATIAL_KNN_URL", srv.URL)
	})
}

// verifyRequiredTables checks that tables created by Laravel migrations exist.
// These tables are not in schema.sql and are created by iznik-batch migrations.
// If missing, it means migrations haven't been run before tests.
func verifyRequiredTables() {
	db := database.DBConn
	for _, table := range []string{"background_tasks", "cron_job_status"} {
		var count int64
		db.Raw("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", table).Scan(&count)
		if count == 0 {
			panic(fmt.Sprintf("Required table '%s' not found - ensure Laravel migrations have been run (setup-test-database.sh)", table))
		}
	}
}

func TestMain(m *testing.M) {
	code := m.Run()

	// Clean up test groups after all tests complete (pass or fail).
	// Test groups accumulate and bloat the groups table, causing SSR payload
	// to exceed Chrome's 65534 function parameter limit.
	cleanupTestGroups()

	os.Exit(code)
}

func cleanupTestGroups() {
	db := database.DBConn
	result := db.Exec("DELETE FROM `groups` WHERE nameshort LIKE 'TestGroup_%'")
	if result.Error != nil {
		fmt.Printf("WARNING: Failed to clean up test groups: %v\n", result.Error)
	} else {
		fmt.Printf("Cleaned up %d test groups\n", result.RowsAffected)
	}
}

func getApp() *TestApp {
	// We use this so that we only initialise fiber once.
	return app
}
