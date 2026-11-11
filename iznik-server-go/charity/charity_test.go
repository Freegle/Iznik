package charity

import (
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestCreateCharity_Validation(t *testing.T) {
	app := fiber.New()
	app.Post("/charity", CreateCharity)

	tests := []struct {
		name        string
		body        string
		contentType string
		status      int
		msg         string
	}{
		{"bad json", `{nope`, "application/json", 400, "Invalid request body"},
		{"empty body", ``, "application/json", 400, ""},
		{"bad email only name", `{"orgname":"X","contactemail":""}`, "application/json", 400, "Contact email is required"},
		{"missing org name", `{"contactemail":"a@b.com"}`, "application/json", 400, "Organisation name is required"},
		{"missing email", `{"orgname":"Org"}`, "application/json", 400, "Contact email is required"},
		{"empty strings", `{"orgname":"","contactemail":""}`, "application/json", 400, "Organisation name is required"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("POST", "/charity", strings.NewReader(tc.body))
			req.Header.Set("Content-Type", tc.contentType)
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, tc.status, resp.StatusCode)
		})
	}
}

func TestCharity_TableName(t *testing.T) {
	assert.Equal(t, "charities", Charity{}.TableName())
}

// Same validation branches, driven through each way a body can arrive.
func TestCreateCharity_ValidationContentTypes(t *testing.T) {
	app := fiber.New()
	app.Post("/charity", CreateCharity)

	tests := []struct {
		name        string
		url         string
		body        string
		contentType string
		msg         string
	}{
		{"json missing name", "/charity", `{"contactemail":"a@b.com"}`, "application/json", "Organisation name is required"},
		{"json missing email", "/charity", `{"orgname":"Org"}`, "application/json", "Contact email is required"},
		{"json bad body", "/charity", `[1,2`, "application/json", "Invalid request body"},
		{"json wrong field type", "/charity", `{"orgname":5}`, "application/json", "Invalid request body"},
		{"json orgtype ignored before email check", "/charity", `{"orgname":"Org","orgtype":"bogus"}`, "application/json", "Contact email is required"},
		{"form missing name", "/charity", "contactemail=a%40b.com", "application/x-www-form-urlencoded", ""},
		{"form missing email", "/charity", "orgname=Org", "application/x-www-form-urlencoded", ""},
		{"query missing name", "/charity?contactemail=a@b.com", "", "", ""},
		{"query missing email", "/charity?orgname=Org", "", "", ""},
		{"no params", "/charity", "", "", ""},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("POST", tc.url, strings.NewReader(tc.body))
			if tc.contentType != "" {
				req.Header.Set("Content-Type", tc.contentType)
			}
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, 400, resp.StatusCode)
			if tc.msg != "" {
				b, _ := io.ReadAll(resp.Body)
				assert.Contains(t, string(b), tc.msg)
			}
		})
	}
}
