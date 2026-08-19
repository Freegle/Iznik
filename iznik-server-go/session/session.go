package session

import (
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"io"
	stdlog "log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/housekeeper"
	log2 "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/maildeferral"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// FetchEmailHealth returns the incoming and outgoing email alert flags
// used by the moderator/admin work badge. Only flagged during daytime
// hours (07:00-22:00 UTC) — outside that window both values are zero.
// Extracted so it can be unit-tested with an explicit hour, making Go
// coverage for this block deterministic regardless of CI wall-clock time.
func FetchEmailHealth(db *gorm.DB, hour int) (emailin, emailout int64) {
	if hour < 7 || hour >= 22 {
		return 0, 0
	}

	var wg sync.WaitGroup
	wg.Add(2)

	go func() {
		defer wg.Done()
		// Incoming: alert if zero platform=0 chat messages in last 2 hours.
		var inCount int64
		db.Table("chat_messages").
			Where("platform = 0 AND date >= DATE_SUB(NOW(), INTERVAL 2 HOUR)").
			Count(&inCount)
		if inCount == 0 {
			emailin = 1
		}
	}()

	go func() {
		defer wg.Done()
		// Outgoing: alert if fewer than 10 emails sent in last hour.
		var outCount int64
		db.Table("email_tracking").
			Where("sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)").
			Count(&outCount)
		if outCount < 10 {
			emailout = 1
		}
	}()

	wg.Wait()
	return emailin, emailout
}

// discourseTopicWindowDays is the look-back window applied when counting new/unread
// Discourse topics for the modtools badge. Without this filter a newly-promoted
// moderator sees every historical topic they have never read as "new", producing
// badge values in the hundreds (e.g. 466+793 as reported in Discourse topic 9654
// post 10). Thirty days is long enough to catch genuinely active threads while
// suppressing the one-time flood for new mods.
const discourseTopicWindowDays = 30

// TopicActiveWithin reports whether a Discourse topic has had activity within the
// given window. Exported so it can be unit-tested without an HTTP server.
//
// The three string parameters map to Discourse's topic_list JSON fields:
//   - createdAt  → created_at
//   - bumpedAt   → bumped_at   (updated on every reply — preferred signal)
//   - lastPosted → last_posted_at
//
// The most-recent-activity timestamp (bumpedAt → lastPosted → createdAt) is
// tried in order; the topic counts if that timestamp parses and falls after
// `since`. A missing or malformed timestamp is treated as inactive (false).
func TopicActiveWithin(createdAt, bumpedAt, lastPosted string, since time.Time) bool {
	formats := []string{
		time.RFC3339,
		"2006-01-02T15:04:05.000Z",
		"2006-01-02T15:04:05Z",
	}
	parse := func(s string) (time.Time, bool) {
		if s == "" {
			return time.Time{}, false
		}
		for _, f := range formats {
			if t, err := time.Parse(f, s); err == nil {
				return t, true
			}
		}
		return time.Time{}, false
	}
	for _, candidate := range []string{bumpedAt, lastPosted, createdAt} {
		if t, ok := parse(candidate); ok {
			return t.After(since)
		}
	}
	return false
}

// fetchDiscourseStats fetches notification and topic counts from the Discourse API.
// Returns nil if Discourse is not configured or the API call fails.
func fetchDiscourseStats(myid uint64) fiber.Map {
	discourseAPI := os.Getenv("DISCOURSE_API")
	discourseKey := os.Getenv("DISCOURSE_APIKEY")
	if discourseAPI == "" || discourseKey == "" {
		return nil
	}

	client := &http.Client{Timeout: 2 * time.Second}

	// Look up the user's Discourse username by external ID.
	req, err := http.NewRequest("GET", discourseAPI+"/users/by-external/"+strconv.FormatUint(myid, 10)+".json", nil)
	if err != nil {
		return nil
	}
	req.Header.Set("Api-Key", discourseKey)
	req.Header.Set("Api-Username", "system")

	resp, err := client.Do(req)
	if err != nil || resp.StatusCode != 200 {
		if resp != nil {
			resp.Body.Close()
		}
		return nil
	}

	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()

	var userResp struct {
		User struct {
			Username string `json:"username"`
		} `json:"user"`
	}
	if err := json.Unmarshal(body, &userResp); err != nil || userResp.User.Username == "" {
		return nil
	}

	username := userResp.User.Username

	// Fetch counts in parallel.
	var notifications, newtopics, unreadtopics int64
	var wg sync.WaitGroup
	wg.Add(3)

	go func() {
		defer wg.Done()
		req, err := http.NewRequest("GET", discourseAPI+"/session/current.json", nil)
		if err != nil {
			return
		}
		req.Header.Set("Api-Key", discourseKey)
		req.Header.Set("Api-Username", username)
		resp, err := client.Do(req)
		if err != nil || resp.StatusCode != 200 {
			if resp != nil {
				resp.Body.Close()
			}
			return
		}
		body, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		var sr struct {
			CurrentUser struct {
				UnreadNotifications int64 `json:"unread_notifications"`
			} `json:"current_user"`
		}
		_ = json.Unmarshal(body, &sr)
		notifications = sr.CurrentUser.UnreadNotifications
	}()

	since := time.Now().AddDate(0, 0, -discourseTopicWindowDays)

	type topicEntry struct {
		CreatedAt  string `json:"created_at"`
		BumpedAt   string `json:"bumped_at"`
		LastPosted string `json:"last_posted_at"`
	}
	type topicListResp struct {
		TopicList struct {
			Topics []topicEntry `json:"topics"`
		} `json:"topic_list"`
	}

	go func() {
		defer wg.Done()
		req, err := http.NewRequest("GET", discourseAPI+"/new.json", nil)
		if err != nil {
			return
		}
		req.Header.Set("Api-Key", discourseKey)
		req.Header.Set("Api-Username", username)
		resp, err := client.Do(req)
		if err != nil || resp.StatusCode != 200 {
			if resp != nil {
				resp.Body.Close()
			}
			return
		}
		body, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		var tr topicListResp
		_ = json.Unmarshal(body, &tr)
		for _, t := range tr.TopicList.Topics {
			if TopicActiveWithin(t.CreatedAt, t.BumpedAt, t.LastPosted, since) {
				newtopics++
			}
		}
	}()

	go func() {
		defer wg.Done()
		req, err := http.NewRequest("GET", discourseAPI+"/unread.json", nil)
		if err != nil {
			return
		}
		req.Header.Set("Api-Key", discourseKey)
		req.Header.Set("Api-Username", username)
		resp, err := client.Do(req)
		if err != nil || resp.StatusCode != 200 {
			if resp != nil {
				resp.Body.Close()
			}
			return
		}
		body, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		var tr topicListResp
		_ = json.Unmarshal(body, &tr)
		for _, t := range tr.TopicList.Topics {
			if TopicActiveWithin(t.CreatedAt, t.BumpedAt, t.LastPosted, since) {
				unreadtopics++
			}
		}
	}()

	wg.Wait()

	return fiber.Map{
		"notifications": notifications,
		"newtopics":     newtopics,
		"unreadtopics":  unreadtopics,
		"timestamp":     time.Now().Unix(),
	}
}

// FlexUint64 is an alias for utils.FlexUint64 for backward compatibility.
type FlexUint64 = utils.FlexUint64

// FlexBool accepts JSON booleans, integers (0/1), and strings ("true","false","0","1").
type FlexBool bool

func (f *FlexBool) UnmarshalJSON(data []byte) error {
	s := strings.Trim(string(data), "\"")
	switch strings.ToLower(s) {
	case "true", "1":
		*f = true
	default:
		*f = false
	}
	return nil
}

func (f FlexBool) Bool() bool {
	return bool(f)
}

// AppleCredentials holds the payload sent by the Capacitor Apple Sign In plugin.
type AppleCredentials struct {
	IdentityToken     string `json:"identityToken"`
	User              string `json:"user"`
	Email             string `json:"email"`
	GivenName         string `json:"givenName"`
	FamilyName        string `json:"familyName"`
	AuthorizationCode string `json:"authorizationCode"`
}

// PostSessionRequest covers all fields used across session POST actions.
type PostSessionRequest struct {
	Action           string           `json:"action"`
	Email            string           `json:"email"`
	Password         string           `json:"password"`
	U                FlexUint64       `json:"u"`
	K                string           `json:"k"`
	Userlist         []uint64         `json:"userlist"`
	Partner          string           `json:"partner"`
	ID               uint64           `json:"id"`
	GoogleLogin      bool             `json:"googlelogin"`
	GoogleJWT        string           `json:"googlejwt"`
	Mobile           bool             `json:"mobile"`
	FBLogin          FlexBool         `json:"fblogin"`
	FBAccessToken    string           `json:"fbaccesstoken"`
	FBLimited        FlexBool         `json:"fblimited"`
	AppleLogin       bool             `json:"applelogin"`
	AppleCredentials AppleCredentials `json:"applecredentials"`
}

// PostSession dispatches session write actions.
//
// @Summary Session actions (LostPassword, Unsubscribe, Login, Forget, Related)
// @Tags session
// @Router /session [post]
func PostSession(c *fiber.Ctx) error {
	var req PostSessionRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	switch req.Action {
	case "LostPassword":
		return handleLostPassword(c, req.Email)
	case "Unsubscribe":
		return handleUnsubscribe(c, req.Email)
	case "Forget":
		return handleForget(c, req.Partner, req.ID)
	case "Related":
		return handleRelated(c, req.Userlist)
	default:
		// No action means login attempt.
		if req.GoogleLogin && req.GoogleJWT != "" {
			return handleGoogleLogin(c, req.GoogleJWT)
		}
		if req.FBLogin.Bool() && req.FBAccessToken != "" {
			if req.FBLimited.Bool() {
				return handleFacebookLimitedLogin(c, req.FBAccessToken)
			}
			return handleFacebookLogin(c, req.FBAccessToken)
		}
		if req.AppleLogin && req.AppleCredentials.IdentityToken != "" {
			creds := req.AppleCredentials
			return handleAppleLogin(c, creds.IdentityToken, creds.User, creds.Email, creds.GivenName, creds.FamilyName)
		}
		if req.Email != "" && req.Password != "" {
			return handleEmailPasswordLogin(c, req.Email, req.Password)
		}
		if uint64(req.U) > 0 && req.K != "" {
			return handleLinkLogin(c, uint64(req.U), req.K)
		}

		// If we get here with a non-empty action we don't recognise, error.
		if req.Action != "" {
			return fiber.NewError(fiber.StatusBadRequest, "Unsupported action")
		}

		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
}

// handleLostPassword finds the user by email and queues a forgot-password email.
func handleLostPassword(c *fiber.Ctx, email string) error {
	if email == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Email parameter required")
	}

	db := database.DBConn

	// Find user by the email they actually typed. Deleted users can still use
	// forgot-password so they can recover their account. Capture the stored
	// (canonical) form of the matched address so we send the login link to the
	// exact email the user used - not their preferred address, which may differ
	// and may be the one that is bouncing.
	var match struct {
		ID    uint64 `gorm:"column:id"`
		Email string `gorm:"column:email"`
	}
	db.Table("users").
		Select("users.id, users_emails.email").
		Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
		Where("users_emails.email = ?", email).
		Limit(1).
		Scan(&match)
	userID := match.ID

	if userID == 0 {
		// Return ret=2 for unknown email.
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"ret":    2,
			"status": "We don't know that email address.",
		})
	}

	// Check whether the account has a native password. OAuth-only accounts (e.g.
	// Google/Facebook signups) have no Native row in users_logins; sending them a
	// password-reset link would be confusing and unhelpful — tell the frontend to
	// redirect to social sign-in instead.
	//
	// Note: users with no login rows at all (email-only, never set a password) are
	// allowed through — they can use the reset link to set their first password.
	var nativeCount int64
	db.Table("users_logins").Where("userid = ? AND type = ?", userID, utils.LOGIN_TYPE_NATIVE).Count(&nativeCount)
	if nativeCount == 0 {
		var socialCount int64
		db.Table("users_logins").Where("userid = ? AND type IN (?, ?)",
			userID, utils.LOGIN_TYPE_GOOGLE, utils.LOGIN_TYPE_FACEBOOK).Count(&socialCount)
		if socialCount > 0 {
			return c.JSON(fiber.Map{
				"ret":          1,
				"status":       "This account uses social sign-in. Please sign in with Google, Facebook, or Yahoo.",
				"socialSignin": true,
			})
		}
	}

	// Get or create the auto-login key for this user.
	key, err := getOrCreateLoginKey(userID)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to generate login key")
	}

	// Build the auto-login URL: /settings?u={id}&k={key}&src=forgotpass
	userSite := os.Getenv("USER_SITE")
	resetURL := fmt.Sprintf("https://%s/settings?u=%d&k=%s&src=forgotpass", userSite, userID, key)

	// Send the login link to the address the user actually used. Prefer the
	// canonical stored form of the matched address; fall back to the typed value.
	destEmail := match.Email
	if destEmail == "" {
		destEmail = email
	}

	// Queue the forgot-password email.
	if err := queue.QueueTask(queue.TaskEmailForgotPassword, map[string]interface{}{
		"user_id":   userID,
		"email":     destEmail,
		"reset_url": resetURL,
	}); err != nil {
		stdlog.Printf("Failed to queue forgot-password email for user %d: %v", userID, err)
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}

