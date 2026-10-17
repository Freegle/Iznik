package chat

import (
	"encoding/json"
	"errors"
	stdlog "log"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// errPromptAlreadyAnswered is returned from the transaction that answers a reply confirmation when another answer got there first.
var errPromptAlreadyAnswered = errors.New("prompt already answered")

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
	// Userid and Askedname are set when the prompt was asked of one member in a
	// chat between two, so the other member reads "@name said" rather than
	// "You said" once it is answered.
	Userid    *uint64 `json:"userid,omitempty"`
	Askedname string  `json:"askedname,omitempty"`
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
	Userid     *uint64
	Askedname  *string
}

// memberNameSQL is the name Freegle addresses a member by, for the users row aliased u.
const memberNameSQL = "COALESCE(NULLIF(u.fullname, ''), " +
	"NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), 'A freegler')"

// promptName is a member's name as a card shows it. users.fullname is whatever the member typed, up to 255
// characters on any number of lines. A card is written by the Freegle account, is drawn under its logo and is not
// content checked, so the name on it is the one the API shows everywhere else: control characters and line breaks
// become spaces, utils.TidyName cuts it at an at sign and at 32 characters, and then comes the filter for names that
// pass for Freegle or an authority.
func promptName(raw string) string {
	plain := strings.Map(func(r rune) rune {
		if unicode.IsControl(r) || unicode.IsSpace(r) || unicode.Is(unicode.Cf, r) {
			return ' '
		}

		return r
	}, raw)

	// TidyName cuts at a byte, so it can leave half of a character at the end.
	tidy := strings.ToValidUTF8(utils.TidyName(strings.Join(strings.Fields(plain), " ")), "")

	return user.SanitizeDisplayName(tidy, false)
}

// memberName is how Freegle addresses a member in a chat: their name, as other members see it.
func memberName(db *gorm.DB, id uint64) string {
	var name string
	db.Table("users u").Select(memberNameSQL).Where("u.id = ?", id).Scan(&name)

	return promptName(name)
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

	p := &ChatPrompt{
		Kind:     r.Kind,
		Msgids:   r.posts(),
		Options:  options,
		Answer:   r.Answer,
		Answered: r.AnsweredAt != nil,
		Expired:  r.ExpiresAt != nil && r.ExpiresAt.Before(time.Now()),
		Userid:   r.Userid,
	}

	if r.Userid != nil && r.Askedname != nil {
		p.Askedname = promptName(*r.Askedname)
	}

	return p
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
		Select("chat_prompts.chatmsgid, chat_prompts.msgid, chat_prompts.msgids, chat_prompts.kind, "+
			"chat_prompts.options, chat_prompts.answer, chat_prompts.answered_at, chat_prompts.expires_at, "+
			"chat_prompts.userid, CASE WHEN u.id IS NULL THEN NULL ELSE "+memberNameSQL+" END AS askedname").
		Joins("LEFT JOIN users u ON u.id = chat_prompts.userid").
		Where("chat_prompts.chatmsgid IN ?", ids).
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
		// Set when the prompt was asked of one member in a chat between two.
		Userid        *uint64
		Heldchatmsgid *uint64
	}

	db.Table("chat_prompts cp").
		Select("cp.chatmsgid, cp.msgid, cp.msgids, cp.kind, cp.options, cp.answer, "+
			"cp.answered_at, cp.expires_at, cr.user1, cr.user2, cp.userid, cp.heldchatmsgid").
		Joins("INNER JOIN chat_messages cm ON cm.id = cp.chatmsgid").
		Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
		Where("cp.chatmsgid = ? AND cm.chatid = ?", chatmsgid, chatid).
		Scan(&row)

	if row.Chatmsgid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Prompt not found")
	}

	if (row.User1 != myid && row.User2 != myid) || (row.Userid != nil && *row.Userid != myid) {
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

	answered := map[string]interface{}{
		"answer":      payload.Answer,
		"answered_at": gorm.Expr("NOW()"),
	}
	if row.Kind == utils.PROMPT_KIND_CONFIRM_REPLY {
		// Only this kind has the column's migration behind it; the others never set it.
		answered["answered_via"] = "button"
	}

	var applied bool
	if row.Kind == utils.PROMPT_KIND_CONFIRM_REPLY && row.Heldchatmsgid != nil {
		// The answer and what it does to the reply are one transaction. The answer is claimed first, by the update that
		// only an unanswered question matches, so two taps cannot both win; the reply is withdrawn in the same
		// transaction, so a no that cannot be applied is not recorded and a no that is recorded has been applied. Recorded
		// without being applied, a no would release the reply: chats:process-incoming holds a reply only while its
		// question is unanswered. A failure leaves the question open, and the member can tap again.
		applyErr := db.Transaction(func(tx *gorm.DB) error {
			res := tx.Table("chat_prompts").
				Where("chatmsgid = ? AND answered_at IS NULL", chatmsgid).
				Updates(answered)
			if res.Error != nil {
				return res.Error
			}
			if res.RowsAffected == 0 {
				return errPromptAlreadyAnswered
			}

			var err error
			applied, err = applyReplyConfirmAnswer(tx, payload.Answer, *row.Heldchatmsgid, chatid, myid)

			return err
		})
		if errors.Is(applyErr, errPromptAlreadyAnswered) {
			return fiber.NewError(fiber.StatusConflict, "Already answered")
		}
		if applyErr != nil {
			stdlog.Printf("Could not record answer %q of member %d to reply %d: %v", payload.Answer, myid, *row.Heldchatmsgid, applyErr)

			return fiber.NewError(fiber.StatusInternalServerError, "Could not record answer")
		}
	} else {
		applied = applyPromptAnswer(db, row.Kind, payload.Answer, asRow.posts(), myid)

		// Record last. An answer that changed the post but was not recorded would be
		// re-askable; one recorded but not applied would silently lose the member's
		// input. Applying first and recording second means the worst case is a
		// repeated question, not a lost answer.
		res := db.Table("chat_prompts").
			Where("chatmsgid = ? AND answered_at IS NULL", chatmsgid).
			Updates(answered)
		if res.Error != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Could not record answer")
		}
	}

	// The Freegle chat's measure is a poster answering Freegle's question in their own chat. A reply
	// confirmation is asked of a replier in a chat between two members and is not part of it.
	if row.Kind != utils.PROMPT_KIND_CONFIRM_REPLY {
		db.Table("firstreply_event_metrics").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"count": gorm.Expr("count + 1")}),
		}).Create(map[string]interface{}{
			"day":   gorm.Expr("CURDATE()"),
			"event": gorm.Expr("'prompt_answered'"),
			"count": gorm.Expr("1"),
		})
	}

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
