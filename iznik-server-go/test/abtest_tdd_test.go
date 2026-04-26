package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/abtest"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestABTest_GetABTest_MissingUID(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/abtest", nil))
	assert.Equal(t, 400, resp.StatusCode)
}

func TestABTest_GetABTest_NoVariants(t *testing.T) {
	uid := uniquePrefix("abtest_no_variants")
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/abtest?uid="+uid, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	assert.Nil(t, result["variant"])
}

func TestABTest_GetABTest_WithVariants(t *testing.T) {
	uid := uniquePrefix("abtest_with_variants")
	db := database.DBConn
	db.Exec("INSERT INTO abtest (uid, variant, suggest, rate) VALUES (?, 'variant_a', 1, 0.8), (?, 'variant_b', 1, 0.5)",
		uid, uid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/abtest?uid="+uid, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	assert.NotNil(t, result["variant"])

	variant := result["variant"].(map[string]interface{})
	assert.NotEmpty(t, variant["variant"])
	assert.Contains(t, []string{"variant_a", "variant_b"}, variant["variant"])
}

func TestABTest_PostABTest_MissingFields(t *testing.T) {
	body := `{"uid":"","variant":""}`
	req := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
}

func TestABTest_PostABTest_IgnoreAppRequests(t *testing.T) {
	prefix := uniquePrefix("abtest_app")
	body := fmt.Sprintf(`{"uid":"%s","variant":"variant_a","shown":true,"app":true}`, prefix)
	req := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	db := database.DBConn
	db.Raw("SELECT COUNT(*) FROM abtest WHERE uid = ? AND variant = ?", prefix, "variant_a").Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestABTest_PostABTest_RecordShown(t *testing.T) {
	prefix := uniquePrefix("abtest_shown")
	uid := prefix
	variant := "variant_shown"

	body := fmt.Sprintf(`{"uid":"%s","variant":"%s","shown":true}`, uid, variant)
	req := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var testVar abtest.ABTestVariant
	db := database.DBConn
	db.Raw("SELECT id, uid, variant, shown, action FROM abtest WHERE uid = ? AND variant = ?", uid, variant).Scan(&testVar)
	assert.Equal(t, uint64(1), testVar.Shown)
	assert.Equal(t, uint64(0), testVar.Action)
}

func TestABTest_PostABTest_RecordAction(t *testing.T) {
	prefix := uniquePrefix("abtest_action")
	uid := prefix
	variant := "variant_action"

	body := fmt.Sprintf(`{"uid":"%s","variant":"%s","action":true}`, uid, variant)
	req := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var testVar abtest.ABTestVariant
	db := database.DBConn
	db.Raw("SELECT id, uid, variant, shown, action FROM abtest WHERE uid = ? AND variant = ?", uid, variant).Scan(&testVar)
	assert.Equal(t, uint64(1), testVar.Action)
}

func TestABTest_PostABTest_RecordActionWithScore(t *testing.T) {
	prefix := uniquePrefix("abtest_score")
	uid := prefix
	variant := "variant_scored"

	body := fmt.Sprintf(`{"uid":"%s","variant":"%s","action":true,"score":5}`, uid, variant)
	req := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var testVar abtest.ABTestVariant
	db := database.DBConn
	db.Raw("SELECT id, uid, variant, shown, action FROM abtest WHERE uid = ? AND variant = ?", uid, variant).Scan(&testVar)
	assert.Equal(t, uint64(5), testVar.Action)
}

func TestABTest_PostABTest_UpdateExistingRecord(t *testing.T) {
	prefix := uniquePrefix("abtest_accum")
	uid := prefix
	variant := "variant_accum"

	db := database.DBConn

	body1 := fmt.Sprintf(`{"uid":"%s","variant":"%s","shown":true}`, uid, variant)
	req1 := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body1))
	req1.Header.Set("Content-Type", "application/json")
	getApp().Test(req1)

	body2 := fmt.Sprintf(`{"uid":"%s","variant":"%s","shown":true}`, uid, variant)
	req2 := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body2))
	req2.Header.Set("Content-Type", "application/json")
	getApp().Test(req2)

	body3 := fmt.Sprintf(`{"uid":"%s","variant":"%s","action":true}`, uid, variant)
	req3 := httptest.NewRequest("POST", "/api/abtest", bytes.NewBufferString(body3))
	req3.Header.Set("Content-Type", "application/json")
	getApp().Test(req3)

	var testVar abtest.ABTestVariant
	db.Raw("SELECT id, uid, variant, shown, action FROM abtest WHERE uid = ? AND variant = ?", uid, variant).Scan(&testVar)
	assert.Equal(t, uint64(2), testVar.Shown)
	assert.Equal(t, uint64(1), testVar.Action)
}
