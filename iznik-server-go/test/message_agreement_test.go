package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// The "agreement" extension to promises: optional terms on Promise, and an
// AcceptAgreement action for the promised-to member. Freegle's own clients use
// neither, so the first two tests pin that a plain promise is unchanged.

func postAgreementAction(t *testing.T, token string, body map[string]interface{}) (int, map[string]interface{}) {
	t.Helper()
	bodyBytes, _ := json.Marshal(body)
	url := "/api/message"
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	return resp.StatusCode, result
}

// getPromises fetches a message and returns its promises as raw maps, so
// tests can assert on which JSON keys are present as well as their values.
func getPromises(t *testing.T, msgID uint64, token string) []map[string]interface{} {
	t.Helper()
	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var msg struct {
		Promises []map[string]interface{} `json:"promises"`
	}
	json.NewDecoder(resp.Body).Decode(&msg)
	return msg.Promises
}

func setupPromisedMessage(t *testing.T, prefix string) (ownerID uint64, ownerToken string, otherID uint64, otherToken string, msgID uint64) {
	t.Helper()
	ownerID = CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken = CreateTestSession(t, ownerID)
	otherID = CreateTestUser(t, prefix+"_other", "User")
	_, otherToken = CreateTestSession(t, otherID)
	groupID := CreateTestGroup(t, prefix)
	msgID = CreateTestMessage(t, ownerID, groupID, prefix+" offer item", 52.5, -1.8)
	CreateTestChatRoom(t, ownerID, &otherID, nil, "User2User")
	return
}

func TestPromiseWithoutTermsIsUnchanged(t *testing.T) {
	prefix := uniquePrefix("agr_plain")
	db := database.DBConn
	_, ownerToken, otherID, _, msgID := setupPromisedMessage(t, prefix)

	status, result := postAgreementAction(t, ownerToken, map[string]interface{}{
		"id": msgID, "action": "Promise", "userid": otherID,
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(0), result["ret"])

	// Nothing agreement-shaped is written for a plain promise...
	var terms *string
	var acceptedat *string
	db.Raw("SELECT terms, acceptedat FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Row().Scan(&terms, &acceptedat)
	assert.Nil(t, terms)
	assert.Nil(t, acceptedat)

	// ...and nothing agreement-shaped appears in the JSON, so existing clients
	// see exactly the promise they always did.
	promises := getPromises(t, msgID, ownerToken)
	if assert.Len(t, promises, 1) {
		assert.Equal(t, float64(otherID), promises[0]["userid"])
		_, hasTerms := promises[0]["terms"]
		_, hasAccepted := promises[0]["acceptedat"]
		_, hasAcceptedBy := promises[0]["acceptedby"]
		assert.False(t, hasTerms, "terms must be omitted when not set")
		assert.False(t, hasAccepted, "acceptedat must be omitted when not set")
		assert.False(t, hasAcceptedBy, "acceptedby must be omitted when not set")
	}
}

func TestPromiseWithTermsIsStoredAndReturned(t *testing.T) {
	prefix := uniquePrefix("agr_terms")
	db := database.DBConn
	_, ownerToken, otherID, _, msgID := setupPromisedMessage(t, prefix)

	terms := map[string]interface{}{"whatToGrow": "beans", "endDate": "2027-03-01"}
	status, _ := postAgreementAction(t, ownerToken, map[string]interface{}{
		"id": msgID, "action": "Promise", "userid": otherID, "terms": terms,
	})
	assert.Equal(t, 200, status)

	var stored string
	db.Raw("SELECT terms FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Scan(&stored)
	var decoded map[string]interface{}
	assert.NoError(t, json.Unmarshal([]byte(stored), &decoded))
	assert.Equal(t, "beans", decoded["whatToGrow"])

	promises := getPromises(t, msgID, ownerToken)
	if assert.Len(t, promises, 1) {
		got, _ := promises[0]["terms"].(map[string]interface{})
		assert.Equal(t, "beans", got["whatToGrow"])
		assert.Equal(t, "2027-03-01", got["endDate"])
		_, hasAccepted := promises[0]["acceptedat"]
		assert.False(t, hasAccepted, "not accepted yet")
	}
}

func TestAcceptAgreementByPromisedMember(t *testing.T) {
	prefix := uniquePrefix("agr_accept")
	db := database.DBConn
	_, ownerToken, otherID, otherToken, msgID := setupPromisedMessage(t, prefix)

	status, _ := postAgreementAction(t, ownerToken, map[string]interface{}{
		"id": msgID, "action": "Promise", "userid": otherID,
	})
	assert.Equal(t, 200, status)

	// The member it was promised to accepts.
	status, result := postAgreementAction(t, otherToken, map[string]interface{}{
		"id": msgID, "action": "AcceptAgreement",
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(0), result["ret"])

	var acceptedby uint64
	var acceptedat *string
	db.Raw("SELECT acceptedby, acceptedat FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, otherID).Row().Scan(&acceptedby, &acceptedat)
	assert.Equal(t, otherID, acceptedby)
	assert.NotNil(t, acceptedat)

	promises := getPromises(t, msgID, ownerToken)
	if assert.Len(t, promises, 1) {
		assert.NotEmpty(t, promises[0]["acceptedat"])
		assert.Equal(t, float64(otherID), promises[0]["acceptedby"])
	}

	// Accepting is a one-time step: a second call finds nothing to accept.
	status, _ = postAgreementAction(t, otherToken, map[string]interface{}{
		"id": msgID, "action": "AcceptAgreement",
	})
	assert.Equal(t, 404, status)
}

func TestAcceptAgreementOnlyByPromisedMember(t *testing.T) {
	prefix := uniquePrefix("agr_notmine")
	db := database.DBConn
	ownerID, ownerToken, otherID, _, msgID := setupPromisedMessage(t, prefix)
	strangerID := CreateTestUser(t, prefix+"_stranger", "User")
	_, strangerToken := CreateTestSession(t, strangerID)

	status, _ := postAgreementAction(t, ownerToken, map[string]interface{}{
		"id": msgID, "action": "Promise", "userid": otherID,
	})
	assert.Equal(t, 200, status)

	// The owner made the promise; they are not the one who accepts it.
	status, _ = postAgreementAction(t, ownerToken, map[string]interface{}{"id": msgID, "action": "AcceptAgreement"})
	assert.Equal(t, 404, status)

	// Someone the message was never promised to cannot accept it either.
	status, _ = postAgreementAction(t, strangerToken, map[string]interface{}{"id": msgID, "action": "AcceptAgreement"})
	assert.Equal(t, 404, status)

	// And nothing was written for either of them.
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND acceptedat IS NOT NULL", msgID).Scan(&count)
	assert.Equal(t, int64(0), count)
	_ = ownerID
}

func TestAcceptAgreementRequiresLogin(t *testing.T) {
	prefix := uniquePrefix("agr_anon")
	_, ownerToken, otherID, _, msgID := setupPromisedMessage(t, prefix)

	status, _ := postAgreementAction(t, ownerToken, map[string]interface{}{
		"id": msgID, "action": "Promise", "userid": otherID,
	})
	assert.Equal(t, 200, status)

	status, _ = postAgreementAction(t, "", map[string]interface{}{"id": msgID, "action": "AcceptAgreement"})
	assert.Equal(t, 401, status)
}
