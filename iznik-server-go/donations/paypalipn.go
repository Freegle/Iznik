package donations

import (
	"log"
	"regexp"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	stripe "github.com/stripe/stripe-go/v82"
	"github.com/stripe/stripe-go/v82/paymentintent"
)

// PayPalIPN handles PayPal Instant Payment Notification callbacks.
// This is the Go equivalent of the legacy V1 PHP donate-IPN handler.
//
// PayPal sends a POST with form-encoded data when a donation is received.
// We record the donation, handle gift aid notifications, and queue thank-you emails.
//
// @Summary Handle PayPal IPN
// @Tags donations
// @Accept application/x-www-form-urlencoded
// @Produce json
// @Router /donateipn [post]
func PayPalIPN(c *fiber.Ctx) error {
	mcGross := c.FormValue("mc_gross")
	payerEmail := c.FormValue("payer_email")
	firstName := c.FormValue("first_name")
	lastName := c.FormValue("last_name")
	txnID := c.FormValue("txn_id")
	txnType := c.FormValue("txn_type")
	paymentDate := c.FormValue("payment_date")
	custom := c.FormValue("custom")

	log.Printf("[PayPalIPN] Received IPN: txn_id=%s, txn_type=%s, mc_gross=%s, payer_email=%s",
		txnID, txnType, mcGross, payerEmail)

	if mcGross == "" {
		log.Printf("[PayPalIPN] No mc_gross, ignoring")
		return c.SendStatus(fiber.StatusOK)
	}

	gdb := database.DBConn

	// Try to identify the user.
	var userID uint64
	displayName := firstName + " " + lastName

	// Check if this is a PayPal-through-Stripe payment. The custom field contains the
	// Stripe PaymentIntent ID in format: acct_xxx:pi_xxx:hash.
	if custom != "" {
		re := regexp.MustCompile(`pi_[A-Za-z0-9_]+`)
		if match := re.FindString(custom); match != "" {
			log.Printf("[PayPalIPN] Found Stripe PaymentIntent ID: %s", match)

			key := getStripeKey(false)
			if key != "" {
				stripeMu.Lock()
				stripe.Key = key
				pi, err := paymentintent.Get(match, nil)
				stripeMu.Unlock()

				if err == nil && pi != nil && pi.Metadata != nil {
					if uidStr, ok := pi.Metadata["uid"]; ok && uidStr != "" {
						var uid uint64
						// ORM migration site b55d22304524 (wave 1).
						gdb.Table("users").Select("id").Where("id = ?", uidStr).Scan(&uid)
						if uid > 0 {
							userID = uid
							log.Printf("[PayPalIPN] Matched user %d from Stripe metadata", userID)
						}
					}
				} else if err != nil {
					log.Printf("[PayPalIPN] Failed to retrieve Stripe PaymentIntent: %v", err)
				}
			}
		}
	}

	// Fallback: registered address (exact or canonical) or a prior donation from
	// the same Payer that's linked to a still-valid user. Mirrors V1 findByEmail
	// and recovers recurring donors whose Freegle email later changed while
	// PayPal keeps billing the original address.
	if userID == 0 && payerEmail != "" {
		userID = MatchUserByEmailOrPriorDonation(payerEmail)
		if userID > 0 {
			log.Printf("[PayPalIPN] Matched user %d for payer email %s (email/canon/prior donation)", userID, payerEmail)
		}
	}

	// Parse payment_date — PayPal uses format like "12:34:56 Jan 01, 2026 PST".
	var timestamp string
	if paymentDate != "" {
		parsed, err := parsePayPalDate(paymentDate)
		if err != nil {
			log.Printf("[PayPalIPN] Failed to parse payment_date '%s': %v, using now", paymentDate, err)
			timestamp = time.Now().Format("2006-01-02 15:04:05")
		} else {
			timestamp = parsed.Format("2006-01-02 15:04:05")
		}
	} else {
		timestamp = time.Now().Format("2006-01-02 15:04:05")
	}

	// Record the donation.
	var userIDPtr *uint64
	if userID > 0 {
		userIDPtr = &userID
	}

	result := gdb.Exec(
		"INSERT INTO users_donations (userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, source, TransactionType, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
		userIDPtr, payerEmail, displayName, timestamp,
		txnID, mcGross, SOURCE_DONATE_WITH_PAYPAL, txnType, TYPE_PAYPAL,
	)

	if result.Error != nil {
		log.Printf("[PayPalIPN] Failed to record donation: %v", result.Error)
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to record donation"})
	}

	log.Printf("[PayPalIPN] Recorded donation txn_id=%s for user=%d amount=%s", txnID, userID, mcGross)

	// Handle gift aid notification.
	if userID > 0 {
		handleGiftAidNotification(userID)
	}

	// Thank-you requests are no longer sent per donation: the daily
	// mail:donations:thank-prep digest now coordinates all thanking (first
	// recurring / one-off >= threshold / external), so a per-donation email
	// here would just duplicate it. See DonationThankPrepService.

	return c.SendStatus(fiber.StatusOK)
}

// IsExcludedPayer checks if a payer email is in the exclusion list (e.g. PayPal Giving Fund).
func IsExcludedPayer(email string) bool {
	for _, excluded := range getExcludedPayers() {
		if strings.EqualFold(email, excluded) {
			return true
		}
	}
	return false
}

// parsePayPalDate tries to parse a PayPal date string.
// PayPal uses formats like "12:34:56 Jan 01, 2026 PST" or PHP strtotime compatible strings.
func parsePayPalDate(dateStr string) (time.Time, error) {
	// Try common PayPal formats.
	formats := []string{
		"15:04:05 Jan 02, 2006 MST",
		"15:04:05 Jan 02, 2006",
		"2006-01-02 15:04:05",
		"2006-01-02T15:04:05Z",
		time.RFC1123,
		time.RFC1123Z,
	}

	for _, format := range formats {
		if t, err := time.Parse(format, dateStr); err == nil {
			return t, nil
		}
	}

	return time.Time{}, &time.ParseError{Value: dateStr, Message: "no matching format"}
}
