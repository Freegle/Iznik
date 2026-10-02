package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

func makeChargeEvent(chargeID string, amountPence int64, metadata map[string]string, billingEmail string, description string) []byte {
	metaJSON, _ := json.Marshal(metadata)

	event := fmt.Sprintf(`{
		"id": "evt_test_%s",
		"type": "charge.succeeded",
		"data": {
			"object": {
				"id": "%s",
				"amount": %d,
				"currency": "gbp",
				"description": "%s",
				"metadata": %s,
				"payment_method_details": {"type": "card"},
				"billing_details": {"email": "%s", "name": "Test Donor"}
			}
		}
	}`, chargeID, chargeID, amountPence, description, string(metaJSON), billingEmail)

	return []byte(event)
}

func TestStripeIPN_ChargeSucceeded(t *testing.T) {
	prefix := uniquePrefix("stripein")
	db := database.DBConn

	// Create a test user with an email.
	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"

	// Send a charge.succeeded event with the user's ID in metadata.
	chargeID := "ch_test_" + prefix
	body := makeChargeEvent(chargeID, 1000, map[string]string{"uid": fmt.Sprint(userID)}, email, "One-time donation")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify donation was recorded.
	var donationCount int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&donationCount)
	assert.Equal(t, int64(1), donationCount, "Donation should be recorded")

	// Verify amount.
	var amount float64
	db.Raw("SELECT GrossAmount FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&amount)
	assert.Equal(t, 10.0, amount, "Amount should be £10.00")

	// Verify user was matched.
	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&donorID)
	assert.NotNil(t, donorID)
	assert.Equal(t, userID, *donorID)

	// Clean up.
	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
}

func TestStripeIPN_MatchByBillingEmail(t *testing.T) {
	prefix := uniquePrefix("stripemail")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"

	// Send event without metadata UID — should match by billing email.
	chargeID := "ch_test_" + prefix
	body := makeChargeEvent(chargeID, 500, map[string]string{}, email, "")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&donorID)
	assert.NotNil(t, donorID)
	assert.Equal(t, userID, *donorID)

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
}

func TestStripeIPN_UnmatchedUser(t *testing.T) {
	prefix := uniquePrefix("stripeunk")
	db := database.DBConn

	// Send event with unknown email and no metadata.
	chargeID := "ch_test_" + prefix
	body := makeChargeEvent(chargeID, 2000, map[string]string{}, "unknown@nowhere.com", "")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Donation should still be recorded with null userid.
	var donationCount int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&donationCount)
	assert.Equal(t, int64(1), donationCount, "Donation should be recorded even without user match")

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&donorID)
	assert.Nil(t, donorID, "userid should be NULL for unmatched donor")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
}

func TestStripeIPN_PayPalIgnored(t *testing.T) {
	db := database.DBConn

	// PayPal charges through Stripe should be ignored.
	body := []byte(`{
		"id": "evt_test_paypal",
		"type": "charge.succeeded",
		"data": {
			"object": {
				"id": "ch_test_paypal_ignore",
				"amount": 500,
				"currency": "gbp",
				"description": "",
				"metadata": {},
				"payment_method_details": {"type": "paypal"},
				"billing_details": {"email": "test@test.com", "name": "Test"}
			}
		}
	}`)

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE TransactionID = 'ch_test_paypal_ignore'").Scan(&count)
	assert.Equal(t, int64(0), count, "PayPal charge should not be recorded")
}

func TestStripeIPN_ZeroAmount(t *testing.T) {
	db := database.DBConn

	body := makeChargeEvent("ch_test_zero", 0, map[string]string{}, "test@test.com", "")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE TransactionID = 'ch_test_zero'").Scan(&count)
	assert.Equal(t, int64(0), count, "Zero amount should not be recorded")
}

