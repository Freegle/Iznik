package rippling

import (
	"os"
	"strconv"
	"strings"
	"sync"

	"gorm.io/gorm"
)

// Which overflow ring, if any, could admit a given viewer. Shared by every read
// surface - the browse feed and badge (isochrone), browse-scoped search and the
// reach-blocked banner (message), and the web reply gate (chat) - so "can this
// member see this post" has exactly one answer everywhere. The mail side
// (UnifiedDigestService in iznik-batch) applies the same rings; a member the
// mail admits must never be turned away by a read surface.

// RuralOverflowEnabled reports whether the rural-access lane is on for reads.
//
// ON unless explicitly switched off, matching the batch side's config default
// (freegle.ripple.rural_access.enabled): the lane is live behaviour, and the
// two halves of it must not ship with opposite defaults - that is exactly the
// split we are recovering from, where mail invited members the site then
// refused.
func RuralOverflowEnabled() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("RIPPLE_RURAL_ACCESS_ENABLED")))
	return v != "0" && v != "false" && v != "no" && v != "off"
}

// ruralBandPaths maps a member's recorded density band to the ring that band earns. Built
// from a fixed set so that a settings value can never become part of a JSON path: the band
// is looked UP here, never interpolated.
var ruralBandPaths = map[string]string{
	"dense":  "$.rural.dense",
	"medium": "$.rural.medium",
	"sparse": "$.rural.sparse",
}

// RuralBandPath is the ring path a band earns, or "" for anything unrecognised.
func RuralBandPath(band string) string {
	return ruralBandPaths[band]
}

// ViewerRuralPath is the rural ring path for this viewer, or "" when the lane cannot
// apply: switched off, no viewer, or no band recorded for them.
//
// A member with no band recorded gets NOTHING extra, which is the safe direction. The
// band is written by the batch backfill, so before that has run - or for a member it
// has not reached - treating absence as "matches anything" would widen the feed for
// the whole membership.
func ViewerRuralPath(db *gorm.DB, myid uint64) string {
	if myid == 0 || !RuralOverflowEnabled() {
		return ""
	}

	var band string
	// COALESCE so a member who has never had the key written scans cleanly into a
	// non-nullable string instead of erroring.
	db.Table("users").
		Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseDensityBand')), '') AS band").
		Where("id = ?", myid).
		Scan(&band)

	return ruralBandPaths[band]
}

// FairnessOverflowEnabled reports whether the deprivation lane is on for reads. Off by
// default, matching the batch config (freegle.ripple.fairness.enabled).
func FairnessOverflowEnabled() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("RIPPLE_FAIRNESS_ENABLED")))
	return v == "1" || v == "true" || v == "yes"
}

// FairnessMaxQuintile is how far down the deprivation scale the lane reaches, clamped to
// the range the rings are built for. Must agree with the batch: a viewer admitted by one
// surface and refused by another is worse than either behaviour on its own.
func FairnessMaxQuintile() int {
	q, err := strconv.Atoi(strings.TrimSpace(os.Getenv("RIPPLE_FAIRNESS_MAX_QUINTILE")))
	if err != nil || q < 1 {
		return 1
	}
	if q > 4 {
		return 4
	}
	return q
}

// ViewerFairnessPath is the ring path for this viewer's deprivation fifth, or "" when the
// lane cannot apply to them.
//
// The fifth comes from the spatial server rather than the database, because that is the
// only place it exists - and asking keeps it that way: nothing records what fifth anyone
// is in. Any failure yields "", so an outage costs the lane its extra posts rather than
// showing everyone inside a stretched ring.
func ViewerFairnessPath(lat, lng float32) string {
	if !FairnessOverflowEnabled() {
		return ""
	}

	q := QuintileFor(float64(lat), float64(lng))
	if q < 1 || q > FairnessMaxQuintile() {
		return ""
	}

	// Quoted: a JSON path member that is a number is not a bare identifier, so
	// $.fairness.1 does not address the ring - it silently matches nothing.
	return "$.fairness.\"" + strconv.Itoa(q) + "\""
}

