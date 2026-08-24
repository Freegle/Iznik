package chat

import (
	"encoding/json"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// Prompts: questions Freegle asks a member inside an ordinary chat, with a small
// set of tappable answers.
//
// The two questions that most change whether a post succeeds - "could you
// deliver?" and "when does it need to be gone?" - were modals fired the instant
// someone finished posting. Both components are still in the frontend tree and
// neither is wired to anything, which is a fair verdict on the format: at the
// moment of posting the member is trying to finish, and logistics for an item
// nobody has asked for yet is noise. The same question three hours later, when
// nothing has happened, is worth answering.
//
// The question is an ordinary chat message (type 'Prompt'), so notification,
// push, unread state and history all work with no changes. Only the tappable
// part lives here, in chat_prompts.
//
// Email deliberately does not carry the buttons. It carries the question and a
// link to the chat, because an emailed one-click answer would be answerable by
// anyone who ever saw the message - including a forwarded copy - and these
// answers change what the post says.

// PromptOption is one tappable answer.
type PromptOption struct {
	Value   string `json:"value"`
	Label   string `json:"label"`
	Variant string `json:"variant,omitempty"`
	// Input marks an option that takes a value from the member rather than being
	// a fixed choice - currently only "date". The client renders the matching
	// control and sends what was entered as the answer.
	Input string `json:"input,omitempty"`
	// Action is a hint to the client that answering should also take the member
	// somewhere, e.g. "editmessage" to open the post editor. Advisory only: the
	// server records the answer either way.
	Action string `json:"action,omitempty"`
}

// ChatPrompt is the answerable part of a Prompt message, as served to the client.
type ChatPrompt struct {
	Kind string `json:"kind"`
	// Msgids are the posts the question is about and the answer applies to.
	// Usually several: Freegle talks about a member's outstanding posts together,
	// the way a clearance treats its items. The client lists them so the subject
	// is never ambiguous, which is also why the wording names no post.
	Msgids   []uint64       `json:"msgids"`
	Options  []PromptOption `json:"options"`
	Answer   *string        `json:"answer,omitempty"`
	Answered bool           `json:"answered"`
	// Expired prompts still render (the conversation should not develop holes)
	// but stop offering their buttons.
	Expired bool `json:"expired"`
}

type promptRow struct {
	Chatmsgid  uint64
	Msgid      *uint64
	Msgids     []byte
	Kind       string
	Options    []byte
	Answer     *string
	AnsweredAt *time.Time `gorm:"column:answered_at"`
	ExpiresAt  *time.Time `gorm:"column:expires_at"`
}

// posts returns the set the prompt covers, falling back to the single msgid for
// prompts written before the set existed.
func (r promptRow) posts() []uint64 {
	ids := []uint64{}

	if len(r.Msgids) > 0 {
		_ = json.Unmarshal(r.Msgids, &ids)
	}

	if len(ids) == 0 && r.Msgid != nil {
		ids = append(ids, *r.Msgid)
	}

	return ids
}

func (r promptRow) toPrompt() *ChatPrompt {
	options := []PromptOption{}
	if len(r.Options) > 0 {
		// A malformed options blob must not take the whole message fetch down;
		// the prompt then renders as text with nothing to tap, which is exactly
		// what an old client would show anyway.
		_ = json.Unmarshal(r.Options, &options)
	}

	return &ChatPrompt{
		Kind:     r.Kind,
		Msgids:   r.posts(),
		Options:  options,
		Answer:   r.Answer,
		Answered: r.AnsweredAt != nil,
		Expired:  r.ExpiresAt != nil && r.ExpiresAt.Before(time.Now()),
	}
}

// attachPrompts fills in the Prompt field on any message that has one. Batched,
// and a no-op when no message in the fetch is a prompt - which is almost always,
// so the common case costs one type check per message and no query at all.
func attachPrompts(db *gorm.DB, messages []ChatMessageQuery) {
	ids := make([]uint64, 0)
	idx := make(map[uint64]int, len(messages))

	for i, m := range messages {
		if m.Type == utils.CHAT_MESSAGE_PROMPT {
			ids = append(ids, m.ID)
			idx[m.ID] = i
		}
	}

	if len(ids) == 0 {
		return
	}

	var rows []promptRow
	db.Table("chat_prompts").
		Select("chatmsgid, msgid, msgids, kind, options, answer, answered_at, expires_at").
		Where("chatmsgid IN ?", ids).
		Scan(&rows)

	for _, r := range rows {
		if i, ok := idx[r.Chatmsgid]; ok {
			messages[i].Prompt = r.toPrompt()
		}
	}
}

type answerPromptPayload struct {
	Answer string `json:"answer"`
}

// AnswerChatPrompt records the member's answer to a prompt and does whatever the
// answer means.
//
// One implementation, here, rather than one in the API and another in the batch
// app: "by this weekend" has to mean the same date wherever it is answered, and
// two copies of that rule would drift the first time either was touched.
func AnswerChatPrompt(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	chatid, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid chat id")
	}

	chatmsgid, err := strconv.ParseUint(c.Params("mid"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	var payload answerPromptPayload
	if err := c.BodyParser(&payload); err != nil || payload.Answer == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid parameters")
	}

	db := database.DBConn

	// Only the person who was asked may answer, and only in the room the prompt
	// is actually in. Joining the room in rather than trusting the path id stops
	// a prompt in someone else's chat being answered by quoting its id here.
	//
	// Flat struct rather than embedding promptRow: GORM's raw Scan matches columns
	// to top-level fields, and an embedded struct would silently come back zeroed.
	var row struct {
		Chatmsgid  uint64
		Msgid      *uint64
		Msgids     []byte
		Kind       string
		Options    []byte
		Answer     *string
		AnsweredAt *time.Time `gorm:"column:answered_at"`
		ExpiresAt  *time.Time `gorm:"column:expires_at"`
		User1      uint64
		User2      uint64
	}

	db.Table("chat_prompts cp").
		Select("cp.chatmsgid, cp.msgid, cp.msgids, cp.kind, cp.options, cp.answer, "+
			"cp.answered_at, cp.expires_at, cr.user1, cr.user2").
		Joins("INNER JOIN chat_messages cm ON cm.id = cp.chatmsgid").
		Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
		Where("cp.chatmsgid = ? AND cm.chatid = ?", chatmsgid, chatid).
		Scan(&row)

	if row.Chatmsgid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Prompt not found")
	}

	if row.User1 != myid && row.User2 != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your prompt")
	}

	if row.AnsweredAt != nil {
		return fiber.NewError(fiber.StatusConflict, "Already answered")
	}

	if row.ExpiresAt != nil && row.ExpiresAt.Before(time.Now()) {
		return fiber.NewError(fiber.StatusGone, "Prompt expired")
	}

	asRow := promptRow{
		Chatmsgid:  row.Chatmsgid,
		Msgid:      row.Msgid,
		Msgids:     row.Msgids,
		Kind:       row.Kind,
		Options:    row.Options,
		Answer:     row.Answer,
		AnsweredAt: row.AnsweredAt,
		ExpiresAt:  row.ExpiresAt,
	}
	prompt := asRow.toPrompt()
	if !validAnswer(prompt.Options, payload.Answer) {
		return fiber.NewError(fiber.StatusBadRequest, "Not one of the offered answers")
	}

	applied := applyPromptAnswer(db, row.Kind, payload.Answer, asRow.posts(), myid)

	// Record last. An answer that changed the post but was not recorded would be
	// re-askable; one recorded but not applied would silently lose the member's
	// input. Applying first and recording second means the worst case is a
	// repeated question, not a lost answer.
	res := db.Table("chat_prompts").
		Where("chatmsgid = ? AND answered_at IS NULL", chatmsgid).
		Updates(map[string]interface{}{
			"answer":      payload.Answer,
			"answered_at": gorm.Expr("NOW()"),
		})
	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not record answer")
	}

	db.Table("firstreply_event_metrics").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{"count": gorm.Expr("count + 1")}),
	}).Create(map[string]interface{}{
		"day":   gorm.Expr("CURDATE()"),
		"event": gorm.Expr("'prompt_answered'"),
		"count": gorm.Expr("1"),
	})

	return c.JSON(fiber.Map{
		"kind":    row.Kind,
		"answer":  payload.Answer,
		"applied": applied,
	})
}