// handleUnsubscribe finds the user by email and queues an unsubscribe confirmation email.
func handleUnsubscribe(c *fiber.Ctx, email string) error {
	if email == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Email parameter required")
	}

	db := database.DBConn

	// Find user by email. Deleted users can still unsubscribe.
	var userID uint64
	db.Table("users").
		Select("users.id").
		Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
		Where("users_emails.email = ?", email).
		Limit(1).
		Scan(&userID)

	if userID == 0 {
		// Return unknown:true so the frontend can branch to a "Contact support" fallback
		// rather than misleading the user with a fake "we sent you an email" message.
		// Trade-off: this endpoint becomes a limited email-enumeration oracle.
		return c.JSON(fiber.Map{
			"ret":       0,
			"status":    "Success",
			"emailsent": false,
			"unknown":   true,
		})
	}

	// Get or create the auto-login key.
	key, err := getOrCreateLoginKey(userID)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to generate login key")
	}

	// Build the unsubscribe URL: /unsubscribe/{id}?u={id}&k={key}&confirm=1
	userSite := os.Getenv("USER_SITE")
	unsubURL := fmt.Sprintf("https://%s/unsubscribe/%d?u=%d&k=%s&confirm=1", userSite, userID, userID, key)

	// Get user's preferred email.
	var preferredEmail string
	db.Table("users_emails").Select("email").Where("userid = ?", userID).
		Order("preferred DESC, id ASC").Limit(1).Scan(&preferredEmail)

	if preferredEmail == "" {
		preferredEmail = email
	}

	// Queue the unsubscribe confirmation email.
	if err := queue.QueueTask(queue.TaskEmailUnsubscribe, map[string]interface{}{
		"user_id":   userID,
		"email":     preferredEmail,
		"unsub_url": unsubURL,
	}); err != nil {
		stdlog.Printf("Failed to queue unsubscribe email for user %d: %v", userID, err)
	}

	return c.JSON(fiber.Map{
		"ret":       0,
		"status":    "Success",
		"emailsent": true,
		"unknown":   false,
	})
}

// getOrCreateLoginKey retrieves or creates a 32-char hex auto-login key
// stored in users_logins with type='Link'.
func getOrCreateLoginKey(userID uint64) (string, error) {
	db := database.DBConn

	// Check for existing key.
	var existingKey string
	db.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?", userID, utils.LOGIN_TYPE_LINK).
		Limit(1).Scan(&existingKey)

	if existingKey != "" {
		return existingKey, nil
	}

	// Generate a new 32-char hex key (16 random bytes → 32 hex chars).
	newKey := utils.RandomHex(16)

	// Insert the login key. Use uid=userid as a unique identifier.
	// Golden column order (userid,
	// type, uid, credentials) is not alphabetical, but normaliseColumnOrder
	// sorted both sides' columns together with their values before comparing
	// (the retired ormharness's normalise_test.go
	// TestNormaliseColumnOrder_Insert, removed in d22ba1d6c), so the
	// map-Create reorder is harmless.
	res := db.Table("users_logins").Create(map[string]interface{}{
		"userid":      userID,
		"type":        utils.LOGIN_TYPE_LINK,
		"uid":         fmt.Sprintf("%d", userID),
		"credentials": newKey,
	})

	if res.Error != nil {
		// Most likely we lost a check-then-insert race on the (userid, type)
		// unique key against a concurrent request. Our key was never stored, so
		// returning it would hand out a dead link - return the winner's instead.
		db.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?", userID, utils.LOGIN_TYPE_LINK).
			Limit(1).Scan(&existingKey)

		if existingKey != "" {
			return existingKey, nil
		}

		return "", res.Error
	}

	return newKey, nil
}

// Delegated to auth package to break circular dependency with user package.

// handleEmailPasswordLogin authenticates via email and sha1-hashed password.
func handleEmailPasswordLogin(c *fiber.Ctx, email string, password string) error {
	db := database.DBConn

	// Find user by email. Deleted users can still log in so they see the
	// "restore your account" banner.
	var userID uint64
	db.Table("users u").
		Select("u.id").
		Joins("JOIN users_emails ue ON ue.userid = u.id").
		Where("ue.email = ?", email).
		Limit(1).
		Scan(&userID)

	if userID == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"ret":    2,
			"status": "We don't know that email address.",
		})
	}

	if !auth.VerifyPassword(userID, password) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{
			"ret":    3,
			"status": "The password is wrong.",
		})
	}

	persistent, jwtString, err := auth.CreateSessionAndJWT(userID)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create session")
	}

	return c.JSON(fiber.Map{
		"ret":        0,
		"status":     "Success",
		"persistent": persistent,
		"jwt":        jwtString,
	})
}

// handleLinkLogin authenticates via userid + link key.
func handleLinkLogin(c *fiber.Ctx, uid uint64, key string) error {
	db := database.DBConn

	// Verify the user exists. Deleted users can still log in so they see the
	// "restore your account" banner.
	var exists uint64
	db.Table("users").Select("id").Where("id = ?", uid).Limit(1).Scan(&exists)

	if exists == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{
			"ret":    2,
			"status": "Unknown user.",
		})
	}

	// Verify the link key.
	var storedKey string
	db.Table("users_logins").Select("credentials").Where("userid = ? AND type = ?", uid, utils.LOGIN_TYPE_LINK).
		Limit(1).Scan(&storedKey)

	if storedKey == "" || subtle.ConstantTimeCompare([]byte(storedKey), []byte(key)) != 1 {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{
			"ret":    3,
			"status": "Invalid key.",
		})
	}

	persistent, jwtString, err := auth.CreateSessionAndJWT(uid)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create session")
	}

	return c.JSON(fiber.Map{
		"ret":        0,
		"status":     "Success",
		"persistent": persistent,
		"jwt":        jwtString,
	})
}

