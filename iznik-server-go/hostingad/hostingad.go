package hostingad

import (
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

// The Krystal hosting ad: a one-time modal for a signed-in member on the web whose email domain hosts
// a WordPress site that is not already on Krystal, linking to Freegle's affiliate link. It is the
// server experiment krystal-hosting-ad (ux.ServerSwitches): the arm ad is shown the modal, control is
// shown nothing, and both are recorded as an exposure so the click rate can be read per member shown.
// docs/developers/reference/ux-experiments.md, "A hosting ad for members with a WordPress site".
//
// A member is decided once. The decision is the key hostingad in users.settings, the unix time it was
// made, which is written in the same transaction as the exposure. A member whose experiment is off
// (no arm) is not decided, so they are still eligible when it starts.
//
// Its own package, because ux imports user and so user cannot read an arm.
const (
	Experiment  = "krystal-hosting-ad"
	ShownArm    = "ad"
	subjectType = "hosting_ad"
)

// HostingAdResponse says whether the modal is to be shown now. Nothing else: the arm stays on the
// server.
type Response struct {
	Show bool `json:"show"`
}

// HostingAdEventRequest is what the modal reports: "click" when the member pressed the button to
// Krystal, "dismiss" when they closed it.
type EventRequest struct {
	Event string `json:"event"`
}

// HostingAdEventResponse says whether the event was written to the member's exposure. It is false
// the second time the same event is sent, and for a member with no exposure.
type EventResponse struct {
	Recorded bool `json:"recorded"`
}

// hostingAdSite is the domain_hosting row for a member's email domain, when it is a WordPress site
// not on Krystal. 0 when there is none.
func site(userid uint64) uint64 {
	db := database.DBConn

	// The member's email, preferred first, the same choice user.go makes for the profile.
	var email string
	db.Table("users_emails").Select("email").Where("userid = ?", userid).Order("preferred DESC, id ASC").Limit(1).Scan(&email)

	at := strings.LastIndex(email, "@")
	if at < 0 || at == len(email)-1 {
		return 0
	}

	domain := strings.ToLower(strings.TrimSpace(email[at+1:]))

	var id uint64
	db.Table("domain_hosting").Select("id").Where("domain = ? AND wordpress = 1 AND krystal = 0", domain).Limit(1).Scan(&id)

	return id
}

// GetHostingAd decides, once, whether the logged-in member is shown the Krystal hosting ad.
func Get(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	var decided int64
	db.Table("users").Where("id = ? AND JSON_VALID(settings) AND JSON_EXTRACT(settings, '$.hostingad') IS NOT NULL", myid).Count(&decided)
	if decided > 0 {
		return c.JSON(Response{Show: false})
	}

	siteID := site(myid)
	if siteID == 0 {
		return c.JSON(Response{Show: false})
	}

	arm := ux.ExperimentArm(db, myid, "krystal-hosting-ad")
	if arm == "" {
		// Off, or not running: nothing is decided and nothing is recorded.
		return c.JSON(Response{Show: false})
	}

	tx := db.Clauses(dbresolver.Write).Begin()
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not decide")
	}

	// The guard on the key makes the decision once even when two tabs ask at the same moment: the
	// second finds the key written and decides nothing. Settings that are not valid JSON are replaced
	// by an empty object, as the settings writers elsewhere do.
	res := tx.Table("users").
		Where("id = ? AND (NOT JSON_VALID(settings) OR JSON_EXTRACT(settings, '$.hostingad') IS NULL)", myid).
		Update("settings", gorm.Expr("JSON_SET(CASE WHEN JSON_VALID(settings) THEN settings ELSE '{}' END, '$.hostingad', ?)", time.Now().Unix()))
	if res.Error != nil || res.RowsAffected == 0 {
		tx.Rollback()

		return c.JSON(Response{Show: false})
	}

	// A failed exposure row is logged by RecordExposure; the decision still stands, because a member
	// must not be asked twice for the sake of an analytics row.
	_ = ux.RecordExposure(tx, Experiment, arm, myid, subjectType, siteID, nil, nil)

	if err := tx.Commit().Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not decide")
	}

	return c.JSON(Response{Show: arm == ShownArm})
}

// PostHostingAd records what the member did with the modal on their exposure row: the unix time of
// the first click (detail.clicked) or the first dismissal (detail.dismissed). Numbers only, as the
// exposure table requires.
func Post(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req EventRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request")
	}

	// The JSON path is a quoted literal in the statement, chosen here, never from the request.
	var path string
	switch req.Event {
	case "click":
		path = "'$.clicked'"
	case "dismiss":
		path = "'$.dismissed'"
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Unknown event")
	}

	res := database.DBConn.Clauses(dbresolver.Write).Table("ux_experiment_exposures").
		Where("experiment = ? AND userid = ? AND subject_type = ? AND JSON_EXTRACT(COALESCE(detail, '{}'), "+path+") IS NULL",
			Experiment, myid, subjectType).
		Update("detail", gorm.Expr("JSON_SET(COALESCE(detail, '{}'), "+path+", ?)", time.Now().Unix()))
	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not record")
	}

	return c.JSON(EventResponse{Recorded: res.RowsAffected > 0})
}
