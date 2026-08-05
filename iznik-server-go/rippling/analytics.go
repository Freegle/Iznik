package rippling

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"os"
	"sort"
	"strconv"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// Package-level analytics endpoint for the sysadmin rippling tab. Everything here is computed
// ON THE FLY (no overnight batch): pure-SQL KPIs over the full set, and drive-time metrics from
// a random SAMPLE of posts scored against the live routing graph. Read-only.

// routingEvalURL is the base URL of the ROUTING server (iznik-routing-go), which
// serves POST /v1/ripple-eval on its internal no-auth listener. This is NOT the
// KNN spatial server, and NOT the routing server's external JWT-gated port.
//
//	ROUTING_EVAL_URL — explicit override. Prod sets http://localhost:8197 (routing
//	                   SPATIAL_INTERNAL_PORT); apiv2-live points it at the routing tunnel.
//	default          — local Docker: the routing container's internal no-auth port.
//
// It previously fell back to SPATIAL_KNN_URL, which addresses the KNN service — a
// different server with no /v1/ripple-eval. Wherever ROUTING_EVAL_URL was unset
// (prod apiv2 and the regular local apiv2 both set SPATIAL_KNN_URL but not
// ROUTING_EVAL_URL) every ripple-eval hit KNN, 404'd, and zeroed the drive-time
// analytics ("No drive-time sample", all-grey bullseye). SPATIAL_SERVER_URL is
// deliberately NOT a fallback either: it points at the routing server's AUTH port
// (8196), which 401s an unauthenticated ripple-eval call.
func routingEvalURL() string {
	if u := os.Getenv("ROUTING_EVAL_URL"); u != "" {
		return u
	}
	return "http://spatial:8194"
}

// Isochrones can take a couple of seconds each on the UK graph, so this client is far more
// patient than the 5s KNN client.
var routingClient = &http.Client{Timeout: 25 * time.Second}

// driveMaxMinutes caps the isochrone. 45 is far cheaper to compute than 60 and covers virtually
// all real reply travel; anything beyond reads as "unreachable" and is excluded from the mean.
const driveMaxMinutes = 45

// Fixed accrual horizons for the settling-clock KPIs, measured from reach creation
// (rippling_reach.created_at - a clock that never moves, unlike messages_groups.arrival, which
// autorepost bumps forward). Replies settle fast, so the long-standing 36h convention fits the
// reply-rate and mean-replies figures. TAKEN is recorded long after the event (offerers mark
// outcomes a mean of ~19 days after the post enters rippling on prod), so a short window would
// read near zero: the taken series uses a 14-day horizon. Without fixed horizons the trend
// series counted replies/takes EVER, so recent days - which simply hadn't finished accruing -
// always drew as a steep mechanical decline.
const (
	ReplyHorizonHours = 36
	TakenHorizonDays  = 14
)

// trendMature reports whether every post on a trend day (YYYY-MM-DD) has had the full horizon
// elapse by now, i.e. the day's fixed-horizon figure can no longer grow. Immature days are still
// returned (flagged false) so the UI can draw them as provisional rather than as a decline.
func trendMature(day string, horizon time.Duration, now time.Time) bool {
	t, err := time.Parse("2006-01-02", day)
	if err != nil {
		return false
	}
	return !now.Before(t.AddDate(0, 0, 1).Add(horizon))
}

// envInt reads a positive int env var, falling back to def. Lets the drive-time sample size and
// concurrency be tuned per-environment WITHOUT a rebuild - prod's routing is on the local network
// (fast), while apiv2-live reaches it over the SSH tunnel (slow), so they want different values.
func envInt(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			return n
		}
	}
	return def
}

// driveSampleSize: per-view post sample for the drive-time metrics. ~250 posts (~500 replies)
// pins the mean to ±~1 min. RIPPLE_ANALYTICS_SAMPLE overrides.
func driveSampleSize() int { return envInt("RIPPLE_ANALYTICS_SAMPLE", 250) }

// driveConcurrency: simultaneous ripple-eval calls. The pass is RTT-bound, so higher concurrency
// shrinks wall-time roughly linearly. RIPPLE_ANALYTICS_CONCURRENCY overrides.
func driveConcurrency() int { return envInt("RIPPLE_ANALYTICS_CONCURRENCY", 16) }

// StratumFilter renders the rippling_reach.total_freeglers SQL predicate for a density stratum.
// Terciles from live data (active freeglers reached, ~6-month window): rural <1700, suburban
// 1700-3800, dense >3800. "all" (or unknown) imposes no extra bound beyond total_freeglers>0.
// Used where rippling_reach is already joined as `rr`.
func StratumFilter(stratum string) string {
	switch stratum {
	case "rural":
		return " AND rr.total_freeglers < 1700"
	case "suburban":
		return " AND rr.total_freeglers >= 1700 AND rr.total_freeglers < 3800"
	case "dense":
		return " AND rr.total_freeglers >= 3800"
	default:
		return ""
	}
}

type rippleEvalReq struct {
	Lat        float64      `json:"lat"`
	Lng        float64      `json:"lng"`
	Mode       string       `json:"mode"`
	MaxMinutes float64      `json:"max_minutes"`
	Points     [][2]float64 `json:"points"`
}
type rippleEvalResp struct {
	Results []struct {
		DriveMin *float64 `json:"drive_min"`
	} `json:"results"`
}

