package shortlink

import (
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

type Shortlink struct {
	ID      uint64  `json:"id" gorm:"primary_key"`
	Name    string  `json:"name"`
	Type    string  `json:"type"`
	Url     *string `json:"url"`
	Clicks  int64   `json:"clicks"`
	Created string  `json:"created"`
}

type ClickHistory struct {
	Date  string `json:"date"`
	Count int    `json:"count"`
}

// GetShortlink handles GET /shortlink with an optional id parameter.
//
// @Summary Get shortlinks
// @Description Returns a single shortlink by ID, or lists all shortlinks
// @Tags shortlink
// @Produce json
// @Param id query integer false "Shortlink ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/shortlink [get]
func GetShortlink(c *fiber.Ctx) error {
	db := database.DBConn
	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)

	if id > 0 {
		// Single shortlink with click history.
		var s Shortlink
		db.Table("shortlinks").Where("id = ?", id).Scan(&s)

		if s.ID == 0 {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
		}

		// Get click history.
		var clicks []ClickHistory
		db.Table("shortlink_clicks").Select("DATE(timestamp) AS date, COUNT(*) AS count").Where("shortlinkid = ?", id).
			Group("date").Order("date ASC").Scan(&clicks)

		if clicks == nil {
			clicks = make([]ClickHistory, 0)
		}

		return c.JSON(fiber.Map{
			"ret":    0,
			"status": "Success",
			"shortlink": fiber.Map{
				"id":           s.ID,
				"name":         s.Name,
				"type":         s.Type,
				"url":          s.Url,
				"clicks":       s.Clicks,
				"created":      s.Created,
				"clickhistory": clicks,
			},
		})
	}

	// List all shortlinks.
	var links []Shortlink
	db.Table("shortlinks").Order("LOWER(name) ASC").Scan(&links)

	if links == nil {
		links = make([]Shortlink, 0)
	}

	return c.JSON(fiber.Map{
		"ret":        0,
		"status":     "Success",
		"shortlinks": links,
	})
}

// PostShortlink handles POST /shortlink to create a new shortlink.
//
// @Summary Create a shortlink
// @Tags shortlink
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/shortlink [post]
func PostShortlink(c *fiber.Ctx) error {
	db := database.DBConn

	type CreateRequest struct {
		Name string `json:"name"`
		Url  string `json:"url"`
	}

	var req CreateRequest

	// Support both form and JSON.
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.Name == "" {
		req.Name = c.FormValue("name", c.Query("name", ""))
	}
	if req.Url == "" {
		req.Url = c.FormValue("url", c.Query("url", ""))
	}

	if req.Name == "" || req.Url == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid parameters"})
	}

	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}
	if !auth.IsModerator(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Must be a moderator"})
	}

	// Check if name already exists.
	var existing uint64
	db.Table("shortlinks").Select("id").Where("name LIKE ?", req.Name).Scan(&existing)
	if existing > 0 {
		return c.Status(fiber.StatusConflict).JSON(fiber.Map{"ret": 3, "status": "Name already in use"})
	}

	// Create the shortlink; id read back via GORM's map-Create "@id" writeback.
	row := map[string]interface{}{
		"name": req.Name,
		"url":  req.Url,
	}
	if err := db.Table("shortlinks").Create(row).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Failed to create shortlink"})
	}
	newIDInt, _ := row["@id"].(int64)
	newID := uint64(newIDInt)

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     newID,
	})
}
