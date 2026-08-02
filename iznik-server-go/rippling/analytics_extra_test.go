package rippling

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// envInt reads a positive int env var, falling back to def for unset, empty,
// non-numeric, zero and negative values.
func TestEnvInt(t *testing.T) {
	cases := []struct {
		name string
		set  bool
		val  string
		def  int
		want int
	}{
		{"unset falls back to default", false, "", 42, 42},
		{"valid positive int wins", true, "7", 42, 7},
		{"non-numeric falls back to default", true, "notanumber", 42, 42},
		{"zero falls back to default", true, "0", 42, 42},
		{"negative falls back to default", true, "-5", 42, 42},
		{"empty string falls back to default", true, "", 42, 42},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if c.set {
				t.Setenv("TEST_ENV_INT_KEY", c.val)
			} else {
				t.Setenv("TEST_ENV_INT_KEY", "")
				// Ensure truly unset, not just empty - os.Getenv treats both the
				// same way here since envInt checks v != "".
			}
			got := envInt("TEST_ENV_INT_KEY", c.def)
			assert.Equal(t, c.want, got, c.name)
		})
	}
}

// driveSampleSize / driveConcurrency default to their documented constants and
// are overridable via their respective env vars.
func TestDriveSampleSize(t *testing.T) {
	t.Setenv("RIPPLE_ANALYTICS_SAMPLE", "")
	assert.Equal(t, 250, driveSampleSize())

	t.Setenv("RIPPLE_ANALYTICS_SAMPLE", "500")
	assert.Equal(t, 500, driveSampleSize())
}

func TestDriveConcurrency(t *testing.T) {
	t.Setenv("RIPPLE_ANALYTICS_CONCURRENCY", "")
	assert.Equal(t, 16, driveConcurrency())

	t.Setenv("RIPPLE_ANALYTICS_CONCURRENCY", "4")
	assert.Equal(t, 4, driveConcurrency())
}

// StratumFilter renders the SQL predicate for each density stratum, and imposes
// no extra bound for "all" or any unrecognised value.
func TestStratumFilter(t *testing.T) {
	cases := []struct {
		stratum string
		want    string
	}{
		{"rural", " AND rr.total_freeglers < 1700"},
		{"suburban", " AND rr.total_freeglers >= 1700 AND rr.total_freeglers < 3800"},
		{"dense", " AND rr.total_freeglers >= 3800"},
		{"all", ""},
		{"", ""},
		{"unknown-stratum", ""},
	}
	for _, c := range cases {
		t.Run(c.stratum, func(t *testing.T) {
			assert.Equal(t, c.want, StratumFilter(c.stratum))
		})
	}
}

func fptr(v float64) *float64 { return &v }

// scoreOnePost posts to the routing server's /v1/ripple-eval and turns the
// response into driveObs, tagging each with its rippled/day/taker flag by
// index. It is best-effort: any transport, HTTP-status or shape mismatch
// yields no observations rather than an error.
func TestScoreOnePost_Success(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req rippleEvalReq
		assert.NoError(t, json.NewDecoder(r.Body).Decode(&req))
		assert.Equal(t, "drive", req.Mode)
		assert.Equal(t, float64(driveMaxMinutes), req.MaxMinutes)
		assert.Len(t, req.Points, 2)

		resp := rippleEvalResp{Results: []struct {
			DriveMin *float64 `json:"drive_min"`
		}{
			{DriveMin: fptr(12.5)},
			{DriveMin: fptr(30)},
		}}
		w.Header().Set("Content-Type", "application/json")
		assert.NoError(t, json.NewEncoder(w).Encode(resp))
	}))
	defer srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	p := samplePost{
		lat: 51.5, lng: -0.1,
		points:  [][2]float64{{-0.2, 51.4}, {-0.3, 51.3}},
		rippled: []bool{true, false},
		days:    []string{"2026-07-01", "2026-07-02"},
		takers:  []bool{false, true},
	}
	obs := scoreOnePost(p)
	assert.Len(t, obs, 2)
	assert.Equal(t, 12.5, obs[0].min)
	assert.True(t, obs[0].rippled)
	assert.Equal(t, "2026-07-01", obs[0].day)
	assert.False(t, obs[0].taker)
	assert.Equal(t, 30.0, obs[1].min)
	assert.False(t, obs[1].rippled)
	assert.Equal(t, "2026-07-02", obs[1].day)
	assert.True(t, obs[1].taker)
}

