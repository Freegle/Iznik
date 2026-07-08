package image

import (
	"database/sql"
	"encoding/json"
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// imageTypeConfig maps imgtype names to their database table and parent ID column.
type imageTypeConfig struct {
	Table          string
	IDColumn       string
	HasContentType bool // All image tables except messages_attachments have a NOT NULL contenttype column.
}

var typeConfigs = map[string]imageTypeConfig{
	"Message":        {Table: "messages_attachments", IDColumn: "msgid", HasContentType: false},
	"Group":          {Table: "groups_images", IDColumn: "groupid", HasContentType: true},
	"Newsletter":     {Table: "newsletters_images", IDColumn: "articleid", HasContentType: true},
	"CommunityEvent": {Table: "communityevents_images", IDColumn: "eventid", HasContentType: true},
	"Volunteering":   {Table: "volunteering_images", IDColumn: "opportunityid", HasContentType: true},
	"ChatMessage":    {Table: "chat_images", IDColumn: "chatmsgid", HasContentType: true},
	"User":           {Table: "users_images", IDColumn: "userid", HasContentType: true},
	"Newsfeed":       {Table: "newsfeed_images", IDColumn: "newsfeedid", HasContentType: true},
	"Story":          {Table: "users_stories_images", IDColumn: "storyid", HasContentType: true},
	"Noticeboard":    {Table: "noticeboards_images", IDColumn: "noticeboardid", HasContentType: true},
}

// PostRequest handles all POST /image operations.
// The operation is determined by which fields are present:
// - externaluid present → create new attachment
// - id + rotate present → rotate existing attachment
type PostRequest struct {
	// Create fields
	ExternalUID  string          `json:"externaluid"`
	ExternalMods json.RawMessage `json:"externalmods"`
	Hash         string          `json:"hash"`

	// Rotate fields
	ID     uint64 `json:"id"`
	Rotate *int   `json:"rotate"`

	// Type resolution: either imgtype string or boolean flags
	ImgType        string `json:"imgtype"`
	Type           string `json:"type"` // Alternative field name for imgtype
	MsgID          uint64 `json:"msgid"`
	GroupID        uint64 `json:"groupid"`
	CommunityEvent any    `json:"communityevent"` // Can be uint64 (parent ID) or bool (type flag)
	Volunteering   any    `json:"volunteering"`   // Can be uint64 (parent ID) or bool (type flag)
	ChatMessage    uint64 `json:"chatmessage"`
	UserID         any    `json:"user"` // Can be uint64 (parent ID) or bool (type flag)
	Newsfeed       uint64 `json:"newsfeed"`
	Story          any    `json:"story"`       // Can be bool (type flag)
	Noticeboard    any    `json:"noticeboard"` // Can be bool (type flag)
	Newsletter     uint64 `json:"newsletter"`
}

// resolveType determines the image type from the various possible request formats.
func (req *PostRequest) resolveType() string {
	if req.ImgType != "" {
		return req.ImgType
	}
	if req.Type != "" {
		return req.Type
	}
	// Check boolean flags used by rotation calls.
	if isTruthy(req.CommunityEvent) {
		return "CommunityEvent"
	}
	if isTruthy(req.Volunteering) {
		return "Volunteering"
	}
	if isTruthy(req.UserID) {
		return "User"
	}
	if isTruthy(req.Story) {
		return "Story"
	}
	if isTruthy(req.Noticeboard) {
		return "Noticeboard"
	}
	return "Message"
}

// resolveParentID determines the parent entity ID from the request.
func (req *PostRequest) resolveParentID() uint64 {
	switch req.resolveType() {
	case "Message":
		return req.MsgID
	case "Group":
		return req.GroupID
	case "Newsletter":
		return req.Newsletter
	case "CommunityEvent":
		return toUint64(req.CommunityEvent)
	case "Volunteering":
		return toUint64(req.Volunteering)
	case "ChatMessage":
		return req.ChatMessage
	case "User":
		return toUint64(req.UserID)
	case "Newsfeed":
		return req.Newsfeed
	case "Story":
		return toUint64(req.Story)
	case "Noticeboard":
		return toUint64(req.Noticeboard)
	default:
		return req.MsgID
	}
}

// Post handles POST /image for both creating attachments and rotating images.
//
// @Summary Create or update image attachment
// @Tags Image
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/image [post]
func Post(c *fiber.Ctx) error {
	// No auth required: image attachments are created during the give/post flow
	// before the user has signed up or logged in. The attachment is linked to
	// the user's message later when the draft is submitted.

	// Handle raterecognise before body parsing - this uses query params.
	raterecognise := c.Query("raterecognise", "")
	id := c.Query("id", "")
	if raterecognise != "" && id != "" {
		idNum, _ := strconv.ParseUint(id, 10, 64)
		db := database.DBConn
		db.Exec("UPDATE messages_attachments_recognise SET rating = ? WHERE attid = ?", raterecognise, idNum)
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	var req PostRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	// Determine operation: rotate if id + rotate present, otherwise create.
	if req.ID > 0 && req.Rotate != nil {
		return doRotate(c, &req)
	}

	return doCreate(c, &req)
}

func doCreate(c *fiber.Ctx, req *PostRequest) error {
	if req.ExternalUID == "" {
		return fiber.NewError(fiber.StatusBadRequest, "externaluid is required")
	}

	imgType := req.resolveType()

	cfg, ok := typeConfigs[imgType]
	if !ok {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid imgtype: "+imgType)
	}

	parentID := req.resolveParentID()

	// Avatar uploads (imgtype='User') require authentication and ownership:
	// the requester must be the target user (or an admin/support account).
	// All other imgtypes remain no-auth so the pre-signup give/post flow is not broken.
	if imgType == "User" {
		myid := auth.WhoAmI(c)
		if myid == 0 {
			return fiber.NewError(fiber.StatusUnauthorized, "Authentication required to upload a user avatar")
		}
		if parentID != 0 && myid != parentID && !auth.IsAdminOrSupport(myid) {
			return fiber.NewError(fiber.StatusForbidden, "Cannot set another user's avatar")
		}
	}

	// Use NULL instead of 0 for parent ID - FK constraints require valid references or NULL.
	var parentIDParam interface{}
	if parentID == 0 {
		parentIDParam = nil
	} else {
		parentIDParam = parentID
	}

	var modsStr *string
	if len(req.ExternalMods) > 0 && string(req.ExternalMods) != "null" {
		s := string(req.ExternalMods)
		modsStr = &s
	}

	db := database.DBConn

	// Use the underlying sql.DB to get LastInsertId() directly from the MySQL protocol
	// response — never issue a separate SELECT LAST_INSERT_ID() as it's unsafe under
	// parallel load (GORM's connection pool may assign a different connection).
	sqlDB, err := db.DB()
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Database error")
	}

	var sqlResult sql.Result
	if cfg.HasContentType {
		sqlResult, err = sqlDB.Exec(
			"INSERT INTO `"+cfg.Table+"` (`"+cfg.IDColumn+"`, externaluid, externalmods, hash, contenttype) VALUES (?, ?, ?, ?, 'image/jpeg')",
			parentIDParam, req.ExternalUID, modsStr, utils.NilIfEmpty(req.Hash),
		)
	} else {
		sqlResult, err = sqlDB.Exec(
			"INSERT INTO `"+cfg.Table+"` (`"+cfg.IDColumn+"`, externaluid, externalmods, hash) VALUES (?, ?, ?, ?)",
			parentIDParam, req.ExternalUID, modsStr, utils.NilIfEmpty(req.Hash),
		)
	}

	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create image attachment")
	}

	var id uint64
	lastID, err := sqlResult.LastInsertId()
	if err == nil && lastID > 0 {
		id = uint64(lastID)
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     id,
		"uid":    req.ExternalUID,
	})
}

