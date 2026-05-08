package test

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
)

// impactNationalResponse mirrors the shape returned by GET /api/impact/national
type impactNationalResponse struct {
	Year         int     `json:"year"`
	TotalWeightKg float64 `json:"totalWeightKg"`
	TotalGifts   int64   `json:"totalGifts"`
	TotalMembers int64   `json:"totalMembers"`
	GroupCount   int     `json:"groupCount"`
}

// impactTopCommunity mirrors one entry in the top-communities list
type impactTopCommunity struct {
	Name      string  `json:"name"`
	WeightKg  float64 `json:"weightKg"`
}

// impactTopResponse mirrors the shape returned by GET /api/impact/top
type impactTopResponse struct {
	Year        int                  `json:"year"`
	Communities []impactTopCommunity `json:"communities"`
}

func TestImpactNationalEndpoint(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/impact/national", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode, "national endpoint should return 200")

	body, _ := io.ReadAll(resp.Body)
	var result impactNationalResponse
	err = json.Unmarshal(body, &result)
	assert.Nil(t, err, "response should be valid JSON")
}

func TestImpactNationalEndpointYearParam(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/impact/national?year=2024", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result impactNationalResponse
	err = json.Unmarshal(body, &result)
	assert.Nil(t, err, "response with year param should be valid JSON")
	assert.Equal(t, 2024, result.Year, "year field should reflect the requested year")
}

func TestImpactNationalEndpointNonNegative(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/impact/national?year=2024", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result impactNationalResponse
	json.Unmarshal(body, &result)
	assert.GreaterOrEqual(t, result.TotalWeightKg, float64(0), "weight must be non-negative")
	assert.GreaterOrEqual(t, result.TotalGifts, int64(0), "gifts must be non-negative")
	assert.GreaterOrEqual(t, result.TotalMembers, int64(0), "members must be non-negative")
	assert.GreaterOrEqual(t, result.GroupCount, 0, "group count must be non-negative")
}

func TestImpactNationalEndpointV2(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/impact/national", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode, "v2 national endpoint should also return 200")
}

func TestImpactTopEndpoint(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/impact/top", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode, "top endpoint should return 200")

	body, _ := io.ReadAll(resp.Body)
	var result impactTopResponse
	err = json.Unmarshal(body, &result)
	assert.Nil(t, err, "top response should be valid JSON")
	assert.NotNil(t, result.Communities, "communities field must be present")
}

func TestImpactTopEndpointLimit(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/impact/top?year=2024&limit=5", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result impactTopResponse
	json.Unmarshal(body, &result)
	assert.LessOrEqual(t, len(result.Communities), 5, "should return at most 5 communities")
	assert.Equal(t, 2024, result.Year, "year field should reflect the requested year")
}

func TestImpactTopEndpointDescendingOrder(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/impact/top?year=2024&limit=10", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result impactTopResponse
	json.Unmarshal(body, &result)

	for i := 1; i < len(result.Communities); i++ {
		assert.GreaterOrEqual(t,
			result.Communities[i-1].WeightKg,
			result.Communities[i].WeightKg,
			"communities should be in descending weight order",
		)
	}
}

func TestImpactTopEndpointV2(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/impact/top", nil)
	resp, err := getApp().Test(req)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode, "v2 top endpoint should also return 200")
}