func TestStripeIPN_UnknownEventType(t *testing.T) {
	body := []byte(`{"id": "evt_test_unknown", "type": "payment_intent.created", "data": {"object": {}}}`)

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestStripeIPN_InvalidPayload(t *testing.T) {
	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBufferString("not json"))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestStripeIPN_RecurringFirstDonation(t *testing.T) {
	prefix := uniquePrefix("striperecur")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"

	// First recurring donation — should trigger thank-you.
	chargeID := "ch_test_" + prefix
	body := makeChargeEvent(chargeID, 500, map[string]string{"uid": fmt.Sprint(userID)}, email, "Subscription creation")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify recorded as subscr_payment.
	var txType string
	db.Raw("SELECT TransactionType FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&txType)
	assert.Equal(t, "subscr_payment", txType)

	// No per-donation thank-you email is queued any more: the daily
	// mail:donations:thank-prep digest coordinates all thanking.
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_donate_external' AND JSON_EXTRACT(data, '$.user_id') = ? AND processed_at IS NULL",
		userID).Scan(&taskCount)
	assert.Equal(t, int64(0), taskCount, "first recurring donation must not queue a per-donation thank-you email")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
}

func TestStripeIPN_GiftAidNotification(t *testing.T) {
	prefix := uniquePrefix("stripegift")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"

	chargeID := "ch_test_" + prefix
	body := makeChargeEvent(chargeID, 1000, map[string]string{"uid": fmt.Sprint(userID)}, email, "")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify gift aid notification was created.
	var notifCount int64
	db.Raw("SELECT COUNT(*) FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", userID).Scan(&notifCount)
	assert.Equal(t, int64(1), notifCount, "Gift aid notification should be created")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
	db.Exec("DELETE FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", userID)
}

// TestStripeIPN_MatchByPriorDonation: a billing email not registered on any
// account, but a prior donation from the same Payer is linked to a valid user.
func TestStripeIPN_MatchByPriorDonation(t *testing.T) {
	prefix := uniquePrefix("stripeprior")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	billingEmail := prefix + "_card@external.com" // not in users_emails
	priorTxn := "ch_prior_" + prefix
	chargeID := "ch_test_" + prefix

	db.Exec("INSERT INTO users_donations (userid, Payer, GrossAmount, TransactionID, TransactionType, source, type, timestamp) "+
		"VALUES (?, ?, 10.00, ?, 'subscr_payment', 'Stripe', 'Stripe', NOW() - INTERVAL 1 MONTH)",
		userID, billingEmail, priorTxn)

	body := makeChargeEvent(chargeID, 1000, map[string]string{}, billingEmail, "One-time donation")
	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&donorID)
	assert.NotNil(t, donorID, "should match via prior donation from same Payer")
	if donorID != nil {
		assert.Equal(t, userID, *donorID)
	}

	db.Exec("DELETE FROM users_donations WHERE TransactionID IN (?, ?)", priorTxn, chargeID)
}

// TestStripeIPN_MatchByCanon: billing email differs from the registered address
// only by canonicalisation; must still match (V1 parity).
func TestStripeIPN_MatchByCanon(t *testing.T) {
	prefix := uniquePrefix("stripecanon")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	billingEmail := prefix + ".donor@test.com"
	canon := user.CanonicalizeEmail(billingEmail)
	storedEmail := canon
	db.Exec("INSERT INTO users_emails (userid, email, canon) VALUES (?, ?, ?)", userID, storedEmail, canon)

	chargeID := "ch_test_" + prefix
	body := makeChargeEvent(chargeID, 1000, map[string]string{}, billingEmail, "One-time donation")
	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var donorID *uint64
	db.Raw("SELECT userid FROM users_donations WHERE TransactionID = ?", chargeID).Scan(&donorID)
	assert.NotNil(t, donorID, "billing email should match registered account via canon")
	if donorID != nil {
		assert.Equal(t, userID, *donorID)
	}

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
	db.Exec("DELETE FROM users_emails WHERE userid = ? AND email = ?", userID, storedEmail)
}

