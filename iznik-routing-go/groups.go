package main

import (
	"database/sql"
	"fmt"
	"log"
	"math"
	"os"
	"strconv"
	"strings"
	"sync"

	_ "github.com/go-sql-driver/mysql"
	"github.com/gofiber/fiber/v2"
)

// Web Mercator (SRID 3857) ↔ WGS84 helpers.
// polyindex is stored in SRID 3857; we convert Go-side to avoid MySQL axis-order
// ambiguity with geographic SRIDs.

const mercatorHalfCircum = 20037508.34

func lngLatToMerc(lng, lat float64) (x, y float64) {
	x = lng * mercatorHalfCircum / 180.0
	y = math.Log(math.Tan((90.0+lat)*math.Pi/360.0)) * (mercatorHalfCircum / math.Pi)
	return
}

func mercToLng(x float64) float64 { return x * 180.0 / mercatorHalfCircum }
func mercToLat(y float64) float64 {
	return math.Atan(math.Exp(y*math.Pi/mercatorHalfCircum))*360.0/math.Pi - 90.0
}

// wktPolygonToCoords parses a WKT POLYGON string whose coordinates are stored
// as WGS84 degrees (despite being tagged SRID 3857). Returns GeoJSON [lng, lat] rings.
func wktPolygonToCoords(wkt string) ([][][2]float64, error) {
	wkt = strings.TrimSpace(wkt)
	if i := strings.Index(wkt, ";"); i >= 0 {
		wkt = wkt[i+1:]
	}
	wkt = strings.TrimSpace(wkt)
	upper := strings.ToUpper(wkt)
	if !strings.HasPrefix(upper, "POLYGON") {
		return nil, fmt.Errorf("not a POLYGON: %s", wkt[:min(20, len(wkt))])
	}
	start := strings.Index(wkt, "(")
	end := strings.LastIndex(wkt, ")")
	if start < 0 || end < 0 {
		return nil, fmt.Errorf("malformed WKT")
	}
	inner := wkt[start+1 : end]
	rawRings := splitRings(inner)
	var rings [][][2]float64
	for _, raw := range rawRings {
		raw = strings.Trim(raw, " ()")
		parts := strings.Split(raw, ",")
		var ring [][2]float64
		for _, p := range parts {
			p = strings.TrimSpace(p)
			fields := strings.Fields(p)
			if len(fields) < 2 {
				continue
			}
			// Coordinates are stored as degrees (WKT: lng lat order).
			lng, err1 := strconv.ParseFloat(fields[0], 64)
			lat, err2 := strconv.ParseFloat(fields[1], 64)
			if err1 != nil || err2 != nil {
				continue
			}
			ring = append(ring, [2]float64{lng, lat})
		}
		if len(ring) >= 4 {
			rings = append(rings, ring)
		}
	}
	return rings, nil
}

func splitRings(s string) []string {
	var rings []string
	depth := 0
	start := 0
	for i, ch := range s {
		switch ch {
		case '(':
			depth++
		case ')':
			depth--
			if depth == 0 {
				rings = append(rings, s[start:i+1])
				start = i + 1
			}
		}
	}
	if start < len(s) {
		tail := strings.TrimSpace(s[start:])
		if tail != "" && tail != "," {
			rings = append(rings, tail)
		}
	}
	return rings
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}

// groupFeature is a GeoJSON Feature wrapping one group's polygon.
type groupFeature struct {
	Type       string      `json:"type"`
	Properties groupProps  `json:"properties"`
	Geometry   geoGeometry `json:"geometry"`
}

type groupProps struct {
	ID        int64  `json:"id"`
	NameShort string `json:"nameshort"`
	Contains  bool   `json:"contains"` // true if the offer point falls inside this group's polygon
}

// groupsCollection is a GeoJSON FeatureCollection.
type groupsCollection struct {
	Type     string         `json:"type"`
	Features []groupFeature `json:"features"`
}

var groupsDB *sql.DB
var groupsDBMu sync.Mutex

// groupsDSN builds the MySQL DSN from environment variables.
func groupsDSN() string {
	host := os.Getenv("MYSQL_HOST")
	if host == "" {
		return ""
	}
	port := os.Getenv("MYSQL_PORT")
	if port == "" {
		port = "3306"
	}
	user := os.Getenv("MYSQL_USER")
	if user == "" {
		user = "iznik"
	}
	pass := os.Getenv("MYSQL_PASSWORD")
	dbname := os.Getenv("MYSQL_DBNAME")
	if dbname == "" {
		dbname = "iznik"
	}
	return fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true", user, pass, host, port, dbname)
}