// samplePost is one post in the drive-time sample: origin + its repliers' points, each tagged
// rippled-out (server-derived, so section 3 can restrict the mean), with its reply day (so a
// drive-time trend falls out of the same routing pass for free) and whether that replier became
// the taker (so the reliability bullseye - reply->take conversion by drive-time ring - falls out too).
type samplePost struct {
	lat, lng float64
	points   [][2]float64
	rippled  []bool
	days     []string
	takers   []bool
}

// driveObs is one scored reply from the sample: its drive-time, whether it was rippled-out, its
// reply day, and whether the replier became the taker. One routing pass produces these; the
// overall/rippled means, the per-day trend and the bullseye are computed in Go without re-calling.
type driveObs struct {
	min     float64
	rippled bool
	day     string
	taker   bool
}

// DriveStat is the result of a sampled drive-time computation: the mean travel minutes with a
// 95% CI half-width and the sample size, so the UI can show adequacy.
type DriveStat struct {
	MeanMin   float64 `json:"mean_min"`
	CIHalf    float64 `json:"ci_half_min"`
	NReplies  int     `json:"n_replies"`
	Available bool    `json:"available"`
}

// scoreOnePost runs ONE ripple-eval for a single sampled post and returns its scored replies.
// Serial and best-effort: a routing/parse failure yields no observations for that post. The whole
// sample is scored one post at a time by the client calling /drivetime/score repeatedly, so a
// single request never runs long enough to hit the gateway timeout (the 504 this replaces).
func scoreOnePost(p samplePost) []driveObs {
	body, _ := json.Marshal(rippleEvalReq{
		Lat: p.lat, Lng: p.lng, Mode: "drive", MaxMinutes: driveMaxMinutes, Points: p.points,
	})
	resp, err := routingClient.Post(routingEvalURL()+"/v1/ripple-eval",
		"application/json", bytes.NewReader(body))
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil
	}
	var r rippleEvalResp
	if json.NewDecoder(resp.Body).Decode(&r) != nil || len(r.Results) != len(p.points) {
		return nil
	}
	out := []driveObs{}
	for j, res := range r.Results {
		if res.DriveMin == nil {
			continue
		}
		o := driveObs{min: *res.DriveMin}
		if j < len(p.rippled) {
			o.rippled = p.rippled[j]
		}
		if j < len(p.days) {
			o.day = p.days[j]
		}
		if j < len(p.takers) {
			o.taker = p.takers[j]
		}
		out = append(out, o)
	}
	return out
}

// statFromObs is the mean drive-time (+95% CI) over the observations, optionally restricted to
// rippled-out replies.
func statFromObs(obs []driveObs, rippledOnly bool) DriveStat {
	vals := []float64{}
	for _, o := range obs {
		if rippledOnly && !o.rippled {
			continue
		}
		vals = append(vals, o.min)
	}
	stat := DriveStat{NReplies: len(vals)}
	if len(vals) == 0 {
		return stat
	}
	stat.Available = true
	sum := 0.0
	for _, v := range vals {
		sum += v
	}
	stat.MeanMin = sum / float64(len(vals))
	if len(vals) > 1 {
		varsum := 0.0
		for _, v := range vals {
			varsum += (v - stat.MeanMin) * (v - stat.MeanMin)
		}
		sd := math.Sqrt(varsum / float64(len(vals)-1))
		stat.CIHalf = 1.96 * sd / math.Sqrt(float64(len(vals)))
	}
	return stat
}

// DriveTrendPoint is one day's mean sampled drive-time (small per-day n; a rough guide).
type DriveTrendPoint struct {
	Day     string  `json:"day"`
	MeanMin float64 `json:"mean_min"`
	N       int     `json:"n"`
}

// driveTrendFromObs buckets the sampled drive-times by reply day (ascending). Per-day n is small
// (the window sample spread over days), so this is a rough trend, flagged as sample-based.
func driveTrendFromObs(obs []driveObs) []DriveTrendPoint {
	sums := map[string]float64{}
	counts := map[string]int{}
	days := []string{}
	for _, o := range obs {
		if o.day == "" {
			continue
		}
		if counts[o.day] == 0 {
			days = append(days, o.day)
		}
		sums[o.day] += o.min
		counts[o.day]++
	}
	sort.Strings(days)
	out := make([]DriveTrendPoint, 0, len(days))
	for _, d := range days {
		out = append(out, DriveTrendPoint{Day: d, MeanMin: sums[d] / float64(counts[d]), N: counts[d]})
	}
	return out
}

// BullseyeBand is one drive-time ring of the reliability bullseye: the reply->take conversion for
// replies whose drive-time from the post falls in [MinLo, MinHi) minutes. NReplies/NTakers carry
// the n and CIHalf the 95% half-width, so a sparse outer ring reads honestly rather than as noise.
type BullseyeBand struct {
	MinLo    int     `json:"min_lo"`
	MinHi    int     `json:"min_hi"`
	NReplies int     `json:"n_replies"`
	NTakers  int     `json:"n_takers"`
	ConvPct  float64 `json:"conv_pct"`
	CIHalf   float64 `json:"ci_half_pct"`
}

// bullseyeEdges are the ring boundaries in minutes. Coarser than the offline reach-tuning analysis
// (which merged only 5-min bands over a huge batch): the innermost 0-10 is one ring because
// sub-5-min-drive replies are rare, so a 0-5 ring would always be too sparse to read on a live
// sample. 5-min resolution is kept through the populated 10-30 band; the 30-45 outer ring is
// inclusive of driveMaxMinutes (the isochrone cap) so no scored reply is dropped. The 30 edge lines
// up with the reach edge drawn on the bullseye.
var bullseyeEdges = []int{0, 10, 15, 20, 25, 30, driveMaxMinutes}

