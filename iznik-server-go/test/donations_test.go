package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestGetDonations(t *testing.T) {
	// Test without groupid - should return default target and current month's raised amount
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/donations", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	// Should have target and raised fields
	assert.Contains(t, result, "target")
	assert.Contains(t, result, "raised")

	// Target should be the default (5000 unless DONATION_TARGET env var is set)
	target, ok := result["target"].(float64)
	assert.True(t, ok, "target should be a number")
	assert.Greater(t, target, float64(0), "target should be positive")

	// Raised should be a non-negative number
	raised, ok := result["raised"].(float64)
	assert.True(t, ok, "raised should be a number")
	assert.GreaterOrEqual(t, raised, float64(0), "raised should be >= 0")
}

func TestGetDonationsWithGroupID(t *testing.T) {
	// Test with a valid group ID
	// Note: This will return 0 for raised if no donations exist for this group
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/donations?groupid=1", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	assert.Contains(t, result, "target")
	assert.Contains(t, result, "raised")

	// Raised should be a valid number
	raised, ok := result["raised"].(float64)
	assert.True(t, ok, "raised should be a number")
	assert.GreaterOrEqual(t, raised, float64(0), "raised should be >= 0")
}

func TestGetDonationsInvalidGroupID(t *testing.T) {
	// Test with non-existent group ID - should fall back to default target
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/donations?groupid=999999", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	assert.Contains(t, result, "target")
	assert.Contains(t, result, "raised")

	// Should fall back to default target when group not found
	target, ok := result["target"].(float64)
	assert.True(t, ok, "target should be a number")
	assert.Greater(t, target, float64(0), "target should be positive (falls back to default)")

	// Raised should be 0 for non-existent group
	assert.Equal(t, float64(0), result["raised"])
}

