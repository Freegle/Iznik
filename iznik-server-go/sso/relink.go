package sso

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/gofiber/fiber/v2"
)

// Discourse knows a moderator by their Freegle user id (its external_id). A merge keeps one of
// the two accounts, and when the one that goes is the one Discourse was linked to, Discourse no
// longer knows the moderator: their email and name stop updating, and their next sign-in makes
// them a new, empty Discourse account. 62 moderators had merged accounts since 2019 when this
// was written; one (Jane L, 24 October 2026) reported it.
//
// RelinkMerged finds the Discourse account linked to an account merged into userID and links it
// to userID instead, then brings its email, name and avatar up to date. It needs no help from
// the moderator: they do not have to sign out of ModTools or Discourse.
// docs/developers/reference/account-merge.md.

var mergedLogPattern = regexp.MustCompile(`^Merged (\d+) into (\d+)`)

var relinkClient = &http.Client{Timeout: 10 * time.Second}

type discourseConfig struct {
	api, key, secret string
}

func discourseSettings() (discourseConfig, bool) {
	c := discourseConfig{
		api:    strings.TrimRight(os.Getenv("DISCOURSE_API"), "/"),
		key:    os.Getenv("DISCOURSE_APIKEY"),
		secret: os.Getenv("DISCOURSE_SECRET"),
	}
	return c, c.api != "" && c.key != "" && c.secret != ""
}

// RelinkMerged returns true when it re-linked an account. Without the Discourse settings, or
// when Discourse already knows userID, it does nothing.
func RelinkMerged(userID uint64) (bool, error) {
	cfg, ok := discourseSettings()
	if !ok || userID == 0 {
		return false, nil
	}

	if _, found, err := discourseUserByExternal(cfg, userID); err != nil || found {
		return false, err
	}

	for _, oldID := range mergedAwayIDs(userID) {
		username, found, err := discourseUserByExternal(cfg, oldID)
		if err != nil {
			return false, err
		}
		if !found {
			continue
		}

		email, err := discourseEmail(cfg, username)
		if err != nil || email == "" {
			return false, fmt.Errorf("no email for Discourse user %s: %v", username, err)
		}

		// Discourse finds no account for the new id, so it looks one up by email, finds this one
		// and links it to the new id. Sending the email it already has is what makes it match.
		link := url.Values{}
		link.Set("external_id", strconv.FormatUint(userID, 10))
		link.Set("email", email)
		link.Set("username", username)
		if err := syncSSO(cfg, link); err != nil {
			return false, fmt.Errorf("relink %s from %d to %d: %w", username, oldID, userID, err)
		}
		if _, linked, err := discourseUserByExternal(cfg, userID); err != nil || !linked {
			return false, fmt.Errorf("relink %s from %d to %d did not take: %v", username, oldID, userID, err)
		}

		// Now the moderator's current details: the preferred email, name and avatar.
		var systemrole string
		database.DBConn.Table("users").Select("COALESCE(systemrole, '')").Where("id = ?", userID).Scan(&systemrole)
		details := ssoParams(moderatorDetails(userID, systemrole))
		details.Set("external_id", strconv.FormatUint(userID, 10))
		if err := syncSSO(cfg, details); err != nil {
			log.Printf("[DiscourseRelink] %s linked to %d but details not updated: %v", username, userID, err)
		}

		log.Printf("[DiscourseRelink] Discourse user %s moved from merged account %d to %d", username, oldID, userID)
		return true, nil
	}

	return false, nil
}

// IsModerator is whether a user could have a Discourse account: a mod-level system role and an
// Owner or Moderator membership of a Freegle community, the same test as signing in.
func IsModerator(userID uint64) bool {
	db := database.DBConn
	var systemrole string
	db.Table("users").Select("COALESCE(systemrole, '')").Where("id = ?", userID).Scan(&systemrole)
	if !isModSystemrole(systemrole) {
		return false
	}
	var n int64
	db.Table("memberships").
		Joins("INNER JOIN `groups` ON memberships.groupid = `groups`.id").
		Where("memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') AND `groups`.type = 'Freegle'", userID).
		Count(&n)
	return n > 0
}

// mergedAwayIDs lists the accounts merged into userID, newest first. A merge moves the merged
// account's log rows to the kept one, so the kept account holds the whole chain: "Merged A into
// B" and later "Merged B into C" are both on C.
func mergedAwayIDs(userID uint64) []uint64 {
	var texts []string
	database.DBConn.Table("logs").
		Select("text").
		Where("user = ? AND type = ? AND subtype = ?", userID, flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_MERGED).
		Order("id DESC").
		Pluck("text", &texts)

	seen := map[uint64]bool{userID: true}
	var ids []uint64
	for _, t := range texts {
		m := mergedLogPattern.FindStringSubmatch(t)
		if m == nil {
			continue
		}
		for _, s := range m[1:] {
			id, err := strconv.ParseUint(s, 10, 64)
			if err == nil && !seen[id] {
				seen[id] = true
				ids = append(ids, id)
			}
		}
	}
	return ids
}