// handleForget puts a user into "limbo" — soft-deleted but recoverable for ~14 days.
// Supports two flows: partner-authenticated (for integrated services) and self-service.
func handleForget(c *fiber.Ctx, partner string, targetID uint64) error {
	db := database.DBConn

	if partner != "" {
		// Partner flow: a partner service can delete users it manages.
		var partnerID uint64
		db.Table("partners_keys").Select("id").Where("`key` = ?", partner).Scan(&partnerID)

		if partnerID == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
		}

		if targetID == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "id is required for partner forget")
		}

		// Only allow for users linked via partner (ljuserid set).
		var ljuserid *uint64
		db.Table("users").Select("ljuserid").Where("id = ?", targetID).Scan(&ljuserid)

		if ljuserid == nil || *ljuserid == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "User is not partner-linked")
		}

		// V1 parity (User::delete): drop approved memberships so the user immediately
		// disappears from group member lists. Emit the per-group (Group, Left) audit
		// log first (byuser NULL — no acting Freegle user in the partner flow), since
		// the eager delete leaves nothing for the later cleanup cron to log.
		user.LogGroupLeftForApprovedMemberships(db, targetID, 0)
		// Converted together with its
		// identical twin in the self-service flow below (54406e904bd5).
		db.Table("memberships").Where("userid = ? AND collection = ?", targetID, utils.COLLECTION_APPROVED).Delete(nil)

		// Converted together with its
		// identical twin in the self-service flow below (da41536965a2).
		db.Table("users").Where("id = ?", targetID).Update("deleted", gorm.Expr("NOW()"))

		// V1 parity (User::delete with $log=TRUE): audit trail for the deletion.
		// byuser is NULL because there is no acting Freegle user in the partner flow.
		// Golden column order (timestamp,
		// type, subtype, user, byuser) is not alphabetical, but
		// normaliseColumnOrder sorted both sides' columns together with their
		// values before comparing
		// (the retired ormharness's normalise_test.go
		// TestNormaliseColumnOrder_Insert, removed in d22ba1d6c), so the
		// map-Create reorder is harmless.
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      log2.LOG_TYPE_USER,
			"subtype":   log2.LOG_SUBTYPE_DELETED,
			"user":      targetID,
			"byuser":    gorm.Expr("NULL"),
		})

		// GDPR erasure: partner-deleted accounts have no recovery affordance (the partner
		// owns the contract), so unlike the self-service flow we blank message content
		// immediately rather than deferring to the 14-day grace cleanup.
		// None of these nine assignments
		// reference another assigned column (all NULL/NOW() literals), so the SET
		// order is not load-bearing and GORM's alphabetical Updates(map) order is
		// safe; see the retired check-set-order.sh / setOrderIsLoadBearing
		// (removed in d22ba1d6c).
		db.Table("messages").Where("fromuser = ?", targetID).Updates(map[string]interface{}{
			"fromip":       gorm.Expr("NULL"),
			"message":      gorm.Expr("NULL"),
			"envelopefrom": gorm.Expr("NULL"),
			"fromname":     gorm.Expr("NULL"),
			"fromaddr":     gorm.Expr("NULL"),
			"messageid":    gorm.Expr("NULL"),
			"textbody":     gorm.Expr("NULL"),
			"htmlbody":     gorm.Expr("NULL"),
			"deleted":      gorm.Expr("NOW()"),
		})
		// gorm.Expr("1") rather than a
		// bare 1: the original writes the literal into the statement, and a
		// plain Go value binds as a placeholder instead, which is a different
		// statement text even though it sets the same value.
		db.Table("messages_groups").
			Where("msgid IN (SELECT id FROM messages WHERE fromuser = ?)", targetID).
			Update("deleted", gorm.Expr("1"))

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	// Self-service flow: logged-in user deletes their own account.
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Moderators must demote themselves first to avoid accidental deletion.
	var modRole string
	db.Table("memberships").Select("role").Where("userid = ? AND role IN (?, ?)", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Limit(1).Scan(&modRole)

	if modRole != "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"ret":    2,
			"status": "Please demote yourself to a member first",
		})
	}

	// Spammers cannot delete their own accounts (prevents evasion of tracking).
	var spammerCount int64
	db.Table("spam_users").Where("userid = ? AND collection IN (?, ?)", myid, utils.SPAM_COLLECTION_SPAMMER, utils.SPAM_COLLECTION_PENDING_ADD).
		Count(&spammerCount)

	if spammerCount > 0 {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{
			"ret":    3,
			"status": "We can't do this.",
		})
	}

	// Signal the auth middleware to skip the post-handler session check.
	c.Locals("skipPostAuthCheck", true)

	// V1 parity (User::delete): drop approved memberships so the user no longer appears
	// in group member lists during the grace period. Emit the per-group (Group, Left)
	// audit log first (byuser = the user themselves), since the eager delete leaves
	// nothing for the later cleanup cron to log.
	user.LogGroupLeftForApprovedMemberships(db, myid, myid)
	// Converted together with its
	// identical twin in the partner flow above (aeda8c91f9ff).
	db.Table("memberships").Where("userid = ? AND collection = ?", myid, utils.COLLECTION_APPROVED).Delete(nil)

	// Soft-delete: user can recover by logging back in within ~14 days.
	// GDPR erasure of message content (and any other personal data) is performed by the
	// background users:cleanup job once the grace period has elapsed (Laravel
	// UserManagementService::forgetInactiveUsers → User::forget). Doing it here would
	// destroy data that the user could otherwise restore by signing back in — which is
	// exactly the recovery affordance the soft-delete is supposed to preserve.
	// Converted together with its
	// identical twin in the partner flow above (c38c5422a649).
	db.Table("users").Where("id = ?", myid).Update("deleted", gorm.Expr("NOW()"))

	// V1 parity (User::delete with $log=TRUE): record the deletion in the audit log.
	// Same reasoning as 02506a663a0e above.
	db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"),
		"type":      log2.LOG_TYPE_USER,
		"subtype":   log2.LOG_SUBTYPE_DELETED,
		"user":      myid,
		"byuser":    myid,
	})

	// Destroy session so the user is logged out.
	db.Table("sessions").Where("userid = ?", myid).Delete(nil)

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}

// handleRelated records related users.
func handleRelated(c *fiber.Ctx, userlist []uint64) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// Insert related records for each pair.
	for _, otherID := range userlist {
		if otherID != myid && otherID > 0 {
			db.Table("users_related").Clauses(clause.Insert{Modifier: "IGNORE"}).
				Create(map[string]interface{}{"user1": myid, "user2": otherID})
		}
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}

// isActiveModForGroup checks the membership settings JSON to determine if the
// moderator is actively moderating this group. Defaults to active=1, then checks
// the 'active' key in the JSON settings, falling back to the legacy 'showmessages' key.
func isActiveModForGroup(settingsJSON *string) bool {
	if settingsJSON == nil || *settingsJSON == "" {
		return true // default to active when no settings are present
	}
	var settings map[string]interface{}
	if err := json.Unmarshal([]byte(*settingsJSON), &settings); err != nil {
		return true
	}
	if active, ok := settings["active"]; ok {
		switch v := active.(type) {
		case bool:
			return v
		case float64:
			return v != 0
		}
	}
	// Fallback to legacy showmessages flag (default true if absent).
	if sm, ok := settings["showmessages"]; ok {
		switch v := sm.(type) {
		case bool:
			return v
		case float64:
			return v != 0
		}
	}
	return true
}

