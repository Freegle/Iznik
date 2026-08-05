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

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
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
	Automode      string     `json:"automode"`
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
	db.Table("messages").Select("fromuser").Where("id = ? AND deleted IS NULL", msgid).Scan(&offerer)
	if offerer == 0 {
		return 0, false, false
	}
	// The Helper / clearance feature is gated on the Clearance permission.
	allowed = auth.HasPermission(myid, auth.PERM_CLEARANCE) && (offerer == myid || isModForMessage(db, myid, msgid))
	return offerer, allowed, true
}

// msgidForBatch / msgidForReplier / msgidForProposal resolve the owning message id
// so an action that references a child row can be authorised against the message.
func msgidForBatch(db *gorm.DB, batchid uint64) uint64 {
	var msgid uint64
	db.Table("helper_batches").Select("msgid").Where("id = ?", batchid).Scan(&msgid)
	return msgid
}

func msgidForReplier(db *gorm.DB, replierid uint64) uint64 {
	var msgid uint64
	db.Table("helper_repliers r").
		Select("b.msgid").
		Joins("INNER JOIN helper_batches b ON b.id = r.batchid").
		Where("r.id = ?", replierid).
		Scan(&msgid)
	return msgid
}

func msgidForProposal(db *gorm.DB, proposalid uint64) uint64 {
	var msgid uint64
	db.Table("helper_proposals p").
		Select("b.msgid").
		Joins("INNER JOIN helper_batches b ON b.id = p.batchid").
		Where("p.id = ?", proposalid).
		Scan(&msgid)
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
	db.Table("helper_batches").
		Select("id, msgid, offereruserid, status, COALESCE(automode,'automatic') AS automode, briefing, lastpolledat, lastrunat, pausedat").
		Where("msgid = ?", msgid).Scan(&b)
	if b.ID == 0 {
		// No batch yet — the Helper hasn't been started for this offer.
		return c.JSON(fiber.Map{"batch": nil, "repliers": []HelperReplier{}, "proposals": []HelperProposal{}, "sent": []HelperSentMessage{}})
	}
	batch = &b

	var repliers []HelperReplier
	db.Table("helper_repliers").
		Select("id, batchid, userid, chatid, state, collection_ok, criteria_met, transport_ok, distance_miles, "+
			"is_connector, related_to, escalation_reason, parked_reason, next_action, other_items_mentioned, "+
			"cooldown_until, offerer_last_message_at, last_processed_chatmsgid, knowledge").
		Where("batchid = ?", b.ID).
		Order("id ASC").
		Scan(&repliers)

	var itemStates []HelperItemState
	db.Table("helper_item_states s").
		Select("s.id, s.replierid, s.bulkitemid, s.state, s.qty_wanted, s.qty_allocated, s.score, s.score_breakdown").
		Joins("INNER JOIN helper_repliers r ON r.id = s.replierid").
		Where("r.batchid = ?", b.ID).
		Scan(&itemStates)
	byReplier := map[uint64][]HelperItemState{}
	for _, s := range itemStates {
		byReplier[s.Replierid] = append(byReplier[s.Replierid], s)
	}
	for i := range repliers {
		repliers[i].ItemStates = byReplier[repliers[i].ID]
	}

	var proposals []HelperProposal
	db.Table("helper_proposals").
		Select("id, batchid, type, replierid, bulkitemid, summary, proposed_text, payload, rationale, status, "+
			"resolved_text, resolvedat, resolvedby").
		Where("batchid = ?", b.ID).
		Order("(status = 'pending') DESC, id DESC").
		Scan(&proposals)

	var sent []HelperSentMessage
	db.Table("helper_sent_messages").
		Select("id, batchid, chatmsgid, chatid, replierid, kind, auto, proposalid").
		Where("batchid = ?", b.ID).
		Order("id ASC").
		Scan(&sent)

	return c.JSON(fiber.Map{"batch": batch, "repliers": repliers, "proposals": proposals, "sent": sent})
}

