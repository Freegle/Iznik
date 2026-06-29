package message

import (
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
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
	ID         uint64     `json:"id" gorm:"primary_key"`
	Bulkitemid uint64     `json:"bulkitemid"`
	Msgid      uint64     `json:"-"`
	Userid     uint64     `json:"userid"`
	Quantity   uint       `json:"quantity"`
	Cancollect *string    `json:"cancollect"`
	State      string     `json:"state"`
	Chatid     *uint64    `json:"chatid,omitempty"`
	CreatedAt  *time.Time `json:"created_at,omitempty" gorm:"column:created_at"`
	// Firstname and blurred lat/lng are populated for owner/concierge reads only.
	Firstname *string  `json:"firstname,omitempty" gorm:"-"`
	BlurLat   *float64 `json:"lat,omitempty" gorm:"-"`
	BlurLng   *float64 `json:"lng,omitempty" gorm:"-"`
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
	db.Raw("SELECT id, msgid, position, name, quantity, `condition`, dimensions, photourl, description "+
		"FROM messages_bulk_items WHERE msgid = ? ORDER BY position ASC, id ASC", msgid).Scan(&items)

	if len(items) == 0 {
		return nil
	}

	var interest []BulkItemInterest
	db.Raw("SELECT id, bulkitemid, msgid, userid, quantity, cancollect, state, chatid, created_at "+
		"FROM messages_bulk_items_interest WHERE msgid = ?", msgid).Scan(&interest)

	// For the offerer/concierge, enrich interest rows with each replier's
	// firstname and blurred location — the concierge needs them to decide
	// who to allocate items to without exposing precise coordinates.
	if canSeeInterest && len(interest) > 0 {
		// Collect distinct user IDs.
		userids := make([]uint64, 0, len(interest))
		seen := map[uint64]bool{}
		for _, in := range interest {
			if !seen[in.Userid] {
				seen[in.Userid] = true
				userids = append(userids, in.Userid)
			}
		}

		type userRow struct {
			ID        uint64  `gorm:"column:id"`
			Firstname string  `gorm:"column:firstname"`
			Lat       float64 `gorm:"column:lat"`
			Lng       float64 `gorm:"column:lng"`
		}
		var users []userRow
		db.Raw("SELECT u.id, u.firstname, COALESCE(l.lat, 0) AS lat, COALESCE(l.lng, 0) AS lng "+
			"FROM users u "+
			"LEFT JOIN locations l ON l.id = u.lastlocation "+
			"WHERE u.id IN (?)", userids).Scan(&users)

		byUser := make(map[uint64]userRow, len(users))
		for _, u := range users {
			byUser[u.ID] = u
		}
		for i := range interest {
			if u, ok := byUser[interest[i].Userid]; ok {
				if u.Firstname != "" {
					fn := u.Firstname
					interest[i].Firstname = &fn
				}
				if u.Lat != 0 || u.Lng != 0 {
					blat, blng := utils.Blur(u.Lat, u.Lng, utils.BLUR_USER)
					interest[i].BlurLat = &blat
					interest[i].BlurLng = &blng
				}
			}
		}
	}

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

	// Reply-gate: reject if the post is no longer active (has an outcome row).
	var outcomeCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", req.ID).Scan(&outcomeCount)
	if outcomeCount > 0 {
		return fiber.NewError(fiber.StatusConflict, "This offer is no longer available")
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
		db.Raw("SELECT name, quantity, msgid FROM messages_bulk_items WHERE id = ?", in.Bulkitemid).
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
		// Use the shared helper so chat_roster is seeded for both participants,
		// enabling ChatNotificationService email delivery.
		var chatErr error
		chatid, chatErr = chat.GetOrCreateUser2UserChat(db, target, fromuser)
		if chatErr != nil || chatid == 0 {
			return fiber.NewError(fiber.StatusInternalServerError, "Could not create chat room")
		}

		for _, p := range picked {
			// Preserve an offerer-set state (Reserved/Collected/Rejected) — a
			// replier re-expressing interest must not reset their allocation.
			// On re-express, reset processingrequired=1 and processingsuccessful=0
			// so the notification pipeline re-fires (fix #7).
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
		db.Raw("SELECT id FROM messages_bulk_items WHERE msgid = ? ORDER BY position ASC, id ASC", req.ID).Scan(&ordered)
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
		// Dedupe consolidated interest message: use a transaction with
		// SELECT … FOR UPDATE on the existing row to close the race window
		// under concurrent re-express (task 3). No global unique index needed.
		sqlDB, txErr := db.DB()
		if txErr == nil {
			tx, txErr2 := sqlDB.Begin()
			if txErr2 == nil {
				var existingID uint64
				// Lock any existing row so concurrent re-expresses serialise here.
				row := tx.QueryRow("SELECT id FROM chat_messages WHERE chatid = ? AND userid = ? AND refmsgid = ? AND type = ? ORDER BY id DESC LIMIT 1 FOR UPDATE",
					chatid, target, req.ID, utils.CHAT_MESSAGE_INTERESTED)
				_ = row.Scan(&existingID)
				if existingID > 0 {
					// Re-express: update body and reset processing flags so the
					// notification pipeline re-fires (fix #7).
					tx.Exec("UPDATE chat_messages SET message = ?, date = ?, processingrequired = 1, processingsuccessful = 0 WHERE id = ?",
						body, time.Now(), existingID)
				} else {
					tx.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
						chatid, target, utils.CHAT_MESSAGE_INTERESTED, req.ID, time.Now(), body)
				}
				tx.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
				tx.Commit() //nolint:errcheck
			} else {
				// Fallback without transaction — best effort.
				var existingID uint64
				db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND userid = ? AND refmsgid = ? AND type = ? ORDER BY id DESC LIMIT 1",
					chatid, target, req.ID, utils.CHAT_MESSAGE_INTERESTED).Scan(&existingID)
				if existingID > 0 {
					db.Exec("UPDATE chat_messages SET message = ?, date = ?, processingrequired = 1, processingsuccessful = 0 WHERE id = ?", body, time.Now(), existingID)
				} else {
					db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
						chatid, target, utils.CHAT_MESSAGE_INTERESTED, req.ID, time.Now(), body)
				}
				db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
			}
		}
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
	var priorQuantity uint
	db.Raw("SELECT COALESCE(state, ''), COALESCE(quantity, 0) FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?",
		*req.Bulkitemid, *req.Userid).Row().Scan(&priorState, &priorQuantity)

	// Over-allocation guard (fix #4): before reserving, ensure existing Reserved+Collected
	// quantity for OTHER users plus this candidate's quantity does not exceed the item's total.
	if *req.State == "Reserved" && priorState != "Reserved" {
		var itemQty uint
		db.Raw("SELECT quantity FROM messages_bulk_items WHERE id = ?", *req.Bulkitemid).Scan(&itemQty)
		if itemQty > 0 {
			var allocatedByOthers uint
			db.Raw("SELECT COALESCE(SUM(quantity), 0) FROM messages_bulk_items_interest "+
				"WHERE bulkitemid = ? AND userid != ? AND state IN ('Reserved','Collected')",
				*req.Bulkitemid, *req.Userid).Scan(&allocatedByOthers)
			if allocatedByOthers+priorQuantity > itemQty {
				return fiber.NewError(fiber.StatusConflict, "Item is already fully allocated")
			}
		}
	}

	result := db.Exec("UPDATE messages_bulk_items_interest SET state = ? WHERE bulkitemid = ? AND userid = ?",
		*req.State, *req.Bulkitemid, *req.Userid)
	if result.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Interest row not found")
	}

	// Promising an item (moving it to Reserved) mirrors the normal promise flow:
	// write a messages_promises row and post a CHAT_MESSAGE_PROMISED system message.
	if *req.State == "Reserved" && priorState != "Reserved" &&
		priorState != "Collected" {
		// REPLACE INTO is idempotent; mirrors handlePromise exactly.
		db.Exec("REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?)", msgid, *req.Userid)
		// System chat message (blank body, just the type) so the recipient's
		// chat list shows the "Promised" indicator.
		chatid, chatErr := chat.GetOrCreateUser2UserChat(db, fromuser, *req.Userid)
		if chatErr == nil && chatid != 0 {
			db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, '', 1)",
				chatid, fromuser, utils.CHAT_MESSAGE_PROMISED, msgid, time.Now())
			db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
		}
		sendAccessInstructions(db, msgid, fromuser, *req.Userid)
	}

	// Reverse the promise row when leaving Reserved back to Interested.
	if priorState == "Reserved" && *req.State == "Interested" {
		db.Exec("DELETE FROM messages_promises WHERE msgid = ? AND userid = ?", msgid, *req.Userid)
	}

	// Rejection notification (fix #3): when the offerer rejects a replier, send
	// them a chat message so they know the item went to someone else.
	if *req.State == "Rejected" && priorState != "Rejected" {
		chatid, _ := chat.GetOrCreateUser2UserChat(db, fromuser, *req.Userid)
		if chatid != 0 {
			var itemName string
			db.Raw("SELECT name FROM messages_bulk_items WHERE id = ?", *req.Bulkitemid).Scan(&itemName)
			body := "Unfortunately the item you were interested in"
			if itemName != "" {
				body += " (" + itemName + ")"
			}
			body += " has gone to someone else. Thank you for your interest!"
			db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
				chatid, fromuser, utils.CHAT_MESSAGE_DEFAULT, msgid, time.Now(), body)
			db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
		}
	}

	// Collected stats (fix #5): decrement messages.availablenow by the interest
	// quantity when transitioning into Collected; reverse when transitioning away.
	if *req.State == "Collected" && priorState != "Collected" && priorQuantity > 0 {
		db.Exec("UPDATE messages SET availablenow = GREATEST(0, CAST(availablenow AS SIGNED) - ?) WHERE id = ?",
			priorQuantity, msgid)
		// TODO: upsert messages_by(msgid, userid) — schema has msgid+userid unique; the
		// existing handleAddBy/handleRemoveBy helpers manage that table via the outcome flow
		// which already covers single-item messages. For bulk offers the correct approach is
		// to call those helpers or inline the same INSERT…ON DUPLICATE KEY UPDATE pattern
		// from handleOutcome. Deferred because the messages_by table is tied to the outcome
		// flow and bulk offers may have several Collected rows per message; we need to decide
		// whether messages_by should track per-item or per-message totals first.
	} else if priorState == "Collected" && *req.State != "Collected" && priorQuantity > 0 {
		db.Exec("UPDATE messages SET availablenow = LEAST(availableinitially, availablenow + ?) WHERE id = ?",
			priorQuantity, msgid)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// BulkInterestStateItem is one transition in a batch state-change request.
type BulkInterestStateItem struct {
	Bulkitemid uint64 `json:"bulkitemid"`
	Userid     uint64 `json:"userid"`
	State      string `json:"state"`
}

// BulkInterestStateBatchRequest is the request body for POST /message/bulk/state.
// Each entry is applied in order inside a single database transaction; the whole
// batch is rolled back if any entry fails the permission or over-allocation check.
type BulkInterestStateBatchRequest struct {
	Items []BulkInterestStateItem `json:"items"`
}

// HandleBulkInterestStateBatch applies many state transitions for a single bulk
// offer in one call. Exported for route registration.
//
// @Summary Batch-update bulk-offer interest states
// @Description Apply multiple Reserved/Collected/Interested/Withdrawn/Rejected state transitions for a bulk offer in a single atomic call. All transitions are applied in a transaction; any validation failure rolls back the whole batch.
// @Tags message
// @Accept json
// @Produce json
// @Security BearerAuth
// @Param body body BulkInterestStateBatchRequest true "List of {bulkitemid, userid, state} transitions"
// @Success 200 {object} map[string]interface{}
// @Failure 400 {object} fiber.Error
// @Failure 401 {object} fiber.Error
// @Failure 403 {object} fiber.Error
// @Failure 404 {object} fiber.Error
// @Failure 409 {object} fiber.Error
// @Router /message/bulk/state [post]
func HandleBulkInterestStateBatch(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	var batchReq BulkInterestStateBatchRequest
	if err := c.BodyParser(&batchReq); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if len(batchReq.Items) == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "items is required")
	}

	validStates := map[string]bool{
		"Interested": true,
		"Reserved":   true,
		"Collected":  true,
		"Withdrawn":  true,
		"Rejected":   true,
	}

	// Validate all items up-front before touching the DB.
	for _, it := range batchReq.Items {
		if it.Bulkitemid == 0 || it.Userid == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "each item requires bulkitemid and userid")
		}
		if !validStates[it.State] {
			return fiber.NewError(fiber.StatusBadRequest, "invalid state: "+it.State)
		}
	}

	// Cache per-item ownership so we make at most one DB round-trip per item.
	type itemMeta struct {
		msgid    uint64
		fromuser uint64
	}
	itemCache := map[uint64]itemMeta{}

	for _, it := range batchReq.Items {
		if _, ok := itemCache[it.Bulkitemid]; !ok {
			var msgid, fromuser uint64
			db.Raw("SELECT bi.msgid, m.fromuser FROM messages_bulk_items bi "+
				"INNER JOIN messages m ON m.id = bi.msgid WHERE bi.id = ?", it.Bulkitemid).
				Row().Scan(&msgid, &fromuser)
			if msgid == 0 {
				return fiber.NewError(fiber.StatusNotFound, "Item not found")
			}
			itemCache[it.Bulkitemid] = itemMeta{msgid: msgid, fromuser: fromuser}
		}
		meta := itemCache[it.Bulkitemid]
		if meta.fromuser != myid && !isModForMessage(db, myid, meta.msgid) {
			return fiber.NewError(fiber.StatusForbidden, "Not your post")
		}
	}

	// Apply each transition via the shared single-row logic, collecting
	// post-transaction side-effects (promise rows, chat messages) to fire
	// after the transaction commits so they don't hold the DB lock.
	type sideEffect struct {
		kind       string // "promise", "reversePromise", "rejection", "access"
		msgid      uint64
		fromuser   uint64
		userid     uint64
		bulkitemid uint64
	}
	var effects []sideEffect

	sqlDB, err := db.DB()
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Database error")
	}
	tx, err := sqlDB.Begin()
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not begin transaction")
	}
	defer tx.Rollback() //nolint:errcheck

	for _, it := range batchReq.Items {
		meta := itemCache[it.Bulkitemid]

		var priorState string
		var priorQty uint
		tx.QueryRow("SELECT COALESCE(state, ''), COALESCE(quantity, 0) FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?",
			it.Bulkitemid, it.Userid).Scan(&priorState, &priorQty)

		// Over-allocation guard — mirrors handleBulkInterestState exactly.
		if it.State == "Reserved" && priorState != "Reserved" {
			var itemQty uint
			tx.QueryRow("SELECT quantity FROM messages_bulk_items WHERE id = ?", it.Bulkitemid).Scan(&itemQty)
			if itemQty > 0 {
				var allocatedByOthers uint
				tx.QueryRow("SELECT COALESCE(SUM(quantity), 0) FROM messages_bulk_items_interest "+
					"WHERE bulkitemid = ? AND userid != ? AND state IN ('Reserved','Collected')",
					it.Bulkitemid, it.Userid).Scan(&allocatedByOthers)
				if allocatedByOthers+priorQty > itemQty {
					return fiber.NewError(fiber.StatusConflict, "Item is already fully allocated")
				}
			}
		}

		res, execErr := tx.Exec("UPDATE messages_bulk_items_interest SET state = ? WHERE bulkitemid = ? AND userid = ?",
			it.State, it.Bulkitemid, it.Userid)
		if execErr != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Database error")
		}
		ra, _ := res.RowsAffected()
		if ra == 0 {
			return fiber.NewError(fiber.StatusNotFound, "Interest row not found")
		}

		// availablenow bookkeeping — mirrors handleBulkInterestState.
		if it.State == "Collected" && priorState != "Collected" && priorQty > 0 {
			tx.Exec("UPDATE messages SET availablenow = GREATEST(0, CAST(availablenow AS SIGNED) - ?) WHERE id = ?",
				priorQty, meta.msgid)
		} else if priorState == "Collected" && it.State != "Collected" && priorQty > 0 {
			tx.Exec("UPDATE messages SET availablenow = LEAST(availableinitially, availablenow + ?) WHERE id = ?",
				priorQty, meta.msgid)
		}

		// Schedule side-effects for after commit.
		if it.State == "Reserved" && priorState != "Reserved" && priorState != "Collected" {
			effects = append(effects, sideEffect{kind: "promise", msgid: meta.msgid, fromuser: meta.fromuser, userid: it.Userid})
			effects = append(effects, sideEffect{kind: "access", msgid: meta.msgid, fromuser: meta.fromuser, userid: it.Userid})
		}
		if priorState == "Reserved" && it.State == "Interested" {
			effects = append(effects, sideEffect{kind: "reversePromise", msgid: meta.msgid, fromuser: meta.fromuser, userid: it.Userid})
		}
		if it.State == "Rejected" && priorState != "Rejected" {
			effects = append(effects, sideEffect{kind: "rejection", msgid: meta.msgid, fromuser: meta.fromuser, userid: it.Userid, bulkitemid: it.Bulkitemid})
		}
	}

	if err := tx.Commit(); err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Transaction failed")
	}

	// Fire side-effects after the transaction commits.
	for _, e := range effects {
		switch e.kind {
		case "promise":
			db.Exec("REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?)", e.msgid, e.userid)
			chatid, chatErr := chat.GetOrCreateUser2UserChat(db, e.fromuser, e.userid)
			if chatErr == nil && chatid != 0 {
				db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, '', 1)",
					chatid, e.fromuser, utils.CHAT_MESSAGE_PROMISED, e.msgid, time.Now())
				db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
			}
		case "reversePromise":
			db.Exec("DELETE FROM messages_promises WHERE msgid = ? AND userid = ?", e.msgid, e.userid)
		case "access":
			sendAccessInstructions(db, e.msgid, e.fromuser, e.userid)
		case "rejection":
			chatid, _ := chat.GetOrCreateUser2UserChat(db, e.fromuser, e.userid)
			if chatid != 0 {
				var itemName string
				db.Raw("SELECT name FROM messages_bulk_items WHERE id = ?", e.bulkitemid).Scan(&itemName)
				body := "Unfortunately the item you were interested in"
				if itemName != "" {
					body += " (" + itemName + ")"
				}
				body += " has gone to someone else. Thank you for your interest!"
				db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
					chatid, e.fromuser, utils.CHAT_MESSAGE_DEFAULT, e.msgid, time.Now(), body)
				db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
			}
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// sendAccessInstructions delivers the offer's private access instructions to a
// replier via chat, once the offerer has promised (Reserved) them an item. It's
// a no-op when the offer has no instructions set. Exposed (via the var below)
// for tests.
func sendAccessInstructions(db *gorm.DB, msgid uint64, fromuser uint64, touser uint64) {
	var ai string
	db.Raw("SELECT COALESCE(accessinstructions, '') FROM messages WHERE id = ?", msgid).Scan(&ai)
	ai = strings.TrimSpace(ai)
	if ai == "" {
		return
	}
	chatid, err := chat.GetOrCreateUser2UserChat(db, fromuser, touser)
	if err != nil || chatid == 0 {
		return
	}
	body := "Access instructions for collection:\n" + ai
	db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
		chatid, fromuser, utils.CHAT_MESSAGE_DEFAULT, msgid, time.Now(), body)
	db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
}

