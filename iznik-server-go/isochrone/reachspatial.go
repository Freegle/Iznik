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
// Always tried: on any spatial error or a not-ready dataset the caller falls
// back to the degraded outer-bound + cells-probe path.

import (
	"fmt"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// spatialReachIDs asks the spatial server which live reaches cover the
// viewer. ok=false (transport error, dataset not ready) means the caller must
// use the degraded outer-bound + cells-probe path.
func spatialReachIDs(db *gorm.DB, latlng utils.LatLng) (in []int64, partial []int64, ok bool) {
	// The call itself lives in rippling so search's reach arm makes the
	// identical decision.
	return rippling.SpatialReachIDs(db, float64(latlng.Lng), float64(latlng.Lat))
}

// reachCandidateQueryFromIDs is reachCandidateQuery with the CONTAINMENT
// answered by id lists instead of geometry: same joins, same unseen and
// author-cap conjuncts, so membership is identical by construction.
//
// Only containment comes from the raster. The reach row's live STATUS is still
// read from MySQL for BOTH buckets, because the raster is rebuilt on a delta
// cadence (iznik-spatial-go dataset_reach.go DeltaInterval, 2 minutes) and a
// hold takes effect the instant a member reports a post or a moderator sends it
// Back to Pending. For up to a delta cycle the index therefore still says a
// held post covers this viewer.
//
// That matters because this query serves the badge COUNT while the feed is
// always served by reachCandidateQuery, which joins rippling_reach and tests
// rr.status != 'held' unconditionally. Any status the two disagree about is a
// count that names a post the feed will not render - the "N new posts" sitting
// above "you're up to date" with nothing in between. The partial bucket already
// had this re-check folded into its exact polygon test; the in bucket had no
// reference to rippling_reach at all, so it counted held posts. Requiring a live
// non-held row for both closes that, and costs one primary-key lookup per id.
// fromIDsWhere builds the containment WHERE for reachCandidateQueryFromIDs:
// the raster id bucket, plus — when a ring admits the viewer to something —
// those posts as a second arm. The rasters only answer the committed reach, and
// the feed (reachOrOverflowSQL) additionally admits via the ring, so the badge
// must too or it undercounts the feed. Every arm requires a live non-held
// reach row, so a held or retracted post cannot be counted in on the
// strength of a raster entry or a ring alone. Pure so the composition is
// unit-testable.
//
// The third arm is a LIST OF MSGIDS (rippling.AdmittedMsgids, which applies the
// non-held rule as it resolves them), never the JSON ring test. An EXISTS
// carrying that test correlates on ms.msgid and is true for rows in NEITHER id
// list, so the optimiser can no longer bound the scan by the raster ids: this
// query stops being the keyed lookup the whole spatial path exists to be, and
// walks messages_spatial instead. That is the same defect, in the same shape,
// that took the feed down on 2026-08-21.
//
// Both plans measured on db1 that day, 15 raster ids and 10 admitted:
// ids    -> ms type=range key=msgid rows=22.
// EXISTS -> ms type=ALL   key=NULL  rows=58,348, with the JSON parse and the
// geometry build repeated per row, on a badge that polls ~2/s.
func fromIDsWhere(in []int64, latlng utils.LatLng, admitted []uint64) (string, []interface{}) {
	ringArm := ""
	var ringArgs []interface{}
	if len(admitted) > 0 {
		// The status re-check rides WITH the id list, as the raster arms' does.
		// The ring ids come from the spatial index, which drops a held reach on
		// its own two-minute delta - too slow for a badge that must never name a
		// post the feed will not render. One primary-key lookup per admitted id
		// closes that, and it is the same EXISTS the other two arms already pay.
		ringArm = "OR (ms.msgid IN (?) AND EXISTS (" +
			"SELECT 1 FROM rippling_reach r3 WHERE r3.msgid = ms.msgid " +
			"AND r3.status != 'held')) "
		ringArgs = []interface{}{admitted}
	}

	whereSQL := "ms.successful = 0 AND ml.msgid IS NULL " +
		"AND ((ms.msgid IN (?) AND EXISTS (" +
		"SELECT 1 FROM rippling_reach r1 WHERE r1.msgid = ms.msgid " +
		"AND r1.status != 'held')) " +
		ringArm + ") " +
		authorReachCapWhere

	// GORM renders an empty slice as IN (NULL) — never matches — which is
	// exactly right for an empty in list.
	whereArgs := []interface{}{in}
	whereArgs = append(whereArgs, ringArgs...)
	whereArgs = append(whereArgs, BrowseDistanceUnlimited, latlng.Lat, latlng.Lng, latlng.Lat)

	return whereSQL, whereArgs
}

func reachCandidateQueryFromIDs(db *gorm.DB, myid uint64, latlng utils.LatLng, in, partial []int64, admitted []uint64) *gorm.DB {
	if len(partial) > 0 {
		// A partial id meant a legacy coarse-raster row whose boundary band
		// needed the exact geometry; healthy rows no longer produce them.
		// Excluded (fail-closed for a badge) rather than silently counted.
		fmt.Printf("badge: %d partial reach ids with no legacy geometry to resolve them\n", len(partial))
	}
	// One concatenated WHERE string in a single Where() call — same GORM
	// extra-paren gotcha as reachCandidateQuery (see there).
	whereSQL, whereArgs := fromIDsWhere(in, latlng, admitted)

	return db.Table("messages_spatial ms").
		Joins("INNER JOIN messages m ON m.id = ms.msgid").
		Joins("INNER JOIN users au ON au.id = m.fromuser").
		Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
		Where(whereSQL, whereArgs...)
}
