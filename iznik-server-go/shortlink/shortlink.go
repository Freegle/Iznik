package shortlink

import (
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

type Shortlink struct {
	ID        uint64  `json:"id" gorm:"primary_key"`
	Name      string  `json:"name"`
	Type      string  `json:"type"`
	Groupid   *uint64 `json:"groupid"`
	Url       *string `json:"url"`
	Clicks    int64   `json:"clicks"`
	Created   string  `json:"created"`
	Nameshort string  `json:"nameshort,omitempty" gorm:"-"`
}

type ClickHistory struct {
	Date  string `json:"date"`
	Count int    `json:"count"`
}

// GetShortlink handles GET /shortlink with optional id and groupid parameters.
//
// @Summary Get shortlinks
// @Description Returns a single shortlink by ID, or lists all shortlinks (optionally filtered by group)
// @Tags shortlink
// @Produce json
// @Param id query integer false "Shortlink ID"
// @Param groupid query integer false "Filter by group ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/shortlink [get]
func GetShortlink(c *fiber.Ctx) error {
	db := database.DBConn
	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)
	groupid, _ := strconv.ParseUint(c.Query("groupid", "0"), 10, 64)

	userSite := os.Getenv("USER_SITE")
	if userSite == "" {
		userSite = "www.ilovefreegle.org"
	}

	if id > 0 {
		// Single shortlink with click history.
		var s Shortlink
		// ORM migration site 23b1c7ff40ab (wave 1).
		db.Table("shortlinks").Where("id = ?", id).Scan(&s)

		if s.ID == 0 {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
		}

		resolveShortlinkURL(&s, userSite)

		// Get click history.
		var clicks []ClickHistory
		// ORM migration site 8ce94884434d (wave 1).
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
				"groupid":      s.Groupid,
				"url":          s.Url,
				"clicks":       s.Clicks,
				"created":      s.Created,
				"nameshort":    s.Nameshort,
				"clickhistory": clicks,
			},
		})
	}

	// List all shortlinks.
	var links []Shortlink
	if groupid > 0 {
		// ORM migration site 87c8a0b19cab (wave 1).
		db.Table("shortlinks").Where("groupid = ?", groupid).Order("LOWER(name) ASC").Scan(&links)
	} else {
		// ORM migration site 5604e1f583b4 (wave 1).
		db.Table("shortlinks").Order("LOWER(name) ASC").Scan(&links)
	}

	if links == nil {
		links = make([]Shortlink, 0)
	}

	for i := range links {
		resolveShortlinkURL(&links[i], userSite)
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
		Name    string `json:"name"`
		Groupid uint64 `json:"groupid"`
	}

	var req CreateRequest

	// Support both form and JSON.
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.Name == "" {
		req.Name = c.FormValue("name", c.Query("name", ""))
	}
	if req.Groupid == 0 {
		req.Groupid, _ = strconv.ParseUint(c.FormValue("groupid", c.Query("groupid", "0")), 10, 64)
	}

	if req.Name == "" || req.Groupid == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid parameters"})
	}

	// SECURITY: shortlinks are a per-group moderator tool; creation was previously
	// unauthenticated. Require the caller to be a mod/owner of the target group
	// (admin/support included via IsModOfGroup).
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}
	if !auth.IsModOfGroup(myid, req.Groupid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Not a moderator of this group"})
	}

	// Check if name already exists.
	var existing uint64
	// ORM migration site d9283245db83 (wave 1).
	db.Table("shortlinks").Select("id").Where("name LIKE ?", req.Name).Scan(&existing)
	if existing > 0 {
		return c.Status(fiber.StatusConflict).JSON(fiber.Map{"ret": 3, "status": "Name already in use"})
	}

	// Create the shortlink.
	// Use the underlying sql.DB to get LastInsertId() directly from the MySQL protocol
	// response — never issue a separate SELECT LAST_INSERT_ID() as it's unsafe under
	// parallel load (GORM's connection pool may assign a different connection).
	sqlDB, err := db.DB()
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Database error"})
	}
	sqlResult, err := sqlDB.Exec("INSERT INTO shortlinks (name, type, groupid) VALUES (?, 'Group', ?)", req.Name, req.Groupid)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Failed to create shortlink"})
	}

	var newID uint64
	lastID, err := sqlResult.LastInsertId()
	if err == nil && lastID > 0 {
		newID = uint64(lastID)
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     newID,
	})
}

// resolveShortlinkURL computes the URL for a Group-type shortlink based on group settings.
func resolveShortlinkURL(s *Shortlink, userSite string) {
	if s.Type == "Group" && s.Groupid != nil {
		var g struct {
			Nameshort string
			External  *string
			Onhere    int
		}
		// ORM migration site 4c3e63baca48 (wave 1).
		database.DBConn.Table("groups").Select("nameshort, external, onhere").Where("id = ?", *s.Groupid).Scan(&g)

		s.Nameshort = g.Nameshort

		if g.External != nil && *g.External != "" {
			s.Url = g.External
		} else if g.Onhere > 0 {
			url := "https://" + userSite + "/explore/" + g.Nameshort
			s.Url = &url
		} else {
			url := "https://groups.yahoo.com/neo/groups/" + g.Nameshort
			s.Url = &url
		}
	}
}
