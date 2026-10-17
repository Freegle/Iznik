package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// insertDecision records a chart decision made `minutesAgo` minutes ago.
func insertDecision(msgid, groupid uint64, mode, version, verdict, end string, minutesAgo int) {
	database.DBConn.Exec(fmt.Sprintf("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, ?, ?, ?, ?, NULL, '[]', NOW() - INTERVAL %d MINUTE)", minutesAgo), msgid, groupid, mode, version, verdict, end)
}

func insertLog(msgid, groupid uint64, subtype string, byuser, user uint64, text string) {
	database.DBConn.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user, text) VALUES (NOW(), 'Message', ?, ?, ?, ?, ?, ?)",
		subtype, msgid, groupid, byuser, user, text)
}

func cleanDecisions(msgids ...uint64) {
	db := database.DBConn
	db.Exec("DELETE FROM logs WHERE msgid IN ?", msgids)
	db.Exec("DELETE FROM messages_automod WHERE msgid IN ?", msgids)
	db.Exec("DELETE FROM messages_groups WHERE msgid IN ?", msgids)
	db.Exec("DELETE FROM messages WHERE id IN ?", msgids)
}

// "A moderator disagreed" means a moderator. The poster withdrawing their own post is not one,
// and neither is the trial community's own moderator pulling a post back: that is already the
// community's own disagreement, and counting its withdraw and hold logs on the neighbours as
// "a neighbouring community disagreed" would blame the neighbours for it.
func TestAutomodAgreementCountsOnlyAModeratorWhoIsNotThePoster(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "")
	prefix := uniquePrefix("automod_whodisagrees")

	groupA := CreateTestGroup(t, prefix+"_a") // the trial community, run by modA
	groupB := CreateTestGroup(t, prefix+"_b") // a neighbour run by modB
	groupC := CreateTestGroup(t, prefix+"_c") // another neighbour, also run by modA
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modA := CreateTestUser(t, prefix+"_moda", "User")
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, modA, groupA, "Moderator")
	CreateTestMembership(t, modA, groupC, "Moderator")
	CreateTestMembership(t, modB, groupB, "Moderator")
	admin := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, admin)

	post := func(label string) uint64 {
		id := CreateTestMessage(t, poster, groupA, prefix+" "+label, 52.0, -1.0)
		insertDecision(id, groupA, "approve", "2", "approve", "APPROVE", 60)
		return id
	}
	withdrawn := post("withdrawn")
	withdrawnElsewhere := post("withdrawn elsewhere")
	pulledBack := post("pulled back by its own moderator")
	rejectedNext := post("rejected by a neighbour")
	t.Cleanup(func() { cleanDecisions(withdrawn, withdrawnElsewhere, pulledBack, rejectedNext) })

	// The poster withdraws their own post: byuser is the poster.
	insertLog(withdrawn, groupA, "Deleted", poster, poster, "Withdrawn")
	insertLog(withdrawnElsewhere, groupB, "Deleted", poster, poster, "Withdrawn")

	// The community's own moderator pulls a post back (a Check reject or Back to pending): a Hold
	// on its own community, and what the pull-back does to the neighbours: a withdraw log on one,
	// a hold log on another.
	insertLog(pulledBack, groupA, "Hold", modA, poster, "Pulled back from Check")
	insertLog(pulledBack, groupB, "Deleted", modA, poster, "Withdrawn: the home community moved this post back to pending, so it will not ripple here again.")
	insertLog(pulledBack, groupC, "Hold", modA, poster, "A moderator moved this post back to pending for review.")

	// A neighbouring community's own moderator rejects the rippled copy: the real signal.
	insertLog(rejectedNext, groupB, "Rejected", modB, poster, "")

	body := getAgreement(t, adminToken, fmt.Sprintf("days=1&groupid=%d", groupA))
	if assert.Len(t, body.ByGroup, 1) {
		g := body.ByGroup[0]
		assert.Equal(t, int64(4), g.Decisions)
		assert.Equal(t, int64(1), g.ModDisagreed, "only the home moderator's pull-back: not the poster's withdrawal")
		assert.Equal(t, int64(1), g.NeighbourDisagreed, "only the neighbouring community's moderator: not the poster, and not the home moderator's own withdraw and hold logs")
	}
}

