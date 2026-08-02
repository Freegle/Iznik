package donations

import (
	"encoding/json"
	"log"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	stripe "github.com/stripe/stripe-go/v82"
	stripecustomer "github.com/stripe/stripe-go/v82/customer"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// MANUAL_THANKS is the minimum one-off donation amount (GBP) that triggers a thank-you request.
// Must match Donations::MANUAL_THANKS in the legacy V1 PHP implementation.
const MANUAL_THANKS = 20.0

// StripeIPN handles Stripe webhook notifications (charge.succeeded).
// This is the Go equivalent of the legacy V1 PHP Stripe webhook handler.
//
// Stripe sends a POST with a JSON event body. We parse the event, record the
// donation, handle gift aid notifications, and queue thank-you emails.
//
// @Summary Handle Stripe webhook
// @Tags donations
// @Accept json
// @Produce json
// @Router /stripeipn [post]
func StripeIPN(c *fiber.Ctx) error {
	body := c.Body()
	log.Printf("[StripeIPN] Received webhook, body length %d", len(body))

	// Parse the event JSON.
	var event stripe.Event
	if err := json.Unmarshal(body, &event); err != nil {
		log.Printf("[StripeIPN] Invalid payload: %v", err)
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid payload"})
	}

	log.Printf("[StripeIPN] Event type: %s, ID: %s", event.Type, event.ID)

	switch event.Type {
	case "charge.succeeded":
		return handleChargeSucceeded(c, &event)
	default:
		log.Printf("[StripeIPN] Ignoring event type: %s", event.Type)
		return c.SendStatus(fiber.StatusOK)
	}
}

// handleChargeSucceeded processes a successful Stripe charge.
func handleChargeSucceeded(c *fiber.Ctx, event *stripe.Event) error {
	gdb := database.DBConn

	// Parse the charge object from the event data.
	var charge stripe.Charge
	if err := json.Unmarshal(event.Data.Raw, &charge); err != nil {
		log.Printf("[StripeIPN] Failed to parse charge data: %v", err)
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Failed to parse charge"})
	}

	// Amount is in pence — convert to pounds.
	amount := float64(charge.Amount) / 100.0

	// Exclude PayPal charges (we get a separate IPN for those).
	var paymentMethod string
	if charge.PaymentMethodDetails != nil {
		paymentMethod = string(charge.PaymentMethodDetails.Type)
	}

	log.Printf("[StripeIPN] Charge succeeded: £%.2f, method=%s, charge_id=%s",
		amount, paymentMethod, charge.ID)

	if amount == 0 {
		log.Printf("[StripeIPN] Zero amount, ignoring")
		return c.SendStatus(fiber.StatusOK)
	}

	if paymentMethod == "paypal" {
		log.Printf("[StripeIPN] PayPal payment, ignoring (handled by PayPal IPN)")
		return c.SendStatus(fiber.StatusOK)
	}

	// Try to identify the user.
	userID, userName, userEmail := matchDonorUser(&charge)

	// Determine if this is a recurring payment.
	recurring := charge.Description == "Subscription creation"

	// Record the donation.
	var transactionType *string
	if recurring {
		tt := "subscr_payment"
		transactionType = &tt
	}

	var userIDPtr *uint64
	if userID > 0 {
		userIDPtr = &userID
	}

	// Read the new donation id from the write result, not a read-split-routable SELECT
	// (9832 class). Here it only feeds the log line below, but keep it correct anyway.
	donationID, err := database.ExecInsertGetID(gdb,
		"INSERT INTO users_donations (userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, source, TransactionType, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
		userIDPtr, userEmail, userName, time.Now().Format("2006-01-02 15:04:05"),
		charge.ID, amount, TYPE_STRIPE, transactionType, TYPE_STRIPE,
	)

	if err != nil {
		log.Printf("[StripeIPN] Failed to record donation: %v", err)
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to record donation"})
	}
	log.Printf("[StripeIPN] Recorded donation id=%d for user=%d amount=£%.2f", donationID, userID, amount)

	// Handle gift aid notification.
	if userID > 0 {
		handleGiftAidNotification(userID)
	}

	// Thank-you requests are no longer sent per donation: the daily
	// mail:donations:thank-prep digest coordinates all thanking. See
	// DonationThankPrepService.

	return c.SendStatus(fiber.StatusOK)
}

// matchDonorUser tries to identify the Freegle user who made the donation.
// Returns (userID, userName, userEmail). userID may be 0 if not matched.
func matchDonorUser(charge *stripe.Charge) (uint64, string, string) {
	gdb := database.DBConn
	var userID uint64
	var userName, userEmail string

	// 1. Try metadata UID from the charge.
	if charge.Metadata != nil {
		if uidStr, ok := charge.Metadata["uid"]; ok && uidStr != "" {
			uid, err := strconv.ParseUint(uidStr, 10, 64)
			if err == nil && uid > 0 {
				var exists uint64
				// ORM migration site 015d0fcc34c4 (wave 1).
				gdb.Table("users").Select("id").Where("id = ?", uid).Scan(&exists)
				if exists > 0 {
					userID = uid
					log.Printf("[StripeIPN] Matched user %d from charge metadata", userID)
				}
			}
		}
	}

	// 2. Try the Stripe customer if no metadata match.
	if userID == 0 && charge.Customer != nil && charge.Customer.ID != "" {
		log.Printf("[StripeIPN] Looking up customer %s", charge.Customer.ID)

		key := getStripeKey(false)
		if key != "" {
			stripeMu.Lock()
			stripe.Key = key
			cust, err := stripecustomer.Get(charge.Customer.ID, nil)
			stripeMu.Unlock()

			if err == nil && cust != nil {
				// Try customer metadata UID.
				if uidStr, ok := cust.Metadata["uid"]; ok && uidStr != "" {
					uid, err := strconv.ParseUint(uidStr, 10, 64)
					if err == nil && uid > 0 {
						var exists uint64
						// ORM migration site 83599f80cec3 (wave 1).
						gdb.Table("users").Select("id").Where("id = ?", uid).Scan(&exists)
						if exists > 0 {
							userID = uid
							log.Printf("[StripeIPN] Matched user %d from customer metadata", userID)
						}
					}
				}

				// Try customer email.
				if userID == 0 && cust.Email != "" {
					// ORM migration site 3c00a7ee8fd9 (wave 1).
					gdb.Table("users_emails").Select("userid").Where("email = ? AND userid IS NOT NULL", cust.Email).Limit(1).Scan(&userID)
					if userID > 0 {
						log.Printf("[StripeIPN] Matched user %d from customer email %s", userID, cust.Email)
					}
				}
			}
		}
	}

	// 3. Try billing_details.email from the charge.
	if userID == 0 && charge.BillingDetails != nil && charge.BillingDetails.Email != "" {
		billingEmail := charge.BillingDetails.Email
		// ORM migration site 7104e922999e (wave 1).
		gdb.Table("users_emails").Select("userid").Where("email = ? AND userid IS NOT NULL", billingEmail).Limit(1).Scan(&userID)
		if userID > 0 {
			log.Printf("[StripeIPN] Matched user %d from billing email %s", userID, billingEmail)
		}
	}

	// 4. Canonical-email and prior-donation fallbacks (V1 parity), using the
	//    best available payer email.
	if userID == 0 {
		payerEmail := ""
		if charge.BillingDetails != nil {
			payerEmail = charge.BillingDetails.Email
		}
		if payerEmail != "" {
			userID = MatchUserByEmailOrPriorDonation(payerEmail)
			if userID > 0 {
				log.Printf("[StripeIPN] Matched user %d via email/canon/prior donation for %s", userID, payerEmail)
			}
		}
	}

	// Get user name and email for the matched user.
	if userID > 0 {
		// ORM migration site c1cf9529710a (wave 1).
		gdb.Table("users_emails").Select("email").Where("userid = ?", userID).Order("preferred DESC").Limit(1).Scan(&userEmail)
		// ORM migration site 9b52d8bd115c (wave 1).
		gdb.Table("users").Select("fullname").Where("id = ?", userID).Scan(&userName)
		log.Printf("[StripeIPN] User %d: name=%s email=%s", userID, userName, userEmail)
	} else {
		// Use billing details as fallback.
		if charge.BillingDetails != nil {
			userEmail = charge.BillingDetails.Email
			userName = charge.BillingDetails.Name
		}
		log.Printf("[StripeIPN] No user matched, using billing details: name=%s email=%s", userName, userEmail)
	}

	return userID, userName, userEmail
}

// handleGiftAidNotification checks if the user needs a gift aid notification.
func handleGiftAidNotification(userID uint64) {
	gdb := database.DBConn

	type GiftAidRecord struct {
		Period string
	}

	var giftaid GiftAidRecord
	// ORM migration site 433192020fab (wave 1).
	gdb.Table("giftaid").Select("period").Where("userid = ?", userID).Order("id DESC").Limit(1).Scan(&giftaid)

	if giftaid.Period == "" || giftaid.Period == PERIOD_THIS {
		// No gift aid declaration or only a temporary one — prompt them.
		// ORM migration site a827fcf725a7 (wave 3).
		gdb.Table("users_notifications").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"fromuser":  gorm.Expr("NULL"),
			"touser":    userID,
			"type":      gorm.Expr("'GiftAid'"),
			"timestamp": gorm.Expr("NOW()"),
		})
		log.Printf("[StripeIPN] Created gift aid notification for user %d (period=%s)", userID, giftaid.Period)
	}
}
