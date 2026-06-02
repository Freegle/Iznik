package message

import (
	"fmt"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// BulkItem is one catalogue item within a bulk-offer ("clearance") message.
// A message is a bulk offer when it has rows in messages_bulk_items; the message
// itself stays a normal Offer. See plans/active/bulk-offer-clearance-design.md.
type BulkItem struct {
	ID          uint64  `json:"id" gorm:"primary_key"`
	Msgid       uint64  `json:"-"`
	Position    uint    `json:"position"`
	Name        string  `json:"name"`
	Quantity    uint    `json:"quantity"`
	Condition   string  `json:"condition"`
	Dimensions  *string `json:"dimensions"`
	Description *string `json:"description"`
	// Photos for this item, grouped from the message's attachments by bulkitemid.
	Attachments []MessageAttachment `json:"attachments" gorm:"-"`
	// Interest summary (visible to everyone).
	Interestcount      int `json:"interestcount" gorm:"-"`
	Interestedquantity int `json:"interestedquantity" gorm:"-"`
	// The calling user's own interest in this item, if any.
	Yourinterest *BulkItemInterest `json:"yourinterest,omitempty" gorm:"-"`
	// The full interest list — only populated for the offerer / a moderator.
	Interest []BulkItemInterest `json:"interest,omitempty" gorm:"-"`
}

func (BulkItem) TableName() string {
	return "messages_bulk_items"
}

// BulkItemInterest is one user's interest in one catalogue item.
type BulkItemInterest struct {
	ID         uint64  `json:"id" gorm:"primary_key"`
	Bulkitemid uint64  `json:"bulkitemid"`
	Msgid      uint64  `json:"-"`
	Userid     uint64  `json:"userid"`
	Quantity   uint    `json:"quantity"`
	Cancollect *string `json:"cancollect"`
	State      string  `json:"state"`
	Chatid     *uint64 `json:"chatid,omitempty"`
}

func (BulkItemInterest) TableName() string {
	return "messages_bulk_items_interest"
}

// interestIsActive reports whether an interest state counts towards the public
// interest summary (everything except explicit withdrawal/rejection).
func interestIsActive(state string) bool {
	return state != "Withdrawn" && state != "Rejected"
}

// LoadBulkItems returns the catalogue for a message, grouping the supplied
// (already path-resolved) attachments by bulkitemid and computing per-item
// interest. The full per-user interest list is only included when
// canSeeInterest is true (the offerer or a moderator). Returns nil for a
// non-bulk message so the JSON field is omitted.
func LoadBulkItems(db *gorm.DB, msgid uint64, myid uint64, canSeeInterest bool, attachments []MessageAttachment) []BulkItem {
	var items []BulkItem
	db.Raw("SELECT id, msgid, position, name, quantity, `condition`, dimensions, description "+
		"FROM messages_bulk_items WHERE msgid = ? ORDER BY position ASC, id ASC", msgid).Scan(&items)

	if len(items) == 0 {
		return nil
	}

	var interest []BulkItemInterest
	db.Raw("SELECT id, bulkitemid, msgid, userid, quantity, cancollect, state, chatid "+
		"FROM messages_bulk_items_interest WHERE msgid = ?", msgid).Scan(&interest)

	// Index interest by bulk item id.
	byItem := map[uint64][]BulkItemInterest{}
	for _, in := range interest {
		byItem[in.Bulkitemid] = append(byItem[in.Bulkitemid], in)
	}

	for i := range items {
		item := &items[i]

		// Group photos belonging to this item.
		for _, a := range attachments {
			if a.Bulkitemid != nil && *a.Bulkitemid == item.ID {
				item.Attachments = append(item.Attachments, a)
			}
		}

		// Summarise interest and pick out the caller's own row.
		for _, in := range byItem[item.ID] {
			if interestIsActive(in.State) {
				item.Interestcount++
				item.Interestedquantity += int(in.Quantity)
			}
			if in.Userid == myid {
				row := in
				item.Yourinterest = &row
			}
			if canSeeInterest {
				item.Interest = append(item.Interest, in)
			}
		}
	}

	return items
}

// handleBulkInterest records the calling user's interest in one or more catalogue
// items of a bulk offer, and posts a single consolidated "Interested" chat reply
// to the offerer summarising the selection. Re-expressing interest updates the
// existing rows; a quantity of 0 withdraws interest in that item.
func handleBulkInterest(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if len(req.BulkInterest) == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "bulkinterest is required")
	}

	var fromuser uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ? AND deleted IS NULL", req.ID).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if fromuser == myid {
		return fiber.NewError(fiber.StatusBadRequest, "Cannot register interest in your own post")
	}

	// Ensure a User2User conversation with the offerer exists.
	chatid := findOrCreateUser2UserRoom(db, myid, fromuser)

	type selected struct {
		name string
		qty  uint
	}
	var picked []selected

	for _, in := range req.BulkInterest {
		// The item must belong to this message.
		var itemName string
		var available uint
		var itemMsgid uint64
		db.Raw("SELECT name, quantity, msgid FROM messages_bulk_items WHERE id = ?", in.Bulkitemid).
			Row().Scan(&itemName, &available, &itemMsgid)
		if itemMsgid != req.ID {
			// Unknown item or item from another post — ignore it.
			continue
		}

		if in.Quantity <= 0 {
			// Withdraw interest in this item (keep the row for history).
			db.Exec("UPDATE messages_bulk_items_interest SET state = ?, quantity = 0 WHERE bulkitemid = ? AND userid = ?",
				"Withdrawn", in.Bulkitemid, myid)
			continue
		}

		// Cap the requested quantity at what is offered.
		qty := uint(in.Quantity)
		if available > 0 && qty > available {
			qty = available
		}

		db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, cancollect, chatid, state) "+
			"VALUES (?, ?, ?, ?, ?, ?, 'Interested') "+
			"ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), cancollect = VALUES(cancollect), chatid = VALUES(chatid), state = 'Interested'",
			in.Bulkitemid, req.ID, myid, qty, in.Cancollect, chatid)

		picked = append(picked, selected{name: itemName, qty: qty})
	}

	// Post one consolidated chat reply describing the selection so it flows
	// through the existing reply/chat machinery (and is visible to the concierge).
	if len(picked) > 0 && chatid > 0 {
		var parts []string
		for _, p := range picked {
			parts = append(parts, fmt.Sprintf("%d× %s", p.qty, p.name))
		}
		body := "I'm interested in: " + strings.Join(parts, ", ")
		// A single cancollect applies to the whole selection if supplied.
		for _, in := range req.BulkInterest {
			if in.Cancollect != nil && strings.TrimSpace(*in.Cancollect) != "" {
				body += " (can collect: " + strings.TrimSpace(*in.Cancollect) + ")"
				break
			}
		}
		db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
			chatid, myid, utils.CHAT_MESSAGE_INTERESTED, req.ID, time.Now(), body)
		db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "chatid": chatid})
}

