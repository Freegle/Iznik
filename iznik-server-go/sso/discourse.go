package sso

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"net/url"
	"os"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// ssoSession holds the user data we need after session validation.
type ssoSession struct {
	UserID    uint64
	Name      string
	AvatarURL string
	Admin     bool
	Email     string
	GroupList string
	IsMod     bool
}

// modtoolsDiscoursePage is the ModTools page that copies the browser's
// persistent token into the Iznik-Discourse-SSO cookie and retries this
// endpoint with the same sso/sig (Netlify preserves the query string).
const modtoolsDiscoursePage = "https://modtools.org/discourse"

// ssoFailure says why a request that DID carry a cookie could not be turned
// into a Discourse login. It reaches the ModTools page as ?ssoerror=<value>,
// and the page shows the moderator a message instead of retrying: the same
// cookie would get the same answer, so a retry can only loop.
type ssoFailure string

const (
	// ssoFailureSession: the cookie could not be parsed, or no live session
	// row matches its id and token (logged out elsewhere, token rotated).
	ssoFailureSession ssoFailure = "session"
	// ssoFailureNotMod: the session is live but the account is not a
	// moderator - its system role is not mod-level, or it holds no Owner or
	// Moderator membership on any Freegle community.
	ssoFailureNotMod ssoFailure = "notmod"
)

// ssoErrorURL is where a refused cookie-bearing request is sent.
func ssoErrorURL(f ssoFailure) string {
	return modtoolsDiscoursePage + "?ssoerror=" + string(f)
}

// isModSystemrole reports whether a users.systemrole value is mod-level.
func isModSystemrole(systemrole string) bool {
	return systemrole == utils.SYSTEMROLE_ADMIN ||
		systemrole == utils.SYSTEMROLE_SUPPORT ||
		systemrole == utils.SYSTEMROLE_MODERATOR
}

// DiscourseSSO handles the Discourse SSO login flow.
// This is the Go equivalent of the legacy V1 PHP Discourse SSO handler.
//
// Discourse sends GET with `sso` and `sig` query params. We validate the signature,
// look up the user from the Iznik-Discourse-SSO cookie, verify they are a Freegle moderator,
// and redirect back to Discourse with the signed SSO response.
//
// @Summary Discourse SSO login
// @Tags sso
// @Param sso query string true "Base64-encoded SSO payload"
// @Param sig query string true "HMAC-SHA256 signature"
// @Success 302
// @Router /discourse_sso [get]
func DiscourseSSO(c *fiber.Ctx) error {
	ssoPayload := c.Query("sso")
	sig := c.Query("sig")

	log.Printf("[DiscourseSSO] Received SSO request")

	secret := os.Getenv("DISCOURSE_SECRET")
	if secret == "" {
		log.Printf("[DiscourseSSO] DISCOURSE_SECRET not set")
		return c.Status(fiber.StatusInternalServerError).SendString("SSO not configured")
	}

	// Validate the HMAC-SHA256 signature.
	if !validateHMAC(ssoPayload, sig, secret) {
		log.Printf("[DiscourseSSO] Invalid signature")
		return c.Status(fiber.StatusForbidden).SendString("Invalid signature")
	}

	log.Printf("[DiscourseSSO] Signature validated")

	// Decode the payload to get the nonce.
	nonce, err := extractNonce(ssoPayload)
	if err != nil {
		log.Printf("[DiscourseSSO] Failed to extract nonce: %v", err)
		return c.Status(fiber.StatusBadRequest).SendString("Invalid SSO payload")
	}

	// Look up user from the Iznik-Discourse-SSO cookie.
	cookieValue := c.Cookies("Iznik-Discourse-SSO")
	if cookieValue == "" {
		// The only case the ModTools page should retry: it sets the cookie
		// and comes straight back with the same nonce.
		log.Printf("[DiscourseSSO] No cookie, redirecting to ModTools to set one")
		return c.Redirect(modtoolsDiscoursePage, fiber.StatusFound)
	}

	// Cookie value may be URL-encoded (browsers sometimes encode JSON cookies).
	if decoded, err2 := url.QueryUnescape(cookieValue); err2 == nil {
		cookieValue = decoded
	}

	session, failure, err := validateDiscourseSession(cookieValue)
	if err != nil {
		log.Printf("[DiscourseSSO] Refused (%s): %v", failure, err)
		return c.Redirect(ssoErrorURL(failure), fiber.StatusFound)
	}

	// Build the SSO response.
	responsePayload := buildSSOResponse(nonce, session)
	encodedPayload := base64.StdEncoding.EncodeToString([]byte(responsePayload))
	responseSig := computeHMAC(encodedPayload, secret)

	redirectURL := fmt.Sprintf("https://discourse.ilovefreegle.org/session/sso_login?sso=%s&sig=%s",
		url.QueryEscape(encodedPayload), url.QueryEscape(responseSig))

	log.Printf("[DiscourseSSO] Logged in %s, redirecting to Discourse", session.Name)
	return c.Redirect(redirectURL, fiber.StatusFound)
}

// parseSSOCookie extracts the session id and token from the Iznik-Discourse-SSO
// cookie. Series is deliberately ignored: PR #679 changed it from a JSON string
// to a JSON number, so a struct field of either type would break on the other —
// dropping it from the parse struct makes the cookie format-agnostic. Pure (no
// DB), so the numeric-series regression is unit-testable without a connection.
func parseSSOCookie(cookieValue string) (id uint64, token string, err error) {
	var cookie struct {
		ID    uint64 `json:"id"`
		Token string `json:"token"`
	}
	if err := json.Unmarshal([]byte(cookieValue), &cookie); err != nil {
		return 0, "", fmt.Errorf("invalid cookie JSON: %w", err)
	}
	if cookie.ID == 0 || cookie.Token == "" {
		return 0, "", fmt.Errorf("incomplete cookie data")
	}
	return cookie.ID, cookie.Token, nil
}

