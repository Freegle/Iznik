package job

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"regexp"
	"strconv"
	"strings"
)

// categorySanitizer removes non-letter/space/slash characters from category queries.
var categorySanitizer = regexp.MustCompile(`[^a-zA-Z/ ]+`)

type Job struct {
	ID           uint64  `json:"id" gorm:"primary_key"`
	Ambit        float64 `json:"ambit"`
	Dist         float64 `json:"dist"`
	Area         float64 `json:"area"`
	Url          string  `json:"url"`
	Title        string  `json:"title"`
	Location     string  `json:"location"`
	Body         string  `json:"body"`
	Reference    string  `json:"job_reference"`
	Category     string  `json:"category"`
	CPC          float64 `json:"cpc"`
	Clickability float64 `json:"clickability"`
	Expectation  float64 `json:"expectation"`
	Image        string  `json:"image,omitempty"`
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

	// Build id list + id→distance map for the enrichment query.
	distByID := make(map[int64]float64, len(knnResults))
	ids := make([]int64, 0, len(knnResults))
	for _, r := range knnResults {
		distByID[r.ID] = r.Distance
		ids = append(ids, r.ID)
	}

	return c.JSON(JobsForIDs(ids, distByID, lat, lng, category))
}

// JobsForIDs enriches the given nearby job IDs from MySQL and returns them as
// Jobs, applying the category filter and — restored from the pre-spatial query
// — an area constraint that drops jobs whose service-area polygon dwarfs the
// local search extent (e.g. nation-wide listings), so they don't surface as
// "nearby". The search box is derived from the KNN extent (the distance to the
// farthest nearby job), mirroring the old expanding-box ST_Area ratio test.
func JobsForIDs(ids []int64, distByID map[int64]float64, lat, lng float64, category string) []Job {
	if len(ids) == 0 {
		return make([]Job, 0)
	}

	idStrs := make([]string, len(ids))
	for i, id := range ids {
		idStrs[i] = strconv.FormatInt(id, 10)
	}
	placeholders := strings.Join(idStrs, ",")

	categoryClause := "category IS NOT NULL"
	var categoryArgs []any
	if category != "" {
		categoryClause = "category REGEXP ?"
		categoryArgs = []any{"(^|;)" + category + ".*"}
	}

	// Search-box half-extent in decimal degrees, from the farthest nearby job,
	// with a floor so the box is never degenerate (avoids divide-by-tiny-area).
	maxDist := 0.0
	for _, d := range distByID {
		if d > maxDist {
			maxDist = d
		}
	}
	if maxDist < 0.01 {
		maxDist = 0.01 // ~1.1km
	}
	swLng, swLat := lng-maxDist, lat-maxDist
	neLng, neLat := lng+maxDist, lat+maxDist
	box := fmt.Sprintf(
		"ST_SRID(POLYGON(LINESTRING("+
			"POINT(%[1]f, %[2]f), POINT(%[1]f, %[4]f), POINT(%[3]f, %[4]f), "+
			"POINT(%[3]f, %[2]f), POINT(%[1]f, %[2]f))), %[5]d)",
		swLng, swLat, neLng, neLat, utils.SRID,
	)
	areaClause := "(ST_Dimension(jobs.geometry) < 2 OR ST_Area(jobs.geometry) / ST_Area(" + box + ") < 2)"

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
		DistKm       float64        `gorm:"column:dist_km"`
	}

	// The KNN distance is a planar degree value (the geometry stores lat/lng tagged
	// SRID 3857), which the client mis-reads as km and shows as "Nearby" for
	// everything. Return the real great-circle km via ST_Distance_Sphere on the
	// geometry centroid so the distance / "Nearby" label is honest. Dedup of the
	// duplicate WhatJobs postings (one recruitment ad spammed to thousands of towns
	// as separate rows — Discourse 9363) is done upstream in the spatial KNN server,
	// which returns the nearest distinct (company, title), so the ids arriving here
	// are already distinct and we just enrich them.
	distExpr := "ST_Distance_Sphere(POINT(?, ?), POINT(ST_X(ST_Centroid(jobs.geometry)), ST_Y(ST_Centroid(jobs.geometry))))"
	args := []any{lng, lat}
	args = append(args, categoryArgs...)

	db.Raw(fmt.Sprintf(
		"SELECT jobs.id, jobs.url, jobs.title, jobs.location, jobs.body, jobs.job_reference, "+
			"jobs.category, jobs.cpc, jobs.clickability, ai_images.externaluid, "+
			distExpr+" / 1000 AS dist_km "+
			"FROM `jobs` LEFT JOIN ai_images ON ai_images.name = jobs.canonical_title "+
			"WHERE jobs.id IN (%s) AND %s AND %s "+
			// Rank by expected value (cpc * clickability) discounted by a mild
			// freshness factor: older WhatJobs postings are likelier already
			// filled/closed, so a click redirects to a different job and doesn't
			// convert. Decay the score with posting age (floored at 0.5 by ~7
			// days); posted_at NULL -> treated as fresh. Kept identical to the
			// digest ordering in iznik-batch Job::nearLocation.
			"ORDER BY jobs.cpc * jobs.clickability * GREATEST(0.5, 1 - COALESCE(DATEDIFF(NOW(), jobs.posted_at), 0) * 0.07) DESC, jobs.id ASC LIMIT %d",
		placeholders, categoryClause, areaClause, JOBS_LIMIT,
	), args...).Scan(&rows)

	ret := make([]Job, 0, len(rows))
	for _, r := range rows {
		job := Job{
			ID:           r.ID,
			Dist:         r.DistKm,
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

	return ret
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
	// Accept the click parameters from any of the transports our clients use:
	// query string and form body (the digest email links), and a JSON body
	// (the web app posts Content-Type: application/json, which FormValue does
	// not parse - so without this branch every web click logged id=0/link='').
	jobID := c.Query("id")
	if jobID == "" {
		jobID = c.FormValue("id")
	}

	link := c.Query("link")
	if link == "" {
		link = c.FormValue("link")
	}

	if jobID == "" && link == "" {
		var body struct {
			ID   json.Number `json:"id"`
			Link string      `json:"link"`
		}
		if err := c.BodyParser(&body); err == nil {
			jobID = body.ID.String()
			link = body.Link
		}
	}

	// Drop content-free hits. Bots/scanners POST /job with neither an id nor a link, which
	// otherwise floods logs_jobs with jobid=0/link='' rows that bury the genuine clicks. A real
	// click always carries at least a job id (web app) or a link (digest email link), so record
	// nothing when both are absent - but still return success so the caller sees no error.
	if (jobID == "" || jobID == "0") && link == "" {
		return c.JSON(fiber.Map{
			"ret":    0,
			"status": "Success",
		})
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
