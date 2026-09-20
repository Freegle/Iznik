package isochrone

import (
	"log"
	"strings"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// Sandwich-bounds prefilter for the browse reach queries — see
// rippling/reachbounds.go for the shared fragments, the sentinel ladder and the design
// rationale (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). The browse form drives
// the R-tree from the small indexed outer_bound column (the design's target shape), so
// completed posts — degraded to POINT bounds — are pruned by the index itself.
//
// Which ring, if any, could admit a viewer is decided in rippling/overflowviewer.go,
// shared with search, the message page and the reply gates so that "can this member see
// this post" has one answer everywhere.

// reachContainmentSQL returns the WHERE fragment (and its point parameters) for
// testing whether the viewer point lies inside rr's reach: the sandwich form when the
// bounds columns exist, else the legacy exact-polygon test. Callers splice it in place
// of the old ST_Contains conjunct.
func reachContainmentSQL(db *gorm.DB, lng, lat float32) (where string, args []interface{}, probe bool) {
	// Spatial-index id-list first (the badge's proven shape, now the feed's
	// too): the geometry test that was 95-98% of this query's cost is
	// answered from the spatial index — exactly, from the stored cell grids —
	// and SQL runs a keyed IN-list lookup. Any spatial failure falls through
	// to the degraded form below.
	if in, partial, ok := spatialReachIDs(db, utils.LatLng{Lat: lat, Lng: lng}); ok {
		if len(partial) > 0 {
			// A partial id meant a legacy coarse-raster row whose boundary
			// band needed the exact geometry; healthy rows no longer produce
			// them. Surface it rather than silently dropping posts.
			log.Printf("reach containment: %d partial ids with no legacy geometry to resolve them", len(partial))
		}
		// Stored labels are the deciding record wherever they exist: drop any
		// grid-admitted post whose label says the member is NOT reachable by
		// road at the post's current budget - the estuary's far bank - and
		// union in the posts the grid prefilter missed. Posts without labels,
		// and everything when routing is unavailable, keep the grid verdict;
		// overflow rings re-admit on top of this list exactly as they always
		// have (composeReachOverflow).
		// The FEED keeps the grid list when the evaluation is unanswered: an
		// empty page is the degraded shape it has always had, and the client's
		// in-flight guard cannot recover from a rejected fetch until the page
		// is reloaded. The BADGE refuses instead (nearbyCount) - see there.
		in, _ = labelNarrowAndDiscover(lat, lng, in)
		// GORM renders an empty slice as IN (NULL) — matches nothing — which
		// is right for a viewer no reach covers (the ring arm may still admit).
		return "AND ms.msgid IN (?) ", []interface{}{in}, false
	}

	// Degraded: the spatial server is unreachable. The outer bound — a
	// stored SUPERSET of the reach — narrows in SQL, and the caller probes
	// each candidate's stored cells in Go (reachCandidateQuery threads this
	// flag up). Correct and bounded, just slower: the emergency path, not a
	// second authority.
	w, a := rippling.ReachOuterOnlyWhere(float64(lng), float64(lat), utils.SRID)
	return w, a, true
}

// viewerOverflowPaths is every ring path that could let this viewer in: their band or
// deprivation ring plus the cluster wedges. See rippling.ViewerOverflowPaths.
func viewerOverflowPaths(db *gorm.DB, myid uint64, lat, lng float32) []string {
	return rippling.ViewerOverflowPaths(db, myid, lat, lng)
}

// viewerAdmittedMsgids is viewerOverflowPaths resolved to the posts those rings
// actually admit. Every read surface that has to bound a scan wants the ids, not
// the ring test: the JSON test can only be asked cheaply of rows already narrowed
// by an index (rippling.AdmittedMsgids), and asked of anything else it removes the
// index that was doing the narrowing.
func viewerAdmittedMsgids(db *gorm.DB, myid uint64, lat, lng float32) []uint64 {
	return rippling.AdmittedMsgids(db, float64(lng), float64(lat), utils.SRID,
		viewerOverflowPaths(db, myid, lat, lng))
}

// reachProbe rides up from reachOrOverflowSQL when the containment conjunct
// is only the outer-bound SUPERSET (the degraded path in reachContainmentSQL):
// the caller must keep a returned row only when the viewer's point probes into
// its stored cells — except ring-admitted posts, which are in via the ring
// whatever the committed reach says, exactly as the OR arm in the SQL says.
type reachProbe struct {
	lng, lat float64
	admitted map[uint64]struct{}
}

// keep answers the probe for one candidate row. Undecidable cells do not
// admit here - but a RETIRED grid (labels-truth drained it) is a healthy,
// designed state, and filterProbed (isochrone/message.go) routes exactly
// those undecided rows through one batched label evaluation before giving
// up, so an empty blob is never treated as corruption.
func (p *reachProbe) keep(msgid uint64, cells []byte) bool {
	if _, ok := p.admitted[msgid]; ok {
		return true
	}
	in, ok := rippling.CellSetContains(cells, p.lng, p.lat)
	return ok && in
}

// labelNarrowAndDiscover applies the labels-truth transform to a
// grid-admitted id list: drop ids whose stored label verdicts the member
// OUT at the post's current budget, and append the labelled posts the grid
// prefilter missed (discover). The feed and the badge count both go through
// here, so they can never disagree about which posts the labels admit. The
// SQL's own visibility conjuncts (held status, spatial joins) still apply
// to every id, discovered ones included.
//
// ok=false means the question went unanswered (routing unreachable, breaker
// open, a 503 that survived the retry) and the list comes back untouched.
// Since the grids retired that list is empty, so an unanswered question
// looks exactly like "nothing is in reach" - which is why the flag exists:
// each caller decides what unanswered means for its surface.
func labelNarrowAndDiscover(lat, lng float32, in []int64) ([]int64, bool) {
	ids := make([]uint64, len(in))
	for i, id := range in {
		ids[i] = uint64(id)
	}
	verdicts, discovered, ok := rippling.LabelVerdictsWithDiscover(float64(lat), float64(lng), ids)
	if !ok {
		return in, false
	}
	in = rippling.DropLabelOut(in, verdicts)
	for _, id := range discovered {
		in = append(in, int64(id))
	}
	return in, true
}

// reachOrOverflowSQL is reachContainmentSQL plus, when any overflow ring applies to this
// viewer, the rings as alternative ways in. Returned as ONE conjunct so it can be spliced
// into the single concatenated WHERE the browse query builds - see the note in
// reachCandidateQuery about why that query cannot be split across several Where() calls.
// A non-nil reachProbe means the conjunct is a superset and rows need the Go-side probe.
func reachOrOverflowSQL(db *gorm.DB, myid uint64, lng, lat float32) (string, []interface{}, *reachProbe) {
	reachWhere, reachArgs, probe := reachContainmentSQL(db, lng, lat)

	admitted := viewerAdmittedMsgids(db, myid, lat, lng)

	// The rings are resolved to msgids HERE, so composeReachOverflow stays pure
	// and its SQL shape remains directly testable - which is the point of the
	// split, and the shape is where this fails silently.
	where, args := composeReachOverflow(reachWhere, reachArgs, admitted)
	if !probe {
		return where, args, nil
	}
	p := &reachProbe{lng: float64(lng), lat: float64(lat), admitted: make(map[uint64]struct{}, len(admitted))}
	for _, id := range admitted {
		p.admitted[id] = struct{}{}
	}
	return where, args, p
}

// composeReachOverflow brackets the reach test and the rings as ALTERNATIVES within one
// conjunct. Kept separate from the database lookup above so the SQL shape can be tested
// directly - the shape is where this fails silently, since a stray keyword or a
// mis-ordered parameter still runs and still returns a plausible feed.
//
// No paths returns the containment SQL untouched, byte for byte: a lane nobody is using
// must not change the query the feed has always run, nor its cost.
func composeReachOverflow(reachWhere string, reachArgs []interface{}, admitted []uint64) (string, []interface{}) {
	// The ring arm is a LIST OF MSGIDS, not the JSON ring test.
	//
	// Splicing the JSON test in here is what took the site down on 2026-08-21:
	// ORing JSON_EXTRACT against the spatial containment removed the SPATIAL
	// index from the feed's query - EXPLAIN key=rippling_reach_polygon rows=1
	// became key=NULL rows=62,534 - so every uncached feed load scanned a ~17GB
	// table. This is the browse feed, so that is most of them.
	//
	// Resolving the rings to msgids first keeps both indexes: measured on db1,
	// the browse form with `... OR rr.msgid IN (10 ids)` plans as
	// index_merge sort_union(rippling_reach_outer, PRIMARY), rows=11.
	//
	// No admitted posts returns the containment SQL untouched, byte for byte: a
	// viewer no ring admits must run precisely the query the feed always ran,
	// and must not pay for an arm that can never match.
	if len(admitted) == 0 {
		return reachWhere, reachArgs
	}

	// reachWhere is a run of conjuncts opening with "AND ", so that keyword is stripped and
	// re-applied around the pair; wrapping without stripping would leave "AND ((AND ...".
	// The outer brackets are load-bearing: without them the OR would escape the conjunct and
	// dissolve every other filter in the WHERE.
	inner := strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(reachWhere), "AND"))

	return "AND ((" + inner + ") OR rr.msgid IN (?)) ",
		append(append([]interface{}{}, reachArgs...), admitted)
}