func TestScoreOnePost_NilDriveMinSkipped(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := rippleEvalResp{Results: []struct {
			DriveMin *float64 `json:"drive_min"`
		}{
			{DriveMin: nil},
			{DriveMin: fptr(5)},
		}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	p := samplePost{points: [][2]float64{{0, 0}, {1, 1}}, rippled: []bool{true, true}, days: []string{"d1", "d2"}, takers: []bool{true, true}}
	obs := scoreOnePost(p)
	assert.Len(t, obs, 1)
	assert.Equal(t, 5.0, obs[0].min)
}

func TestScoreOnePost_IndexOutOfRangeUsesZeroValue(t *testing.T) {
	// rippled/days/takers shorter than points: the extra results still score,
	// just without the per-point tag.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := rippleEvalResp{Results: []struct {
			DriveMin *float64 `json:"drive_min"`
		}{
			{DriveMin: fptr(1)},
			{DriveMin: fptr(2)},
		}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	p := samplePost{points: [][2]float64{{0, 0}, {1, 1}}} // rippled/days/takers all nil
	obs := scoreOnePost(p)
	assert.Len(t, obs, 2)
	assert.False(t, obs[1].rippled)
	assert.Equal(t, "", obs[1].day)
	assert.False(t, obs[1].taker)
}

func TestScoreOnePost_NetworkErrorReturnsNil(t *testing.T) {
	// A closed listener refuses the connection immediately (deterministic and
	// fast, unlike routing to an arbitrary unused port).
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	p := samplePost{points: [][2]float64{{0, 0}}}
	assert.Nil(t, scoreOnePost(p))
}

func TestScoreOnePost_NonOKStatusReturnsNil(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	p := samplePost{points: [][2]float64{{0, 0}}}
	assert.Nil(t, scoreOnePost(p))
}

func TestScoreOnePost_BadJSONReturnsNil(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte("not json"))
	}))
	defer srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	p := samplePost{points: [][2]float64{{0, 0}}}
	assert.Nil(t, scoreOnePost(p))
}

