package message

// Freegle Helper API — the backend the AI concierge and the clearance management
// page share. The Helper is an LLM loop (run on the offerer's behalf) that manages
// replies to a bulk offer; see plans/active/freegle-helper-concierge.md and
// plans/active/freegle-helper-implementation.md.
//
// All state lives in the helper_* tables so both the driver (which writes it) and
// the management page (which reads it and resolves proposals) work off one source
// of truth. Everything here is authorised as the offerer or a moderator of the
// message: the driver authenticates AS the offerer, and the page is used by the
// offerer, so the same check covers both.
//
// Simple conversational messages are auto-sent by the driver (action "Send",
// auto=true). Complex decisions — allocations/promises, rejections, escalations —
// are queued as proposals for the human to confirm/edit/send (action "Proposal",
// resolved via "ResolveProposal").

import (
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// helperDisclosure is appended to the FIRST message the Helper auto-sends to a
// replier, so an automated conversation is never fully silent about it.
const helperDisclosure = "Just to let you know, some of these messages may come from our automated assistant."

// ---------------------------------------------------------------------------
// Read models (also the JSON shape returned to the management page).
// ---------------------------------------------------------------------------

type HelperBatch struct {
	ID            uint64     `json:"id"`
	Msgid         uint64     `json:"msgid"`
	Offereruserid uint64     `json:"offereruserid"`
	Status        string     `json:"status"`
	Briefing      *string    `json:"briefing"`
	Lastpolledat  *time.Time `json:"lastpolledat"`
	Lastrunat     *time.Time `json:"lastrunat"`
	Pausedat      *time.Time `json:"pausedat"`
}

func (HelperBatch) TableName() string { return "helper_batches" }

type HelperReplier struct {
	ID                     uint64            `json:"id"`
	Batchid                uint64            `json:"batchid"`
	Userid                 uint64            `json:"userid"`
	Chatid                 *uint64           `json:"chatid"`
	State                  string            `json:"state"`
	CollectionOk           string            `json:"collection_ok"`
	CriteriaMet            string            `json:"criteria_met"`
	TransportOk            string            `json:"transport_ok"`
	DistanceMiles          *float64          `json:"distance_miles"`
	IsConnector            bool              `json:"is_connector"`
	RelatedTo              *uint64           `json:"related_to"`
	EscalationReason       *string           `json:"escalation_reason"`
	ParkedReason           *string           `json:"parked_reason"`
	NextAction             *string           `json:"next_action"`
	OtherItemsMentioned    bool              `json:"other_items_mentioned"`
	CooldownUntil          *time.Time        `json:"cooldown_until"`
	OffererLastMessageAt   *time.Time        `json:"offerer_last_message_at"`
	LastProcessedChatmsgid *uint64           `json:"last_processed_chatmsgid"`
	Knowledge              *string           `json:"knowledge"`
	ItemStates             []HelperItemState `json:"item_states" gorm:"-"`
}

func (HelperReplier) TableName() string { return "helper_repliers" }

type HelperItemState struct {
	ID             uint64   `json:"id"`
	Replierid      uint64   `json:"replierid"`
	Bulkitemid     uint64   `json:"bulkitemid"`
	State          string   `json:"state"`
	QtyWanted      uint     `json:"qty_wanted"`
	QtyAllocated   uint     `json:"qty_allocated"`
	Score          *float64 `json:"score"`
	ScoreBreakdown *string  `json:"score_breakdown"`
}

func (HelperItemState) TableName() string { return "helper_item_states" }

type HelperProposal struct {
	ID           uint64     `json:"id"`
	Batchid      uint64     `json:"batchid"`
	Type         string     `json:"type"`
	Replierid    *uint64    `json:"replierid"`
	Bulkitemid   *uint64    `json:"bulkitemid"`
	Summary      *string    `json:"summary"`
	ProposedText *string    `json:"proposed_text"`
	Payload      *string    `json:"payload"`
	Rationale    *string    `json:"rationale"`
	Status       string     `json:"status"`
	ResolvedText *string    `json:"resolved_text"`
	Resolvedat   *time.Time `json:"resolvedat"`
	Resolvedby   *uint64    `json:"resolvedby"`
}

func (HelperProposal) TableName() string { return "helper_proposals" }

type HelperSentMessage struct {
	ID         uint64  `json:"id"`
	Batchid    uint64  `json:"batchid"`
	Chatmsgid  uint64  `json:"chatmsgid"`
	Chatid     uint64  `json:"chatid"`
	Replierid  *uint64 `json:"replierid"`
	Kind       string  `json:"kind"`
	Auto       bool    `json:"auto"`
	Proposalid *uint64 `json:"proposalid"`
}

func (HelperSentMessage) TableName() string { return "helper_sent_messages" }

// ---------------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------------

// helperAuthForMsg returns the message's offerer and whether the caller may manage
// the Helper for it. found is false when the message doesn't exist.
func helperAuthForMsg(db *gorm.DB, myid uint64, msgid uint64) (offerer uint64, allowed bool, found bool) {
	db.Raw("SELECT fromuser FROM messages WHERE id = ? AND deleted IS NULL", msgid).Scan(&offerer)
	if offerer == 0 {
		return 0, false, false
	}
	return offerer, offerer == myid || isModForMessage(db, myid, msgid), true
}

// msgidForBatch / msgidForReplier / msgidForProposal resolve the owning message id
// so an action that references a child row can be authorised against the message.
func msgidForBatch(db *gorm.DB, batchid uint64) uint64 {
	var msgid uint64
	db.Raw("SELECT msgid FROM helper_batches WHERE id = ?", batchid).Scan(&msgid)
	return msgid
}

func msgidForReplier(db *gorm.DB, replierid uint64) uint64 {
	var msgid uint64
	db.Raw("SELECT b.msgid FROM helper_repliers r INNER JOIN helper_batches b ON b.id = r.batchid WHERE r.id = ?", replierid).Scan(&msgid)
	return msgid
}

func msgidForProposal(db *gorm.DB, proposalid uint64) uint64 {
	var msgid uint64
	db.Raw("SELECT b.msgid FROM helper_proposals p INNER JOIN helper_batches b ON b.id = p.batchid WHERE p.id = ?", proposalid).Scan(&msgid)
	return msgid
}

// ---------------------------------------------------------------------------
// GET /helper/:msgid — full Helper state for the management page.
// ---------------------------------------------------------------------------

func GetHelper(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	msgid, err := c.ParamsInt("msgid")
	if err != nil || msgid <= 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}
	db := database.DBConn

	offerer, allowed, found := helperAuthForMsg(db, myid, uint64(msgid))
	if !found {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if !allowed {
		return fiber.NewError(fiber.StatusForbidden, "Not your post")
	}
	_ = offerer

	var batch *HelperBatch
	var b HelperBatch
	db.Raw("SELECT id, msgid, offereruserid, status, briefing, lastpolledat, lastrunat, pausedat FROM helper_batches WHERE msgid = ?", msgid).Scan(&b)
	if b.ID == 0 {
		// No batch yet — the Helper hasn't been started for this offer.
		return c.JSON(fiber.Map{"batch": nil, "repliers": []HelperReplier{}, "proposals": []HelperProposal{}, "sent": []HelperSentMessage{}})
	}
	batch = &b

	var repliers []HelperReplier
	db.Raw("SELECT id, batchid, userid, chatid, state, collection_ok, criteria_met, transport_ok, distance_miles, "+
		"is_connector, related_to, escalation_reason, parked_reason, next_action, other_items_mentioned, "+
		"cooldown_until, offerer_last_message_at, last_processed_chatmsgid, knowledge "+
		"FROM helper_repliers WHERE batchid = ? ORDER BY id ASC", b.ID).Scan(&repliers)

	var itemStates []HelperItemState
	db.Raw("SELECT s.id, s.replierid, s.bulkitemid, s.state, s.qty_wanted, s.qty_allocated, s.score, s.score_breakdown "+
		"FROM helper_item_states s INNER JOIN helper_repliers r ON r.id = s.replierid WHERE r.batchid = ?", b.ID).Scan(&itemStates)
	byReplier := map[uint64][]HelperItemState{}
	for _, s := range itemStates {
		byReplier[s.Replierid] = append(byReplier[s.Replierid], s)
	}
	for i := range repliers {
		repliers[i].ItemStates = byReplier[repliers[i].ID]
	}

	var proposals []HelperProposal
	db.Raw("SELECT id, batchid, type, replierid, bulkitemid, summary, proposed_text, payload, rationale, status, "+
		"resolved_text, resolvedat, resolvedby FROM helper_proposals WHERE batchid = ? ORDER BY (status = 'pending') DESC, id DESC", b.ID).Scan(&proposals)

	var sent []HelperSentMessage
	db.Raw("SELECT id, batchid, chatmsgid, chatid, replierid, kind, auto, proposalid FROM helper_sent_messages WHERE batchid = ? ORDER BY id ASC", b.ID).Scan(&sent)

	return c.JSON(fiber.Map{"batch": batch, "repliers": repliers, "proposals": proposals, "sent": sent})
}

// ---------------------------------------------------------------------------
// POST /helper — action dispatch (driver writes + human resolves).
// ---------------------------------------------------------------------------

type HelperRequest struct {
	Action string `json:"action"`
	Msgid  uint64 `json:"msgid"`

	// Batch
	Status   *string `json:"status"`
	Briefing *string `json:"briefing"`

	// Replier knowledge record
	Userid                 *uint64  `json:"userid"`
	Chatid                 *uint64  `json:"chatid"`
	State                  *string  `json:"state"`
	CollectionOk           *string  `json:"collection_ok"`
	CriteriaMet            *string  `json:"criteria_met"`
	TransportOk            *string  `json:"transport_ok"`
	DistanceMiles          *float64 `json:"distance_miles"`
	IsConnector            *bool    `json:"is_connector"`
	RelatedTo              *uint64  `json:"related_to"`
	EscalationReason       *string  `json:"escalation_reason"`
	ParkedReason           *string  `json:"parked_reason"`
	NextAction             *string  `json:"next_action"`
	OtherItemsMentioned    *bool    `json:"other_items_mentioned"`
	CooldownUntil          *string  `json:"cooldown_until"`
	OffererLastMessageAt   *string  `json:"offerer_last_message_at"`
	LastProcessedChatmsgid *uint64  `json:"last_processed_chatmsgid"`
	Knowledge              *string  `json:"knowledge"`

	// Item state
	Replierid      *uint64  `json:"replierid"`
	Bulkitemid     *uint64  `json:"bulkitemid"`
	QtyWanted      *uint    `json:"qty_wanted"`
	QtyAllocated   *uint    `json:"qty_allocated"`
	Score          *float64 `json:"score"`
	ScoreBreakdown *string  `json:"score_breakdown"`

	// Proposal
	Type         *string `json:"type"`
	Summary      *string `json:"summary"`
	ProposedText *string `json:"proposed_text"`
	Payload      *string `json:"payload"`
	Rationale    *string `json:"rationale"`

	// Resolve proposal
	Proposalid *uint64 `json:"proposalid"`
	Decision   *string `json:"decision"`
	Text       *string `json:"text"`

	// Send / record a chat message
	Body *string `json:"body"`
	Kind *string `json:"kind"`
	Auto *bool   `json:"auto"`
}

func PostHelper(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	var req HelperRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid body")
	}
	db := database.DBConn

	switch req.Action {
	case "EnsureBatch":
		return helperEnsureBatch(c, db, myid, req)
	case "SetStatus":
		return helperSetStatus(c, db, myid, req)
	case "UpsertReplier":
		return helperUpsertReplier(c, db, myid, req)
	case "SetItemState":
		return helperSetItemState(c, db, myid, req)
	case "Proposal":
		return helperCreateProposal(c, db, myid, req)
	case "ResolveProposal":
		return helperResolveProposal(c, db, myid, req)
	case "Send":
		return helperSendAction(c, db, myid, req)
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
	}
}