// A community moved from shadow to approve-only has decisions of both kinds, and only the
// shadow ones are an unbiased measure (a human decides every post there; in approve mode only
// a post somebody opens in Check meets a human). So the community's row is split by mode.
func TestAutomodAgreementSplitsAGroupByMode(t *testing.T) {
	prefix := uniquePrefix("automod_modesplit")
	groupA := CreateTestGroup(t, prefix+"_a")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	admin := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, admin)

	shadow := CreateTestMessage(t, poster, groupA, prefix+" shadow", 52.0, -1.0)
	approve1 := CreateTestMessage(t, poster, groupA, prefix+" approve 1", 52.0, -1.0)
	approve2 := CreateTestMessage(t, poster, groupA, prefix+" approve 2", 52.0, -1.0)
	t.Cleanup(func() { cleanDecisions(shadow, approve1, approve2) })
	insertDecision(shadow, groupA, "shadow", "2", "approve", "APPROVE", 30)
	insertDecision(approve1, groupA, "approve", "2", "approve", "APPROVE", 20)
	insertDecision(approve2, groupA, "approve", "2", "hold", "HOLD_MONEY", 10)

	body := getAgreement(t, adminToken, fmt.Sprintf("days=1&groupid=%d", groupA))
	byMode := map[string]int64{}
	for _, g := range body.ByGroup {
		assert.Equal(t, groupA, g.Groupid)
		byMode[g.Mode] += g.Decisions
	}
	assert.Len(t, body.ByGroup, 2, "one row for the shadow decisions and one for the approve-mode decisions")
	assert.Equal(t, int64(1), byMode["shadow"])
	assert.Equal(t, int64(2), byMode["approve"])
	assert.Equal(t, int64(3), body.Total)
}

// What moderators then do with a post pulled back from Check is the safety measure for the
// trial: of the posts that published themselves and were pulled back, how many did the
// moderator then approve (a false alarm) and how many reject or delete (a real catch).
func TestAutomodAgreementPulledBackFromCheck(t *testing.T) {
	prefix := uniquePrefix("automod_pulledback")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupZ := CreateTestGroup(t, prefix+"_z")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, mod, groupA, "Moderator")
	admin := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, admin)

	post := func(group uint64, label string) uint64 {
		return CreateTestMessage(t, poster, group, prefix+" "+label, 52.0, -1.0)
	}
	pulled := func(id, group uint64) { insertLog(id, group, "Hold", mod, poster, "Pulled back from Check") }

	approvedAfter := post(groupA, "pulled back, then approved")
	rejectedAfter := post(groupA, "pulled back, then rejected")
	undecided := post(groupA, "pulled back, nobody has decided")
	posterOnly := post(groupA, "pulled back, only the poster acted")
	otherHold := post(groupA, "ordinary back to pending")
	elsewhere := post(groupZ, "pulled back on another community, then approved")
	tooOld := post(groupA, "pulled back long ago")
	t.Cleanup(func() {
		cleanDecisions(approvedAfter, rejectedAfter, undecided, posterOnly, otherHold, elsewhere, tooOld)
	})

	pulled(approvedAfter, groupA)
	insertLog(approvedAfter, groupA, "Approved", mod, poster, "")

	pulled(rejectedAfter, groupA)
	insertLog(rejectedAfter, groupA, "Rejected", mod, poster, "")
	insertLog(rejectedAfter, groupA, "Approved", mod, poster, "") // a later approval does not change what the first decision was

	pulled(undecided, groupA)

	pulled(posterOnly, groupA)
	insertLog(posterOnly, groupA, "Deleted", poster, poster, "Withdrawn") // the poster is not a moderator

	insertLog(otherHold, groupA, "Hold", mod, poster, "Back to pending")

	pulled(elsewhere, groupZ)
	insertLog(elsewhere, groupZ, "Approved", mod, poster, "")

	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user, text) VALUES (NOW() - INTERVAL 10 DAY, 'Message', 'Hold', ?, ?, ?, ?, 'Pulled back from Check')",
		tooOld, groupA, mod, poster)

	one := getAgreement(t, adminToken, fmt.Sprintf("days=1&groupid=%d", groupA))
	assert.Equal(t, int64(4), one.PulledBack, "the four pulled back on this community in the window; not the ordinary Back to pending, not the old one")
	assert.Equal(t, int64(1), one.PulledBackApproved, "a moderator then approved one")
	assert.Equal(t, int64(1), one.PulledBackRejected, "and rejected one, whatever happened after")

	all := getAgreement(t, adminToken, "days=1")
	assert.GreaterOrEqual(t, all.PulledBack, int64(5), "without the filter the other community's counts too")
	assert.GreaterOrEqual(t, all.PulledBackApproved, int64(2))

	wide := getAgreement(t, adminToken, fmt.Sprintf("days=30&groupid=%d", groupA))
	assert.Equal(t, int64(5), wide.PulledBack, "a wider window takes in the old one")
}