func TestScoreOnePost_MismatchedResultCountReturnsNil(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := rippleEvalResp{Results: []struct {
			DriveMin *float64 `json:"drive_min"`
		}{
			{DriveMin: fptr(1)},
		}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	// Two points requested but only one result returned.
	p := samplePost{points: [][2]float64{{0, 0}, {1, 1}}}
	assert.Nil(t, scoreOnePost(p))
}

// statFromObs computes the mean drive-time (+95% CI) over the observations,
// optionally restricted to rippled-out replies.
func TestStatFromObs_Empty(t *testing.T) {
	stat := statFromObs(nil, false)
	assert.Equal(t, 0, stat.NReplies)
	assert.False(t, stat.Available)
	assert.Equal(t, 0.0, stat.MeanMin)
	assert.Equal(t, 0.0, stat.CIHalf)
}

func TestStatFromObs_SingleValueNoCIHalf(t *testing.T) {
	obs := []driveObs{{min: 10}}
	stat := statFromObs(obs, false)
	assert.True(t, stat.Available)
	assert.Equal(t, 1, stat.NReplies)
	assert.Equal(t, 10.0, stat.MeanMin)
	// A single sample has no variance -> CI half stays the zero value.
	assert.Equal(t, 0.0, stat.CIHalf)
}

func TestStatFromObs_MultipleValuesComputesMeanAndCI(t *testing.T) {
	obs := []driveObs{{min: 10}, {min: 20}, {min: 30}}
	stat := statFromObs(obs, false)
	assert.True(t, stat.Available)
	assert.Equal(t, 3, stat.NReplies)
	assert.InDelta(t, 20.0, stat.MeanMin, 1e-9)
	assert.True(t, stat.CIHalf > 0)
}

func TestStatFromObs_RippledOnlyFiltersNonRippled(t *testing.T) {
	obs := []driveObs{{min: 10, rippled: true}, {min: 100, rippled: false}}
	stat := statFromObs(obs, true)
	assert.Equal(t, 1, stat.NReplies)
	assert.Equal(t, 10.0, stat.MeanMin)
}

func TestStatFromObs_RippledOnlyNoMatchesIsUnavailable(t *testing.T) {
	obs := []driveObs{{min: 10, rippled: false}}
	stat := statFromObs(obs, true)
	assert.False(t, stat.Available)
	assert.Equal(t, 0, stat.NReplies)
}

// driveTrendFromObs buckets the sampled drive-times by reply day (ascending),
// dropping observations with no day tag.
func TestDriveTrendFromObs_Empty(t *testing.T) {
	assert.Empty(t, driveTrendFromObs(nil))
}

func TestDriveTrendFromObs_SkipsEmptyDay(t *testing.T) {
	obs := []driveObs{{min: 5, day: ""}}
	assert.Empty(t, driveTrendFromObs(obs))
}

func TestDriveTrendFromObs_GroupsAndSortsByDay(t *testing.T) {
	obs := []driveObs{
		{min: 20, day: "2026-07-02"},
		{min: 10, day: "2026-07-01"},
		{min: 30, day: "2026-07-02"},
	}
	trend := driveTrendFromObs(obs)
	assert.Len(t, trend, 2)
	// Ascending day order.
	assert.Equal(t, "2026-07-01", trend[0].Day)
	assert.Equal(t, 10.0, trend[0].MeanMin)
	assert.Equal(t, 1, trend[0].N)
	assert.Equal(t, "2026-07-02", trend[1].Day)
	assert.Equal(t, 25.0, trend[1].MeanMin) // (20+30)/2
	assert.Equal(t, 2, trend[1].N)
}

// Bullseye buckets parallel (drive-time, taker?) observations into the fixed
// drive-time rings and computes reply->take conversion per ring.
func TestBullseye_EmptyInput(t *testing.T) {
	bands := Bullseye(nil, nil)
	assert.Len(t, bands, len(bullseyeEdges)-1)
	for _, b := range bands {
		assert.Equal(t, 0, b.NReplies)
		assert.Equal(t, 0.0, b.ConvPct)
	}
}

func TestBullseye_BandsAssignedCorrectly(t *testing.T) {
	// 0-10 band (first, exclusive of hi=10), 10-15 band, and the last band
	// (30-45) which is inclusive at both ends.
	mins := []float64{5, 10, 45}
	takers := []bool{true, false, true}
	bands := Bullseye(mins, takers)

	band0 := bands[0] // [0,10)
	assert.Equal(t, 1, band0.NReplies)
	assert.Equal(t, 1, band0.NTakers)
	assert.InDelta(t, 100.0, band0.ConvPct, 1e-9)

	band1 := bands[1] // [10,15)
	assert.Equal(t, 1, band1.NReplies)
	assert.Equal(t, 0, band1.NTakers)
	assert.Equal(t, 0.0, band1.ConvPct)

	last := bands[len(bands)-1] // [30,45] inclusive
	assert.Equal(t, 1, last.NReplies)
	assert.Equal(t, 1, last.NTakers)
}

func TestBullseye_ValueBelowZeroExcludedFromEveryBand(t *testing.T) {
	bands := Bullseye([]float64{-1}, []bool{true})
	for _, b := range bands {
		assert.Equal(t, 0, b.NReplies)
	}
}

func TestBullseye_ValueAboveMaxExcludedFromEveryBand(t *testing.T) {
	bands := Bullseye([]float64{1000}, []bool{true})
	for _, b := range bands {
		assert.Equal(t, 0, b.NReplies)
	}
}

func TestBullseye_TakerIndexOutOfRangeTreatedAsFalse(t *testing.T) {
	// More mins than takers: the extra mins must not panic and count as
	// non-takers.
	bands := Bullseye([]float64{5}, nil)
	assert.Equal(t, 1, bands[0].NReplies)
	assert.Equal(t, 0, bands[0].NTakers)
}

func TestBullseye_ConversionAndCIHalfComputed(t *testing.T) {
	// Band [0,10): 4 replies, 2 takers -> 50% conversion with a non-zero CI.
	mins := []float64{1, 2, 3, 4}
	takers := []bool{true, true, false, false}
	bands := Bullseye(mins, takers)
	band0 := bands[0]
	assert.Equal(t, 4, band0.NReplies)
	assert.Equal(t, 2, band0.NTakers)
	assert.InDelta(t, 50.0, band0.ConvPct, 1e-9)
	assert.True(t, band0.CIHalf > 0)
}

// bullseyeFromObs pulls the drive-time + taker flag off the sampled
// observations and bands them, matching Bullseye run directly on the same
// mins/takers.
func TestBullseyeFromObs_MatchesDirectBullseyeCall(t *testing.T) {
	obs := []driveObs{
		{min: 5, taker: true},
		{min: 12, taker: false},
	}
	got := bullseyeFromObs(obs)
	want := Bullseye([]float64{5, 12}, []bool{true, false})
	assert.Equal(t, want, got)
}

func TestBullseyeFromObs_Empty(t *testing.T) {
	bands := bullseyeFromObs(nil)
	assert.Len(t, bands, len(bullseyeEdges)-1)
}

// dayWindows splits [start,end) into half-open 1-day sub-windows so the rippledOutSection queries
// run as many short statements instead of one long one; it degrades to the original bounds as a
// single window whenever it can't confidently chunk them.
func TestDayWindows_MultiDayRangeProducesContiguousHalfOpenWindows(t *testing.T) {
	got := dayWindows("2026-07-01 00:00:00", "2026-07-04 00:00:00")
	want := [][2]string{
		{"2026-07-01 00:00:00", "2026-07-02 00:00:00"},
		{"2026-07-02 00:00:00", "2026-07-03 00:00:00"},
		{"2026-07-03 00:00:00", "2026-07-04 00:00:00"},
	}
	assert.Equal(t, want, got)
}

func TestDayWindows_PartialFinalDayIsClampedToEnd(t *testing.T) {
	got := dayWindows("2026-07-01 00:00:00", "2026-07-02 12:30:00")
	want := [][2]string{
		{"2026-07-01 00:00:00", "2026-07-02 00:00:00"},
		{"2026-07-02 00:00:00", "2026-07-02 12:30:00"},
	}
	assert.Equal(t, want, got)
}

func TestDayWindows_SubDayRangeIsOneWindow(t *testing.T) {
	got := dayWindows("2026-07-01 09:00:00", "2026-07-01 17:00:00")
	want := [][2]string{{"2026-07-01 09:00:00", "2026-07-01 17:00:00"}}
	assert.Equal(t, want, got)
}

func TestDayWindows_DateOnlyLayoutParses(t *testing.T) {
	// The frontend's plain date filter sends "YYYY-MM-DD" (no time component) - must chunk the
	// same as the full datetime layout the handler's own default uses.
	got := dayWindows("2026-07-01", "2026-07-03")
	want := [][2]string{
		{"2026-07-01 00:00:00", "2026-07-02 00:00:00"},
		{"2026-07-02 00:00:00", "2026-07-03 00:00:00"},
	}
	assert.Equal(t, want, got)
}

func TestDayWindows_UnparseableBoundsFallBackToOriginalSingleWindow(t *testing.T) {
	cases := []struct {
		name, start, end string
	}{
		{"garbage start", "not-a-date", "2026-07-03 00:00:00"},
		{"garbage end", "2026-07-01 00:00:00", "not-a-date"},
		{"both garbage", "nope", "also-nope"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := dayWindows(c.start, c.end)
			assert.Equal(t, [][2]string{{c.start, c.end}}, got)
		})
	}
}

