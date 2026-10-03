package test

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"os"
	"strconv"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/amp"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// computeTestHMAC generates an HMAC-SHA256 signature for testing.
func computeTestHMAC(message, secret string) string {
	h := hmac.New(sha256.New, []byte(secret))
	h.Write([]byte(message))
	return hex.EncodeToString(h.Sum(nil))
}

// generateAMPToken generates an HMAC-based AMP token for testing.
func generateAMPToken(userID uint64, chatID uint64, exp int64, secret string) string {
	message := "amp" + strconv.FormatUint(userID, 10) + strconv.FormatUint(chatID, 10) + strconv.FormatInt(exp, 10)
	return computeTestHMAC(message, secret)
}

// buildAMPURL builds a URL with AMP token parameters.
func buildAMPURL(path string, userID uint64, chatID uint64, exp int64, token string) string {
	return fmt.Sprintf("%s?rt=%s&uid=%d&exp=%d", path, token, userID, exp)
}

// CreateTestEmailTracking creates an email tracking record.
func CreateTestEmailTracking(t *testing.T, userID uint64) uint64 {
	db := database.DBConn

	// Generate unique tracking ID. tracking_id is VARCHAR(32); base36-encode
	// so it stays short even as test userIDs and nanosecond timestamps grow.
	trackingUUID := fmt.Sprintf("t%s%s",
		strconv.FormatUint(userID, 36),
		strconv.FormatInt(time.Now().UnixNano(), 36))

	result := db.Exec(`
		INSERT INTO email_tracking (tracking_id, userid, email_type, recipient_email, sent_at)
		VALUES (?, ?, 'chat_notification', 'test@example.com', NOW())
	`, trackingUUID, userID)

	if result.Error != nil {
		t.Fatalf("ERROR: Failed to create email tracking: %v", result.Error)
	}

	var trackingID uint64
	db.Raw("SELECT id FROM email_tracking WHERE tracking_id = ?", trackingUUID).Scan(&trackingID)

	return trackingID
}

// CreateTestChatRoster creates a roster entry for a user in a chat.
func CreateTestChatRoster(t *testing.T, chatID uint64, userID uint64) {
	db := database.DBConn

	result := db.Exec(`
		INSERT INTO chat_roster (chatid, userid, status)
		VALUES (?, ?, 'Online')
		ON DUPLICATE KEY UPDATE status = 'Online'
	`, chatID, userID)

	if result.Error != nil {
		t.Fatalf("ERROR: Failed to create chat roster entry: %v", result.Error)
	}
}

func TestAMPGetChatMessagesInvalidToken(t *testing.T) {
	// Missing token params - should return empty fallback response
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/amp/chat/1", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var response amp.AMPChatResponse
	json2.Unmarshal(rsp(resp), &response)
	assert.Equal(t, 0, len(response.Items))
	assert.False(t, response.CanReply)

	// Invalid HMAC token
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/amp/chat/1?rt=invalid&uid=1&exp="+fmt.Sprint(time.Now().Unix()+3600), nil))
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &response)
	assert.Equal(t, 0, len(response.Items))
	assert.False(t, response.CanReply)

	// Expired token
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/amp/chat/1?rt=test&uid=1&exp="+fmt.Sprint(time.Now().Unix()-3600), nil))
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &response)
	assert.False(t, response.CanReply)
}