// ---------------------------------------------------------------------------
// GET /helper/escalated — every ESCALATED replier across all clearances, for the
// ModTools "needs you" queue. Gated on the Clearance permission.
// ---------------------------------------------------------------------------

type HelperEscalatedRow struct {
	ID               uint64  `json:"id"`
	Batchid          uint64  `json:"batchid"`
	Msgid            uint64  `json:"msgid"`
	Offereruserid    uint64  `json:"offereruserid"`
	Userid           uint64  `json:"userid"`
	Chatid           *uint64 `json:"chatid"`
	EscalationReason *string `json:"escalation_reason"`
	Subject          *string `json:"subject"`
}

func GetHelperEscalated(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !auth.HasPermission(myid, auth.PERM_CLEARANCE) {
		return fiber.NewError(fiber.StatusForbidden, "Not permitted")
	}
	db := database.DBConn
	var rows []HelperEscalatedRow
	db.Table("helper_repliers r").
		Select("r.id, r.batchid, b.msgid, b.offereruserid, r.userid, r.chatid, r.escalation_reason, m.subject").
		Joins("INNER JOIN helper_batches b ON b.id = r.batchid").
		Joins("INNER JOIN messages m ON m.id = b.msgid").
		Where("r.state = 'ESCALATED'").
		Order("r.id DESC").
		Scan(&rows)
	if rows == nil {
		rows = []HelperEscalatedRow{}
	}
	return c.JSON(rows)
}

// ---------------------------------------------------------------------------
// POST /helper — action dispatch (driver writes + human resolves).
// ---------------------------------------------------------------------------

