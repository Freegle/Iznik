package ux

import (
	"encoding/json"
	"errors"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/go-sql-driver/mysql"
	"github.com/gofiber/fiber/v2"
	"gorm.io/plugin/dbresolver"
)

// The experiments routes: per funnel, what is running, what ran and how it ended. The rows are
// written by the Laravel batch (UxExperimentService) and by the two write handlers below. Starting,
// stopping and closing go through a background task so the batch's one implementation of the rules
// (the concurrency cap, the 24-hour end, the config key the client reads) is the only one.

const (
	// TaskUxExperiment is the background task the batch runs for start, stop and close.
	TaskUxExperiment = "ux_experiment"

	// maxArms is the most arms an experiment may have, as in UxExperimentService::MAX_ARMS. The
	// batch reads an experiment with one Loki query, and Loki refuses a query that comes back with
	// 500 series or more; five arms keep every funnel under that.
	maxArms = 5
)

var (
	experimentNameRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	armNameRe        = regexp.MustCompile(`^[A-Za-z0-9_]{1,16}$`)
	// These names are keys of the public config object the client reads. A name that is also an
	// Object.prototype key would be written into it and could shadow the lookup.
	reservedNames = map[string]bool{"__proto__": true, "constructor": true, "prototype": true}
	// Ended without being adopted. Closing one of these keeps its status and records the cleanup.
	endedUnadopted   = map[string]bool{"reverted": true, "inconclusive": true, "stopped": true}
	experimentStates = map[string]bool{"draft": true, "running": true, "adopted": true, "reverted": true, "inconclusive": true, "stopped": true, "closed": true}
)

type Experiment struct {
	ID                uint64          `json:"id"`
	Flow              string          `json:"flow"`
	Name              string          `json:"name"`
	Title             string          `json:"title"`
	Hypothesis        string          `json:"hypothesis"`
	ChangeDescription string          `json:"change"`
	Expected          string          `json:"expected"`
	Arms              json.RawMessage `json:"arms"`
	Status            string          `json:"status"`
	StartedAt         *string         `json:"started_at"`
	EndsAt            *string         `json:"ends_at"`
	DecidedAt         *string         `json:"decided_at"`
	Decision          *string         `json:"decision"`
	// Result is the whole structured read (arms, primary, guardrails, allocation). The hourly tick
	// rewrites it while the experiment runs, a start resets it to the start facts, and a finished
	// experiment keeps its final read in it. A Result with no arms means no read yet.
	Result       json.RawMessage `json:"result,omitempty"`
	PrURL        *string         `json:"pr_url"`
	CleanupPrURL *string         `json:"cleanup_pr_url"`
	CreatedAt    string          `json:"created_at"`
	// Design is what the batch keeps about how the test is read: the flows it can affect
	// (touches), the effect it was sized for (expected_points) and its power.
	Design json.RawMessage `json:"design,omitempty"`
	// LatestRead is the paragraph of the newest hourly read taken since the last start, and Hours
	// how long it has run.
	LatestRead *ExperimentRead `json:"latest_read"`
	Hours      float64         `json:"hours"`
	// CleanupOverdue is true for an adopted experiment whose switch is still in the code after
	// the grace period, and at once for a reverted, inconclusive or stopped one with no cleanup
	// recorded, so a caller can list the ones nobody has cleaned up.
	CleanupOverdue bool `json:"cleanup_overdue"`
	// HoldoutUntil is set while an adopted experiment is still inside its holdout: a share of
	// members keeps the old version and the batch can still reverse the adoption. Closing the
	// experiment ends the holdout.
	HoldoutUntil *string `json:"holdout_until"`
}

type ExperimentRead struct {
	ReadAt  string  `json:"read_at"`
	Hours   float64 `json:"hours"`
	Summary string  `json:"summary"`
}

type FlowExperiments struct {
	Flow    string       `json:"flow"`
	Running []Experiment `json:"running"`
	History []Experiment `json:"history"`
}

