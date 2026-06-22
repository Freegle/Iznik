package message

import (
	"encoding/json"
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// ReachResponse is the current rippling-out reach of a single post, for the moderation map.
// `Rippling` is false (and the geometry fields are zero) when the post has no reach row, i.e.
// it is not rippling yet.
type ReachResponse struct {
	Rippling        bool            `json:"rippling"`
	Msgid           uint64          `json:"msgid"`
	Lat             float64         `json:"lat"`
	Lng             float64         `json:"lng"`
	Tick            int             `json:"tick"`
	TotalTicks      int             `json:"totalticks"`
	Status          string          `json:"status"`
	MaxDriveMin     *float64        `json:"maxdrivemin"`
	TotalFreeglers  uint64          `json:"totalfreeglers"`
	Arrival         *string         `json:"arrival"`
	NextExpansionAt *string         `json:"nextexpansionat"`
	Polygon         json.RawMessage `json:"polygon"`
}

// reachRow is the raw scan target; polygon arrives as a GeoJSON string from ST_AsGeoJSON.
type reachRow struct {
	Lat             float64
	Lng             float64
	Tick            int
	TotalTicks      int
	Status          string
	MaxDriveMin     *float64
	TotalFreeglers  uint64
	Arrival         *string
	NextExpansionAt *string
	Polygon         string
}

// Reach returns the current rippling-out reach polygon for a post, for moderators to see (on a
// map) the area the post is currently visible in. Mod-only: the caller must be a moderator/owner
// of one of the post's groups, or Admin/Support.
//
// @Router /message/{id}/reach [get]
// @Summary Current rippling-out reach of a post (moderation)
// @Tags message
// @Produce json
// @Param id path int true "Message ID"
// @Security BearerAuth
// @Success 200 {object} message.ReachResponse
// @Failure 403 {object} fiber.Error "Moderator of the post's group required"
// @Failure 404 {object} fiber.Error "Message not found"
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

	// The groups the post is (currently) on. Used both for the mod-of-group authorisation and
	// to confirm the message exists.
	var groupids []uint64
	db.Raw("SELECT groupid FROM messages_groups WHERE msgid = ? AND deleted = 0", id).Scan(&groupids)
	if len(groupids) == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	// Authorise: Admin/Support, or a moderator/owner of any group the post is on.
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

	// Select raw column names: gorm maps snake_case columns to the struct's CamelCase fields,
	// so aliasing to the camelCase JSON keys here would break the binding.
	var row reachRow
	found := db.Raw("SELECT lat, lng, tick, total_ticks, status, max_drive_min, "+
		"total_freeglers, arrival, next_expansion_at, "+
		"ST_AsGeoJSON(polygon) AS polygon "+
		"FROM rippling_reach WHERE msgid = ?", id).Scan(&row)

	if found.RowsAffected == 0 {
		// Not rippling yet — return a clean "not rippling" payload rather than 404.
		return c.JSON(ReachResponse{Rippling: false, Msgid: id})
	}

	return c.JSON(ReachResponse{
		Rippling:        true,
		Msgid:           id,
		Lat:             row.Lat,
		Lng:             row.Lng,
		Tick:            row.Tick,
		TotalTicks:      row.TotalTicks,
		Status:          row.Status,
		MaxDriveMin:     row.MaxDriveMin,
		TotalFreeglers:  row.TotalFreeglers,
		Arrival:         row.Arrival,
		NextExpansionAt: row.NextExpansionAt,
		Polygon:         json.RawMessage(row.Polygon),
	})
}
