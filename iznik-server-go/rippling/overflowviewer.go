package rippling

import (
	"log"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/spatial"
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

// The ring bbox side table (rippling_reach_overflow) is no longer read here.
// It was built on 2026-08-21 to narrow the JSON ring test to a few candidates,
// and it did - 836 candidates in 8.7ms - but narrowing was never the problem:
// 558 of those 836 genuinely admitted, and parsing their 37k-vertex rings cost
// 4.8s regardless. AdmittedMsgids now asks the spatial server's rasters
// instead. iznik-batch still maintains the table (RipplingOverflowIndex), which
// costs one small upsert per ripple and leaves the fallback available; nothing
// on the read path depends on it.

// AdmittedMsgids returns the posts whose rings admit this point.
//
// Answered ENTIRELY by the spatial server - no database work, and no local
// derivation. Every surface calls THIS, so that "does a ring admit this member"
// has one answer: the feed, the badge, search, the message page banner, the
// reply gate, and (through its own client) the mail. Consistency between them is
// not a property to be maintained by keeping several copies of a JSON test in
// step; it is a property of there being one copy.
//
// Two earlier shapes are why the database is not involved:
//
//   - the ring JSON in the query: 37k-vertex polygons, 836 of them at one real
//     point, 4.8s per page load, and it removed the index it sat beside. Took
//     the site down twice on 2026-08-21.
//   - the raster plus an exact test of its boundary band: bounded to a handful
//     of msgids, which sounded cheap, but a viewer's band carries up to four
//     lanes and each row parses a ring per lane. 4-14 running concurrently at
//     1-6s each; the read node went from load 8.5 to 45 in five minutes.
//
// So the band is not resolved, by anyone. The raster is conservative - a cell
// counts as "in" only when the whole cell is inside the ring - so this never
// admits someone a ring does not, and the strip it declines is about one cell
// wide just inside each ring's edge. Every surface declines the same strip,
// which is what makes it acceptable: nobody is mailed a post another surface
// will refuse them.
//
// Returns nil when no ring can apply, and when the spatial server cannot answer.
// Ring members then see the committed reach only, on every surface at once,
// logged and throttled.
func AdmittedMsgids(db *gorm.DB, lng, lat float64, srid int, paths []string) []uint64 {
	lanes := knownLanes(paths)
	if len(lanes) == 0 {
		return nil
	}

	in, _, err := spatial.ReachOverflowContaining(lng, lat, lanes)
	if err != nil {
		logRingLookupFailure(err)
		return nil
	}

	ids := make([]uint64, 0, len(in))
	for _, id := range in {
		if id > 0 {
			ids = append(ids, uint64(id))
		}
	}
	return ids
}

// ringFailureLog throttles the "rings are dark" line to one a minute: enough to
// notice and to timestamp the window, not enough to bury everything else.
var ringFailureLog struct {
	mu   sync.Mutex
	last time.Time
}

func logRingLookupFailure(err error) {
	ringFailureLog.mu.Lock()
	defer ringFailureLog.mu.Unlock()

	if time.Since(ringFailureLog.last) < time.Minute {
		return
	}
	ringFailureLog.last = time.Now()
	log.Printf("rippling: ring lookup unavailable, showing committed reach only: %v", err)
}
