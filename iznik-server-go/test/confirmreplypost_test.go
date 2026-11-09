package test

import (
	"fmt"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The experiment confirm-reply-post (chat/replyconfirm.go). The POSTER is in an arm. In "ask", every replier to the
// poster's offers that look damaged, or too big to carry, is asked whether they still want it before their reply is
// passed on; in "control" nothing changes. Either way the poster's exposure is one row per eligible post, subject
// "post", and the batch counts each poster once, on the first.

const confirmReplyPost = "confirm-reply-post"

func seedOfferEee(t *testing.T, msgID uint64, condition string, size string, weight string) {
	t.Helper()
	res := database.DBConn.Exec("INSERT INTO messages_eee (msgid, is_eee, item_condition, size_bucket, weight_bucket, "+
		"model, prompt_version, classified_at) VALUES (?, 0, ?, ?, ?, 'gemini-test', 'v1', NOW())",
		msgID, condition, size, weight)
	require.NoError(t, res.Error, "could not seed messages_eee")
}

// forceConfirmReplyPostArm puts every member in one arm. The entry is written as adopted so that it has no
// end time to keep in the future.
func forceConfirmReplyPostArm(t *testing.T, arm string) {
	t.Helper()
	writeServerArmConfig(t, `{"`+confirmReplyPost+`":{"arms":{"`+arm+`":[0,99]},"adopted":true}}`, "1", true)
}

func confirmReplyPostExposures(t *testing.T, posterID uint64) []map[string]interface{} {
	t.Helper()
	db := database.DBConn
	t.Cleanup(func() {
		db.Exec("DELETE FROM ux_experiment_exposures WHERE experiment = ? AND userid = ?", confirmReplyPost, posterID)
	})

	var rows []map[string]interface{}
	db.Raw("SELECT arm, userid, subject_type, subject_id, stratum FROM ux_experiment_exposures WHERE experiment = ? AND userid = ? ORDER BY id",
		confirmReplyPost, posterID).Scan(&rows)

	return rows
}

// anotherReplier adds a second replier to the fixture's offer, with their own chat with the poster.
func anotherReplier(t *testing.T, f replyConfirmFixture, name string) replyConfirmFixture {
	t.Helper()
	g := f
	g.replierID = CreateTestUser(t, uniquePrefix(name), "User")
	g.chatID = CreateTestChatRoom(t, g.replierID, &g.posterID, nil, "User2User")
	_, g.replierToken = CreateTestSession(t, g.replierID)

	return g
}

func TestConfirmReplyPost_AskArmAsksEveryReplierToThePoster(t *testing.T) {
	f := newOfferFixture(t, "crp_ask", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	forceConfirmReplyPostArm(t, "ask")

	first := f.reply(t, "Could I have the wardrobe?", true)
	p := f.prompt(t)
	require.NotNil(t, p, "the first replier is asked")
	require.NotNil(t, p.Userid)
	assert.Equal(t, f.replierID, *p.Userid)
	require.NotNil(t, p.Heldchatmsgid)
	assert.Equal(t, first, *p.Heldchatmsgid)
	assert.Contains(t, p.Message, "big or heavy enough")
	assert.Equal(t, 0, p.Processing, "the question is hidden from the poster while it waits")

	g := anotherReplier(t, f, "crp_ask2")
	g.reply(t, "Me too please", true)
	assert.NotNil(t, g.prompt(t), "a second replier to the same poster is asked as well")

	rows := confirmReplyPostExposures(t, f.posterID)
	require.Len(t, rows, 1, "one row for the poster and this post, however many repliers")
	assert.Equal(t, "ask", rows[0]["arm"])
	assert.Equal(t, "post", rows[0]["subject_type"])
	assert.EqualValues(t, f.msgID, rows[0]["subject_id"])
	assert.Equal(t, "car", rows[0]["stratum"])
}

func TestConfirmReplyPost_ControlAsksNobodyAndRecordsThePosterOnce(t *testing.T) {
	f := newOfferFixture(t, "crp_ctl", "OFFER: broken lawnmower")
	seedOfferEee(t, f.msgID, "damaged", "medium", "5_20kg")
	forceConfirmReplyPostArm(t, "control")

	reply := f.reply(t, "I can fix it", true)
	assert.Nil(t, f.prompt(t), "control is not asked")
	required, _ := processingState(reply)
	assert.Equal(t, 1, required, "the reply waits for the batch as any reply does, and nothing holds it")
	assert.Equal(t, int64(0), promptsHolding(reply))

	g := anotherReplier(t, f, "crp_ctl2")
	g.reply(t, "Me too", true)
	assert.Nil(t, g.prompt(t))

	rows := confirmReplyPostExposures(t, f.posterID)
	require.Len(t, rows, 1)
	assert.Equal(t, "control", rows[0]["arm"])
	assert.Equal(t, "post", rows[0]["subject_type"])
	assert.EqualValues(t, f.msgID, rows[0]["subject_id"])
	assert.Equal(t, "damaged", rows[0]["stratum"])
}

// The arm is the poster's bucket. A replier whose own bucket is outside the ask range is asked when the poster's
// is inside it, and the other way round is not.
func TestConfirmReplyPost_TheArmIsThePostersNotTheRepliers(t *testing.T) {
	f := newOfferFixture(t, "crp_who", "OFFER: double bed")
	seedOfferEee(t, f.msgID, "reusable", "large", "over_100kg")
	posterBucket := ux.ExperimentBucket(f.posterID, confirmReplyPost, "")
	for ux.ExperimentBucket(f.replierID, confirmReplyPost, "") == posterBucket {
		f = anotherReplier(t, f, "crp_who_other")
	}
	replierBucket := ux.ExperimentBucket(f.replierID, confirmReplyPost, "")

	writeServerArmConfig(t, fmt.Sprintf(`{"%s":{"arms":{"ask":[%d,%d]},"adopted":true}}`, confirmReplyPost, posterBucket, posterBucket), "1", true)
	f.reply(t, "Could I have it?", true)
	assert.NotNil(t, f.prompt(t), "the poster is in ask, so the replier is asked")

	g := newOfferFixture(t, "crp_who2", "OFFER: chest freezer")
	seedOfferEee(t, g.msgID, "reusable", "large", "20_100kg")
	posterBucket2 := ux.ExperimentBucket(g.posterID, confirmReplyPost, "")
	for ux.ExperimentBucket(g.replierID, confirmReplyPost, "") == posterBucket2 {
		g = anotherReplier(t, g, "crp_who2_other")
	}
	replierBucket = ux.ExperimentBucket(g.replierID, confirmReplyPost, "")
	writeServerArmConfig(t, fmt.Sprintf(`{"%s":{"arms":{"ask":[%d,%d]},"adopted":true}}`, confirmReplyPost, replierBucket, replierBucket), "1", true)
	g.reply(t, "Could I have it?", true)
	assert.Nil(t, g.prompt(t), "the replier's own bucket does not put the poster in ask")
	assert.Empty(t, confirmReplyPostExposures(t, g.posterID), "a poster outside every arm is not counted")
}

func TestConfirmReplyPost_EachEligiblePostOfThePosterHasItsOwnRow(t *testing.T) {
	f := newOfferFixture(t, "crp_two", "OFFER: sofa")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	forceConfirmReplyPostArm(t, "control")
	f.reply(t, "Sofa please", true)

	second := CreateTestMessage(t, f.posterID, f.groupID, "OFFER: armchair ("+uniquePrefix("crp_two")+")", 51.5, -0.1)
	seedOfferEee(t, second, "damaged", "medium", "5_20kg")
	f.replyAbout(t, second, "And the armchair?")

	rows := confirmReplyPostExposures(t, f.posterID)
	require.Len(t, rows, 2, "the batch counts the poster once, on the first; each post keeps its own row")
	assert.EqualValues(t, f.msgID, rows[0]["subject_id"])
	assert.EqualValues(t, second, rows[1]["subject_id"])
}

func TestConfirmReplyPost_AnOfferThatLooksFineIsLeftOut(t *testing.T) {
	f := newOfferFixture(t, "crp_fine", "OFFER: lamp")
	seedOfferEee(t, f.msgID, "reusable", "small", "1_5kg")
	forceConfirmReplyPostArm(t, "ask")

	f.reply(t, "Lamp please", true)
	assert.Nil(t, f.prompt(t))
	assert.Empty(t, confirmReplyPostExposures(t, f.posterID))
}

func TestConfirmReplyPost_APosterWhoMayDeliverIsNotAskedAboutACar(t *testing.T) {
	f := newOfferFixture(t, "crp_deliver", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	database.DBConn.Exec("UPDATE messages SET deliverypossible = 1 WHERE id = ?", f.msgID)
	forceConfirmReplyPostArm(t, "ask")

	f.reply(t, "Wardrobe please", true)
	assert.Nil(t, f.prompt(t))
	assert.Empty(t, confirmReplyPostExposures(t, f.posterID))
}

// A child car seat is asked about as policy, whatever the arm, and is not in the experiment.
func TestConfirmReplyPost_ACarSeatIsThePolicyQuestionAndNotInTheExperiment(t *testing.T) {
	f := newOfferFixture(t, "crp_seat", "OFFER: child car seat")
	seedOfferEee(t, f.msgID, "damaged", "medium", "5_20kg")
	forceConfirmReplyPostArm(t, "control")

	f.reply(t, "Seat please", true)
	p := f.prompt(t)
	require.NotNil(t, p, "control does not stop the car seat question")
	assert.True(t, strings.Contains(p.Message, "child car seat"), p.Message)
	assert.Empty(t, confirmReplyPostExposures(t, f.posterID))
}

func TestConfirmReplyPost_ATrashNothingPosterIsLeftOut(t *testing.T) {
	f := newOfferFixture(t, "crp_tn", "OFFER: wardrobe")
	seedOfferEee(t, f.msgID, "reusable", "large", "20_100kg")
	database.DBConn.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", f.posterID, f.posterID)
	forceConfirmReplyPostArm(t, "ask")

	f.reply(t, "Wardrobe please", true)
	assert.Nil(t, f.prompt(t))
	assert.Empty(t, confirmReplyPostExposures(t, f.posterID))
}