func TestAddDonationExternal(t *testing.T) {
	prefix := uniquePrefix("AddDonation")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	db := database.DBConn

	// Give the calling user GiftAid permission.
	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", userID)

	// Create the target user who receives the donation.
	targetUserID := CreateTestUser(t, prefix+"Target", "User")

	body := fmt.Sprintf(`{"userid":%d,"amount":10.00,"date":"2026-01-15 12:00:00"}`, targetUserID)
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	respBody := rsp(resp)
	var result map[string]interface{}
	json2.Unmarshal(respBody, &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.NotZero(t, result["id"])

	// Verify donation was inserted.
	var donationType string
	db.Raw("SELECT type FROM users_donations WHERE id = ?", uint64(result["id"].(float64))).Scan(&donationType)
	assert.Equal(t, "External", donationType)

	// Verify GiftAid notification was created for the target user.
	var notifCount int64
	db.Raw("SELECT COUNT(*) FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", targetUserID).Scan(&notifCount)
	assert.Greater(t, notifCount, int64(0))

	// Verify email task was queued.
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_donate_external' AND processed_at IS NULL").Scan(&taskCount)
	assert.Greater(t, taskCount, int64(0))

	// Source field must be 'external' so the email is worded correctly.
	var source string
	db.Raw("SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.source')) FROM background_tasks WHERE task_type = 'email_donate_external' AND JSON_EXTRACT(data, '$.user_id') = ? AND processed_at IS NULL ORDER BY id DESC LIMIT 1", targetUserID).Scan(&source)
	assert.Equal(t, "external", source, "Manually-added donation must tag thank-you task with source=external")
}

func TestAddDonationZeroAmount(t *testing.T) {
	prefix := uniquePrefix("AddDonZero")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// No GiftAid permission needed for zero amount.
	targetUserID := CreateTestUser(t, prefix+"Target", "User")

	body := fmt.Sprintf(`{"userid":%d,"amount":0,"date":"2026-01-15 12:00:00"}`, targetUserID)
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	respBody := rsp(resp)
	var result map[string]interface{}
	json2.Unmarshal(respBody, &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.NotZero(t, result["id"])

	// Verify no GiftAid notification for zero amount.
	db := database.DBConn
	var notifCount int64
	db.Raw("SELECT COUNT(*) FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", targetUserID).Scan(&notifCount)
	assert.Equal(t, int64(0), notifCount)
}

func TestAddDonationNoPermission(t *testing.T) {
	prefix := uniquePrefix("AddDonNoPerm")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	targetUserID := CreateTestUser(t, prefix+"Target", "User")

	// Non-zero amount without GiftAid permission should be denied.
	body := fmt.Sprintf(`{"userid":%d,"amount":25.00,"date":"2026-01-15 12:00:00"}`, targetUserID)
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
}

func TestAddDonationUnauthorized(t *testing.T) {
	body := `{"userid":1,"amount":10.00,"date":"2026-01-15 12:00:00"}`
	req := httptest.NewRequest("PUT", "/api/donations", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}

func TestAddDonationMissingUserID(t *testing.T) {
	prefix := uniquePrefix("AddDonNoUID")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"amount":10.00,"date":"2026-01-15 12:00:00"}`
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestAddDonationInvalidUserID(t *testing.T) {
	prefix := uniquePrefix("AddDonBadUID")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	db := database.DBConn

	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", userID)

	body := `{"userid":999999999,"amount":10.00,"date":"2026-01-15 12:00:00"}`
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

// Regression: a donor who exists but has a NULL `fullname` (only firstname /
// lastname) must NOT be rejected. Previously the handler treated a NULL
// fullname as a missing user and returned 400 "Invalid userid", which blocked
// gift-aid uploads for such donors (observed in production 2026-05-30).
func TestAddDonationNullFullname(t *testing.T) {
	prefix := uniquePrefix("AddDonNullName")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	db := database.DBConn

	// Give the calling user GiftAid permission.
	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", userID)

	// Target donor exists with firstname/lastname but a NULL fullname.
	targetUserID := CreateTestUser(t, prefix+"Target", "User")
	db.Exec("UPDATE users SET fullname = NULL, firstname = 'Julie', lastname = 'Mac' WHERE id = ?", targetUserID)

	body := fmt.Sprintf(`{"userid":%d,"amount":10.00,"date":"2026-05-18 12:00:00"}`, targetUserID)
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	respBody := rsp(resp)
	var result map[string]interface{}
	json2.Unmarshal(respBody, &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.NotZero(t, result["id"])

	// Display name should fall back to firstname + lastname.
	var displayName string
	db.Raw("SELECT PayerDisplayName FROM users_donations WHERE id = ?", uint64(result["id"].(float64))).Scan(&displayName)
	assert.Equal(t, "Julie Mac", displayName)
}

func TestBulkUploadDonations(t *testing.T) {
	prefix := uniquePrefix("BulkDon")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	db := database.DBConn

	// Make user an admin.
	db.Exec("UPDATE users SET systemrole = 'Admin' WHERE id = ?", userID)

	body := `{"donations":[
		{"date":"2026-01-15","donor_name":"Alice Test","email":"alice@test.com","program":"PayPalGivingFund","amount":25.00,"transaction_id":"PPGF-TEST-001"},
		{"date":"2026-01-16","donor_name":"Bob Test","email":"bob@test.com","program":"eBay for Charity Seller Donations","amount":10.00,"transaction_id":"PPGF-TEST-002"},
		{"date":"2026-01-17","donor_name":"Carol Test","email":"","program":"Facebook donations with PPGF","amount":5.00,"transaction_id":"PPGF-TEST-003"}
	]}`
	req := httptest.NewRequest("POST", "/api/donations/bulk?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, float64(3), result["inserted"])
	assert.Equal(t, float64(0), result["updated"])
	assert.Equal(t, float64(0), result["skipped"])

	// Verify records inserted with correct sources.
	var source1, source2, source3 string
	db.Raw("SELECT source FROM users_donations WHERE TransactionID = 'PPGF-TEST-001'").Scan(&source1)
	db.Raw("SELECT source FROM users_donations WHERE TransactionID = 'PPGF-TEST-002'").Scan(&source2)
	db.Raw("SELECT source FROM users_donations WHERE TransactionID = 'PPGF-TEST-003'").Scan(&source3)
	assert.Equal(t, "PayPalGivingFund", source1)
	assert.Equal(t, "eBay", source2)
	assert.Equal(t, "Facebook", source3)

	// Verify giftaidconsent is 0 — Gift Aid already claimed by PayPal, so
	// GiftAidClaimService (which only processes giftaidconsent=1) won't reclaim.
	var giftaidconsent1 bool
	db.Raw("SELECT giftaidconsent FROM users_donations WHERE TransactionID = 'PPGF-TEST-001'").Scan(&giftaidconsent1)
	assert.False(t, giftaidconsent1, "giftaidconsent should be false for PayPal Giving Fund donations")

	// Re-upload same data — should update, not insert.
	req2 := httptest.NewRequest("POST", "/api/donations/bulk?jwt="+token, strings.NewReader(body))
	req2.Header.Set("Content-Type", "application/json")
	resp2, _ := getApp().Test(req2)
	assert.Equal(t, fiber.StatusOK, resp2.StatusCode)

	var result2 map[string]interface{}
	json2.Unmarshal(rsp(resp2), &result2)
	// ON DUPLICATE KEY UPDATE counts as 2 rows affected, so RowsAffected != 1
	assert.Equal(t, float64(0), result2["inserted"])

	// Clean up test data.
	db.Exec("DELETE FROM users_donations WHERE TransactionID IN ('PPGF-TEST-001','PPGF-TEST-002','PPGF-TEST-003')")
}

func TestBulkUploadDonationsSkipsInvalid(t *testing.T) {
	prefix := uniquePrefix("BulkDonSkip")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	db := database.DBConn

	db.Exec("UPDATE users SET systemrole = 'Admin' WHERE id = ?", userID)

	body := `{"donations":[
		{"date":"2026-01-15","donor_name":"Negative","email":"neg@test.com","program":"PayPalGivingFund","amount":-5.00,"transaction_id":"PPGF-NEG-001"},
		{"date":"2026-01-16","donor_name":"Huge","email":"huge@test.com","program":"PayPalGivingFund","amount":99999999.99,"transaction_id":"PPGF-HUGE-001"},
		{"date":"2026-01-17","donor_name":"NoTxId","email":"notx@test.com","program":"PayPalGivingFund","amount":10.00,"transaction_id":""},
		{"date":"2026-01-18","donor_name":"Valid","email":"valid@test.com","program":"PayPalGivingFund","amount":15.00,"transaction_id":"PPGF-VALID-001"}
	]}`
	req := httptest.NewRequest("POST", "/api/donations/bulk?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(1), result["inserted"])
	assert.Equal(t, float64(3), result["skipped"])

	db.Exec("DELETE FROM users_donations WHERE TransactionID = 'PPGF-VALID-001'")
}

func TestBulkUploadDonationsRequiresAdmin(t *testing.T) {
	prefix := uniquePrefix("BulkDonAuth")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"donations":[]}`
	req := httptest.NewRequest("POST", "/api/donations/bulk?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
}

func TestBulkUploadDonationsUnauthorized(t *testing.T) {
	body := `{"donations":[]}`
	req := httptest.NewRequest("POST", "/api/donations/bulk", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}

func TestAddDonationSkipsGiftAidNotifWhenExisting(t *testing.T) {
	prefix := uniquePrefix("AddDonGAExist")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	db := database.DBConn

	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", userID)

	targetUserID := CreateTestUser(t, prefix+"Target", "User")

	// Create a pre-existing giftaid record with period != 'This'.
	db.Exec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, 'Declined', 'Test', 'Test')", targetUserID)

	body := fmt.Sprintf(`{"userid":%d,"amount":15.00,"date":"2026-01-15 12:00:00"}`, targetUserID)
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	// Should NOT create a GiftAid notification since giftaid record exists with period != 'This'.
	var notifCount int64
	db.Raw("SELECT COUNT(*) FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", targetUserID).Scan(&notifCount)
	assert.Equal(t, int64(0), notifCount)
}

// Regression: when a donor has two users_emails rows both preferred=0, and the
// alias (@users.ilovefreegle.org) sorts BEFORE the real email under email ASC
// (because '-' < '@' in ASCII), the old query picked the alias. V1 skips our-domain
// addresses on the first pass and only falls back to them if no external email exists.
// Payer must always be the real external email when one is available.
func TestAddDonationPayerUsesRealEmailNotAlias(t *testing.T) {
	prefix := uniquePrefix("DonPayerAlias")
	callerID := CreateTestUser(t, prefix+"Caller", "User")
	_, token := CreateTestSession(t, callerID)
	db := database.DBConn

	// Give the caller GiftAid permission.
	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", callerID)

	// Create the donor WITHOUT any email row (CreateTestUser adds one; we'll replace it).
	donorID := CreateTestUser(t, prefix+"Donor", "User")

	// Remove the default email row added by CreateTestUser.
	db.Exec("DELETE FROM users_emails WHERE userid = ?", donorID)

	// Insert TWO rows, both preferred=0.
	// The alias uses the donor's actual UID so it sorts BEFORE the real email under
	// email ASC (e.g. "aaa-<uid>@users.ilovefreegle.org" vs "aaa@gmail.com").
	realEmail := fmt.Sprintf("aaa%d@gmail.com", donorID)
	aliasEmail := fmt.Sprintf("aaa%d-%d@users.ilovefreegle.org", donorID, donorID)
	// aliasEmail sorts before realEmail because '-' (0x2D) < '@' (0x40) in ASCII.
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 0)", donorID, realEmail)
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 0)", donorID, aliasEmail)

	body := fmt.Sprintf(`{"userid":%d,"amount":0,"date":"2026-01-15 12:00:00"}`, donorID)
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	donationID := uint64(result["id"].(float64))
	assert.NotZero(t, donationID)

	// Payer must be the real external email, not the alias.
	var payer string
	db.Raw("SELECT Payer FROM users_donations WHERE id = ?", donationID).Scan(&payer)
	assert.Equal(t, realEmail, payer,
		"Payer should be the real external email, not the @users.ilovefreegle.org alias")
}

// Regression (fallback): when a donor's ONLY email is an @users.ilovefreegle.org alias,
// AddDonation must still succeed and store the alias in Payer (second-pass fallback).
func TestAddDonationPayerFallsBackToAliasWhenNoRealEmail(t *testing.T) {
	prefix := uniquePrefix("DonPayerFB")
	callerID := CreateTestUser(t, prefix+"Caller", "User")
	_, token := CreateTestSession(t, callerID)
	db := database.DBConn

	// Give the caller GiftAid permission.
	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", callerID)

	// Create donor whose ONLY email is the alias.
	donorID := CreateTestUser(t, prefix+"Donor", "User")
	db.Exec("DELETE FROM users_emails WHERE userid = ?", donorID)
	aliasOnly := fmt.Sprintf("donor%d-%d@users.ilovefreegle.org", donorID, donorID)
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 0)", donorID, aliasOnly)

	body := fmt.Sprintf(`{"userid":%d,"amount":0,"date":"2026-01-15 12:00:00"}`, donorID)
	req := httptest.NewRequest("PUT", "/api/donations?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	donationID := uint64(result["id"].(float64))
	assert.NotZero(t, donationID)

	// When no real email exists, Payer falls back to the alias.
	var payer string
	db.Raw("SELECT Payer FROM users_donations WHERE id = ?", donationID).Scan(&payer)
	assert.Equal(t, aliasOnly, payer,
		"Payer should fall back to the alias when no external email exists")
}
