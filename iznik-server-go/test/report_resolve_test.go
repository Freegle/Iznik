package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// Experiment: reports resolved by the system. Today two member reports pull a post back to
// Pending for a moderator, and with nobody there the 48-hour auto-approve puts it back up.
// Nobody tells the poster or the reporters anything. With REPORTS_RESOLVE on, the quorum
// takes the post down, tells the poster why and how to repost, and tells each reporter.

type reportFixture struct {
	groupID  uint64
	posterID uint64
	msgID    uint64
}

func setupReports(t *testing.T, prefix string) reportFixture {
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: a dodgy item", 51.5, -0.1)
	// The Freegle account the system speaks as, if this database does not have it yet.
	var sys uint64
	database.DBConn.Table("users_emails").Select("userid").Where("email = ?", microvolunteering.SystemUserEmail()).Limit(1).Scan(&sys)
	if sys == 0 {
		CreateTestUserWithEmail(t, prefix+"_system", microvolunteering.SystemUserEmail())
	}
	return reportFixture{groupID: groupID, posterID: posterID, msgID: msgID}
}

// A member reports the post the way the website does: a message to the community's
// volunteers referencing the post.
func reportPost(t *testing.T, f reportFixture, prefix string, n int) (reporterID uint64, roomID uint64) {
	reporterID, roomID, status := reportPostStatus(t, f, prefix, n)
	assert.Equal(t, 200, status)
	return
}

func reportPostStatus(t *testing.T, f reportFixture, prefix string, n int) (reporterID uint64, roomID uint64, status int) {
	reporterID = CreateTestUser(t, fmt.Sprintf("%s_reporter%d", prefix, n), "User")
	CreateTestMembership(t, reporterID, f.groupID, "Member")
	roomID = CreateTestChatRoom(t, reporterID, nil, &f.groupID, "User2Mod")
	_, token := CreateTestSession(t, reporterID)
	body, _ := json.Marshal(map[string]interface{}{"message": "This looks like a scam", "refmsgid": f.msgID})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", roomID, token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("report: %v", err)
	}
	status = resp.StatusCode
	return
}

func modMailsIn(roomID uint64) []string {
	var texts []string
	database.DBConn.Table("chat_messages").Select("message").
		Where("chatid = ? AND type = 'ModMail'", roomID).Order("id").Scan(&texts)
	return texts
}

func posterRoom(f reportFixture) uint64 {
	var id uint64
	database.DBConn.Table("chat_rooms").Select("id").
		Where("user1 = ? AND groupid = ? AND chattype = 'User2Mod'", f.posterID, f.groupID).Limit(1).Scan(&id)
	return id
}

func TestReportsResolve_OffLeavesThePostForAModerator(t *testing.T) {
	t.Setenv("REPORTS_RESOLVE", "")
	assert.False(t, microvolunteering.ReportsResolve())
	prefix := uniquePrefix("reportoff")
	f := setupReports(t, prefix)

	reportPost(t, f, prefix, 1)
	_, room2 := reportPost(t, f, prefix, 2)

	var deleted *string
	database.DBConn.Table("messages").Select("deleted").Where("id = ?", f.msgID).Scan(&deleted)
	assert.Nil(t, deleted, "today the post is not taken down")

	var collection string
	database.DBConn.Table("messages_groups").Select("collection").Where("msgid = ?", f.msgID).Scan(&collection)
	assert.Equal(t, "Pending", collection, "today the post waits for a moderator")

	assert.Empty(t, modMailsIn(room2), "today nobody tells the reporter anything")
	assert.Equal(t, uint64(0), posterRoom(f), "today nobody tells the poster anything")
}

func TestReportsResolve_OnTakesDownAndTellsEveryone(t *testing.T) {
	t.Setenv("REPORTS_RESOLVE", "1")
	prefix := uniquePrefix("reporton")
	f := setupReports(t, prefix)

	_, room1 := reportPost(t, f, prefix, 1)

	var deleted *string
	database.DBConn.Table("messages").Select("deleted").Where("id = ?", f.msgID).Scan(&deleted)
	assert.Nil(t, deleted, "one report is not enough")
	assert.Empty(t, modMailsIn(room1), "no outcome to report yet")

	_, room2 := reportPost(t, f, prefix, 2)

	database.DBConn.Table("messages").Select("deleted").Where("id = ?", f.msgID).Scan(&deleted)
	assert.NotNil(t, deleted, "two reports take the post down")

	pr := posterRoom(f)
	if assert.NotEqual(t, uint64(0), pr, "the poster is told") {
		mails := modMailsIn(pr)
		if assert.Len(t, mails, 1) {
			assert.Contains(t, mails[0], "taken down")
			assert.Contains(t, mails[0], "a dodgy item")
			assert.Contains(t, mails[0], "post it again")
		}
	}

	for _, room := range []uint64{room1, room2} {
		mails := modMailsIn(room)
		if assert.Len(t, mails, 1, "each reporter is told once") {
			assert.Contains(t, mails[0], "Thanks for reporting")
			assert.Contains(t, mails[0], "taken down")
		}
	}

	var logged int64
	database.DBConn.Table("logs").Where("msgid = ? AND type = 'Message' AND subtype = 'Deleted'", f.msgID).Count(&logged)
	assert.GreaterOrEqual(t, logged, int64(1), "the takedown is logged so a person can find it")

	// A third report finds the post already gone: the send is refused the way any reply
	// to a gone post is, nothing is recorded, and nobody is told twice.
	_, room3, status := reportPostStatus(t, f, prefix, 3)
	assert.Equal(t, 404, status, "the post is gone")
	assert.Len(t, modMailsIn(posterRoom(f)), 1, "the poster is told once")
	assert.Empty(t, modMailsIn(room3), "a report after the takedown gets no separate outcome")
}