func TestAMPGetChatMessagesValidToken(t *testing.T) {
	// Set up test environment secret
	ampSecret := os.Getenv("AMP_SECRET")
	if ampSecret == "" {
		ampSecret = os.Getenv("FREEGLE_AMP_SECRET")
	}
	if ampSecret == "" {
		t.Fatal("AMP_SECRET not set")
	}

	// Create test data
	prefix := uniquePrefix("ampget")
	user1ID := CreateTestUser(t, prefix+"_1", "User")
	user2ID := CreateTestUser(t, prefix+"_2", "User")

	// Create user-to-user chat
	chatID := CreateTestChatRoom(t, user1ID, &user2ID, "User2User")

	// Add users to chat roster
	CreateTestChatRoster(t, chatID, user1ID)
	CreateTestChatRoster(t, chatID, user2ID)

	// Create messages
	msg1ID := CreateTestChatMessage(t, chatID, user1ID, "Hello from user 1")
	CreateTestChatMessage(t, chatID, user2ID, "Hi back from user 2")
	CreateTestChatMessage(t, chatID, user1ID, "Another message from user 1")

	// Generate valid token
	exp := time.Now().Unix() + 3600
	token := generateAMPToken(user1ID, chatID, exp, ampSecret)

	url := fmt.Sprintf("/amp/chat/%d?rt=%s&uid=%d&exp=%d&exclude=%d",
		chatID, token, user1ID, exp, msg1ID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var response amp.AMPChatResponse
	json2.Unmarshal(rsp(resp), &response)
	assert.True(t, response.CanReply)
	assert.Equal(t, chatID, response.ChatID)
	// Should have messages (excluding msg1ID)
	assert.Greater(t, len(response.Items), 0)
}

func TestAMPGetChatMessagesNotInChat(t *testing.T) {
	// Set up test environment secret
	ampSecret := os.Getenv("AMP_SECRET")
	if ampSecret == "" {
		ampSecret = os.Getenv("FREEGLE_AMP_SECRET")
	}
	if ampSecret == "" {
		t.Fatal("AMP_SECRET not set")
	}

	// Create test data
	prefix := uniquePrefix("ampnotinchat")
	user1ID := CreateTestUser(t, prefix+"_1", "User")
	user2ID := CreateTestUser(t, prefix+"_2", "User")
	user3ID := CreateTestUser(t, prefix+"_3", "User") // Not in chat

	// Create chat between user1 and user2
	chatID := CreateTestChatRoom(t, user1ID, &user2ID, "User2User")
	CreateTestChatRoster(t, chatID, user1ID)
	CreateTestChatRoster(t, chatID, user2ID)
	CreateTestChatMessage(t, chatID, user1ID, "Test message")

	// Try to access with user3's token (not in chat)
	exp := time.Now().Unix() + 3600
	token := generateAMPToken(user3ID, chatID, exp, ampSecret)

	url := fmt.Sprintf("/amp/chat/%d?rt=%s&uid=%d&exp=%d", chatID, token, user3ID, exp)

	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var response amp.AMPChatResponse
	json2.Unmarshal(rsp(resp), &response)
	assert.False(t, response.CanReply) // Should not allow reply since not in chat
	assert.Equal(t, 0, len(response.Items))
}

func TestAMPPostChatReplyValidToken(t *testing.T) {
	// Set up test environment secret
	ampSecret := os.Getenv("AMP_SECRET")
	if ampSecret == "" {
		ampSecret = os.Getenv("FREEGLE_AMP_SECRET")
	}
	if ampSecret == "" {
		t.Fatal("AMP_SECRET not set")
	}

	// Create test data
	prefix := uniquePrefix("ampreplyvalid")
	user1ID := CreateTestUser(t, prefix+"_1", "User")
	user2ID := CreateTestUser(t, prefix+"_2", "User")

	chatID := CreateTestChatRoom(t, user1ID, &user2ID, "User2User")
	CreateTestChatRoster(t, chatID, user1ID)
	CreateTestChatRoster(t, chatID, user2ID)

	// Create valid token (expires in 1 hour)
	exp := time.Now().Unix() + 3600
	token := generateAMPToken(user1ID, chatID, exp, ampSecret)

	body := map[string]string{"message": "Test reply from AMP email"}
	bodyBytes, _ := json2.Marshal(body)

	url := fmt.Sprintf("/amp/chat/%d/reply?rt=%s&uid=%d&exp=%d", chatID, token, user1ID, exp)
	request := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	request.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(request)
	assert.Equal(t, 200, resp.StatusCode)

	var response amp.ReplyResponse
	json2.Unmarshal(rsp(resp), &response)
	assert.True(t, response.Success)
	assert.Equal(t, "Message sent!", response.Message)

	// Verify message was created
	db := database.DBConn
	var messageCount int
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ? AND message = ?",
		chatID, "Test reply from AMP email").Scan(&messageCount)
	assert.Equal(t, 1, messageCount)
}