// initGroupsDB attempts to connect to MySQL at startup.
func initGroupsDB() {
	dsn := groupsDSN()
	if dsn == "" {
		return
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Printf("groups: MySQL open error: %v", err)
		return
	}
	if err := db.Ping(); err != nil {
		log.Printf("groups: MySQL ping error: %v", err)
		db.Close()
		return
	}
	groupsDB = db
	host := os.Getenv("MYSQL_HOST")
	port := os.Getenv("MYSQL_PORT")
	if port == "" {
		port = "3306"
	}
	dbname := os.Getenv("MYSQL_DBNAME")
	if dbname == "" {
		dbname = "iznik"
	}
	log.Printf("groups: MySQL connected (%s:%s/%s)", host, port, dbname)
}

// ensureGroupsDB returns the groups DB connection, reconnecting lazily if
// the initial startup connection failed (e.g. the DB tunnel was not yet up).
func ensureGroupsDB() *sql.DB {
	groupsDBMu.Lock()
	defer groupsDBMu.Unlock()
	if groupsDB != nil {
		if err := groupsDB.Ping(); err == nil {
			return groupsDB
		}
		// Stale connection — close and reconnect.
		groupsDB.Close()
		groupsDB = nil
	}
	dsn := groupsDSN()
	if dsn == "" {
		return nil
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Printf("groups: MySQL reconnect open error: %v", err)
		return nil
	}
	if err := db.Ping(); err != nil {
		log.Printf("groups: MySQL reconnect ping error: %v", err)
		db.Close()
		return nil
	}
	groupsDB = db
	host := os.Getenv("MYSQL_HOST")
	port := os.Getenv("MYSQL_PORT")
	if port == "" {
		port = "3306"
	}
	dbname := os.Getenv("MYSQL_DBNAME")
	if dbname == "" {
		dbname = "iznik"
	}
	log.Printf("groups: MySQL reconnected (%s:%s/%s)", host, port, dbname)
	return groupsDB
}

// handleNearbyGroups returns a GeoJSON FeatureCollection of Freegle groups near
// the given lat/lng. Each feature carries a "contains" boolean: true when the
// offer point falls inside that group's polygon (i.e. the home group), false for
// adjacent neighbours. Requires MySQL connection.
//
// polyindex is tagged SRID 3857 in the database but the coordinate values are
// stored as WGS84 degrees (not Mercator meters). We pass degree values directly
// to avoid incorrect Mercator conversion.
func handleNearbyGroups() fiber.Handler {
	return func(c *fiber.Ctx) error {
		db := ensureGroupsDB()
		if db == nil {
			return c.JSON(groupsCollection{Type: "FeatureCollection", Features: []groupFeature{}})
		}
		latS := c.Query("lat")
		lngS := c.Query("lng")
		if latS == "" || lngS == "" {
			return c.Status(400).JSON(fiber.Map{"error": "lat and lng required"})
		}
		latF, err1 := strconv.ParseFloat(latS, 64)
		lngF, err2 := strconv.ParseFloat(lngS, 64)
		if err1 != nil || err2 != nil {
			return c.Status(400).JSON(fiber.Map{"error": "lat and lng must be numeric"})
		}

		// polyindex stores degree values tagged as SRID 3857 — use raw degrees.
		// Bounding box: ±1.5° covers all adjacent groups.
		boxLngMin := lngF - 1.5
		boxLngMax := lngF + 1.5
		boxLatMin := latF - 1.5
		boxLatMax := latF + 1.5

		rows, err := db.Query(`
			SELECT id, nameshort, ST_AsText(polyindex) AS wkt,
			       ST_Contains(polyindex,
			           ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)) AS contains_pt
			FROM `+"`groups`"+`
			WHERE publish = 1 AND listable = 1
			  AND polyindex IS NOT NULL
			  AND (
			    ST_Contains(polyindex,
			        ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857))
			    OR (
			      ST_X(ST_Centroid(polyindex)) BETWEEN ? AND ?
			      AND ST_Y(ST_Centroid(polyindex)) BETWEEN ? AND ?
			    )
			  )
			ORDER BY contains_pt DESC,
			         ST_Distance(polyindex,
			             ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)) ASC
			LIMIT 60
		`,
			lngF, latF, // SELECT contains_pt  (WKT: POINT(lng lat))
			lngF, latF, // WHERE ST_Contains
			boxLngMin, boxLngMax, // centroid X = lng (degrees)
			boxLatMin, boxLatMax, // centroid Y = lat (degrees)
			lngF, latF, // ORDER BY distance from polygon boundary
		)
		if err != nil {
			log.Printf("groups query: %v", err)
			return c.Status(500).JSON(fiber.Map{"error": err.Error()})
		}
		defer rows.Close()

		var features []groupFeature
		for rows.Next() {
			var id int64
			var name, wkt string
			var containsPt bool
			if err := rows.Scan(&id, &name, &wkt, &containsPt); err != nil {
				continue
			}
			coords, err := wktPolygonToCoords(wkt)
			if err != nil || len(coords) == 0 {
				continue
			}
			features = append(features, groupFeature{
				Type:       "Feature",
				Properties: groupProps{ID: id, NameShort: name, Contains: containsPt},
				Geometry: geoGeometry{
					Type:        "Polygon",
					Coordinates: coords,
				},
			})
		}
		if features == nil {
			features = []groupFeature{}
		}
		return c.JSON(groupsCollection{Type: "FeatureCollection", Features: features})
	}
}