func doRotate(c *fiber.Ctx, req *PostRequest) error {
	imgType := req.resolveType()

	cfg, ok := typeConfigs[imgType]
	if !ok {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid imgtype")
	}

	modsJSON := `{"rotate":` + strconv.Itoa(*req.Rotate) + `}`

	db := database.DBConn
	result := db.Exec("UPDATE `"+cfg.Table+"` SET externalmods = ? WHERE id = ?", modsJSON, req.ID)

	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to rotate image")
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}

// Legacy GET /image support. The images.ilovefreegle.org vhost rewrites the
// old *img_N.jpg URL forms (still referenced by old emails and external
// links) into /api/image?id=N[&w=..&h=..][&<type>=1], which V1 answered with
// a 302. This endpoint replaces that last live V1 code so app1 can retire
// (front-end migration plan Stage 4).

// getFlagTypes maps the legacy query flags to image types, mirroring V1
// image.php's flag checks and the vhost rewrite forms (gimg_→group,
// nimg_→newsletter, cimg_→communityevent, oimg_→volunteering, simg_→story,
// fimg_→newsfeed, mimg_→chatmessage, uimg_→user, bimg_→noticeboard; plain
// img_ is a message attachment).
var getFlagTypes = []struct {
	flag    string
	imgType string
}{
	{"group", "Group"},
	{"newsletter", "Newsletter"},
	{"communityevent", "CommunityEvent"},
	{"volunteering", "Volunteering"},
	{"story", "Story"},
	{"newsfeed", "Newsfeed"},
	{"chatmessage", "ChatMessage"},
	{"user", "User"},
	{"noticeboard", "Noticeboard"},
}

