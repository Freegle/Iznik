// Package supportai records each question put to the AI Support Helper and the
// volunteer's thumbs up or down on the answer, and lists them most recent first
// for ModTools SysAdmin so that cases where the helper did badly can be found
// and improved.
//
// The helper (claude-agent-sdk) records a run here, with the asking
// volunteer's own JWT, because its database connection is a read-only grant.
// Everything is Support/Admin only, the same people who can use the helper.
package supportai

import (
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/plugin/dbresolver"
)

// maxTranscriptBytes bounds what one run can put into the table. The helper
// already caps each tool result; this is the backstop.
const maxTranscriptBytes = 2 * 1024 * 1024

// Run is one row of support_ai_runs.
type Run struct {
	ID                  uint64     `json:"id" gorm:"primaryKey"`
	Modid               *uint64    `json:"modid"`
	Userid              *uint64    `json:"userid"`
	Sessionid           *string    `json:"sessionid"`
	Query               string     `json:"query"`
	Analysis            *string    `json:"analysis"`
	Transcript          *string    `json:"transcript,omitempty"`
	Status              string     `json:"status"`
	Error               *string    `json:"error"`
	Driver              *string    `json:"driver"`
	Model               *string    `json:"model"`
	InputTokens         uint64     `json:"input_tokens"`
	OutputTokens        uint64     `json:"output_tokens"`
	CacheCreationTokens uint64     `json:"cache_creation_tokens"`
	CacheReadTokens     uint64     `json:"cache_read_tokens"`
	DurationMs          uint64     `json:"duration_ms"`
	CostUsd             float64    `json:"cost_usd"`
	Quota5hBefore       *float64   `json:"quota_5h_before" gorm:"column:quota_5h_before"`
	Quota5hAfter        *float64   `json:"quota_5h_after" gorm:"column:quota_5h_after"`
	Quota7dBefore       *float64   `json:"quota_7d_before" gorm:"column:quota_7d_before"`
	Quota7dAfter        *float64   `json:"quota_7d_after" gorm:"column:quota_7d_after"`
	Rating              *int       `json:"rating"`
	RatingComment       *string    `json:"rating_comment"`
	Ratedby             *uint64    `json:"ratedby"`
	RatedAt             *time.Time `json:"rated_at"`
	CreatedAt           time.Time  `json:"created_at"`
}

func (Run) TableName() string {
	return "support_ai_runs"
}

// ListedRun is a run as the SysAdmin list shows it: no transcript, plus the
// names of who asked and who it was about.
type ListedRun struct {
	Run
	Modname  string `json:"modname"`
	Username string `json:"username"`
}

// RecordRequest is what the helper sends after each question. The asking
// volunteer is taken from the JWT, never from the body.
type RecordRequest struct {
	Userid              uint64   `json:"userid"`
	Sessionid           string   `json:"sessionid"`
	Query               string   `json:"query"`
	Analysis            string   `json:"analysis"`
	Transcript          string   `json:"transcript"`
	Status              string   `json:"status"`
	Error               string   `json:"error"`
	Driver              string   `json:"driver"`
	Model               string   `json:"model"`
	InputTokens         uint64   `json:"input_tokens"`
	OutputTokens        uint64   `json:"output_tokens"`
	CacheCreationTokens uint64   `json:"cache_creation_tokens"`
	CacheReadTokens     uint64   `json:"cache_read_tokens"`
	DurationMs          uint64   `json:"duration_ms"`
	CostUsd             float64  `json:"cost_usd"`
	Quota5hBefore       *float64 `json:"quota_5h_before"`
	Quota5hAfter        *float64 `json:"quota_5h_after"`
	Quota7dBefore       *float64 `json:"quota_7d_before"`
	Quota7dAfter        *float64 `json:"quota_7d_after"`
}

// RateRequest is a thumbs up (1), thumbs down (-1) or cleared rating (0), with
// an optional comment saying what was wrong or right.
type RateRequest struct {
	ID      uint64  `json:"id"`
	Rating  int     `json:"rating"`
	Comment *string `json:"comment"`
}

func requireSupport(c *fiber.Ctx) (uint64, error) {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return 0, fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !auth.IsAdminOrSupport(myid) {
		return 0, fiber.NewError(fiber.StatusForbidden, "Support or admin required")
	}
	return myid, nil
}

func nullableString(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

// Record stores one run of the helper and returns its id, which the helper
// passes back to the browser so the volunteer can rate it.
//
// @Summary Record an AI Support Helper run
// @Tags supportai
// @Router /supportai/runs [post]
func Record(c *fiber.Ctx) error {
	myid, err := requireSupport(c)
	if err != nil {
		return err
	}

	var req RecordRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if strings.TrimSpace(req.Query) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "query is required")
	}

	status := "Success"
	if req.Status == "Error" {
		status = "Error"
	}

	transcript := req.Transcript
	if len(transcript) > maxTranscriptBytes {
		transcript = transcript[:maxTranscriptBytes]
	}

	run := Run{
		Modid:               &myid,
		Sessionid:           nullableString(req.Sessionid),
		Query:               req.Query,
		Analysis:            nullableString(req.Analysis),
		Transcript:          nullableString(transcript),
		Status:              status,
		Error:               nullableString(req.Error),
		Driver:              nullableString(req.Driver),
		Model:               nullableString(req.Model),
		InputTokens:         req.InputTokens,
		OutputTokens:        req.OutputTokens,
		CacheCreationTokens: req.CacheCreationTokens,
		CacheReadTokens:     req.CacheReadTokens,
		DurationMs:          req.DurationMs,
		CostUsd:             req.CostUsd,
		Quota5hBefore:       req.Quota5hBefore,
		Quota5hAfter:        req.Quota5hAfter,
		Quota7dBefore:       req.Quota7dBefore,
		Quota7dAfter:        req.Quota7dAfter,
		CreatedAt:           time.Now(),
	}
	if req.Userid > 0 {
		uid := req.Userid
		run.Userid = &uid
	}

	if err := database.DBConn.Create(&run).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to record run")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": run.ID})
}