// Bullseye buckets parallel (drive-time, taker?) observations into the fixed drive-time rings and
// computes reply->take conversion per ring with a 95% CI half-width (normal approximation). It is
// exported and input-typed (not driveObs) so the banding is unit-testable without a routing pass.
func Bullseye(mins []float64, takers []bool) []BullseyeBand {
	bands := make([]BullseyeBand, 0, len(bullseyeEdges)-1)
	for i := 0; i+1 < len(bullseyeEdges); i++ {
		lo, hi := bullseyeEdges[i], bullseyeEdges[i+1]
		last := i+2 == len(bullseyeEdges)
		b := BullseyeBand{MinLo: lo, MinHi: hi}
		for j, m := range mins {
			if m < float64(lo) {
				continue
			}
			if m > float64(hi) || (m == float64(hi) && !last) {
				continue
			}
			b.NReplies++
			if j < len(takers) && takers[j] {
				b.NTakers++
			}
		}
		if b.NReplies > 0 {
			p := float64(b.NTakers) / float64(b.NReplies)
			b.ConvPct = p * 100
			b.CIHalf = 1.96 * math.Sqrt(p*(1-p)/float64(b.NReplies)) * 100
		}
		bands = append(bands, b)
	}
	return bands
}

// bullseyeFromObs pulls the drive-time + taker flag off the sampled observations and bands them.
// Over ALL sampled replies (home + rippled) on rippled-out posts, so it reads as "how reliably does
// a reply at this drive-time complete" - the offerer's-eye reliability profile of the reach.
func bullseyeFromObs(obs []driveObs) []BullseyeBand {
	mins := make([]float64, len(obs))
	takers := make([]bool, len(obs))
	for i, o := range obs {
		mins[i] = o.min
		takers[i] = o.taker
	}
	return Bullseye(mins, takers)
}

// fetchDriveSample pulls a random sample of posts (with their replier points) for the window +
// stratum, tagging each reply rippled-out server-side. rippled-out = the replier reached the
// post via rippling: not an established member of an ORIGIN group before arrival, on a post that
// had a rippled_in copy by reply time. (The same durable signal the attribution ladder uses.)
func fetchDriveSample(db *gorm.DB, start, end, stratumSQL string, sampleN int) []samplePost {
	type row struct {
		Msgid   uint64
		Plat    float64
		Plng    float64
		Rlat    float64
		Rlng    float64
		Rippled int
		Taker   int
		Day     string
	}
	var rows []row
	// Sample post ids first (ORDER BY RAND over the windowed rippled set), then expand to
	// replier points. Bounded to posts that actually have an Interested reply.
	//
	// stratumSQL is
	// one of StratumFilter's 4 fixed strings (all/rural/suburban/dense) - 4
	// possible rendered forms, all declared in ormharness/shapes.json and
	// proven by TestTier3Shapes_f382f9bfe80b (iznik-server-go/test). Uses the
	// derived-table trick (GORM's Table() passes its name argument through
	// verbatim once it contains a space) already proven elsewhere in this
	// codebase for a parenthesized subquery.
	//
	// The "rippled" column calls EstablishedOriginMemberExists rather than
	// spelling the EXISTS out again: master moved that predicate into the
	// shared helper when it added the mem.rippled = 0 provenance test, and a
	// second copy here would drift the moment either is edited.
	innerSample := "(" +
		"SELECT rr.msgid " +
		"FROM rippling_reach rr " +
		"JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer' " +
		"WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0" + stratumSQL +
		" AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)" +
		" AND EXISTS(SELECT 1 FROM chat_messages c WHERE c.refmsgid = rr.msgid AND c.type = 'Interested')" +
		" ORDER BY RAND() LIMIT ?" +
		") samp"

	db.Table(innerSample, start, end, sampleN).
		Select("samp.msgid, ml.lat AS plat, ml.lng AS plng, ul.lat AS rlat, ul.lng AS rlng, "+
			"DATE_FORMAT(cm.date, '%Y-%m-%d') AS day, "+
			"EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = samp.msgid AND mb.userid = cm.userid) AS taker, "+
			"(NOT "+EstablishedOriginMemberExists("samp.msgid", "cm.userid")+") AS rippled").
		Joins("JOIN messages m ON m.id = samp.msgid").
		Joins("JOIN locations ml ON ml.id = m.locationid AND ml.lat IS NOT NULL").
		Joins("JOIN chat_messages cm ON cm.refmsgid = samp.msgid AND cm.type = 'Interested' AND cm.date >= ? AND cm.date < ?", start, end).
		Joins("JOIN users u ON u.id = cm.userid").
		Joins("JOIN locations ul ON ul.id = u.lastlocation AND ul.lat IS NOT NULL").
		Order("samp.msgid").
		Scan(&rows)

	byPost := map[uint64]*samplePost{}
	order := []uint64{}
	for _, r := range rows {
		sp := byPost[r.Msgid]
		if sp == nil {
			sp = &samplePost{lat: r.Plat, lng: r.Plng}
			byPost[r.Msgid] = sp
			order = append(order, r.Msgid)
		}
		sp.points = append(sp.points, [2]float64{r.Rlng, r.Rlat})
		sp.rippled = append(sp.rippled, r.Rippled == 1)
		sp.days = append(sp.days, r.Day)
		sp.takers = append(sp.takers, r.Taker == 1)
	}
	out := make([]samplePost, 0, len(order))
	for _, id := range order {
		out = append(out, *byPost[id])
	}
	return out
}