type ExperimentsResponse struct {
	Flows        []FlowExperiments `json:"flows"`
	RunningCount int64             `json:"running_count"`
	MaxRunning   int64             `json:"max_running"`
}

type experimentRow struct {
	ID                uint64     `gorm:"column:id"`
	Flow              string     `gorm:"column:flow"`
	Name              string     `gorm:"column:name"`
	Title             string     `gorm:"column:title"`
	Hypothesis        string     `gorm:"column:hypothesis"`
	ChangeDescription string     `gorm:"column:change_description"`
	Expected          string     `gorm:"column:expected"`
	Arms              string     `gorm:"column:arms"`
	Status            string     `gorm:"column:status"`
	StartedAt         *time.Time `gorm:"column:started_at"`
	EndsAt            *time.Time `gorm:"column:ends_at"`
	DecidedAt         *time.Time `gorm:"column:decided_at"`
	Decision          *string    `gorm:"column:decision"`
	Result            *string    `gorm:"column:result"`
	Design            *string    `gorm:"column:design"`
	PrURL             *string    `gorm:"column:pr_url"`
	CleanupPrURL      *string    `gorm:"column:cleanup_pr_url"`
	CreatedAt         time.Time  `gorm:"column:created_at"`
}

// envInt reads a whole-number setting from the environment, so the routes report the same cap and
// grace period as the batch that enforces them (UX_EXPERIMENTS_MAX_CONCURRENT and
// UX_EXPERIMENTS_CLEANUP_DAYS, defaulting alike in iznik-batch/config/freegle.php). Set each of
// these variables on both sides or on neither.
func envInt(name string, def int) int {
	if v, err := strconv.Atoi(strings.TrimSpace(os.Getenv(name))); err == nil && v > 0 {
		return v
	}
	return def
}