// ViewerOverflowPath is the band-or-fairness ring for this viewer. Rural first, because
// it is answered from a column already to hand; the deprivation lane only then, so its
// network call is not paid by viewers the rural ring has already admitted.
//
// A post carries rings from at most one of these two lanes (the routing server picks by
// whether the audience cap bound), so at most one can ever match. The cluster lane is
// separate - see ViewerOverflowPaths.
func ViewerOverflowPath(db *gorm.DB, myid uint64, lat, lng float32) string {
	if p := ViewerRuralPath(db, myid); p != "" {
		return p
	}
	return ViewerFairnessPath(lat, lng)
}

// ClusterOverflowEnabled reports whether the cluster-anchor lane is on for reads. On
// unless explicitly switched off, like the rural lane: live behaviour is the default.
func ClusterOverflowEnabled() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("RIPPLE_CLUSTER_ANCHOR_ENABLED")))
	return v != "0" && v != "false" && v != "no" && v != "off"
}

// clusterPaths are the wedge slots a cluster-anchored post may carry: up to three bearing
// wedges toward the strongest member clusters just beyond a very sparse post's reach. A
// fixed set, like the band paths: nothing user-supplied can become part of a JSON path.
var clusterPaths = []string{"$.cluster.w1", "$.cluster.w2", "$.cluster.w3"}

// ViewerOverflowPaths is every ring path that could admit this viewer: their band or
// fairness ring, if any, plus the cluster wedges.
//
// EVERY lane needs a viewer. A ring admits a PERSON standing at their OWN location, so
// with no viewer there is no one to admit: callers without one (the matched-posts mailer
// in message/postmatches.go) are asking whether a post has rippled to another POST's
// location, and answering that with a ring would rescue a post on the strength of a
// wedge pointed at a town neither of them is in. That matters most for the cluster lane,
// which is pull-only and must never decide what we send: postmatches.go feeds the
// matched-posts EMAIL.
//
// Within a viewer, the cluster lane is unconditional on BAND - the wedges sit beyond
// every band's ceiling (they exist precisely because the nearest town is further than the
// widest budget), so gating them on a band would turn the lane off for exactly the town
// members it was built to reach. Band-unconditional is not the same as viewer-optional.
func ViewerOverflowPaths(db *gorm.DB, myid uint64, lat, lng float32) []string {
	if myid == 0 {
		return nil
	}

	var paths []string
	if p := ViewerOverflowPath(db, myid, lat, lng); p != "" {
		paths = append(paths, p)
	}
	if ClusterOverflowEnabled() {
		paths = append(paths, clusterPaths...)
	}
	return paths
}

// OverflowWhereAny is the containment test for a set of ring paths against one reach
// row's overflow_bounds. The bbox prefilter is shared - the box covers every ring the
// row carries, so one cheap box test guards however many polygon parses follow, with the
// same argument order per path as RuralOverflowWhere. No usable paths yields "" -
// callers splice nothing, keeping the untouched-query guarantee.
func OverflowWhereAny(lng, lat float64, srid int, paths []string) (string, []interface{}) {
	usable := make([]string, 0, len(paths))
	for _, p := range paths {
		if p != "" {
			// A caller with no path for a lane passes "" rather than filtering; an
			// empty path must contribute nothing, not a fragment that matches
			// nothing at real query cost.
			usable = append(usable, p)
		}
	}
	if len(usable) == 0 {
		return "", nil
	}

	where := "(rr.overflow_bounds IS NOT NULL " +
		"AND ? BETWEEN JSON_EXTRACT(rr.overflow_bounds, '$.bbox[0]') AND JSON_EXTRACT(rr.overflow_bounds, '$.bbox[2]') " +
		"AND ? BETWEEN JSON_EXTRACT(rr.overflow_bounds, '$.bbox[1]') AND JSON_EXTRACT(rr.overflow_bounds, '$.bbox[3]') " +
		"AND ("
	args := []interface{}{lng, lat}
	for i, p := range usable {
		if i > 0 {
			where += "OR "
		}
		// COALESCE is load-bearing: a row without this path yields a NULL geometry, and
		// ST_Contains(NULL, ...) is NULL, not FALSE. In a negated context (the banner's
		// "AND NOT <ring>") that NULL would poison the whole predicate and quietly
		// unblock every post carrying any overflow row at all.
		where += "COALESCE(ST_Contains(ST_GeomFromText(JSON_UNQUOTE(JSON_EXTRACT(rr.overflow_bounds, ?)), ?), ST_SRID(POINT(?, ?), ?)), 0) = 1 "
		args = append(args, p, srid, lng, lat, srid)
	}
	where = strings.TrimSpace(where) + ")) "

	return where, args
}

