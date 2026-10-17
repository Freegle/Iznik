package ux

import (
	"sort"
)

// ---- funnels ----

type Breakdown struct {
	Key       string `json:"key"`
	Entered   int64  `json:"entered"`
	Visits    int64  `json:"visits"`
	Completed int64  `json:"completed"`
	Abandoned int64  `json:"abandoned"`
}

type StepBreakdown struct {
	DeviceClass []Breakdown `json:"device_class"`
	LoggedIn    []Breakdown `json:"logged_in"`
	Variant     []Breakdown `json:"variant"`
}

type FunnelStep struct {
	Step      string `json:"step"`
	Entered   int64  `json:"entered"`
	Reentered int64  `json:"reentered"`
	// Visits is entered plus reentered. Completed, backed, abandoned and help_sought count once
	// per visit, so rates over them divide by this, not by entered.
	Visits             int64          `json:"visits"`
	Completed          int64          `json:"completed"`
	Backed             int64          `json:"backed"`
	Abandoned          int64          `json:"abandoned"`
	Revisits           int64          `json:"revisits"`
	ValidationFailures int64          `json:"validation_failures"`
	HelpSought         int64          `json:"help_sought"`
	DwellP50Ms         *int64         `json:"dwell_p50_ms"`
	DwellP90Ms         *int64         `json:"dwell_p90_ms"`
	By                 *StepBreakdown `json:"by,omitempty"`
}

type FunnelFlow struct {
	Flow  string       `json:"flow"`
	Steps []FunnelStep `json:"steps"`
}

// FlowsPeriod is the period before the one asked for. It carries its own dates so the tab prints
// them from the server and never works them out itself.
type FlowsPeriod struct {
	Start string       `json:"start"`
	End   string       `json:"end"`
	Flows []FunnelFlow `json:"flows"`
}

type FunnelsResponse struct {
	Start    string       `json:"start"`
	End      string       `json:"end"`
	Flows    []FunnelFlow `json:"flows"`
	Previous FlowsPeriod  `json:"previous"`
	Applied
}

type funnelRow struct {
	Flow               string `gorm:"column:flow"`
	Step               string `gorm:"column:step"`
	Entered            int64  `gorm:"column:entered"`
	Reentered          int64  `gorm:"column:reentered"`
	Completed          int64  `gorm:"column:completed"`
	Backed             int64  `gorm:"column:backed"`
	Abandoned          int64  `gorm:"column:abandoned"`
	Revisits           int64  `gorm:"column:revisits"`
	ValidationFailures int64  `gorm:"column:validation_failures"`
	HelpSought         int64  `gorm:"column:help_sought"`
	DwellP50Ms         *int64 `gorm:"column:dwell_p50_ms"`
	DwellP90Ms         *int64 `gorm:"column:dwell_p90_ms"`
}

type breakdownRow struct {
	Flow      string `gorm:"column:flow"`
	Step      string `gorm:"column:step"`
	K         string `gorm:"column:k"`
	Entered   int64  `gorm:"column:entered"`
	Reentered int64  `gorm:"column:reentered"`
	Completed int64  `gorm:"column:completed"`
	Abandoned int64  `gorm:"column:abandoned"`
}

// breakdownDims maps the three "by" dimensions to the SQL expression that yields their key. The
// expressions are fixed here, never built from input.
var breakdownDims = []struct {
	name string
	expr string
}{
	{"device_class", "device_class"},
	{"logged_in", "CASE WHEN logged_in = 1 THEN 'true' ELSE 'false' END"},
	{"variant", "variant"},
}