func TestAMPPostChatReplyTokenCanBeReused(t *testing.T) {
	// Set up test environment secret
	ampSecret := os.Getenv("AMP_SECRET")
	if ampSecret == "" {
		ampSecret = os.Getenv("FREEGLE_AMP_SECRET")
	}
	if ampSecret == "" {
		t.Fatal("AMP_SECRET not set")
	}

	// Create test data
	prefix := uniquePrefix("ampreplyreuse")
	user1ID := CreateTestUser(t, prefix+"_1", "User")
	user2ID := CreateTestUser(t, prefix+"_2", "User")

	chatID := CreateTestChatRoom(t, user1ID, &user2ID, "User2User")
	CreateTestChatRoster(t, chatID, user1ID)
	CreateTestChatRoster(t, chatID, user2ID)

	// Create valid token
	exp := time.Now().Unix() + 3600
	token := generateAMPToken(user1ID, chatID, exp, ampSecret)

	url := fmt.Sprintf("/amp/chat/%d/reply?rt=%s&uid=%d&exp=%d", chatID, token, user1ID, exp)

	// First use - should succeed
	body := map[string]string{"message": "First reply"}
	bodyBytes, _ := json2.Marshal(body)
	request := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	request.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(request)

	var response amp.ReplyResponse
	json2.Unmarshal(rsp(resp), &response)
	assert.True(t, response.Success)

	// Second use - should ALSO succeed (HMAC tokens are reusable)
	body = map[string]string{"message": "Second reply"}
	bodyBytes, _ = json2.Marshal(body)
	request = httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	request.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(request)

	json2.Unmarshal(rsp(resp), &response)
	assert.True(t, response.Success) // Token can be reused
}

func TestAMPPostChatReplyWithTracking(t *testing.T) {
	// Set up test environment secret
	ampSecret := os.Getenv("AMP_SECRET")
	if ampSecret == "" {
		ampSecret = os.Getenv("FREEGLE_AMP_SECRET")
	}
	if ampSecret == "" {
		t.Fatal("AMP_SECRET not set")
	}

	// Create test data
	prefix := uniquePrefix("ampreplytrack")
	user1ID := CreateTestUser(t, prefix+"_1", "User")
	user2ID := CreateTestUser(t, prefix+"_2", "User")

	chatID := CreateTestChatRoom(t, user1ID, &user2ID, "User2User")
	CreateTestChatRoster(t, chatID, user1ID)
	CreateTestChatRoster(t, chatID, user2ID)

	// Create email tracking record
	trackingID := CreateTestEmailTracking(t, user1ID)

	// Create valid token with tracking ID
	exp := time.Now().Unix() + 3600
	token := generateAMPToken(user1ID, chatID, exp, ampSecret)

	body := map[string]string{"message": "Reply with tracking"}
	bodyBytes, _ := json2.Marshal(body)

	url := fmt.Sprintf("/amp/chat/%d/reply?rt=%s&uid=%d&exp=%d&tid=%d", chatID, token, user1ID, exp, trackingID)
	request := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	request.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(request)
	assert.Equal(t, 200, resp.StatusCode)

	var response amp.ReplyResponse
	json2.Unmarshal(rsp(resp), &response)
	assert.True(t, response.Success)

	// Verify tracking was updated
	db := database.DBConn
	var repliedVia string
	db.Raw("SELECT replied_via FROM email_tracking WHERE id = ?", trackingID).Scan(&repliedVia)
	assert.Equal(t, "amp", repliedVia)
}

// TestAMPChatRosterMembershipScan tests that the GORM scan for chat roster membership works correctly.
func TestAMPChatRosterMembershipScan(t *testing.T) {
	// Create test data
	prefix := uniquePrefix("amproster")
	user1ID := CreateTestUser(t, prefix+"_1", "User")
	user2ID := CreateTestUser(t, prefix+"_2", "User")

	chatID := CreateTestChatRoom(t, user1ID, &user2ID, "User2User")
	CreateTestChatRoster(t, chatID, user1ID)
	CreateTestChatRoster(t, chatID, user2ID)

	// Directly test the GORM query
	db := database.DBConn
	var memberUserID uint64
	result := db.Raw(`
		SELECT userid FROM chat_roster
		WHERE chatid = ? AND userid = ?
	`, chatID, user1ID).Scan(&memberUserID)

	assert.NoError(t, result.Error, "GORM scan should not error")
	assert.Equal(t, user1ID, memberUserID, "Should find user in chat roster")

	// Test with user not in roster
	var notMemberUserID uint64
	user3ID := CreateTestUser(t, prefix+"_3", "User")
	result = db.Raw(`
		SELECT userid FROM chat_roster
		WHERE chatid = ? AND userid = ?
	`, chatID, user3ID).Scan(&notMemberUserID)

	// Should return 0 (not found) without error
	assert.NoError(t, result.Error, "GORM scan should not error for non-member")
	assert.Equal(t, uint64(0), notMemberUserID, "Should return 0 for non-member")
}