// authMsg authorises a msgid-scoped action, returning the offerer.
func authMsg(c *fiber.Ctx, db *gorm.DB, myid, msgid uint64) (uint64, error) {
	offerer, allowed, found := helperAuthForMsg(db, myid, msgid)
	if !found {
		return 0, fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if !allowed {
		return 0, fiber.NewError(fiber.StatusForbidden, "Not your post")
	}
	return offerer, nil
}

// ensureBatchRow creates the batch row for a message if absent and returns its id.
func ensureBatchRow(db *gorm.DB, msgid, offerer uint64) uint64 {
	sqlDB, err := db.DB()
	if err != nil {
		return 0
	}
	res, err := sqlDB.Exec("INSERT INTO helper_batches (msgid, offereruserid, status) VALUES (?, ?, 'active') "+
		"ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", msgid, offerer)
	if err != nil {
		return 0
	}
	id, err := res.LastInsertId()
	if err != nil {
		return 0
	}
	return uint64(id)
}

func helperEnsureBatch(c *fiber.Ctx, db *gorm.DB, myid uint64, req HelperRequest) error {
	offerer, err := authMsg(c, db, myid, req.Msgid)
	if err != nil {
		return err
	}
	batchid := ensureBatchRow(db, req.Msgid, offerer)
	if batchid == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not create batch")
	}
	if req.Briefing != nil {
		db.Exec("UPDATE helper_batches SET briefing = ? WHERE id = ?", *req.Briefing, batchid)
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "batchid": batchid})
}

