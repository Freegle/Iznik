// Package automod holds the ModTools-facing endpoints for the automod flowchart's stored
// decisions: moderator feedback on a single step, and the SysAdmin agreement report. The
// decisions themselves are written by the batch container (messages:automod); this package
// only reads and annotates them.
package automod

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// FeedbackRequest is the request body for POST /modtools/automod/feedback.
type FeedbackRequest struct {
	Msgid   uint64 `json:"msgid"`
	Groupid uint64 `json:"groupid"`
	Node    string `json:"node"`
}

// Feedback handles POST /modtools/automod/feedback. A moderator viewing a message's
// automod decision (messages_groups[].automod, see message.populateAutomodDecisions) can
// flag that one step (node) in the recorded path was wrong. This is not a dispute of the
// final verdict - it is per-node signal the agreement endpoint counts, to find which
// question is unreliable rather than just how often the chart as a whole is right.
//
// @Summary Record moderator feedback on one step of an automod decision
// @Tags automod
// @Accept json
// @Produce json
// @Param request body FeedbackRequest true "msgid, groupid, node"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Failure 404 {object} map[string]interface{}
// @Router /modtools/automod/feedback [post]
func Feedback(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req FeedbackRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Msgid == 0 || req.Groupid == 0 || req.Node == "" {
		return fiber.NewError(fiber.StatusBadRequest, "msgid, groupid and node are required")
	}

	if !user.IsModOfGroup(myid, req.Groupid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
	}

	if !utils.AutomodGroup(req.Groupid) {
		return fiber.NewError(fiber.StatusForbidden, "Not an automod group")
	}

	db := database.DBConn

	// messages_automod has a UNIQUE (msgid, groupid): at most one row, no ORDER BY/LIMIT
	// needed to find it.
	var automodid uint64
	db.Table("messages_automod").Select("id").
		Where("msgid = ? AND groupid = ?", req.Msgid, req.Groupid).
		Scan(&automodid)

	if automodid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "No automod decision for this message and group")
	}

	row := map[string]interface{}{
		"automodid": automodid,
		"node":      req.Node,
		"userid":    myid,
		"created":   gorm.Expr("NOW()"),
	}
	if err := db.Table("messages_automod_feedback").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to record feedback")
	}

	return c.JSON(fiber.Map{"success": true})
}
