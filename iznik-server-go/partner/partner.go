// Package partner implements the TrashNothing member-join integration: the
// only external system that still joins Freegle by area rather than by
// browsing the site. There is no membership row and no per-area concept any
// more; partner_areas exists only so a brand-new TrashNothing member can be
// given a starting location, the same way a signup on the site is.
package partner

import (
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/location"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// PutMember handles PUT /memberships?partner=<key>&tnuserid=<id>&email=<email>&groupid=<id>.
// TrashNothing is the only caller. groupid is the area id it has always
// sent; it is now resolved against partner_areas rather than groups, and
// only used to seed a location for a member who does not already have one.
func PutMember(c *fiber.Ctx) error {
	db := database.DBConn

	key := c.Query("partner", "")
	if key == "" {
		return fiber.NewError(fiber.StatusBadRequest, "partner is required")
	}

	_, _, domain, err := user.ValidatePartnerKey(db, key)
	if err != nil {
		return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
	}

	email := c.Query("email", "")
	tnuserid, _ := strconv.ParseUint(c.Query("tnuserid", "0"), 10, 64)
	areaid, _ := strconv.ParseUint(c.Query("groupid", "0"), 10, 64)

	// The partner's identifiers must actually belong to their own domain, so
	// one partner cannot join a member using another partner's email alias.
	if email != "" && domain != "" {
		parts := strings.SplitN(email, "@", 2)
		if len(parts) == 2 && !strings.EqualFold(parts[1], domain) {
			return fiber.NewError(fiber.StatusForbidden, "Email domain does not match partner domain")
		}
	}

	candidates := user.FindTNCandidates(db, tnuserid, email)
	candidates = user.HealTNDivergence(db, candidates)

	var userid uint64
	if len(candidates) > 0 {
		userid = candidates[0]
	}

	if userid == 0 {
		userid, err = user.CreatePartnerUser(db, tnuserid, email)
		if err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create user")
		}
	} else {
		user.EnsurePartnerIdentifiers(db, userid, tnuserid, email)
	}

	// The ban is site-wide now; there is no per-area membership to check
	// instead. A banned member must get a genuine failure, not a fake
	// success that lets the partner believe the join worked.
	var banned *time.Time
	db.Table("users").Select("banned").Where("id = ?", userid).Scan(&banned)
	if banned != nil {
		return fiber.NewError(fiber.StatusForbidden, "Member is banned")
	}

	if areaid > 0 {
		giveLocationIfNone(db, userid, areaid)
	}

	return c.JSON(fiber.Map{
		"ret":      0,
		"status":   "Success",
		"fduserid": userid,
	})
}

// giveLocationIfNone sets a brand-new member's location from the
// TrashNothing area's centroid, but only when they don't already have one -
// an existing member's declared location always wins over the area id
// carried on this call.
func giveLocationIfNone(db *gorm.DB, userid uint64, areaid uint64) {
	var lastlocation *uint64
	db.Table("users").Select("lastlocation").Where("id = ?", userid).Scan(&lastlocation)
	if lastlocation != nil {
		return
	}

	var area struct {
		Lat float32
		Lng float32
	}
	db.Table("partner_areas").Select("lat, lng").Where("id = ?", areaid).Scan(&area)
	if area.Lat == 0 && area.Lng == 0 {
		return
	}

	loc := location.ClosestPostcode(area.Lat, area.Lng)
	if loc.ID == 0 {
		return
	}

	db.Table("users").Where("id = ? AND lastlocation IS NULL", userid).Update("lastlocation", loc.ID)
}