// Section1KPI is the headline block for one stratum + window, over rippled-out offers.
// The headline reply rate is WITHIN 36H (the settling-window convention, comparable to the old
// dashboard); RepliedEverPct is the eventual total, shown smaller as context.
type Section1KPI struct {
	Stratum        string    `json:"stratum"`
	Posts          int       `json:"posts"`
	Replied36h     int       `json:"replied_36h"`
	Replied36hPct  float64   `json:"replied_36h_pct"`
	RepliedEver    int       `json:"replied_ever"`
	RepliedEverPct float64   `json:"replied_ever_pct"`
	Taken          int       `json:"taken"`
	TakenPct       float64   `json:"taken_pct"`
	MeanReplies    float64   `json:"mean_replies"`
	MeanFreeglers  float64   `json:"mean_freeglers_reached"`
	ReplyDrive     DriveStat `json:"reply_drive_min"`
	// Reply friction: share of replies that were HELD (an email/TN reply held because the post
	// had not yet rippled to the replier's location).
	HeldReplies    int     `json:"held_replies"`
	HeldRepliesPct float64 `json:"held_replies_pct"`
}

// Analytics is the on-the-fly sysadmin rippling analytics endpoint. Support/Admin only.
//
// @Router /rippling/analytics [get]
// @Summary On-the-fly rippling analytics KPIs (sysadmin)
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// analyticsWindow parses the shared stratum + [start,end) window query params, defaulting to the
// last 30 days, and returns the density SQL predicate. Used by both the fast SQL Analytics handler
// and the slow AnalyticsDriveTimes routing pass so their windows match exactly.
func analyticsWindow(c *fiber.Ctx) (stratum, start, end, stratumSQL string) {
	stratum = c.Query("stratum", "all")
	start = c.Query("start")
	end = c.Query("end")
	if start == "" {
		start = time.Now().AddDate(0, 0, -30).Format("2006-01-02 15:04:05")
	}
	if end == "" {
		end = time.Now().Format("2006-01-02 15:04:05")
	}
	stratumSQL = StratumFilter(stratum)
	return
}

func Analytics(c *fiber.Ctx) error {
	// Bound to the request context so an abandoned request (the gateway giving up, the user
	// switching window) stops its DB work rather than running on behind a response nobody reads.
	db := database.DBConn.WithContext(c.Context())
	stratum, start, end, stratumSQL := analyticsWindow(c)

	// Section 1 counts - pure SQL, full set. Per-post nreplies/taken/freeglers, then aggregate.
	var agg struct {
		Posts         int
		Replied36h    int
		RepliedEver   int
		Taken         int
		TotalReplies  int
		MeanFreeglers float64
	}
	// The four query blocks below (counts, held replies, trend, section 3) are independent, and
	// each takes seconds even with the rippling_reach(created_at, total_freeglers) index (they
	// aggregate per-post/per-reply subqueries over the whole window). Run them concurrently so the
	// endpoint's wall time is the slowest block, not the sum - serially they added up past the
	// gateway timeout (504s on the sysadmin KPIs).
	var wg sync.WaitGroup
	wg.Add(4)

	// replied_36h: got a reply within 36h of the settling clock - the headline convention. The
	// clock is reach creation (rippling_reach.created_at), which in practice coincides with the
	// first ripple and, unlike messages_groups.arrival, never moves: autorepost bumps arrival
	// forward, which used to hand older posts ever-longer 36h windows. replied_ever: any reply
	// eventually - the total.
	go func() {
		defer wg.Done()
		// stratumSQL
		// is one of StratumFilter's 4 fixed strings - 4 possible rendered
		// forms, all declared in ormharness/shapes.json and proven by
		// TestTier3Shapes_2def63211a50 (iznik-server-go/test). ReplyHorizonHours
		// is a Go constant (36), inlined directly rather than via fmt.Sprintf.
		innerCounts := fmt.Sprintf("(SELECT rr.total_freeglers AS freeglers, "+
			"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested') AS nreplies, "+
			"EXISTS(SELECT 1 FROM chat_messages c2 WHERE c2.refmsgid = rr.msgid AND c2.type = 'Interested' "+
			"AND c2.date <= rr.created_at + INTERVAL %d HOUR) AS replied_36h, "+
			"EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid) AS taken "+
			"FROM rippling_reach rr "+
			"JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer' "+
			"WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0%s "+
			"AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)"+
			") d", ReplyHorizonHours, stratumSQL)

		db.Table(innerCounts, start, end).
			Select("COUNT(*) AS posts, SUM(replied_36h) AS replied36h, SUM(nreplies > 0) AS replied_ever, " +
				"SUM(taken) AS taken, SUM(nreplies) AS total_replies, AVG(freeglers) AS mean_freeglers").
			Scan(&agg)
	}()

	// Reply friction: held replies over the window on rippled-out offers, as a share of all
	// replies. rippling_held_replies has msgid + created_at so it scopes by post + stratum.
	//
	// Same
	// stratumSQL toggle as 2def63211a50 above - 4 possible rendered forms,
	// all declared in ormharness/shapes.json and proven by
	// TestTier3Shapes_62cea0b491c9 (iznik-server-go/test).
	var held int
	go func() {
		defer wg.Done()
		// WHERE built as a single string for ONE Where() call: GORM's
		// clause.Where wraps any fragment containing "AND"/"OR" in an extra
		// paren pair once there is more than one Where expression to
		// combine (clause/where.go buildExprs), which would diverge from
		// the golden.
		db.Table("rippling_held_replies hr").
			Select("COUNT(*)").
			Joins("JOIN rippling_reach rr ON rr.msgid = hr.msgid AND rr.total_freeglers > 0"+stratumSQL).
			Joins("JOIN messages m ON m.id = hr.msgid AND m.type = 'Offer'").
			Where("hr.created_at >= ? AND hr.created_at < ? AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = hr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)", start, end).
			Scan(&held)
	}()

	// The drive-time figures (the Section 1 overall mean, the Section 3 rippled-out mean, the
	// per-day trend and the reliability bullseye) all come from a sampled routing pass - ~250
	// isochrone calls that take tens of seconds (worse over the apiv2-live tunnel). They are NOT
	// computed here: the frontend fetches them separately from AnalyticsDriveTimes so these fast
	// SQL KPIs render immediately instead of the whole tab blocking on the routing graph.
	// kpi.ReplyDrive and s3.RippleDrive keep their zero value (Available:false), so the UI shows a
	// "sampling..." placeholder until the drive-time request fills them in.

	// Section 2 - trends: the SQL KPIs (fixed-horizon reply rate, taken rate and mean replies,
	// plus freeglers reached) per day the post entered rippling. The sample-based drive-time
	// trend is merged in client-side from the separate drive-time request.
	var trendRows []TrendRow
	go func() {
		defer wg.Done()
		trendRows = trendSeries(db, start, end, stratumSQL)
	}()

	// Section 3 - is rippling helping? Server-derived rippled-out shares, the rescue floor and
	// contribution range, and the home-vs-rippled comparison. RippleDrive fills in separately.
	var s3 Section3RippledOut
	go func() {
		defer wg.Done()
		s3 = rippledOutSection(db, start, end, stratumSQL)
	}()

	wg.Wait()

	kpi := Section1KPI{Stratum: stratum, Posts: agg.Posts, Replied36h: agg.Replied36h,
		RepliedEver: agg.RepliedEver, Taken: agg.Taken, MeanFreeglers: agg.MeanFreeglers}
	if agg.Posts > 0 {
		kpi.Replied36hPct = float64(agg.Replied36h) / float64(agg.Posts) * 100
		kpi.RepliedEverPct = float64(agg.RepliedEver) / float64(agg.Posts) * 100
		kpi.TakenPct = float64(agg.Taken) / float64(agg.Posts) * 100
		kpi.MeanReplies = float64(agg.TotalReplies) / float64(agg.Posts)
	}
	kpi.HeldReplies = held
	if agg.TotalReplies > 0 {
		kpi.HeldRepliesPct = float64(held) / float64(agg.TotalReplies) * 100
	}

	trend := fiber.Map{
		"kpis":       trendRows,
		"drive_time": []DriveTrendPoint{},
	}

	return c.JSON(fiber.Map{
		"stratum":       stratum,
		"start":         start,
		"end":           end,
		"sample_target": driveSampleSize(),
		"section1":      kpi,
		"section2":      trend,
		"section3":      s3,
	})
}

