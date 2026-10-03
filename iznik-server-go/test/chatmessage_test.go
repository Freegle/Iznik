package test

import (
	"bytes"
	"encoding/json"
	"net/http"
	"testing"

)
// postChatmessages sends a POST to /api/chatmessages with a JSON body.
func postChatmessages(t *testing.T, path string, body map[string]interface{}, token string) *http.Response {
	t.Helper()
	bodyBytes, _ := json.Marshal(body)

	url := path
	if token != "" {
		url += "?jwt=" + token
	}

	req, err := http.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	if err != nil {
		t.Fatalf("Failed to create request: %v", err)
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("Failed to execute request: %v", err)
	}

	return resp
}

