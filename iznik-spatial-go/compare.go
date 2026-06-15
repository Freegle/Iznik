//go:build ignore

// compare.go — one-off tool to compare KNN server vs PostGIS results.
// Build: go build -o compare compare.go
// Run:   ./compare
package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"time"

	_ "github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
)

const (
	knnURL  = "http://localhost:8194/knn"
	pgHost  = "bulk3-internal"
	pgPort  = 5432
	pgUser  = "iznik"
	pgPass  = "F5432f12azfvds"
	pgDB    = "iznik"
	srid    = 3857
	samples = 1000
)

type sample struct {
	ID   int64
	Name string
	Lng  float64
	Lat  float64
}

func main() {
	// MySQL — get sample postcode centroids.
	mysqlDSN := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true",
		getenv("MYSQL_USER", "root"),
		getenv("MYSQL_PASSWORD", ""),
		getenv("MYSQL_HOST", "localhost"),
		getenv("MYSQL_PORT", "3306"),
		getenv("MYSQL_DBNAME", "iznik"),
	)
	my, err := sql.Open("mysql", mysqlDSN)
	if err != nil {
		log.Fatalf("mysql: %v", err)
	}
	defer my.Close()

	rows, err := my.Query(`
		SELECT l.id, l.name,
			   ST_X(ST_Centroid(COALESCE(l.ourgeometry, l.geometry))) AS lng,
			   ST_Y(ST_Centroid(COALESCE(l.ourgeometry, l.geometry))) AS lat
		FROM locations l
		WHERE l.type = 'Postcode'
		  AND COALESCE(l.ourgeometry, l.geometry) IS NOT NULL
		ORDER BY RAND()
		LIMIT ?
	`, samples)
	if err != nil {
		log.Fatalf("mysql query: %v", err)
	}

	var pts []sample
	for rows.Next() {
		var s sample
		if err := rows.Scan(&s.ID, &s.Name, &s.Lng, &s.Lat); err != nil {
			log.Fatalf("scan: %v", err)
		}
		pts = append(pts, s)
	}
	rows.Close()
	log.Printf("Sampled %d postcodes from MySQL", len(pts))

	// PostgreSQL connection.
	pgDSN := fmt.Sprintf("host=%s port=%d user=%s password=%s dbname=%s sslmode=disable",
		pgHost, pgPort, pgUser, pgPass, pgDB)
	pg, err := sql.Open("postgres", pgDSN)
	if err != nil {
		log.Fatalf("pgsql open: %v", err)
	}
	defer pg.Close()
	if err := pg.Ping(); err != nil {
		log.Fatalf("pgsql ping: %v", err)
	}
	log.Println("Connected to PostgreSQL")

	pgQuery := fmt.Sprintf(`
		WITH ourpoint AS (
			SELECT ST_MakePoint($1, $2) AS p
		)
		SELECT locationid FROM (
			SELECT
				locationid,
				ST_Area(location) AS area,
				CASE
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.00015625), %d)) THEN 1
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.0003125), %d)) THEN 2
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.000625), %d)) THEN 3
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.00125), %d)) THEN 4
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.0025), %d)) THEN 5
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.005), %d)) THEN 6
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.01), %d)) THEN 7
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.02), %d)) THEN 8
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.04), %d)) THEN 9
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.08), %d)) THEN 10
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.16), %d)) THEN 11
					WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint), 0.32), %d)) THEN 12
				END AS intersects
			FROM (
				SELECT locationid,
					   location,
					   location <-> ST_SetSRID((SELECT p FROM ourpoint), %d) AS dist
				FROM locations
				WHERE ST_Area(location) BETWEEN 0.00001 AND 0.15
				ORDER BY location <-> ST_SetSRID((SELECT p FROM ourpoint), %d)
				LIMIT 10
			) q
		) candidates
		WHERE intersects IS NOT NULL
		ORDER BY intersects ASC, area ASC
		LIMIT 1`,
		srid, srid, srid, srid, srid, srid,
		srid, srid, srid, srid, srid, srid,
		srid, srid)

	// Compare.
	match, mismatch, bothNull, knnOnly, pgOnly := 0, 0, 0, 0, 0
	var totalKNN, totalPG time.Duration

	fmt.Printf("\n%-12s %8s %8s %8s %8s  %s\n", "Postcode", "KNN ID", "PG ID", "KNN ms", "PG ms", "Status")
	fmt.Println("------------ -------- -------- -------- --------  ------")

	for _, pt := range pts {
		// Query KNN server.
		t0 := time.Now()
		knnID := queryKNN(pt.Lng, pt.Lat)
		knnDur := time.Since(t0)
		totalKNN += knnDur

		// Query PostgreSQL.
		t1 := time.Now()
		var pgID *int64
		var tmp int64
		err := pg.QueryRow(pgQuery, pt.Lng, pt.Lat).Scan(&tmp)
		if err == nil {
			pgID = &tmp
		}
		pgDur := time.Since(t1)
		totalPG += pgDur

		status := ""
		switch {
		case knnID == nil && pgID == nil:
			bothNull++
			status = "both-null"
		case knnID != nil && pgID != nil && *knnID == *pgID:
			match++
			status = "MATCH"
		case knnID != nil && pgID == nil:
			knnOnly++
			status = "KNN-only"
		case knnID == nil && pgID != nil:
			pgOnly++
			status = "PG-only"
		default:
			mismatch++
			status = "MISMATCH"
		}

		knnStr, pgStr := "null", "null"
		if knnID != nil {
			knnStr = fmt.Sprintf("%d", *knnID)
		}
		if pgID != nil {
			pgStr = fmt.Sprintf("%d", *pgID)
		}
		fmt.Printf("%-12s %8s %8s %7.1f  %7.1f   %s\n",
			pt.Name, knnStr, pgStr,
			float64(knnDur.Microseconds())/1000.0,
			float64(pgDur.Microseconds())/1000.0,
			status)
	}

	n := len(pts)
	fmt.Printf("\n=== Summary (%d samples) ===\n", n)
	fmt.Printf("Match:      %d (%.1f%%)\n", match, pct(match, n))
	fmt.Printf("Mismatch:   %d (%.1f%%)\n", mismatch, pct(mismatch, n))
	fmt.Printf("Both null:  %d (%.1f%%)\n", bothNull, pct(bothNull, n))
	fmt.Printf("KNN only:   %d (%.1f%%)\n", knnOnly, pct(knnOnly, n))
	fmt.Printf("PG only:    %d (%.1f%%)\n", pgOnly, pct(pgOnly, n))
	fmt.Printf("\nAvg latency — KNN: %.1fms, PG: %.1fms (%.1fx)\n",
		float64(totalKNN.Microseconds())/float64(n)/1000.0,
		float64(totalPG.Microseconds())/float64(n)/1000.0,
		float64(totalPG.Microseconds())/float64(totalKNN.Microseconds()))
}

func queryKNN(lng, lat float64) *int64 {
	resp, err := http.Get(fmt.Sprintf("%s?lng=%f&lat=%f", knnURL, lng, lat))
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	var result struct {
		LocationID *int64 `json:"locationid"`
	}
	json.NewDecoder(resp.Body).Decode(&result)
	return result.LocationID
}

func pct(v, total int) float64 {
	if total == 0 {
		return 0
	}
	return 100.0 * float64(v) / float64(total)
}

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