// TestStripeIPN_GiftAid_SoftDeletedDeclarationGetsNotification proves the
// AND deleted IS NULL fix in triggerGiftAidNotification: a donor whose ONLY
// giftaid record has been soft-deleted (deleted IS NOT NULL) must still receive a
// GiftAid notification via the Stripe path, because the deleted record must be
// ignored and the donor treated as having no live declaration.
//
// Before the fix, handleGiftAidNotification queried without AND deleted IS NULL, so
// the deleted 'All' record was found and its period suppressed the notification — a
// silent regression for donors who had previously opted in but later withdrew.
func TestStripeIPN_GiftAid_SoftDeletedDeclarationGetsNotification(t *testing.T) {
	prefix := uniquePrefix("stripegadel")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"

	// Insert a soft-deleted 'All' declaration: the donor previously opted in for
	// ongoing gift aid but the record has since been removed.
	db.Exec("INSERT INTO giftaid (userid, period, fullname, homeaddress, deleted) VALUES (?, 'All', 'Test', 'Test', NOW())",
		userID)

	chargeID := "ch_test_sfdel_" + prefix
	body := makeChargeEvent(chargeID, 1000, map[string]string{"uid": fmt.Sprint(userID)}, email, "")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// With AND deleted IS NULL, the soft-deleted record is invisible. The query
	// finds nothing, period is nil, and the notification IS created.
	var notifCount int64
	db.Raw("SELECT COUNT(*) FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", userID).Scan(&notifCount)
	assert.Greater(t, notifCount, int64(0),
		"GiftAid notification must be created when only giftaid declaration is soft-deleted")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
	db.Exec("DELETE FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", userID)
	db.Exec("DELETE FROM giftaid WHERE userid = ?", userID)
}

// TestStripeIPN_GiftAid_LiveDeclarationSuppressesNotification proves that a
// donor with a live (non-deleted) giftaid declaration with period != PERIOD_THIS
// does NOT receive a GiftAid notification — their agreement is still valid.
func TestStripeIPN_GiftAid_LiveDeclarationSuppressesNotification(t *testing.T) {
	prefix := uniquePrefix("stripegalive")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"

	// Live 'All' declaration — donor has an ongoing gift aid agreement.
	db.Exec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, 'All', 'Test', 'Test')",
		userID)

	chargeID := "ch_test_sflive_" + prefix
	body := makeChargeEvent(chargeID, 1000, map[string]string{"uid": fmt.Sprint(userID)}, email, "")

	req := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var notifCount int64
	db.Raw("SELECT COUNT(*) FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", userID).Scan(&notifCount)
	assert.Equal(t, int64(0), notifCount,
		"GiftAid notification must NOT be created when a live non-This declaration exists")

	db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", chargeID)
	db.Exec("DELETE FROM giftaid WHERE userid = ?", userID)
}

// TestStripeIPN_GiftAid_DuplicateInsertNoError proves that INSERT IGNORE makes
// calling the gift-aid notification path twice for the same user (two consecutive
// charges) produce no database error, even though users_notifications has no
// UNIQUE constraint on (touser, type) and a second row is silently inserted.
func TestStripeIPN_GiftAid_DuplicateInsertNoError(t *testing.T) {
	prefix := uniquePrefix("stripegadup")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_donor", "User")
	email := prefix + "_donor@test.com"

	// First charge — creates a GiftAid notification.
	chargeID1 := "ch_dup1_" + prefix
	body1 := makeChargeEvent(chargeID1, 1000, map[string]string{"uid": fmt.Sprint(userID)}, email, "")
	req1 := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body1))
	req1.Header.Set("Content-Type", "application/json")
	resp1, err := getApp().Test(req1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp1.StatusCode)

	// Second charge for the same user — INSERT IGNORE must not error even though
	// a notification row already exists (no unique constraint, so a second row is
	// inserted, but there is no FK or uniqueness violation).
	chargeID2 := "ch_dup2_" + prefix
	body2 := makeChargeEvent(chargeID2, 1000, map[string]string{"uid": fmt.Sprint(userID)}, email, "")
	req2 := httptest.NewRequest("POST", "/api/stripeipn", bytes.NewBuffer(body2))
	req2.Header.Set("Content-Type", "application/json")
	resp2, err := getApp().Test(req2)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp2.StatusCode,
		"second charge.succeeded for the same user must not error on duplicate notification")

	db.Exec("DELETE FROM users_donations WHERE TransactionID IN (?, ?)", chargeID1, chargeID2)
	db.Exec("DELETE FROM users_notifications WHERE touser = ? AND type = 'GiftAid'", userID)
}
