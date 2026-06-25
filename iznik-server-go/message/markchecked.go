package message

import (
	"fmt"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// MarkCheckedRequest is the request body for marking auto-published posts as
// checked by a moderator (clearing them from the Checked/Trusted oversight queues).
type MarkCheckedRequest struct {
	Groupid uint64   `json:"groupid"`          // 0 = all of the mod's groups
	Filter  string   `json:"filter"`           // "checked" or "trusted" (used when no ids given)
	IDs     []uint64 `json:"ids,omitempty"`    // specific messages, else mark the whole bucket
}

// MarkChecked records that a moderator has reviewed auto-published posts. These
// are posts that went live without a manual approval (auto-moderated and trusted
// members), so there is no approvedby/heldby to key off — this sets a dedicated
// checkedat/checkedby marker that drives the Checked/Trusted counts.
//
// @Summary Mark auto-published posts as checked by a moderator
// @Tags message
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /modtools/messages/markchecked [post]
func MarkChecked(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req MarkCheckedRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	// Resolve the groups the mod may act on.
	var groupIDs []uint64
	if req.Groupid == 0 {
		groupIDs = user.GetActiveModGroupIDs(myid)
		if len(groupIDs) == 0 {
			// Not a moderator of any group — cannot mark anything checked (D11).
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
		}
	} else {
		if !user.IsModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
		}
		groupIDs = []uint64{req.Groupid}
	}

	var rowsAffected int64
	if len(req.IDs) > 0 {
		// Mark the specified posts checked — only ones still unchecked, and only
		// on groups the mod moderates.
		r := db.Exec("UPDATE messages_groups SET checkedat = NOW(), checkedby = ? "+
			"WHERE msgid IN ? AND groupid IN ? AND collection = ? AND deleted = 0 AND checkedat IS NULL",
			myid, req.IDs, groupIDs, utils.COLLECTION_APPROVED)
		rowsAffected = r.RowsAffected
	} else {
		// Mark the whole bucket checked (the "mark all as checked" action). The
		// bucket condition mirrors the Checked/Trusted list filter so exactly the
		// posts a mod is looking at get cleared.
		if req.Filter != "checked" && req.Filter != "trusted" {
			return fiber.NewError(fiber.StatusBadRequest, "filter must be 'checked' or 'trusted' (D10)")
		}
		statusWhere := "mem.ourPostingStatus IS NULL"
		if req.Filter == "trusted" {
			statusWhere = "(mem.ourPostingStatus = 'DEFAULT' OR mem.ourPostingStatus = 'UNMODERATED')"
		}
		r := db.Exec("UPDATE messages_groups mg "+
			"INNER JOIN messages m ON m.id = mg.msgid "+
			"INNER JOIN memberships mem ON mem.userid = m.fromuser AND mem.groupid = mg.groupid "+
			"SET mg.checkedat = NOW(), mg.checkedby = ? "+
			"WHERE mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 "+
			"AND mg.approvedby IS NULL AND mg.rippled_in = 0 AND "+statusWhere+" "+
			fmt.Sprintf("AND mg.checkedat IS NULL AND mg.arrival >= NOW() - INTERVAL %d DAY", utils.MESSAGE_CHECK_WINDOW_DAYS),
			myid, groupIDs, utils.COLLECTION_APPROVED)
		rowsAffected = r.RowsAffected
	}

	return c.JSON(fiber.Map{"success": true, "checked": rowsAffected})
}