// Experiments lists, per funnel, the running experiments and the past ones (drafts sit with the
// past ones), each with the paragraph of its newest read since its last start. Support/Admin only.
//
// Everything is read in a few queries, not one per experiment: an experiment history grows without
// limit.
// @Router /modtools/ux/experiments [get]
func Experiments(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	flow, err := parseFlow(c)
	if err != nil {
		return err
	}

	db := database.DBConn
	now := time.Now().UTC()
	cleanupDays := envInt("UX_EXPERIMENTS_CLEANUP_DAYS", 7)
	maxRunning := envInt("UX_EXPERIMENTS_MAX_CONCURRENT", 5)
	holdoutDays := envInt("UX_EXPERIMENTS_HOLDOUT_DAYS", 14)
	// A holdout share of 0 means no holdout at all (the batch's own rule), which envInt cannot say.
	holdoutOn := strings.TrimSpace(os.Getenv("UX_EXPERIMENTS_HOLDOUT_SHARE")) != "0"

	var rows []experimentRow
	q := db.Table("ux_experiments").Order("id DESC")
	if flow != "" {
		q = q.Where("flow = ?", flow)
	}
	if err := q.Scan(&rows).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read experiments")
	}

	byFlow := map[string]*FlowExperiments{}
	order := []string{}
	get := func(f string) *FlowExperiments {
		if fe, ok := byFlow[f]; ok {
			return fe
		}
		fe := &FlowExperiments{Flow: f, Running: []Experiment{}, History: []Experiment{}}
		byFlow[f] = fe
		order = append(order, f)
		return fe
	}
	// Every known flow has an entry, even an empty one, so the answer has the same shape every time.
	for _, f := range []string{"give", "ask", "login", "reply", "chat_promise", "chat_outcome", "settings"} {
		if flow == "" || flow == f {
			get(f)
		}
	}

	ids := make([]uint64, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	reads := latestReads(ids)

	var running int64
	for _, r := range rows {
		e := Experiment{
			ID: r.ID, Flow: r.Flow, Name: r.Name, Title: r.Title, Hypothesis: r.Hypothesis,
			ChangeDescription: r.ChangeDescription, Expected: r.Expected, Arms: json.RawMessage(r.Arms),
			Status: r.Status, StartedAt: tstr(r.StartedAt), EndsAt: tstr(r.EndsAt), DecidedAt: tstr(r.DecidedAt),
			Decision: r.Decision, PrURL: r.PrURL, CleanupPrURL: r.CleanupPrURL, CreatedAt: r.CreatedAt.UTC().Format(timeFormat),
		}
		if r.Result != nil && json.Valid([]byte(*r.Result)) {
			e.Result = json.RawMessage(*r.Result)
		}
		if r.Design != nil && json.Valid([]byte(*r.Design)) {
			e.Design = json.RawMessage(*r.Design)
		}
		if r.StartedAt != nil {
			end := now
			if r.DecidedAt != nil {
				end = *r.DecidedAt
			}
			e.Hours = float64(int(end.Sub(*r.StartedAt).Minutes())) / 60
		}
		if r.Status == "adopted" && r.DecidedAt != nil && now.Sub(*r.DecidedAt) > time.Duration(cleanupDays)*24*time.Hour {
			e.CleanupOverdue = true
		}
		// Reverted, inconclusive and stopped serve control to everyone, so the variant code is dead at once.
		if endedUnadopted[r.Status] && (r.CleanupPrURL == nil || *r.CleanupPrURL == "") {
			e.CleanupOverdue = true
		}
		if r.Status == "adopted" && r.DecidedAt != nil && holdoutOn {
			if until := r.DecidedAt.Add(time.Duration(holdoutDays) * 24 * time.Hour); until.After(now) {
				s := until.UTC().Format(timeFormat)
				e.HoldoutUntil = &s
			}
		}
		e.LatestRead = reads[r.ID]

		fe := get(r.Flow)
		if r.Status == "running" {
			running++
			fe.Running = append(fe.Running, e)
		} else {
			// Drafts sit with history so a prepared experiment is visible before it starts.
			fe.History = append(fe.History, e)
		}
	}

	out := ExperimentsResponse{Flows: []FlowExperiments{}, RunningCount: running, MaxRunning: int64(maxRunning)}
	for _, f := range order {
		out.Flows = append(out.Flows, *byFlow[f])
	}
	return c.JSON(out)
}

// VerdictCounts is the SysAdmin nudge: how many experiments are waiting for a person now that they
// have a verdict. confirm is adopted ones not yet closed (confirm the result and remove the
// switch); remove is reverted, inconclusive or stopped ones with no cleanup recorded (remove the
// variant code). A closed experiment, or one with a cleanup pull request, is done and not counted.
//
// This is wider than cleanup_overdue on purpose. An adopted experiment is overdue only after the
// grace period (UX_EXPERIMENTS_CLEANUP_DAYS), but the nudge is for the moment a verdict exists, so
// an adopted one counts at once. For an ended one the two agree.
func VerdictCounts() (confirm int64, remove int64) {
	var rows []struct {
		Status string `gorm:"column:status"`
		N      int64  `gorm:"column:n"`
	}
	err := database.DBConn.Table("ux_experiments").
		Select("status, COUNT(*) AS n").
		Where("status IN ? AND (cleanup_pr_url IS NULL OR cleanup_pr_url = '')", []string{"adopted", "reverted", "inconclusive", "stopped"}).
		Group("status").
		Scan(&rows).Error
	if err != nil {
		return 0, 0
	}
	for _, r := range rows {
		if r.Status == "adopted" {
			confirm += r.N
		} else {
			remove += r.N
		}
	}
	return confirm, remove
}