// AnalyticsDriveTimes is the SLOW half of the analytics tab: the sampled drive-time routing pass.
// Split out from Analytics so the fast SQL KPIs render immediately and these figures fill in
// progressively, rather than the whole tab blocking on ~250 isochrone calls against the routing
// graph (tens of seconds, and slower still over the apiv2-live tunnel). ONE sampled pass still
// feeds every figure - the Section 1 overall mean, the Section 3 rippled-out mean, the per-day
// trend and the reliability bullseye - so nothing re-calls the graph. Support/Admin only. Read-only.
//
// @Router /rippling/analytics/drivetime [get]
// @Summary Sampled drive-time rippling analytics (sysadmin) - slow routing pass, loaded separately
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// The ~250-post routing pass takes tens of seconds — longer than the production gateway's
// timeout — so scoring the whole sample inside one request 504s (which surfaces on the client as
// a misleading CORS error, since the gateway's 504 has no Access-Control-Allow-Origin). So the
// work is driven by the CLIENT, one post at a time, and no server-side state (the old
// rippling_drivetime_cache job table) is needed. prod apiv2 is multi-node, but each request is
// self-contained, so there is no shared state to keep either:
//
//	1. GET  /rippling/analytics/drivetime            -> the random sample to score { posts, total }
//	2. POST /rippling/analytics/drivetime/score      -> score a chunk serially     { obs }   (repeat)
//	3. POST /rippling/analytics/drivetime/aggregate  -> obs -> the drive-time stats { reply_drive_min, ... }
//
// The client loops step 2 over the sample (one call after another), showing a progress bar, then
// aggregates. Each request does at most a few routing calls, so none runs long enough to 504.

// AnalyticsDriveTimes returns the random drive-time SAMPLE for the client to score serially.
// Fast (one sampling query, no routing).
func AnalyticsDriveTimes(c *fiber.Ctx) error {
	stratum, start, end, stratumSQL := analyticsWindow(c)
	posts := fetchDriveSample(database.DBConn.WithContext(c.Context()), start, end, stratumSQL, driveSampleSize())
	wire := make([]samplePostWire, len(posts))
	for i, p := range posts {
		wire[i] = samplePostWire{Lat: p.lat, Lng: p.lng, Points: p.points, Rippled: p.rippled, Days: p.days, Takers: p.takers}
	}
	return c.JSON(fiber.Map{
		"posts": wire, "total": len(wire),
		"stratum": stratum, "start": start, "end": end, "sample_target": driveSampleSize(),
	})
}