// GetSession returns current session info for the logged-in user.
//
// @Summary Get current session
// @Tags session
// @Router /session [get]
func GetSession(c *fiber.Ctx) error {
	// Reject obsolete app versions.
	appversion := c.Query("appversion")
	if appversion != "" && strings.HasPrefix(appversion, "2") {
		return c.JSON(fiber.Map{
			"ret":    123,
			"status": "App is out of date - please upgrade or use the website",
		})
	}

	// Kill switch: reject any client whose build is older than the configured
	// minimum. webversion (BUILD_DATE) is sent on every GET /session and needs no
	// client cooperation, so failing the session makes an out-of-date client
	// non-functional until it updates. ModTools and the Freegle app/web are gated
	// separately (app_min_webversion_mt vs app_min_webversion) so each can be forced
	// to update independently; the modtools flag is added to every request by the
	// client. Fails open — blocks nobody unless an operator sets the relevant ISO
	// date. Website bundles are always fresh and self-heal on reload, so a date-only
	// gate doesn't meaningfully affect the website.
	modtools := c.Query("modtools") == "true" || c.Query("modtools") == "1"
	var minWebversion string
	database.DBConn.Table("config").Select("value").Where("`key` = ?", minWebversionConfigKey(modtools)).Scan(&minWebversion)
	if webversionOlderThan(c.Query("webversion"), minWebversion) {
		return c.JSON(fiber.Map{
			"ret":    123,
			"status": "App is out of date - please upgrade or use the website",
		})
	}

	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"ret":    1,
			"status": "Not logged in",
		})
	}

	db := database.DBConn

	// Record app/web version in users_builddates.
	// Throttled client-side via lastversiontime; we just insert/update.
	// A still-valid JWT can outlive its user (purged account), and the FK on
	// users_builddates then rejects the insert (~2/day since May) - so check
	// the user still exists rather than tolerating the 1452, which would also
	// hide real FK trouble. Same ghost-user family as the impersonation-link
	// guard in user.GetUser.
	webversion := c.Query("webversion")
	if webversion != "" || appversion != "" {
		var userExists int64
		db.Table("users").Where("id = ?", myid).Count(&userExists)
		if userExists > 0 {
			db.Table("users_builddates").Clauses(clause.OnConflict{
				DoUpdates: clause.Assignments(map[string]interface{}{
					"timestamp": gorm.Expr("NOW()"), "webversion": webversion, "appversion": appversion,
				}),
			}).Create(map[string]interface{}{
				"userid": myid, "webversion": webversion, "appversion": appversion,
			})
		}
	}

	// Parallel fetches for user data.
	type UserRow struct {
		ID                 uint64          `json:"id"`
		Fullname           *string         `json:"fullname"`
		Firstname          *string         `json:"firstname"`
		Lastname           *string         `json:"lastname"`
		Systemrole         string          `json:"systemrole"`
		Settings           json.RawMessage `json:"settings"`
		Lastaccess         *time.Time      `json:"lastaccess"`
		Added              *time.Time      `json:"added"`
		Lastlocation       *uint64         `json:"lastlocation"`
		Onholidaytill      *string         `json:"onholidaytill"`
		Source             *string         `json:"source"`
		Deleted            *time.Time      `json:"deleted"`
		Forgotten          *time.Time      `json:"forgotten"`
		Trustlevel         *string         `json:"trustlevel"`
		Permissions        *string         `json:"permissions"`
		Marketingconsent   bool            `json:"marketingconsent"`
		Bouncing           int             `json:"bouncing"`
		Relevantallowed    int             `json:"relevantallowed"`
		Newslettersallowed int             `json:"newslettersallowed"`
		Engagementlevel    *string         `json:"engagementlevel" gorm:"column:engagementlevel"`
	}

	type EmailRow struct {
		ID        uint64     `json:"id"`
		Email     string     `json:"email"`
		Preferred int        `json:"preferred"`
		Validated *time.Time `json:"validated"`
		Bounced   *time.Time `json:"bounced"`
		Ourdomain int        `json:"ourdomain"`
	}

	type MembershipRow struct {
		Groupid                  uint64  `json:"groupid"`
		Role                     string  `json:"role"`
		Emailfrequency           int     `json:"emailfrequency"`
		Eventsallowed            int     `json:"eventsallowed"`
		Volunteeringallowed      int     `json:"volunteeringallowed"`
		Microvolunteeringallowed int     `json:"microvolunteeringallowed"`
		Configid                 *uint64 `json:"configid"`
		Active                   int     `json:"active"` // 1=active mod, 0=backup mod
		Type                     string  `json:"-"`      // Used server-side for moderator detection, not returned to client
		Settings                 *string `json:"-"`      // Per-group membership settings JSON, used to determine active/inactive
	}

	type LocationRow struct {
		Name string  `json:"name"`
		Lat  float64 `json:"lat"`
		Lng  float64 `json:"lng"`
	}

	type SessionRow struct {
		ID     uint64 `json:"id"`
		Series uint64 `json:"series"`
		Token  string `json:"token"`
	}

	type AboutmeRow struct {
		Text      string    `json:"text"`
		Timestamp time.Time `json:"timestamp"`
	}

	// Identify which session authenticated this request so we return its
	// credentials in the response, not an arbitrary session row.
	// Without this, "WHERE userid = ? LIMIT 1" returns the oldest session for
	// the user (InnoDB primary-key order). A user with two active sessions —
	// one for ModTools, one for ilovefreegle.org — gets the other app's
	// session credentials back, causing the client to overwrite its stored
	// JWT/persistent token with the wrong session. (Discourse #9748)
	var currentSessionID uint64
	if _, sessID, _ := user.GetJWTFromRequest(c); sessID > 0 {
		currentSessionID = sessID
	} else if ptHeader := c.Get("Authorization2"); ptHeader != "" {
		// Use a minimal struct (no Series field) so that old persistent tokens
		// whose Series was serialised as a JSON string don't cause Unmarshal to
		// zero out the parsed ID.
		var minPT struct {
			ID uint64 `json:"id"`
		}
		if json.Unmarshal([]byte(ptHeader), &minPT) == nil && minPT.ID > 0 {
			currentSessionID = minPT.ID
		}
	}

	var wg sync.WaitGroup
	var userRow UserRow
	var emails []EmailRow
	var memberships []MembershipRow
	var sessionRow SessionRow
	var aboutme AboutmeRow

	type supporterRow struct {
		Supporter   bool       `json:"supporter" gorm:"column:supporter"`
		Donated     *time.Time `json:"donated" gorm:"column:donated"`
		DonatedType *string    `json:"donatedtype" gorm:"column:donatedtype"`
	}
	var supporterInfo supporterRow

	wg.Add(6)
	go func() {
		defer wg.Done()
		db.Table("users").Select("id, fullname, firstname, lastname, systemrole, settings, lastaccess, added, lastlocation, onholidaytill, source, deleted, forgotten, trustlevel, permissions, marketingconsent, bouncing, relevantallowed, newslettersallowed, engagement AS engagementlevel").
			Where("id = ?", myid).Scan(&userRow)
	}()
	go func() {
		defer wg.Done()
		db.Table("users_emails").Select("id, email, preferred, validated, bounced").
			Where("userid = ?", myid).Order("preferred DESC").Scan(&emails)
	}()
	go func() {
		defer wg.Done()
		db.Table("memberships m").
			Select("m.groupid, m.role, m.emailfrequency, m.eventsallowed, m.volunteeringallowed, m.configid, g.type, m.settings, g.microvolunteering AS microvolunteeringallowed").
			Joins("JOIN `groups` g ON g.id = m.groupid").
			Where("m.userid = ? AND m.collection = ?", myid, utils.COLLECTION_APPROVED).
			Order("LOWER(CASE WHEN g.namefull IS NOT NULL THEN g.namefull ELSE g.nameshort END)").
			Scan(&memberships)
	}()
	go func() {
		defer wg.Done()
		if currentSessionID > 0 {
			db.Table("sessions").Select("id, series, token").
				Where("id = ? AND userid = ?", currentSessionID, myid).Scan(&sessionRow)
		} else {
			db.Table("sessions").Select("id, series, token").
				Where("userid = ?", myid).Limit(1).Scan(&sessionRow)
		}
	}()
	go func() {
		defer wg.Done()
		db.Table("users_aboutme").Select("text, timestamp").
			Where("userid = ?", myid).Order("timestamp DESC").Limit(1).Scan(&aboutme)
	}()
	go func() {
		defer wg.Done()
		start := time.Now().AddDate(0, 0, -utils.SUPPORTER_PERIOD).Format("2006-01-02")
		db.Table("users").
			Select("(CASE WHEN ((users.systemrole != ? OR EXISTS(SELECT id FROM users_donations WHERE userid = ? AND users_donations.timestamp >= ?) OR EXISTS(SELECT id FROM microactions WHERE userid = ? AND microactions.timestamp >= ?)) AND (CASE WHEN JSON_EXTRACT(users.settings, '$.hidesupporter') IS NULL THEN 0 ELSE JSON_EXTRACT(users.settings, '$.hidesupporter') END) = 0) THEN 1 ELSE 0 END) AS supporter, (SELECT MAX(timestamp) FROM users_donations WHERE userid = ?) AS donated, (SELECT type FROM users_donations WHERE userid = ? ORDER BY timestamp DESC LIMIT 1) AS donatedtype",
				utils.SYSTEMROLE_USER, myid, start, myid, start, myid, myid).
			Where("users.id = ?", myid).
			Scan(&supporterInfo)
	}()
	wg.Wait()

	// Compute ourdomain flag for each email so the client can filter internal addresses.
	for i := range emails {
		emails[i].Ourdomain = utils.OurDomain(emails[i].Email)
	}

	// Populate the Active field on each membership from the settings JSON.
	for i := range memberships {
		if isActiveModForGroup(memberships[i].Settings) {
			memberships[i].Active = 1
		} else {
			memberships[i].Active = 0
		}
	}

	// Compute work counts and discourse stats for moderators (depends on memberships).
	var work fiber.Map
	var discourse fiber.Map

	// Collect group IDs where user is a moderator or owner, split by active/inactive.
	// The memberships.settings JSON 'active' flag determines if a mod is actively
	// moderating a group. Inactive groups' work counts show as blue (info) badges instead
	// of red (danger) badges. Default is active.
	var modGroupIDs, activeGroupIDs, inactiveGroupIDs []uint64
	isFreegleMod := false
	for _, m := range memberships {
		if m.Role == utils.ROLE_OWNER || m.Role == utils.ROLE_MODERATOR {
			modGroupIDs = append(modGroupIDs, m.Groupid)
			if m.Active == 1 {
				activeGroupIDs = append(activeGroupIDs, m.Groupid)
			} else {
				inactiveGroupIDs = append(inactiveGroupIDs, m.Groupid)
			}
			if m.Type == utils.GROUP_TYPE_FREEGLE {
				isFreegleMod = true
			}
		}
	}

	// Start discourse fetch in parallel with work counts (only for Freegle moderators).
	var discourseWg sync.WaitGroup
	if isFreegleMod {
		discourseWg.Add(1)
		go func() {
			defer discourseWg.Done()
			discourse = fetchDiscourseStats(myid)
		}()
	}

	if len(modGroupIDs) > 0 {
		// Work counts are split by active/inactive group status.
		// Active groups → primary fields (red/danger badges in UI).
		// Inactive groups → "other" fields (blue/info badges in UI).
		// Counts that only appear for active groups: spam, pendingevents, pendingvolunteering,
		// pendingadmins, editreview, happiness, relatedmembers.
		// Counts split by active/inactive: pending/pendingother, spammembers/spammembersother,
		// chatreview/chatreviewother.
		var pending, pendingother, spam int64
		var pendingmembers, spammembers, spammembersother int64
		var pendingevents, pendingadmins, editreview int64
		var pendingvolunteering, spammerpendingadd, spammerpendingremove, stories int64
		var chatreview, chatreviewother, newsletterstories, giftaid, happiness, relatedmembers int64
		var housekeeping, cronjobs int64
		var emailin, emailout int64
		var maildeferrals int64
		var helperEscalated int64

		var wg2 sync.WaitGroup

		// --- Pending messages: active groups split by held, inactive all → pendingother ---
		// Only count messages where contentcheck_checked_at IS NOT NULL: the content
		// check has run and left the message pending (moderated user/group or flagged
		// content). Messages that have not yet been content-checked may still be
		// auto-approved and must not trigger a phantom notification or inflate the
		// badge count. Discourse #9481 post 563.
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				// Unheld pending in active groups → pending (red).
				db.Table("messages_groups mg").
					Joins("INNER JOIN messages m ON m.id = mg.msgid").
					Joins("INNER JOIN users u ON u.id = m.fromuser").
					Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND mg.heldby IS NULL AND mg.contentcheck_checked_at IS NOT NULL",
						activeGroupIDs, utils.COLLECTION_PENDING).
					Count(&pending)
				// Held pending in active groups → pendingother (blue). No
				// contentcheck_checked_at filter here: that filter exists so a post which
				// might still auto-approve does not raise a phantom badge, which only
				// applies while nobody has claimed it. A held post has been claimed by a
				// moderator, will never auto-approve, and is already showing in their list
				// as "Held by ..." — dropping it left mods with a badge lower than the
				// number of held posts in front of them (Discourse 9481/635).
				var heldActive int64
				db.Table("messages_groups mg").
					Joins("INNER JOIN messages m ON m.id = mg.msgid").
					Joins("INNER JOIN users u ON u.id = m.fromuser").
					Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND mg.heldby IS NOT NULL",
						activeGroupIDs, utils.COLLECTION_PENDING).
					Count(&heldActive)
				pendingother += heldActive
			}
			if len(inactiveGroupIDs) > 0 {
				// All pending in inactive groups → pendingother (blue). Same rule as
				// above: an unchecked post might still auto-approve so it waits for the
				// content check, but a held one is claimed work and always counts.
				var inact int64
				db.Table("messages_groups mg").
					Joins("INNER JOIN messages m ON m.id = mg.msgid").
					Joins("INNER JOIN users u ON u.id = m.fromuser").
					Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND (mg.contentcheck_checked_at IS NOT NULL OR mg.heldby IS NOT NULL)",
						inactiveGroupIDs, utils.COLLECTION_PENDING).
					Count(&inact)
				pendingother += inact
			}
		}()

		// --- Spam messages (only for active groups) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				// Match the Pending review list (message_list.go): Spam-collection
				// messages older than 30 days are aged out of the queue, so they
				// must not be counted in the badge either — otherwise the badge
				// shows a total with no visible, clickable home (an inflated
				// hamburger count and no red left-menu count).
				db.Table("messages_groups mg").
					Joins("INNER JOIN messages m ON m.id = mg.msgid").
					Joins("INNER JOIN users u ON u.id = m.fromuser").
					Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND mg.arrival >= (NOW() - INTERVAL 30 DAY)",
						activeGroupIDs, utils.COLLECTION_SPAM).
					Count(&spam)
			}
		}()

		// --- Pending members (all groups, no active/inactive split) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			db.Table("memberships").Where("groupid IN ? AND collection = ?",
				modGroupIDs, utils.COLLECTION_PENDING).Count(&pendingmembers)
		}()

		// --- Spam members: active split by held, inactive all → spammembersother ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				// Unheld spam members in active groups → spammembers (red).
				// Condition matches getSpamMembers list: flag set and either never reviewed
				// or re-flagged after the last review action.
				db.Table("memberships").
					Where("groupid IN ? AND reviewrequestedat IS NOT NULL "+
						"AND (reviewedat IS NULL OR reviewrequestedat > reviewedat) "+
						"AND heldby IS NULL",
						activeGroupIDs).Count(&spammembers)
				// Held spam members in active groups → spammembersother (blue).
				var heldActive int64
				db.Table("memberships").
					Where("groupid IN ? AND reviewrequestedat IS NOT NULL "+
						"AND (reviewedat IS NULL OR reviewrequestedat > reviewedat) "+
						"AND heldby IS NOT NULL",
						activeGroupIDs).Count(&heldActive)
				spammembersother += heldActive
			}
			if len(inactiveGroupIDs) > 0 {
				// All spam members in inactive groups → spammembersother (blue).
				var inact int64
				db.Table("memberships").
					Where("groupid IN ? AND reviewrequestedat IS NOT NULL "+
						"AND (reviewedat IS NULL OR reviewrequestedat > reviewedat)",
						inactiveGroupIDs).Count(&inact)
				spammembersother += inact
			}
		}()

		// --- Pending community events (only active groups) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				db.Table("communityevents ce").
					Select("COUNT(DISTINCT ce.id)").
					Joins("INNER JOIN communityevents_groups ceg ON ceg.eventid = ce.id").
					Joins("INNER JOIN communityevents_dates ced ON ced.eventid = ce.id").
					Where("ceg.groupid IN ? AND ce.pending = 1 AND ce.deleted = 0 AND ced.end >= NOW()",
						activeGroupIDs).
					Scan(&pendingevents)
			}
		}()

		// --- Pending admin applications (only active groups) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				db.Table("admins").Where("groupid IN ? AND complete IS NULL AND pending = 1 AND heldby IS NULL",
					activeGroupIDs).Count(&pendingadmins)
			}
		}()

		// --- Edit reviews (only active groups) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				// rippled_in = 0: an edit belongs to the post's origin group only;
				// without this the Edit badge counts rippled-in copies that the Edit
				// list (filtered rippled_in=0) never shows — a ghost count (Discourse
				// 9839). Matches ListMessagesMT and groupWork's per-group Editreview.
				db.Table("messages_edits me").
					Select("COUNT(DISTINCT me.msgid)").
					Joins("INNER JOIN messages_groups mg ON mg.msgid = me.msgid AND mg.deleted = 0 AND mg.rippled_in = 0").
					Where("mg.groupid IN ? AND me.reviewrequired = 1 AND me.approvedat IS NULL AND me.revertedat IS NULL AND me.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)",
						activeGroupIDs).
					Scan(&editreview)
			}
		}()

		// --- Pending volunteering (only active groups) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				db.Table("volunteering v").
					Select("COUNT(DISTINCT v.id)").
					Joins("INNER JOIN volunteering_groups vg ON vg.volunteeringid = v.id").
					Joins("LEFT JOIN volunteering_dates vd ON vd.volunteeringid = v.id").
					Where("vg.groupid IN ? AND v.pending = 1 AND v.deleted = 0 AND v.expired = 0 AND (vd.end IS NULL OR vd.end >= NOW())",
						activeGroupIDs).
					Scan(&pendingvolunteering)
			}
		}()

		// --- Stories (active groups only — must match the listing query in story.go) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				storyCutoff := time.Now().AddDate(0, 0, -31).Format("2006-01-02")
				db.Table("users_stories us").
					Select("COUNT(DISTINCT us.id)").
					Joins("INNER JOIN memberships m ON m.userid = us.userid").
					Joins("INNER JOIN users ON users.id = us.userid").
					Where("m.groupid IN ? AND m.collection = ? AND us.date > ? AND us.reviewed = 0 AND users.deleted IS NULL",
						activeGroupIDs, utils.COLLECTION_APPROVED, storyCutoff).
					Scan(&stories)
			}
		}()

		// --- Spammer pending counts (SpamAdmin permission only) ---
		// Gated by the SpamAdmin permission (granted via the teams table), to
		// match the Spammers page (spammers.go) and the frontend's
		// hasPermissionSpamAdmin. Systemrole=Support is too broad: a Support
		// user not on the spam team can't see the Spammers menu, so counting
		// these would inflate the badge with no visible, clickable home.
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if auth.HasPermission(myid, auth.PERM_SPAM_ADMIN) {
				db.Table("spam_users").Where("collection = ?", utils.SPAM_COLLECTION_PENDING_ADD).Count(&spammerpendingadd)
				db.Table("spam_users").Where("collection = ?", utils.SPAM_COLLECTION_PENDING_REMOVE).Count(&spammerpendingremove)
			}
		}()

		// --- Chat review: RECIPIENT matching + active/inactive split ---
		// Review counts are based on the RECIPIENT's group membership (not either participant).
		// Active groups: not-held -> chatreview, held -> chatreviewother.
		// Inactive groups: all -> chatreviewother.
		//
		// The chat review SQL uses CASE WHEN to find the recipient:
		//   CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END
		// Primary: recipient IS a member of a Freegle group.
		// Secondary: recipient is NOT a member → use sender's group instead.
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			chatCutoff := time.Now().AddDate(0, 0, -utils.CHAT_ACTIVE_LIMIT).Format("2006-01-02")

			// Helper SQL for recipient-based chat review counting.
			// Count chat messages pending review. Must match the logic in
			// chatmessage_review.go getReviewQueue() so the sidebar count
			// equals the number of displayed messages.
			//
			// heldFilter is the only toggle - 2 possible rendered forms, both
			// proven by the retired ormharness (shapes.json /
			// TestTier3Shapes_f43d5f680ef9, removed in d22ba1d6c).
			chatReviewSQL := func(groupIDs []uint64, heldFilter string) int64 {
				if len(groupIDs) == 0 {
					return 0
				}
				// WHERE built as a single string for ONE Where() call: GORM's
				// clause.Where wraps any fragment containing "AND"/"OR" in an
				// extra paren pair once there is more than one Where
				// expression to combine (clause/where.go buildExprs), which
				// would diverge from the golden.
				whereSQL := "cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? " +
					heldFilter + " AND (" +
					// User2Mod: chat belongs to one of the mod's groups.
					"  (cr.chattype = ? AND cr.groupid IN ?) " +
					"  OR " +
					// User2User case 1: recipient (other user) is in mod's groups.
					"  (cr.chattype = ? AND " +
					"    EXISTS (SELECT 1 FROM memberships m " +
					"      INNER JOIN `groups` g ON m.groupid = g.id AND g.type = ? " +
					"      WHERE m.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND m.groupid IN ?)) " +
					"  OR " +
					// User2User case 2: recipient has no Freegle memberships, sender in mod's groups.
					"  (cr.chattype = ? AND " +
					"    NOT EXISTS (SELECT 1 FROM memberships m " +
					"      INNER JOIN `groups` g ON m.groupid = g.id AND g.type = ? " +
					"      WHERE m.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)) " +
					"    AND EXISTS (SELECT 1 FROM memberships m " +
					"      INNER JOIN `groups` g ON m.groupid = g.id AND g.type = ? " +
					"      WHERE m.userid = cm.userid AND m.groupid IN ?))" +
					")"

				var count int64
				db.Table("chat_messages cm").
					Select("COUNT(DISTINCT cm.id)").
					Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
					Joins("INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL").
					Joins("LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id").
					Where(whereSQL, chatCutoff,
						utils.CHAT_TYPE_USER2MOD, groupIDs,
						utils.CHAT_TYPE_USER2USER, utils.GROUP_TYPE_FREEGLE, groupIDs,
						utils.CHAT_TYPE_USER2USER, utils.GROUP_TYPE_FREEGLE, utils.GROUP_TYPE_FREEGLE, groupIDs).
					Scan(&count)
				return count
			}

			// Active groups: not held → chatreview (red), held → chatreviewother (blue).
			chatreview = chatReviewSQL(activeGroupIDs, "AND cmh.userid IS NULL")
			chatreviewother = chatReviewSQL(activeGroupIDs, "AND cmh.userid IS NOT NULL")
			// Inactive groups: all → chatreviewother (blue).
			chatreviewother += chatReviewSQL(inactiveGroupIDs, "AND cmh.userid IS NULL")
			chatreviewother += chatReviewSQL(inactiveGroupIDs, "AND cmh.userid IS NOT NULL")

			// Wider chat review: unheld messages from groups with widerchatreview=1
			// that are NOT already counted in the base queries above.
			// These go into chatreviewother (blue badge).
			if user.HasWiderReview(myid) {
				allModGroupIDs := append(activeGroupIDs, inactiveGroupIDs...)
				var widerCount int64

				// WHERE built as a single string for ONE Where() call: GORM's
				// clause.Where wraps any fragment containing "AND"/"OR" in an
				// extra paren pair once there is more than one Where
				// expression to combine (clause/where.go buildExprs), which
				// would diverge from the golden.
				widerWhereSQL := "cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? AND cmh.id IS NULL " +
					"AND JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 AND (cm.reportreason IS NULL OR cm.reportreason != 'User')"
				widerWhereArgs := []interface{}{chatCutoff}

				if len(allModGroupIDs) > 0 {
					// Exclude messages where the recipient has ANY membership in
					// the mod's own groups (those are already counted in the base
					// chatreview/chatreviewother). We use NOT EXISTS rather than
					// AND m.groupid NOT IN because a recipient may be on both a
					// mod's group AND a separate wider-review group; the simple
					// NOT IN only filters the mod-group JOIN row while still
					// counting the wider-group JOIN row, causing double-counting.
					//
					// This branch (allModGroupIDs>0) has exactly one rendered
					// form, proven by the retired ormharness (shapes.json /
					// TestTier3Shapes_3f3696f3bba4, removed in d22ba1d6c).
					recipientExpr := "(CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)"
					widerWhereSQL += " AND NOT EXISTS (SELECT 1 FROM memberships m2 WHERE m2.userid = " + recipientExpr + " AND m2.groupid IN (?))"
					widerWhereArgs = append(widerWhereArgs, allModGroupIDs)
				}
				// else: ORM migration site 76555fe088e5 (Tier 3 keep-raw
				// review). This branch (no mod groups) has exactly one
				// rendered form, proven by the retired ormharness
				// (shapes.json / TestTier3Shapes_76555fe088e5, removed in
				// d22ba1d6c).

				db.Table("chat_messages cm").
					Select("COUNT(DISTINCT cm.id)").
					Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
					Joins("INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL").
					Joins("LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id").
					Joins("INNER JOIN memberships m ON m.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)").
					Joins("INNER JOIN `groups` g ON m.groupid = g.id AND g.type = '"+utils.GROUP_TYPE_FREEGLE+"'").
					Where(widerWhereSQL, widerWhereArgs...).
					Scan(&widerCount)

				chatreviewother += widerCount
			}
		}()

		// --- Newsletter stories (global, no group scope) ---
		// Only visible to users with Newsletter permission — prevents phantom task counts for regular mods.
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if auth.HasPermission(myid, auth.PERM_NEWSLETTER) {
				db.Table("users_stories").
					Joins("INNER JOIN users ON users.id = users_stories.userid AND users.deleted IS NULL").
					Where("reviewed = 1 AND public = 1 AND newsletterreviewed = 0").
					Count(&newsletterstories)
			}
		}()

		// --- Escalated helper conversations (global, Clearance permission) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if auth.HasPermission(myid, auth.PERM_CLEARANCE) {
				db.Table("helper_repliers").Where("state = 'ESCALATED'").Count(&helperEscalated)
			}
		}()

		// --- Gift aid (global) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			db.Table("giftaid").Where("reviewed IS NULL AND deleted IS NULL AND period != 'Declined'").Count(&giftaid)
		}()

		// --- Happiness (only active groups) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				hapCutoff := time.Now().AddDate(0, 0, -utils.CHAT_ACTIVE_LIMIT).Format("2006-01-02")
				// rippled_in = 0: the aggregate Feedback work count must match
				// the per-group badge (groupWork.go) and the list — only posts
				// that originated on the group, not rippled-in copies. 9808/633.
				db.Table("messages_outcomes mo").
					Select("COUNT(DISTINCT mo.id)").
					Joins("INNER JOIN messages_groups mg ON mg.msgid = mo.msgid").
					Where("mo.timestamp >= ? AND mg.arrival >= ? AND mg.groupid IN ? "+
						"AND mg.rippled_in = 0 "+
						"AND mo.comments IS NOT NULL "+
						"AND mo.comments != 'Sorry, this is no longer available.' "+
						"AND mo.comments != 'Thanks, this has now been taken.' "+
						"AND mo.comments != 'Thanks, I''m no longer looking for this.' "+
						"AND mo.comments != 'Sorry, this has now been taken.' "+
						"AND mo.comments != 'Thanks for the interest, but this has now been taken.' "+
						"AND mo.comments != 'Thanks, these have now been taken.' "+
						"AND mo.comments != 'Thanks, this has now been received.' "+
						"AND mo.comments != 'Withdrawn on user unsubscribe' "+
						"AND mo.comments != 'Auto-Expired' "+
						"AND (mo.happiness = 'Happy' OR mo.happiness IS NULL) "+
						"AND mo.reviewed = 0",
						hapCutoff, hapCutoff, activeGroupIDs).
					Scan(&happiness)
			}
		}()

		// --- Related members (only active groups) ---
		wg2.Add(1)
		go func() {
			defer wg2.Done()
			if len(activeGroupIDs) > 0 {
				// Derived-table trick: GORM's
				// Table() passes its name argument through verbatim (no quoting) once it
				// contains a space, so a parenthesized UNION subquery can be given as the
				// "table name" with its own bind args in Table()'s variadic args.
				db.Table("(SELECT ur.user1 FROM users_related ur "+
					"INNER JOIN memberships m ON m.userid = ur.user1 "+
					"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = ? "+
					"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = ? "+
					"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
					"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
					"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0 "+
					"UNION "+
					"SELECT ur.user1 FROM users_related ur "+
					"INNER JOIN memberships m ON m.userid = ur.user2 "+
					"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = ? "+
					"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = ? "+
					"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
					"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
					"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0) t",
					utils.SYSTEMROLE_USER, utils.SYSTEMROLE_USER, activeGroupIDs, utils.SYSTEMROLE_USER, utils.SYSTEMROLE_USER, activeGroupIDs).
					Select("COUNT(*)").
					Scan(&relatedmembers)
			}
		}()

		// --- Housekeeping tasks: overdue or failed (Admin only) ---
		// Housekeeping is a SysAdmin function — Admin systemrole only. Support
		// users don't see the SysAdmin housekeeping list, so counting it for
		// them inflates the badge with no visible, clickable home.
		if userRow.Systemrole == utils.SYSTEMROLE_ADMIN {
			wg2.Add(1)
			go func() {
				defer wg2.Done()
				db.Table("housekeeper_tasks").
					Where("enabled = 1 AND placeholder = 0 AND ( last_status = 'failure' OR last_run_at IS NULL OR last_run_at < DATE_SUB(NOW(), INTERVAL interval_hours HOUR) )").
					Count(&housekeeping)
			}()

			// --- Cron jobs: failures + never-run jobs (admin-only) ---
			wg2.Add(1)
			go func() {
				defer wg2.Done()
				var failures int64
				db.Table("cron_job_status").Where("last_exit_code IS NOT NULL AND last_exit_code != 0").Count(&failures)

				var runCount int64
				db.Table("cron_job_status").Count(&runCount)

				activeCount := int64(housekeeper.ActiveCronJobCount())
				neverRun := activeCount - runCount
				if neverRun < 0 {
					neverRun = 0
				}

				cronjobs = failures + neverRun
			}()

			// --- Email health: incoming and outgoing alerts (admin/support) ---
			wg2.Add(1)
			go func() {
				defer wg2.Done()
				emailin, emailout = FetchEmailHealth(db, time.Now().Hour())
			}()

			// --- Providers currently refusing our mail (ADMIN only) ---
			// ADMIN only, like every other badge on that page - the whole
			// block is SYSTEMROLE_ADMIN, so a support user gets no
			// maildeferrals key and therefore no badge, exactly as they get
			// none for housekeeping or cronjobs. Deliberate, not an oversight:
			// support can open the Delayed tab (the endpoint is
			// IsAdminOrSupport) but is not badged towards it. The neighbouring
			// comments saying "admin/support" are wrong about the gate.
			// Counted by DOMAIN, and per-mailbox reasons excluded, so the badge
			// matches what the Delayed table shows. A full inbox is not an
			// outage and must not light this up.
			wg2.Add(1)
			go func() {
				defer wg2.Done()
				db.Table("mail_suppressions").
					Where("released_at IS NULL AND scope = ?", "domain").
					Where("reason IS NULL OR reason NOT REGEXP ?", maildeferral.PerMailboxReason).
					Count(&maildeferrals)
			}()
		}

		wg2.Wait()

		// Total only includes actionable work items (primary/red badge counts),
		// not informational ones (other/blue badge counts like chatreviewother, happiness, giftaid, pendingother).
		total := pending + spam + pendingmembers + spammembers + pendingevents +
			pendingadmins + editreview + pendingvolunteering + stories +
			spammerpendingadd + spammerpendingremove +
			chatreview + newsletterstories + relatedmembers + housekeeping + cronjobs +
			emailin + emailout + helperEscalated + maildeferrals

		work = fiber.Map{
			"pending":              pending,
			"pendingother":         pendingother,
			"spam":                 spam,
			"pendingmembers":       pendingmembers,
			"spammembers":          spammembers,
			"spammembersother":     spammembersother,
			"pendingevents":        pendingevents,
			"pendingadmins":        pendingadmins,
			"editreview":           editreview,
			"pendingvolunteering":  pendingvolunteering,
			"stories":              stories,
			"spammerpendingadd":    spammerpendingadd,
			"spammerpendingremove": spammerpendingremove,
			"chatreview":           chatreview,
			"chatreviewother":      chatreviewother,
			"newsletterstories":    newsletterstories,
			"helperEscalated":      helperEscalated,
			"giftaid":              giftaid,
			"happiness":            happiness,
			"relatedmembers":       relatedmembers,
			"housekeeping":         housekeeping,
			"cronjobs":             cronjobs,
			"emailin":              emailin,
			"emailout":             emailout,
			"maildeferrals":        maildeferrals,
			"total":                total,
		}
	}

	// Wait for discourse fetch to complete.
	discourseWg.Wait()

	// Fetch location for me.lat/me.lng — V1 parity: prefer settings.mylocation, fall back to lastlocation.
	// V1 User::getLatLngs() uses settings.mylocation.lat/lng as primary (the postcode the user typed in
	// their settings) and only falls back to lastlocation when mylocation is absent. lastlocation is
	// updated by every draft post, so without this preference a draft on a remote group would shift
	// me.lat/me.lng (and therefore chat distances) to that group's location.
	var loc *LocationRow
	if len(userRow.Settings) > 0 {
		var parsed struct {
			Mylocation *struct {
				Lat  float64 `json:"lat"`
				Lng  float64 `json:"lng"`
				Name string  `json:"name"`
			} `json:"mylocation"`
		}
		if json.Unmarshal(userRow.Settings, &parsed) == nil && parsed.Mylocation != nil &&
			(parsed.Mylocation.Lat != 0 || parsed.Mylocation.Lng != 0) {
			loc = &LocationRow{
				Lat:  parsed.Mylocation.Lat,
				Lng:  parsed.Mylocation.Lng,
				Name: parsed.Mylocation.Name,
			}
		}
	}
	if loc == nil && userRow.Lastlocation != nil && *userRow.Lastlocation > 0 {
		var locRow LocationRow
		db.Table("locations").Select("name, lat, lng").Where("id = ?", *userRow.Lastlocation).Scan(&locRow)
		if locRow.Name != "" {
			loc = &locRow
		}
	}

	// Fetch profile using the same logic as user.GetUserById — GetProfileRecord
	// fetches the raw DB row, then ProfileSetPath computes path/paththumb URLs
	// that the frontend expects (me.profile.path, me.profile.paththumb).
	var profile *user.UserProfile
	profileRecord := user.GetProfileRecord(myid)
	if profileRecord.Profileid > 0 && profileRecord.Useprofile {
		var p user.UserProfile
		user.ProfileSetPath(profileRecord.Profileid, profileRecord.Url, profileRecord.Externaluid, profileRecord.Externalmods, profileRecord.Archived, &p)
		profile = &p
	}

	// Build JWT from session.
	var jwtString string
	if sessionRow.ID > 0 {
		jwtToken := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
			"id":        strconv.FormatUint(myid, 10),
			"sessionid": strconv.FormatUint(sessionRow.ID, 10),
			"exp":       time.Now().Unix() + 30*24*60*60,
		})
		secret := os.Getenv("JWT_SECRET")
		var jwtErr error
		jwtString, jwtErr = jwtToken.SignedString([]byte(secret))
		if jwtErr != nil {
			stdlog.Printf("Failed to sign JWT for user %d: %v", myid, jwtErr)
		}
	}

	// Build persistent token.
	var persistent interface{}
	if sessionRow.ID > 0 {
		persistent = fiber.Map{
			"id":     sessionRow.ID,
			"series": sessionRow.Series,
			"token":  sessionRow.Token,
			"userid": myid,
		}
	}

	// Compute displayname from fullname/firstname/lastname (matching GetUserById logic).
	displayname := ""
	if userRow.Fullname != nil && *userRow.Fullname != "" {
		displayname = *userRow.Fullname
	} else {
		if userRow.Firstname != nil {
			displayname = *userRow.Firstname
			if userRow.Lastname != nil {
				displayname += " " + *userRow.Lastname
			}
		} else if userRow.Lastname != nil {
			displayname = *userRow.Lastname
		}
	}
	displayname = utils.TidyName(displayname)

	// Invent a name from email when no usable name is set.
	if displayname == "A freegler" {
		displayname = user.InventName(db, myid)
	}

	// Apply V1-parity defaults (notificationmails, engagement, mod-only modnotifs/backupmodnotifs)
	// to the settings before returning.  GetSession is a read path — this never persists to DB.
	settingsWithDefaults := user.ApplySettingsDefaultsToJSON(userRow.Settings, userRow.Systemrole)

	// Build the me object.
	me := fiber.Map{
		"id":                 userRow.ID,
		"displayname":        displayname,
		"fullname":           userRow.Fullname,
		"firstname":          userRow.Firstname,
		"lastname":           userRow.Lastname,
		"systemrole":         userRow.Systemrole,
		"settings":           settingsWithDefaults,
		"lastaccess":         userRow.Lastaccess,
		"added":              userRow.Added,
		"source":             userRow.Source,
		"deleted":            userRow.Deleted,
		"forgotten":          userRow.Forgotten,
		"trustlevel":         userRow.Trustlevel,
		"marketingconsent":   userRow.Marketingconsent,
		"bouncing":           userRow.Bouncing,
		"relevantallowed":    userRow.Relevantallowed,
		"newslettersallowed": userRow.Newslettersallowed,
		"engagementlevel":    userRow.Engagementlevel,
		"aboutme":            aboutme,
		"supporter":          supporterInfo.Supporter,
		"donated":            supporterInfo.Donated,
		"donatedtype":        supporterInfo.DonatedType,
	}

	if userRow.Onholidaytill != nil {
		me["onholidaytill"] = *userRow.Onholidaytill
	}

	if loc != nil {
		me["city"] = loc.Name
		me["lat"] = loc.Lat
		me["lng"] = loc.Lng
	}

	if profile != nil {
		me["profile"] = profile
	}

	// Parse permissions from comma-separated string into array.
	if userRow.Permissions != nil && *userRow.Permissions != "" {
		perms := strings.Split(*userRow.Permissions, ",")
		for i := range perms {
			perms[i] = strings.TrimSpace(perms[i])
		}
		me["permissions"] = perms
	}

	// Team membership gates a few ModTools pages (currently Partnerships). This is not
	// gated on systemrole: some team members are ordinary members by role - the account a
	// team shares for its own inbox, for instance - and they still need their page. The
	// table is tiny and indexed on userid, so the lookup is cheap enough to always do.
	var teams []string
	db.Table("teams_members tm").
		Select("t.name").
		Joins("INNER JOIN teams t ON t.id = tm.teamid").
		Where("tm.userid = ?", myid).
		Order("t.name ASC").
		Scan(&teams)

	if teams == nil {
		teams = []string{}
	}

	me["teams"] = teams

	if emails == nil {
		emails = make([]EmailRow, 0)
	}

	// Add primary email to the me object (first non-internal-domain email).
	for _, email := range emails {
		if utils.OurDomain(email.Email) == 0 {
			me["email"] = email.Email
			break
		}
	}

	// Tell the member when their provider is currently refusing our mail, so
	// they can check back on the site instead of waiting for email that cannot
	// arrive. Preferred address only: emails is ordered preferred DESC, so the
	// address chosen above is the one we would actually send to, and warning
	// about a secondary address they never read would just be noise.
	//
	// This is the deferral sibling of "bouncing" above. They are different
	// failures and read differently to a member: bouncing means their address
	// is rejecting us and they must fix it, whereas this means their provider
	// is throttling us and there is nothing for them to do but wait.
	if primary, ok := me["email"].(string); ok {
		if deferral := maildeferral.ForEmail(primary); deferral != nil {
			me["emaildeferred"] = deferral
		}
	}

	if memberships == nil {
		memberships = make([]MembershipRow, 0)
	}

	resp := fiber.Map{
		"ret":        0,
		"status":     "Success",
		"me":         me,
		"groups":     memberships,
		"emails":     emails,
		"persistent": persistent,
		"jwt":        jwtString,
	}

	if work != nil {
		resp["work"] = work
	}

	if discourse != nil {
		resp["discourse"] = discourse
	}

	return c.JSON(resp)
}

