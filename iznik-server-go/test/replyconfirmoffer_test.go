package test

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Asking before a reply is passed on, for an offer that looks damaged or too big to carry (chat/replyconfirm.go).
// Every replier to such an offer is asked whether they still want it, whoever posted it.

func seedOfferEee(t *testing.T, msgID uint64, condition string, size string, weight string) {
	t.Helper()
	res := database.DBConn.Exec("INSERT INTO messages_eee (msgid, is_eee, item_condition, size_bucket, weight_bucket, "+
		"model, prompt_version, classified_at) VALUES (?, 0, ?, ?, ?, 'gemini-test', 'v1', NOW())",
		msgID, condition, size, weight)
	require.NoError(t, res.Error, "could not seed messages_eee")
}

// replyConfirmExposures is what the reply question has written to the experiment exposures for a poster. It writes
// none: the question is asked of everyone and nothing is being counted.
func replyConfirmExposures(posterID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM ux_experiment_exposures WHERE userid = ? AND subject_type = 'post'",
		posterID).Scan(&n)

	return n
}

// anotherReplier adds a second replier to the fixture's offer, with their own chat with the poster.
func anotherReplier(t *testing.T, f replyConfirmFixture, name string) replyConfirmFixture {
	t.Helper()
	g := f
	g.replierID = CreateTestUser(t, uniquePrefix(name), utils.SYSTEMROLE_USER)
	g.chatID = CreateTestChatRoom(t, g.replierID, &g.posterID, nil, "User2User")
	_, g.replierToken = CreateTestSession(t, g.replierID)

	return g
}