type HelperRequest struct {
	Action string `json:"action"`
	Msgid  uint64 `json:"msgid"`

	// Batch
	Status   *string `json:"status"`
	Automode *string `json:"automode"`
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
	// Clauses(gorm.WithResult())
	// reads the id from the same sql.Result the write returned, which - unlike
	// GORM's own "@id" map writeback - is not skipped when RowsAffected is 0
	// (guaranteed on every duplicate-key hit): see
	// test/orm_insertid_test.go's WithResultBeatsTheRowsAffectedZeroTrap.
	res := gorm.WithResult()
	tx := db.Table("helper_batches").Clauses(res, clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
		},
	}).Create(map[string]interface{}{"msgid": msgid, "offereruserid": offerer, "status": gorm.Expr("'active'")})
	if tx.Error != nil || res.Result == nil {
		return 0
	}
	id, err := res.Result.LastInsertId()
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
	// The driver pings EnsureBatch every loop cycle, so lastpolledat doubles as a
	// heartbeat: the page treats a pause as confirmed once lastpolledat advances
	// past pausedat (the loop has observed the pause and gone idle).
	db.Table("helper_batches").Where("id = ?", batchid).Update("lastpolledat", gorm.Expr("NOW()"))
	if req.Briefing != nil {
		db.Table("helper_batches").Where("id = ?", batchid).Update("briefing", *req.Briefing)
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
		db.Table("helper_batches").Where("id = ?", batchid).
			Updates(map[string]interface{}{"status": *req.Status, "pausedat": gorm.Expr("NOW()")})
	} else {
		db.Table("helper_batches").Where("id = ?", batchid).
			Updates(map[string]interface{}{"status": *req.Status, "pausedat": gorm.Expr("NULL")})
	}
	// The send mode (Automatic vs Approve) is orthogonal to pause/stop. In Approve
	// mode the FSM proposes every outgoing message for the offerer to edit + approve.
	if req.Automode != nil {
		switch *req.Automode {
		case "automatic", "approve":
			db.Table("helper_batches").Where("id = ?", batchid).Update("automode", *req.Automode)
		default:
			return fiber.NewError(fiber.StatusBadRequest, "Invalid automode")
		}
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

	res := gorm.WithResult()
	tx := db.Table("helper_repliers").Clauses(res, clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
		},
	}).Create(map[string]interface{}{"batchid": batchid, "userid": *req.Userid, "state": gorm.Expr("'NEW'")})
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not upsert replier")
	}
	var replierid uint64
	if res.Result != nil {
		if rid, idErr := res.Result.LastInsertId(); idErr == nil {
			replierid = uint64(rid)
		}
	}

	// Only update the fields the caller actually supplied, so partial updates from
	// the driver don't clobber other knowledge.
	//
	// col is a
	// per-call constant from a fixed set of literal call sites below, never
	// caller-controlled, so this is GORM's ordinary per-field Update(col, val)
	// pattern - already proven a few lines up in this same file
	// (helper_batches "automode", line 461) - not a SQL-injection-shaped
	// dynamic column. Every column this closure can be called with is
	// declared as its own shape in ormharness/shapes.json and proven by
	// TestTier2_7ba4875e8aec (iznik-server-go/test).
	set := func(col string, val interface{}) {
		db.Table("helper_repliers").Where("id = ?", replierid).Update(col, val)
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

	res := gorm.WithResult()
	tx := db.Table("helper_item_states").Clauses(res, clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
		},
	}).Create(map[string]interface{}{"replierid": *req.Replierid, "bulkitemid": *req.Bulkitemid, "state": gorm.Expr("'NEW'")})
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not upsert item state")
	}
	var stateid uint64
	if res.Result != nil {
		if sid, idErr := res.Result.LastInsertId(); idErr == nil {
			stateid = uint64(sid)
		}
	}

	// Same reasoning
	// as helperUpsertReplier's "set" above: col is a per-call constant from a
	// fixed set of literal call sites below. Every column this closure can be
	// called with is declared as its own shape in ormharness/shapes.json and
	// proven by TestTier2_b48b319835d0 (iznik-server-go/test).
	set := func(col string, val interface{}) {
		db.Table("helper_item_states").Where("id = ?", stateid).Update(col, val)
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

	// Table()+map Create
	// reads the generated id back from the same sql.Result the INSERT
	// returned (gorm.io/gorm/callbacks/create.go), writing it into the map
	// under "@id" - proven against the real database in
	// test/orm_insertid_test.go, not merely reasoned about.
	row := map[string]interface{}{
		"batchid":       batchid,
		"type":          *req.Type,
		"replierid":     req.Replierid,
		"bulkitemid":    req.Bulkitemid,
		"summary":       req.Summary,
		"proposed_text": req.ProposedText,
		"payload":       req.Payload,
		"rationale":     req.Rationale,
		"status":        gorm.Expr("'pending'"),
	}
	if err := db.Table("helper_proposals").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not create proposal")
	}
	pid, _ := row["@id"].(int64)
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "proposalid": uint64(pid)})
}

