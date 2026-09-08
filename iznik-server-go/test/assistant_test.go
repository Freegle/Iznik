package test

import (
	"bytes"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/assistant"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func assistantTurn(t *testing.T, body string, headers map[string]string) (int, string, http.Header) {
	req := httptest.NewRequest("POST", "/api/assistant/turn", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := getApp().Test(req, 30000)
	assert.Nil(t, err)
	out, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(out), resp.Header
}

func TestAssistantWorkflowIsServed(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/assistant/workflow", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), `"initialState":"HUB"`)
	assert.Contains(t, string(body), `"GIVE_CONFIRM"`)
}

func TestAssistantAnonymousTapStreamsATurn(t *testing.T) {
	status, body, hdr := assistantTurn(t, `{"tap":"give"}`, nil)
	assert.Equal(t, 200, status)
	assert.Contains(t, body, "event: identity")
	assert.Contains(t, body, "event: turn")
	assert.Contains(t, body, `"state":"GIVE_PHOTO"`)
	assert.Contains(t, body, `"label":"Add a photo"`)
	anon := hdr.Get("X-Assistant-Anon")
	assert.NotEmpty(t, anon)

	// The same visitor continues the same conversation with the token.
	conv := extractConversation(body)
	assert.NotEmpty(t, conv)
	status2, body2, _ := assistantTurn(t, `{"conversation":"`+conv+`","tap":"no_photo"}`, map[string]string{"X-Assistant-Anon": anon})
	assert.Equal(t, 200, status2)
	assert.Contains(t, body2, `"state":"GIVE_ITEM"`)
	assert.Contains(t, body2, `"conversation":"`+conv+`"`)
}

func TestAssistantForgedAnonTokenGetsAFreshOne(t *testing.T) {
	_, body, hdr := assistantTurn(t, `{"tap":"give"}`, map[string]string{"X-Assistant-Anon": "forged.token"})
	assert.Contains(t, body, "event: turn")
	assert.NotEqual(t, "forged.token", hdr.Get("X-Assistant-Anon"))
	assert.NotEmpty(t, hdr.Get("X-Assistant-Anon"))
}

func TestAssistantMemberTurnWritesTranscript(t *testing.T) {
	userID := CreateTestUser(t, "assistant", "User")
	token := getToken(t, userID)
	status, body, hdr := assistantTurn(t, `{"tap":"ask"}`, map[string]string{"Authorization": token})
	assert.Equal(t, 200, status)
	assert.Contains(t, body, `"state":"ASK_ITEM"`)
	assert.Empty(t, hdr.Get("X-Assistant-Anon"), "a member needs no anonymous token")
	// A member's turns land in a real Freegle chat room when the system user exists.
	if assistant.Default().Transcript != nil {
		var count int64
		database.DBConn.Table("chat_rooms").Where("user2 = ? OR user1 = ?", userID, userID).Count(&count)
		if strings.Contains(body, `"chatid":`) {
			assert.Greater(t, count, int64(0))
		}
	}
}

func TestAssistantWidgetsNeedsLogin(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/assistant/widgets?ids=1", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 401, resp.StatusCode)
}

func extractConversation(sse string) string {
	i := strings.Index(sse, `"conversation":"`)
	if i < 0 {
		return ""
	}
	rest := sse[i+len(`"conversation":"`):]
	j := strings.Index(rest, `"`)
	if j < 0 {
		return ""
	}
	return rest[:j]
}