// Every breakdown is rolled up from the same per-decision flags, so they must add up to the
// same totals however they are sliced.
func TestAutomodAgreementBreakdownsAddUp(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "")
	prefix := uniquePrefix("automod_addsup")
	groupA := CreateTestGroup(t, prefix+"_a")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, mod, groupA, "Moderator")
	admin := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, admin)
	db := database.DBConn

	var ids []uint64
	mk := func(label string, mode, version, verdict, end string, minutesAgo int) uint64 {
		id := CreateTestMessage(t, poster, groupA, prefix+" "+label, 52.0, -1.0)
		insertDecision(id, groupA, mode, version, verdict, end, minutesAgo)
		ids = append(ids, id)
		return id
	}
	t.Cleanup(func() { cleanDecisions(ids...) })

	a1 := mk("approve v2", "approve", "2", "approve", "APPROVE", 60)
	mk("approve v2 shadow", "shadow", "2", "approve", "APPROVE", 50)
	h1 := mk("hold v2", "approve", "2", "hold", "HOLD_MONEY", 40)
	mk("unavailable", "approve", "", "hold", "UNAVAILABLE", 30)
	mk("approve v3 two days ago", "shadow", "3", "approve", "APPROVE", 2*24*60)
	mk("moderated everything", "approve", "3", "hold", "GROUP_MODERATES_ALL", 20)

	// A moderator rejects one the chart approved, and approves one it held.
	insertLog(a1, groupA, "Rejected", mod, poster, "")
	db.Exec("UPDATE messages_groups SET approvedby = ? WHERE msgid = ? AND groupid = ?", mod, h1, groupA)
	// The held one arrived three hours before the chart decided it: a late decision.
	db.Exec("UPDATE messages SET arrival = NOW() - INTERVAL 3 HOUR WHERE id = ?", h1)

	body := getAgreement(t, adminToken, fmt.Sprintf("days=7&groupid=%d", groupA))
	assert.Equal(t, int64(6), body.Total)

	var end, group, version, day int64
	var endMod, groupMod, versionMod, dayMod int64
	var groupLate, versionLate, dayLate int64
	for _, e := range body.ByEnd {
		end += e.Count
		endMod += e.ModDisagreed
	}
	for _, g := range body.ByGroup {
		group += g.Decisions
		groupMod += g.ModDisagreed
		groupLate += g.Late
	}
	for _, v := range body.ByVersion {
		version += v.Decisions
		versionMod += v.ModDisagreed
		versionLate += v.Late
	}
	for _, d := range body.ByDay {
		day += d.Decisions
		dayMod += d.ModDisagreed
		dayLate += d.Late
	}
	assert.Equal(t, body.Total, end, "by end node")
	assert.Equal(t, body.Total, group, "by community")
	assert.Equal(t, body.Total, version, "by chart version")
	assert.Equal(t, body.Total, day, "by day")
	assert.Equal(t, int64(2), endMod, "the rejected approve and the approved hold")
	assert.Equal(t, endMod, groupMod)
	assert.Equal(t, endMod, versionMod)
	assert.Equal(t, endMod, dayMod)
	assert.Equal(t, body.Late, groupLate)
	assert.Equal(t, body.Late, versionLate)
	assert.Equal(t, body.Late, dayLate)
	assert.Equal(t, int64(1), body.Late, "the held post's decision came three hours after it arrived")
	assert.Equal(t, int64(1), body.Unavailable)
	assert.GreaterOrEqual(t, len(body.ByDay), 2, "today's decisions and the one from two days ago")
}
