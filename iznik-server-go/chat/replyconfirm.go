package chat

import (
	"encoding/json"
	"fmt"
	stdlog "log"
	"regexp"
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
// When someone replies to an OFFER of a child car seat, Freegle asks them in the chat to check the seat
// before they accept it, and whether they still want it, and the reply waits for the answer (Discourse
// 9939). A seat that has been in a crash, or is past the life its maker gave it, can look fine and fail,
// and most people do not know that. The wording is the moderators'. It is policy, asked of everyone, in
// the app as on the website.
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
// The same question is also the experiment confirm-reply-post, for offers that look damaged, or too big to carry
// home, from the photo check (messages_eee). The POSTER is in an arm: in "ask", every replier to the poster's
// eligible offers is asked whether they still want it before the reply is passed on; in "control" nothing changes.
// Both arms write one exposure row per poster and post (subject "post"), and the batch counts each poster once, on
// their first, by what the poster saw: ConfirmReplyPostSource. docs/developers/reference/ux-experiments.md,
// "Confirming a reply, judged per poster". A child car seat is asked about as policy and is not in the experiment.

// questionAskedOfMeSQL is true for a chat message that is a reply question still open and asked of the member given by
// its one placeholder. The question is written unsuccessful, so that nothing delivers it to the poster, and the member it
// was asked of counts it as unseen all the same: a question they never see is a reply dropped three days later with
// nobody told. Once it is answered or has run out it is no longer unseen, so it does not stay in the count.
const questionAskedOfMeSQL = "EXISTS (SELECT 1 FROM chat_prompts cpq WHERE cpq.chatmsgid = chat_messages.id AND cpq.kind = '" +
	utils.PROMPT_KIND_CONFIRM_REPLY + "' AND cpq.userid = ? AND cpq.answered_at IS NULL AND (cpq.expires_at IS NULL OR cpq.expires_at > NOW()))"

// replyConfirmExpiryDays is how long the question stays answerable before the reply is dropped.
const replyConfirmExpiryDays = 3

// confirmReplyPost is the experiment's name, as the switch list and the batch know it.
const confirmReplyPost = "confirm-reply-post"

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

// replyConfirmStratum is why a post is in the experiment: the exposure's stratum.
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

var replyConfirmOptions = []PromptOption{
	{Value: "yes", Label: "Yes, send my reply", Variant: "primary"},
	{Value: "no", Label: "No, don't send it", Variant: "secondary"},
}

// childCarSeatTerms are the words in an offer's subject that mean it is a child car seat, and
// childCarSeatExclusions are phrases that are not one: a cover, or a seat for a dog. Kept in step with
// the "Car seats" entry in iznik-nuxt3/composables/useItemWarnings.js, which shows the poster a note
// under the item box for the same words, matched the same way.
var childCarSeatTerms = []string{"car seat", "carseat", "child car", "booster seat", "isofix", "infant carrier"}

var childCarSeatExclusions = []string{
	"seat cover", "dog car seat", "dog booster seat", "dog seat", "pet car seat", "pet booster seat", "pet seat",
}

// wordPattern matches a phrase that ends where a word ends, allowing a plural in -s or -es, so that
// "child car" does not match "child carrier".
func wordPattern(phrase string) *regexp.Regexp {
	return regexp.MustCompile(regexp.QuoteMeta(phrase) + `(?:e?s)?\b`)
}

var (
	childCarSeatTermPatterns      = wordPatterns(childCarSeatTerms)
	childCarSeatExclusionPatterns = wordPatterns(childCarSeatExclusions)
)

func wordPatterns(phrases []string) []*regexp.Regexp {
	ret := make([]*regexp.Regexp, len(phrases))
	for i, p := range phrases {
		ret[i] = wordPattern(p)
	}

	return ret
}

// isChildCarSeat says whether a post's subject is offering a child car seat. The exclusions are taken out
// first, so that "car seat and seat cover" is still a seat and "car seat cover" is not.
func isChildCarSeat(subject string) bool {
	lower := strings.ToLower(subject)
	for _, re := range childCarSeatExclusionPatterns {
		lower = re.ReplaceAllString(lower, " ")
	}

	for _, re := range childCarSeatTermPatterns {
		if re.MatchString(lower) {
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

// replyConfirmPlan is what was decided about a reply before the reply was written: whether its replier is asked,
// and, for the experiment, the poster's arm and exposure.
type replyConfirmPlan struct {
	// experiment is confirm-reply-post, or empty for the car seat question, which writes no exposure.
	experiment string
	arm        string
	// ask says the reply is held and its replier asked.
	ask       bool
	freegleID uint64
	chatid    uint64
	msgid     uint64
	replier   uint64
	poster    uint64
	question  string
	stratum   string
	detail    map[string]any
	// questionID is the chat message that asks, set once the hold has been written.
	questionID uint64
}

// asks says whether the reply is held and its replier asked.
func (p *replyConfirmPlan) asks() bool {
	return p.ask
}

// planReplyConfirm decides whether a reply is held and its replier asked: a reply to an OFFER of a child car
// seat (policy), or, in the experiment confirm-reply-post, a reply to an offer that looks damaged or too big to carry
// from a poster in the arm "ask". Either way only in the chat with the poster, while the offer is still open, once
// per replier and post. A reply to an eligible offer from a poster in "control" is not asked, and its plan only writes
// the poster's exposure (record).
//
// It only decides, and writes nothing, so that it can run before the reply is written: the hold is written in the
// transaction of the reply (hold), because the batch takes any committed unprocessed reply. It returns nil for a reply
// that is not asked about. The post is read first, so a reply to anything else costs one read and nothing more.
func planReplyConfirm(db *gorm.DB, chatid uint64, msgid uint64, replier uint64) *replyConfirmPlan {
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

	// The car seat question is policy and comes first. Otherwise the poster's arm decides, and with no arm (switch off,
	// experiment not running, entry malformed or expired) nothing else is read.
	carSeat := isChildCarSeat(post.Subject)
	arm := ""
	if !carSeat {
		arm = ux.ExperimentArm(db, post.Fromuser, "confirm-reply-post")
		if arm == "" {
			return nil
		}
	}

	freegleID := firstreply.SystemUserID(db)
	if freegleID == 0 {
		return nil
	}

	// An offer that has already been taken, withdrawn or has expired has nothing left to ask about: the reply goes
	// through as any other does.
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
		if askedBefore(db, replier, msgid) {
			return nil
		}

		return &replyConfirmPlan{
			ask:       true,
			freegleID: freegleID,
			chatid:    chatid,
			msgid:     msgid,
			replier:   replier,
			poster:    post.Fromuser,
			question:  childCarSeatQuestion(memberName(db, replier)),
		}
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
		experiment: confirmReplyPost,
		arm:        arm,
		freegleID:  freegleID,
		chatid:     chatid,
		msgid:      msgid,
		replier:    replier,
		poster:     post.Fromuser,
		stratum:    replyConfirmStratum(eee.damaged(), needsCar),
		detail:     map[string]any{"eee_id": eee.ID, "deliverypossible": post.Deliverypossible},
	}
	if arm == "ask" && !askedBefore(db, replier, msgid) {
		plan.ask = true
		plan.question = replyConfirmQuestion(memberName(db, replier), eee.damaged(), needsCar)
	}

	return plan
}

// askedBefore says whether this replier has been asked about this post already: a second reply after a no, or after
// the question ran out, goes through. Read on the primary, because the first reply may be seconds old
// (.claude/rules/go-api-traps.md, "Reads and writes go to different servers"). A read that fails is taken as asked,
// which leaves the reply alone rather than risk asking twice.
func askedBefore(db *gorm.DB, replier uint64, msgid uint64) bool {
	var asked int64
	if err := db.Clauses(dbresolver.Write).Table("chat_prompts").
		Where("kind = ? AND userid = ? AND msgid = ?", utils.PROMPT_KIND_CONFIRM_REPLY, replier, msgid).
		Count(&asked).Error; err != nil {
		stdlog.Printf("Could not check for an earlier reply question for replier %d on post %d: %v", replier, msgid, err)

		return true
	}

	return asked > 0
}

// record writes the poster's exposure for a reply that is not asked about: control, a replier asked before, and any
// arm this code has no meaning for. The reply goes through as it always did. The row is the poster and the post, so a
// second reply adds nothing (the unique key), and RecordExposure logs a failure: the reply carries on whatever happens.
func (p *replyConfirmPlan) record(db *gorm.DB) {
	if p.experiment == "" {
		return
	}

	_ = ux.RecordExposure(db, p.experiment, p.arm, p.poster, "post", p.msgid, &p.stratum, p.detail)
}

// hold writes what holds the reply: the question that asks about it, and the row that names the reply. It runs in the transaction that writes the reply, so the reply is never committed without its hold. The
// batch takes any committed unprocessed reply, whatever its age, and a reply committed before its hold would be
// delivered by a run that landed between the two, to a poster whose replier is then asked whether they still want it.
func (p *replyConfirmPlan) hold(tx *gorm.DB, replyid uint64) error {
	options, _ := json.Marshal(replyConfirmOptions)

	// First, so that a row which cannot be written leaves no hold and no question: the reply then goes through exactly
	// as it would have in control. The car seat question has no experiment and writes no row.
	if p.experiment != "" {
		if err := ux.RecordExposure(tx, p.experiment, p.arm, p.poster, "post", p.msgid, &p.stratum, p.detail); err != nil {
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
