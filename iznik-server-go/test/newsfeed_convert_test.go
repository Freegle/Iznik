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
	"gorm.io/gorm"
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
		res := gorm.WithResult()
		if err := db.Table("teams").Clauses(res).
			Create(map[string]interface{}{"name": "ChitChat Moderation"}).Error; err != nil {
			t.Fatalf("ERROR: could not create ChitChat Moderation team: %v", err)
		}
		id, err := res.Result.LastInsertId()
		if err != nil {
			t.Fatalf("ERROR: could not read back the ChitChat Moderation team id: %v", err)
		}
		teamid = uint64(id)
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

// --- Convert preview: where would this post land? ---
//
// The modal shows the moderator the postcode and community before they commit.
// It must be the MEMBER's, never the moderator's, and it must come from the same
// resolver that does the posting - a preview that promises one postcode while the
// post uses another is worse than no preview.

func TestNewsfeedConvertInfo_RefusedForOrdinaryMember(t *testing.T) {
	prefix := uniquePrefix("cvtinfomember")
	userID, token := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 55.9533, -3.1883, "A sofa going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/convertinfo?jwt="+token, nil))

	assert.Equal(t, 403, resp.StatusCode, "it says where another member lives")
}

func TestNewsfeedConvertInfo_RefusedWhenLoggedOut(t *testing.T) {
	prefix := uniquePrefix("cvtinfoout")
	userID, _ := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 55.9533, -3.1883, "A sofa going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/convertinfo", nil))

	assert.Equal(t, 401, resp.StatusCode)
}

func TestNewsfeedConvertInfo_ModSeesWhereItWouldPost(t *testing.T) {
	prefix := uniquePrefix("cvtinfomod")
	posterID, _ := CreateFullTestUser(t, prefix+"_poster")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	nfID := CreateTestNewsfeed(t, posterID, 55.9533, -3.1883, "Dining chairs going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/convertinfo?jwt="+modToken, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	// canpost is always present so the modal can decide what to show; when it is
	// false there must be a reason the moderator can act on.
	canpost, present := result["canpost"]
	assert.True(t, present, "response should always say whether it can post")

	if canpost == true {
		assert.NotEmpty(t, result["locationname"], "a moderator needs the actual postcode, not a placeholder")
		assert.NotEmpty(t, result["groupid"], "and the community it will go to")
	} else {
		assert.NotEmpty(t, result["reason"], "if it cannot post, say why")
	}
}

func TestNewsfeedConvertInfo_NotFoundForMissingEntry(t *testing.T) {
	prefix := uniquePrefix("cvtinfomissing")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/999999999/convertinfo?jwt="+modToken, nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestNewsfeedConvertInfo_UsesTheMembersChosenLocation(t *testing.T) {
	// The postcode must be the one the MEMBER set in their settings, not one
	// inferred from wherever they last happened to be, and not the moderator's.
	prefix := uniquePrefix("cvtinfochosen")
	posterID, _ := CreateFullTestUser(t, prefix+"_poster")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	db := database.DBConn

	// Any real location row will do - we only care that the id and name we set
	// are the ones handed back.
	var locID uint64
	var locName string
	db.Raw("SELECT id, name FROM locations WHERE type = ? AND name IS NOT NULL AND name != '' LIMIT 1",
		"Postcode").Row().Scan(&locID, &locName)

	if locID == 0 {
		t.Skip("no postcode locations seeded")
	}

	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(NULLIF(settings, ''), '{}'), "+
		"'$.mylocation', JSON_OBJECT('id', ?, 'name', ?, 'lat', 55.9533, 'lng', -3.1883)) WHERE id = ?",
		locID, locName, posterID)

	nfID := CreateTestNewsfeed(t, posterID, 55.9533, -3.1883, "Dining chairs going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/convertinfo?jwt="+modToken, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	if result["canpost"] == true {
		assert.Equal(t, locName, result["locationname"],
			"the preview must show the postcode the member chose")
	} else {
		// Only legitimate reason left is that the test user is in no community.
		assert.Contains(t, result["reason"], "community")
	}
}

// --- After the convert: what the thread records ---
//
// The action must leave a properly-typed notice (a missing enum value truncates
// the type to '' and the thread renders it as an empty reply from the
// moderator), point it at the created message so the wording can say WANTED or
// OFFER, hide the now-redundant ChitChat post, and carry any photo over.

func TestConvertedToPost_TypedNoticeMsgidAndHide(t *testing.T) {
	prefix := uniquePrefix("convdone")
	posterID, posterToken := CreateFullTestUser(t, prefix+"_poster")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	nfID := CreateTestNewsfeed(t, posterID, 55.9533, -3.1883, "Bunny ears wanted "+prefix)
	msgID := CreateTestMessageWithoutGroup(t, posterID, "WANTED: bunny ears "+prefix)
	database.DBConn.Exec("UPDATE messages SET type = 'Wanted' WHERE id = ?", msgID)

	body, _ := json2.Marshal(map[string]interface{}{
		"id":     nfID,
		"msgid":  msgID,
		"action": "ConvertedToPost",
	})
	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, -1)
	assert.Equal(t, 200, resp.StatusCode)

	db := database.DBConn

	var notice struct {
		ID    uint64
		Type  string
		Msgid uint64
	}
	db.Raw("SELECT id, type, msgid FROM newsfeed WHERE replyto = ? ORDER BY id DESC LIMIT 1", nfID).Scan(&notice)
	assert.Equal(t, "ConvertedToPost", notice.Type,
		"the notice must survive the enum - '' means the migration is missing")
	assert.Equal(t, msgID, notice.Msgid,
		"the notice must point at the post it is about")

	// The real post now exists, so the ChitChat copy is redundant and would
	// just keep collecting replies the member no longer needs. Hidden exactly
	// as the mod Hide action does it.
	var hiddenBy uint64
	db.Raw("SELECT COALESCE(hiddenby, 0) FROM newsfeed WHERE id = ?", nfID).Scan(&hiddenBy)
	assert.Equal(t, modID, hiddenBy, "the ChitChat post should be hidden by the converting mod")

	// The member still sees their own hidden thread, and the notice on it must
	// say what kind of post was made for them.
	id := strconv.FormatUint(nfID, 10)
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"?jwt="+posterToken, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var thread map[string]interface{}
	json2.Unmarshal(rsp(resp), &thread)

	replies, _ := thread["replies"].([]interface{})
	var foundNotice map[string]interface{}
	for _, r := range replies {
		reply, _ := r.(map[string]interface{})
		if reply["type"] == "ConvertedToPost" {
			foundNotice = reply
		}
	}

	if assert.NotNil(t, foundNotice, "the member must see the notice in their thread") {
		assert.Equal(t, "Wanted", foundNotice["msgtype"],
			"the thread must know whether it became a WANTED or an OFFER")
	}
}

