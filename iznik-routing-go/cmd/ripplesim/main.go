// ripplesim — Layer-1 simulator for the ripple notification algorithm.
//
// Reads a JSON file produced by `ripple_simulator_extract.php` containing
// historical (post, replier) records, asks the routing server's
// /v1/ripple-eval endpoint to compute each replier's drive-time-and-rank
// relative to the post location (slow; cached), then evaluates a set of
// candidate curves against the cached data (fast; many curves).
//
// The metric is "reach-in-time fraction" — for each historical (post, replier)
// pair, would the candidate schedule have notified this replier before they
// actually replied?  This is a relative comparison: we don't claim more
// replies would have occurred under the new algorithm, only that the
// schedule that hits this metric reliably is closer to the right shape.
//
// Usage:
//
//	go run ./cmd/ripplesim \
//	    --data=/tmp/ripple_sim_data.json \
//	    --cache=/tmp/ripple_sim_cache.json \
//	    --routing=http://localhost:8194 \
//	    --ticks=30 \
//	    --post-lifetime-days=30 \
//	    --max-minutes=30 \
//	    --workers=8
//
// On first run it populates the cache by calling the routing server once per
// post.  Subsequent runs reuse the cache and only re-run the simulation step.
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"math"
	"net/http"
	"os"
	"sort"
	"sync"
	"sync/atomic"
	"time"
)

// ---------------------------------------------------------------------------
// Input data structures (must match what the PHP extractor writes).
// ---------------------------------------------------------------------------

type extractedReplier struct {
	UserID    int     `json:"user_id"`
	Lat       float64 `json:"lat"`
	Lng       float64 `json:"lng"`
	ReplyTime string  `json:"reply_time"` // "2025-01-15 11:30:00"
	EmailFreq *int    `json:"email_freq,omitempty"`
}

type extractedPost struct {
	PostID     int                `json:"post_id"`
	Lat        float64            `json:"lat"`
	Lng        float64            `json:"lng"`
	Arrival    string             `json:"arrival"`
	Type       string             `json:"type"`
	FromUser   int                `json:"fromuser"`
	Outcome    *string            `json:"outcome"`
	OutcomeAt  *string            `json:"outcome_at"`
	RUCategory *string            `json:"ru_category"`
	Repliers   []extractedReplier `json:"repliers"`
}

// ---------------------------------------------------------------------------
// Cache: per-post results of calling /v1/ripple-eval.
// ---------------------------------------------------------------------------

type evalPoint struct {
	DriveMin *float64 `json:"drive_min"`
	Rank     *int     `json:"rank"`
}

type evalResponse struct {
	TotalReachable int         `json:"total_reachable"`
	Results        []evalPoint `json:"results"`
}

type cachedPost struct {
	PostID         int         `json:"post_id"`
	TotalReachable int         `json:"total_reachable"`
	Repliers       []evalPoint `json:"repliers"` // aligned with extractedPost.Repliers
}

type cacheFile struct {
	GeneratedAt string       `json:"generated_at"`
	Posts       []cachedPost `json:"posts"`
}

// ---------------------------------------------------------------------------
// CLI flags.
// ---------------------------------------------------------------------------

var (
	dataPath     = flag.String("data", "/tmp/ripple_sim_data.json", "input post/reply JSON (from ripple_simulator_extract.php)")
	cachePath    = flag.String("cache", "/tmp/ripple_sim_cache.json", "routing-eval cache JSON (read-write)")
	routingURL   = flag.String("routing", "http://localhost:8194", "routing server base URL (internal/no-auth)")
	ticks        = flag.Int("ticks", 30, "number of cron ticks across the post lifetime")
	lifetimeDays = flag.Float64("post-lifetime-days", 30, "post lifetime (days)")
	maxMinutes   = flag.Float64("max-minutes", 30, "max drive-time isochrone (minutes)")
	mode         = flag.String("mode", "drive", "travel mode: walk / cycle / drive")
	workers      = flag.Int("workers", 8, "concurrent eval workers")
	rebuildCache = flag.Bool("rebuild-cache", false, "ignore existing cache and re-fetch everything")
	limit        = flag.Int("limit", 0, "max posts to evaluate (0 = all)")
	groupBy      = flag.String("group-by", "", "stratify results by post attribute: \"\"=all, \"ru\"=RU category, \"ru-coarse\"=Urban/Rural/Other")
	jsonOutput   = flag.String("json-output", "", "if set, write structured results JSON here (for Layer-3 monitoring)")
	filterEmail  = flag.String("filter-email-freq", "", "only count repliers with this email-frequency setting: 'immediate' (=-1), 'digest' (>0), or '' for no filter")
	maxReach     = flag.Int("max-reach", 0, "population cap on the reach: notify at most the closest N located freeglers per post (0 = uncapped, i.e. the full max-minutes isochrone). Models a density-adaptive reach — dense areas stop early, sparse areas fall back to the time cap.")
)

