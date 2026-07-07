package message

// Logged-out "secret link" page for a bulk offer (clearance). An external
// item-owner - who may give items away through other channels - opens a page at
// /clearance/update/<token> and toggles each catalogue item available/taken and
// edits its count, WITHOUT logging in.
//
// Security model (mirrors iznik-server-go/amp): the unguessable token in the URL
// is the sole credential. It is stored once per offer on messages_bulk_access
// (one row per msgid) and authorises ONLY availability/count edits to that one
// offer - no session, no account access, and no replier/interest data is ever
// returned. Nulling messages_bulk_access.edittoken revokes the link.

import (
	"crypto/rand"
	"encoding/base64"
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// generateEditToken returns a 32-char URL-safe random secret (192 bits of
// entropy) for a bulk-offer edit link.
func generateEditToken() (string, error) {
	b := make([]byte, 24)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(b), nil
}

// ensureBulkEditToken returns the offer's edit token, creating one on first use.
// The token is stored on the per-offer messages_bulk_access row and is stable
// across calls so a shared link keeps working; nulling the column revokes it. A
// concurrent create is handled by re-reading the stored value.
func ensureBulkEditToken(db *gorm.DB, msgid uint64) string {
	var token string
	db.Raw("SELECT COALESCE(edittoken, '') FROM messages_bulk_access WHERE msgid = ?", msgid).Scan(&token)
	if token != "" {
		return token
	}
	newTok, err := generateEditToken()
	if err != nil || newTok == "" {
		return ""
	}
	// COALESCE keeps any token another request set first (unique index also guards).
	db.Exec("INSERT INTO messages_bulk_access (msgid, edittoken) VALUES (?, ?) "+
		"ON DUPLICATE KEY UPDATE edittoken = COALESCE(edittoken, VALUES(edittoken))", msgid, newTok)
	db.Raw("SELECT COALESCE(edittoken, '') FROM messages_bulk_access WHERE msgid = ?", msgid).Scan(&token)
	return token
}

// resolveBulkEditToken maps a secret edit token to its offer, requiring the
// message to still exist (not deleted) and to actually be a bulk offer. Returns 0
// for an unknown/blank token.
func resolveBulkEditToken(db *gorm.DB, token string) uint64 {
	token = strings.TrimSpace(token)
	// A real token is 32 URL-safe chars; refuse anything implausibly short so a
	// blank/1-char guess can never match an empty column.
	if len(token) < 16 {
		return 0
	}
	var msgid uint64
	db.Raw("SELECT a.msgid FROM messages_bulk_access a "+
		"INNER JOIN messages m ON m.id = a.msgid AND m.deleted IS NULL "+
		"WHERE a.edittoken = ? AND a.edittoken <> '' "+
		"AND EXISTS (SELECT 1 FROM messages_bulk_items bi WHERE bi.msgid = a.msgid) LIMIT 1",
		token).Scan(&msgid)
	return msgid
}

// BulkEditItem is the privacy-trimmed per-item shape returned to the logged-out
// owner page. It deliberately carries NO replier/interest data.
type BulkEditItem struct {
	ID         uint64  `json:"id"`
	Position   uint    `json:"position"`
	Name       string  `json:"name"`
	Quantity   uint    `json:"quantity"`
	Condition  string  `json:"condition"`
	Dimensions *string `json:"dimensions"`
	Available  bool    `json:"available"`
	Photo      string  `json:"photo,omitempty"`
}

// bulkEditItems loads the catalogue for the logged-out page, resolving one
// thumbnail per item (an uploaded attachment preferred, else the item's photourl
// fallback). Same image-domain logic as the main message read.
func bulkEditItems(db *gorm.DB, msgid uint64) []BulkEditItem {
	type row struct {
		ID         uint64
		Position   uint
		Name       string
		Quantity   uint
		Available  int
		Condition  string
		Dimensions *string
		Photourl   *string
	}
	var rows []row
	db.Raw("SELECT id, position, name, quantity, available, `condition`, dimensions, photourl "+
		"FROM messages_bulk_items WHERE msgid = ? ORDER BY position ASC, id ASC", msgid).Scan(&rows)

	// One representative attachment per item (primary first), for the thumbnail.
	type att struct {
		Bulkitemid   uint64
		ID           uint64
		Archived     int
		Externaluid  string
		Externalmods string
	}
	var atts []att
	db.Raw("SELECT bia.bulkitemid, ma.id, ma.archived, "+
		"COALESCE(ma.externaluid, '') AS externaluid, COALESCE(ma.externalmods, '') AS externalmods "+
		"FROM messages_bulk_item_attachments bia "+
		"INNER JOIN messages_attachments ma ON ma.id = bia.attachmentid "+
		"WHERE ma.msgid = ? ORDER BY ma.`primary` DESC, ma.id ASC", msgid).Scan(&atts)
	firstAtt := map[uint64]att{}
	for _, a := range atts {
		if _, ok := firstAtt[a.Bulkitemid]; !ok {
			firstAtt[a.Bulkitemid] = a
		}
	}

	imageDomain := os.Getenv("IMAGE_DOMAIN")
	archiveDomain := os.Getenv("IMAGE_ARCHIVED_DOMAIN")

	items := make([]BulkEditItem, 0, len(rows))
	for _, r := range rows {
		it := BulkEditItem{
			ID:         r.ID,
			Position:   r.Position,
			Name:       r.Name,
			Quantity:   r.Quantity,
			Condition:  r.Condition,
			Dimensions: r.Dimensions,
			Available:  r.Available != 0,
		}
		if a, ok := firstAtt[r.ID]; ok {
			if a.Externaluid != "" {
				it.Photo = misc.GetImageDeliveryUrl(a.Externaluid, a.Externalmods)
			} else if a.Archived > 0 {
				it.Photo = "https://" + archiveDomain + "/timg_" + strconv.FormatUint(a.ID, 10) + ".jpg"
			} else {
				it.Photo = "https://" + imageDomain + "/timg_" + strconv.FormatUint(a.ID, 10) + ".jpg"
			}
		} else if r.Photourl != nil && *r.Photourl != "" {
			it.Photo = *r.Photourl
		}
		items = append(items, it)
	}
	return items
}

// recomputeBulkAvailableNow keeps messages.availablenow in step as the external
// owner toggles availability / edits counts: the sum of quantities across items
// still marked available.
func recomputeBulkAvailableNow(db *gorm.DB, msgid uint64) {
	db.Exec("UPDATE messages SET availablenow = "+
		"(SELECT COALESCE(SUM(quantity), 0) FROM messages_bulk_items WHERE msgid = ? AND available = 1) "+
		"WHERE id = ?", msgid, msgid)
}

// GetBulkEditOffer returns a bulk offer's catalogue for the logged-out owner
// page, authorised solely by the secret token in the path. No JWT, no PII.
//
// @Summary Get a bulk offer's items via a secret update-link token
// @Description Logged-out access for an external item-owner. Returns the offer's catalogue only (no replier data).
// @Tags message
// @Produce json
// @Param token path string true "Secret edit-link token"
// @Success 200 {object} map[string]interface{}
// @Router /bulkoffer/update/{token} [get]
func GetBulkEditOffer(c *fiber.Ctx) error {
	db := database.DBConn
	msgid := resolveBulkEditToken(db, c.Params("token"))
	if msgid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Unknown or expired link")
	}
	var subject string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgid).Scan(&subject)
	items := bulkEditItems(db, msgid)
	return c.JSON(fiber.Map{
		"ret":     0,
		"status":  "Success",
		"id":      msgid,
		"subject": subject,
		"items":   items,
	})
}