// displayname/firstname/lastname keep the original's exact interaction
// (Displayname injects "= NULL" for whichever sibling is absent) inside
// this function, same as the original - that logic does not depend on
// anything outside these three pointers, so there was nothing to pull out.
//
// lastlocationID is different: it is the CALLER's already-resolved decision
// from user.ProcessSettingsUpdate's postcode-change detection, a live DB
// read comparing the new location id against the user's CURRENT
// users.lastlocation. This function only assembles the SET list from
// whatever the caller resolved; it does not decide whether that lookup
// fires or what it finds - PatchSession calls ProcessSettingsUpdate with
// its OWN local scratch slice (not the shared setClauses/setArgs every
// other field appends to) so the result is inspectable before this
// function ever runs. That is the same technique user/user.go's PatchUser
// (site 941509171a6e) already uses for its own, unrelated call to the same
// helper - ProcessSettingsUpdate's signature and behaviour, and PatchUser
// itself, are unchanged by this.
//
// buildPatchSessionUpdateSet assembles the users UPDATE's SET list as a
// clause.Set (a slice of clause.Assignment, gorm.io/gorm/clause), one
// assignment appended per field the request actually supplies - the same
// field-by-field branching the string-concatenation version this replaced
// used, just emitting an assignment instead of a "col = ?" text fragment +
// bound arg. clause.Set is order-preserving (it is a plain slice; Build()
// walks it in slice order, see gorm.io/gorm/clause/set.go), so the
// left-to-right assignment order MySQL evaluates a SET list in is exactly
// the order fields are appended below - unchanged from the string version.
//
// Previously kept raw with
// the reasoning that a dynamic SET list built by string concatenation has
// 2^n possible shapes and so cannot be a fixed GORM chain - true for a FIXED
// chain, but irrelevant here: the chain itself is fixed
// (.Table("users").Where(...).Clauses(set).Updates(...)), only the
// PRE-BUILT clause.Set slice varies at runtime, exactly the way the SQL
// string used to. Proven against the identical fieldwise.json goldens
// already recorded for the string version (session_fieldwise_tier9_test.go),
// via the retired ormharness's AssertGoldenFieldwise (all removed in
// d22ba1d6c) - same n+2 cases, same golden SQL per
// case, now rendered by GORM instead of by hand.
func buildPatchSessionUpdateSet(displayname, firstname, lastname, settingsJSON *string, lastlocationID *uint64, onholidaytill *string, relevantallowed, newslettersallowed *int, source *string, deletedNull bool, marketingconsent *int) clause.Set {
	var set clause.Set

	if displayname != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "fullname"}, Value: *displayname})
		// Clear first/last unless explicitly provided in the same request.
		if firstname == nil {
			set = append(set, clause.Assignment{Column: clause.Column{Name: "firstname"}, Value: gorm.Expr("NULL")})
		}
		if lastname == nil {
			set = append(set, clause.Assignment{Column: clause.Column{Name: "lastname"}, Value: gorm.Expr("NULL")})
		}
	}

	if firstname != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "firstname"}, Value: *firstname})
	}

	if lastname != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "lastname"}, Value: *lastname})
	}

	if settingsJSON != nil {
		if lastlocationID != nil {
			set = append(set, clause.Assignment{Column: clause.Column{Name: "lastlocation"}, Value: *lastlocationID})
		}
		set = append(set, clause.Assignment{Column: clause.Column{Name: "settings"}, Value: *settingsJSON})
	}

	if onholidaytill != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "onholidaytill"}, Value: *onholidaytill})
	}

	if relevantallowed != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "relevantallowed"}, Value: *relevantallowed})
	}

	if newslettersallowed != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "newslettersallowed"}, Value: *newslettersallowed})
	}

	if source != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "source"}, Value: *source})
	}

	if deletedNull {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "deleted"}, Value: gorm.Expr("NULL")})
	}

	if marketingconsent != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "marketingconsent"}, Value: *marketingconsent})
	}

	return set
}