// effectiveReach returns the number of freeglers actually in the notification
// pool for a post, given the total reachable within the max-minutes isochrone
// and an optional population cap.  A cap of 0 means uncapped.
//
// This is the whole population-cap model: instead of notifying everyone a car
// could reach in 30 minutes (≈19k people in central London, ≈240 in rural
// Wales), we notify only the closest-by-drive-time N.  Because the eval cache
// already stores each replier's drive-time rank, the cap is pure
// post-processing — one cache serves every candidate N.
func effectiveReach(total, cap int) int {
	if cap > 0 && total > cap {
		return cap
	}
	return total
}

// ruCoarse maps a fine-grained ONS RU category (A1, B1, ...) to one of three
// coarse buckets so the simulator output is readable when there are too few
// pairs per fine category.
func ruCoarse(cat *string) string {
	if cat == nil || *cat == "" {
		return "?"
	}
	c := *cat
	if len(c) == 0 {
		return "?"
	}
	switch c[0] {
	case 'A', 'B', 'C':
		return "urban"
	case 'D', 'E', 'F':
		return "rural"
	}
	return "?"
}

func main() {
	flag.Parse()
	log.SetFlags(log.Ltime)

	// ----- load extracted data -----
	posts, err := loadExtractedData(*dataPath)
	if err != nil {
		log.Fatalf("loading %s: %v", *dataPath, err)
	}
	if *limit > 0 && *limit < len(posts) {
		posts = posts[:*limit]
	}
	log.Printf("loaded %d posts from %s", len(posts), *dataPath)

	// ----- load or rebuild cache -----
	cache, err := loadCache(*cachePath)
	if err != nil && !os.IsNotExist(err) {
		log.Fatalf("loading cache %s: %v", *cachePath, err)
	}
	if *rebuildCache {
		log.Printf("--rebuild-cache: discarding existing cache")
		cache = nil
	}

	cache = ensureCache(posts, cache)
	missing := findMissing(posts, cache)
	if len(missing) > 0 {
		log.Printf("calling routing server for %d uncached posts (workers=%d)...", len(missing), *workers)
		populateCache(posts, cache, missing, *workers)
		if err := saveCache(*cachePath, cache); err != nil {
			log.Fatalf("saving cache: %v", err)
		}
		log.Printf("cache written: %s", *cachePath)
	} else {
		log.Printf("cache covers all %d posts", len(posts))
	}

	// ----- run simulation against several curves -----
	// Step-curve: notify a fixed initial fraction `s` at tick 1, then linear
	// across the remaining ticks for the residual (1-s) fraction.  Mimics
	// "legacy bombardment + ripple tail".
	stepCurve := func(s float64) func(float64) float64 {
		return func(x float64) float64 {
			if x <= 0 {
				return 0
			}
			// Tick 1 lands at x = 1/N; everything ≤ 1/N is the initial burst.
			// For x > 1/N: y = s + (1-s) * (x - 1/N) / (1 - 1/N).
			oneTick := 1.0 / float64(*ticks)
			if x <= oneTick {
				return s * (x / oneTick) // smooth ramp to s within first tick
			}
			return s + (1-s)*(x-oneTick)/(1-oneTick)
		}
	}

	curves := []curve{
		{Name: "linear", F: func(x float64) float64 { return x }},
		{Name: "front-quad (1-(1-x)^2)", F: func(x float64) float64 { return 1 - math.Pow(1-x, 2) }},
		{Name: "front-cubic (1-(1-x)^3)", F: func(x float64) float64 { return 1 - math.Pow(1-x, 3) }},
		{Name: "front-sqrt (x^0.5)", F: func(x float64) float64 { return math.Sqrt(x) }},
		{Name: "front-heavy (x^0.3)", F: func(x float64) float64 { return math.Pow(x, 0.3) }},
		// Even more aggressive: x^0.2 puts 51 % in tick 1; x^0.15 puts 60 %.
		{Name: "front-x^0.2", F: func(x float64) float64 { return math.Pow(x, 0.2) }},
		{Name: "front-x^0.15", F: func(x float64) float64 { return math.Pow(x, 0.15) }},
		// Step curves: explicit initial-burst-then-linear.
		{Name: "step-50%+linear", F: stepCurve(0.50)},
		{Name: "step-70%+linear", F: stepCurve(0.70)},
		{Name: "back-quad (x^2)", F: func(x float64) float64 { return x * x }},
		{Name: "linear+stop@3replies", F: func(x float64) float64 { return x }, StopAtReplies: 3},
		{Name: "front-cubic+stop@3", F: func(x float64) float64 { return 1 - math.Pow(1-x, 3) }, StopAtReplies: 3},
	}

	reachLabel := "uncapped"
	if *maxReach > 0 {
		reachLabel = fmt.Sprintf("max-reach=%d", *maxReach)
	}
	log.Printf("simulating %d curves over %d posts (%s)...", len(curves), len(posts), reachLabel)
	results := simulate(posts, cache, curves)
	printResults(results)

	if *jsonOutput != "" {
		if err := writeJSONResults(*jsonOutput, posts, results); err != nil {
			log.Fatalf("write json-output: %v", err)
		}
		log.Printf("structured results written to %s", *jsonOutput)
	}
}