func funnelFlows(p period, s scope, withBreakdown bool) ([]FunnelFlow, error) {
	var rows []funnelRow
	err := rangeQuery("ux_flow_daily", p, s).
		Select("flow, step, " +
			"CAST(SUM(entered) AS SIGNED) AS entered, CAST(SUM(reentered) AS SIGNED) AS reentered, " +
			"CAST(SUM(completed) AS SIGNED) AS completed, CAST(SUM(backed) AS SIGNED) AS backed, " +
			"CAST(SUM(abandoned) AS SIGNED) AS abandoned, CAST(SUM(revisits) AS SIGNED) AS revisits, " +
			"CAST(SUM(validation_failures) AS SIGNED) AS validation_failures, CAST(SUM(help_sought) AS SIGNED) AS help_sought, " +
			weighted("dwell_p50_ms", "entered") + " AS dwell_p50_ms, " + weighted("dwell_p90_ms", "entered") + " AS dwell_p90_ms").
		Group("flow, step").Scan(&rows).Error
	if err != nil {
		return nil, err
	}

	steps := map[string]*FunnelStep{}
	byFlow := map[string][]*FunnelStep{}
	for _, r := range rows {
		s := &FunnelStep{
			Step: r.Step, Entered: r.Entered, Reentered: r.Reentered, Visits: r.Entered + r.Reentered,
			Completed: r.Completed, Backed: r.Backed,
			Abandoned: r.Abandoned, Revisits: r.Revisits, ValidationFailures: r.ValidationFailures,
			HelpSought: r.HelpSought, DwellP50Ms: r.DwellP50Ms, DwellP90Ms: r.DwellP90Ms,
		}
		if withBreakdown {
			s.By = &StepBreakdown{DeviceClass: []Breakdown{}, LoggedIn: []Breakdown{}, Variant: []Breakdown{}}
		}
		steps[r.Flow+"\x00"+r.Step] = s
		byFlow[r.Flow] = append(byFlow[r.Flow], s)
	}

	if withBreakdown {
		for _, dim := range breakdownDims {
			var brows []breakdownRow
			err := rangeQuery("ux_flow_daily", p, s).
				Select("flow, step, " + dim.expr + " AS k, " +
					"CAST(SUM(entered) AS SIGNED) AS entered, CAST(SUM(reentered) AS SIGNED) AS reentered, " +
					"CAST(SUM(completed) AS SIGNED) AS completed, CAST(SUM(abandoned) AS SIGNED) AS abandoned").
				Group("flow, step, k").Scan(&brows).Error
			if err != nil {
				return nil, err
			}
			for _, b := range brows {
				s := steps[b.Flow+"\x00"+b.Step]
				if s == nil {
					continue
				}
				item := Breakdown{Key: b.K, Entered: b.Entered, Visits: b.Entered + b.Reentered, Completed: b.Completed, Abandoned: b.Abandoned}
				switch dim.name {
				case "device_class":
					s.By.DeviceClass = append(s.By.DeviceClass, item)
				case "logged_in":
					s.By.LoggedIn = append(s.By.LoggedIn, item)
				default:
					s.By.Variant = append(s.By.Variant, item)
				}
			}
		}
		for _, s := range steps {
			for _, list := range [][]Breakdown{s.By.DeviceClass, s.By.LoggedIn, s.By.Variant} {
				sort.SliceStable(list, func(i, j int) bool {
					if list[i].Entered != list[j].Entered {
						return list[i].Entered > list[j].Entered
					}
					return list[i].Key < list[j].Key
				})
			}
		}
	}

	names := make([]string, 0, len(byFlow))
	for f := range byFlow {
		names = append(names, f)
	}
	sort.Strings(names)

	out := make([]FunnelFlow, 0, len(names))
	for _, f := range names {
		list := byFlow[f]
		sort.SliceStable(list, func(i, j int) bool {
			if list[i].Entered != list[j].Entered {
				return list[i].Entered > list[j].Entered
			}
			return list[i].Step < list[j].Step
		})
		ff := FunnelFlow{Flow: f, Steps: make([]FunnelStep, 0, len(list))}
		for _, s := range list {
			ff.Steps = append(ff.Steps, *s)
		}
		out = append(out, ff)
	}
	return out, nil
}

// Funnels serves GET /modtools/ux/funnels.
var Funnels = serve("funnels", func(r request) (interface{}, error) {
	cur, err := funnelFlows(r.cur, r.scope(), true)
	if err != nil {
		return nil, err
	}
	prev, err := funnelFlows(r.prev, r.scope(), false)
	if err != nil {
		return nil, err
	}
	return FunnelsResponse{
		Start: r.cur.startStr(), End: r.cur.endStr(), Flows: cur,
		Previous: FlowsPeriod{Start: r.prev.startStr(), End: r.prev.endStr(), Flows: prev},
		Applied:  r.filter.applied("ux_flow_daily"),
	}, nil
})

// ---- outcomes ----