// latestReads returns, for each experiment, the paragraph of its newest hourly read taken at or
// after the experiment's started_at, in one query. A restarted experiment keeps the reads of the run
// before in the table, so without the cut it would show the text of a run it is no longer in; with
// it, the experiment has no read until the first new one. A draft has no started_at and so no read.
func latestReads(ids []uint64) map[uint64]*ExperimentRead {
	out := map[uint64]*ExperimentRead{}
	if len(ids) == 0 {
		return out
	}
	var rows []struct {
		ID           uint64    `gorm:"column:id"`
		ExperimentID uint64    `gorm:"column:experiment_id"`
		ReadAt       time.Time `gorm:"column:read_at"`
		Hours        float64   `gorm:"column:hours"`
		Summary      string    `gorm:"column:summary"`
	}
	database.DBConn.Table("ux_experiment_reads r").
		Select("r.id, r.experiment_id, r.read_at, r.hours, r.summary").
		Joins("INNER JOIN ux_experiments e ON e.id = r.experiment_id AND r.read_at >= e.started_at").
		Joins("INNER JOIN (SELECT r2.experiment_id, MAX(r2.read_at) AS latest FROM ux_experiment_reads r2 INNER JOIN ux_experiments e2 ON e2.id = r2.experiment_id AND r2.read_at >= e2.started_at WHERE r2.experiment_id IN ? GROUP BY r2.experiment_id) x ON x.experiment_id = r.experiment_id AND x.latest = r.read_at", ids).
		Order("r.id ASC").Scan(&rows)
	// Two reads in the same second: the later row wins, because the rows arrive in id order.
	for _, r := range rows {
		out[r.ExperimentID] = &ExperimentRead{ReadAt: r.ReadAt.UTC().Format(timeFormat), Hours: r.Hours, Summary: r.Summary}
	}
	return out
}

// ---- writes ----

type CreateExperimentRequest struct {
	Flow       string `json:"flow"`
	Name       string `json:"name"`
	Title      string `json:"title"`
	Hypothesis string `json:"hypothesis"`
	Change     string `json:"change"`
	Expected   string `json:"expected"`
	// Arms maps an arm name to its [from, to] slice of the 0 to 99 bucket. A slice, not an array,
	// so a range with three numbers or one is refused instead of being cut or padded to two.
	Arms map[string][]int `json:"arms"`
	// PrURL is a link to the code change. It is drawn as a link, so it must start with https://.
	PrURL string `json:"pr_url"`
	// Design is optional: touches (the flows the change can affect, for the guardrails),
	// expected_points (the effect the test is sized for), primary_event (a client event the test is
	// judged on instead of finishing its funnel) and base_rate (that event's usual rate per start).
	Design *CreateExperimentDesign `json:"design"`
}

type CreateExperimentDesign struct {
	Touches        []string `json:"touches"`
	ExpectedPoints *float64 `json:"expected_points"`
	PrimaryEvent   string   `json:"primary_event"`
	BaseRate       *float64 `json:"base_rate"`
}

// primaryEvents are the client events a web experiment may be judged on, the same list as
// UxExperimentService::PRIMARY_EVENTS in the batch. The name reaches a LogQL query there.
var primaryEvents = map[string]bool{"job_ad_click": true, "job_ad_click_chat": true, "app_store_click": true, "user_rated": true}

// validArms checks arms the way the batch does: at most maxArms, names, ranges inside 0..99, no overlap, a control
// and at least one variant. Returns the arms as JSON.
func validArms(arms map[string][]int) (string, string) {
	if len(arms) == 0 {
		return `{"control":[0,49],"b":[50,99]}`, ""
	}
	if len(arms) > maxArms {
		return "", "an experiment has at most " + strconv.Itoa(maxArms) + " arms"
	}
	covered := [100]bool{}
	for name, r := range arms {
		if !armNameRe.MatchString(name) {
			return "", "arm names are letters, digits and _"
		}
		if len(r) != 2 {
			return "", "arm " + name + " must be a [from, to] range of exactly two numbers"
		}
		if r[0] < 0 || r[1] > 99 || r[0] > r[1] {
			return "", "arm " + name + " must be a [from, to] range within 0 to 99"
		}
		for i := r[0]; i <= r[1]; i++ {
			if covered[i] {
				return "", "arm " + name + " overlaps another arm"
			}
			covered[i] = true
		}
	}
	if _, ok := arms["control"]; !ok {
		return "", "arms must include control"
	}
	if len(arms) < 2 {
		return "", "arms must include a variant"
	}
	b, _ := json.Marshal(arms)
	return string(b), ""
}