func TestConvertedToPost_CopiesThePhotoOntoTheMessage(t *testing.T) {
	// If the member put a photo on their ChitChat post, the OFFER/WANTED made
	// from it must carry the same photo - a photo is often the most useful part
	// of the post.
	prefix := uniquePrefix("convphoto")
	posterID, _ := CreateFullTestUser(t, prefix+"_poster")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	db := database.DBConn

	nfID := CreateTestNewsfeed(t, posterID, 55.9533, -3.1883, "Bunny ears with photo "+prefix)
	msgID := CreateTestMessageWithoutGroup(t, posterID, "WANTED: bunny ears photo "+prefix)

	uid := "freegletusd-" + prefix
	db.Exec("INSERT INTO newsfeed_images (newsfeedid, contenttype, externaluid, externalmods) VALUES (?, 'image/jpeg', ?, '{}')", nfID, uid)
	var imgID uint64
	db.Raw("SELECT id FROM newsfeed_images WHERE externaluid = ? LIMIT 1", uid).Scan(&imgID)
	db.Exec("UPDATE newsfeed SET imageid = ? WHERE id = ?", imgID, nfID)

	body, _ := json2.Marshal(map[string]interface{}{
		"id":     nfID,
		"msgid":  msgID,
		"action": "ConvertedToPost",
	})
	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, -1)
	assert.Equal(t, 200, resp.StatusCode)

	var att struct {
		ID          uint64
		Externaluid string
		Primary     bool
	}
	db.Raw("SELECT id, externaluid, `primary` FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&att)
	assert.Equal(t, uid, att.Externaluid, "the ChitChat photo must arrive on the message")
	assert.True(t, att.Primary, "and be its primary photo")
}

func TestConvertedToPost_NoPhotoIsFine(t *testing.T) {
	// Most ChitChat posts have no photo; the convert must not invent one.
	prefix := uniquePrefix("convnophoto")
	posterID, _ := CreateFullTestUser(t, prefix+"_poster")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	nfID := CreateTestNewsfeed(t, posterID, 55.9533, -3.1883, "Bunny ears no photo "+prefix)
	msgID := CreateTestMessageWithoutGroup(t, posterID, "WANTED: bunny ears nophoto "+prefix)

	body, _ := json2.Marshal(map[string]interface{}{
		"id":     nfID,
		"msgid":  msgID,
		"action": "ConvertedToPost",
	})
	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, -1)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestNewsfeedConvertInfo_RefusesWhenMemberHasNoChosenLocation(t *testing.T) {
	// No guessing: if the member never set a location we refuse rather than
	// stamp one on their post.
	prefix := uniquePrefix("cvtinfonoloc")
	posterID, _ := CreateFullTestUser(t, prefix+"_poster")
	modID, modToken := CreateFullTestUser(t, prefix+"_mod")
	makeChitChatMod(t, modID)

	database.DBConn.Exec("UPDATE users SET settings = JSON_REMOVE(COALESCE(NULLIF(settings, ''), '{}'), '$.mylocation') WHERE id = ?", posterID)

	nfID := CreateTestNewsfeed(t, posterID, 55.9533, -3.1883, "A sofa going spare "+prefix)

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id+"/convertinfo?jwt="+modToken, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	assert.Equal(t, false, result["canpost"], "cannot post for a member with no chosen location")
	assert.Contains(t, result["reason"], "location")
}
