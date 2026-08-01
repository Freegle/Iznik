package message

import (
	"context"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"sort"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/aiimage"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// BulkItem is one catalogue item within a bulk-offer ("clearance") message.
// A message is a bulk offer when it has rows in messages_bulk_items; the message
// itself stays a normal Offer. See plans/active/bulk-offer-clearance-design.md.
type BulkItem struct {
	ID       uint64 `json:"id" gorm:"primary_key"`
	Msgid    uint64 `json:"-"`
	Position uint   `json:"position"`
	Name     string `json:"name"`
	Quantity uint   `json:"quantity"`
	// Available is the owner's per-item on-offer flag: false once they mark the
	// item taken/gone (here or via the logged-out secret-link update page).
	Available   bool    `json:"available"`
	Condition   string  `json:"condition"`
	Dimensions  *string `json:"dimensions"`
	Photourl    *string `json:"photourl,omitempty"`
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

// linkBulkItemAttachment maps an existing message attachment to a bulk-offer
// catalogue item. This replaces the former messages_attachments.bulkitemid
// column with a separate join table so the core attachments table is untouched.
// One attachment belongs to at most one item (unique attachmentid).
func linkBulkItemAttachment(db *gorm.DB, bulkitemid uint64, attachmentid uint64) {
	db.Exec("INSERT INTO messages_bulk_item_attachments (bulkitemid, attachmentid) VALUES (?, ?) "+
		"ON DUPLICATE KEY UPDATE bulkitemid = VALUES(bulkitemid)", bulkitemid, attachmentid)
}

// loadAccessInstructions / saveAccessInstructions replace the former
// messages.accessinstructions column with a separate per-offer table, keeping the
// core messages table untouched.
func loadAccessInstructions(db *gorm.DB, msgid uint64) string {
	var ai string
	// ORM migration site 0c576c0140ff (wave 1).
	db.Table("messages_bulk_access").Select("COALESCE(accessinstructions, '')").Where("msgid = ?", msgid).Scan(&ai)
	return ai
}

func saveAccessInstructions(db *gorm.DB, msgid uint64, instructions string) {
	db.Exec("INSERT INTO messages_bulk_access (msgid, accessinstructions) VALUES (?, ?) "+
		"ON DUPLICATE KEY UPDATE accessinstructions = VALUES(accessinstructions)", msgid, instructions)
}

// LoadBulkItems returns the catalogue for a message, grouping the supplied
// (already path-resolved) attachments by bulkitemid and computing per-item
// interest. The full per-user interest list is only included when
// canSeeInterest is true (the offerer or a moderator). Returns nil for a
// non-bulk message so the JSON field is omitted.
func LoadBulkItems(db *gorm.DB, msgid uint64, myid uint64, canSeeInterest bool, attachments []MessageAttachment) []BulkItem {
	var items []BulkItem
	// ORM migration site 6d41381ea61b (wave 1).
	db.Table("messages_bulk_items").
		Select("id, msgid, position, name, quantity, available, `condition`, dimensions, photourl, description").
		Where("msgid = ?", msgid).
		Order("position ASC, id ASC").
		Scan(&items)

	if len(items) == 0 {
		return nil
	}

	var interest []BulkItemInterest
	// ORM migration site 4b13c81d7f07 (wave 1).
	db.Table("messages_bulk_items_interest").
		Select("id, bulkitemid, msgid, userid, quantity, cancollect, state, chatid").
		Where("msgid = ?", msgid).
		Scan(&interest)

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
	// ORM migration site 644035e792fd (wave 1).
	db.Table("messages").Select("fromuser").Where("id = ? AND deleted IS NULL", req.ID).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	// Whose interest are we recording? By default the caller's own. The offerer
	// may record a replier's interest on their behalf (e.g. the replier asked
	// for an extra item in free-text chat) by passing interestuserid.
	target := myid
	if req.Interestuserid != nil && *req.Interestuserid != myid {
		if myid != fromuser {
			return fiber.NewError(fiber.StatusForbidden, "Only the giver can record interest for another freegler")
		}
		target = *req.Interestuserid
	}
	if target == fromuser {
		return fiber.NewError(fiber.StatusBadRequest, "Cannot register interest in your own post")
	}

	// Validate each item belongs to this message, then split into active picks
	// and withdrawals. Withdrawals don't need a chat room.
	type selected struct {
		bulkitemid uint64
		name       string
		qty        uint
		cancollect *string
	}
	var picked []selected
	var withdraw []uint64

	for _, in := range req.BulkInterest {
		var itemName string
		var available uint
		var itemMsgid uint64
		// ORM migration site b169ddebf3b7 (wave 1).
		db.Table("messages_bulk_items").Select("name, quantity, msgid").Where("id = ?", in.Bulkitemid).
			Row().Scan(&itemName, &available, &itemMsgid)
		if itemMsgid != req.ID {
			// Unknown item or item from another post — ignore it.
			continue
		}
		if in.Quantity <= 0 {
			withdraw = append(withdraw, in.Bulkitemid)
			continue
		}
		qty := uint(in.Quantity)
		if available > 0 && qty > available {
			qty = available
		}
		picked = append(picked, selected{bulkitemid: in.Bulkitemid, name: itemName, qty: qty, cancollect: in.Cancollect})
	}

	// Withdraw interest (keep the row for history). No chat room needed.
	for _, id := range withdraw {
		db.Exec("UPDATE messages_bulk_items_interest SET state = 'Withdrawn', quantity = 0 WHERE bulkitemid = ? AND userid = ?",
			id, target)
	}

	var chatid uint64
	if len(picked) > 0 {
		// Only now do we need a conversation between the replier and the offerer.
		chatid = findOrCreateUser2UserRoom(db, target, fromuser)

		for _, p := range picked {
			// Preserve an offerer-set state (Reserved/Collected/Rejected) — a
			// replier re-expressing interest must not reset their allocation.
			db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, cancollect, chatid, state) "+
				"VALUES (?, ?, ?, ?, ?, ?, 'Interested') "+
				"ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), cancollect = VALUES(cancollect), chatid = VALUES(chatid), "+
				"state = IF(state IN ('Reserved','Collected','Rejected'), state, 'Interested')",
				p.bulkitemid, req.ID, target, p.qty, p.cancollect, chatid)
		}

		// Map each item to its catalogue reference number (1-based, in catalogue
		// order) — the same #n the offer body and the on-site catalogue show.
		// Including it makes the reply unambiguous even when two items share a
		// name (e.g. two swivel chairs of different colours), so a human or the
		// Freegle Helper / AI can map each line straight back to the offer.
		type orderedItem struct{ ID uint64 }
		var ordered []orderedItem
		// ORM migration site 4c3f0662bf60 (wave 1).
		db.Table("messages_bulk_items").Select("id").Where("msgid = ?", req.ID).
			Order("position ASC, id ASC").Scan(&ordered)
		refByItem := make(map[uint64]int, len(ordered))
		for i, o := range ordered {
			refByItem[o.ID] = i + 1
		}

		// List the picks in catalogue order so the reply reads top-to-bottom.
		sort.SliceStable(picked, func(i, j int) bool {
			return refByItem[picked[i].bulkitemid] < refByItem[picked[j].bulkitemid]
		})

		// One consolidated Interested chat reply: insert the first time, update
		// it on re-express (so the thread isn't spammed with duplicates).
		// Structured one-item-per-line with the reference number, quantity and
		// name; newline-separated so it stays machine-parseable. Names are
		// flattened to a single line to keep the format predictable.
		var lines []string
		for _, p := range picked {
			name := strings.Join(strings.Fields(p.name), " ")
			lines = append(lines, fmt.Sprintf("#%d %s × %d", refByItem[p.bulkitemid], name, p.qty))
		}
		body := "I'd like these items from your offer:\n" + strings.Join(lines, "\n")
		for _, p := range picked {
			if p.cancollect != nil && strings.TrimSpace(*p.cancollect) != "" {
				body += "\nI can collect: " + strings.TrimSpace(*p.cancollect)
				break
			}
		}
		// The recipient's own free-text message to the giver, if they added one.
		if req.Comment != nil && strings.TrimSpace(*req.Comment) != "" {
			body += "\n\n" + strings.TrimSpace(*req.Comment)
		}
		var existingID uint64
		// ORM migration site b3c3cfed62ed (wave 1).
		db.Table("chat_messages").Select("id").
			Where("chatid = ? AND userid = ? AND refmsgid = ? AND type = ?", chatid, target, req.ID, utils.CHAT_MESSAGE_INTERESTED).
			Order("id DESC").Limit(1).Scan(&existingID)
		if existingID > 0 {
			db.Exec("UPDATE chat_messages SET message = ?, date = ? WHERE id = ?", body, time.Now(), existingID)
		} else {
			db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
				chatid, target, utils.CHAT_MESSAGE_INTERESTED, req.ID, time.Now(), body)
		}
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

	var priorState string
	// ORM migration site 14de6b4b23b0 (wave 1).
	db.Table("messages_bulk_items_interest").Select("COALESCE(state, '')").
		Where("bulkitemid = ? AND userid = ?", *req.Bulkitemid, *req.Userid).Scan(&priorState)

	result := db.Exec("UPDATE messages_bulk_items_interest SET state = ? WHERE bulkitemid = ? AND userid = ?",
		*req.State, *req.Bulkitemid, *req.Userid)
	if result.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Interest row not found")
	}

	// Promising an item (moving it to Reserved) reveals the offer's private
	// access instructions to that replier — once, via chat.
	if *req.State == "Reserved" && priorState != "Reserved" {
		sendAccessInstructions(db, msgid, fromuser, *req.Userid)
	}

	// Collected: record who collected the item (messages_by) and update the
	// post's available count via a full recompute. Using recomputeBulkAvailableNow
	// instead of a raw decrement prevents a double-deduction when the external-owner
	// toggle (PostBulkEditOffer) has already marked this item available=0 and
	// recomputed: a second recompute from the same source table is a no-op rather
	// than a further subtraction. Reducing the item's quantity first ensures the
	// recompute reflects the collected units.
	if *req.State == "Collected" && priorState != "Collected" {
		var qty int
		// ORM migration site a1b05269552d (wave 1).
		db.Table("messages_bulk_items_interest").Select("COALESCE(quantity, 1)").
			Where("bulkitemid = ? AND userid = ?", *req.Bulkitemid, *req.Userid).Scan(&qty)
		if qty < 1 {
			qty = 1
		}
		db.Exec("INSERT INTO messages_by (msgid, userid, count) VALUES (?, ?, ?) "+
			"ON DUPLICATE KEY UPDATE count = count + VALUES(count)", msgid, *req.Userid, qty)
		db.Exec("UPDATE messages_bulk_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?", qty, *req.Bulkitemid)
		// If collecting cleared the remaining stock, mark the line unavailable so
		// the external-owner page reflects it and updated_at bumps for flip-day arms.
		db.Exec("UPDATE messages_bulk_items SET available = 0 WHERE id = ? AND quantity = 0", *req.Bulkitemid)
		recomputeBulkAvailableNow(db, msgid)
		recordBulkOutcomeIfComplete(db, msgid)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleBulkEditLink mints (or returns the existing) secret token that lets an
// external item-owner update this bulk offer's item availability/counts from a
// logged-out page, without a Freegle login. Owner or moderator only. The token
// authorises ONLY availability/count edits to this one offer (see bulkEdit.go).
func handleBulkEditLink(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	var fromuser uint64
	// ORM migration site 6f136c99e5de (wave 1).
	db.Table("messages").Select("fromuser").Where("id = ? AND deleted IS NULL", req.ID).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if fromuser != myid && !isModForMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not your post")
	}

	// Only a bulk offer (one with catalogue items) can have an update link.
	var items int64
	// ORM migration site bec9f1bec5e5 (wave 1).
	db.Table("messages_bulk_items").Where("msgid = ?", req.ID).Count(&items)
	if items == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Not a bulk offer")
	}

	token := ensureBulkEditToken(db, req.ID)
	if token == "" {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not create link")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "token": token})
}