// PatchSession updates session/user settings for the logged-in user.
//
// @Summary Update session/user settings
// @Tags session
// @Router /session [patch]
func PatchSession(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type PatchNotifications struct {
		Push *json.RawMessage `json:"push,omitempty"`
	}

	type PatchRequest struct {
		Displayname        *string             `json:"displayname,omitempty"`
		Firstname          *string             `json:"firstname,omitempty"`
		Lastname           *string             `json:"lastname,omitempty"`
		Settings           *json.RawMessage    `json:"settings,omitempty"`
		Password           *string             `json:"password,omitempty"`
		Onholidaytill      *string             `json:"onholidaytill,omitempty"`
		Relevantallowed    *utils.FlexInt      `json:"relevantallowed,omitempty"`
		Newslettersallowed *utils.FlexInt      `json:"newslettersallowed,omitempty"`
		Aboutme            *string             `json:"aboutme,omitempty"`
		Notifications      *PatchNotifications `json:"notifications,omitempty"`
		Email              *string             `json:"email,omitempty"`
		Source             *string             `json:"source,omitempty"`
		Deleted            json.RawMessage     `json:"deleted,omitempty"`
		Marketingconsent   *FlexBool           `json:"marketingconsent,omitempty"`
		Key                *string             `json:"key,omitempty"`
		Modtools           FlexBool            `json:"modtools,omitempty"`
	}

	var req PatchRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	// Handle email confirmation via validatekey. This is a standalone operation
	// that confirms ownership of an email address. Ported from the legacy V1
	// PHP User::confirmEmail() implementation.
	if req.Key != nil && *req.Key != "" {
		type EmailRecord struct {
			ID     uint64 `gorm:"column:id"`
			UserID uint64 `gorm:"column:userid"`
			Email  string `gorm:"column:email"`
		}

		var emails []EmailRecord
		// SECURITY: reject keys older than 7 days so a validatekey is not an indefinitely-valid
		// bearer credential (it can trigger an account merge below). NULL validatetime (legacy rows
		// predating this) stays accepted for compatibility; new keys always set it.
		db.Table("users_emails").Select("id, userid, email").
			Where("validatekey = ? AND (validatetime IS NULL OR validatetime > NOW() - INTERVAL 7 DAY)", *req.Key).
			Scan(&emails)

		if len(emails) == 0 {
			return c.JSON(fiber.Map{
				"ret":    11,
				"status": "Validation key not found",
			})
		}

		for _, mail := range emails {
			if mail.UserID != 0 && mail.UserID != myid {
				// Email belongs to another user — merge their account into
				// ours. Chat rooms need collision-safe consolidation (the
				// unique key on user1/user2/chattype forbids a blind
				// reassignment), so the whole merge runs transactionally in
				// MergeUserAccounts.
				if err := MergeUserAccounts(db, myid, mail.UserID); err != nil {
					// Rolled back — nothing half-applied, and the email is
					// still confirmed below so verification succeeds for the
					// user. The duplicate account survives; a later
					// verification or support action can merge it.
					stdlog.Printf("ERROR: merge of user %d into %d failed during email verify of %s: %v",
						mail.UserID, myid, mail.Email, err)
				} else {
					stdlog.Printf("Merged user %d into %d during email verify of %s", mail.UserID, myid, mail.Email)
				}
			}

			// Clear all preferred flags for this user, then set the confirmed email as preferred.
			db.Table("users_emails").Where("userid = ?", myid).Update("preferred", gorm.Expr("0"))
			// None of these four
			// assignments reference another assigned column, so the SET order
			// is not load-bearing and GORM's alphabetical Updates(map) order is
			// safe; see the retired check-set-order.sh /
			// setOrderIsLoadBearing (removed in d22ba1d6c).
			db.Table("users_emails").Where("id = ?", mail.ID).Updates(map[string]interface{}{
				"userid":      myid,
				"preferred":   gorm.Expr("1"),
				"validated":   gorm.Expr("NOW()"),
				"validatekey": gorm.Expr("NULL"),
			})

			// Confirming the key proves this address accepts mail: the member had to
			// receive the verification email to click the link. Clear the bounce
			// suspension, or a member who bounced and then promptly fixed their address
			// stays silently cut off - users.bouncing gates UnifiedDigestService,
			// UserManagementService and NotificationChaseUpService, and NOTHING else
			// resets it (the only other path is the manual, per-domain
			// UnbounceDomainCommand). Observed live: 47 members had validated
			// after bouncing - several within MINUTES - and were still suppressed.
			// Same two-part clear as UnbounceDomainCommand, which is the canonical
			// unbounce: the per-address timestamp (gates welcome mail via
			// whereNull('bounced')) and the per-user suspension flag.
			// Safe if the address later fails again: BounceService re-suspends.
			db.Table("users_emails").Where("id = ?", mail.ID).Update("bounced", gorm.Expr("NULL"))
			db.Table("users").Where("id = ?", myid).Update("bouncing", gorm.Expr("0"))
		}

		return c.JSON(fiber.Map{
			"ret":    0,
			"status": "Success",
		})
	}

	// Build a single UPDATE for all users table fields to avoid race conditions
	// between concurrent goroutines writing conflicting values to the same row.
	// For example, displayname sets firstname=NULL while a concurrent firstname
	// goroutine sets firstname to a value — the outcome was non-deterministic.
	//
	// ORM migration site 64dbb28e0d7b / f85b0b8ed693 - see
	// buildPatchSessionUpdateSet above for the SET list assembly itself,
	// factored out for fieldwise proof and built as a dynamic clause.Set.
	var settingsJSONStr *string
	var lastlocationID *uint64
	if req.Settings != nil {
		// Local scratch slices, NOT buildPatchSessionUpdateSet's shared clause.Set
		// every other field below is appended to - see buildPatchSessionUpdateSet's
		// doc comment for why isolating this call is what makes
		// ProcessSettingsUpdate's live-DB-read-gated decision inspectable without
		// changing its signature.
		var settingsPrefixClauses []string
		var settingsPrefixArgs []interface{}
		settingsJSON := user.ProcessSettingsUpdate([]byte(*req.Settings), myid, &settingsPrefixClauses, &settingsPrefixArgs)
		s := string(settingsJSON)
		settingsJSONStr = &s
		if len(settingsPrefixArgs) > 0 {
			// ProcessSettingsUpdate appends at most one clause
			// ("lastlocation = ?", newLocID as a uint64) when it detects a
			// postcode change - the same invariant user.go's PatchUser
			// (site 941509171a6e) relies on for its own call.
			if v, ok := settingsPrefixArgs[0].(uint64); ok {
				lastlocationID = &v
			}
		}
	}

	deletedNull := string(req.Deleted) == "null"
	if deletedNull {
		// Deletion writes a (User, Deleted) audit log; record the matching
		// reinstatement so mods can see the full story. The INSERT..SELECT only
		// fires if the account is actually flagged deleted right now (the UPDATE
		// clearing the flag runs after this), so a routine settings save that
		// happens to include deleted:null doesn't spam the log.
		database.InsertSelect(db, "logs",
			"(timestamp, type, subtype, user, byuser) "+
				"SELECT NOW(), ?, ?, id, id FROM users WHERE id = ? AND deleted IS NOT NULL",
			log2.LOG_TYPE_USER, log2.LOG_SUBTYPE_RESTORED, myid)
	}

	var relevantallowedInt, newslettersallowedInt *int
	if req.Relevantallowed != nil {
		v := int(*req.Relevantallowed)
		relevantallowedInt = &v
	}
	if req.Newslettersallowed != nil {
		v := int(*req.Newslettersallowed)
		newslettersallowedInt = &v
	}

	var marketingconsentInt *int
	if req.Marketingconsent != nil {
		mc := 0
		if req.Marketingconsent.Bool() {
			mc = 1
		}
		marketingconsentInt = &mc
	}

	// Execute single users table UPDATE if there are any changes.
	if set := buildPatchSessionUpdateSet(req.Displayname, req.Firstname, req.Lastname, settingsJSONStr,
		lastlocationID, req.Onholidaytill, relevantallowedInt, newslettersallowedInt, req.Source, deletedNull,
		marketingconsentInt); len(set) > 0 {
		if result := db.Table("users").Where("id = ?", myid).Clauses(set).Updates(map[string]interface{}{}); result.Error != nil {
			stdlog.Printf("Failed to update user %d: %v", myid, result.Error)
		}
	}

	// Run non-users-table operations in parallel (different tables, no conflicts).
	var wg sync.WaitGroup

	if req.Password != nil {
		wg.Add(1)
		go func() {
			defer wg.Done()
			salt := auth.GetPasswordSalt()
			hashed := auth.HashPassword(*req.Password, salt)
			uid := strconv.FormatUint(myid, 10)
			db.Table("users_logins").Clauses(clause.OnConflict{
				DoUpdates: clause.Assignments(map[string]interface{}{"credentials": hashed, "salt": salt}),
			}).Create(map[string]interface{}{
				"userid": myid, "type": utils.LOGIN_TYPE_NATIVE, "uid": uid, "credentials": hashed, "salt": salt,
			})
		}()
	}

	if req.Aboutme != nil {
		wg.Add(1)
		go func() {
			defer wg.Done()
			// Golden column order not
			// alphabetical, but normaliseColumnOrder handled the map-Create
			// reorder; see the retired ormharness's normalise_test.go
			// TestNormaliseColumnOrder_Insert (removed in d22ba1d6c).
			db.Table("users_aboutme").Create(map[string]interface{}{
				"userid":    myid,
				"text":      *req.Aboutme,
				"timestamp": gorm.Expr("NOW()"),
			})
		}()
	}

	if req.Notifications != nil && req.Notifications.Push != nil {
		wg.Add(1)
		apptype := "User"
		if c.Query("modtools") == "true" || c.Query("modtools") == "1" || req.Modtools.Bool() {
			apptype = "ModTools"
		}
		go func() {
			defer wg.Done()
			type PushSub struct {
				Type         string `json:"type"`
				Subscription string `json:"subscription"`
			}
			var pushSub PushSub
			if err := json.Unmarshal(*req.Notifications.Push, &pushSub); err == nil && pushSub.Type != "" {
				// subscription has a UNIQUE constraint and a push token (FCM/APNs)
				// identifies a device install, not a user. When a device switches
				// accounts the same token re-registers, so we MUST reassign userid
				// on conflict — otherwise the row stays bound to whoever logged in
				// first and the current user gets no push (and pushes for the old
				// user are delivered to this device). Reassign userid/type/apptype
				// to the currently-logged-in user.
				db.Table("users_push_notifications").Clauses(clause.OnConflict{
					DoUpdates: clause.Assignments(map[string]interface{}{
						"userid": myid, "type": pushSub.Type, "apptype": apptype,
					}),
				}).Create(map[string]interface{}{
					"userid": myid, "type": pushSub.Type, "subscription": pushSub.Subscription, "apptype": apptype,
				})
			}
		}()
	}

	if req.Email != nil && *req.Email != "" {
		// Queue verification email. Return ret=10 so the frontend shows the
		// "check your mailbox" modal.
		wg.Wait()

		if err := queue.QueueTask(queue.TaskEmailVerify, map[string]interface{}{
			"user_id": myid,
			"email":   strings.TrimSpace(*req.Email),
		}); err != nil {
			stdlog.Printf("Failed to queue email verify for user %d: %v", myid, err)
		}

		return c.JSON(fiber.Map{
			"ret":    10,
			"status": "We've sent a verification mail; please check your mailbox.",
		})
	}

	wg.Wait()

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}