func helperSetStatus(c *fiber.Ctx, db *gorm.DB, myid uint64, req HelperRequest) error {
	if _, err := authMsg(c, db, myid, req.Msgid); err != nil {
		return err
	}
	if req.Status == nil {
		return fiber.NewError(fiber.StatusBadRequest, "status is required")
	}
	switch *req.Status {
	case "active", "paused", "stopped":
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Invalid status")
	}
	offerer, _, _ := helperAuthForMsg(db, myid, req.Msgid)
	batchid := ensureBatchRow(db, req.Msgid, offerer)
	if *req.Status == "paused" {
		db.Exec("UPDATE helper_batches SET status = ?, pausedat = NOW() WHERE id = ?", *req.Status, batchid)
	} else {
		db.Exec("UPDATE helper_batches SET status = ?, pausedat = NULL WHERE id = ?", *req.Status, batchid)
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "batchid": batchid})
}

func helperUpsertReplier(c *fiber.Ctx, db *gorm.DB, myid uint64, req HelperRequest) error {
	offerer, err := authMsg(c, db, myid, req.Msgid)
	if err != nil {
		return err
	}
	if req.Userid == nil || *req.Userid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "userid is required")
	}
	batchid := ensureBatchRow(db, req.Msgid, offerer)

	sqlDB, dberr := db.DB()
	if dberr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "DB error")
	}
	res, exerr := sqlDB.Exec("INSERT INTO helper_repliers (batchid, userid, state) VALUES (?, ?, 'NEW') "+
		"ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", batchid, *req.Userid)
	if exerr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not upsert replier")
	}
	rid, _ := res.LastInsertId()
	replierid := uint64(rid)

	// Only update the fields the caller actually supplied, so partial updates from
	// the driver don't clobber other knowledge.
	set := func(col string, val interface{}) {
		db.Exec("UPDATE helper_repliers SET "+col+" = ? WHERE id = ?", val, replierid)
	}
	if req.Chatid != nil {
		set("chatid", *req.Chatid)
	}
	if req.State != nil {
		set("state", *req.State)
	}
	if req.CollectionOk != nil {
		set("collection_ok", *req.CollectionOk)
	}
	if req.CriteriaMet != nil {
		set("criteria_met", *req.CriteriaMet)
	}
	if req.TransportOk != nil {
		set("transport_ok", *req.TransportOk)
	}
	if req.DistanceMiles != nil {
		set("distance_miles", *req.DistanceMiles)
	}
	if req.IsConnector != nil {
		set("is_connector", *req.IsConnector)
	}
	if req.RelatedTo != nil {
		set("related_to", *req.RelatedTo)
	}
	if req.EscalationReason != nil {
		set("escalation_reason", *req.EscalationReason)
	}
	if req.ParkedReason != nil {
		set("parked_reason", *req.ParkedReason)
	}
	if req.NextAction != nil {
		set("next_action", *req.NextAction)
	}
	if req.OtherItemsMentioned != nil {
		set("other_items_mentioned", *req.OtherItemsMentioned)
	}
	if req.CooldownUntil != nil {
		set("cooldown_until", *req.CooldownUntil)
	}
	if req.OffererLastMessageAt != nil {
		set("offerer_last_message_at", *req.OffererLastMessageAt)
	}
	if req.LastProcessedChatmsgid != nil {
		set("last_processed_chatmsgid", *req.LastProcessedChatmsgid)
	}
	if req.Knowledge != nil {
		set("knowledge", *req.Knowledge)
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "replierid": replierid})
}