// archivePrefixes: the only types V1 ever archived to Azure (the switch in
// Attachment::canRedirect); other types never have archived set, so an
// archived flag on them falls through to the default.
var archivePrefixes = map[string]string{
	"Message":        "img",
	"ChatMessage":    "mimg",
	"Newsfeed":       "fimg",
	"CommunityEvent": "cimg",
	"Noticeboard":    "bimg",
}

type legacyAttachment struct {
	Externaluid  string
	Externalmods string
	Externalurl  string
	Archived     int
}

func defaultProfileURL() string {
	site := os.Getenv("USER_SITE")

	if site == "" {
		site = "https://www.ilovefreegle.org"
	}

	if !strings.HasPrefix(site, "http") {
		site = "https://" + site
	}

	return strings.TrimSuffix(site, "/") + "/defaultprofile.png"
}

// Get handles GET /image - resolves a legacy image reference to a redirect.
//
// @Summary Resolve a legacy image URL to a redirect
// @Tags Image
// @Produce json
// @Success 302
// @Router /api/image [get]
func Get(c *fiber.Ctx) error {
	// No auth: these are public image URLs embedded in old emails and pages.
	id, _ := strconv.ParseUint(c.Query("id"), 10, 64)
	w, _ := strconv.Atoi(c.Query("w"))
	h, _ := strconv.Atoi(c.Query("h"))

	imgType := "Message"
	for _, ft := range getFlagTypes {
		if isTruthy(c.Query(ft.flag)) {
			imgType = ft.imgType
			break
		}
	}

	cfg, ok := typeConfigs[imgType]
	if id == 0 || !ok {
		// Seen in the wild (e.g. gimg_0.jpg); V1 sent the default profile image.
		return c.Redirect(defaultProfileURL(), fiber.StatusFound)
	}

	// Every image table has externaluid/externalmods/archived; only
	// messages_attachments has externalurl.
	cols := "COALESCE(externaluid, '') AS externaluid, COALESCE(externalmods, '') AS externalmods, COALESCE(archived, 0) AS archived"
	if imgType == "Message" {
		cols += ", COALESCE(externalurl, '') AS externalurl"
	}

	db := database.DBConn
	var rows []legacyAttachment
	db.Raw("SELECT "+cols+" FROM `"+cfg.Table+"` WHERE id = ?", id).Scan(&rows)

	if len(rows) == 0 {
		return c.Redirect(defaultProfileURL(), fiber.StatusFound)
	}
	row := rows[0]

	// Resolution order mirrors V1 Attachment::canRedirect.
	if row.Externaluid != "" {
		// Uploaded via tusd: serve through the caching delivery proxy. V1
		// ignores w/h here - the proxy serves a browser-scalable image.
		return c.Redirect(misc.GetImageDeliveryUrl(row.Externaluid, row.Externalmods), fiber.StatusFound)
	}

	if row.Externalurl != "" {
		// Hosted elsewhere (e.g. TrashNothing): deliver via the proxy.
		return c.Redirect(misc.GetExternalImageDeliveryUrl(row.Externalurl, row.Externalmods), fiber.StatusFound)
	}

	if row.Archived > 0 {
		if prefix, found := archivePrefixes[imgType]; found {
			return c.Redirect(misc.GetArchivedImageDeliveryUrl(prefix, id, w, h), fiber.StatusFound)
		}
	}

	// Pre-tusd rows whose bytes live in the legacy `data` column. V1 served
	// those bytes straight from the DB; we deliberately don't (migration plan
	// Stage 4 decision) - they are ancient, mostly profile thumbnails, so the
	// default profile image is served rather than keeping blob-serving alive.
	return c.Redirect(defaultProfileURL(), fiber.StatusFound)
}

// isTruthy checks if a value from JSON is truthy (bool true or non-zero number).
func isTruthy(v any) bool {
	if v == nil {
		return false
	}
	switch val := v.(type) {
	case bool:
		return val
	case float64:
		return val != 0
	case string:
		return val != "" && val != "0" && val != "false"
	}
	return false
}

// toUint64 extracts a uint64 from a value that might be a number or bool.
func toUint64(v any) uint64 {
	if v == nil {
		return 0
	}
	switch val := v.(type) {
	case float64:
		return uint64(val)
	case bool:
		return 0
	}
	return 0
}