// Rate sets or clears the thumbs up/down on a run. Any Support or Admin
// volunteer may rate, so a run can also be judged when it is reviewed later.
//
// @Summary Rate an AI Support Helper run
// @Tags supportai
// @Router /supportai/runs [patch]
func Rate(c *fiber.Ctx) error {
	myid, err := requireSupport(c)
	if err != nil {
		return err
	}

	var req RateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}
	if req.Rating != 1 && req.Rating != -1 && req.Rating != 0 {
		return fiber.NewError(fiber.StatusBadRequest, "rating must be 1, -1 or 0")
	}

	updates := map[string]interface{}{}
	if req.Rating == 0 {
		updates["rating"] = nil
		updates["ratedby"] = nil
		updates["rated_at"] = nil
	} else {
		updates["rating"] = req.Rating
		updates["ratedby"] = myid
		updates["rated_at"] = time.Now()
	}
	if req.Comment != nil {
		updates["rating_comment"] = nullableString(strings.TrimSpace(*req.Comment))
	}

	res := database.DBConn.Table("support_ai_runs").Where("id = ?", req.ID).Updates(updates)
	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to rate run")
	}
	if res.RowsAffected == 0 {
		// Updates reports 0 rows both for a missing id and for a rating that
		// did not change, so tell them apart before calling it missing. Read
		// the writer: the run may have been recorded a moment ago.
		var count int64
		database.DBConn.Clauses(dbresolver.Write).Table("support_ai_runs").Where("id = ?", req.ID).Count(&count)
		if count == 0 {
			return fiber.NewError(fiber.StatusNotFound, "Run not found")
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

const nameSQL = "COALESCE(NULLIF(%s.fullname, ''), NULLIF(TRIM(CONCAT(COALESCE(%s.firstname, ''), ' ', COALESCE(%s.lastname, ''))), ''), '')"

func nameOf(alias string) string {
	return strings.ReplaceAll(nameSQL, "%s", alias)
}

// List returns runs most recent first, without their transcripts. Page back
// with ?before=<id>; filter with ?rating=up|down|unrated.
//
// @Summary List AI Support Helper runs
// @Tags supportai
// @Router /supportai/runs [get]
func List(c *fiber.Ctx) error {
	if _, err := requireSupport(c); err != nil {
		return err
	}

	limit, _ := strconv.Atoi(c.Query("limit", "50"))
	if limit < 1 || limit > 200 {
		limit = 50
	}

	q := database.DBConn.Table("support_ai_runs r").
		Select("r.id, r.modid, r.userid, r.sessionid, r.query, r.analysis, r.status, r.error, r.driver, r.model, " +
			"r.input_tokens, r.output_tokens, r.cache_creation_tokens, r.cache_read_tokens, r.duration_ms, r.cost_usd, " +
			"r.quota_5h_before, r.quota_5h_after, r.quota_7d_before, r.quota_7d_after, " +
			"r.rating, r.rating_comment, r.ratedby, r.rated_at, r.created_at, " +
			nameOf("m") + " AS modname, " + nameOf("u") + " AS username").
		Joins("LEFT JOIN users m ON m.id = r.modid").
		Joins("LEFT JOIN users u ON u.id = r.userid")

	if before, _ := strconv.ParseUint(c.Query("before"), 10, 64); before > 0 {
		q = q.Where("r.id < ?", before)
	}
	switch c.Query("rating") {
	case "up":
		q = q.Where("r.rating = 1")
	case "down":
		q = q.Where("r.rating = -1")
	case "unrated":
		q = q.Where("r.rating IS NULL")
	}

	runs := []ListedRun{}
	if err := q.Order("r.id DESC").Limit(limit).Scan(&runs).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to list runs")
	}

	return c.JSON(runs)
}

// Get returns one run in full, transcript included.
//
// @Summary Get an AI Support Helper run
// @Tags supportai
// @Router /supportai/runs/{id} [get]
func Get(c *fiber.Ctx) error {
	if _, err := requireSupport(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	var runs []ListedRun
	err := database.DBConn.Table("support_ai_runs r").
		Select("r.*, "+nameOf("m")+" AS modname, "+nameOf("u")+" AS username").
		Joins("LEFT JOIN users m ON m.id = r.modid").
		Joins("LEFT JOIN users u ON u.id = r.userid").
		Where("r.id = ?", id).
		Limit(1).
		Scan(&runs).Error
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to fetch run")
	}
	if len(runs) == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Run not found")
	}

	return c.JSON(runs[0])
}