// validateDiscourseSession turns the Iznik-Discourse-SSO cookie into the
// moderator it belongs to. On refusal it also returns which ssoFailure class
// applies, so the caller can send the moderator to a page that explains it.
//
// Authentication uses id+token only. The series field is intentionally ignored:
// PR #679 (2026-06-09) changed the session emitter to output series as a JSON
// number (uint64) rather than a string, which caused json.Unmarshal to error
// when trying to decode a number into a string field - producing an infinite
// ModTools <-> Discourse redirect loop for all moderators. Authenticating by
// id+token alone (matching the approach in auth/auth.go WhoAmI) is sufficient
// because token is a cryptographically random secret.
func validateDiscourseSession(cookieValue string) (*ssoSession, ssoFailure, error) {
	cookieID, cookieToken, err := parseSSOCookie(cookieValue)
	if err != nil {
		return nil, ssoFailureSession, err
	}

	db := database.DBConn

	// Find the session with no role filter, so that "no such session" and
	// "not a moderator" are told apart - the moderator needs a different
	// message for each.
	var userID uint64
	var systemrole string
	row := db.Table("sessions").
		Select("sessions.userid, users.systemrole").
		Joins("INNER JOIN users ON sessions.userid = users.id").
		Where("sessions.id = ? AND sessions.token = ?", cookieID, cookieToken).
		Row()
	if err := row.Scan(&userID, &systemrole); err != nil || userID == 0 {
		return nil, ssoFailureSession, fmt.Errorf("no live session for cookie id %d", cookieID)
	}

	// Double gate, kept deliberately: a mod-level system role AND a real
	// Owner/Moderator membership on a Freegle community. A stale system role
	// on its own grants nothing.
	if !isModSystemrole(systemrole) {
		return nil, ssoFailureNotMod, fmt.Errorf("user %d has system role %s", userID, systemrole)
	}

	var freegleGroupCount int64
	db.Table("memberships").
		Joins("INNER JOIN `groups` ON memberships.groupid = `groups`.id").
		Where("memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') AND `groups`.type = 'Freegle'", userID).
		Count(&freegleGroupCount)

	if freegleGroupCount == 0 {
		return nil, ssoFailureNotMod, fmt.Errorf("user %d is not a moderator of a Freegle group", userID)
	}

	// Get user details.
	var fullname string
	db.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", userID).Scan(&fullname)

	var email string
	db.Table("users_emails").Select("email").Where("userid = ?", userID).Order("preferred DESC").Limit(1).Scan(&email)

	var profileURL string
	db.Table("users_images").Select("url").Where("userid = ?", userID).Order("id DESC").Limit(1).Scan(&profileURL)

	// Get group list - try active mod groups first, fall back to all moderatorships.
	groupList := getModGroupList(userID)

	return &ssoSession{
		UserID:    userID,
		Name:      fullname,
		AvatarURL: profileURL,
		Admin:     systemrole == utils.SYSTEMROLE_ADMIN,
		Email:     email,
		GroupList: groupList,
		IsMod:     true,
	}, "", nil
}

// getModGroupList returns a comma-separated list of group display names for a moderator.
func getModGroupList(userID uint64) string {
	db := database.DBConn

	type GroupName struct {
		NameDisplay string `gorm:"column:namedisplay"`
	}

	var groups []GroupName
	db.Table("`groups`").
		Select("COALESCE(namefull, nameshort) AS namedisplay").
		Joins("INNER JOIN memberships ON memberships.groupid = `groups`.id").
		Where("memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') AND `groups`.type = 'Freegle'", userID).
		Scan(&groups)

	names := make([]string, 0, len(groups))
	for _, g := range groups {
		names = append(names, g.NameDisplay)
	}

	result := strings.Join(names, ",")
	if len(result) > 1000 {
		result = result[:1000]
	}

	return result
}

// validateHMAC checks that the HMAC-SHA256 of the payload matches the signature.
func validateHMAC(payload, signature, secret string) bool {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(payload))
	expected := hex.EncodeToString(mac.Sum(nil))
	return hmac.Equal([]byte(expected), []byte(signature))
}

// computeHMAC returns the hex-encoded HMAC-SHA256 of the data.
func computeHMAC(data, secret string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(data))
	return hex.EncodeToString(mac.Sum(nil))
}

// extractNonce decodes the base64 SSO payload and extracts the nonce value.
func extractNonce(ssoPayload string) (string, error) {
	decoded, err := base64.StdEncoding.DecodeString(ssoPayload)
	if err != nil {
		return "", fmt.Errorf("base64 decode failed: %w", err)
	}

	values, err := url.ParseQuery(string(decoded))
	if err != nil {
		return "", fmt.Errorf("query parse failed: %w", err)
	}

	nonce := values.Get("nonce")
	if nonce == "" {
		return "", fmt.Errorf("no nonce in payload")
	}

	return nonce, nil
}

// buildSSOResponse builds the query string for the Discourse SSO response.
func buildSSOResponse(nonce string, session *ssoSession) string {
	bio := session.Email + " \r\n\r\nis a mod on " + session.GroupList

	params := url.Values{}
	params.Set("nonce", nonce)
	params.Set("email", session.Email)
	params.Set("external_id", fmt.Sprint(session.UserID))
	params.Set("username", session.Name)
	params.Set("name", session.Name)
	params.Set("avatar_url", session.AvatarURL)
	if session.Admin {
		params.Set("admin", "true")
	} else {
		params.Set("admin", "false")
	}
	params.Set("bio", bio)

	return params.Encode()
}
