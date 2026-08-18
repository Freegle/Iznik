package emailtracking

import (
	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
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

	// Only the mxgroup and address rows: the domain rows are derived children
	// of an mxgroup row and listing them would just repeat it once per domain.
	// keep-raw: a correlated count over a child table, which GORM's struct
	// conditions cannot express as a selected column.
	res := db.Table("mail_suppressions ms").
		Select("ms.id, ms.scope, ms.value, ms.provider, ms.reason, ms.deferred_since, " +
			"ms.first_seen, ms.last_seen, ms.message_count, " +
			"(SELECT COUNT(DISTINCT msc.userid) FROM mail_suppressed_counts msc " +
			" WHERE msc.caughtup_at IS NULL) AS membersaffected").
		Where("ms.released_at IS NULL AND ms.scope IN ('mxgroup','address')").
		Order("ms.deferred_since ASC").
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
		Joins("LEFT JOIN users_emails ue ON ue.userid = msc.userid AND ue.preferred = 1").
		Joins("LEFT JOIN mail_suppressions ms ON ms.released_at IS NULL AND " +
			"((ms.scope = 'domain' AND ms.value = SUBSTRING_INDEX(ue.email, '@', -1)) " +
			" OR (ms.scope = 'address' AND ms.value = ue.email))").
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
