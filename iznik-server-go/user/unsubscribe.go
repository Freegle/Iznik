package user

import (
	"html"
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// Unsubscribe categories. These mirror UnsubscribeService::TYPES in iznik-batch, which
// handles the mailto: arm of the same List-Unsubscribe header. apiv2 and batch-prod run on
// different hosts and batch-prod is outside the compose network, so neither side can call
// the other and the map is implemented twice; TestUnsubscribeTypesMatchBatch asserts the
// lists are identical so they cannot drift apart unnoticed.
const (
	UnsubDigest        = "digest"
	UnsubEvents        = "events"
	UnsubVolunteering  = "volunteering"
	UnsubNewsletter    = "newsletter"
	UnsubRelevant      = "relevant"
	UnsubChat          = "chat"
	UnsubNotifications = "notifications"
	UnsubEngagement    = "engagement"
	UnsubAll           = "all"
	// UnsubAllExceptReplies stops the bulk mail but keeps chat, so someone who offers a
	// sofa still hears when a neighbour replies. UnsubAll still means all, because
	// one-clicking Unsubscribe on a chat notification has to stop chat notifications.
	UnsubAllExceptReplies = "allexceptreplies"
)

// UnsubscribeTypes is the full category list, in the same order as the PHP side.
var UnsubscribeTypes = []string{
	UnsubDigest,
	UnsubEvents,
	UnsubVolunteering,
	UnsubNewsletter,
	UnsubRelevant,
	UnsubChat,
	UnsubNotifications,
	UnsubEngagement,
	UnsubAll,
	UnsubAllExceptReplies,
}

// singleUnsubscribeCategories is every category that names one kind of email, i.e. all of
// them except the two combinations that expand into them.
func singleUnsubscribeCategories() []string {
	return UnsubscribeTypes[:len(UnsubscribeTypes)-2]
}

// unsubDescriptions is what we tell the member each category covers. Kept in step with
// UnsubscribeService::DESCRIPTIONS so both arms of the header say the same thing.
var unsubDescriptions = map[string]string{
	UnsubDigest:           "emails about new posts in your communities",
	UnsubEvents:           "emails about community events",
	UnsubVolunteering:     "emails about volunteer opportunities",
	UnsubNewsletter:       "newsletters and community news",
	UnsubRelevant:         "emails suggesting posts that match what you are looking for",
	UnsubChat:             "emails telling you about new chat messages",
	UnsubNotifications:    "emails about replies and notifications",
	UnsubEngagement:       "occasional emails asking how we are doing",
	UnsubAll:              "all our non-essential emails",
	UnsubAllExceptReplies: "everything except replies to your posts",
}

// UnsubscribeDescription is what we tell the member a category covers.
func UnsubscribeDescription(t string) string {
	return unsubDescriptions[t]
}

func validUnsubscribeType(t string) bool {
	for _, known := range UnsubscribeTypes {
		if known == t {
			return true
		}
	}
	return false
}

// Unsubscribe turns off one category of email for a user - the HTTPS arm of the
// List-Unsubscribe header on every bulk mailable.
//
// It is authenticated by the user's auto-login Link key (the same mechanism as RelevantOff
// and the login/forget links) so it works with no session: as a one-click List-Unsubscribe
// (RFC 8058 POST) from Gmail/Yahoo, and as a plain link (GET) clicked in a browser.
//
// The endpoint has to do the work itself. Pointing List-Unsubscribe at a front-end page
// instead means the RFC 8058 POST gets a 200 back from the page renderer, mail clients
// report a successful unsubscribe to the member, and nothing is actually turned off.
//
// @Router /user/unsubscribe [post]
// @Summary Turn off one category of Freegle email
// @Tags user
// @Param u query int true "User ID"
// @Param k query string true "Auto-login Link key"
// @Param t query string true "Category: digest, events, volunteering, newsletter, relevant, chat, notifications, engagement or all"
// @Success 200
func Unsubscribe(c *fiber.Ctx) error {
	uidStr := c.Query("u")
	if uidStr == "" {
		uidStr = c.FormValue("u")
	}
	key := c.Query("k")
	if key == "" {
		key = c.FormValue("k")
	}
	unsubType := c.Query("t")
	if unsubType == "" {
		unsubType = c.FormValue("t")
	}

	uid, _ := strconv.ParseUint(uidStr, 10, 64)
	if uid == 0 || key == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Missing u or k")
	}

	// An address we generated ourselves always carries a known category, but a mangled or
	// truncated one must not silently do nothing - fall back to turning everything off,
	// which is what "unsubscribe" means to the member.
	if !validUnsubscribeType(unsubType) {
		unsubType = UnsubAll
	}

	db := database.DBConn

	// Validate the Link key (constant-ish: single indexed read then compare).
	var storedKey string
	db.Raw("SELECT credentials FROM users_logins WHERE userid = ? AND type = ? LIMIT 1",
		uid, utils.LOGIN_TYPE_LINK).Scan(&storedKey)
	if storedKey == "" || storedKey != key {
		return fiber.NewError(fiber.StatusForbidden, "Invalid key")
	}

	applyUnsubscribe(db, uid, unsubType)

	// One-click (POST from a mail client) wants a 200; a browser click (GET) gets a
	// friendly confirmation page.
	if c.Method() == fiber.MethodGet {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.SendString(unsubscribePage(unsubType))
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// applyUnsubscribe switches off the categories covered by unsubType. Mirrors
// UnsubscribeService::applyOne().
func applyUnsubscribe(db *gorm.DB, uid uint64, unsubType string) {
	wanted := []string{unsubType}
	switch unsubType {
	case UnsubAll:
		wanted = singleUnsubscribeCategories()
	case UnsubAllExceptReplies:
		wanted = nil
		for _, one := range singleUnsubscribeCategories() {
			if one != UnsubChat {
				wanted = append(wanted, one)
			}
		}
	}

	for _, one := range wanted {
		switch one {
		case UnsubDigest:
			db.Exec("UPDATE memberships SET emailfrequency = 0 WHERE userid = ? AND emailfrequency != 0", uid)
		case UnsubEvents:
			db.Exec("UPDATE memberships SET eventsallowed = 0 WHERE userid = ? AND eventsallowed != 0", uid)
		case UnsubVolunteering:
			db.Exec("UPDATE memberships SET volunteeringallowed = 0 WHERE userid = ? AND volunteeringallowed != 0", uid)
		case UnsubNewsletter:
			db.Exec("UPDATE users SET newslettersallowed = 0 WHERE id = ?", uid)
		case UnsubRelevant:
			db.Exec("UPDATE users SET relevantallowed = 0 WHERE id = ?", uid)
		case UnsubChat:
			// settings is a JSON column, and absent means "on", so the key has to be
			// created rather than only updated. JSON_SET will not create a nested member
			// whose parent object is missing, so $.notifications is ensured first -
			// without that, a user whose settings have no notifications object is left
			// completely unchanged and the unsubscribe silently does nothing.
			db.Exec("UPDATE users SET settings = JSON_SET("+
				"JSON_SET(COALESCE(settings, '{}'), '$.notifications', COALESCE(JSON_EXTRACT(settings, '$.notifications'), JSON_OBJECT()))"+
				", '$.notifications.email', CAST('false' AS JSON)) WHERE id = ?", uid)
		case UnsubNotifications:
			db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.notificationmails', CAST('false' AS JSON)) WHERE id = ?", uid)
		case UnsubEngagement:
			db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.engagement', CAST('false' AS JSON)) WHERE id = ?", uid)
		}
	}
}

func unsubscribePage(unsubType string) string {
	what := unsubDescriptions[unsubType]
	if what == "" {
		what = "these emails"
	}

	var b strings.Builder
	b.WriteString("<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">")
	b.WriteString("<title>Unsubscribed</title></head>")
	b.WriteString("<body style=\"font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#333\">")
	b.WriteString("<h2 style=\"color:#338808\">Done</h2>")
	b.WriteString("<p>We've turned off " + html.EscapeString(what) + ".</p>")
	if unsubType != UnsubAll {
		b.WriteString("<p>You may still get other kinds of email from Freegle.</p>")
	}
	b.WriteString("<p>You can change any of this, or turn everything off, in your ")
	b.WriteString("<a href=\"" + userSiteBase() + "/settings\" style=\"color:#338808\">Freegle settings</a>.</p>")
	b.WriteString("</body></html>")

	return b.String()
}

// userSiteBase returns the user-facing site as a full https:// base URL.
// USER_SITE is a bare host (e.g. www.ilovefreegle.org).
func userSiteBase() string {
	userSite := os.Getenv("USER_SITE")
	if userSite == "" {
		userSite = "www.ilovefreegle.org"
	}
	return "https://" + userSite
}