// groupSeedNodesAndConn loads a group's boundary and returns graph nodes to seed a catchment
// from — the nearest node to each exterior-ring vertex plus the centroid — along with a
// representative connectivity score (the centroid's) for the group's willingness. Seeding the
// whole boundary (not just the centroid) is what lets the catchment capture corridor reach
// into the group's edges (e.g. an M62 offer clipping HullFreegle's western strip). ok=false
// when the group or its polygon is missing.
func groupSeedNodesAndConn(g *Graph, groupID int64, mode Mode) ([]NodeID, uint8, bool) {
	db := ensureGroupsDB()
	if db == nil {
		return nil, 0, false
	}
	var wkt string
	var clat, clng float64
	err := db.QueryRow("SELECT ST_AsText(polyindex), ST_Y(ST_Centroid(polyindex)), "+
		"ST_X(ST_Centroid(polyindex)) FROM `groups` WHERE id = ?", groupID).Scan(&wkt, &clat, &clng)
	if err != nil {
		return nil, 0, false
	}
	rings, err := wktPolygonToCoords(wkt) // rings of [lng, lat]
	if err != nil || len(rings) == 0 {
		return nil, 0, false
	}

	seen := make(map[NodeID]bool)
	var seeds []NodeID
	add := func(lat, lng float64) {
		n := nearestNodeForMode(g, lat, lng, mode)
		if n != noNode && !seen[n] {
			seen[n] = true
			seeds = append(seeds, n)
		}
	}
	for _, ring := range rings {
		for _, pt := range ring {
			add(pt[1], pt[0]) // pt = [lng, lat]
		}
	}
	add(clat, clng) // centroid ensures interior coverage for small budgets

	var conn uint8
	if cn := nearestNodeForMode(g, clat, clng, mode); cn != noNode {
		conn = g.Nodes[cn].Conn
	}
	return seeds, conn, len(seeds) > 0
}

// groupListItem is one entry in the catchment tab's searchable group selector.
type groupListItem struct {
	ID   int64   `json:"id"`
	Name string  `json:"name"`
	Lat  float64 `json:"lat"`
	Lng  float64 `json:"lng"`
}

// handleGroupsList returns every publishable Freegle group (id, short name, polygon
// centroid) for the catchment tab's group picker. Requires MySQL; returns [] without it.
// polyindex stores degree values tagged SRID 3857, so ST_X/ST_Y give lng/lat directly.
func handleGroupsList() fiber.Handler {
	return func(c *fiber.Ctx) error {
		db := ensureGroupsDB()
		if db == nil {
			return c.JSON([]groupListItem{})
		}
		rows, err := db.Query("SELECT id, nameshort, " +
			"ST_Y(ST_Centroid(polyindex)) AS lat, ST_X(ST_Centroid(polyindex)) AS lng " +
			"FROM `groups` WHERE publish = 1 AND listable = 1 AND polyindex IS NOT NULL " +
			"ORDER BY nameshort")
		if err != nil {
			log.Printf("groups list query: %v", err)
			return c.Status(500).JSON(fiber.Map{"error": err.Error()})
		}
		defer rows.Close()

		items := []groupListItem{}
		for rows.Next() {
			var it groupListItem
			var lat, lng sql.NullFloat64
			if err := rows.Scan(&it.ID, &it.Name, &lat, &lng); err != nil {
				continue
			}
			if !lat.Valid || !lng.Valid {
				continue
			}
			it.Lat, it.Lng = lat.Float64, lng.Float64
			items = append(items, it)
		}
		return c.JSON(items)
	}
}
