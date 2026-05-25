// Package amp provides AMP email endpoints for dynamic email content.
//
// This package implements the AMP for Email specification, allowing
// dynamic content in emails (e.g., chat messages, job listings) and
// inline actions (e.g., replying to messages).
//
// Security Model:
// - Single HMAC-SHA256 token for both read and write operations
// - Token is reusable within expiry period (default 7 days)
// - User must also be a chat member (verified server-side)
package amp

import (
	"crypto/hmac"
	"crypto/md5"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"log"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// AMPChatMessage extends ChatMessageQuery with AMP-specific display fields.
// These fields are populated by enriching the base chat messages with user info.
type AMPChatMessage struct {
	chat.ChatMessageQuery
	FromUser  string `json:"fromUser"`
	FromImage string `json:"fromImage"`
	IsNew     bool   `json:"isNew"`
}

// AMPChatResponse wraps the enriched chat messages with AMP-specific metadata.
type AMPChatResponse struct {
	Items    []AMPChatMessage `json:"items"`
	ChatID   uint64           `json:"chatId"`
	SinceID  uint64           `json:"sinceId,omitempty"`
	CanReply bool             `json:"canReply"`
}

// ReplyResponse is the response for AMP chat reply submissions.
type ReplyResponse struct {
	Success bool   `json:"success"`
	Message string `json:"message"`
}

// getAMPSecret returns the AMP secret from environment.
func getAMPSecret() string {
	secret := os.Getenv("AMP_SECRET")
	if secret == "" {
		// Fallback for development - should be set in production
		secret = os.Getenv("FREEGLE_AMP_SECRET")
	}
	if secret == "" {
		log.Printf("[AMP] WARNING: No AMP secret configured (checked AMP_SECRET and FREEGLE_AMP_SECRET)")
	}
	return secret
}

// recordAmpReplyTracking marks an email_tracking row as replied via AMP and
// inserts a click record. No-op when the tracking ID is absent (older
// emails sent before email_tracking was wired up). Kept as a small helper
// so PostChatReply and PostDigestReply share the same write pattern
// instead of duplicating two raw statements.
func recordAmpReplyTracking(db *gorm.DB, emailTrackingID *uint64, linkURL, linkPosition string) {
	if emailTrackingID == nil {
		return
	}
	db.Exec(
		"UPDATE email_tracking SET replied_at = NOW(), replied_via = 'amp' WHERE id = ?",
		*emailTrackingID,
	)
	db.Exec(
		"INSERT INTO email_tracking_clicks (email_tracking_id, link_url, link_position, action, clicked_at) "+
			"VALUES (?, ?, ?, 'amp_reply', NOW())",
		*emailTrackingID, linkURL, linkPosition,
	)
}

// computeHMAC generates an HMAC-SHA256 signature.
func computeHMAC(message, secret string) string {
	h := hmac.New(sha256.New, []byte(secret))
	h.Write([]byte(message))
	return hex.EncodeToString(h.Sum(nil))
}

// ValidateToken validates HMAC-based tokens for AMP operations (read and write).
// Tokens are reusable within their expiry period.
// Returns (userID, resourceID, error). On failure, returns (0, 0, nil) for graceful fallback.
func ValidateToken(c *fiber.Ctx) (uint64, uint64, error) {
	token := c.Query("rt")  // Token
	uid := c.Query("uid")   // User ID
	exp := c.Query("exp")   // Expiry timestamp
	resID := c.Params("id") // Resource ID (chat ID, etc.)

	// Check all required params present
	if token == "" || uid == "" || exp == "" || resID == "" {
		return 0, 0, nil
	}

	// Check expiry
	expTime, err := strconv.ParseInt(exp, 10, 64)
	if err != nil || time.Now().Unix() > expTime {
		return 0, 0, nil
	}

	// Validate HMAC: "amp" + user_id + resource_id + expiry
	secret := getAMPSecret()
	if secret == "" {
		return 0, 0, nil
	}

	message := "amp" + uid + resID + exp
	expectedMAC := computeHMAC(message, secret)

	// Constant-time comparison to prevent timing attacks
	if subtle.ConstantTimeCompare([]byte(token), []byte(expectedMAC)) != 1 {
		return 0, 0, nil
	}

	userID, _ := strconv.ParseUint(uid, 10, 64)
	resourceID, _ := strconv.ParseUint(resID, 10, 64)

	// Verify user still exists
	db := database.DBConn
	var exists bool
	db.Raw("SELECT EXISTS(SELECT 1 FROM users WHERE id = ?)", userID).Scan(&exists)
	if !exists {
		return 0, 0, nil
	}

	return userID, resourceID, nil
}

// AMPCORSMiddleware handles both AMP CORS spec versions (v1 Origin + v2 AMP-Email-Sender).
// See: https://amp.dev/documentation/guides-and-tutorials/learn/cors-in-email
func AMPCORSMiddleware() fiber.Handler {
	return func(c *fiber.Ctx) error {
		// Get AMP sender header (v2 spec)
		ampSender := c.Get("AMP-Email-Sender")

		// Get Origin header (v1 spec)
		origin := c.Get("Origin")
		sourceOrigin := c.Query("__amp_source_origin")

		if ampSender != "" {
			// Version 2: Just validate and echo back
			if !isAllowedSender(ampSender) {
				return fiber.NewError(fiber.StatusForbidden, "Sender not allowed")
			}
			c.Set("AMP-Email-Allow-Sender", ampSender)
			c.Set("Access-Control-Expose-Headers", "AMP-Email-Allow-Sender")
		} else if origin != "" && sourceOrigin != "" {
			// Version 1: Full CORS headers
			if !isAllowedSender(sourceOrigin) {
				return fiber.NewError(fiber.StatusForbidden, "Sender not allowed")
			}
			c.Set("Access-Control-Allow-Origin", origin)
			c.Set("Access-Control-Expose-Headers", "AMP-Access-Control-Allow-Source-Origin")
			c.Set("AMP-Access-Control-Allow-Source-Origin", sourceOrigin)
		}

		// Handle preflight OPTIONS request
		if c.Method() == "OPTIONS" {
			c.Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
			c.Set("Access-Control-Allow-Headers", "Content-Type, AMP-Email-Sender")
			c.Set("Access-Control-Max-Age", "86400")
			return c.SendStatus(fiber.StatusNoContent)
		}

		return c.Next()
	}
}

// isAllowedSender checks if the email sender is from an allowed domain.
func isAllowedSender(email string) bool {
	allowedDomains := []string{
		"@ilovefreegle.org",
		"@users.ilovefreegle.org",
		"@mail.ilovefreegle.org",
		"@gmail.dev", // Google AMP Playground for testing
	}
	for _, domain := range allowedDomains {
		if strings.HasSuffix(strings.ToLower(email), domain) {
			return true
		}
	}
	return false
}

// userInfo holds display information for a user.
type userInfo struct {
	ID       uint64
	Name     string
	ImageURL string
}

// getUserInfo fetches display name and profile image for a user.
// This matches the logic used in chat/chatroom.go for consistency.
func getUserInfo(userID uint64) userInfo {
	db := database.DBConn
	var result struct {
		ID        uint64
		Fullname  *string
		Firstname *string
		Lastname  *string
		ImageID   *uint64
		ImageURL  *string
		Email     *string
	}

	// Query user info with image and preferred email for Gravatar fallback.
	// Email is in users_emails table, not users table.
	db.Raw(`
		SELECT u.id, u.fullname, u.firstname, u.lastname,
		       ui.id AS imageid, ui.url AS imageurl,
		       ue.email AS email
		FROM users u
		LEFT JOIN users_images ui ON ui.userid = u.id
		LEFT JOIN users_emails ue ON ue.userid = u.id
		WHERE u.id = ?
		ORDER BY ui.default DESC, ui.id ASC, ue.preferred DESC
		LIMIT 1
	`, userID).Scan(&result)

	// Build display name - same logic as chat/chatroom.go
	var name string
	if result.Fullname != nil && *result.Fullname != "" {
		name = *result.Fullname
	} else {
		firstName := ""
		lastName := ""
		if result.Firstname != nil {
			firstName = *result.Firstname
		}
		if result.Lastname != nil {
			lastName = *result.Lastname
		}
		name = strings.TrimSpace(firstName + " " + lastName)
	}
	if name == "" {
		name = "Freegler"
	}

	// Build profile image URL - match Laravel AmpEmail trait logic
	imageDomain := os.Getenv("IMAGE_DOMAIN")
	if imageDomain == "" {
		imageDomain = "images.ilovefreegle.org"
	}
	deliveryDomain := os.Getenv("DELIVERY_DOMAIN")
	if deliveryDomain == "" {
		deliveryDomain = "delivery.ilovefreegle.org"
	}

	var imageURL string
	if result.ImageURL != nil && *result.ImageURL != "" {
		// External URL exists, use it directly
		imageURL = *result.ImageURL
	} else if result.ImageID != nil {
		// Build thumbnail URL from image ID
		imageURL = "https://" + imageDomain + "/tuimg_" + strconv.FormatUint(*result.ImageID, 10) + ".jpg"
	} else if result.Email != nil && *result.Email != "" {
		// No custom image - use Gravatar based on email MD5
		emailHash := md5.Sum([]byte(strings.ToLower(strings.TrimSpace(*result.Email))))
		gravatarURL := "https://www.gravatar.com/avatar/" + hex.EncodeToString(emailHash[:]) + "?s=200&d=identicon&r=g"
		// Route through delivery service for resizing
		imageURL = "https://" + deliveryDomain + "/?url=" + url.QueryEscape(gravatarURL) + "&w=40"
	} else {
		// Fallback to default profile
		imageURL = "https://" + imageDomain + "/defaultprofile.png"
	}

	return userInfo{
		ID:       userID,
		Name:     name,
		ImageURL: imageURL,
	}
}

// GetChatMessages returns the last 5 messages for AMP email "Earlier conversation" section.
// Uses the shared FetchChatMessages function to return messages in the same format as the regular chat API,
// then enriches them with user display information for the AMP template.
// Excludes the triggering message (passed via 'exclude' param) to avoid duplication.
// @Summary Get chat messages for AMP email
// @Tags AMP
// @Produce json
// @Param id path int true "Chat ID"
// @Param rt query string true "Read token (HMAC)"
// @Param uid query int true "User ID"
// @Param exp query int true "Token expiry timestamp"
// @Param exclude query int false "Message ID to exclude (the one shown statically)"
// @Param since query int false "Message ID - messages newer than this are considered NEW"
// @Param tid query int false "Email tracking ID - records that AMP was rendered"
// @Success 200 {object} AMPChatResponse
// @Router /amp/chat/{id} [get]
func GetChatMessages(c *fiber.Ctx) error {
	userID, chatID, err := ValidateToken(c)
	if err != nil || userID == 0 {
		// Return graceful fallback response - empty items triggers fallback UI
		return c.JSON(AMPChatResponse{
			Items:    []AMPChatMessage{},
			CanReply: false,
		})
	}

	// Message ID to exclude (the one shown statically in the email)
	excludeID, _ := strconv.ParseUint(c.Query("exclude", "0"), 10, 64)
	// Messages newer than this ID can be marked as "NEW" by the client
	sinceID, _ := strconv.ParseUint(c.Query("since", "0"), 10, 64)

	db := database.DBConn

	// Track AMP render if we have an email tracking ID
	// This call to the AMP API proves the email was rendered with AMP support
	if tidStr := c.Query("tid"); tidStr != "" {
		if tid, err := strconv.ParseUint(tidStr, 10, 64); err == nil {
			// Record AMP render - always upgrade to 'amp' since it's a stronger
			// signal than pixel tracking (proves AMP content was rendered)
			db.Exec(`
				UPDATE email_tracking
				SET opened_at = COALESCE(opened_at, NOW()),
				    opened_via = 'amp'
				WHERE id = ?
			`, tid)
			// Also record in clicks table for analytics (won't duplicate due to unique tracking)
			db.Exec(`
				INSERT IGNORE INTO email_tracking_clicks (email_tracking_id, link_url, action, clicked_at)
				VALUES (?, 'amp://render', 'amp_render', NOW())
			`, tid)
		}
	}

	// Verify user is member of this chat
	var memberUserID uint64
	db.Raw(`SELECT userid FROM chat_roster WHERE chatid = ? AND userid = ?`, chatID, userID).Scan(&memberUserID)

	if memberUserID == 0 {
		return c.JSON(AMPChatResponse{Items: []AMPChatMessage{}, CanReply: false})
	}

	// Use the shared function to fetch messages
	// Limit to 5, exclude the triggering message, newest first (for "earlier conversation")
	messages := chat.FetchChatMessages(chatID, userID, 5, excludeID, true, false)

	// Reverse to show oldest first (chronological order for display)
	for i, j := 0, len(messages)-1; i < j; i, j = i+1, j-1 {
		messages[i], messages[j] = messages[j], messages[i]
	}

	// Cache user info to avoid repeated lookups
	userCache := make(map[uint64]userInfo)

	// Enrich messages with user display info
	ampMessages := make([]AMPChatMessage, len(messages))
	for i, msg := range messages {
		// Get user info (from cache or fetch)
		info, ok := userCache[msg.Userid]
		if !ok {
			info = getUserInfo(msg.Userid)
			userCache[msg.Userid] = info
		}

		ampMessages[i] = AMPChatMessage{
			ChatMessageQuery: msg,
			FromUser:         info.Name,
			FromImage:        info.ImageURL,
			IsNew:            sinceID > 0 && msg.ID > sinceID,
		}
	}

	return c.JSON(AMPChatResponse{
		Items:    ampMessages,
		ChatID:   chatID,
		SinceID:  sinceID,
		CanReply: true,
	})
}

// PostChatReply handles inline replies from AMP email.
// @Summary Post reply from AMP email
// @Tags AMP
// @Accept json
// @Produce json
// @Param id path int true "Chat ID"
// @Param rt query string true "Token (HMAC)"
// @Param uid query int true "User ID"
// @Param exp query int true "Token expiry timestamp"
// @Param tid query int false "Email tracking ID for analytics"
// @Param body body object true "Message body with 'message' field"
// @Success 200 {object} ReplyResponse
// @Failure 400 {object} ReplyResponse
// @Router /amp/chat/{id}/reply [post]
func PostChatReply(c *fiber.Ctx) error {
	userID, chatID, err := ValidateToken(c)
	if err != nil || userID == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "Invalid token",
		})
	}

	// Get optional tracking ID from query param
	var emailTrackingID *uint64
	if tidStr := c.Query("tid"); tidStr != "" {
		if tid, err := strconv.ParseUint(tidStr, 10, 64); err == nil {
			emailTrackingID = &tid
		}
	}

	var body struct {
		Message string `json:"message"`
	}
	if err := c.BodyParser(&body); err != nil || strings.TrimSpace(body.Message) == "" {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "Please enter a message.",
		})
	}

	// Trim and validate message length
	message := strings.TrimSpace(body.Message)
	if len(message) > 10000 {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "Message is too long. Please keep it under 10,000 characters.",
		})
	}

	db := database.DBConn

	// Verify user is still member of chat
	var memberUserID uint64
	db.Raw(`
		SELECT userid FROM chat_roster
		WHERE chatid = ? AND userid = ?
	`, chatID, userID).Scan(&memberUserID)

	if memberUserID == 0 {
		return c.Status(fiber.StatusForbidden).JSON(ReplyResponse{
			Success: false,
			Message: "You are not a member of this conversation.",
		})
	}

	// Insert the message.
	// Use the underlying sql.DB to get LastInsertId() directly from the MySQL protocol
	// response — never issue a separate SELECT LAST_INSERT_ID() as it's unsafe under
	// parallel load (GORM's connection pool may assign a different connection).
	sqlDB, err := db.DB()
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(ReplyResponse{
			Success: false,
			Message: "Failed to send message. Please try on Freegle.",
		})
	}
	sqlResult, err := sqlDB.Exec(`
		INSERT INTO chat_messages (chatid, userid, message, type, date, processingsuccessful)
		VALUES (?, ?, ?, ?, NOW(), 1)
	`, chatID, userID, message, utils.CHAT_MESSAGE_DEFAULT)

	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(ReplyResponse{
			Success: false,
			Message: "Failed to send message. Please try on Freegle.",
		})
	}

	var messageID uint64
	lastID, err := sqlResult.LastInsertId()
	if err == nil && lastID > 0 {
		messageID = uint64(lastID)
	}

	// Update chat room latest message time
	db.Exec(`UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?`, chatID)

	recordAmpReplyTracking(db, emailTrackingID, "amp://reply", "amp_reply_form")

	// Log to Loki for dashboard analytics
	misc.GetLoki().LogChatReply("amp", chatID, userID, &messageID, emailTrackingID)

	return c.JSON(ReplyResponse{
		Success: true,
		Message: "Message sent!",
	})
}