func TestReplyConfirmOffer_EveryReplierToAnOfferTooBigToCarryIsAsked(t *testing.T) {
	f := newOfferFixture(t, "rco_big", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")

	first := f.reply(t, "Could I have the wardrobe?", true)
	p := f.prompt(t)
	require.NotNil(t, p, "the first replier is asked")
	require.NotNil(t, p.Userid)
	assert.Equal(t, f.replierID, *p.Userid)
	require.NotNil(t, p.Heldchatmsgid)
	assert.Equal(t, first, *p.Heldchatmsgid)
	assert.Contains(t, p.Message, "big or heavy enough")
	assert.Equal(t, 0, p.Processing, "the question is hidden from the poster while it waits")

	g := anotherReplier(t, f, "rco_big2")
	g.reply(t, "Me too please", true)
	assert.NotNil(t, g.prompt(t), "a second replier to the same poster is asked as well")

	assert.Equal(t, int64(0), replyConfirmExposures(f.posterID), "nothing is counted as an experiment")
}

// No experiment entry is needed for the question: it is asked with the server arms config empty.
func TestReplyConfirmOffer_AskedWithNoExperimentRunning(t *testing.T) {
	f := newOfferFixture(t, "rco_noexp", "OFFER: double bed")
	seedOfferEee(t, f.msgID, "reusable", "large", "over_100kg")
	writeServerArmConfig(t, `{}`, "1", true)

	f.reply(t, "Could I have it?", true)
	assert.NotNil(t, f.prompt(t))
}

func TestReplyConfirmOffer_AnOfferThatLooksDamagedIsAskedAbout(t *testing.T) {
	f := newOfferFixture(t, "rco_dmg", "OFFER: broken lawnmower")
	seedOfferEee(t, f.msgID, "damaged", "medium", "5_20kg")

	reply := f.reply(t, "I can fix it", true)
	p := f.prompt(t)
	require.NotNil(t, p)
	assert.Contains(t, p.Message, "damaged or not working")
	assert.Equal(t, int64(1), promptsHolding(reply), "the reply is held by its question")
}

func TestReplyConfirmOffer_EachPostIsAskedAboutSeparately(t *testing.T) {
	f := newOfferFixture(t, "rco_two", "OFFER: sofa")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	f.reply(t, "Sofa please", true)
	require.NotNil(t, f.prompt(t))

	second := CreateTestMessage(t, f.posterID, f.groupID, "OFFER: armchair ("+uniquePrefix("rco_two")+")", 51.5, -0.1)
	seedOfferEee(t, second, "damaged", "medium", "5_20kg")
	held := f.replyAbout(t, second, "And the armchair?")
	assert.Equal(t, int64(1), promptsHolding(held), "a reply about another eligible post has its own question")
}

func TestReplyConfirmOffer_AnOfferThatLooksFineIsNotAskedAbout(t *testing.T) {
	f := newOfferFixture(t, "rco_fine", "OFFER: lamp")
	seedOfferEee(t, f.msgID, "reusable", "small", "1_5kg")

	reply := f.reply(t, "Lamp please", true)
	assert.Nil(t, f.prompt(t))
	required, _ := processingState(reply)
	assert.Equal(t, 1, required, "the reply waits for the batch as any reply does, and nothing holds it")
	assert.Equal(t, int64(0), promptsHolding(reply))
}

func TestReplyConfirmOffer_APosterWhoMayDeliverIsNotAskedAboutACar(t *testing.T) {
	f := newOfferFixture(t, "rco_deliver", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	database.DBConn.Exec("UPDATE messages SET deliverypossible = 1 WHERE id = ?", f.msgID)

	f.reply(t, "Wardrobe please", true)
	assert.Nil(t, f.prompt(t))
}

// A child car seat has its own wording, the moderators', even when the photo check also calls it damaged.
func TestReplyConfirmOffer_ACarSeatKeepsItsOwnQuestion(t *testing.T) {
	f := newOfferFixture(t, "rco_seat", "OFFER: child car seat")
	seedOfferEee(t, f.msgID, "damaged", "medium", "5_20kg")

	f.reply(t, "Seat please", true)
	p := f.prompt(t)
	require.NotNil(t, p)
	assert.True(t, strings.Contains(p.Message, "child car seat"), p.Message)
}

func TestReplyConfirmOffer_ATrashNothingPosterIsLeftOut(t *testing.T) {
	f := newOfferFixture(t, "rco_tn", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	database.DBConn.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", f.posterID, f.posterID)

	f.reply(t, "Wardrobe please", true)
	assert.Nil(t, f.prompt(t))
}

func TestReplyConfirmOffer_AskedOncePerReplierAndPost(t *testing.T) {
	f := newOfferFixture(t, "rco_once", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")

	f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	// The replier says no, then replies again: a second question would be a nag.
	f.answer(t, p.Chatmsgid, f.replierToken, "no")
	second := f.reply(t, "Actually, I do want it", true)

	required, _ := processingState(second)
	assert.Equal(t, 1, required, "the second reply goes to the batch as an ordinary reply")
	assert.Equal(t, int64(0), promptsHolding(second), "and nothing holds it")
}

func TestReplyConfirmOffer_AnOfferThatHasGoneIsNotAskedAbout(t *testing.T) {
	f := newOfferFixture(t, "rco_gone", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	database.DBConn.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", f.msgID)
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", f.msgID) })

	f.reply(t, "Yes please", true)
	assert.Nil(t, f.prompt(t))
}

// The newest row of the photo check decides: a post first called large and then small is not asked about.
func TestReplyConfirmOffer_TheNewestPhotoCheckDecides(t *testing.T) {
	f := newOfferFixture(t, "rco_newest", "OFFER: cabinet")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	// One row per post, model and prompt version, so the later check is a later prompt version.
	res := database.DBConn.Exec("INSERT INTO messages_eee (msgid, is_eee, item_condition, size_bucket, weight_bucket, "+
		"model, prompt_version, classified_at) VALUES (?, 0, 'reusable', 'small', '1_5kg', 'gemini-test', 'v2', NOW())",
		f.msgID)
	require.NoError(t, res.Error, "could not seed the later messages_eee row")

	f.reply(t, "Cabinet please", true)
	assert.Nil(t, f.prompt(t))
}