type OutcomeFlow struct {
	Flow            string `json:"flow" gorm:"column:flow"`
	Started         int64  `json:"started" gorm:"column:started"`
	Submitted       int64  `json:"submitted" gorm:"column:submitted"`
	SubmitOK        int64  `json:"submit_ok" gorm:"column:submit_ok"`
	SubmitFailed    int64  `json:"submit_failed" gorm:"column:submit_failed"`
	Completed       int64  `json:"completed" gorm:"column:completed"`
	Abandoned       int64  `json:"abandoned" gorm:"column:abandoned"`
	Resumed         int64  `json:"resumed" gorm:"column:resumed"`
	TotalDwellP50Ms *int64 `json:"total_dwell_p50_ms" gorm:"column:total_dwell_p50_ms"`
}

type OutcomesPeriod struct {
	Flows []OutcomeFlow `json:"flows"`
}

type OutcomesResponse struct {
	Start    string         `json:"start"`
	End      string         `json:"end"`
	Flows    []OutcomeFlow  `json:"flows"`
	Previous OutcomesPeriod `json:"previous"`
	Applied
}

func outcomeFlows(p period, s scope) ([]OutcomeFlow, error) {
	rows := []OutcomeFlow{}
	err := rangeQuery("ux_flow_outcome_daily", p, s).
		Select("flow, " +
			"CAST(SUM(started) AS SIGNED) AS started, CAST(SUM(submitted) AS SIGNED) AS submitted, " +
			"CAST(SUM(submit_ok) AS SIGNED) AS submit_ok, CAST(SUM(submit_failed) AS SIGNED) AS submit_failed, " +
			"CAST(SUM(completed) AS SIGNED) AS completed, CAST(SUM(abandoned) AS SIGNED) AS abandoned, " +
			"CAST(SUM(resumed) AS SIGNED) AS resumed, " + weighted("total_dwell_p50_ms", "started") + " AS total_dwell_p50_ms").
		Group("flow").Order("flow").Scan(&rows).Error
	return rows, err
}

// Outcomes serves GET /modtools/ux/outcomes.
var Outcomes = serve("outcomes", func(r request) (interface{}, error) {
	cur, err := outcomeFlows(r.cur, r.scope())
	if err != nil {
		return nil, err
	}
	prev, err := outcomeFlows(r.prev, r.scope())
	if err != nil {
		return nil, err
	}
	return OutcomesResponse{
		Start: r.cur.startStr(), End: r.cur.endStr(), Flows: cur, Previous: OutcomesPeriod{Flows: prev},
		Applied: r.filter.applied("ux_flow_outcome_daily"),
	}, nil
})

// ---- fields ----

type FieldRow struct {
	Flow               string `json:"flow" gorm:"column:flow"`
	Step               string `json:"step" gorm:"column:step"`
	Field              string `json:"field" gorm:"column:field"`
	Revisits           int64  `json:"revisits" gorm:"column:revisits"`
	ValidationFailures int64  `json:"validation_failures" gorm:"column:validation_failures"`
	ChoiceSwitches     int64  `json:"choice_switches" gorm:"column:choice_switches"`
}

type FieldsPeriod struct {
	Fields []FieldRow `json:"fields"`
}

type FieldsResponse struct {
	Start    string       `json:"start"`
	End      string       `json:"end"`
	Fields   []FieldRow   `json:"fields"`
	Previous FieldsPeriod `json:"previous"`
	Applied
}

func fieldRows(p period, s scope) ([]FieldRow, error) {
	rows := []FieldRow{}
	err := rangeQuery("ux_field_daily", p, s).
		Select("flow, step, field, " +
			"CAST(SUM(revisits) AS SIGNED) AS revisits, CAST(SUM(validation_failures) AS SIGNED) AS validation_failures, " +
			"CAST(SUM(choice_switches) AS SIGNED) AS choice_switches").
		Group("flow, step, field").
		Order("SUM(revisits) + SUM(validation_failures) DESC, flow, step, field").
		Limit(maxFieldRows).Scan(&rows).Error
	return rows, err
}

// Fields serves GET /modtools/ux/fields.
var Fields = serve("fields", func(r request) (interface{}, error) {
	cur, err := fieldRows(r.cur, r.scope())
	if err != nil {
		return nil, err
	}
	prev, err := fieldRows(r.prev, r.scope())
	if err != nil {
		return nil, err
	}
	return FieldsResponse{
		Start: r.cur.startStr(), End: r.cur.endStr(), Fields: cur, Previous: FieldsPeriod{Fields: prev},
		Applied: r.filter.applied("ux_field_daily"),
	}, nil
})