// BulkEditUpdateRequest is the write payload from the logged-out page: change one
// item's availability and/or count.
type BulkEditUpdateRequest struct {
	Itemid    uint64 `json:"itemid"`
	Available *bool  `json:"available"`
	Quantity  *int   `json:"quantity"`
}

// PostBulkEditOffer updates one item's availability and/or count, authorised
// solely by the secret token. Two-step check (as in the amp handlers): the token
// resolves the msgid, then the item must belong to that msgid. Recomputes
// messages.availablenow and returns the fresh catalogue.
//
// @Summary Update a bulk offer's item availability/count via a secret token
// @Description Logged-out write for an external item-owner. Toggles available/taken and edits the count for one item.
// @Tags message
// @Accept json
// @Produce json
// @Param token path string true "Secret edit-link token"
// @Success 200 {object} map[string]interface{}
// @Router /bulkoffer/update/{token} [post]
func PostBulkEditOffer(c *fiber.Ctx) error {
	db := database.DBConn
	msgid := resolveBulkEditToken(db, c.Params("token"))
	if msgid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Unknown or expired link")
	}

	var req BulkEditUpdateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid body")
	}
	if req.Itemid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "itemid is required")
	}
	if req.Available == nil && req.Quantity == nil {
		return fiber.NewError(fiber.StatusBadRequest, "Nothing to update")
	}

	// The item must belong to the offer the token authorises.
	var owning uint64
	db.Raw("SELECT msgid FROM messages_bulk_items WHERE id = ?", req.Itemid).Scan(&owning)
	if owning != msgid {
		return fiber.NewError(fiber.StatusNotFound, "Item not found")
	}

	if req.Available != nil {
		av := 0
		if *req.Available {
			av = 1
		}
		db.Exec("UPDATE messages_bulk_items SET available = ? WHERE id = ? AND msgid = ?", av, req.Itemid, msgid)
	}
	if req.Quantity != nil {
		q := *req.Quantity
		if q < 0 {
			q = 0
		}
		db.Exec("UPDATE messages_bulk_items SET quantity = ? WHERE id = ? AND msgid = ?", q, req.Itemid, msgid)
	}

	recomputeBulkAvailableNow(db, msgid)

	items := bulkEditItems(db, msgid)
	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"items":  items,
	})
}