// OverflowRowSelector returns a WHERE fragment that isolates the reach rows
// carrying an overflow ring - about 4,200 of 55,000 on prod.
//
// The ring test itself (OverflowWhereAny) is JSON_EXTRACT over a column, which
// no index can serve. That is survivable only if something else has already cut
// the rows down; ORing it into a spatial predicate instead removed the SPATIAL
// index and full-scanned a 17GB table, which is what took the site down on
// 2026-08-21 (EXPLAIN went from key=rippling_reach_polygon rows=1 to key=NULL
// rows=62,534, and the arm measured 49s standalone).
//
// Prefers the generated has_overflow column when the migration adding it has
// been applied, and falls back to the unindexed IS NOT NULL test otherwise, so
// the code is correct either side of a hand-applied DDL. Checked once per
// process: this is called from read paths.
var (
	hasOverflowOnce sync.Once
	hasOverflowCol  bool
)

func OverflowRowSelector(db *gorm.DB) string {
	hasOverflowOnce.Do(func() {
		var n int64
		err := db.Raw("SELECT COUNT(*) FROM information_schema.columns " +
			"WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' " +
			"AND column_name = 'has_overflow'").Scan(&n).Error
		hasOverflowCol = err == nil && n > 0
	})

	if hasOverflowCol {
		return "rr.has_overflow = 1"
	}

	return "rr.overflow_bounds IS NOT NULL"
}

// --- indexed ring prefilter -------------------------------------------------
//
// rippling_reach_overflow holds each ring family's bounding box as an indexed
// POLYGON. It exists because the authoritative rings are JSON in
// rippling_reach.overflow_bounds, which no index can answer: asking the ring
// question against that column means a scan of a ~17GB table, and ORing it into
// the spatial containment predicate took the site down on 2026-08-21. Indexing
// rippling_reach in place was not available either - the ALTER blocks the
// cluster under TOI and blocks the node under RSU - so the index lives here.
//
// This is a PREFILTER, not the answer. It narrows to the handful of posts whose
// rings could admit this point; the exact per-lane test still runs against the
// JSON, bounded to those msgids by primary key.

var (
	prefilterOnce  sync.Once
	prefilterReady bool
)

// OverflowPrefilterReady reports whether the side table exists AND has rows.
//
// Emptiness matters as much as absence: an empty table would silently answer
// "no post has a ring", quietly removing every ring member's access while
// looking perfectly healthy. Until the backfill has run we keep using the JSON
// path. Checked once per process - this is called from read paths.
func OverflowPrefilterReady(db *gorm.DB) bool {
	prefilterOnce.Do(func() {
		var n int64
		if err := db.Raw("SELECT COUNT(*) FROM information_schema.tables " +
			"WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_overflow'").
			Scan(&n).Error; err != nil || n == 0 {
			return
		}

		var rows int64
		if err := db.Raw("SELECT COUNT(*) FROM (SELECT 1 FROM rippling_reach_overflow LIMIT 1) t").
			Scan(&rows).Error; err == nil && rows > 0 {
			prefilterReady = true
		}
	})

	return prefilterReady
}

// OverflowCandidates returns the msgids whose ring bounding box contains the
// point, straight off the SPATIAL index.
func OverflowCandidates(db *gorm.DB, lng, lat float64, srid int) []uint64 {
	var ids []uint64
	db.Table("rippling_reach_overflow").
		Select("msgid").
		Where("ST_Contains(bbox, ST_SRID(POINT(?, ?), ?))", lng, lat, srid).
		Scan(&ids)

	return ids
}
