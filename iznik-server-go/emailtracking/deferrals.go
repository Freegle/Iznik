package emailtracking

import (
	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/maildeferral"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// MailSuppression is one active deferral suppression, as shown to support.
type MailSuppression struct {
	ID            uint64  `json:"id"`
	Scope         string  `json:"scope"`
	Value         string  `json:"value"`
	Provider      *string `json:"provider"`
	Reason        *string `json:"reason"`
	DeferredSince *string `json:"deferredsince" gorm:"column:deferred_since"`
	FirstSeen     *string `json:"firstseen" gorm:"column:first_seen"`
	LastSeen      *string `json:"lastseen" gorm:"column:last_seen"`
	MessageCount  uint64  `json:"messagecount" gorm:"column:message_count"`
	// How many members we have actually declined to mail behind this
	// suppression. Zero at the start of an episode and climbing thereafter,
	// which is the honest picture: the suppression is live from the moment it
	// is written, but nobody has missed anything yet.
	MembersAffected uint64 `json:"membersaffected" gorm:"column:membersaffected"`
}

// DelayedMember is one member whose mail we are currently holding.
type DelayedMember struct {
	Userid       uint64  `json:"userid"`
	Displayname  *string `json:"displayname"`
	Email        *string `json:"email"`
	Provider     *string `json:"provider"`
	Since        *string `json:"since"`
	HeldMessages uint64  `json:"heldmessages" gorm:"column:heldmessages"`
}

// Deferrals handles GET /modtools/email/deferrals.
//
// Support's view of a deferral episode: which providers have stopped accepting
// our mail, since when, and which members are affected. This is the "list all
// currently-delayed members" tool - the per-member notice in ModTools only
// tells you about a member you already happen to be looking at, which is no
// use when you are trying to work out how big a problem is.
func Deferrals(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	suppressions := []MailSuppression{}

	// By DOMAIN. That is the unit support actually needs - "is mail to
	// yahoo.co.uk held?" - and it is what a member would recognise. The
	// mxgroup row names a relay pattern (am0.yahoodns.net) that means nothing
	// to anyone outside this code, and the address rows are individual
	// mailboxes: thousands of them in a bad episode, which buried the handful
	// of domains that were the actual story.
	//
	// Per-mailbox reasons are excluded for the same reason they no longer
	// suppress a provider: a full inbox is that member's problem, not a
	// provider refusing us, and listing them here reads as an outage.
	//
	// keep-raw: a correlated count over a child table, which GORM's struct
	// conditions cannot express as a selected column.
	res := db.Table("mail_suppressions ms").
		Select("ms.id, ms.scope, ms.value, ms.provider, ms.reason, ms.deferred_since, "+
			"ms.first_seen, ms.last_seen, ms.message_count, "+
			"(SELECT COUNT(DISTINCT msc.userid) FROM mail_suppressed_counts msc "+
			" WHERE msc.caughtup_at IS NULL AND msc.suppressionid IN "+
			"  (SELECT c.id FROM mail_suppressions c WHERE c.id = ms.id OR c.parentid = ms.id)"+
			") AS membersaffected").
		Where("ms.released_at IS NULL AND ms.scope = 'domain'").
		Where("ms.reason IS NULL OR ms.reason NOT REGEXP ?", maildeferral.PerMailboxReason).
		// Biggest backlog first: the question is always "what is worst".
		Order("ms.message_count DESC").
		Scan(&suppressions)

	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read suppressions")
	}

	members := []DelayedMember{}

	// Members with mail actually held. Driven from mail_suppressed_counts
	// rather than from the suppression's domain list, because that is the set
	// with something to show for it - a count, and a date it started.
	//
	// keep-raw: an aggregate over a per-type child table joined back to the
	// suppression that explains it; the same shape as the raw SELECTs used
	// throughout this package.
	res = db.Table("mail_suppressed_counts msc").
		Select("msc.userid, u.fullname AS displayname, ue.email, " +
			"ms.provider, MIN(msc.firstat) AS since, SUM(msc.count) AS heldmessages").
		Joins("JOIN users u ON u.id = msc.userid").
		Joins("LEFT JOIN mail_suppressions ms ON ms.id = msc.suppressionid").
		// The address we would have mailed, resolved the same way the mailer
		// resolves it: highest preferred, then highest validated. Joined on a
		// single id so a member with several addresses still yields one row.
		Joins("LEFT JOIN users_emails ue ON ue.id = (SELECT ue2.id FROM users_emails ue2 " +
			"WHERE ue2.userid = msc.userid ORDER BY ue2.preferred DESC, ue2.validated DESC LIMIT 1)").
		Where("msc.caughtup_at IS NULL").
		Group("msc.userid, u.fullname, ue.email, ms.provider").
		Order("since ASC").
		Limit(1000).
		Scan(&members)

	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read delayed members")
	}

	return c.JSON(fiber.Map{
		"suppressions": suppressions,
		"members":      members,
		// Capped so an estate-wide episode cannot try to render 9,400 rows in
		// a browser. Say so rather than silently truncate.
		"memberlimit": 1000,
	})
}
