package user

import (
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// RelevantOff turns off the matched-posts / "suggestion" emails for a user
// (users.relevantallowed = 0) — a TARGETED opt-out, not a full account
// unsubscribe. It is authenticated by the user's auto-login Link key (the same
// key mechanism as the login/forget links) so it works with no session: as a
// one-click List-Unsubscribe (RFC 8058 POST) from Gmail/Yahoo, and as a plain
// footer link (GET) the recipient can click in a browser.
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
	// ORM migration site 74e53dad60bf (wave 1).
	db.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?",
		uid, utils.LOGIN_TYPE_LINK).Limit(1).Scan(&storedKey)
	if storedKey == "" || storedKey != key {
		return fiber.NewError(fiber.StatusForbidden, "Invalid key")
	}

	db.Exec("UPDATE users SET relevantallowed = 0 WHERE id = ?", uid)

	// One-click (POST from a mail client) wants a 200; a browser click (GET)
	// gets a friendly confirmation page.
	if c.Method() == fiber.MethodGet {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.SendString(
			"<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">" +
				"<title>Unsubscribed</title></head>" +
				"<body style=\"font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#333\">" +
				"<h2 style=\"color:#338808\">Done</h2>" +
				"<p>You won't receive any more suggestion emails from Freegle about posts matching what you've offered or asked for.</p>" +
				"<p>You can turn them back on any time in your Freegle settings.</p></body></html>")
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