// DeleteSession logs the user out by destroying their session.
//
// @Summary Logout
// @Tags session
// @Router /session [delete]
func DeleteSession(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	// Signal the auth middleware to skip the post-handler session check.
	// Without this, there's a race condition: the middleware's goroutine checks
	// that the session exists in DB, but the handler deletes the session before
	// the goroutine completes, causing a spurious 401.
	c.Locals("skipPostAuthCheck", true)

	if myid > 0 {
		db := database.DBConn

		// Log out the current login SERIES only (Discourse #9748: logout was
		// clearing every session, so logging out of Freegle also logged you out
		// of ModTools and every other device). Freegle and ModTools each get
		// their own random series at login, so deleting by series closes only the
		// current app and leaves the other app logged in.
		//
		// Identify the current session ROW authoritatively, then resolve its
		// series SERVER-SIDE. Do NOT trust a series value supplied by the client:
		// a persistent token minted before the 53-bit series mask (or otherwise
		// stale) can carry a 0 / wrong series, which previously dropped logout
		// into a "delete everything" fallback — the actual cause of #9748 still
		// failing. The session row id, by contrast, is a small stable integer
		// that survives a JSON round-trip through the browser intact.
		var sessionId uint64

		// Prefer the JWT (server-verified). It carries the session row id.
		if _, sid, _ := user.GetJWTFromRequest(c); sid > 0 {
			sessionId = sid
		}

		// Fall back to the persistent token's id (NOT its series).
		if sessionId == 0 {
			if persistent := c.Get("Authorization2"); persistent != "" {
				var pt auth.PersistentToken
				if json.Unmarshal([]byte(persistent), &pt) == nil {
					sessionId = pt.ID
				}
			}
		}

		var series uint64
		if sessionId > 0 {
			db.Table("sessions").Select("series").Where("id = ? AND userid = ?", sessionId, myid).Scan(&series)
		}

		if series > 0 {
			// Close the whole current login series (all its tabs/token rotations).
			db.Table("sessions").Where("userid = ? AND series = ?", myid, series).Delete(nil)
		} else if sessionId > 0 {
			// Series unavailable but the row is known — delete just that row.
			db.Table("sessions").Where("id = ? AND userid = ?", sessionId, myid).Delete(nil)
		}
		// If the current session cannot be identified at all, do NOT delete every
		// session for the user. A logout that can't scope itself must no-op rather
		// than evict the user from every device and app (Discourse #9748).
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}