// handleBulkInterestState lets the offerer (or a moderator) move a single
// interest row through its lifecycle: Interested → Reserved → Collected, or
// Rejected/Withdrawn. Repliers cannot change state via this action.
func handleBulkInterestState(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if req.Bulkitemid == nil || *req.Bulkitemid == 0 || req.Userid == nil || *req.Userid == 0 || req.State == nil {
		return fiber.NewError(fiber.StatusBadRequest, "bulkitemid, userid and state are required")
	}

	switch *req.State {
	case "Interested", "Reserved", "Collected", "Withdrawn", "Rejected":
		// ok
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Invalid state")
	}

	// Resolve the owning message and check permission.
	var msgid, fromuser uint64
	db.Raw("SELECT bi.msgid, m.fromuser FROM messages_bulk_items bi "+
		"INNER JOIN messages m ON m.id = bi.msgid WHERE bi.id = ?", *req.Bulkitemid).
		Row().Scan(&msgid, &fromuser)
	if msgid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Item not found")
	}
	if fromuser != myid && !isModForMessage(db, myid, msgid) {
		return fiber.NewError(fiber.StatusForbidden, "Not your post")
	}

	db.Exec("UPDATE messages_bulk_items_interest SET state = ? WHERE bulkitemid = ? AND userid = ?",
		*req.State, *req.Bulkitemid, *req.Userid)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// findOrCreateUser2UserRoom returns the id of the User2User chat room between