// discourseUserByExternal is the username of the Discourse account linked to a Freegle id.
func discourseUserByExternal(cfg discourseConfig, externalID uint64) (string, bool, error) {
	body, status, err := discourseGet(cfg, "/users/by-external/"+strconv.FormatUint(externalID, 10)+".json")
	if err != nil {
		return "", false, err
	}
	if status == http.StatusNotFound {
		return "", false, nil
	}
	if status != http.StatusOK {
		return "", false, fmt.Errorf("by-external %d: status %d", externalID, status)
	}
	var r struct {
		User struct {
			Username string `json:"username"`
		} `json:"user"`
	}
	if err := json.Unmarshal(body, &r); err != nil || r.User.Username == "" {
		return "", false, fmt.Errorf("by-external %d: unreadable answer", externalID)
	}
	return r.User.Username, true, nil
}

// discourseEmail is the primary email Discourse holds for a username.
func discourseEmail(cfg discourseConfig, username string) (string, error) {
	body, status, err := discourseGet(cfg, "/admin/users/list/all.json?show_emails=true&filter="+url.QueryEscape(username))
	if err != nil {
		return "", err
	}
	if status != http.StatusOK {
		return "", fmt.Errorf("user list: status %d", status)
	}
	var users []struct {
		Username string `json:"username"`
		Email    string `json:"email"`
	}
	if err := json.Unmarshal(body, &users); err != nil {
		return "", err
	}
	for _, u := range users {
		if strings.EqualFold(u.Username, username) {
			return u.Email, nil
		}
	}
	return "", nil
}

func syncSSO(cfg discourseConfig, params url.Values) error {
	payload := base64.StdEncoding.EncodeToString([]byte(params.Encode()))
	form := url.Values{}
	form.Set("sso", payload)
	form.Set("sig", computeHMAC(payload, cfg.secret))

	for attempt := 0; attempt < 5; attempt++ {
		req, err := http.NewRequest("POST", cfg.api+"/admin/users/sync_sso", strings.NewReader(form.Encode()))
		if err != nil {
			return err
		}
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		req.Header.Set("Api-Key", cfg.key)
		req.Header.Set("Api-Username", "system")
		resp, err := relinkClient.Do(req)
		if err != nil {
			return err
		}
		body, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		if resp.StatusCode == http.StatusTooManyRequests {
			time.Sleep(discourseWait(body))
			continue
		}
		if resp.StatusCode != http.StatusOK {
			return fmt.Errorf("sync_sso: status %d: %.200s", resp.StatusCode, body)
		}
		return nil
	}
	return fmt.Errorf("sync_sso: rate limited")
}

func discourseGet(cfg discourseConfig, path string) ([]byte, int, error) {
	for attempt := 0; attempt < 5; attempt++ {
		req, err := http.NewRequest("GET", cfg.api+path, nil)
		if err != nil {
			return nil, 0, err
		}
		req.Header.Set("Api-Key", cfg.key)
		req.Header.Set("Api-Username", "system")
		resp, err := relinkClient.Do(req)
		if err != nil {
			return nil, 0, err
		}
		body, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		if resp.StatusCode == http.StatusTooManyRequests {
			time.Sleep(discourseWait(body))
			continue
		}
		return body, resp.StatusCode, nil
	}
	return nil, 0, fmt.Errorf("GET %s: rate limited", path)
}

// discourseWait is how long a 429 says to wait, at most ten seconds.
func discourseWait(body []byte) time.Duration {
	var r struct {
		Extras struct {
			WaitSeconds float64 `json:"wait_seconds"`
		} `json:"extras"`
	}
	_ = json.Unmarshal(body, &r)
	w := time.Duration(r.Extras.WaitSeconds*float64(time.Second)) + 500*time.Millisecond
	if w > 10*time.Second {
		w = 10 * time.Second
	}
	return w
}

// RelinkRequest is the body of POST /discourse/relink.
type RelinkRequest struct {
	Userid uint64 `json:"userid"`
}

// RelinkHandler re-links a moderator's Discourse account after a merge, for Support and Admin.
// Merges re-link by themselves, and so does signing in to Discourse; this is for the accounts
// merged before either existed.
//
// @Summary Re-link a moderator's Discourse account after an account merge
// @Tags discourse
// @Accept json
// @Produce json
// @Param body body RelinkRequest true "The kept Freegle user"
// @Success 200 {object} map[string]interface{}
// @Router /discourse/relink [post]
func RelinkHandler(c *fiber.Ctx) error {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin only")
	}

	var req RelinkRequest
	if err := c.BodyParser(&req); err != nil || req.Userid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "userid required")
	}
	if _, ok := discourseSettings(); !ok {
		return fiber.NewError(fiber.StatusServiceUnavailable, "Discourse is not configured")
	}

	relinked, err := RelinkMerged(req.Userid)
	if err != nil {
		return fiber.NewError(fiber.StatusBadGateway, err.Error())
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "relinked": relinked})
}
