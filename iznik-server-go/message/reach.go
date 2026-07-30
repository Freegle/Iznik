package message

import (
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// ReachBlockedSet returns the subset of msgids that a viewer at (lat,lng) may
// NOT reply to because the post has rippled out (it has a rippling_reach row)
// but not yet to the viewer's location. This is the canonical reply-eligibility
// containment check, shared by the browse read path and the similar-posts
// endpoint so the SQL lives in exactly one place.
//
// Fail-open semantics (matching the existing guards): a msgid is blocked only
// when a reach row exists AND its reach does not contain the point. Any error
// (e.g. rippling_reach not yet deployed) yields an empty set, and a viewer with
// no location (0,0) is never blocked.
//
// Containment consults the sandwich bounds when migrated (see
// rippling/reachbounds.go): outside a real outer_bound is an authoritative
// reject, inside inner_bound an authoritative accept, and only the band between
// touches the ~178KB exact polygon; POINT (completed) bounds fall back to the
// exact test.
func ReachBlockedSet(msgids []uint64, lat, lng float64) map[uint64]bool {
	blocked := make(map[uint64]bool)
	if len(msgids) == 0 || (lat == 0 && lng == 0) {
		return blocked
	}

	db := database.DBConn
	var rows []struct {
		Msgid uint64 `gorm:"column:msgid"`
	}
	var err error
	if rippling.ReachBoundsReady(db) {
		expr, exprArgs := rippling.ReachInReachExpr(lng, lat, utils.SRID)
		args := append([]interface{}{msgids}, exprArgs...)
		err = db.Raw("SELECT rr.msgid FROM rippling_reach rr "+
			"WHERE rr.msgid IN (?) AND NOT "+expr, args...).Scan(&rows).Error
	} else {
		err = db.Raw("SELECT msgid FROM rippling_reach WHERE msgid IN (?) "+
			"AND ST_Contains(polygon, ST_SRID(POINT(?, ?), ?)) = 0",
			msgids, lng, lat, utils.SRID).Scan(&rows).Error
	}
	if err == nil {
		for _, r := range rows {
			blocked[r.Msgid] = true
		}
	}
	return blocked
}

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
