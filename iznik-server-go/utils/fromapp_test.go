package utils

import (
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestOriginIsApp(t *testing.T) {
	// The Android app, its Referer form, the iOS app and the old Ionic scheme.
	for _, from := range []string{"https://localhost", "https://localhost/", "capacitor://localhost", "ionic://localhost"} {
		assert.True(t, originIsApp(from), from)
	}
	// The website, a developer's local site, a look-alike host and nothing at all.
	for _, from := range []string{
		"https://www.ilovefreegle.org",
		"https://www.ilovefreegle.org/chats/1",
		"http://localhost",
		"http://localhost:3002",
		"https://localhost:3002",
		"http://freegle-dev-local.localhost",
		"https://localhost.evil.example",
		"",
		"::not a url",
	} {
		assert.False(t, originIsApp(from), from)
	}
}

func TestRequestFromAppReadsOriginThenReferer(t *testing.T) {
	app := fiber.New()
	app.Get("/", func(c *fiber.Ctx) error {
		if RequestFromApp(c) {
			return c.SendString("app")
		}
		return c.SendString("web")
	})

	call := func(headers map[string]string) string {
		req := httptest.NewRequest("GET", "/", nil)
		for k, v := range headers {
			req.Header.Set(k, v)
		}
		resp, err := app.Test(req)
		assert.NoError(t, err)
		buf := make([]byte, 8)
		n, _ := resp.Body.Read(buf)
		return string(buf[:n])
	}

	assert.Equal(t, "app", call(map[string]string{"Origin": "https://localhost"}))
	assert.Equal(t, "app", call(map[string]string{"Referer": "capacitor://localhost/chats"}))
	assert.Equal(t, "web", call(map[string]string{"Origin": "https://www.ilovefreegle.org", "Referer": "https://localhost/"}))
	assert.Equal(t, "web", call(map[string]string{}))
}