// samplePostWire is samplePost over the wire (exported JSON). The sample is random per fetch, so
// it cannot be re-derived server-side by offset — the client holds one run's sample and sends
// each post back to /score.
type samplePostWire struct {
	Lat     float64      `json:"lat"`
	Lng     float64      `json:"lng"`
	Points  [][2]float64 `json:"points"`
	Rippled []bool       `json:"rippled"`
	Days    []string     `json:"days"`
	Takers  []bool       `json:"takers"`
}

// driveObsWire is one scored observation over the wire.
type driveObsWire struct {
	Min     float64 `json:"min"`
	Rippled bool    `json:"rippled"`
	Day     string  `json:"day"`
	Taker   bool    `json:"taker"`
}

// AnalyticsDriveScore scores a chunk of the sample SERIALLY (one routing call per post) and
// returns the observations. The client calls this repeatedly, one chunk after another.
//
// @Router /rippling/analytics/drivetime/score [post]
// @Summary Score a chunk of the drive-time sample (sysadmin)
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
func AnalyticsDriveScore(c *fiber.Ctx) error {
	var req struct {
		Posts []samplePostWire `json:"posts"`
	}
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "bad body")
	}
	out := []driveObsWire{}
	for _, w := range req.Posts {
		p := samplePost{lat: w.Lat, lng: w.Lng, points: w.Points, rippled: w.Rippled, days: w.Days, takers: w.Takers}
		for _, o := range scoreOnePost(p) {
			out = append(out, driveObsWire{Min: o.min, Rippled: o.rippled, Day: o.day, Taker: o.taker})
		}
	}
	return c.JSON(fiber.Map{"obs": out})
}

// AnalyticsDriveAggregate turns the client's accumulated observations into the drive-time stats
// (overall mean, rippled-only mean, per-day trend, reply->take bullseye). Pure — no routing.
//
// @Router /rippling/analytics/drivetime/aggregate [post]
// @Summary Aggregate scored drive-time observations (sysadmin)
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
func AnalyticsDriveAggregate(c *fiber.Ctx) error {
	var req struct {
		Obs []driveObsWire `json:"obs"`
	}
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "bad body")
	}
	obs := make([]driveObs, len(req.Obs))
	for i, w := range req.Obs {
		obs[i] = driveObs{min: w.Min, rippled: w.Rippled, day: w.Day, taker: w.Taker}
	}
	return c.JSON(fiber.Map{
		"status":           "done",
		"reply_drive_min":  statFromObs(obs, false),
		"ripple_drive_min": statFromObs(obs, true),
		"drive_time":       driveTrendFromObs(obs),
		"bullseye":         bullseyeFromObs(obs),
	})
}

// TrendRow is one day's point for the Section 2 time series, by the day the post entered
// rippling. The reply/taken/mean-replies figures are FIXED-HORIZON (see ReplyHorizonHours /
// TakenHorizonDays): every day is measured the same way, so a lower recent point is a real
// change, not just less accrual time.
type TrendRow struct {
	Day           string  `json:"day"            gorm:"column:day"`
	Posts         int     `json:"posts"          gorm:"column:posts"`
	RepliedPct    float64 `json:"replied_pct"    gorm:"column:replied_pct"`
	TakenPct      float64 `json:"taken_pct"      gorm:"column:taken_pct"`
	MeanReplies   float64 `json:"mean_replies"   gorm:"column:mean_replies"`
	MeanFreeglers float64 `json:"mean_freeglers" gorm:"column:mean_freeglers"`
	// Whether the day is old enough for its fixed-horizon figures to be final. RepliedMature
	// covers replied_pct + mean_replies (36h); taken_pct matures much later (14 days), so the
	// taken trend's provisional tail is weeks long, not hours.
	RepliedMature bool `json:"replied_mature" gorm:"-"`
	TakenMature   bool `json:"taken_mature"   gorm:"-"`
}

// trendSeries returns per-day KPI points (ascending) over the window + stratum. Pure SQL plus
// the maturity flags, which depend on the wall clock, not the data.
func trendSeries(db *gorm.DB, start, end, stratumSQL string) []TrendRow {
	// stratumSQL is
	// one of StratumFilter's 4 fixed strings - 4 possible rendered forms, all
	// declared in ormharness/shapes.json and proven by
	// TestTier3Shapes_1dee90c3c378 (iznik-server-go/test). ReplyHorizonHours/
	// TakenHorizonDays are Go constants (36, 14), inlined directly.
	innerTrend := fmt.Sprintf("(SELECT rr.created_at AS created, rr.total_freeglers AS freeglers, "+
		"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested' "+
		"AND cm.date <= rr.created_at + INTERVAL %d HOUR) AS nreplies, "+
		"EXISTS(SELECT 1 FROM chat_messages c2 WHERE c2.refmsgid = rr.msgid AND c2.type = 'Interested' "+
		"AND c2.date <= rr.created_at + INTERVAL %d HOUR) AS replied, "+
		"EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid "+
		"AND mb.timestamp <= rr.created_at + INTERVAL %d DAY) AS taken "+
		"FROM rippling_reach rr "+
		"JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer' "+
		"WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0%s "+
		"AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)"+
		") d", ReplyHorizonHours, ReplyHorizonHours, TakenHorizonDays, stratumSQL)

	rows := []TrendRow{}
	db.Table(innerTrend, start, end).
		Select("DATE_FORMAT(created, '%Y-%m-%d') AS day, COUNT(*) AS posts, " +
			"100 * SUM(replied) / COUNT(*) AS replied_pct, 100 * SUM(taken) / COUNT(*) AS taken_pct, " +
			"SUM(nreplies) / COUNT(*) AS mean_replies, AVG(freeglers) AS mean_freeglers").
		Group("day").Order("day").Scan(&rows)
	now := time.Now()
	for i := range rows {
		rows[i].RepliedMature = trendMature(rows[i].Day, ReplyHorizonHours*time.Hour, now)
		rows[i].TakenMature = trendMature(rows[i].Day, TakenHorizonDays*24*time.Hour, now)
	}
	return rows
}