func TestDayWindows_StartNotBeforeEndFallsBackToOriginalSingleWindow(t *testing.T) {
	cases := []struct {
		name, start, end string
	}{
		{"equal bounds", "2026-07-01 00:00:00", "2026-07-01 00:00:00"},
		{"start after end", "2026-07-05 00:00:00", "2026-07-01 00:00:00"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := dayWindows(c.start, c.end)
			assert.Equal(t, [][2]string{{c.start, c.end}}, got)
		})
	}
}

// The chunked bounds must be gap-free and non-overlapping: each window's end is exactly the next
// window's start, so summing per-chunk COUNT/SUM results in Go equals what the unchunked query
// would have returned - the additivity property the day-chunking fix depends on.
func TestDayWindows_WindowsAreContiguousAndCoverTheFullRange(t *testing.T) {
	start, end := "2026-01-01 00:00:00", "2026-03-15 06:00:00"
	windows := dayWindows(start, end)
	assert.Equal(t, start, windows[0][0])
	assert.Equal(t, end, windows[len(windows)-1][1])
	for i := 1; i < len(windows); i++ {
		assert.Equal(t, windows[i-1][1], windows[i][0], "gap or overlap between chunk %d and %d", i-1, i)
	}
	for _, w := range windows {
		st, err := time.Parse(dayWindowLayout, w[0])
		assert.NoError(t, err)
		en, err := time.Parse(dayWindowLayout, w[1])
		assert.NoError(t, err)
		assert.True(t, st.Before(en), "chunk must be non-empty: %v", w)
	}
}