func helperSetItemState(c *fiber.Ctx, db *gorm.DB, myid uint64, req HelperRequest) error {
	if req.Replierid == nil || *req.Replierid == 0 || req.Bulkitemid == nil || *req.Bulkitemid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "replierid and bulkitemid are required")
	}
	msgid := msgidForReplier(db, *req.Replierid)
	if msgid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Replier not found")
	}
	if _, err := authMsg(c, db, myid, msgid); err != nil {
		return err
	}

	sqlDB, dberr := db.DB()
	if dberr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "DB error")
	}
	res, exerr := sqlDB.Exec("INSERT INTO helper_item_states (replierid, bulkitemid, state) VALUES (?, ?, 'NEW') "+
		"ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", *req.Replierid, *req.Bulkitemid)
	if exerr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not upsert item state")
	}
	sid, _ := res.LastInsertId()
	stateid := uint64(sid)

	set := func(col string, val interface{}) {
		db.Exec("UPDATE helper_item_states SET "+col+" = ? WHERE id = ?", val, stateid)
	}
	if req.State != nil {
		set("state", *req.State)
	}
	if req.QtyWanted != nil {
		set("qty_wanted", *req.QtyWanted)
	}
	if req.QtyAllocated != nil {
		set("qty_allocated", *req.QtyAllocated)
	}
	if req.Score != nil {
		set("score", *req.Score)
	}
	if req.ScoreBreakdown != nil {
		set("score_breakdown", *req.ScoreBreakdown)
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "itemstateid": stateid})
}