// ---- friction ----

type FrictionCounts struct {
	Impressions    int64 `json:"impressions"`
	Clicks         int64 `json:"clicks"`
	DeadClicks     int64 `json:"dead_clicks"`
	RageClicks     int64 `json:"rage_clicks"`
	RepeatedIntent int64 `json:"repeated_intent"`
}

type FrictionRow struct {
	Page           string         `json:"page"`
	Component      string         `json:"component"`
	Impressions    int64          `json:"impressions"`
	Clicks         int64          `json:"clicks"`
	DeadClicks     int64          `json:"dead_clicks"`
	RageClicks     int64          `json:"rage_clicks"`
	RepeatedIntent int64          `json:"repeated_intent"`
	Previous       FrictionCounts `json:"previous"`
	Change         float64        `json:"change"`
}

type FrictionResponse struct {
	Start      string        `json:"start"`
	End        string        `json:"end"`
	Components []FrictionRow `json:"components"`
	Applied
}

type frictionDB struct {
	Page           string `gorm:"column:page"`
	Component      string `gorm:"column:component"`
	Impressions    int64  `gorm:"column:impressions"`
	Clicks         int64  `gorm:"column:clicks"`
	DeadClicks     int64  `gorm:"column:dead_clicks"`
	RageClicks     int64  `gorm:"column:rage_clicks"`
	RepeatedIntent int64  `gorm:"column:repeated_intent"`
}

func frictionRows(p period, s scope) (map[[2]string]frictionDB, error) {
	var rows []frictionDB
	err := rangeQuery("ux_component_daily", p, s).
		Select("page, component, " +
			"CAST(SUM(impressions) AS SIGNED) AS impressions, CAST(SUM(clicks) AS SIGNED) AS clicks, " +
			"CAST(SUM(dead_clicks) AS SIGNED) AS dead_clicks, CAST(SUM(rage_clicks) AS SIGNED) AS rage_clicks, " +
			"CAST(SUM(repeated_intent) AS SIGNED) AS repeated_intent").
		Group("page, component").Scan(&rows).Error
	out := map[[2]string]frictionDB{}
	for _, r := range rows {
		out[[2]string{r.Page, r.Component}] = r
	}
	return out, err
}

// frictionRatio is the share of clicks that went wrong. A component nobody clicked divides by 1,
// so a rage click on something with no clicks still scores.
func frictionRatio(r frictionDB) float64 {
	clicks := r.Clicks
	if clicks < 1 {
		clicks = 1
	}
	return float64(r.RageClicks+r.DeadClicks+r.RepeatedIntent) / float64(clicks)
}

// Friction serves GET /modtools/ux/friction: the components whose trouble grew most. The top 20 is
// worked out from the rows the filter leaves.
var Friction = serve("friction", func(r request) (interface{}, error) {
	cur, err := frictionRows(r.cur, r.filterOnly())
	if err != nil {
		return nil, err
	}
	prev, err := frictionRows(r.prev, r.filterOnly())
	if err != nil {
		return nil, err
	}

	out := make([]FrictionRow, 0, len(cur))
	for k, c := range cur {
		pv := prev[k]
		out = append(out, FrictionRow{
			Page: c.Page, Component: c.Component, Impressions: c.Impressions, Clicks: c.Clicks,
			DeadClicks: c.DeadClicks, RageClicks: c.RageClicks, RepeatedIntent: c.RepeatedIntent,
			Previous: FrictionCounts{Impressions: pv.Impressions, Clicks: pv.Clicks, DeadClicks: pv.DeadClicks,
				RageClicks: pv.RageClicks, RepeatedIntent: pv.RepeatedIntent},
			Change: frictionRatio(c) - frictionRatio(pv),
		})
	}
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].Change != out[j].Change {
			return out[i].Change > out[j].Change
		}
		if out[i].Page != out[j].Page {
			return out[i].Page < out[j].Page
		}
		return out[i].Component < out[j].Component
	})
	if len(out) > frictionTop {
		out = out[:frictionTop]
	}
	return FrictionResponse{Start: r.cur.startStr(), End: r.cur.endStr(), Components: out, Applied: r.filter.applied("ux_component_daily")}, nil
})