// findOrCreateUser2UserRoom returns the id of the User2User chat room between
// two users, creating it (without seeding chat_roster) if necessary. Retained
// for helper.go and other callers in this package. For new conversations where
// notification delivery is needed, prefer chat.GetOrCreateUser2UserChat which
// also seeds chat_roster for both participants.
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
func upsertBulkItems(db *gorm.DB, msgid uint64, items []BulkItemInput) (int, error) {
	// Item-count cap (fix #10a): prevent unreasonably large catalogues.
	if len(items) > 200 {
		return 0, fiber.NewError(fiber.StatusBadRequest, "Too many items (max 200)")
	}

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
			res := db.Exec("UPDATE messages_bulk_items SET position = ?, name = ?, quantity = ?, `condition` = ?, dimensions = ?, photourl = ?, description = ? WHERE id = ? AND msgid = ?",
				pos, name, qty, condition, in.Dimensions, in.Photourl, in.Description, itemID, msgid)
			// Orphan attachment guard (fix #6): if RowsAffected==0 the supplied id
			// doesn't belong to this message — skip photo linking for foreign ids.
			if res.RowsAffected == 0 {
				itemID = 0
			}
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
			// Link this item's photos to both the message and the item. Freshly
			// uploaded attachments have no msgid yet, so set it here too. The
			// (msgid IS NULL OR msgid = ?) guard prevents stealing an attachment
			// that already belongs to a different message.
			for _, attID := range in.Attachments {
				db.Exec("UPDATE messages_attachments SET bulkitemid = ?, msgid = ? WHERE id = ? AND (msgid IS NULL OR msgid = ?)",
					itemID, msgid, attID, msgid)
			}
		}
	}

	// Item removal (fix #10b): before CASCADE-deleting items no longer present,
	// withdraw any active interest rows so repliers aren't left in a dangling state.
	// Per-user rejection chat is skipped for now — a batch Withdrawn for removed items
	// is silent. TODO: consider sending a "this item was removed" chat message per
	// interested user once the rejection notification path (fix #3) is confirmed stable.
	if len(keepIDs) > 0 {
		db.Exec("UPDATE messages_bulk_items_interest SET state = 'Withdrawn' "+
			"WHERE msgid = ? AND bulkitemid NOT IN (?) AND state IN ('Interested','Reserved')",
			msgid, keepIDs)
		db.Exec("DELETE FROM messages_bulk_items WHERE msgid = ? AND id NOT IN (?)", msgid, keepIDs)
	} else {
		db.Exec("UPDATE messages_bulk_items_interest SET state = 'Withdrawn' "+
			"WHERE msgid = ? AND state IN ('Interested','Reserved')", msgid)
		db.Exec("DELETE FROM messages_bulk_items WHERE msgid = ?", msgid)
	}

	return total, nil
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
		// Description (fix #8): append non-empty descriptions indented under the
		// item line so textbody/content checks and email consumers see them.
		if in.Description != nil {
			if desc := strings.TrimSpace(*in.Description); desc != "" {
				lines = append(lines, "   "+desc)
			}
		}
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

// Note: a spreadsheet's per-item `photourl` is stored as-is and shown by the
// frontend as a hotlinked preview image. The server does NOT fetch these URLs:
// fetching user-supplied URLs server-side is an SSRF surface that is not specific
// to bulk offers, so it has been removed. Photos uploaded the normal way (the
// per-item picker or the batch tray) still become real Freegle attachments.

// LoadBulkSlots returns the offerer's collection date/time windows for a message,
// in display order. Returns nil when none are set.
func LoadBulkSlots(db *gorm.DB, msgid uint64) []string {
	var slots []string
	db.Raw("SELECT slot FROM messages_bulk_slots WHERE msgid = ? ORDER BY position ASC, id ASC", msgid).Scan(&slots)
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
