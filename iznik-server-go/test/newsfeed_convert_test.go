package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// --- ChitChat moderator tools: duplicate flagging, and posting for a member ---
//
// Both are restricted to the ChitChat Moderation team and support/admin. The
// duplicate answer names one of the poster's OTHER posts, and posting on
// someone's behalf writes to their account, so the permission checks matter more
// than the happy paths and are what these cover.

// makeChitChatMod puts a user in the ChitChat Moderation team, creating the team
// if this is the first test to need it.
func makeChitChatMod(t *testing.T, userid uint64) {
	db := database.DBConn

	var teamid uint64
	db.Raw("SELECT id FROM teams WHERE name = 'ChitChat Moderation' LIMIT 1").Scan(&teamid)

	if teamid == 0 {
		id, err := database.ExecInsertGetID(db, "INSERT INTO teams (name) VALUES ('ChitChat Moderation')")
		if err != nil {
			t.Fatalf("ERROR: could not create ChitChat Moderation team: %v", err)
		}
		teamid = id
	}

	db.Exec("INSERT INTO teams_members (teamid, userid) VALUES (?, ?)", teamid, userid)
}

func TestNewsfeedDuplicate_RefusedForOrdinaryMember(t *testing.T) {
	// The response names one of the poster's own posts, so a member must not be
	// able to ask - not even about their own ChitChat entry.
	prefix := uniquePrefix("dupmember")
	userID, token := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 55.9533, -3.1883, "A sofa going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/duplicate?jwt="+token, nil))

	assert.Equal(t, 403, resp.StatusCode, "an ordinary member must not learn about the poster's other posts")
}

func TestNewsfeedDuplicate_RefusedWhenLoggedOut(t *testing.T) {
	prefix := uniquePrefix("dupanon")
	userID, _ := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 55.9533, -3.1883, "A sofa going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/duplicate", nil))

	assert.Equal(t, 401, resp.StatusCode)
}

func TestNewsfeedDuplicate_ModGetsAnAnswer(t *testing.T) {
	// A ChitChat moderator gets a well-formed answer. Whether a duplicate is
	// found depends on the embedding sidecar, which the test environment may not
	// run, so this asserts the endpoint is reachable and shaped right rather
	// than asserting a match.
	prefix := uniquePrefix("dupmod")
	posterID, _ := CreateFullTestUser(t, prefix+"_poster")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	nfID := CreateTestNewsfeed(t, posterID, 55.9533, -3.1883, "Dining chairs going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/duplicate?jwt="+modToken, nil))

	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	_, present := result["duplicate"]
	assert.True(t, present, "response should always carry a duplicate key, null when there is no match")
}

func TestNewsfeedDuplicate_NotFoundForMissingEntry(t *testing.T) {
	prefix := uniquePrefix("dupmissing")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/999999999/duplicate?jwt="+modToken, nil))

	assert.Equal(t, 404, resp.StatusCode)
}

func TestPutMessageOnBehalfOf_RefusedForOrdinaryMember(t *testing.T) {
	// Posting as someone else writes to their account. Only ChitChat moderators
	// and support/admin may do it; an ordinary member must not, whoever they aim
	// it at.
	prefix := uniquePrefix("oboputmember")
	_, token := CreateFullTestUser(t, prefix+"_actor")
	victimID, _ := CreateFullTestUser(t, prefix+"_victim")

	body, _ := json2.Marshal(map[string]interface{}{
		"messagetype": "Offer",
		"item":        "a thing they never offered",
		"textbody":    "posted by someone else",
		"collection":  "Draft",
	})

	url := fmt.Sprintf("/api/message?jwt=%s&onbehalfof=%d", token, victimID)
	req := httptest.NewRequest("PUT", url, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, -1)

	assert.Equal(t, 403, resp.StatusCode, "a member must not be able to post as another member")
}

func TestJoinAndPostOnBehalfOf_RefusedForOrdinaryMember(t *testing.T) {
	// The submit half is gated too, so a member can't finish a draft that
	// belongs to someone else.
	prefix := uniquePrefix("obojoinmember")
	_, token := CreateFullTestUser(t, prefix+"_actor")
	victimID, _ := CreateFullTestUser(t, prefix+"_victim")

	body, _ := json2.Marshal(map[string]interface{}{
		"id":     1,
		"action": "JoinAndPost",
	})

	url := fmt.Sprintf("/api/message?jwt=%s&onbehalfof=%d", token, victimID)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, -1)

	assert.Equal(t, 403, resp.StatusCode, "a member must not be able to submit another member's draft")
}

func TestConvertedToPost_RefusedForOrdinaryMember(t *testing.T) {
	// The note on the thread claims a volunteer acted, so a member must not be
	// able to fabricate one.
	prefix := uniquePrefix("convmember")
	userID, token := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 55.9533, -3.1883, "Something "+prefix)

	body, _ := json2.Marshal(map[string]interface{}{
		"id":     nfID,
		"msgid":  1,
		"action": "ConvertedToPost",
	})

	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, -1)

	assert.Equal(t, 403, resp.StatusCode)
}
