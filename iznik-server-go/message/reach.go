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
		// ReachInReachExpr always returns the same expression text (only the
		// bind args vary per call), so this has exactly one rendered form,
		// proven by the retired ormharness (shapes.json /
		// TestTier3Shapes_ff9be67577e8, removed in d22ba1d6c).
		// WHERE built as a single string for ONE Where() call: GORM's
		// clause.Where wraps any fragment containing "AND"/"OR" in an extra
		// paren pair once there is more than one Where expression to
		// combine (clause/where.go buildExprs), which would diverge from
		// the golden.
		expr, exprArgs := rippling.ReachInReachExpr(lng, lat, utils.SRID)
		whereArgs := append([]interface{}{msgids}, exprArgs...)
		err = db.Table("rippling_reach rr").
			Select("rr.msgid").
			Where("rr.msgid IN (?) AND NOT "+expr, whereArgs...).
			Scan(&rows).Error
	} else {
		err = db.Table("rippling_reach").
			Select("msgid").
			Where("msgid IN ? AND ST_Contains(polygon, ST_SRID(POINT(?, ?), ?)) = 0", msgids, lng, lat, utils.SRID).
			Scan(&rows).Error
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
	// Polygon is the ACTUAL stored reach (rippling_reach.polygon) as GeoJSON, and is what the
	// reach modal draws - a reach is held, clipped where members left a group, or capped by the
	// poster's distance preference, none of which a client-side projection of the schedule
	// knows about, so the projection was dropped in favour of this. This is the ONE place the
	// stored polygon crosses the API: mod-only, one post per request, so the payload
	// (~300KB typical / ~850KB worst on prod at 5 decimal places, well-compressed on the wire
	// - the grid-fill polygons are highly repetitive) is a deliberate exception to the "never
	// ship reach polygons" rule that governs the member-facing feed.
	Polygon string `json:"polygon,omitempty"`
}

type reachRow struct {
	Tick            int
	TotalTicks      int
	Status          string
	Arrival         *string
	NextExpansionAt *string
	Polygon         *string
}

// Reach returns a post's current ACTUAL rippling-out progress (the hazard-schedule tick it has
// really reached), which is what the moderation reach map draws.
// Any moderator (or Admin/Support); returns rippling:false (not 404) with a reason when the
// post has no reach row.
//
// @Router /message/{id}/reach [get]
// @Summary Actual rippling-out progress of a post (moderation)
// @Tags message
// @Produce json
// @Param id path int true "Message ID"
// @Security BearerAuth
// @Success 200 {object} message.ReachResponse
// @Failure 403 {object} fiber.Error "Moderator required"
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

	// Confirm the post exists (and is on a group at all).
	var groupids []uint64
	db.Table("messages_groups").Select("groupid").Where("msgid = ? AND deleted = 0", id).Scan(&groupids)
	if len(groupids) == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	// Any moderator may look at reach, not only mods of the groups this post is on.
	// Rippling deliberately carries posts to OTHER groups, so the mods who most need
	// to see how far one has travelled are usually not mods of its origin group -
	// gating on mod-of-this-post's-group hid reach from exactly them. Reach exposes
	// no member data, so there is nothing here to scope per group.
	if !user.IsModOfAnyGroup(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Moderator required")
	}

	// 5 decimal places ≈ 1m - plenty for a map overlay, and it keeps the grid-fill polygons
	// (~11k vertices) to a fraction of their full-precision WKT size. The stored geometry's
	// coordinates are lng/lat degrees (the SRID label notwithstanding), which is exactly
	// GeoJSON's [lng, lat] order, so no transform is needed.
	var row reachRow
	found := db.Table("rippling_reach").
		Select("tick, total_ticks, status, arrival, next_expansion_at, ST_AsGeoJSON(polygon, 5) AS polygon").
		Where("msgid = ?", id).
		Scan(&row)

	if found.RowsAffected == 0 {
		// No actual reach row. Work out WHY so the UI doesn't imply the engine is behind when
		// it simply isn't running (dark) or the post isn't eligible yet.
		reason := "pending"
		if !rippleEnabled() {
			reason = "disabled"
		} else {
			var inSpatial int
			// inSpatial is int, not
			// int64, so this keeps Row().Scan rather than GORM's Count, which
			// requires *int64.
			db.Table("messages_spatial").Select("COUNT(*)").Where("msgid = ?", id).Row().Scan(&inSpatial)
			if inSpatial == 0 {
				reason = "notbrowsable"
			}
		}
		return c.JSON(ReachResponse{Rippling: false, Reason: reason, Msgid: id})
	}

	polygon := ""
	if row.Polygon != nil {
		polygon = *row.Polygon
	}
	return c.JSON(ReachResponse{
		Rippling:        true,
		Msgid:           id,
		Tick:            row.Tick,
		TotalTicks:      row.TotalTicks,
		Status:          row.Status,
		Arrival:         row.Arrival,
		NextExpansionAt: row.NextExpansionAt,
		Polygon:         polygon,
	})
}
