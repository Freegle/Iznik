package test

import (
	"fmt"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

func makePayPalForm(values map[string]string) string {
	form := url.Values{}
	for k, v := range values {
		form.Set(k, v)
	}
	return form.Encode()
}

func TestPayPalIPN_ValidCharge(t *testing.T) {
	prefix := uniquePrefix("paypalipn")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"
	txnID := "PAY_" + prefix

	body := makePayPalForm(map[string]string{
		"mc_gross":     "15.00",
		"payer_email":  email,
		"first_name":   "Test",
		"last_name":    "Donor",
		"txn_id":       txnID,
		"txn_type":     "web_accept",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify donation was recorded.
	var donationCount int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE TransactionID = ?", txnID).Scan(&donationCount)
	assert.Equal(t, int64(1), donationCount, "Donation should be recorded")

	// Verify amount.
	var amount float64
	db.Raw("SELECT GrossAmount FROM users_donations WHERE TransactionID = ?", txnID).Scan(&amount)
	assert.Equal(t, 15.0, amount)

	// Verify user was matched by email.
	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", txnID).Scan(&donorID)
	assert.NotNil(t, donorID)
	assert.Equal(t, userID, *donorID)

	// Verify source and type.
	type DonationRow struct {
		Source string
		Type   string
	}
	var row DonationRow
	db.Raw("SELECT source, `type` FROM users_donations WHERE TransactionID = ?", txnID).Scan(&row)
	assert.Equal(t, "DonateWithPayPal", row.Source)
	assert.Equal(t, "PayPal", row.Type)

	// Clean up.
	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", txnID)
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'email_donate_external' AND data LIKE ?",
		fmt.Sprintf("%%\"user_id\":%d%%", userID))
}

func TestPayPalIPN_MatchByEmail(t *testing.T) {
	prefix := uniquePrefix("paypalemail")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"
	txnID := "PAY_" + prefix

	body := makePayPalForm(map[string]string{
		"mc_gross":     "5.00",
		"payer_email":  email,
		"first_name":   "Jane",
		"last_name":    "Smith",
		"txn_id":       txnID,
		"txn_type":     "web_accept",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", txnID).Scan(&donorID)
	assert.NotNil(t, donorID)
	assert.Equal(t, userID, *donorID)

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", txnID)
}

func TestPayPalIPN_UnmatchedUser(t *testing.T) {
	prefix := uniquePrefix("paypalunk")
	db := database.DBConn

	txnID := "PAY_" + prefix

	body := makePayPalForm(map[string]string{
		"mc_gross":     "20.00",
		"payer_email":  "unknown_" + prefix + "@nowhere.com",
		"first_name":   "Unknown",
		"last_name":    "Person",
		"txn_id":       txnID,
		"txn_type":     "web_accept",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Donation should still be recorded with null userid.
	var donationCount int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE TransactionID = ?", txnID).Scan(&donationCount)
	assert.Equal(t, int64(1), donationCount, "Donation should be recorded even without user match")

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", txnID).Scan(&donorID)
	assert.Nil(t, donorID, "userid should be NULL for unmatched donor")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", txnID)
}

func TestPayPalIPN_ExcludedPayer(t *testing.T) {
	prefix := uniquePrefix("paypalexcl")
	db := database.DBConn

	// ppgfukpay@paypalgivingfund.org is in the default exclusion list.
	excludedEmail := "ppgfukpay@paypalgivingfund.org"
	txnID := "PAY_" + prefix

	// Create a user with the excluded email so we can test the exclusion logic.
	userID := CreateTestUserWithEmail(t, prefix+"_excl", excludedEmail)

	body := makePayPalForm(map[string]string{
		"mc_gross":     "50.00",
		"payer_email":  excludedEmail,
		"first_name":   "PayPal",
		"last_name":    "GivingFund",
		"txn_id":       txnID,
		"txn_type":     "web_accept",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Donation should be recorded (we always record).
	var donationCount int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE TransactionID = ?", txnID).Scan(&donationCount)
	assert.Equal(t, int64(1), donationCount)

	// But NO thank-you email should be queued for excluded payer.
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_donate_external' AND JSON_EXTRACT(data, '$.user_id') = ? AND processed_at IS NULL",
		userID).Scan(&taskCount)
	assert.Equal(t, int64(0), taskCount, "No thank-you email for excluded payer")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", txnID)
}

func TestPayPalIPN_RecurringVsOneOff(t *testing.T) {
	prefix := uniquePrefix("paypalrecur")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"
	txnID := "PAY_" + prefix

	// First recurring donation.
	body := makePayPalForm(map[string]string{
		"mc_gross":     "5.00",
		"payer_email":  email,
		"first_name":   "Recurring",
		"last_name":    "Donor",
		"txn_id":       txnID,
		"txn_type":     "subscr_payment",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify transaction type recorded.
	var txnType string
	db.Raw("SELECT TransactionType FROM users_donations WHERE TransactionID = ?", txnID).Scan(&txnType)
	assert.Equal(t, "subscr_payment", txnType)

	// No per-donation thank-you email is queued any more: the daily
	// mail:donations:thank-prep digest coordinates all thanking.
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_donate_external' AND JSON_EXTRACT(data, '$.user_id') = ? AND processed_at IS NULL",
		userID).Scan(&taskCount)
	assert.Equal(t, int64(0), taskCount, "first recurring donation must not queue a per-donation thank-you email")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", txnID)
}

func TestPayPalIPN_OneOffBelowThresholdNoThankYou(t *testing.T) {
	prefix := uniquePrefix("paypalsmall")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"
	txnID := "PAY_" + prefix

	// One-off £10 donation — below £20 threshold, must NOT queue thank-you.
	body := makePayPalForm(map[string]string{
		"mc_gross":     "10.00",
		"payer_email":  email,
		"first_name":   "Small",
		"last_name":    "Donor",
		"txn_id":       txnID,
		"txn_type":     "web_accept",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_donate_external' AND JSON_EXTRACT(data, '$.user_id') = ? AND processed_at IS NULL",
		userID).Scan(&taskCount)
	assert.Equal(t, int64(0), taskCount, "One-off donation below £20 must not queue thank-you")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", txnID)
}

// TestPayPalIPN_MatchByPriorDonation covers the case where a recurring donor's
// PayPal payer email is no longer (or never was) on their Freegle account — but
// a PRIOR donation from the same Payer is already linked to a still-valid user.
// V1 matched these; the Go port must too. (e.g. donor changed their Freegle
// email but PayPal keeps billing the old address.)
func TestPayPalIPN_MatchByPriorDonation(t *testing.T) {
	prefix := uniquePrefix("paypalprior")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	// PayPal email that is NOT registered on the account.
	payerEmail := prefix + "_paypal@external.com"
	priorTxn := "PRIOR_" + prefix
	newTxn := "PAY_" + prefix

	// A historical donation from this Payer, already linked to the valid user.
	db.Exec("INSERT INTO users_donations (userid, Payer, GrossAmount, TransactionID, TransactionType, source, type, timestamp) "+
		"VALUES (?, ?, 5.00, ?, 'subscr_payment', 'DonateWithPayPal', 'PayPal', NOW() - INTERVAL 1 MONTH)",
		userID, payerEmail, priorTxn)

	body := makePayPalForm(map[string]string{
		"mc_gross":     "5.00",
		"payer_email":  payerEmail,
		"first_name":   "Recurring",
		"last_name":    "Donor",
		"txn_id":       newTxn,
		"txn_type":     "subscr_payment",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", newTxn).Scan(&donorID)
	assert.NotNil(t, donorID, "new donation should be matched via prior donation from same Payer")
	if donorID != nil {
		assert.Equal(t, userID, *donorID)
	}

	db.Exec("DELETE FROM users_donations WHERE TransactionID IN (?, ?)", priorTxn, newTxn)
}

// TestPayPalIPN_PriorDonationDeletedUserNotMatched guards the merge/deletion
// case: if the only prior-donation link points to a deleted (or merged-away)
// user, we must NOT reuse it — the donation stays unmatched.
func TestPayPalIPN_PriorDonationDeletedUserNotMatched(t *testing.T) {
	prefix := uniquePrefix("paypalpriordel")
	db := database.DBConn

	deletedID := CreateDeletedTestUser(t, prefix+"_gone")
	payerEmail := prefix + "_paypal@external.com"
	priorTxn := "PRIOR_" + prefix
	newTxn := "PAY_" + prefix

	db.Exec("INSERT INTO users_donations (userid, Payer, GrossAmount, TransactionID, TransactionType, source, type, timestamp) "+
		"VALUES (?, ?, 5.00, ?, 'subscr_payment', 'DonateWithPayPal', 'PayPal', NOW() - INTERVAL 1 MONTH)",
		deletedID, payerEmail, priorTxn)

	body := makePayPalForm(map[string]string{
		"mc_gross":     "5.00",
		"payer_email":  payerEmail,
		"first_name":   "Recurring",
		"last_name":    "Donor",
		"txn_id":       newTxn,
		"txn_type":     "subscr_payment",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", newTxn).Scan(&donorID)
	assert.Nil(t, donorID, "must not match a prior donation linked to a deleted/merged-away user")

	db.Exec("DELETE FROM users_donations WHERE TransactionID IN (?, ?)", priorTxn, newTxn)
}

// TestPayPalIPN_MatchByCanon covers V1's `email = ? OR canon = ?` matching: a
// payer email that differs from the registered address only by canonicalisation
// (gmail dots, +tag, etc.) must still match. The Go port dropped this.
func TestPayPalIPN_MatchByCanon(t *testing.T) {
	prefix := uniquePrefix("paypalcanon")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")

	// Register a canonicalised email; the incoming payer is a dotted variant
	// that is NOT an exact match but shares the same canon.
	payerEmail := prefix + ".donor@test.com"
	canon := user.CanonicalizeEmail(payerEmail)
	storedEmail := canon // exact-different from payerEmail (no dot), same canon
	db.Exec("INSERT INTO users_emails (userid, email, canon) VALUES (?, ?, ?)", userID, storedEmail, canon)

	newTxn := "PAY_" + prefix
	body := makePayPalForm(map[string]string{
		"mc_gross":     "5.00",
		"payer_email":  payerEmail,
		"first_name":   "Canon",
		"last_name":    "Donor",
		"txn_id":       newTxn,
		"txn_type":     "subscr_payment",
		"payment_date": "2026-01-15 10:30:00",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", newTxn).Scan(&donorID)
	assert.NotNil(t, donorID, "payer email should match registered account via canon")
	if donorID != nil {
		assert.Equal(t, userID, *donorID)
	}

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", newTxn)
	db.Exec("DELETE FROM users_emails WHERE userid = ? AND email = ?", userID, storedEmail)
}

func TestPayPalIPN_NoMcGross(t *testing.T) {
	// An IPN without mc_gross should be ignored.
	body := makePayPalForm(map[string]string{
		"payer_email": "test@test.com",
		"txn_id":      "PAY_nomcgross",
		"txn_type":    "web_accept",
	})

	req := httptest.NewRequest("POST", "/donateipn", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}
