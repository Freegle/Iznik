package user

import (
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// RelevantOff turns off the matched-posts / "suggestion" emails for a user
// (users.relevantallowed = 0) — a TARGETED opt-out, not a full account
// unsubscribe. It is authenticated by the user's auto-login Link key (the same
// key mechanism as the login/forget links) so it works with no session: as a
// one-click List-Unsubscribe (RFC 8058 POST) from Gmail/Yahoo, and as a plain
// footer link (GET) the recipient can click in a browser. POST applies it; GET only asks.
//
// @Router /user/relevantoff [get]
// @Summary Turn off matched-posts suggestion emails (relevantallowed=0)
// @Tags user
// @Param u query int true "User ID"
// @Param k query string true "Auto-login Link key"
// @Success 200
func RelevantOff(c *fiber.Ctx) error {
	uidStr := c.Query("u")
	if uidStr == "" {
		uidStr = c.FormValue("u")
	}
	key := c.Query("k")
	if key == "" {
		key = c.FormValue("k")
	}

	uid, _ := strconv.ParseUint(uidStr, 10, 64)
	if uid == 0 || key == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Missing u or k")
	}

	db := database.DBConn

	// Validate the Link key (constant-ish: single indexed read then compare).
	var storedKey string
	db.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?",
		uid, utils.LOGIN_TYPE_LINK).Limit(1).Scan(&storedKey)
	if storedKey == "" || storedKey != key {
		return fiber.NewError(fiber.StatusForbidden, "Invalid key")
	}

	// A GET changes nothing: link scanners and mail proxies follow the footer link with nobody
	// having clicked, so the page asks first and its form POSTs back here to apply it.
	if c.Method() == fiber.MethodGet {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.SendString(confirmPage(uid, key, UnsubRelevant))
	}

	db.Table("users").Where("id = ?", uid).Update("relevantallowed", gorm.Expr("0"))

	// A mail client's one-click POST wants a 200; our own confirm form gets a page saying it worked.
	if c.FormValue("confirm") != "" {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.SendString(donePage(UnsubRelevant))
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