// validDesign checks the optional design and returns it as JSON, or "" for none.
func validDesign(d *CreateExperimentDesign) (string, string) {
	if d == nil {
		return "", ""
	}
	out := map[string]interface{}{}
	if len(d.Touches) > 0 {
		seen := map[string]bool{}
		touches := []string{}
		for _, f := range d.Touches {
			if !knownFlows[f] {
				return "", "touches must be flows: give, ask, reply, login, chat_promise, chat_outcome, settings"
			}
			if !seen[f] {
				seen[f] = true
				touches = append(touches, f)
			}
		}
		out["touches"] = touches
	}
	if d.ExpectedPoints != nil {
		if *d.ExpectedPoints < 0.1 || *d.ExpectedPoints > 50 {
			return "", "expected_points must be between 0.1 and 50"
		}
		out["expected_points"] = *d.ExpectedPoints
	}
	if d.PrimaryEvent != "" {
		if !primaryEvents[d.PrimaryEvent] {
			return "", "primary_event must be one of: job_ad_click, job_ad_click_chat, app_store_click, user_rated"
		}
		out["primary_event"] = d.PrimaryEvent
	}
	if d.BaseRate != nil {
		if *d.BaseRate <= 0 || *d.BaseRate >= 1 {
			return "", "base_rate must be a share between 0 and 1"
		}
		out["base_rate"] = *d.BaseRate
	}
	if len(out) == 0 {
		return "", ""
	}
	b, _ := json.Marshal(out)
	return string(b), ""
}

// isDuplicateKey is true for MySQL's duplicate entry error (1062), which gorm wraps.
func isDuplicateKey(err error) bool {
	var me *mysql.MySQLError
	return errors.As(err, &me) && me.Number == 1062
}

// isHTTPS is true for an address that starts with https://. It is checked on the text as sent, so
// a leading space does not pass.
func isHTTPS(address string) bool {
	return strings.HasPrefix(address, "https://")
}

// CreateExperiment adds a draft. Support/Admin only. Keys in the body that the engine does not know
// are ignored, so a caller written for an earlier shape still works.
// @Router /modtools/ux/experiments [post]
func CreateExperiment(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	var req CreateExperimentRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if !knownFlows[req.Flow] {
		return fiber.NewError(fiber.StatusBadRequest, "flow must be one of give, ask, reply, login, chat_promise, chat_outcome, settings")
	}
	if !experimentNameRe.MatchString(req.Name) || reservedNames[req.Name] {
		return fiber.NewError(fiber.StatusBadRequest, "name must be 1 to 64 letters, digits, _ or -, and not a reserved word")
	}
	for k, v := range map[string]string{"title": req.Title, "hypothesis": req.Hypothesis, "change": req.Change, "expected": req.Expected} {
		if strings.TrimSpace(v) == "" {
			return fiber.NewError(fiber.StatusBadRequest, k+" is required")
		}
	}
	if req.PrURL != "" && !isHTTPS(req.PrURL) {
		return fiber.NewError(fiber.StatusBadRequest, "pr_url must start with https://")
	}
	arms, msg := validArms(req.Arms)
	if msg != "" {
		return fiber.NewError(fiber.StatusBadRequest, msg)
	}
	design, msg := validDesign(req.Design)
	if msg != "" {
		return fiber.NewError(fiber.StatusBadRequest, msg)
	}

	db := database.DBConn
	// The unique index on name is the only check for a duplicate: a count first would let a
	// lost race between two creates reach the insert only sometimes, so the 1062 path below was
	// covered on some runs and not others.
	now := time.Now().UTC()
	row := map[string]interface{}{
		"flow": req.Flow, "name": req.Name, "title": clip(req.Title, 160), "hypothesis": strings.TrimSpace(req.Hypothesis),
		"change_description": strings.TrimSpace(req.Change), "expected": strings.TrimSpace(req.Expected), "arms": arms,
		"status": "draft", "created_at": now, "updated_at": now,
	}
	if design != "" {
		row["design"] = design
	}
	if req.PrURL != "" {
		row["pr_url"] = clip(req.PrURL, 255)
	}

	if err := db.Table("ux_experiments").Create(row).Error; err != nil {
		if isDuplicateKey(err) {
			return fiber.NewError(fiber.StatusConflict, "An experiment with that name already exists")
		}
		return fiber.NewError(fiber.StatusInternalServerError, "Could not create the experiment")
	}
	// The row was just written to the primary, so its id is read from the primary too: a replica
	// that has not caught up would answer 0, and the caller starts the experiment by that id.
	var id uint64
	db.Clauses(dbresolver.Write).Table("ux_experiments").Select("id").Where("name = ?", req.Name).Scan(&id)
	return c.JSON(fiber.Map{"id": id, "status": "draft"})
}