func helperCreateProposal(c *fiber.Ctx, db *gorm.DB, myid uint64, req HelperRequest) error {
	offerer, err := authMsg(c, db, myid, req.Msgid)
	if err != nil {
		return err
	}
	if req.Type == nil {
		return fiber.NewError(fiber.StatusBadRequest, "type is required")
	}
	switch *req.Type {
	case "allocation", "message", "rejection", "escalation", "reminder", "withdrawal_notice":
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Invalid proposal type")
	}
	batchid := ensureBatchRow(db, req.Msgid, offerer)

	sqlDB, dberr := db.DB()
	if dberr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "DB error")
	}
	res, exerr := sqlDB.Exec("INSERT INTO helper_proposals (batchid, type, replierid, bulkitemid, summary, proposed_text, payload, rationale, status) "+
		"VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
		batchid, *req.Type, req.Replierid, req.Bulkitemid, req.Summary, req.ProposedText, req.Payload, req.Rationale)
	if exerr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not create proposal")
	}
	pid, _ := res.LastInsertId()
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "proposalid": uint64(pid)})
}

// insertHelperChat inserts a chat message from the offerer to the replier and
// records it in helper_sent_messages (AI badge + outbound dedupe). Returns the new
// chat message id.
func insertHelperChat(db *gorm.DB, batchid, chatid, offerer, msgid, replierid uint64, body, kind string, auto bool, proposalid *uint64) uint64 {
	sqlDB, err := db.DB()
	if err != nil {
		return 0
	}
	res, err := sqlDB.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, ?, 1)",
		chatid, offerer, utils.CHAT_MESSAGE_DEFAULT, msgid, time.Now(), body)
	if err != nil {
		return 0
	}
	cmid, err := res.LastInsertId()
	if err != nil {
		return 0
	}
	db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatid)
	var rid interface{}
	if replierid > 0 {
		rid = replierid
	}
	db.Exec("INSERT INTO helper_sent_messages (batchid, chatmsgid, chatid, replierid, kind, auto, proposalid) VALUES (?, ?, ?, ?, ?, ?, ?) "+
		"ON DUPLICATE KEY UPDATE kind = VALUES(kind)", batchid, uint64(cmid), chatid, rid, kind, auto, proposalid)
	return uint64(cmid)
}

