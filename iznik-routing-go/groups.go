package main

import (
	"database/sql"
	"fmt"
	"log"
	"math"
	"os"
	"strconv"
	"strings"

	_ "github.com/go-sql-driver/mysql"
	"github.com/gofiber/fiber/v2"
)

// Web Mercator (SRID 3857) ↔ WGS84 helpers.
// polyindex is stored in SRID 3857; we convert Go-side to avoid MySQL axis-order
// ambiguity with geographic SRIDs.

const mercatorHalfCircum = 20037508.34

func lngLatToMerc(lng, lat float64) (x, y float64) {
	x = lng * mercatorHalfCircum / 180.0
	y = math.Log(math.Tan((90.0+lat)*math.Pi/360.0)) * (mercatorHalfCircum / 180.0)
	return
}

func mercToLng(x float64) float64 { return x * 180.0 / mercatorHalfCircum }
func mercToLat(y float64) float64 {
	return math.Atan(math.Exp(y*math.Pi/mercatorHalfCircum))*360.0/math.Pi - 90.0
}

// wktPolygonToCoords parses a WKT POLYGON string whose coordinates are in
// SRID 3857 (meters) and converts each vertex to GeoJSON [lng, lat] degrees.
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
			// 3857 WKT: POINT(x y) = POINT(easting northing) — x=lng direction, y=lat direction
			xMeters, err1 := strconv.ParseFloat(fields[0], 64)
			yMeters, err2 := strconv.ParseFloat(fields[1], 64)
			if err1 != nil || err2 != nil {
				continue
			}
			lng := mercToLng(xMeters)
			lat := mercToLat(yMeters)
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

func initGroupsDB() {
	host := os.Getenv("MYSQL_HOST")
	if host == "" {
		return
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
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true", user, pass, host, port, dbname)
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
	log.Printf("groups: MySQL connected (%s:%s/%s)", host, port, dbname)
}

// handleNearbyGroups returns a GeoJSON FeatureCollection of Freegle groups near
// the given lat/lng. Each feature carries a "contains" boolean: true when the
// offer point falls inside that group's polygon (i.e. the home group), false for
// adjacent neighbours. Requires MySQL connection.
//
// polyindex is stored in SRID 3857 (Web Mercator). We convert the input WGS84
// coordinates to 3857 in Go so the MySQL query stays in a single SRS and we
// avoid MySQL 8.0 geographic-SRS axis-order ambiguity.
func handleNearbyGroups() fiber.Handler {
	return func(c *fiber.Ctx) error {
		if groupsDB == nil {
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

		// Convert offer point to 3857 meters for the spatial query.
		ptX, ptY := lngLatToMerc(lngF, latF)

		// Bounding box: ±1.5° ≈ ±167 km latitude / ±107 km longitude at 51°N.
		// Compute in 3857 so the centroid range check is in the same SRS.
		boxXMin, boxYMin := lngLatToMerc(lngF-1.5, latF-1.5)
		boxXMax, boxYMax := lngLatToMerc(lngF+1.5, latF+1.5)

		rows, err := groupsDB.Query(`
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
			LIMIT 60
		`,
			ptX, ptY, // SELECT contains_pt
			ptX, ptY, // WHERE ST_Contains
			boxXMin, boxXMax, // centroid X (easting) range
			boxYMin, boxYMax, // centroid Y (northing) range
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
