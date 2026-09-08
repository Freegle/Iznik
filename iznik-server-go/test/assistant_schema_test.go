package test

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// The three small data additions the chat shell's Your posts view relies on: how many of
// a multi-quantity post were promised to someone, which post a collection time belongs
// to, and how many replies are held for a volunteer.

func TestAssistantSchemaPromiseCarriesCount(t *testing.T) {
	giver := CreateTestUser(t, "asstgiver", "User")
	taker := CreateTestUser(t, "assttaker", "User")
	group := CreateTestGroup(t, "asstgrp")
	CreateTestMembership(t, giver, group, "Member")
	msgid := CreateTestMessage(t, giver, group, "OFFER: Four chairs (EH3)", 55.9, -3.2)
	_, token := CreateTestSession(t, giver)

	body, _ := json.Marshal(map[string]interface{}{"id": msgid, "action": "Promise", "userid": taker, "count": 2})
	req := httptest.NewRequest("POST", "/api/message", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", token)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var count *int
	database.DBConn.Table("messages_promises").Select("count").Where("msgid = ? AND userid = ?", msgid, taker).Scan(&count)
	if assert.NotNil(t, count) {
		assert.Equal(t, 2, *count)
	}

	// The record the giver reads back carries it.
	req = httptest.NewRequest("GET", "/api/message/"+itoa(msgid), nil)
	req.Header.Set("Authorization", token)
	resp, err = getApp().Test(req)
	assert.Nil(t, err)
	out, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(out), `"count":2`)
}

func TestAssistantSchemaTrystCarriesPost(t *testing.T) {
	giver := CreateTestUser(t, "assttryst1", "User")
	taker := CreateTestUser(t, "assttryst2", "User")
	group := CreateTestGroup(t, "assttgrp")
	CreateTestMembership(t, giver, group, "Member")
	msgid := CreateTestMessage(t, giver, group, "OFFER: Lamp (EH3)", 55.9, -3.2)
	CreateTestChatRoom(t, giver, &taker, nil, "User2User")
	_, token := CreateTestSession(t, giver)

	body, _ := json.Marshal(map[string]interface{}{"user1": giver, "user2": taker, "arrangedfor": "2036-01-01 10:00:00", "msgid": msgid})
	req := httptest.NewRequest("PUT", "/api/tryst", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", token)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	req = httptest.NewRequest("GET", "/api/tryst", nil)
	req.Header.Set("Authorization", token)
	resp, err = getApp().Test(req)
	assert.Nil(t, err)
	out, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(out), `"msgid":`+itoa(msgid))
}

func TestAssistantSchemaHeldRepliesForOwnerOnly(t *testing.T) {
	giver := CreateTestUser(t, "asstheld1", "User")
	replier := CreateTestUser(t, "asstheld2", "User")
	group := CreateTestGroup(t, "assthgrp")
	CreateTestMembership(t, giver, group, "Member")
	msgid := CreateTestMessage(t, giver, group, "OFFER: Bike (EH3)", 55.9, -3.2)
	chatid := CreateTestChatRoom(t, replier, &giver, nil, "User2User")
	database.DBConn.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, reviewrequired, reviewrejected, processingrequired, processingsuccessful) VALUES (?, ?, 'Interested', ?, NOW(), 'Still available?', 1, 0, 1, 0)", chatid, replier, msgid)

	_, giverToken := CreateTestSession(t, giver)
	req := httptest.NewRequest("GET", "/api/message/"+itoa(msgid), nil)
	req.Header.Set("Authorization", giverToken)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	out, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(out), `"heldreplies":1`)

	_, replierToken := CreateTestSession(t, replier)
	req = httptest.NewRequest("GET", "/api/message/"+itoa(msgid), nil)
	req.Header.Set("Authorization", replierToken)
	resp, err = getApp().Test(req)
	assert.Nil(t, err)
	out, _ = io.ReadAll(resp.Body)
	assert.NotContains(t, string(out), `"heldreplies"`)
}

func itoa(n uint64) string {
	return strconv.FormatUint(n, 10)
}