// two users, creating it if necessary.
func findOrCreateUser2UserRoom(db *gorm.DB, a uint64, b uint64) uint64 {
	var chatID uint64
	db.Raw("SELECT id FROM chat_rooms WHERE chattype = ? AND ((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)) LIMIT 1",
		utils.CHAT_TYPE_USER2USER, a, b, b, a).Scan(&chatID)
	if chatID != 0 {
		return chatID
	}

	sqlDB, err := db.DB()
	if err != nil {
		return 0
	}
	res, err := sqlDB.Exec("INSERT INTO chat_rooms (user1, user2, chattype, latestmessage) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), latestmessage = NOW()",
		a, b, utils.CHAT_TYPE_USER2USER)
	if err != nil {
		return 0
	}
	id, err := res.LastInsertId()
	if err != nil {
		return 0
	}
	return uint64(id)
}

// upsertBulkItems replaces the catalogue for a message from the supplied input.
// Existing items are matched by id; items not present in the input are removed
// (their interest cascades). Returns the total quantity across all items, used
// to keep messages.availableinitially in sync.
func upsertBulkItems(db *gorm.DB, msgid uint64, items []BulkItemInput) int {
	keepIDs := []uint64{}
	total := 0

	for pos, in := range items {
		name := strings.TrimSpace(in.Name)
		if name == "" {
			continue
		}
		qty := in.Quantity
		if qty < 1 {
			qty = 1
		}
		condition := in.Condition
		switch condition {
		case "New", "LikeNew", "Good", "Used", "Poor", "Unknown":
		default:
			condition = "Unknown"
		}
		total += qty

		var itemID uint64
		if in.ID > 0 {
			itemID = in.ID
			db.Exec("UPDATE messages_bulk_items SET position = ?, name = ?, quantity = ?, `condition` = ?, dimensions = ?, description = ? WHERE id = ? AND msgid = ?",
				pos, name, qty, condition, in.Dimensions, in.Description, itemID, msgid)
		} else {
			sqlDB, err := db.DB()
			if err == nil {
				res, err := sqlDB.Exec("INSERT INTO messages_bulk_items (msgid, position, name, quantity, `condition`, dimensions, description) VALUES (?, ?, ?, ?, ?, ?, ?)",
					msgid, pos, name, qty, condition, in.Dimensions, in.Description)
				if err == nil {
					if id, err := res.LastInsertId(); err == nil {
						itemID = uint64(id)
					}
				}
			}
		}

		if itemID > 0 {
			keepIDs = append(keepIDs, itemID)
			// Link this item's photos to both the message and the item. Freshly
			// uploaded attachments have no msgid yet, so set it here too (matching
			// PutMessage's attachment-linking pattern).
			for _, attID := range in.Attachments {
				db.Exec("UPDATE messages_attachments SET bulkitemid = ?, msgid = ? WHERE id = ?", itemID, msgid, attID)
			}
		}
	}

	// Remove items no longer present.
	if len(keepIDs) > 0 {
		db.Exec("DELETE FROM messages_bulk_items WHERE msgid = ? AND id NOT IN (?)", msgid, keepIDs)
	} else {
		db.Exec("DELETE FROM messages_bulk_items WHERE msgid = ?", msgid)
	}

	return total
}

// BulkItemInput is the create/edit payload for one catalogue item (PUT/PATCH).
type BulkItemInput struct {
	ID          uint64   `json:"id"`
	Name        string   `json:"name"`
	Quantity    int      `json:"quantity"`
	Condition   string   `json:"condition"`
	Dimensions  *string  `json:"dimensions"`
	Description *string  `json:"description"`
	Attachments []uint64 `json:"attachments"`
}

// buildBulkSummary returns a human-readable textbody summary of a catalogue, so
// non-bulk-aware consumers (search, V1 digest, plain-text email) still show
// something useful.
func buildBulkSummary(items []BulkItemInput) string {
	var lines []string
	for _, in := range items {
		name := strings.TrimSpace(in.Name)
		if name == "" {
			continue
		}
		qty := in.Quantity
		if qty < 1 {
			qty = 1
		}
		line := fmt.Sprintf("- %d× %s", qty, name)
		if in.Condition != "" && in.Condition != "Unknown" {
			line += " (" + in.Condition + ")"
		}
		lines = append(lines, line)
	}
	if len(lines) == 0 {
		return ""
	}
	return "Items available in this offer:\n" + strings.Join(lines, "\n")
}
