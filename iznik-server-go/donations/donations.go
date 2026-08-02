package donations

import (
	"fmt"
	"log"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

const TYPE_PAYPAL = "PayPal"
const TYPE_EXTERNAL = "External"
const TYPE_OTHER = "Other"
const TYPE_STRIPE = "Stripe"

const SOURCE_DONATE_WITH_PAYPAL = "DonateWithPayPal"
const SOURCE_PAYPAL_GIVING_FUND = "PayPalGivingFund"
const SOURCE_BANK_TRANSFER = "BankTransfer"

const PERIOD_THIS = "This"

// Overridable via DONATION_TARGET / DONATIONS_EXCLUDE in .env (wired through
// docker-compose into the apiv2 container). Production still reads /etc/iznik.conf.
const DEFAULT_DONATION_TARGET = 5000
const DEFAULT_DONATIONS_EXCLUDE = "ppgfukpay@paypalgivingfund.org,paypal.msb@tipalti.com"

// getDonationTarget returns the donation target from env var or default
func getDonationTarget() int {
	if target := os.Getenv("DONATION_TARGET"); target != "" {
		if val, err := strconv.Atoi(target); err == nil {
			return val
		}
	}
	return DEFAULT_DONATION_TARGET
}

// getExcludedPayers returns list of emails to exclude from donation counts
//
// Excluded emails come from the DONATIONS_EXCLUDE environment variable (comma-separated).
// These are typically:
// - ppgfukpay@paypalgivingfund.org: PayPal Giving Fund payments (already donated through other channels)
// - paypal.msb@tipalti.com: Tipalti payment processor (internal transfers, not actual donations)
//
// The exclusion list is configurable via environment variable to handle future payment processors
// or partnership accounts that shouldn't count toward donation targets.
//
// Returns the list of payer emails excluded from donation totals.
func getExcludedPayers() []string {
	exclude := os.Getenv("DONATIONS_EXCLUDE")
	if exclude == "" {
		exclude = DEFAULT_DONATIONS_EXCLUDE
	}
	emails := strings.Split(exclude, ",")
	var result []string
	for _, email := range emails {
		if trimmed := strings.TrimSpace(email); trimmed != "" {
			result = append(result, trimmed)
		}
	}
	return result
}

// MatchUserByEmailOrPriorDonation finds a still-valid Freegle user for a payer
// email, mirroring V1 donateipn.php / User::findByEmail. It tries, in order:
//
//  1. an exact or canonical match against a registered address — V1 matched
//     `email = ? OR canon = ?`, but the Go IPN handlers had dropped the canon
//     leg, so variant addresses (gmail dots/+tags, googlemail, TN variants)
//     stopped matching; and
//  2. a prior donation from the same Payer that is already linked to a
//     non-deleted user. This catches recurring donors whose Freegle email later
//     changed or was removed while PayPal keeps billing the original address.
//     The `users.deleted IS NULL` guard makes it merge-safe: a merged-away
//     account is marked deleted (and its donations reassigned to the survivor),
//     so we never re-link to a dead account — we just fall through to unmatched.
//
// Returns 0 when there is no confident match.
func MatchUserByEmailOrPriorDonation(email string) uint64 {
	email = strings.TrimSpace(email)
	if email == "" {
		return 0
	}

	gdb := database.DBConn
	var userID uint64

	// 1. Registered address, exact or canonical (reuses the shared util so the
	//    canon form matches how addresses are stored).
	canon := user.CanonicalizeEmail(email)
	// ORM migration site 5ae46192a944 (wave 1).
	gdb.Table("users_emails").Select("userid").
		Where("(email = ? OR canon = ?) AND userid IS NOT NULL", email, canon).
		Limit(1).
		Scan(&userID)
	if userID > 0 {
		return userID
	}

	// 2. Prior donation from the same Payer, linked to a still-valid user.
	gdb.Raw("SELECT ud.userid FROM users_donations ud "+
		"JOIN users u ON u.id = ud.userid "+
		"WHERE ud.Payer = ? AND ud.userid IS NOT NULL AND u.deleted IS NULL "+
		"ORDER BY ud.timestamp DESC LIMIT 1", email).Scan(&userID)
	return userID
}

// GetDonations returns donation target and amount raised for the current month
// @Summary Get donations summary
// @Description Returns the donation target and amount raised for the current month, optionally filtered by group
// @Tags donations
// @Accept json
// @Produce json
// @Param groupid query int false "Group ID to filter donations"
// @Success 200 {object} map[string]interface{} "Donation summary with target and raised amounts"
// @Router /donations [get]
func GetDonations(c *fiber.Ctx) error {
	db := database.DBConn

	// Get optional groupid parameter
	groupID := c.Query("groupid")

	var target int
	var raised float64

	// Get target - from group if specified, otherwise use default from env
	target = getDonationTarget()
	if groupID != "" {
		var fundingtarget *int
		// ORM migration site e05d3c7dc0c1 (wave 1).
		db.Table("groups").Select("fundingtarget").Where("id = ?", groupID).Scan(&fundingtarget)
		if fundingtarget != nil && *fundingtarget > 0 {
			target = *fundingtarget
		}
	}

	// Get raised amount for current month
	// If groupid specified, only count donations from members of that group
	// Exclude certain payers (eBay partnerships, PayPal Giving Fund) from totals
	excludedPayers := getExcludedPayers()

	query := `
		SELECT COALESCE(SUM(GrossAmount), 0) AS raised
		FROM users_donations
	`

	if groupID != "" {
		query += ` INNER JOIN memberships ON users_donations.userid = memberships.userid
		           AND memberships.groupid = ?
		`
	}

	query += ` WHERE timestamp >= DATE_FORMAT(NOW(), '%Y-%m-01')`

	// Build exclusion condition dynamically
	for range excludedPayers {
		query += ` AND Payer != ?`
	}

	// Build query arguments
	var args []interface{}
	if groupID != "" {
		args = append(args, groupID)
	}
	for _, email := range excludedPayers {
		args = append(args, email)
	}

	db.Raw(query, args...).Scan(&raised)

	return c.JSON(fiber.Map{
		"target": target,
		"raised": raised,
	})
}

// AddDonation records an external bank transfer donation.
// @Summary Record an external donation
// @Description Records a donation made via bank transfer. Requires GiftAid permission for non-zero amounts.
// @Tags donations
// @Accept json
// @Produce json
// @Param body body AddDonationRequest true "Donation details"
// @Success 200 {object} map[string]interface{} "Donation recorded with id"
// @Failure 401 {object} map[string]string "Not logged in"
// @Failure 403 {object} map[string]string "Permission denied"
// @Router /donations [put]
func AddDonation(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type AddDonationRequest struct {
		UserID uint64  `json:"userid"`
		Amount float64 `json:"amount"`
		Date   string  `json:"date"`
	}

	var req AddDonationRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.UserID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing userid")
	}

	db := database.DBConn

	// Permission check: need GiftAid permission for non-zero amounts.
	if req.Amount > 0 {
		var permissions *string
		// ORM migration site 86e67ec8afc4 (wave 1).
		db.Table("users").Select("permissions").Where("id = ?", myid).Scan(&permissions)

		hasGiftAid := false
		if permissions != nil {
			hasGiftAid = strings.Contains(strings.ToLower(*permissions), "giftaid")
		}

		if !hasGiftAid {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}
	}

	// Look up the target user's name and preferred email. A NULL `fullname` is
	// common for perfectly valid donors (they may have only firstname/lastname),
	// so it must NOT be treated as a missing user. Check existence via the id and
	// derive the display name with the standard fullname -> firstname+lastname
	// fallback. Previously a NULL fullname returned 400 "Invalid userid", which
	// blocked gift-aid uploads for such donors.
	var preferredEmail string

	var donor struct {
		ID   uint64
		Name string
	}
	// ORM migration site 8f09f277831b (wave 1).
	db.Table("users").
		Select("id, COALESCE(NULLIF(fullname, ''), NULLIF(TRIM(CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, ''))), ''), '') AS name").
		Where("id = ?", req.UserID).
		Scan(&donor)

	if donor.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid userid")
	}

	name := donor.Name

	// Get the donor's preferred email, mirroring V1 getEmailPreferred().
	//
	// First pass: prefer an external address, skipping our-domain aliases
	// (@users.ilovefreegle.org, @groups.ilovefreegle.org, @direct.ilovefreegle.org,
	// @republisher.freegle.in) and Yahoo Groups addresses. These are internal routing
	// addresses, not the donor's real contact email.
	//
	// Second pass: if no external address exists (e.g. social-login-only users whose
	// only email is the alias), fall back to any email including the alias.
	// ORM migration site 9330b7d3045a (wave 1).
	db.Table("users_emails").
		Select("email").
		Where("userid = ? AND email NOT LIKE ? AND email NOT LIKE ? AND email NOT LIKE ? AND email NOT LIKE ? AND email NOT LIKE '%@yahoogroups.%'",
			req.UserID,
			"%@"+utils.USER_DOMAIN,
			"%@groups.ilovefreegle.org",
			"%@direct.ilovefreegle.org",
			"%@republisher.freegle.in",
		).
		Order("preferred DESC, added DESC").
		Limit(1).
		Scan(&preferredEmail)

	if preferredEmail == "" {
		// Second pass: no external email found; accept any email including our-domain aliases.
		// ORM migration site 3b4f1c2cf9eb (wave 1).
		db.Table("users_emails").
			Select("email").
			Where("userid = ?", req.UserID).
			Order("preferred DESC, added DESC").
			Limit(1).
			Scan(&preferredEmail)
	}

	if preferredEmail == "" {
		preferredEmail = "unknown"
	}

	// Build a unique transaction ID for the external donation.
	transactionID := fmt.Sprintf("External for #%d added at %s%s",
		req.UserID, time.Now().UTC().Format("2006-01-02 15:04:05"), SOURCE_BANK_TRANSFER)

	// Insert donation with ON DUPLICATE KEY UPDATE (TransactionID is unique).
	result := db.Exec(`INSERT INTO users_donations
		(userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, type, source)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE userid = VALUES(userid), timestamp = VALUES(timestamp)`,
		req.UserID, preferredEmail, name, req.Date, transactionID, req.Amount, TYPE_EXTERNAL, SOURCE_BANK_TRANSFER)

	if result.Error != nil {
		log.Printf("Failed to add donation for user %d: %v", req.UserID, result.Error)
		return fiber.NewError(fiber.StatusInternalServerError, "Add failed")
	}

	// Get the inserted ID.
	var donationID uint64
	// ORM migration site e2d485938df9 (wave 1).
	db.Table("users_donations").Select("id").Where("TransactionID = ?", transactionID).Scan(&donationID)

	if donationID == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Add failed")
	}

	// For non-zero amounts: create a Gift Aid prompt. Thanking is handled by
	// the daily mail:donations:thank-prep digest, not a per-donation email.
	if req.Amount > 0 {
		var giftAidPeriod *string
		// ORM migration site 21e8dbdd136c (wave 1).
		db.Table("giftaid").Select("period").Where("userid = ? AND deleted IS NULL", req.UserID).Limit(1).Scan(&giftAidPeriod)

		if giftAidPeriod == nil || *giftAidPeriod == PERIOD_THIS {
			// Create a GiftAid notification for the user.
			// ORM migration site dcb461443c86 (wave 2).
			db.Table("users_notifications").Create(map[string]interface{}{
				"touser":    req.UserID,
				"type":      gorm.Expr("'GiftAid'"),
				"timestamp": gorm.Expr("NOW()"),
				"seen":      gorm.Expr("0"),
			})
		}
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     donationID,
	})
}

