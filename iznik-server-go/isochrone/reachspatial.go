package isochrone

// The unread badge's reach-containment via the spatial server, replacing the
// MySQL geometry walk that was 95-98% of the badge-count query (measured
// 2026-08-11: 300-500ms per call at ~215 calls/min peak — ~2 mysqld cores on
// the write node — because 65-84% of sandwich-bounds candidates have no inner
// bound and fall through to exact ST_Contains on ~11k-vertex polygon BLOBs).
//
// The spatial server rasterises each reach polygon once at load into a
// tri-state grid, so "which reaches cover this viewer" is answered from RAM:
// `in` ids are definite (raster cell fully inside the polygon), `partial`
// ids sit in the one-cell boundary band and are exact-tested here in SQL —
// a handful of rows by primary key, not hundreds — so the result is exactly
// the old query's, only the bulk geometry work is gone.
//
// Dark-launched: SPATIAL_REACH_MODE=on enables it per node; anything else
// (or any spatial error, or a not-ready dataset) falls back to the SQL
// containment path unchanged.

import (
	"os"

	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// spatialReachIDs asks the spatial server which live reaches cover the
// viewer. ok=false (mode off, transport error, dataset not ready) means the
// caller must use the SQL containment path.
func spatialReachIDs(latlng utils.LatLng) (in []int64, partial []int64, ok bool) {
	// Read fresh each call (cheap next to the network hop): lets tests point
	// SPATIAL_KNN_URL at a stub with t.Setenv, and ops flip the mode per node
	// via .env + monit restart.
	if os.Getenv("SPATIAL_REACH_MODE") != "on" {
		return nil, nil, false
	}
	in, partial, err := spatial.ReachContaining(float64(latlng.Lng), float64(latlng.Lat))
	if err != nil {
		return nil, nil, false
	}
	return in, partial, true
}

// reachCandidateQueryFromIDs is reachCandidateQuery with the containment
// answered by id lists instead of geometry: same joins (minus rippling_reach
// — its only jobs here were containment and the held filter), same unseen
// and author-cap conjuncts, so membership is identical by construction.
// Partial ids get the exact polygon test, by primary key, with the held
// re-check folded in (a hold newer than the spatial delta cadence must still
// hide the post).
func reachCandidateQueryFromIDs(db *gorm.DB, myid uint64, latlng utils.LatLng, in, partial []int64) *gorm.DB {
	// One concatenated WHERE string in a single Where() call — same GORM
	// extra-paren gotcha as reachCandidateQuery (see there).
	whereSQL := "ms.successful = 0 AND ml.msgid IS NULL " +
		"AND (ms.msgid IN (?) OR (ms.msgid IN (?) AND EXISTS (" +
		"SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = ms.msgid " +
		"AND r2.status != 'held' " +
		"AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?))))) " +
		authorReachCapWhere

	// GORM renders an empty slice as IN (NULL) — never matches — which is
	// exactly right for an empty in or partial list.
	whereArgs := []interface{}{
		in, partial, latlng.Lng, latlng.Lat, utils.SRID,
		BrowseDistanceUnlimited, latlng.Lat, latlng.Lng, latlng.Lat,
	}

	return db.Table("messages_spatial ms").
		Joins("INNER JOIN messages m ON m.id = ms.msgid").
		Joins("INNER JOIN users au ON au.id = m.fromuser").
		Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
		Where(whereSQL, whereArgs...)
}