// ---- adoption ----

type AdoptionRow struct {
	Feature       string  `json:"feature"`
	ActiveUsers   int64   `json:"active_users"`
	UsingUsers    int64   `json:"using_users"`
	SessionsUsing int64   `json:"sessions_using"`
	Share         float64 `json:"share"`
	PreviousShare float64 `json:"previous_share"`
}

type AdoptionResponse struct {
	Start    string        `json:"start"`
	End      string        `json:"end"`
	Features []AdoptionRow `json:"features"`
	Applied
}

type adoptionDB struct {
	Feature       string `gorm:"column:feature"`
	ActiveUsers   int64  `gorm:"column:active_users"`
	UsingUsers    int64  `gorm:"column:using_users"`
	SessionsUsing int64  `gorm:"column:sessions_using"`
}

func (a adoptionDB) share() float64 {
	if a.ActiveUsers <= 0 {
		return 0
	}
	return float64(a.UsingUsers) / float64(a.ActiveUsers)
}

func adoptionRows(p period) ([]adoptionDB, error) {
	var rows []adoptionDB
	err := rangeQuery("ux_feature_adoption_daily", p, scope{}).
		Select("feature, CAST(SUM(active_users) AS SIGNED) AS active_users, " +
			"CAST(SUM(using_users) AS SIGNED) AS using_users, CAST(SUM(sessions_using) AS SIGNED) AS sessions_using").
		Group("feature").Order("feature").Scan(&rows).Error
	return rows, err
}

// Adoption serves GET /modtools/ux/adoption. Share is the sum of daily using users over the sum of
// daily active users, so it is the average daily share rather than a count of distinct people.
var Adoption = serve("adoption", func(r request) (interface{}, error) {
	cur, err := adoptionRows(r.cur)
	if err != nil {
		return nil, err
	}
	prev, err := adoptionRows(r.prev)
	if err != nil {
		return nil, err
	}
	prevShare := map[string]float64{}
	for _, p := range prev {
		prevShare[p.Feature] = p.share()
	}
	out := make([]AdoptionRow, 0, len(cur))
	for _, c := range cur {
		out = append(out, AdoptionRow{Feature: c.Feature, ActiveUsers: c.ActiveUsers, UsingUsers: c.UsingUsers,
			SessionsUsing: c.SessionsUsing, Share: c.share(), PreviousShare: prevShare[c.Feature]})
	}
	return AdoptionResponse{Start: r.cur.startStr(), End: r.cur.endStr(), Features: out, Applied: r.filter.applied("ux_feature_adoption_daily")}, nil
})

// ---- regret ----

type RegretDay struct {
	Date  string `json:"date" gorm:"column:d"`
	Count int64  `json:"count" gorm:"column:cnt"`
	Base  int64  `json:"base" gorm:"column:base"`
}

type RegretSignal struct {
	Signal        string      `json:"signal"`
	Days          []RegretDay `json:"days"`
	Total         int64       `json:"total"`
	PreviousTotal int64       `json:"previous_total"`
}

type RegretResponse struct {
	Start   string         `json:"start"`
	End     string         `json:"end"`
	Signals []RegretSignal `json:"signals"`
	Applied
}

type regretDB struct {
	Signal string `gorm:"column:sig"`
	RegretDay
}

// Regret serves GET /modtools/ux/regret.
var Regret = serve("regret", func(r request) (interface{}, error) {
	var rows []regretDB
	if err := rangeQuery("ux_regret_daily", r.cur, scope{}).
		Select("`signal` AS sig, DATE_FORMAT(`date`, '%Y-%m-%d') AS d, `count` AS cnt, base").
		Order("`signal`, `date`").Scan(&rows).Error; err != nil {
		return nil, err
	}
	var prevRows []struct {
		Sig   string `gorm:"column:sig"`
		Total int64  `gorm:"column:total"`
	}
	if err := rangeQuery("ux_regret_daily", r.prev, scope{}).
		Select("`signal` AS sig, CAST(SUM(`count`) AS SIGNED) AS total").
		Group("`signal`").Scan(&prevRows).Error; err != nil {
		return nil, err
	}
	prevTotal := map[string]int64{}
	for _, p := range prevRows {
		prevTotal[p.Sig] = p.Total
	}

	out := []RegretSignal{}
	for _, row := range rows {
		if len(out) == 0 || out[len(out)-1].Signal != row.Signal {
			out = append(out, RegretSignal{Signal: row.Signal, Days: []RegretDay{}, PreviousTotal: prevTotal[row.Signal]})
		}
		s := &out[len(out)-1]
		s.Days = append(s.Days, row.RegretDay)
		s.Total += row.Count
	}
	return RegretResponse{Start: r.cur.startStr(), End: r.cur.endStr(), Signals: out, Applied: r.filter.applied("ux_regret_daily")}, nil
})

