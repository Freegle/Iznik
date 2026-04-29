package test

// Tests for PATCH /api/session covering FlexInt fields (relevantallowed, newslettersallowed),
// marketingconsent, and aboutme — these were recently fixed when emailfrequency-style fields
// were changed from *int to *utils.FlexInt so HTML <select> string values ("0", "1") parse
// correctly.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// patchSession is a helper that sends a PATCH /api/session request and returns the decoded JSON body.
func patchSession(t *testing.T, token string, payload map[string]interface{}) map[string]interface{} {
	t.Helper()
	body, err := json.Marshal(payload)
	require.NoError(t, err)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/session?jwt=%s", token), bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	return result
}

// ---------------------------------------------------------------------------
// relevantallowed — FlexInt (number)
// ---------------------------------------------------------------------------

func TestPatchSessionRelevantallowedNumber(t *testing.T) {
	prefix := uniquePrefix("sess_relnum")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	result := patchSession(t, token, map[string]interface{}{
		"relevantallowed": 1,
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 1, val)
}

func TestPatchSessionRelevantallowedZeroNumber(t *testing.T) {
	prefix := uniquePrefix("sess_relnum0")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// First set to 1.
	patchSession(t, token, map[string]interface{}{"relevantallowed": 1})

	// Now clear via numeric 0.
	result := patchSession(t, token, map[string]interface{}{
		"relevantallowed": 0,
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 0, val)
}

// ---------------------------------------------------------------------------
// relevantallowed — FlexInt (string — HTML <select> sends "0"/"1")
// ---------------------------------------------------------------------------

func TestPatchSessionRelevantallowedStringOne(t *testing.T) {
	prefix := uniquePrefix("sess_relstr1")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	result := patchSession(t, token, map[string]interface{}{
		"relevantallowed": "1",
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 1, val, "string '1' must be accepted as FlexInt")
}

func TestPatchSessionRelevantallowedStringZero(t *testing.T) {
	prefix := uniquePrefix("sess_relstr0")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Prime to 1 first.
	patchSession(t, token, map[string]interface{}{"relevantallowed": 1})

	result := patchSession(t, token, map[string]interface{}{
		"relevantallowed": "0",
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 0, val, "string '0' must be accepted as FlexInt")
}

// ---------------------------------------------------------------------------
// relevantallowed — FlexInt (boolean — Vue OurToggle emits true/false)
// ---------------------------------------------------------------------------

// EmailSettingsSection.vue's "Suggested posts for you" toggle uses OurToggle,
// which emits a JS boolean on @change. saveAndGet then PATCHes
// {relevantallowed: true|false}. PHP V1 coerced naturally; V2 must too.
func TestPatchSessionRelevantallowedBoolTrue(t *testing.T) {
	prefix := uniquePrefix("sess_relbtrue")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn
	db.Exec("UPDATE users SET relevantallowed = 0 WHERE id = ?", userID)

	result := patchSession(t, token, map[string]interface{}{
		"relevantallowed": true,
	})
	assert.Equal(t, float64(0), result["ret"])

	var val int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 1, val, "JSON boolean true must be accepted as FlexInt 1")
}

func TestPatchSessionRelevantallowedBoolFalse(t *testing.T) {
	prefix := uniquePrefix("sess_relbfalse")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn
	db.Exec("UPDATE users SET relevantallowed = 1 WHERE id = ?", userID)

	result := patchSession(t, token, map[string]interface{}{
		"relevantallowed": false,
	})
	assert.Equal(t, float64(0), result["ret"])

	var val int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 0, val, "JSON boolean false must be accepted as FlexInt 0")
}

// ---------------------------------------------------------------------------
// newslettersallowed — FlexInt (number)
// ---------------------------------------------------------------------------

func TestPatchSessionNewslettersallowedNumber(t *testing.T) {
	prefix := uniquePrefix("sess_nlnum")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	result := patchSession(t, token, map[string]interface{}{
		"newslettersallowed": 1,
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT newslettersallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 1, val)
}

// ---------------------------------------------------------------------------
// newslettersallowed — FlexInt (string)
// ---------------------------------------------------------------------------

func TestPatchSessionNewslettersallowedStringOne(t *testing.T) {
	prefix := uniquePrefix("sess_nlstr")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	result := patchSession(t, token, map[string]interface{}{
		"newslettersallowed": "1",
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT newslettersallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 1, val, "string '1' must be accepted as FlexInt for newslettersallowed")
}

func TestPatchSessionNewslettersallowedStringZero(t *testing.T) {
	prefix := uniquePrefix("sess_nlstr0")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	patchSession(t, token, map[string]interface{}{"newslettersallowed": 1})

	result := patchSession(t, token, map[string]interface{}{
		"newslettersallowed": "0",
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT newslettersallowed FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 0, val, "string '0' must be accepted as FlexInt for newslettersallowed")
}

// ---------------------------------------------------------------------------
// marketingconsent
// ---------------------------------------------------------------------------

func TestPatchSessionMarketingconsentTrue(t *testing.T) {
	prefix := uniquePrefix("sess_mktrue")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	result := patchSession(t, token, map[string]interface{}{
		"marketingconsent": true,
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT marketingconsent FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 1, val)
}

func TestPatchSessionMarketingconsentFalse(t *testing.T) {
	prefix := uniquePrefix("sess_mkfalse")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Set to true first.
	patchSession(t, token, map[string]interface{}{"marketingconsent": true})

	result := patchSession(t, token, map[string]interface{}{
		"marketingconsent": false,
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var val int
	db.Raw("SELECT marketingconsent FROM users WHERE id = ?", userID).Scan(&val)
	assert.Equal(t, 0, val)
}

// ---------------------------------------------------------------------------
// aboutme
// ---------------------------------------------------------------------------

func TestPatchSessionAboutme(t *testing.T) {
	prefix := uniquePrefix("sess_aboutme")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	result := patchSession(t, token, map[string]interface{}{
		"aboutme": "I love giving things away!",
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var text string
	db.Raw("SELECT text FROM users_aboutme WHERE userid = ? ORDER BY timestamp DESC LIMIT 1", userID).Scan(&text)
	assert.Equal(t, "I love giving things away!", text)
}

// ---------------------------------------------------------------------------
// All FlexInt fields together (combined patch)
// ---------------------------------------------------------------------------

func TestPatchSessionAllFlexIntFieldsTogether(t *testing.T) {
	prefix := uniquePrefix("sess_flexall")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Send all FlexInt fields as strings (the way a browser <select> would).
	result := patchSession(t, token, map[string]interface{}{
		"relevantallowed":    "1",
		"newslettersallowed": "1",
	})
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var rel, nl int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&rel)
	db.Raw("SELECT newslettersallowed FROM users WHERE id = ?", userID).Scan(&nl)
	assert.Equal(t, 1, rel)
	assert.Equal(t, 1, nl)
}