// ---------------------------------------------------------------------------
// Structured output for Layer-3 monitoring (Laravel cron consumes this).
// ---------------------------------------------------------------------------

type jsonGroupRow struct {
	Group                    string  `json:"group"`
	Curve                    string  `json:"curve"`
	PairsTotal               int     `json:"pairs_total"`
	PairsInTime              int     `json:"pairs_in_time"`
	PairsLate                int     `json:"pairs_late"`
	PairsUnreachable         int     `json:"pairs_unreachable"`
	NotifyVol                int     `json:"notify_vol"`
	InTimePct                float64 `json:"in_time_pct"`
	FirstRepliersTotal       int     `json:"first_repliers_total"`
	FirstRepliersInTime      int     `json:"first_repliers_in_time"`
	FirstRepliersLate        int     `json:"first_repliers_late"`
	FirstRepliersUnreachable int     `json:"first_repliers_unreachable"`
	FirstRepliersInTimePct   float64 `json:"first_repliers_in_time_pct"`
	TickCaught               []int   `json:"tick_caught"` // histogram by tick index (0-based)
}

type jsonSummary struct {
	Ticks              int            `json:"ticks"`
	LifetimeDays       float64        `json:"lifetime_days"`
	MaxMinutes         float64        `json:"max_minutes"`
	MaxReach           int            `json:"max_reach"`
	Mode               string         `json:"mode"`
	PostsEvaluated     int            `json:"posts_evaluated"`
	ReplyP50Hours      float64        `json:"reply_p50_hours"`
	ReplyP75Hours      float64        `json:"reply_p75_hours"`
	ReplierRankP50     float64        `json:"replier_rank_p50"`
	ReplierDriveMinP75 float64        `json:"replier_drive_min_p75"`
	Rows               []jsonGroupRow `json:"rows"`
}