// sendAccessInstructions delivers the offer's private access instructions to a
// replier via chat, once the offerer has promised (Reserved) them an item. It's
// a no-op when the offer has no instructions set. Exposed (via the var below)
// for tests.
func sendAccessInstructions(db *gorm.DB, msgid uint64, fromuser uint64, touser uint64) {
	ai := strings.TrimSpace(loadAccessInstructions(db, msgid))
	if ai == "" {
		return
	}
	chatid := findOrCreateUser2UserRoom(db, fromuser, touser)
	if chatid == 0 {
		return
	}
	body := "Access instructions for collection:\n" + ai
	db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
		chatid, fromuser, utils.CHAT_MESSAGE_DEFAULT, msgid, time.Now(), body)
	db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
}

// findOrCreateUser2UserRoom returns the id of the User2User chat room between
// two users, creating it if necessary.
func findOrCreateUser2UserRoom(db *gorm.DB, a uint64, b uint64) uint64 {
	var chatID uint64
	// ORM migration site 24edbdb077a3 (wave 1).
	db.Table("chat_rooms").Select("id").
		Where("chattype = ? AND ((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?))", utils.CHAT_TYPE_USER2USER, a, b, b, a).
		Limit(1).Scan(&chatID)

	if chatID == 0 {
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
		chatID = uint64(id)
	}

	if chatID == 0 {
		return 0
	}

	// Ensure BOTH participants have a chat_roster row. Without one the chat has no
	// seen-pointer for that user, so it shows permanently unread to the offerer and
	// "mark all read" (handleAllSeen) can never clear it — handleAllSeen UPDATEs
	// existing roster rows only and has no INSERT fallback for User2User chats.
	// GetOrCreateChatRoom (the normal reply path) already creates both roster rows;
	// this bulk-offer-interest path previously created the room WITHOUT them, which
	// stranded the offerer's reply as un-clearable unread. Idempotent
	// (ON DUPLICATE KEY UPDATE no-op) so it also heals a pre-existing roster-less room.
	db.Exec("INSERT INTO chat_roster (chatid, userid, status, date) VALUES (?, ?, ?, NOW()) "+
		"ON DUPLICATE KEY UPDATE date = date",
		chatID, a, utils.CHAT_STATUS_ONLINE)
	db.Exec("INSERT INTO chat_roster (chatid, userid, status, date) VALUES (?, ?, ?, NOW()) "+
		"ON DUPLICATE KEY UPDATE date = date",
		chatID, b, utils.CHAT_STATUS_ONLINE)

	return chatID
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
			db.Exec("UPDATE messages_bulk_items SET position = ?, name = ?, quantity = ?, `condition` = ?, dimensions = ?, photourl = ?, description = ? WHERE id = ? AND msgid = ?",
				pos, name, qty, condition, in.Dimensions, in.Photourl, in.Description, itemID, msgid)
		} else {
			sqlDB, err := db.DB()
			if err == nil {
				res, err := sqlDB.Exec("INSERT INTO messages_bulk_items (msgid, position, name, quantity, `condition`, dimensions, photourl, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
					msgid, pos, name, qty, condition, in.Dimensions, in.Photourl, in.Description)
				if err == nil {
					if id, err := res.LastInsertId(); err == nil {
						itemID = uint64(id)
					}
				}
			}
		}

		if itemID > 0 {
			keepIDs = append(keepIDs, itemID)
			// Claim this item's photos for the message (freshly uploaded
			// attachments have no msgid yet; msgid is an existing core column).
			// The (msgid IS NULL OR msgid = ?) guard prevents stealing an
			// attachment that already belongs to a different message. The
			// item linkage lives in the separate messages_bulk_item_attachments
			// table (no schema change to messages_attachments).
			for _, attID := range in.Attachments {
				db.Exec("UPDATE messages_attachments SET msgid = ? WHERE id = ? AND (msgid IS NULL OR msgid = ?)",
					msgid, attID, msgid)
				linkBulkItemAttachment(db, itemID, attID)
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
	Photourl    *string  `json:"photourl"`
	Description *string  `json:"description"`
	Attachments []uint64 `json:"attachments"`
}

// buildBulkSummary returns a human-readable textbody summary of a catalogue and
// the collection windows, so consumers with no structured-data channel (Trash
// Nothing, email replies, search, the V1 digest) still see the items and times
// in plain text. Repliers on those channels answer in free text; the Freegle
// Helper later turns that into structured interest.
func buildBulkSummary(items []BulkItemInput, slots []string) string {
	var lines []string
	n := 0
	for _, in := range items {
		name := strings.TrimSpace(in.Name)
		if name == "" {
			continue
		}
		n++
		qty := in.Quantity
		if qty < 1 {
			qty = 1
		}
		// Reference number disambiguates similar items (same name, different
		// condition) for free-text repliers and the Freegle Helper.
		line := fmt.Sprintf("%d) %d× %s", n, qty, name)
		if in.Condition != "" && in.Condition != "Unknown" {
			line += " (" + in.Condition + ")"
		}
		lines = append(lines, line)
	}
	if len(lines) == 0 {
		return ""
	}
	out := "Items available in this offer:\n" + strings.Join(lines, "\n")

	var windows []string
	for _, s := range slots {
		if s = strings.TrimSpace(s); s != "" {
			windows = append(windows, "- "+s)
		}
	}
	if len(windows) > 0 {
		out += "\n\nCollection times (let us know which suits you):\n" + strings.Join(windows, "\n")
	}

	return out
}

// fetchRemoteImage downloads an image from an http(s) URL, returning its bytes
// and MIME type. Bounded size/time; verifies the content is an image.
// isDisallowedIP reports whether an IP is one a user-supplied fetch must never reach: loopback,
// private (RFC1918), link-local (incl. the 169.254.169.254 cloud-metadata endpoint), unspecified
// or multicast.
// allowLoopbackFetch relaxes the SSRF dialer to permit loopback/private targets. It exists solely so
// unit tests can fetch from a local httptest server (which listens on 127.0.0.1); it stays false in
// production, where the dialer must block every internal address.
var allowLoopbackFetch = false

func isDisallowedIP(ip net.IP) bool {
	if ip == nil {
		return true
	}
	return ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast() ||
		ip.IsLinkLocalMulticast() || ip.IsUnspecified() || ip.IsMulticast()
}

// ssrfSafeDialContext resolves the target host and refuses to connect to any non-public address,
// checked on the ACTUAL resolved IP. http.Transport calls this for every connection including each
// redirect hop, so it also defeats DNS rebinding and redirects to internal hosts.
func ssrfSafeDialContext(ctx context.Context, network, addr string) (net.Conn, error) {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return nil, err
	}
	ips, err := net.DefaultResolver.LookupIPAddr(ctx, host)
	if err != nil {
		return nil, err
	}
	d := &net.Dialer{Timeout: 10 * time.Second}
	var lastErr error = fmt.Errorf("no usable address for %s", host)
	for _, ipa := range ips {
		if !allowLoopbackFetch && isDisallowedIP(ipa.IP) {
			return nil, fmt.Errorf("refusing to connect to non-public address %s", ipa.IP)
		}
		conn, derr := d.DialContext(ctx, network, net.JoinHostPort(ipa.IP.String(), port))
		if derr == nil {
			return conn, nil
		}
		lastErr = derr
	}
	return nil, lastErr
}

