package message

import (
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// ReachResponse is a post's ACTUAL rippling-out progress, for the moderation reach map to
// compare against the EXPECTED progress (the map itself draws the expected/projected reach;
// this tells the modal how far the post has really rippled, so it can flag when the engine
// is behind). `Rippling` is false (with a Reason) when the post has no reach row.
type ReachResponse struct {
	Rippling bool `json:"rippling"`
	// Reason explains why a post has no actual reach (only set when Rippling is false):
	//   "disabled"     - rippling is switched off globally (the dark default).
	//   "notbrowsable" - the post isn't browsable (pending/taken/withdrawn).
	//   "pending"      - eligible but no reach row yet (the ~1-minute post-approval window,
	//                    or it arrived before the go-live cutoff).
	Reason          string  `json:"reason,omitempty"`
	Msgid           uint64  `json:"msgid"`
	Tick            int     `json:"tick"`
	TotalTicks      int     `json:"totalticks"`
	Status          string  `json:"status"`
	Arrival         *string `json:"arrival"`
	NextExpansionAt *string `json:"nextexpansionat"`
}

type reachRow struct {
	Tick            int
	TotalTicks      int
	Status          string
	Arrival         *string
	NextExpansionAt *string
}

// Reach returns a post's current ACTUAL rippling-out progress (the hazard-schedule tick it has
// really reached), for moderators to compare with the expected progress on the reach map.
// Mod-of-group (or Admin/Support) only; returns rippling:false (not 404) with a reason when the
// post has no reach row.
//
// @Router /message/{id}/reach [get]
// @Summary Actual rippling-out progress of a post (moderation)
// @Tags message
// @Produce json
// @Param id path int true "Message ID"
// @Security BearerAuth
// @Success 200 {object} message.ReachResponse
// @Failure 403 {object} fiber.Error "Moderator of the post's group required"
func Reach(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	// The groups the post is on, for the mod-of-group authorisation and to confirm it exists.
	var groupids []uint64
	db.Raw("SELECT groupid FROM messages_groups WHERE msgid = ? AND deleted = 0", id).Scan(&groupids)
	if len(groupids) == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	allowed := user.IsAdminOrSupport(myid)
	if !allowed {
		for _, gid := range groupids {
			if user.IsModOfGroup(myid, gid) {
				allowed = true
				break
			}
		}
	}
	if !allowed {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this post's group")
	}

	var row reachRow
	found := db.Raw("SELECT tick, total_ticks, status, arrival, next_expansion_at "+
		"FROM rippling_reach WHERE msgid = ?", id).Scan(&row)

	if found.RowsAffected == 0 {
		// No actual reach row. Work out WHY so the UI doesn't imply the engine is behind when
		// it simply isn't running (dark) or the post isn't eligible yet.
		reason := "pending"
		if !rippleEnabled() {
			reason = "disabled"
		} else {
			var inSpatial int
			db.Raw("SELECT COUNT(*) FROM messages_spatial WHERE msgid = ?", id).Scan(&inSpatial)
			if inSpatial == 0 {
				reason = "notbrowsable"
			}
		}
		return c.JSON(ReachResponse{Rippling: false, Reason: reason, Msgid: id})
	}

	return c.JSON(ReachResponse{
		Rippling:        true,
		Msgid:           id,
		Tick:            row.Tick,
		TotalTicks:      row.TotalTicks,
		Status:          row.Status,
		Arrival:         row.Arrival,
		NextExpansionAt: row.NextExpansionAt,
	})
}
