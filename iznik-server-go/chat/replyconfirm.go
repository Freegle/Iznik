package chat

import (
	"encoding/json"
	"fmt"
	stdlog "log"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/firstreply"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/freegle/iznik-server-go/ux"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

// Confirming a reply before the poster gets it.
//
// eee:classify-new reads each new OFFER's photo and records whether the item looks damaged and
// roughly how big and heavy it is (messages_eee). When someone replies to an offer that looks
// damaged, or that looks like it needs a car to collect, Freegle asks them in the chat whether
// they still want it, and the reply waits for the answer. That saves the poster a reply from
// someone who would have backed out on seeing the item, or on finding it would not fit in a bag.
//
// The question is an ordinary Prompt message (see chatprompt.go), @-addressed to and asked only
// of the replier: chat_prompts.userid says who may answer it. It is written already processed
// and unsuccessful, the state every delivery path treats as "not for the other member", so
// while it waits the poster is not shown it, notified of it, or has their chat list changed by
// it. When the reply is delivered, ChatProcessService marks the question successful in the same
// step, so the poster sees the question, the answer and the reply together; after a "no" they
// see none of them. A reply that is processed but held for review, or dropped by a block keyword,
// is not delivered, so its question stays hidden too: it is shown with the reply if a moderator
// approves the reply (revealQuestionOfApprovedReply), and never if they reject it.
//
// The reply is held by leaving it unprocessed: chats:process-incoming skips a message named in
// an unanswered prompt's heldchatmsgid (ChatProcessService), and an unprocessed message is only
// ever shown to its sender. "Yes" lets the next run deliver it, "no" drops it, and a prompt that
// expires unanswered is dropped by the batch processor. The batch takes any committed unprocessed
// reply, whatever its age, so the reply and its hold are written in one transaction
// (CreateChatMessage), and the decision to ask is made before either is written (planReplyConfirm).
//
// Only the two buttons answer. Anything else the replier writes in that chat while the question is open
// is held behind it: the batch leaves it unprocessed too, a yes delivers it after the reply, and a no
// withdraws it with the reply (applyReplyConfirmAnswer). Text is never read as an answer.
//
// TrashNothing members are left out on both sides: their replies and posts travel through
// TrashNothing, where nobody would see the question.
//
// It is the experiment confirm-reply, so only repliers whose arm is ask are asked; the others
// are counted and left alone.
//
// A child car seat is asked about whatever the experiment is doing (Discourse 9939). A seat that has
// been in a crash, or is past the life its maker gave it, can look fine and fail, and most people do
// not know that. The question tells the replier what to check before they accept one, with the
// wording the moderators settled on, and the reply waits for their answer in the same way. That is
// policy, not an experiment: it has no arm, writes no exposure, and is asked in the app too.

// questionAskedOfMeSQL is true for a chat message that is a reply question still open and asked of the member given by
// its one placeholder. The question is written unsuccessful, so that nothing delivers it to the poster, and the member it
// was asked of counts it as unseen all the same: a question they never see is a reply dropped three days later with
// nobody told. Once it is answered or has run out it is no longer unseen, so it does not stay in the count.
const questionAskedOfMeSQL = "EXISTS (SELECT 1 FROM chat_prompts cpq WHERE cpq.chatmsgid = chat_messages.id AND cpq.kind = '" +
	utils.PROMPT_KIND_CONFIRM_REPLY + "' AND cpq.userid = ? AND cpq.answered_at IS NULL AND (cpq.expires_at IS NULL OR cpq.expires_at > NOW()))"

// replyConfirmExpiryDays is how long the question stays answerable before the reply is dropped.
const replyConfirmExpiryDays = 3

type replyConfirmEee struct {
	ID            uint64  `gorm:"column:id"`
	ItemCondition *string `gorm:"column:item_condition"`
	SizeBucket    *string `gorm:"column:size_bucket"`
	WeightBucket  *string `gorm:"column:weight_bucket"`
}

// needsACar says whether the item looks too big or heavy to carry home: the size the model
// called large, or a weight from 20kg up.
func (e replyConfirmEee) needsACar() bool {
	if e.SizeBucket != nil && *e.SizeBucket == "large" {
		return true
	}

	return e.WeightBucket != nil && (*e.WeightBucket == "20_100kg" || *e.WeightBucket == "over_100kg")
}

func (e replyConfirmEee) damaged() bool {
	return e.ItemCondition != nil && *e.ItemCondition == "damaged"
}

// replyConfirmQuestion is the wording, given which reasons apply. Empty when neither does.
// It is @-addressed to the replier: once answered the poster sees it too, and a message from
// Freegle in a chat between two people must say which of them it is talking to.
func replyConfirmQuestion(name string, damaged bool, needsCar bool) string {
	var concern string

	switch {
	case damaged && needsCar:
		concern = "from the photo, this looks like it may be damaged or not working, and big or heavy enough " +
			"that you'd need a car or van to collect it."
	case damaged:
		concern = "from the photo, this looks like it may be damaged or not working."
	case needsCar:
		concern = "from the photo, this looks big or heavy enough that you'd need a car or van to collect it."
	default:
		return ""
	}

	return "@" + name + ", before we pass your reply on: " + concern + " Do you still want it?"
}

var replyConfirmOptions = []PromptOption{
	{Value: "yes", Label: "Yes, send my reply", Variant: "primary"},
	{Value: "no", Label: "No, don't send it", Variant: "secondary"},
}

// childCarSeatTerms are the words in an offer's subject that mean it is a child car seat. Kept in step
// with the "Car seats" entry in iznik-nuxt3/components/PostItem.vue, which shows the poster a note
// under the box for the same words.
var childCarSeatTerms = []string{"car seat", "carseat", "child car", "booster seat", "isofix", "infant carrier"}

// isChildCarSeat says whether a post's subject is offering a child car seat.
func isChildCarSeat(subject string) bool {
	lower := strings.ToLower(subject)
	for _, term := range childCarSeatTerms {
		if strings.Contains(lower, term) {
			return true
		}
	}

	return false
}

// childCarSeatQuestion is what a replier to a child car seat is asked. The wording is the moderators'
// (Discourse 9939/61); keep it theirs.
func childCarSeatQuestion(name string) string {
	return "@" + name + ", before we pass your reply on: this is a child car seat. Only accept one if you know its " +
		"complete history, are certain it has never been in an accident, and have confirmed it meets approved UK " +
		"safety standards (look for the official 'E' mark). Always check the specific model's manufacturer-recommended " +
		"lifespan, as plastics degrade over time and some seats expire in as little as five years. Freegle cannot " +
		"inspect or verify items for you, so please read the safety guidance at https://childcarseats.org.uk before " +
		"proceeding. Do you still want it?"
}

// replyConfirmStratum is why a reply is in the experiment: the exposure's stratum.
func replyConfirmStratum(damaged bool, needsCar bool) string {
	switch {
	case damaged && needsCar:
		return "both"
	case damaged:
		return "damaged"
	default:
		return "car"
	}
}

// replyConfirmPlan is what the experiment decided about a reply before the reply was written.
type replyConfirmPlan struct {
	// experiment is the experiment the reply is in, and empty when it is asked as policy (a child car
	// seat), in which case no exposure is written.
	experiment string
	arm        string
	freegleID  uint64
	chatid     uint64
	msgid      uint64
	replier    uint64
	question   string
	stratum    string
	detail     map[string]any
	// questionID is the chat message that asks, set once the hold has been written.
	questionID uint64
}

// asks says whether the reply is held and its replier asked.
func (p *replyConfirmPlan) asks() bool {
	return p.arm == "ask"
}

// planReplyConfirm is the experiment confirm-reply (docs/developers/reference/ux-experiments.md,
// "Confirming a reply before it is passed on"). Half of the repliers to an eligible offer are in the arm
// "ask": their reply is held and they are asked whether they still want the item. The other half are
// "control": nothing changes for them. Both arms leave one exposure row. With no arm (switch off,
// experiment not running, entry malformed or expired) it does nothing at all.
//
// It only decides, and writes nothing, so that it can run before the reply is written: the hold is written in
// the transaction of the reply (hold), because the batch takes any committed unprocessed reply. It returns nil for a
// reply that is outside the experiment, and everything that makes a reply ineligible comes before the plan is made,
// so an exposure always means "this reply was eligible".
//
// A child car seat is asked about outside the experiment: whatever the arm, and from the app too, which the
// experiment leaves out (fromApp). The post is read first so that a reply to anything else, with no experiment
// running, costs one read and nothing more.
func planReplyConfirm(db *gorm.DB, chatid uint64, msgid uint64, replier uint64, fromApp bool) *replyConfirmPlan {
	var post struct {
		Type             string
		Subject          string
		Fromuser         uint64
		Deliverypossible int
	}
	db.Table("messages").
		Select("type, COALESCE(subject, '') AS subject, COALESCE(fromuser, 0) AS fromuser, deliverypossible").
		Where("id = ?", msgid).
		Scan(&post)
	if post.Type != utils.OFFER || post.Fromuser == 0 || post.Fromuser == replier {
		return nil
	}

	carSeat := isChildCarSeat(post.Subject)

	arm := ""
	experiment := ""
	if !carSeat {
		if fromApp {
			return nil
		}

		arm = ux.ExperimentArm(db, replier, "confirm-reply")
		if arm == "" {
			return nil
		}
		experiment = "confirm-reply"
	}

	freegleID := firstreply.SystemUserID(db)
	if freegleID == 0 {
		return nil
	}

	// An offer that has already been taken, withdrawn or has expired has nothing left to ask about: the reply goes
	// through as it does outside the experiment, and the exposure is not written.
	var outcomes int64
	db.Table("messages_outcomes").Where("msgid = ?", msgid).Count(&outcomes)
	if outcomes > 0 {
		return nil
	}

	// The question is for the chat between the replier and the member who posted the offer. A message that names the
	// offer in a chat with anyone else is not a reply to the poster, and the question would land in that member's chat.
	var between int64
	db.Table("chat_rooms").
		Where("id = ? AND chattype = ? AND ((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?))",
			chatid, utils.CHAT_TYPE_USER2USER, replier, post.Fromuser, post.Fromuser, replier).
		Count(&between)
	if between == 0 {
		return nil
	}

	var tn int64
	db.Table("users u").
		Where("u.id IN ? AND "+user.TrashNothingSQL("u"), []uint64{replier, post.Fromuser}).
		Count(&tn)
	if tn > 0 {
		return nil
	}

	if carSeat {
		// Asked once per replier and post: a second reply after a no, or after the question ran out, goes
		// through. There is no exposure row to go by, so the question itself is the record. Read on the
		// primary, as below.
		var asked int64
		if err := db.Clauses(dbresolver.Write).Table("chat_prompts").
			Where("kind = ? AND userid = ? AND msgid = ?", utils.PROMPT_KIND_CONFIRM_REPLY, replier, msgid).
			Count(&asked).Error; err != nil {
			stdlog.Printf("Could not check for an earlier car seat question for replier %d on post %d: %v", replier, msgid, err)

			return nil
		}
		if asked > 0 {
			return nil
		}

		stratum := "carseat"

		return &replyConfirmPlan{
			arm:       "ask",
			freegleID: freegleID,
			chatid:    chatid,
			msgid:     msgid,
			replier:   replier,
			question:  childCarSeatQuestion(memberName(db, replier)),
			stratum:   stratum,
		}
	}

	// One row per replier and post, whichever arm: a second reply to the same post is not a second
	// unit. This also stops a second question. Read on the primary, because the first reply may be
	// seconds old (.claude/rules/go-api-traps.md, "Reads and writes go to different servers"). A read
	// that fails leaves the reply alone, in both arms: carrying on would ask a repeat replier a second
	// question and write a second row (the unique key includes the reply id).
	var exposed int64
	if err := db.Clauses(dbresolver.Write).Table("ux_experiment_exposures x").
		Joins("INNER JOIN chat_messages cm ON cm.id = x.subject_id").
		Where("x.experiment = ? AND x.subject_type = ? AND x.userid = ? AND cm.refmsgid = ?",
			experiment, "reply", replier, msgid).
		Count(&exposed).Error; err != nil {
		stdlog.Printf("Could not check for an earlier confirm-reply exposure for replier %d on post %d: %v", replier, msgid, err)

		return nil
	}
	if exposed > 0 {
		return nil
	}

	// The newest classification wins: the post may have been reclassified since.
	var eee replyConfirmEee
	db.Table("messages_eee").
		Select("id, item_condition, size_bucket, weight_bucket").
		Where("msgid = ?", msgid).
		Order("id DESC").
		Limit(1).
		Scan(&eee)

	// A poster who has said they might deliver takes the car out of the question.
	needsCar := eee.needsACar() && post.Deliverypossible == 0
	if !eee.damaged() && !needsCar {
		return nil
	}

	plan := &replyConfirmPlan{
		experiment: experiment,
		arm:        arm,
		freegleID:  freegleID,
		chatid:     chatid,
		msgid:      msgid,
		replier:    replier,
		stratum:    replyConfirmStratum(eee.damaged(), needsCar),
		detail:     map[string]any{"eee_id": eee.ID, "deliverypossible": post.Deliverypossible},
	}
	if plan.asks() {
		plan.question = replyConfirmQuestion(memberName(db, replier), eee.damaged(), needsCar)
	}

	return plan
}

// record writes the exposure of a reply that is not asked about: control, and any arm this code has no meaning for.
// The reply goes through as it always did. RecordExposure logs a failure, and the reply carries on whatever happens
// to the row.
func (p *replyConfirmPlan) record(db *gorm.DB, replyid uint64) {
	if p.experiment == "" {
		return
	}

	_ = ux.RecordExposure(db, p.experiment, p.arm, p.replier, "reply", replyid, &p.stratum, p.detail)
}

// hold writes what holds the reply: its exposure, the question that asks about it, and the row that names the
// reply. It runs in the transaction that writes the reply, so the reply is never committed without its hold. The
// batch takes any committed unprocessed reply, whatever its age, and a reply committed before its hold would be
// delivered by a run that landed between the two, to a poster whose replier is then asked whether they still want it.
func (p *replyConfirmPlan) hold(tx *gorm.DB, replyid uint64) error {
	options, _ := json.Marshal(replyConfirmOptions)

	// First, so that a row which cannot be written leaves no hold and no question: the reply then
	// goes through exactly as it would have in control. A question asked as policy has no experiment
	// and writes no row.
	if p.experiment != "" {
		if err := ux.RecordExposure(tx, p.experiment, p.arm, p.replier, "reply", replyid, &p.stratum, p.detail); err != nil {
			return err
		}
	}

	msgid := p.msgid
	prompt := ChatMessage{
		Chatid:   p.chatid,
		Userid:   p.freegleID,
		Type:     utils.CHAT_MESSAGE_PROMPT,
		Refmsgid: &msgid,
		Message:  p.question,
		Date:     time.Now(),
	}
	if err := tx.Create(&prompt).Error; err != nil {
		return err
	}

	err := tx.Table("chat_prompts").Create(map[string]interface{}{
		"chatmsgid":     prompt.ID,
		"msgid":         msgid,
		"msgids":        fmt.Sprintf("[%d]", msgid),
		"userid":        p.replier,
		"heldchatmsgid": replyid,
		"kind":          utils.PROMPT_KIND_CONFIRM_REPLY,
		"options":       string(options),
		"expires_at":    gorm.Expr("DATE_ADD(NOW(), INTERVAL ? DAY)", replyConfirmExpiryDays),
		"created_at":    gorm.Expr("NOW()"),
	}).Error
	if err == nil {
		p.questionID = prompt.ID
	}

	return err
}

// notify tells the replier about the question as any chat message is told: the batch sends the push, for the member
// the question was asked of and nobody else (PushNotificationService::getChatMessageRecipients), and the chat email
// follows (ChatNotificationService). The question is written already processed, so chats:process-incoming, which queues
// the push of an ordinary message, never sees it. It runs once the reply and its hold are committed: a push for a
// question that was rolled back would name a message that is not there. A push that cannot be queued is logged and the
// question stands, since the email still goes.
func (p *replyConfirmPlan) notify() {
	if p.questionID == 0 {
		return
	}

	if err := queue.QueueTask(queue.TaskPushNotifyChatMessage, map[string]interface{}{"message_id": p.questionID}); err != nil {
		stdlog.Printf("Could not queue the push for question %d to replier %d: %v", p.questionID, p.replier, err)
	}
}

// applyReplyConfirmAnswer is what the replier's answer does to the held reply. "yes" needs
// nothing here: once the prompt is answered chats:process-incoming delivers the reply on its
// next run. "no" marks the reply, and everything the replier wrote in that chat since it, processed
// and unsuccessful, which every delivery path reads as "never deliver", with the reason ModTools
// shows (ChatMessage::PROCESSFAIL_REPLY_WITHDRAWN). Those further messages were held behind the
// same question, so they go with the reply.
//
// applied is false when there was nothing left to withdraw: the reply had already been processed. An error is
// the database failing, and the caller must not record the answer then, or the reply would be released.
func applyReplyConfirmAnswer(db *gorm.DB, answer string, heldchatmsgid uint64, chatid uint64, myid uint64) (applied bool, err error) {
	if answer != "no" {
		return true, nil
	}

	res := db.Table("chat_messages").
		Where("chatid = ? AND userid = ? AND id >= ? AND processingrequired = 1", chatid, myid, heldchatmsgid).
		Updates(map[string]interface{}{
			"processingrequired":   0,
			"processingsuccessful": 0,
			"processingfailreason": "ReplyWithdrawn",
		})
	if res.Error != nil {
		return false, res.Error
	}

	return res.RowsAffected > 0, nil
}

// revealQuestionOfApprovedReply shows the poster the question of a reply that a moderator has just let through. The
// batch shows a question with its reply, but only when the reply is delivered (ChatProcessService::showQuestionWithReply):
// a reply held for review leaves its question hidden, and this shows it when the reply is shown.
func revealQuestionOfApprovedReply(db *gorm.DB, replyid uint64) {
	// Nearly every approval is of a message no question was asked about, and finds nothing here.
	var questions []uint64
	db.Clauses(dbresolver.Write).Table("chat_prompts").
		Select("chatmsgid").
		Where("heldchatmsgid = ? AND kind = ? AND answer = 'yes'", replyid, utils.PROMPT_KIND_CONFIRM_REPLY).
		Scan(&questions)
	if len(questions) == 0 {
		return
	}

	var shown int64
	db.Clauses(dbresolver.Write).Table("chat_messages").
		Where("id = ? AND processingsuccessful = 1 AND reviewrequired = 0 AND reviewrejected = 0", replyid).
		Count(&shown)
	if shown == 0 {
		return
	}

	db.Table("chat_messages").Where("id IN ?", questions).Update("processingsuccessful", 1)
}