func writeJSONResults(path string, posts []extractedPost, groups map[string][]curveResult) error {
	out := jsonSummary{
		Ticks:          *ticks,
		LifetimeDays:   *lifetimeDays,
		MaxMinutes:     *maxMinutes,
		MaxReach:       *maxReach,
		Mode:           *mode,
		PostsEvaluated: len(posts),
		Rows:           make([]jsonGroupRow, 0, 32),
	}
	// Reply-time / rank / drive-min summary stats from the data itself.
	// Independent of any curve choice — they describe the underlying sample.
	{
		var replyHours, ranks, dmin []float64
		// Need the cache to translate rank/drive-min, but it's not in scope here.
		// We pass the cache via the global byID built in simulate(); instead just
		// compute reply-times from the data (always available) and leave the rank/
		// drive-min stats to be filled out below by a quick re-read of the cache.
		_ = ranks
		_ = dmin
		for _, p := range posts {
			arrival, err := time.Parse("2006-01-02 15:04:05", p.Arrival)
			if err != nil {
				continue
			}
			for _, r := range p.Repliers {
				rt, err := time.Parse("2006-01-02 15:04:05", r.ReplyTime)
				if err != nil {
					continue
				}
				replyHours = append(replyHours, rt.Sub(arrival).Hours())
			}
		}
		out.ReplyP50Hours = percentile(replyHours, 0.50)
		out.ReplyP75Hours = percentile(replyHours, 0.75)
	}
	// Pull rank/drive-min summary from the cache file directly.
	if cache, err := loadCache(*cachePath); err == nil {
		var ranks, dmin []float64
		for _, cp := range cache.Posts {
			if cp.TotalReachable == 0 {
				continue
			}
			for _, ep := range cp.Repliers {
				if ep.Rank != nil {
					ranks = append(ranks, float64(*ep.Rank)/float64(cp.TotalReachable))
				}
				if ep.DriveMin != nil {
					dmin = append(dmin, *ep.DriveMin)
				}
			}
		}
		out.ReplierRankP50 = percentile(ranks, 0.50)
		out.ReplierDriveMinP75 = percentile(dmin, 0.75)
	}
	keys := make([]string, 0, len(groups))
	for k := range groups {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		for _, r := range groups[k] {
			pct := 0.0
			if r.PairsTotal > 0 {
				pct = 100.0 * float64(r.PairsReachedInTime) / float64(r.PairsTotal)
			}
			firstPct := 0.0
			if r.FirstRepliersTotal > 0 {
				firstPct = 100.0 * float64(r.FirstRepliersInTime) / float64(r.FirstRepliersTotal)
			}
			out.Rows = append(out.Rows, jsonGroupRow{
				Group:                    k,
				Curve:                    r.Name,
				PairsTotal:               r.PairsTotal,
				PairsInTime:              r.PairsReachedInTime,
				PairsLate:                r.PairsReachableButLate,
				PairsUnreachable:         r.PairsUnreachable,
				NotifyVol:                r.NotificationsSent,
				InTimePct:                pct,
				FirstRepliersTotal:       r.FirstRepliersTotal,
				FirstRepliersInTime:      r.FirstRepliersInTime,
				FirstRepliersLate:        r.FirstRepliersLate,
				FirstRepliersUnreachable: r.FirstRepliersUnreachable,
				FirstRepliersInTimePct:   firstPct,
				TickCaught:               r.TickCaught,
			})
		}
	}
	b, err := json.MarshalIndent(out, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, b, 0o644)
}

func percentile(xs []float64, p float64) float64 {
	if len(xs) == 0 {
		return 0
	}
	sorted := make([]float64, len(xs))
	copy(sorted, xs)
	sort.Float64s(sorted)
	idx := int(p * float64(len(sorted)-1))
	if idx < 0 {
		idx = 0
	}
	if idx >= len(sorted) {
		idx = len(sorted) - 1
	}
	return sorted[idx]
}

// ---------------------------------------------------------------------------
// IO helpers.
// ---------------------------------------------------------------------------

func loadExtractedData(path string) ([]extractedPost, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var out []extractedPost
	if err := json.Unmarshal(b, &out); err != nil {
		return nil, err
	}
	return out, nil
}

func loadCache(path string) (*cacheFile, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var c cacheFile
	if err := json.Unmarshal(b, &c); err != nil {
		return nil, err
	}
	return &c, nil
}