// helperSendAction is the driver's auto-send path: send a simple conversational
// message to a replier and record it (auto=true). The body is supplied by the LLM.
func helperSendAction(c *fiber.Ctx, db *gorm.DB, myid uint64, req HelperRequest) error {
	offerer, err := authMsg(c, db, myid, req.Msgid)
	if err != nil {
		return err
	}
	if req.Userid == nil || *req.Userid == 0 || req.Body == nil || strings.TrimSpace(*req.Body) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "userid and body are required")
	}
	batchid := ensureBatchRow(db, req.Msgid, offerer)
	chatid := findOrCreateUser2UserRoom(db, offerer, *req.Userid)
	if chatid == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not open chat")
	}
	// Link the chat to the replier record if one exists.
	var replierid uint64
	db.Raw("SELECT id FROM helper_repliers WHERE batchid = ? AND userid = ?", batchid, *req.Userid).Scan(&replierid)
	if replierid > 0 {
		db.Exec("UPDATE helper_repliers SET chatid = ? WHERE id = ?", chatid, replierid)
	}
	kind := "other"
	if req.Kind != nil {
		kind = *req.Kind
	}
	auto := true
	if req.Auto != nil {
		auto = *req.Auto
	}
	body := strings.TrimSpace(*req.Body)
	// On the FIRST message the Helper auto-sends to a replier, append a light-touch
	// disclosure so the conversation isn't silently automated. Only once per
	// replier, and only for auto-sends (human-confirmed sends are the offerer's
	// own words).
	if auto && replierid > 0 {
		var priorAuto int
		db.Raw("SELECT COUNT(*) FROM helper_sent_messages WHERE batchid = ? AND replierid = ? AND auto = 1", batchid, replierid).Scan(&priorAuto)
		if priorAuto == 0 {
			body += "\n\n" + helperDisclosure
		}
	}
	cmid := insertHelperChat(db, batchid, chatid, offerer, req.Msgid, replierid, body, kind, auto, nil)
	if cmid == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not send message")
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "chatid": chatid, "chatmsgid": cmid})
}