// Section3RippledOut is the "is rippling helping?" block. rippled-out is server-derived: the
// replier reached the post via rippling (not an established member of an origin group before
// arrival).
//
// The headline is a CONTRIBUTION RANGE, because per-reply conversion (rippled worse - people
// further away follow through less) is the wrong lens: rippled takes are ADDITIVE.
//   - Floor = RescuedTakes: posts taken by a rippled replier that had NO home-group reply at all.
//     Without rippling those posts go silent, so those takes definitely would not have happened.
//   - Ceiling = RippledTakers: every take by a rippled replier (assumes no home member would have
//     taken it instead).
//
// Rippling's true contribution to reuse sits between the two.
//
// HomeConvPct / RippledConvPct give the reply->take comparison you'd expect rippled to lose (it's
// further away), shown so the "worse per reply, still additive" point is explicit.
type Section3RippledOut struct {
	Replies           int     `json:"replies"`
	RippledReplies    int     `json:"rippled_replies"`
	RippledRepliesPct float64 `json:"rippled_replies_pct"`
	Takers            int     `json:"takers"`
	RippledTakers     int     `json:"rippled_takers"`
	RippledTakersPct  float64 `json:"rippled_takers_pct"`

	// "Is it helping?" contribution range (share of all takes on rippled-eligible posts).
	RescuedTakes        int     `json:"rescued_takes"`
	RescuedTakesPct     float64 `json:"rescued_takes_pct"`
	ContributionLowPct  float64 `json:"contribution_low_pct"`  // = rescued / takers
	ContributionHighPct float64 `json:"contribution_high_pct"` // = rippled / takers

	// Per-reply reply->take conversion, home vs rippled cohort.
	HomeConvPct    float64 `json:"home_conv_pct"`
	RippledConvPct float64 `json:"rippled_conv_pct"`

	ClientInstrumentedPct float64   `json:"client_instrumented_pct"`
	RippleDrive           DriveStat `json:"ripple_drive_min"`
}

// dayWindowLayout is the datetime format used for the synthesised chunk boundaries handed back to
// MySQL. It always includes a time component, which MySQL accepts equally well for a date-only
// boundary (midnight), so it is safe to use regardless of which layout the caller's start/end used.
const dayWindowLayout = "2006-01-02 15:04:05"

// dayWindows splits [start,end) into half-open 1-day sub-windows, so a rippling_reach/chat_messages
// scan over a wide date range runs as many short statements instead of one that can hold a Galera
// commit-certification queue for minutes. If either bound doesn't parse (free-form query params -
// accepts both the "YYYY-MM-DD" a plain date input sends and the full "YYYY-MM-DD HH:MM:SS" default)
// or start is not before end, it falls back to the original [start,end) as a single window: MySQL
// still validates and runs it exactly as it does today, just unchunked.
func dayWindows(start, end string) [][2]string {
	layouts := []string{dayWindowLayout, "2006-01-02"}
	parse := func(s string) (time.Time, bool) {
		for _, l := range layouts {
			if t, err := time.Parse(l, s); err == nil {
				return t, true
			}
		}
		return time.Time{}, false
	}
	st, ok1 := parse(start)
	en, ok2 := parse(end)
	if !ok1 || !ok2 || !st.Before(en) {
		return [][2]string{{start, end}}
	}
	var windows [][2]string
	for d := st; d.Before(en); d = d.AddDate(0, 0, 1) {
		next := d.AddDate(0, 0, 1)
		if next.After(en) {
			next = en
		}
		windows = append(windows, [2]string{d.Format(dayWindowLayout), next.Format(dayWindowLayout)})
	}
	return windows
}

// rippledOutSection computes the server-derived rippled-out reply/taker shares (pure SQL) over
// replies to rippled posts in the window + stratum.
// rippledOutDeadline bounds rippledOutSection's chunked walks. Each per-day
// statement is short, but a wide range issues many, and the fiber request
// context can't do this (fasthttp only cancels it on server shutdown) - so
// without it an abandoned tab load would keep stepping through the remaining
// windows to completion.
const rippledOutDeadline = 120 * time.Second

