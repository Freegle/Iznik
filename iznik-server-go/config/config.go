package config

import (
	"encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"strconv"
	"strings"
)

type ConfigItem struct {
	ID    uint64 `json:"id" gorm:"primary_key"`
	Key   string `json:"key"`
	Value string `json:"value"`
}

type ConcernKeyword struct {
	ID        uint64  `json:"id" gorm:"primary_key"`
	Keyword   string  `json:"keyword"`
	Substance *string `json:"substance"`
	Category  string  `json:"category"`
	MatchMode string  `json:"match_mode" gorm:"column:match_mode"`
	Exclude   *string `json:"exclude"`
	Scope     string  `json:"scope"`
	GroupID   uint64  `json:"group_id" gorm:"column:group_id"`
	Action    string  `json:"action"`
}

func (ConcernKeyword) TableName() string {
	return "concern_keywords"
}

type CreateConcernKeywordRequest struct {
	Keyword   string  `json:"keyword" validate:"required"`
	Substance *string `json:"substance"`
	Category  string  `json:"category" validate:"required"`
	MatchMode string  `json:"match_mode"`
	Exclude   *string `json:"exclude"`
	Scope     string  `json:"scope"`
	GroupID   uint64  `json:"group_id"`
	Action    string  `json:"action"`
}

// RequireSupportOrAdminMiddleware creates middleware that checks for Support/Admin role
func RequireSupportOrAdminMiddleware() fiber.Handler {
	return func(c *fiber.Ctx) error {
		// Extract JWT information including session ID
		userID, sessionID, _ := user.GetJWTFromRequest(c)
		if userID == 0 {
			return fiber.NewError(fiber.StatusUnauthorized, "Authentication required")
		}

		db := database.DBConn

		// Validate that the user and session are still valid in the database
		var userInfo struct {
			ID         uint64 `json:"id"`
			Systemrole string `json:"systemrole"`
		}

		db.Raw("SELECT users.id, users.systemrole FROM sessions INNER JOIN users ON users.id = sessions.userid WHERE sessions.id = ? AND users.id = ? LIMIT 1", sessionID, userID).Scan(&userInfo)

		if userInfo.ID == 0 {
			return fiber.NewError(fiber.StatusUnauthorized, "Invalid session")
		}

		if userInfo.Systemrole != utils.SYSTEMROLE_SUPPORT && userInfo.Systemrole != utils.SYSTEMROLE_ADMIN {
			return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
		}

		return c.Next()
	}
}

func Get(c *fiber.Ctx) error {
	key := c.Params("key")

	var items []ConfigItem

	db := database.DBConn

	db.Raw("SELECT * FROM config WHERE `key` = ?", key).Scan(&items)

	if len(items) > 0 {
		return c.JSON(items)
	} else {
		// Force [] rather than null to be returned.
		return c.JSON(make([]string, 0))
	}
}

// PatchAdminConfig updates config key/value pairs. Protected by RequireSupportOrAdminMiddleware.
// @Summary Update admin config
// @Tags config
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /config/admin [patch]
func PatchAdminConfig(c *fiber.Ctx) error {
	var body map[string]interface{}
	if err := c.BodyParser(&body); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if len(body) == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "No config keys provided")
	}

	db := database.DBConn

	for key, value := range body {
		// Skip modtools metadata injected by the client.
		if key == "modtools" {
			continue
		}

		var strVal string
		switch v := value.(type) {
		case string:
			strVal = v
		default:
			b, err := json.Marshal(v)
			if err != nil {
				return fiber.NewError(fiber.StatusBadRequest, fmt.Sprintf("Invalid value for key %s", key))
			}
			strVal = string(b)
		}

		db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
			key, strVal, strVal)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// Concern Keywords endpoints

func ListConcernKeywords(c *fiber.Ctx) error {
	db := database.DBConn

	query := db.Order("keyword ASC")

	scope := c.Query("scope")
	if scope != "" {
		query = query.Where("scope = ?", scope)
	}

	groupID := c.Query("group_id")
	if groupID != "" {
		query = query.Where("group_id = ?", groupID)
	}

	var keywords []ConcernKeyword
	query.Find(&keywords)

	if keywords == nil {
		keywords = make([]ConcernKeyword, 0)
	}

	return c.JSON(keywords)
}

func CreateConcernKeyword(c *fiber.Ctx) error {
	var req CreateConcernKeywordRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if strings.TrimSpace(req.Keyword) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Keyword is required")
	}
	if req.Category == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Category is required")
	}

	matchMode := req.MatchMode
	if matchMode == "" {
		matchMode = "literal"
	}
	scope := req.Scope
	if scope == "" {
		scope = "global"
	}
	action := req.Action
	if action == "" {
		action = "flag"
	}

	kw := ConcernKeyword{
		Keyword:   strings.TrimSpace(req.Keyword),
		Substance: req.Substance,
		Category:  req.Category,
		MatchMode: matchMode,
		Exclude:   req.Exclude,
		Scope:     scope,
		GroupID:   req.GroupID,
		Action:    action,
	}

	result := database.DBConn.Create(&kw)
	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create concern keyword")
	}

	return c.Status(fiber.StatusOK).JSON(kw)
}

func DeleteConcernKeyword(c *fiber.Ctx) error {
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
	}

	result := database.DBConn.Delete(&ConcernKeyword{}, id)
	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to delete concern keyword")
	}

	if result.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Concern keyword not found")
	}

	return c.Status(fiber.StatusOK).JSON(fiber.Map{"success": true})
}