// helperResolveProposal is the human path: confirm/edit/send or dismiss a proposal.
// On send it performs the proposal's side effect (allocation/promise, rejection,
// reminder, escalation) and sends the (optionally edited) message.
func helperResolveProposal(c *fiber.Ctx, db *gorm.DB, myid uint64, req HelperRequest) error {
	if req.Proposalid == nil || *req.Proposalid == 0 || req.Decision == nil {
		return fiber.NewError(fiber.StatusBadRequest, "proposalid and decision are required")
	}
	msgid := msgidForProposal(db, *req.Proposalid)
	if msgid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Proposal not found")
	}
	offerer, err := authMsg(c, db, myid, msgid)
	if err != nil {
		return err
	}

	var p HelperProposal
	db.Raw("SELECT id, batchid, type, replierid, bulkitemid, summary, proposed_text, payload, rationale, status FROM helper_proposals WHERE id = ?", *req.Proposalid).Scan(&p)
	if p.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Proposal not found")
	}
	if p.Status != "pending" {
		return fiber.NewError(fiber.StatusConflict, "Proposal already resolved")
	}

	// Final text: the human's edit if supplied, else the AI draft.
	finalText := ""
	if p.ProposedText != nil {
		finalText = *p.ProposedText
	}
	if req.Text != nil {
		finalText = *req.Text
	}
	finalText = strings.TrimSpace(finalText)

	if *req.Decision == "dismiss" {
		db.Exec("UPDATE helper_proposals SET status = 'dismissed', resolvedat = NOW(), resolvedby = ? WHERE id = ?", myid, *req.Proposalid)
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}
	if *req.Decision != "send" {
		return fiber.NewError(fiber.StatusBadRequest, "decision must be 'send' or 'dismiss'")
	}

	// Resolve the replier (userid) for side effects + messaging.
	var replierUserid uint64
	if p.Replierid != nil && *p.Replierid > 0 {
		db.Raw("SELECT userid FROM helper_repliers WHERE id = ?", *p.Replierid).Scan(&replierUserid)
	}

	// Side effects by proposal type. Quantity for an allocation comes from payload
	// via the qty field set when the proposal was created (mirrored to item state).
	switch p.Type {
	case "allocation":
		if replierUserid > 0 && p.Bulkitemid != nil && *p.Bulkitemid > 0 {
			var prior string
			db.Raw("SELECT COALESCE(state,'') FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", *p.Bulkitemid, replierUserid).Scan(&prior)
			db.Exec("UPDATE messages_bulk_items_interest SET state = 'Reserved' WHERE bulkitemid = ? AND userid = ?", *p.Bulkitemid, replierUserid)
			db.Exec("REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?)", msgid, replierUserid)
			if prior != "Reserved" {
				sendAccessInstructions(db, msgid, offerer, replierUserid)
			}
			if p.Replierid != nil {
				db.Exec("UPDATE helper_item_states SET state = 'ALLOCATED' WHERE replierid = ? AND bulkitemid = ?", *p.Replierid, *p.Bulkitemid)
				db.Exec("UPDATE helper_repliers SET state = 'ALLOCATED' WHERE id = ? AND state IN ('QUALIFIED','GATHERING','NEW','ESCALATED','PARKED_REPLIED','PARKED_QUIET','TIMED_OUT')", *p.Replierid)
			}
		}
	case "rejection":
		if p.Replierid != nil && p.Bulkitemid != nil {
			db.Exec("UPDATE helper_item_states SET state = 'REJECTED' WHERE replierid = ? AND bulkitemid = ?", *p.Replierid, *p.Bulkitemid)
		}
		if replierUserid > 0 && p.Bulkitemid != nil {
			db.Exec("UPDATE messages_bulk_items_interest SET state = 'Rejected' WHERE bulkitemid = ? AND userid = ?", *p.Bulkitemid, replierUserid)
		}
	case "withdrawal_notice":
		if p.Replierid != nil && p.Bulkitemid != nil {
			db.Exec("UPDATE helper_item_states SET state = 'REJECTED' WHERE replierid = ? AND bulkitemid = ?", *p.Replierid, *p.Bulkitemid)
		}
	}

	// Send the (optionally edited) message, if any, and record it.
	var chatmsgid uint64
	if finalText != "" && replierUserid > 0 {
		chatid := findOrCreateUser2UserRoom(db, offerer, replierUserid)
		if chatid > 0 {
			kind := proposalKindToSentKind(p.Type)
			chatmsgid = insertHelperChat(db, p.Batchid, chatid, offerer, msgid, derefU64(p.Replierid), finalText, kind, false, &p.ID)
		}
	}

	db.Exec("UPDATE helper_proposals SET status = 'sent', resolved_text = ?, resolvedat = NOW(), resolvedby = ? WHERE id = ?", finalText, myid, *req.Proposalid)
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "chatmsgid": chatmsgid})
}

func proposalKindToSentKind(t string) string {
	switch t {
	case "allocation", "rejection", "reminder", "withdrawal_notice":
		return t
	default:
		return "other"
	}
}

func derefU64(p *uint64) uint64 {
	if p == nil {
		return 0
	}
	return *p
}