// validAnswer accepts one of the offered option values, or - when an option
// invites free input - a value of that shape.
//
// The deadline prompt offers a date picker rather than fixed choices, because
// "by this weekend" is wrong for somebody moving house on the 14th and the
// poster already knows their own date. That means the answer cannot be checked
// against a fixed list, so it is checked against a shape and a sane range
// instead: a bare date, today or later, within a year.
func validAnswer(options []PromptOption, answer string) bool {
	for _, o := range options {
		if o.Value == answer {
			return true
		}
		if o.Input == "date" && validDateAnswer(answer) {
			return true
		}
	}
	return false
}

func validDateAnswer(answer string) bool {
	d, err := time.Parse("2006-01-02", answer)
	if err != nil {
		return false
	}

	// Compare on the day, not the instant, so "today" is not rejected for being
	// a few hours in the past.
	now := time.Now()
	today := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC)

	return !d.Before(today) && d.Before(today.AddDate(1, 0, 0))
}

// applyPromptAnswer is what answering actually DOES. Each kind owns its effect
// here, so a new kind is a case in this switch and nothing else.
//
// Applies across the whole set the question covered - one "could you deliver?"
// about six offers means six offers, which is the point of grouping them. Posts
// are matched on fromuser as well as id, so a prompt row pointing somewhere
// unexpected can never edit a stranger's post, and returns true if it changed
// anything at all: a member whose posts have partly gone since still gets credit
// for answering about the ones that remain.
func applyPromptAnswer(db *gorm.DB, kind string, answer string, msgids []uint64, myid uint64) bool {
	if len(msgids) == 0 {
		return false
	}

	switch kind {
	case utils.PROMPT_KIND_DELIVERY:
		// "maybe" is the honest reading of this flag: the poster is open to
		// delivering, not committed to it.
		possible := 0
		if answer == "maybe" {
			possible = 1
		}
		res := db.Table("messages").
			Where("id IN ? AND fromuser = ?", msgids, myid).
			Update("deliverypossible", possible)
		return res.Error == nil && res.RowsAffected > 0

	case utils.PROMPT_KIND_DEADLINE:
		deadline := deadlineFor(answer)
		if deadline == "" {
			// "No rush" is a real answer - it stops us asking again - but it is
			// not a date, so nothing is patched.
			return false
		}
		res := db.Table("messages").
			Where("id IN ? AND fromuser = ?", msgids, myid).
			Update("deadline", deadline)
		return res.Error == nil && res.RowsAffected > 0

	default:
		// Informational prompts: the answer is recorded, nothing is patched.
		return false
	}
}

// deadlineFor turns a deadline answer into a date. Empty for "no rush".
//
// A picked date is used as given. The named timescales are kept because prompts
// sent before the picker existed are still answerable, and silently doing
// nothing with one would lose the member's answer.
func deadlineFor(answer string) string {
	now := time.Now()

	if validDateAnswer(answer) {
		return answer
	}

	switch answer {
	case "weekend":
		// The coming Sunday, or today if it already is one, so "this weekend"
		// never lands in the past.
		days := (int(time.Sunday) - int(now.Weekday()) + 7) % 7
		return now.AddDate(0, 0, days).Format("2006-01-02")
	case "week":
		return now.AddDate(0, 0, 7).Format("2006-01-02")
	case "twoweeks":
		return now.AddDate(0, 0, 14).Format("2006-01-02")
	default:
		return ""
	}
}
