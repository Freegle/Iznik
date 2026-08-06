package team

import (
	"fmt"
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm/clause"
)

type Team struct {
	ID           uint64  `json:"id" gorm:"primary_key"`
	Name         string  `json:"name"`
	Description  *string `json:"description"`
	Type         string  `json:"type"`
	Email        *string `json:"email"`
	Active       int     `json:"active"`
	Wikiurl      *string `json:"wikiurl"`
	Supporttools int     `json:"supporttools"`
}

type TeamMember struct {
	Userid        uint64  `json:"userid"`
	Description   *string `json:"description"`
	Added         string  `json:"added"`
	Nameoverride  *string `json:"nameoverride"`
	Imageoverride *string `json:"imageoverride"`
}

// hasTeamsPermission checks if user is Admin/Support (simplified PERM_TEAMS check).
func hasTeamsPermission(myid uint64) bool {
	return auth.IsAdminOrSupport(myid)
}

// GetTeam handles GET /team - list all, single by id, or Volunteers pseudo-team.
//
// @Summary Get teams
// @Tags team
// @Produce json
// @Param id query integer false "Team ID"
// @Param name query string false "Team name (use 'Volunteers' for pseudo-team)"
// @Success 200 {object} map[string]interface{}
// @Router /api/team [get]
func GetTeam(c *fiber.Ctx) error {
	db := database.DBConn
	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)
	name := c.Query("name", "")

	// Volunteers pseudo-team.
	if name == "Volunteers" {
		return getVolunteers(c)
	}

	// Get by name.
	if name != "" {
		db.Table("teams").Select("id").Where("name LIKE ?", name).Scan(&id)
		if id == 0 {
			// Team not found is a search result, not a resource error - return 200.
			return c.JSON(fiber.Map{"ret": 2, "status": "Not found"})
		}
	}

	if id > 0 {
		// Single team with members.
		var t Team
		db.Table("teams").Where("id = ?", id).Scan(&t)
		if t.ID == 0 {
			return c.JSON(fiber.Map{"ret": 2, "status": "Not found"})
		}

		var members []TeamMember
		db.Table("teams_members").Select("userid, description, added, nameoverride, imageoverride").
			Where("teamid = ?", id).Scan(&members)

		memberList := make([]map[string]interface{}, len(members))
		for i, m := range members {
			entry := map[string]interface{}{
				"id":          m.Userid,
				"description": m.Description,
				"added":       m.Added,
			}

			// Get display name (nameoverride takes precedence).
			if m.Nameoverride != nil && *m.Nameoverride != "" {
				entry["displayname"] = *m.Nameoverride
			} else {
				var displayname string
				db.Table("users").Select("COALESCE(fullname, CONCAT(COALESCE(firstname,''), ' ', COALESCE(lastname,'')), 'Unknown')").
					Where("id = ?", m.Userid).Scan(&displayname)
				entry["displayname"] = strings.TrimSpace(displayname)
			}

			// Get profile image.
			entry["profile"] = getUserProfile(m.Userid, m.Imageoverride)

			memberList[i] = entry
		}

		return c.JSON(fiber.Map{
			"ret":    0,
			"status": "Success",
			"team": fiber.Map{
				"id":           t.ID,
				"name":         t.Name,
				"description":  t.Description,
				"type":         t.Type,
				"email":        t.Email,
				"active":       t.Active,
				"wikiurl":      t.Wikiurl,
				"supporttools": t.Supporttools,
				"members":      memberList,
			},
		})
	}

	// List all teams.
	var teams []Team
	db.Table("teams").Order("LOWER(name) ASC").Scan(&teams)

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"teams":  teams,
	})
}

// getVolunteers returns the pseudo-team of all moderators who opt-in.
func getVolunteers(c *fiber.Ctx) error {
	db := database.DBConn

	type VolRow struct {
		Userid    uint64
		Firstname *string
		Lastname  *string
		Fullname  *string
		Added     string
		Settings  *string
	}

	var vols []VolRow
	db.Table("memberships").
		Select("DISTINCT memberships.userid, users.firstname, users.lastname, users.fullname, users.added, users.settings").
		Joins("INNER JOIN `groups` ON `groups`.id = memberships.groupid AND memberships.role IN (?, ?)", utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Joins("INNER JOIN users ON users.id = memberships.userid").
		Where("`groups`.type = ?", utils.GROUP_TYPE_FREEGLE).
		Scan(&vols)

	members := []map[string]interface{}{}
	for _, v := range vols {
		// Check settings for showmod and useprofile.
		if v.Settings == nil {
			continue
		}
		settings := *v.Settings
		// Simple JSON field check - showmod must be true.
		if !strings.Contains(settings, `"showmod":true`) && !strings.Contains(settings, `"showmod": true`) {
			continue
		}

		displayname := ""
		if v.Fullname != nil && *v.Fullname != "" {
			displayname = *v.Fullname
		} else {
			parts := []string{}
			if v.Firstname != nil && *v.Firstname != "" {
				parts = append(parts, *v.Firstname)
			}
			if v.Lastname != nil && *v.Lastname != "" {
				parts = append(parts, *v.Lastname)
			}
			displayname = strings.Join(parts, " ")
		}

		if displayname == "" {
			displayname = "Unknown"
		}

		members = append(members, map[string]interface{}{
			"userid":      v.Userid,
			"added":       v.Added,
			"displayname": displayname,
			"profile":     getUserProfile(v.Userid, nil),
		})
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"team": fiber.Map{
			"name":    "Volunteers",
			"members": members,
		},
	})
}