func saveCache(path string, cache *cacheFile) error {
	cache.GeneratedAt = time.Now().Format(time.RFC3339)
	tmp := path + ".tmp"
	b, err := json.MarshalIndent(cache, "", "  ")
	if err != nil {
		return err
	}
	if err := os.WriteFile(tmp, b, 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func ensureCache(posts []extractedPost, c *cacheFile) *cacheFile {
	if c == nil {
		c = &cacheFile{}
	}
	known := make(map[int]int, len(c.Posts))
	for i, p := range c.Posts {
		known[p.PostID] = i
	}
	// Drop entries no longer referenced (extracted file may have shrunk).
	wanted := make(map[int]bool, len(posts))
	for _, p := range posts {
		wanted[p.PostID] = true
	}
	out := c.Posts[:0]
	for _, cp := range c.Posts {
		if wanted[cp.PostID] {
			out = append(out, cp)
		}
	}
	c.Posts = out
	_ = known
	return c
}

func findMissing(posts []extractedPost, c *cacheFile) []int {
	have := make(map[int]bool, len(c.Posts))
	for _, p := range c.Posts {
		have[p.PostID] = true
	}
	var missing []int
	for i, p := range posts {
		if !have[p.PostID] {
			missing = append(missing, i)
		}
	}
	return missing
}

// ---------------------------------------------------------------------------
// Routing-server calls (slow path).
// ---------------------------------------------------------------------------

type evalRequest struct {
	Lat        float64      `json:"lat"`
	Lng        float64      `json:"lng"`
	Mode       string       `json:"mode"`
	MaxMinutes float64      `json:"max_minutes"`
	Points     [][2]float64 `json:"points"`
}

func populateCache(posts []extractedPost, cache *cacheFile, missingIdx []int, workers int) {
	if workers < 1 {
		workers = 1
	}
	jobs := make(chan int, len(missingIdx))
	for _, i := range missingIdx {
		jobs <- i
	}
	close(jobs)

	results := make([]cachedPost, len(missingIdx))
	resultIdx := make(map[int]int, len(missingIdx))
	for k, i := range missingIdx {
		resultIdx[i] = k
	}

	var done int64
	var wg sync.WaitGroup
	wg.Add(workers)
	for w := 0; w < workers; w++ {
		go func() {
			defer wg.Done()
			client := &http.Client{Timeout: 60 * time.Second}
			for i := range jobs {
				p := posts[i]
				cp, err := evalPost(client, p)
				if err != nil {
					log.Printf("post %d: %v", p.PostID, err)
					cp = cachedPost{PostID: p.PostID}
				}
				results[resultIdx[i]] = cp
				n := atomic.AddInt64(&done, 1)
				if n%100 == 0 || int(n) == len(missingIdx) {
					log.Printf("  ...%d / %d", n, len(missingIdx))
				}
			}
		}()
	}
	wg.Wait()

	cache.Posts = append(cache.Posts, results...)
	// Stable order by post_id for deterministic output.
	sort.Slice(cache.Posts, func(i, j int) bool { return cache.Posts[i].PostID < cache.Posts[j].PostID })
}

func evalPost(client *http.Client, p extractedPost) (cachedPost, error) {
	points := make([][2]float64, len(p.Repliers))
	for i, r := range p.Repliers {
		points[i] = [2]float64{r.Lng, r.Lat}
	}
	body := evalRequest{
		Lat:        p.Lat,
		Lng:        p.Lng,
		Mode:       *mode,
		MaxMinutes: *maxMinutes,
		Points:     points,
	}
	buf, _ := json.Marshal(body)
	resp, err := client.Post(*routingURL+"/v1/ripple-eval", "application/json", bytes.NewReader(buf))
	if err != nil {
		return cachedPost{}, err
	}
	defer resp.Body.Close()
	rb, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		return cachedPost{}, fmt.Errorf("HTTP %d: %s", resp.StatusCode, string(rb))
	}
	var er evalResponse
	if err := json.Unmarshal(rb, &er); err != nil {
		return cachedPost{}, err
	}
	return cachedPost{
		PostID:         p.PostID,
		TotalReachable: er.TotalReachable,
		Repliers:       er.Results,
	}, nil
}

// ---------------------------------------------------------------------------
// Simulation (fast path).
// ---------------------------------------------------------------------------

type curve struct {
	Name          string
	F             func(x float64) float64 // x ∈ [0,1] → cumulative-notified ∈ [0,1]
	StopAtReplies int                     // 0 = no stop; otherwise stop the schedule after this many replies
}

type curveResult struct {
	Name                  string
	PairsTotal            int
	PairsReachedInTime    int
	PairsReachableButLate int // we'd have notified them eventually but after they replied
	PairsUnreachable      int // never reached by max isochrone
	NotificationsSent     int // total notifications across simulated runs

	// First-replier breakdown (the replier whose reply_time was earliest).
	// Arguably this is what matters most: catching the FIRST person who'd
	// reply, since they're the most likely to actually claim the item.
	FirstRepliersTotal       int
	FirstRepliersInTime      int
	FirstRepliersLate        int
	FirstRepliersUnreachable int

	// Per-tick "caught" histogram: how many in-time pairs were notified
	// at each tick.  TickCaught[0] = caught at tick 1 (immediate batch),
	// TickCaught[1] = caught at tick 2, etc.  Sums to PairsReachedInTime.
	TickCaught []int

	// Wasted notifications: emails sent at ticks that fired AFTER the post
	// was already marked Taken/Promised/Withdrawn.  Counts the additional
	// users notified at those late ticks; these emails go to people whose
	// reply would no longer be useful.  A more aggressive front-load
	// trades a bigger initial burst for fewer wasted later notifications.
	NotificationsWasted int

	// Lead-time tracking: how far AHEAD of the actual reply did we notify
	// the eventual replier?  Smaller lead time = more "just in time".
	// Larger lead time = "notified too early".
	//
	// Two separate distributions: across ALL in-time catches, and across
	// FIRST-REPLIER in-time catches only.  The latter is the production-
	// relevant figure (the first replier is who ends up with the item).
	// Units: hours.
	LeadHoursSum    float64
	LeadHoursCount  int
	LeadHoursSample []float64

	FirstLeadHoursSum    float64
	FirstLeadHoursCount  int
	FirstLeadHoursSample []float64
}

const maxLeadSamples = 50000

// groupKey returns the bucket label for a post under the current --group-by
// setting.  Empty string = all-in-one (no stratification).
func groupKey(p extractedPost) string {
	switch *groupBy {
	case "ru":
		if p.RUCategory != nil && *p.RUCategory != "" {
			return *p.RUCategory
		}
		return "?"
	case "ru-coarse":
		return ruCoarse(p.RUCategory)
	case "type":
		if p.Type == "" {
			return "?"
		}
		return p.Type
	default:
		return "all"
	}
}

// simulate returns results keyed by group label (or {"all": [...]} if not grouping).
func simulate(posts []extractedPost, cache *cacheFile, curves []curve) map[string][]curveResult {
	byID := make(map[int]*cachedPost, len(cache.Posts))
	for i := range cache.Posts {
		byID[cache.Posts[i].PostID] = &cache.Posts[i]
	}
	groups := map[string][]curveResult{}
	getGroup := func(k string) []curveResult {
		r, ok := groups[k]
		if !ok {
			r = make([]curveResult, len(curves))
			for ci, c := range curves {
				r[ci].Name = c.Name
			}
			groups[k] = r
		}
		return r
	}
	// Only pre-seed the "all" bucket when not stratifying — otherwise it
	// pollutes the output with an empty group.
	var results []curveResult
	if *groupBy == "" {
		results = getGroup("all")
	}

	lifetimeSec := *lifetimeDays * 24 * 3600
	// Tick 1 fires at t=0 (post arrival), tick N fires at t=lifetime.
	// So the N ticks span [0, lifetime] inclusive: tick K fires at
	// t = (K-1) * lifetime / (N-1).  Without this, tick 1 was delayed
	// by a full tickSec and we missed every replier inside that initial
	// window — historically the bulk of them respond within 24 hours.
	var tickSec float64
	if *ticks > 1 {
		tickSec = lifetimeSec / float64(*ticks-1)
	}

	for _, p := range posts {
		cp, ok := byID[p.PostID]
		if !ok || cp.TotalReachable == 0 {
			continue
		}
		arrival, err := time.Parse("2006-01-02 15:04:05", p.Arrival)
		if err != nil {
			continue
		}

		// Pick the group bucket for this post.  When not grouping, everything
		// goes into "all".  Either way `results` points at the slice we mutate.
		var key string
		if *groupBy == "" {
			key = "all"
		} else {
			key = groupKey(p)
		}
		results = getGroup(key)

		// Population-capped reach: the schedule notifies at most the closest
		// `--max-reach` freeglers (0 = uncapped).  Repliers whose drive-time
		// rank is beyond the cap are never notified under this run, modelling
		// a density-adaptive reach that contracts in dense areas.
		effectiveTotal := effectiveReach(cp.TotalReachable, *maxReach)

		for ci, c := range curves {
			// Pre-compute the cumulative-rank schedule for this post.
			// schedule[k] (0-based) = cumulative rank notified by end of tick (k+1).
			cumRank := make([]int, *ticks)
			for k := 1; k <= *ticks; k++ {
				x := float64(k) / float64(*ticks)
				cumRank[k-1] = int(math.Round(c.F(x) * float64(effectiveTotal)))
			}

			replyOrder := make([]int, len(p.Repliers))
			for i := range p.Repliers {
				replyOrder[i] = i
			}
			sort.SliceStable(replyOrder, func(a, b int) bool {
				ta, _ := time.Parse("2006-01-02 15:04:05", p.Repliers[replyOrder[a]].ReplyTime)
				tb, _ := time.Parse("2006-01-02 15:04:05", p.Repliers[replyOrder[b]].ReplyTime)
				return ta.Before(tb)
			})

			// --- Compute the schedule's effective "stop tick" for this post.
			// If stop@N is in force, the schedule stops at the tick during
			// which the Nth reply arrives.  Find that tick honestly from the
			// reply timestamps so the volume count and in-time check use the
			// same stop point.
			stopTick := *ticks
			if c.StopAtReplies > 0 && len(replyOrder) >= c.StopAtReplies {
				nthReplyIdx := replyOrder[c.StopAtReplies-1]
				nthReplyTime, err := time.Parse("2006-01-02 15:04:05", p.Repliers[nthReplyIdx].ReplyTime)
				if err == nil {
					nthReplyElapsedSec := nthReplyTime.Sub(arrival).Seconds()
					// Number of ticks that had fired by then (tick K fires at
					// (K-1) * tickSec; we want all ticks where (K-1)*tickSec <= elapsed).
					if tickSec > 0 {
						firedByThen := int(nthReplyElapsedSec/tickSec) + 1
						if firedByThen < stopTick {
							stopTick = firedByThen
						}
					}
				}
			}

			// Lazy-init the per-tick histogram once we know the tick count.
			if results[ci].TickCaught == nil {
				results[ci].TickCaught = make([]int, *ticks)
			}

			for replyIdx, idx := range replyOrder {
				isFirstReplier := replyIdx == 0
				// Apply --filter-email-freq if set.  Skip repliers whose
				// emailfrequency setting doesn't match.
				replier := p.Repliers[idx]
				if *filterEmail != "" {
					if replier.EmailFreq == nil {
						continue
					}
					switch *filterEmail {
					case "immediate":
						if *replier.EmailFreq != -1 {
							continue
						}
					case "digest":
						if *replier.EmailFreq <= 0 {
							continue
						}
					}
				}
				results[ci].PairsTotal++
				if isFirstReplier {
					results[ci].FirstRepliersTotal++
				}
				ep := cp.Repliers[idx]
				if ep.Rank == nil {
					results[ci].PairsUnreachable++
					if isFirstReplier {
						results[ci].FirstRepliersUnreachable++
					}
					continue
				}
				rank := *ep.Rank
				// Capped out: this replier is inside the max-minutes isochrone
				// but beyond the population cap, so the schedule never reaches
				// them.  Counts against in-time (they'd have replied but we
				// chose not to notify them) — exactly the trade-off the sweep
				// measures: smaller cap = lower volume, but some real repliers
				// fall outside the reach.
				if rank > effectiveTotal {
					results[ci].PairsReachableButLate++
					if isFirstReplier {
						results[ci].FirstRepliersLate++
					}
					continue
				}
				replyTime, err := time.Parse("2006-01-02 15:04:05", p.Repliers[idx].ReplyTime)
				if err != nil {
					continue
				}

				// Find smallest tick k ∈ [1, stopTick] s.t. cumRank[k-1] >= rank.
				notifyTick := -1
				for k := 1; k <= stopTick; k++ {
					if cumRank[k-1] >= rank {
						notifyTick = k
						break
					}
				}
				if notifyTick == -1 {
					results[ci].PairsReachableButLate++
					if isFirstReplier {
						results[ci].FirstRepliersLate++
					}
					continue
				}
				wallSec := float64(notifyTick-1) * tickSec
				notifyTime := arrival.Add(time.Duration(wallSec * float64(time.Second)))
				if !notifyTime.After(replyTime) {
					results[ci].PairsReachedInTime++
					results[ci].TickCaught[notifyTick-1]++
					leadHrs := replyTime.Sub(notifyTime).Hours()
					// Track lead-time across all in-time catches AND
					// separately across first-replier in-time catches.
					results[ci].LeadHoursSum += leadHrs
					results[ci].LeadHoursCount++
					if len(results[ci].LeadHoursSample) < maxLeadSamples {
						results[ci].LeadHoursSample = append(results[ci].LeadHoursSample, leadHrs)
					}
					if isFirstReplier {
						results[ci].FirstRepliersInTime++
						results[ci].FirstLeadHoursSum += leadHrs
						results[ci].FirstLeadHoursCount++
						if len(results[ci].FirstLeadHoursSample) < maxLeadSamples {
							results[ci].FirstLeadHoursSample = append(results[ci].FirstLeadHoursSample, leadHrs)
						}
					}
				} else {
					results[ci].PairsReachableButLate++
					if isFirstReplier {
						results[ci].FirstRepliersLate++
					}
				}
			}

			// Notifications sent under this curve = cumRank at the stop tick.
			// Accurately reflects circuit-breaker savings for stop@N variants.
			if stopTick > 0 {
				results[ci].NotificationsSent += cumRank[stopTick-1]
			}

			// Wasted notifications: count emails sent at ticks that fired
			// AFTER the post's recorded outcome time (Taken/Promised/etc).
			// If the post never had an outcome we conservatively assume no
			// waste — the schedule's notifications might still have been
			// useful within the lifetime.
			if p.OutcomeAt != nil && *p.OutcomeAt != "" {
				outcomeT, err := time.Parse("2006-01-02 15:04:05", *p.OutcomeAt)
				if err == nil && outcomeT.After(arrival) {
					outcomeElapsed := outcomeT.Sub(arrival).Seconds()
					if tickSec > 0 {
						// Index of the tick during which the outcome happened.
						// Notifications at ticks AFTER this are wasted.
						firstWastedTick := int(outcomeElapsed/tickSec) + 2 // tick numbers are 1-based
						if firstWastedTick <= stopTick {
							// Volume sent at ticks [firstWastedTick, stopTick]
							// = cumRank[stopTick-1] - cumRank[firstWastedTick-2]
							endCum := cumRank[stopTick-1]
							priorCum := 0
							if firstWastedTick >= 2 {
								priorCum = cumRank[firstWastedTick-2]
							}
							if endCum > priorCum {
								results[ci].NotificationsWasted += endCum - priorCum
							}
						}
					}
				}
			}
		}
		// Write back updated results for this group (slices-of-struct in a
		// map need re-assignment after mutation if we didn't keep a pointer).
		groups[key] = results
	}

	return groups
}

func printResults(groups map[string][]curveResult) {
	// Sort group keys for stable output.
	keys := make([]string, 0, len(groups))
	for k := range groups {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		results := groups[k]
		fmt.Println()
		if k == "all" {
			fmt.Println("Simulation results")
			fmt.Println("==================")
		} else {
			fmt.Printf("Simulation results — group %q\n", k)
			fmt.Println("=======================================")
		}
		fmt.Printf("%-30s  %10s  %10s  %10s  %10s  %12s\n",
			"curve", "pairs", "in-time", "late", "unreached", "notify-vol")
		printResultsRows(results)
	}
}

func printResultsRows(results []curveResult) {
	// First-replier metrics are the focus: the first replier is who
	// actually gets the item, so catching them in time is what really
	// matters.  Lead times here are restricted to first-replier catches.
	fmt.Printf("%-30s  %8s  %7s  %7s  %7s  %7s\n",
		"", "1st-in%", "waste%", "1st-p50", "1st-p75", "1st-p90")
	for _, r := range results {
		firstPct := 0.0
		if r.FirstRepliersTotal > 0 {
			firstPct = 100.0 * float64(r.FirstRepliersInTime) / float64(r.FirstRepliersTotal)
		}
		wastedPct := 0.0
		if r.NotificationsSent > 0 {
			wastedPct = 100.0 * float64(r.NotificationsWasted) / float64(r.NotificationsSent)
		}
		p50 := percentile(r.FirstLeadHoursSample, 0.50)
		p75 := percentile(r.FirstLeadHoursSample, 0.75)
		p90 := percentile(r.FirstLeadHoursSample, 0.90)
		fmt.Printf("%-30s  %7.1f%%  %6.1f%%  %6.1fh  %6.1fh  %6.1fh\n",
			r.Name, firstPct, wastedPct, p50, p75, p90)
	}
}