// ---- search ----

type SearchDay struct {
	Date              string `json:"date" gorm:"column:d"`
	Searches          int64  `json:"searches" gorm:"column:searches"`
	ZeroResult        int64  `json:"zero_result" gorm:"column:zero_result"`
	ReSearched60s     int64  `json:"re_searched_60s" gorm:"column:re_searched_60s"`
	BrowseSessions    int64  `json:"browse_sessions" gorm:"column:browse_sessions"`
	BrowseScrolledOut int64  `json:"browse_scrolled_out" gorm:"column:browse_scrolled_out"`
}

type SearchResponse struct {
	Start string      `json:"start"`
	End   string      `json:"end"`
	Days  []SearchDay `json:"days"`
	Applied
}

// Search serves GET /modtools/ux/search.
var Search = serve("search", func(r request) (interface{}, error) {
	days := []SearchDay{}
	if err := rangeQuery("ux_search_daily", r.cur, scope{}).
		Select("DATE_FORMAT(`date`, '%Y-%m-%d') AS d, searches, zero_result, re_searched_60s, browse_sessions, browse_scrolled_out").
		Order("`date`").Scan(&days).Error; err != nil {
		return nil, err
	}
	return SearchResponse{Start: r.cur.startStr(), End: r.cur.endStr(), Days: days, Applied: r.filter.applied("ux_search_daily")}, nil
})

// ---- health ----

type HealthDay struct {
	Date     string   `json:"date"`
	Expected *float64 `json:"expected"`
	Observed float64  `json:"observed"`
	Ratio    *float64 `json:"ratio"`
	Ok       bool     `json:"ok"`
}

type HealthMetric struct {
	Metric string      `json:"metric"`
	Ok     bool        `json:"ok"`
	Days   []HealthDay `json:"days"`
}

type HealthResponse struct {
	Start   string         `json:"start"`
	End     string         `json:"end"`
	Metrics []HealthMetric `json:"metrics"`
	Applied
}

// Observed against expected within this band is healthy; outside it the telemetry for that metric
// has probably stopped or doubled, and the charts built on it should not be trusted that day.
const (
	healthLow  = 0.7
	healthHigh = 1.3
)

// Health serves GET /modtools/ux/health.
var Health = serve("health", func(r request) (interface{}, error) {
	var rows []struct {
		Metric   string   `gorm:"column:metric"`
		D        string   `gorm:"column:d"`
		Expected *float64 `gorm:"column:expected"`
		Observed float64  `gorm:"column:observed"`
		Ratio    *float64 `gorm:"column:ratio"`
	}
	if err := rangeQuery("ux_telemetry_health_daily", r.cur, scope{}).
		Select("metric, DATE_FORMAT(`date`, '%Y-%m-%d') AS d, expected, observed, ratio").
		Order("metric, `date`").Scan(&rows).Error; err != nil {
		return nil, err
	}

	out := []HealthMetric{}
	for _, row := range rows {
		if len(out) == 0 || out[len(out)-1].Metric != row.Metric {
			out = append(out, HealthMetric{Metric: row.Metric, Ok: true, Days: []HealthDay{}})
		}
		m := &out[len(out)-1]
		ok := row.Ratio == nil || (*row.Ratio >= healthLow && *row.Ratio <= healthHigh)
		m.Days = append(m.Days, HealthDay{Date: row.D, Expected: row.Expected, Observed: row.Observed, Ratio: row.Ratio, Ok: ok})
		if !ok {
			m.Ok = false
		}
	}
	return HealthResponse{Start: r.cur.startStr(), End: r.cur.endStr(), Metrics: out, Applied: r.filter.applied("ux_telemetry_health_daily")}, nil
})