func rippledOutSection(db *gorm.DB, start, end, stratumSQL string) Section3RippledOut {
	ctx, cancel := context.WithTimeout(context.Background(), rippledOutDeadline)
	defer cancel()
	db = db.WithContext(ctx)

	var raw struct {
		Replies        int
		RippledReplies int
		Takers         int
		RippledTakers  int
		ClientRippled  int
	}
	// The shares query and the rescue-floor query are independent and both heavy (the rescue floor
	// is the slowest single query on the tab), so run them concurrently. Each is also chunked into
	// 1-day sub-windows and summed in Go: both are plain COUNT/SUM aggregates over rows disjointly
	// partitioned by the column each chunks on (cm.date below, rr.created_at in the rescue query),
	// so per-chunk totals summed in Go equal the single-window total exactly - no DISTINCT and no
	// cross-window dependency to break additivity.
	var wg sync.WaitGroup
	wg.Add(2)

	go func() {
		defer wg.Done()
		// NOT converted here (raw): master replaced the single unchunked
		// query (site 0608bbe6423f) with a per-day chunked loop - see this
		// function's own doc comment on rippledOutDeadline/why both goroutines
		// are chunked. Re-doing the .Table(innerShares,...) conversion per
		// chunk was not attempted under a merge; left raw for a properly
		// tested follow-up.
		for _, w := range dayWindows(start, end) {
			var chunk struct {
				Replies        int
				RippledReplies int
				Takers         int
				RippledTakers  int
				ClientRippled  int
			}
			if err := db.Raw(`
			SELECT COUNT(*) AS replies,
			       SUM(rippled) AS rippled_replies,
			       SUM(is_taker) AS takers,
			       SUM(rippled AND is_taker) AS rippled_takers,
			       SUM(client_rippled) AS client_rippled
			FROM (
			    SELECT
			      (NOT `+EstablishedOriginMemberExists("cm.refmsgid", "cm.userid")+`) AS rippled,
			      EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = cm.refmsgid AND mb.userid = cm.userid) AS is_taker,
			      EXISTS(SELECT 1 FROM rippling_reply_attribution rra
			             WHERE rra.msgid = cm.refmsgid AND rra.userid = cm.userid
			               AND rra.attribution IN ('ripple_notified','ripple_group','ripple_join','ripple_reach')) AS client_rippled
			    FROM chat_messages cm
			    JOIN rippling_reach rr ON rr.msgid = cm.refmsgid AND rr.total_freeglers > 0`+stratumSQL+`
			    JOIN messages m ON m.id = cm.refmsgid AND m.type = 'Offer'
			    WHERE cm.type = 'Interested' AND cm.date >= ? AND cm.date < ?
			      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = cm.refmsgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
			) d`, w[0], w[1]).Scan(&chunk).Error; err != nil {
				// Zero the whole metric, matching the unchunked query's
				// all-or-nothing failure shape - a partially-summed total
				// would look plausible while silently missing days.
				raw.Replies, raw.RippledReplies, raw.Takers, raw.RippledTakers, raw.ClientRippled = 0, 0, 0, 0, 0
				break
			}
			raw.Replies += chunk.Replies
			raw.RippledReplies += chunk.RippledReplies
			raw.Takers += chunk.Takers
			raw.RippledTakers += chunk.RippledTakers
			raw.ClientRippled += chunk.ClientRippled
		}
	}()

	// Rescue floor: DISTINCT posts that were taken AND had at least one reply AND had NO reply
	// from an established home-group member - so the take could only have come via rippling.
	// The membership test must match EstablishedOriginMemberExists (same clauses, mem.rippled = 0
	// included) but is spelled out as a flat join rather than calling it: this is the slowest
	// query on the tab and nesting an EXISTS per chat_messages row changes its plan.
	var rescued int
	go func() {
		defer wg.Done()
		// NOT converted here (raw): master replaced the single unchunked
		// query (site 1f03ad9be65b) with a per-day chunked loop, same
		// reasoning as innerShares above.
		for _, w := range dayWindows(start, end) {
			var chunkRescued int
			if err := db.Raw(`
			SELECT COUNT(*) FROM (
			    SELECT rr.msgid
			    FROM rippling_reach rr
			    JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer'
			    WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0`+stratumSQL+`
			      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
			      AND EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid)
			      AND EXISTS(SELECT 1 FROM chat_messages c WHERE c.refmsgid = rr.msgid AND c.type = 'Interested')
			      AND NOT EXISTS(
			          SELECT 1 FROM chat_messages ch
			          INNER JOIN messages_groups og ON og.msgid = ch.refmsgid AND og.rippled_in = 0 AND og.deleted = 0
			          INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = ch.userid
			            AND mem.collection = 'Approved' AND mem.added < og.arrival AND mem.rippled = 0
			          WHERE ch.refmsgid = rr.msgid AND ch.type = 'Interested')
			) x`, w[0], w[1]).Scan(&chunkRescued).Error; err != nil {
				// As above: all-or-nothing rather than a silent undercount.
				rescued = 0
				break
			}
			rescued += chunkRescued
		}
	}()

	wg.Wait()

	s := Section3RippledOut{Replies: raw.Replies, RippledReplies: raw.RippledReplies,
		Takers: raw.Takers, RippledTakers: raw.RippledTakers, RescuedTakes: rescued}
	if raw.Replies > 0 {
		s.RippledRepliesPct = float64(raw.RippledReplies) / float64(raw.Replies) * 100
		s.ClientInstrumentedPct = float64(raw.ClientRippled) / float64(raw.Replies) * 100
	}
	if raw.Takers > 0 {
		s.RippledTakersPct = float64(raw.RippledTakers) / float64(raw.Takers) * 100
		s.RescuedTakesPct = float64(rescued) / float64(raw.Takers) * 100
		s.ContributionLowPct = s.RescuedTakesPct
		s.ContributionHighPct = s.RippledTakersPct
	}
	// Per-reply conversion, home vs rippled cohort (derived from the counts).
	if raw.RippledReplies > 0 {
		s.RippledConvPct = float64(raw.RippledTakers) / float64(raw.RippledReplies) * 100
	}
	if homeReplies := raw.Replies - raw.RippledReplies; homeReplies > 0 {
		s.HomeConvPct = float64(raw.Takers-raw.RippledTakers) / float64(homeReplies) * 100
	}
	return s
}