// insertHelperChat inserts a chat message from the offerer to the replier and
// records it in helper_sent_messages (AI badge + outbound dedupe). Returns the new
// chat message id.
func insertHelperChat(db *gorm.DB, batchid, chatid, offerer, msgid, replierid uint64, body, kind string, auto bool, proposalid *uint64) uint64 {
	// See 7394291c903d
	// above for why Table()+map Create's "@id" writeback is a safe read of
	// this same INSERT's generated id, not a second connection-scoped query.
	row := map[string]interface{}{
		"chatid":             chatid,
		"userid":             offerer,
		"type":               utils.CHAT_MESSAGE_DEFAULT,
		"refmsgid":           msgid,
		"date":               time.Now(),
		"message":            body,
		"processingrequired": gorm.Expr("1"),
	}
	if err := db.Table("chat_messages").Create(row).Error; err != nil {
		return 0
	}
	cmid, _ := row["@id"].(int64)
	db.Table("chat_rooms").Where("id = ?", chatid).Update("latestmessage", gorm.Expr("NOW()"))
	var rid interface{}
	if replierid > 0 {
		rid = replierid
	}
	db.Table("helper_sent_messages").Clauses(clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "kind"}, Value: clause.Column{Table: "excluded", Name: "kind"}},
		},
	}).Create(map[string]interface{}{
		"batchid":    batchid,
		"chatmsgid":  uint64(cmid),
		"chatid":     chatid,
		"replierid":  rid,
		"kind":       kind,
		"auto":       auto,
		"proposalid": proposalid,
	})
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

	// Pause is enforced HERE, not just in the driver loop: if the offerer has
	// paused (or stopped) the Helper, an auto-send is refused even when the brain
	// is mid-cycle. This closes the timing window where an in-flight run could
	// message a replier after the human stepped in.
	var status string
	db.Table("helper_batches").Select("status").Where("id = ?", batchid).Scan(&status)
	if status != "active" {
		return fiber.NewError(fiber.StatusConflict, "Helper is paused — auto-send refused")
	}

	chatid := findOrCreateUser2UserRoom(db, offerer, *req.Userid)
	if chatid == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not open chat")
	}
	// Ensure a replier record exists (create if the driver is sending before it
	// recorded one) and link the chat. A valid replierid is needed so the
	// first-message disclosure can be deduped per replier.
	var replierid uint64
	db.Table("helper_repliers").Select("id").Where("batchid = ? AND userid = ?", batchid, *req.Userid).Scan(&replierid)
	if replierid == 0 {
		res := gorm.WithResult()
		tx := db.Table("helper_repliers").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "chatid"}, Value: clause.Column{Table: "excluded", Name: "chatid"}},
			},
		}).Create(map[string]interface{}{"batchid": batchid, "userid": *req.Userid, "chatid": chatid, "state": gorm.Expr("'NEW'")})
		if tx.Error == nil && res.Result != nil {
			if id, idErr := res.Result.LastInsertId(); idErr == nil {
				replierid = uint64(id)
			}
		}
	} else {
		db.Table("helper_repliers").Where("id = ?", replierid).Update("chatid", chatid)
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
	// Approve mode: never auto-send. Hold the message as a pending proposal so the
	// offerer can edit it and approve it on the clearance page. Resolving the
	// proposal sends the offerer's (possibly edited) text via helperResolveProposal,
	// so it counts as a human send and gets no automated-message disclosure.
	var automode string
	db.Table("helper_batches").Select("COALESCE(automode, 'automatic')").Where("id = ?", batchid).Scan(&automode)
	if automode == "approve" {
		var ridArg interface{}
		if replierid > 0 {
			ridArg = replierid
		}
		row := map[string]interface{}{
			"batchid":       batchid,
			"type":          gorm.Expr("'message'"),
			"replierid":     ridArg,
			"summary":       "Message to send (" + kind + ")",
			"proposed_text": body,
			"status":        gorm.Expr("'pending'"),
		}
		if err := db.Table("helper_proposals").Create(row).Error; err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Could not create proposal")
		}
		pid, _ := row["@id"].(int64)
		return c.JSON(fiber.Map{"ret": 0, "status": "Proposed", "proposalid": uint64(pid)})
	}
	// On the FIRST message the Helper auto-sends to a replier, append a light-touch
	// disclosure so the conversation isn't silently automated. Only once per
	// replier, and only for auto-sends (human-confirmed sends are the offerer's
	// own words).
	if auto && replierid > 0 {
		var priorAuto int
		// priorAuto is int, not int64,
		// so this keeps Row().Scan (database/sql converts a numeric COUNT(*) into
		// int) rather than GORM's Count, which requires *int64.
		db.Table("helper_sent_messages").Select("COUNT(*)").
			Where("batchid = ? AND replierid = ? AND auto = 1", batchid, replierid).Row().Scan(&priorAuto)
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
	db.Table("helper_proposals").
		Select("id, batchid, type, replierid, bulkitemid, summary, proposed_text, payload, rationale, status").
		Where("id = ?", *req.Proposalid).Scan(&p)
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
		db.Table("helper_proposals").Where("id = ?", *req.Proposalid).
			Updates(map[string]interface{}{"status": gorm.Expr("'dismissed'"), "resolvedat": gorm.Expr("NOW()"), "resolvedby": myid})
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}
	if *req.Decision != "send" {
		return fiber.NewError(fiber.StatusBadRequest, "decision must be 'send' or 'dismiss'")
	}

	// Resolve the replier (userid) for side effects + messaging.
	var replierUserid uint64
	if p.Replierid != nil && *p.Replierid > 0 {
		db.Table("helper_repliers").Select("userid").Where("id = ?", *p.Replierid).Scan(&replierUserid)
	}

	// Side effects by proposal type. Quantity for an allocation comes from payload
	// via the qty field set when the proposal was created (mirrored to item state).
	switch p.Type {
	case "allocation":
		if replierUserid > 0 && p.Bulkitemid != nil && *p.Bulkitemid > 0 {
			var prior string
			db.Table("messages_bulk_items_interest").Select("COALESCE(state,'')").
				Where("bulkitemid = ? AND userid = ?", *p.Bulkitemid, replierUserid).Scan(&prior)
			db.Table("messages_bulk_items_interest").Where("bulkitemid = ? AND userid = ?", *p.Bulkitemid, replierUserid).
				Update("state", gorm.Expr("'Reserved'"))
			// REPLACE INTO via
			// clause.Insert{Modifier: "REPLACE"} - see
			// database.RegisterCustomClauseBuilders for why the plain
			// Modifier field alone is not enough.
			db.Table("messages_promises").Clauses(clause.Insert{Modifier: "REPLACE"}).
				Create(map[string]interface{}{"msgid": msgid, "userid": replierUserid})
			if prior != "Reserved" {
				sendAccessInstructions(db, msgid, offerer, replierUserid)
			}
			if p.Replierid != nil {
				db.Table("helper_item_states").Where("replierid = ? AND bulkitemid = ?", *p.Replierid, *p.Bulkitemid).
					Update("state", gorm.Expr("'ALLOCATED'"))
				db.Table("helper_repliers").
					Where("id = ? AND state IN ('QUALIFIED','GATHERING','NEW','ESCALATED','PARKED_REPLIED','PARKED_QUIET','TIMED_OUT')", *p.Replierid).
					Update("state", gorm.Expr("'ALLOCATED'"))
			}
		}
	case "rejection":
		if p.Replierid != nil && p.Bulkitemid != nil {
			// Identical to 15b15ae11812
			// (withdrawal_notice, below); converted together per gate (h).
			db.Table("helper_item_states").Where("replierid = ? AND bulkitemid = ?", *p.Replierid, *p.Bulkitemid).
				Update("state", gorm.Expr("'REJECTED'"))
		}
		if replierUserid > 0 && p.Bulkitemid != nil {
			db.Table("messages_bulk_items_interest").Where("bulkitemid = ? AND userid = ?", *p.Bulkitemid, replierUserid).
				Update("state", gorm.Expr("'Rejected'"))
		}
	case "withdrawal_notice":
		if p.Replierid != nil && p.Bulkitemid != nil {
			// Identical to 17c08ee0c835
			// (rejection, above); converted together per gate (h).
			db.Table("helper_item_states").Where("replierid = ? AND bulkitemid = ?", *p.Replierid, *p.Bulkitemid).
				Update("state", gorm.Expr("'REJECTED'"))
		}
	case "escalation":
		// Confirming an escalation moves the replier to ESCALATED with the AI's
		// reason, so it surfaces in the offerer's "needs you" view and the
		// cross-clearance ModTools queue.
		if p.Replierid != nil && *p.Replierid > 0 {
			reason := ""
			if p.Summary != nil {
				reason = *p.Summary
			}
			db.Table("helper_repliers").Where("id = ?", *p.Replierid).
				Updates(map[string]interface{}{"state": gorm.Expr("'ESCALATED'"), "escalation_reason": reason})
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

	db.Table("helper_proposals").Where("id = ?", *req.Proposalid).
		Updates(map[string]interface{}{"status": gorm.Expr("'sent'"), "resolved_text": finalText, "resolvedat": gorm.Expr("NOW()"), "resolvedby": myid})
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