type ExperimentActionRequest struct {
	Action    string `json:"action"`
	Reason    string `json:"reason"`
	CleanupPr string `json:"cleanup_pr"`
}

// ExperimentAction asks the batch to start, stop or close an experiment. The request is queued
// as a background task the batch runs within a minute, so the rules live in one place.
// @Router /modtools/ux/experiments/{id} [patch]
func ExperimentAction(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	id, err := c.ParamsInt("id")
	if err != nil || id <= 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid experiment id")
	}
	var req ExperimentActionRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if req.Action != "start" && req.Action != "stop" && req.Action != "close" {
		return fiber.NewError(fiber.StatusBadRequest, "action must be start, stop or close")
	}
	if req.CleanupPr != "" && !isHTTPS(req.CleanupPr) {
		return fiber.NewError(fiber.StatusBadRequest, "cleanup_pr must start with https://")
	}

	db := database.DBConn
	// Read the row from the primary. A caller that has just created the experiment sends this
	// request straight after, and a read replica that does not hold the row yet would answer 404.
	var row struct {
		Status       string
		CleanupPrURL *string `gorm:"column:cleanup_pr_url"`
	}
	res := db.Clauses(dbresolver.Write).Table("ux_experiments").Select("status, cleanup_pr_url").Where("id = ?", id).Scan(&row)
	if res.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "No such experiment")
	}
	status := row.Status
	switch req.Action {
	case "start":
		if status != "draft" && status != "stopped" && status != "inconclusive" && status != "reverted" {
			return fiber.NewError(fiber.StatusBadRequest, "Only a draft or a finished experiment can be started")
		}
		if row.CleanupPrURL != nil && *row.CleanupPrURL != "" {
			return fiber.NewError(fiber.StatusBadRequest, "This experiment's code has been removed, so it cannot be started again. Use a new name, or put the variant back and re-run it with a new salt")
		}
	case "stop":
		if status != "running" {
			return fiber.NewError(fiber.StatusBadRequest, "Only a running experiment can be stopped")
		}
	case "close":
		if status != "adopted" && !endedUnadopted[status] {
			return fiber.NewError(fiber.StatusBadRequest, "Only an adopted, reverted, inconclusive or stopped experiment can be closed")
		}
		if endedUnadopted[status] && req.CleanupPr == "" {
			return fiber.NewError(fiber.StatusBadRequest, "Give the cleanup pull request that removed the code")
		}
	}

	if err := queue.QueueTask(TaskUxExperiment, map[string]interface{}{
		"id": id, "action": req.Action, "reason": clip(req.Reason, 500), "cleanup_pr": clip(req.CleanupPr, 255),
	}); err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not queue the request")
	}
	return c.JSON(fiber.Map{"queued": true, "action": req.Action, "id": id})
}