// BulkDonation represents a single donation in a bulk upload (e.g. from PayPal Giving Fund CSV).
type BulkDonation struct {
	Date          string  `json:"date"`
	DonorName     string  `json:"donor_name"`
	Email         string  `json:"email"`
	Program       string  `json:"program"`
	Amount        float64 `json:"amount"`
	TransactionID string  `json:"transaction_id"`
}

// BulkUploadDonations records multiple donations from an external source like PayPal Giving Fund.
// Matches the logic in the legacy V1 PHP paypal_giving_fund script.
// @Summary Bulk upload donations
// @Description Records multiple donations from PayPal Giving Fund or similar sources. Admin only.
// @Tags donations
// @Accept json
// @Produce json
// @Router /donations/bulk [post]
func BulkUploadDonations(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Admin or support required")
	}

	type BulkRequest struct {
		Donations []BulkDonation `json:"donations"`
	}

	var req BulkRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if len(req.Donations) == 0 {
		return c.JSON(fiber.Map{
			"ret":      0,
			"status":   "Success",
			"inserted": 0,
			"updated":  0,
			"skipped":  0,
		})
	}

	db := database.DBConn

	inserted := 0
	updated := 0
	skipped := 0

	for _, d := range req.Donations {
		if d.Amount <= 0 || d.Amount >= 10000 {
			// Skip debits and unconvincingly large amounts (matches PHP script logic).
			skipped++
			continue
		}

		if d.TransactionID == "" {
			skipped++
			continue
		}

		// Map program to source, matching the PHP script.
		source := "PayPalGivingFund"
		switch d.Program {
		case "eBay for Charity Seller Donations":
			source = "eBay"
		case "Facebook donations with PPGF":
			source = "Facebook"
		}

		// Try to match donor email to an existing user.
		var userID *uint64
		if d.Email != "" {
			var uid uint64
			// ORM migration site 16c4a7f6e566 (wave 1).
			db.Table("users_emails").Select("userid").Where("email = ? AND userid IS NOT NULL", d.Email).Limit(1).Scan(&uid)
			if uid > 0 {
				userID = &uid
			}
		}

		// PayPal Giving Fund donations: type=PayPal, source from program mapping.
		// Gift Aid is already claimed by PayPal — giftaidconsent defaults to 0
		// which means GiftAidClaimService will never try to reclaim it.
		result := db.Exec(`INSERT INTO users_donations
			(userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, type, source)
			VALUES (?, ?, ?, ?, ?, ?, 'PayPal', ?)
			ON DUPLICATE KEY UPDATE
				userid = VALUES(userid),
				timestamp = VALUES(timestamp),
				source = VALUES(source),
				GrossAmount = VALUES(GrossAmount)`,
			userID, d.Email, d.DonorName, d.Date, d.TransactionID, d.Amount, source)

		if result.Error != nil {
			log.Printf("Bulk donation insert failed for txid %s: %v", d.TransactionID, result.Error)
			skipped++
			continue
		}

		// MySQL ON DUPLICATE KEY UPDATE returns: 1=inserted, 2=updated, 0=no-op (same data).
		if result.RowsAffected == 1 {
			inserted++
		} else if result.RowsAffected == 2 {
			updated++
		} else {
			skipped++
		}
	}

	return c.JSON(fiber.Map{
		"ret":      0,
		"status":   "Success",
		"inserted": inserted,
		"updated":  updated,
		"skipped":  skipped,
	})
}
