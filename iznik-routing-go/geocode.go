package main

import (
	"database/sql"
	"log"
)

// resolvePlace reverse-geocodes a (lat,lng) point to its nearest postcode and the containing
// place/area name, using the already-open groupsDB connection (groups.go) — self-contained, no
// cross-service HTTP call. locations.areaid on a Postcode row already points directly at the
// containing place's locations.id (the same join iznik-server's Location::closestPostcode() uses
// in PHP), so a single query resolves both in one round trip.
//
// Best-effort: on any error, no row, or a nil db (e.g. no MySQL configured), returns ("", "") —
// callers must treat both as optional and degrade gracefully; the group-extent response is still
// returned, the postcode/place fields are simply omitted.
func resolvePlace(db *sql.DB, lat, lng float64) (postcode, place string) {
	if db == nil {
		return "", ""
	}
	row := db.QueryRow(`
		SELECT p.name AS postcode, COALESCE(a.name,'') AS place
		FROM locations p LEFT JOIN locations a ON a.id = p.areaid
		WHERE p.type='Postcode' AND p.lat BETWEEN ?-0.1 AND ?+0.1 AND p.lng BETWEEN ?-0.15 AND ?+0.15
		ORDER BY ST_Distance_Sphere(POINT(p.lng,p.lat), POINT(?,?)) LIMIT 1
	`, lat, lat, lng, lng, lng, lat)
	if err := row.Scan(&postcode, &place); err != nil {
		if err != sql.ErrNoRows {
			log.Printf("resolvePlace: query failed: %v", err)
		}
		return "", ""
	}
	return postcode, place
}