// getUserProfile gets the profile image for a user.
func getUserProfile(userid uint64, imageOverride *string) map[string]interface{} {
	if imageOverride != nil && *imageOverride != "" {
		return map[string]interface{}{
			"url":     *imageOverride,
			"turl":    *imageOverride,
			"default": false,
		}
	}

	imageDomain := os.Getenv("IMAGE_DOMAIN")
	if imageDomain == "" {
		imageDomain = "images.ilovefreegle.org"
	}

	db := database.DBConn
	var imgID uint64
	db.Table("users_images").Select("id").Where("userid = ?", userid).Order("id DESC").Limit(1).Scan(&imgID)

	if imgID > 0 {
		return map[string]interface{}{
			"url":     fmt.Sprintf("https://%s/uimg_%d.jpg", imageDomain, imgID),
			"turl":    fmt.Sprintf("https://%s/tuimg_%d.jpg", imageDomain, imgID),
			"default": false,
		}
	}

	return map[string]interface{}{
		"url":     "https://www.gravatar.com/avatar/?s=200",
		"turl":    "https://www.gravatar.com/avatar/?s=100",
		"default": true,
	}
}

// PostTeam handles POST /team to create a new team.
//
// @Summary Create team
// @Tags team
// @Accept json
// @Produce json
// @Router /api/team [post]
func PostTeam(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	if !hasTeamsPermission(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Permission denied"})
	}

	type CreateRequest struct {
		Name        string `json:"name"`
		Description string `json:"description"`
		Email       string `json:"email"`
	}

	var req CreateRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.Name == "" {
		req.Name = c.FormValue("name", c.Query("name", ""))
	}

	if req.Name == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing name"})
	}

	db := database.DBConn

	// Plain, isolated, literal single-row
	// INSERT; the generated id is read back via GORM's map-Create "@id" writeback
	// (proven in test/insertid_gorm_writeback_test.go), same pattern already
	// shipped for over
	// a dozen sibling sites.
	row := map[string]interface{}{
		"name":        req.Name,
		"email":       req.Email,
		"description": req.Description,
	}
	if err := db.Table("teams").Create(row).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Create failed"})
	}
	newIDInt, _ := row["@id"].(int64)
	newID := uint64(newIDInt)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
}

// PatchTeam handles PATCH /team to update team or manage members.
//
// @Summary Update team or manage members
// @Tags team
// @Accept json
// @Produce json
// @Router /api/team [patch]
func PatchTeam(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	if !hasTeamsPermission(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Permission denied"})
	}

	type PatchRequest struct {
		ID          utils.FlexUint64 `json:"id"`
		Action      string           `json:"action"`
		Userid      utils.FlexUint64 `json:"userid"`
		Name        string           `json:"name"`
		Description string           `json:"description"`
		Email       string           `json:"email"`
		Wikiurl     string           `json:"wikiurl"`
	}

	var req PatchRequest
	// FlexUint64 unmarshals both string and numeric JSON values, so BodyParser
	// handles ModTools teams.vue sending userid from a <b-form-input
	// type="number"> v-model, which yields a JSON string.
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.ID == 0 {
		id, _ := strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
		req.ID = utils.FlexUint64(id)
	}

	if req.ID == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	db := database.DBConn

	switch req.Action {
	case "Add":
		if req.Userid == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing userid"})
		}
		db.Table("teams_members").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"userid": req.Userid, "teamid": req.ID, "description": req.Description})
	case "Remove":
		if req.Userid == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing userid"})
		}
		db.Table("teams_members").Where("userid = ? AND teamid = ?", req.Userid, req.ID).Delete(nil)
	default:
		// Update team attributes.
		if req.Name != "" {
			db.Table("teams").Where("id = ?", req.ID).Update("name", req.Name)
		}
		if req.Description != "" {
			db.Table("teams").Where("id = ?", req.ID).Update("description", req.Description)
		}
		if req.Email != "" {
			db.Table("teams").Where("id = ?", req.ID).Update("email", req.Email)
		}
		if req.Wikiurl != "" {
			db.Table("teams").Where("id = ?", req.ID).Update("wikiurl", req.Wikiurl)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeleteTeam handles DELETE /team.
//
// @Summary Delete team
// @Tags team
// @Produce json
// @Param id query integer true "Team ID"
// @Router /api/team [delete]
func DeleteTeam(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	if !hasTeamsPermission(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Permission denied"})
	}

	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	db := database.DBConn
	// Team carries no gorm.DeletedAt,
	// so this stays a hard DELETE rather than becoming a soft-delete UPDATE.
	db.Where("id = ?", id).Delete(&Team{})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
