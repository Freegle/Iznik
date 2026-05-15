package job

import (
	"database/sql"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/gofiber/fiber/v2"
	"regexp"
	"strconv"
	"strings"
)

// categorySanitizer removes non-letter/space/slash characters from category queries.
var categorySanitizer = regexp.MustCompile(`[^a-zA-Z/ ]+`)

type Job struct {
	ID             uint64  `json:"id" gorm:"primary_key"`
	Ambit          float64 `json:"ambit"`
	Dist           float64 `json:"dist"`
	Area           float64 `json:"area"`
	Url            string  `json:"url"`
	Title          string  `json:"title"`
	Location       string  `json:"location"`
	Body           string  `json:"body"`
	Reference      string  `json:"job_reference"`
	Category       string  `json:"category"`
	CPC            float64 `json:"cpc"`
	Clickability   float64 `json:"clickability"`
	Expectation    float64 `json:"expectation"`
	Image          string  `json:"image,omitempty"`
	CanonicalTitle string  `json:"-"`
}

const JOBS_LIMIT = 50
const JOBS_DISTANCE = 64
const JOBS_MINIMUM_CPC = 0.10

func GetJobs(c *fiber.Ctx) error {
	lat, _ := strconv.ParseFloat(c.Query("lat"), 64)
	lng, _ := strconv.ParseFloat(c.Query("lng"), 64)
	category := categorySanitizer.ReplaceAllString(c.Query("category", ""), "")

	if lat == 0 && lng == 0 {
		return c.JSON(make([]Job, 0))
	}

	// Ask spatial server for nearest job IDs.
	knnResults, err := spatial.KNN("jobs", lng, lat, JOBS_LIMIT, "")
	if err != nil || len(knnResults) == 0 {
		return c.JSON(make([]Job, 0))
	}

	// Build a map of id→distance for the enrichment query.
	distByID := make(map[int64]float64, len(knnResults))
	ids := make([]string, 0, len(knnResults))
	for _, r := range knnResults {
		distByID[r.ID] = r.Distance
		ids = append(ids, strconv.FormatInt(r.ID, 10))
	}
	placeholders := strings.Join(ids, ",")

	categoryClause := "category IS NOT NULL"
	if category != "" {
		categoryClause = "category REGEXP '(^|;)" + category + ".*'"
	}

	db := database.DBConn
	var rows []struct {
		ID           uint64         `gorm:"column:id"`
		Url          string         `gorm:"column:url"`
		Title        string         `gorm:"column:title"`
		Location     string         `gorm:"column:location"`
		Body         string         `gorm:"column:body"`
		Reference    string         `gorm:"column:job_reference"`
		Category     string         `gorm:"column:category"`
		CPC          float64        `gorm:"column:cpc"`
		Clickability float64        `gorm:"column:clickability"`
		Externaluid  sql.NullString `gorm:"column:externaluid"`
	}

	db.Raw(fmt.Sprintf(
		"SELECT jobs.id, jobs.url, jobs.title, jobs.location, jobs.body, jobs.job_reference, "+
			"jobs.category, jobs.cpc, jobs.clickability, ai_images.externaluid "+
			"FROM `jobs` LEFT JOIN ai_images ON ai_images.name = jobs.canonical_title "+
			"WHERE jobs.id IN (%s) AND %s "+
			"ORDER BY jobs.cpc * jobs.clickability DESC, jobs.id ASC LIMIT %d",
		placeholders, categoryClause, JOBS_LIMIT,
	)).Scan(&rows)

	ret := make([]Job, 0, len(rows))
	for _, r := range rows {
		job := Job{
			ID:           r.ID,
			Dist:         distByID[int64(r.ID)],
			Url:          r.Url,
			Title:        r.Title,
			Location:     r.Location,
			Body:         r.Body,
			Reference:    r.Reference,
			Category:     r.Category,
			CPC:          r.CPC,
			Clickability: r.Clickability,
			Expectation:  r.CPC * r.Clickability,
		}
		if r.Externaluid.Valid && r.Externaluid.String != "" {
			job.Image = misc.GetImageDeliveryUrl(r.Externaluid.String, "")
		}
		ret = append(ret, job)
	}

	return c.JSON(ret)
}

func GetJob(c *fiber.Ctx) error {
	var job Job
	var externaluid sql.NullString

	if c.Params("id") != "" {
		id, err := strconv.ParseUint(c.Params("id"), 10, 64)

		if err == nil {
			db := database.DBConn

			db.Raw("SELECT jobs.id, jobs.url, jobs.title, jobs.location, jobs.body, jobs.job_reference, jobs.category, jobs.cpc, jobs.clickability, ai_images.externaluid "+
				"FROM `jobs` "+
				"LEFT JOIN ai_images ON ai_images.name = jobs.canonical_title "+
				"WHERE jobs.id = ? "+
				"AND visible = 1;",
				id).Row().Scan(&job.ID, &job.Url, &job.Title, &job.Location, &job.Body, &job.Reference, &job.Category, &job.CPC, &job.Clickability, &externaluid)

			if job.ID != 0 {
				if externaluid.Valid && len(externaluid.String) > 0 {
					job.Image = misc.GetImageDeliveryUrl(externaluid.String, "")
				}
				return c.JSON(job)
			}
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "Job not found")
}

// RecordJobClick records a job click for analytics
func RecordJobClick(c *fiber.Ctx) error {
	// Check query params first, then form body.
	jobID := c.Query("id")
	if jobID == "" {
		jobID = c.FormValue("id")
	}

	link := c.Query("link")
	if link == "" {
		link = c.FormValue("link")
	}

	// Get user ID from context if authenticated (optional)
	var userID *uint64
	if c.Locals("session") != nil {
		if session, ok := c.Locals("session").(map[string]interface{}); ok {
			if id, exists := session["id"]; exists {
				if idUint, ok := id.(uint64); ok {
					userID = &idUint
				}
			}
		}
	}

	// Don't require ID, just record what we have.
	// The INSERT IGNORE handles missing/invalid IDs gracefully
	db := database.DBConn

	// Use IGNORE to handle clicks for purged jobs gracefully
	if userID != nil {
		db.Exec("INSERT IGNORE INTO logs_jobs (userid, jobid, link) VALUES (?, ?, ?)",
			*userID, jobID, link)
	} else {
		db.Exec("INSERT IGNORE INTO logs_jobs (userid, jobid, link) VALUES (NULL, ?, ?)",
			jobID, link)
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}