func fetchRemoteImage(url string) ([]byte, string, error) {
	if !strings.HasPrefix(url, "http://") && !strings.HasPrefix(url, "https://") {
		return nil, "", fmt.Errorf("not an http(s) url")
	}
	// SECURITY: this fetches an attacker-supplied bulk-offer photo URL server-side. Use an SSRF-safe
	// dialer that blocks internal/private targets, and cap redirects.
	client := &http.Client{
		Timeout:   20 * time.Second,
		Transport: &http.Transport{DialContext: ssrfSafeDialContext},
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 3 {
				return fmt.Errorf("too many redirects")
			}
			return nil
		},
	}
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, "", err
	}
	req.Header.Set("User-Agent", "Freegle bulk-offer photo fetcher")
	resp, err := client.Do(req)
	if err != nil {
		return nil, "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, "", fmt.Errorf("status %d", resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 25<<20)) // 25 MB cap
	if err != nil {
		return nil, "", err
	}
	mime := resp.Header.Get("Content-Type")
	if mime == "" || !strings.HasPrefix(mime, "image/") {
		mime = http.DetectContentType(data)
	}
	if !strings.HasPrefix(mime, "image/") {
		return nil, "", fmt.Errorf("not an image: %s", mime)
	}
	return data, mime, nil
}