// PostDigestReply handles AMP form submissions from immediate-digest emails.
// The resource ID is a message ID (the post the user is replying to). We
// validate the HMAC token against that message ID, look up the post's owner,
// find-or-create a User2User chat between the replier and the post owner,
// and insert the reply as a chat message — mirroring what the website "Reply"
// button does for the same post.
//
// @Summary Post reply from AMP digest email
// @Description Submits an inline reply to a digest-email post (one-time token)
// @Tags AMP
// @Accept json
// @Produce json
// @Param id path int true "Message ID (the post being replied to)"
// @Param rt query string true "Token (HMAC)"
// @Param uid query int true "User ID"
// @Param exp query int true "Token expiry timestamp"
// @Param tid query int false "Email tracking ID for analytics"
// @Param body body object true "Message body with 'message' field"
// @Success 200 {object} ReplyResponse
// @Failure 400 {object} ReplyResponse
// @Router /amp/digest/{id}/reply [post]
func PostDigestReply(c *fiber.Ctx) error {
	userID, messageID, err := ValidateToken(c)
	if err != nil || userID == 0 || messageID == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "Invalid token",
		})
	}

	var emailTrackingID *uint64
	if tidStr := c.Query("tid"); tidStr != "" {
		if tid, err := strconv.ParseUint(tidStr, 10, 64); err == nil {
			emailTrackingID = &tid
		}
	}

	var body struct {
		Message string `json:"message"`
	}
	if err := c.BodyParser(&body); err != nil || strings.TrimSpace(body.Message) == "" {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "Please enter a message.",
		})
	}

	replyText := strings.TrimSpace(body.Message)
	if len(replyText) > 10000 {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "Message is too long. Please keep it under 10,000 characters.",
		})
	}

	db := database.DBConn

	// Look up the post owner using the Message model so we benefit from
	// any hooks/scopes the model defines, and reject deleted/orphan posts.
	var post message.Message
	if err := db.Select("id", "fromuser").
		Where("id = ? AND deleted IS NULL", messageID).
		First(&post).Error; err != nil || post.Fromuser == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "This post is no longer available.",
		})
	}
	fromUser := post.Fromuser

	if fromUser == userID {
		return c.Status(fiber.StatusBadRequest).JSON(ReplyResponse{
			Success: false,
			Message: "You can't reply to your own post.",
		})
	}

	// Find-or-create the User2User chat via the shared helper so this
	// handler doesn't duplicate the (user1,user2) ordering logic.
	chatID, err := chat.GetOrCreateUser2UserChat(db, userID, fromUser)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(ReplyResponse{
			Success: false,
			Message: "Failed to send reply. Please try on Freegle.",
		})
	}

	// Insert the chat message via GORM so the create goes through the
	// model the rest of the API uses. Type=Interested + refmsgid links
	// the reply back to the originating post, matching what the website
	// "Reply" button on a post does (see chat.CreateChatMessage).
	refmsgid := messageID
	chatMsg := chat.ChatMessage{
		Chatid:             chatID,
		Userid:             userID,
		Type:               utils.CHAT_MESSAGE_INTERESTED,
		Refmsgid:           &refmsgid,
		Message:            replyText,
		Date:               time.Now(),
		Processingrequired: true,
	}
	if err := db.Create(&chatMsg).Error; err != nil || chatMsg.ID == 0 {
		return c.Status(fiber.StatusInternalServerError).JSON(ReplyResponse{
			Success: false,
			Message: "Failed to send reply. Please try on Freegle.",
		})
	}
	chatMessageID := chatMsg.ID

	// ChatRoom struct doesn't model the latestmessage column (it's not
	// returned in normal reads), so touch it with a small raw UPDATE.
	db.Exec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", chatID)

	recordAmpReplyTracking(db, emailTrackingID, "amp://digest-reply", "amp_digest_reply_form")

	misc.GetLoki().LogChatReply("amp_digest", chatID, userID, &chatMessageID, emailTrackingID)

	return c.JSON(ReplyResponse{
		Success: true,
		Message: "Reply sent! The poster will get your message.",
	})
}
