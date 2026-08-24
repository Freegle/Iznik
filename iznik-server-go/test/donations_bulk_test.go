package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// bulkDonation mirrors the JSON shape the housekeeper extension sends
type bulkDonation struct {
	Date          string  `json:"date"`
	DonorName     string  `json:"donor_name"`
	Email         string  `json:"email"`
	Program       string  `json:"program"`
	Amount        float64 `json:"amount"`
	TransactionID string  `json:"transaction_id"`
}

func testBulkPost(token string, donations []bulkDonation) (int, map[string]interface{}) {
	body, _ := json2.Marshal(map[string]interface{}{"donations": donations})
	url := "/api/donations/bulk"
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("POST", url, strings.NewReader(string(body)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	return resp.StatusCode, result
}

func uniqueTxID(prefix string) string {
	return fmt.Sprintf("%s_%s", prefix, uniquePrefix(prefix))
}

func cleanupDonations(t *testing.T, txIDs ...string) {
	db := database.DBConn
	for _, id := range txIDs {
		db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", id)
	}
}

// TestBulkDonations_RequiresAuth ensures unauthenticated requests are rejected
func TestBulkDonations_RequiresAuth(t *testing.T) {
	status, _ := testBulkPost("", []bulkDonation{})
	assert.Equal(t, 401, status)
}

// TestBulkDonations_RequiresSupportRole ensures regular users are rejected
func TestBulkDonations_RequiresSupportRole(t *testing.T) {
	prefix := uniquePrefix("bulk_role")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	status, _ := testBulkPost(token, []bulkDonation{})
	assert.Equal(t, 403, status)
}

// TestBulkDonations_InsertsNew verifies new donations are inserted and counted correctly
func TestBulkDonations_InsertsNew(t *testing.T) {
	prefix := uniquePrefix("bulk_insert")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	tx1 := uniqueTxID("TX_NEW_A")
	tx2 := uniqueTxID("TX_NEW_B")
	defer cleanupDonations(t, tx1, tx2)

	donations := []bulkDonation{
		{Date: "2026-06-01", DonorName: "Alice Smith", Email: "alice@example.com", Program: "Freegle", Amount: 10.00, TransactionID: tx1},
		{Date: "2026-06-02", DonorName: "Bob Jones", Email: "bob@example.com", Program: "Freegle", Amount: 25.50, TransactionID: tx2},
	}

	status, result := testBulkPost(token, donations)
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(2), result["inserted"])
	assert.Equal(t, float64(0), result["updated"])
	assert.Equal(t, float64(0), result["skipped"])
}

// TestBulkDonations_SkipsDuplicates verifies identical re-submissions are skipped
func TestBulkDonations_SkipsDuplicates(t *testing.T) {
	prefix := uniquePrefix("bulk_dedup")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	tx1 := uniqueTxID("TX_DUP")
	defer cleanupDonations(t, tx1)

	donations := []bulkDonation{
		{Date: "2026-06-01", DonorName: "Charlie", Email: "charlie@example.com", Program: "Freegle", Amount: 5.00, TransactionID: tx1},
	}

	// First submission — should insert
	status1, result1 := testBulkPost(token, donations)
	assert.Equal(t, 200, status1)
	assert.Equal(t, float64(1), result1["inserted"])

	// Second identical submission — should skip (same data)
	status2, result2 := testBulkPost(token, donations)
	assert.Equal(t, 200, status2)
	assert.Equal(t, float64(0), result2["inserted"])
	assert.Equal(t, float64(0), result2["updated"])
	assert.Equal(t, float64(1), result2["skipped"])
}

// TestBulkDonations_UpdatesChanged verifies changed amount on re-submission is recorded
func TestBulkDonations_UpdatesChanged(t *testing.T) {
	prefix := uniquePrefix("bulk_update")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	tx1 := uniqueTxID("TX_UPD")
	defer cleanupDonations(t, tx1)

	original := []bulkDonation{
		{Date: "2026-06-01", DonorName: "Dana", Email: "dana@example.com", Program: "Freegle", Amount: 10.00, TransactionID: tx1},
	}
	revised := []bulkDonation{
		{Date: "2026-06-01", DonorName: "Dana", Email: "dana@example.com", Program: "Freegle", Amount: 15.00, TransactionID: tx1},
	}

	status1, result1 := testBulkPost(token, original)
	assert.Equal(t, 200, status1)
	assert.Equal(t, float64(1), result1["inserted"])

	status2, result2 := testBulkPost(token, revised)
	assert.Equal(t, 200, status2)
	assert.Equal(t, float64(0), result2["inserted"])
	assert.Equal(t, float64(1), result2["updated"])
	assert.Equal(t, float64(0), result2["skipped"])

	// Verify the DB has the updated amount
	db := database.DBConn
	var stored float64
	db.Raw("SELECT GrossAmount FROM users_donations WHERE TransactionID = ?", tx1).Scan(&stored)
	assert.Equal(t, 15.00, stored)
}

// TestBulkDonations_EmptyList verifies an empty submission succeeds with zero counts
func TestBulkDonations_EmptyList(t *testing.T) {
	prefix := uniquePrefix("bulk_empty")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	status, result := testBulkPost(token, []bulkDonation{})
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(0), result["inserted"])
	assert.Equal(t, float64(0), result["updated"])
	assert.Equal(t, float64(0), result["skipped"])
}

// TestBulkDonations_AdminRoleAllowed verifies Admin role (not just Support) can call the endpoint
func TestBulkDonations_AdminRoleAllowed(t *testing.T) {
	prefix := uniquePrefix("bulk_admin")
	userID := CreateTestUser(t, prefix, "Admin")
	_, token := CreateTestSession(t, userID)

	tx1 := uniqueTxID("TX_ADMIN")
	defer cleanupDonations(t, tx1)

	donations := []bulkDonation{
		{Date: "2026-06-01", DonorName: "Eve", Email: "eve@example.com", Program: "Freegle", Amount: 20.00, TransactionID: tx1},
	}

	status, result := testBulkPost(token, donations)
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(1), result["inserted"])
}
