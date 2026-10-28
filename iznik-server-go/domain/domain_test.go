package domain

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestGetDomain_MissingDomain(t *testing.T) {
	app := fiber.New()
	app.Get("/domains", GetDomain)

	for _, url := range []string{"/domains", "/domains?domain=", "/domains?other=x"} {
		resp, err := app.Test(httptest.NewRequest("GET", url, nil))
		assert.NoError(t, err)
		assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode, url)
		b, _ := io.ReadAll(resp.Body)
		var m map[string]interface{}
		assert.NoError(t, json.Unmarshal(b, &m))
		assert.Equal(t, float64(2), m["ret"])
		assert.Equal(t, "Missing domain", m["status"])
	}
}