// BulkPhotoFetcher is injectable for tests.
var BulkPhotoFetcher = fetchRemoteImage

// ingestBulkItemPhotos downloads any per-item photo URLs that don't yet have an
// uploaded attachment, stores them in image storage (TUS) and links them to the
// item. This is how a spreadsheet's "photo" links become real Freegle photos.
// Run in a goroutine — downloads are slow; the photourl stays as an immediate
// fallback until the attachment lands. Errors are logged only.
func ingestBulkItemPhotos(db *gorm.DB, msgid uint64) {
	type prow struct {
		ID       uint64
		Photourl string
	}
	var rows []prow
	db.Raw("SELECT bi.id, bi.photourl FROM messages_bulk_items bi "+
		"WHERE bi.msgid = ? AND bi.photourl IS NOT NULL AND bi.photourl != '' "+
		"AND NOT EXISTS (SELECT 1 FROM messages_bulk_item_attachments x WHERE x.bulkitemid = bi.id)", msgid).Scan(&rows)

	for _, r := range rows {
		data, mime, err := BulkPhotoFetcher(r.Photourl)
		if err != nil {
			log.Printf("bulk photo ingest: download %s failed: %v", r.Photourl, err)
			continue
		}
		uid, err := aiimage.ImageUploader(data, mime)
		if err != nil {
			log.Printf("bulk photo ingest: upload failed for %s: %v", r.Photourl, err)
			continue
		}
		sqlDB, dberr := db.DB()
		if dberr != nil {
			continue
		}
		res, dberr := sqlDB.Exec("INSERT INTO messages_attachments (msgid, externaluid) VALUES (?, ?)", msgid, uid)
		if dberr != nil {
			log.Printf("bulk photo ingest: attachment insert failed for %s: %v", r.Photourl, dberr)
			continue
		}
		if attID, idErr := res.LastInsertId(); idErr == nil {
			linkBulkItemAttachment(db, r.ID, uint64(attID))
		}
	}
}

// IngestBulkItemPhotosSync is the synchronous variant, exposed for tests.
var IngestBulkItemPhotosSync = ingestBulkItemPhotos

// LoadBulkSlots returns the offerer's collection date/time windows for a message,
// in display order. Returns nil when none are set.
func LoadBulkSlots(db *gorm.DB, msgid uint64) []string {
	var slots []string
	// ORM migration site 7fd0b7c845d2 (wave 1).
	db.Table("messages_bulk_slots").Select("slot").Where("msgid = ?", msgid).
		Order("position ASC, id ASC").Scan(&slots)
	if len(slots) == 0 {
		return nil
	}
	return slots
}

// upsertBulkSlots replaces the collection windows for a message from the supplied
// list (blank entries ignored). An empty/nil slice clears them.
func upsertBulkSlots(db *gorm.DB, msgid uint64, slots []string) {
	db.Exec("DELETE FROM messages_bulk_slots WHERE msgid = ?", msgid)
	pos := 0
	for _, s := range slots {
		s = strings.TrimSpace(s)
		if s == "" {
			continue
		}
		db.Exec("INSERT INTO messages_bulk_slots (msgid, position, slot) VALUES (?, ?, ?)", msgid, pos, s)
		pos++
	}
}
