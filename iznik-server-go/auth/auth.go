// Package auth provides authentication and authorization primitives.
// It exists as a separate package from user to break a circular dependency:
// user imports location (for geo queries), and location needs auth functions.
// This package depends only on database/fiber/jwt — no user or location imports.
package auth

import (
	"crypto/sha1"
	"encoding/hex"
	json2 "encoding/json"
	"errors"
	"fmt"
	"log"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
)

// PersistentToken represents the old-style session token from the Authorization2 header.
type PersistentToken struct {
	ID     uint64 `json:"id"`
	Series uint64 `json:"series"`
	Token  string `json:"token"`
}

// WhoAmI returns the authenticated user ID from the request, or 0 if not logged in.
// It first tries JWT (fast), then falls back to the old-style persistent token.
func WhoAmI(c *fiber.Ctx) uint64 {
	id, _, _ := GetJWTFromRequest(c)

	// If we don't manage to get a user from the JWT, which is fast, then try the old-style persistent token which
	// is stored in the session table.
	persistent := c.Get("Authorization2")

	if id == 0 && len(persistent) > 0 {
		// Use a minimal struct so that old-format tokens whose Series field was
		// serialised as a JSON string ("12345") don't cause json.Unmarshal to
		// return a type-error that zeroes out the parsed ID.  id+token is a
		// sufficient authenticator without Series.
		var minPT struct {
			ID    uint64 `json:"id"`
			Token string `json:"token"`
		}
		_ = json2.Unmarshal([]byte(persistent), &minPT)

		if minPT.ID > 0 && minPT.Token != "" {
			// Verify token against sessions table
			db := database.DBConn

			type Userid struct {
				Userid uint64 `json:"userid"`
			}

			var userids []Userid
			// ORM migration site e57197b50601 (wave 1).
			db.Table("sessions").Select("userid").Where("id = ? AND token = ?", minPT.ID, minPT.Token).Limit(1).Scan(&userids)

			if len(userids) > 0 {
				id = userids[0].Userid
			}
		}
	}

	if id > 0 {
		// Signal to the auth middleware that this handler used auth — if the JWT
		// turns out to be stale the middleware should return 401.
		c.Locals("authUsed", true)
	}

	return id
}

// GetJWTFromRequest extracts user ID, session ID, and expiry from the JWT in the request.
func GetJWTFromRequest(c *fiber.Ctx) (uint64, uint64, float64) {
	// Passing JWT via URL parameters is not a great idea, but it's useful to support that for testing.
	tokenString := c.Query("jwt")

	if tokenString == "" {
		// No URL parameter found.  Try Authorization header.
		tokenString = c.Get("Authorization")
	}

	if tokenString != "" && len(tokenString) > 2 {
		// Check if there are leading and trailing quotes.  If so, strip them.
		if tokenString[0] == '"' {
			tokenString = tokenString[1:]
		}
		if tokenString[len(tokenString)-1] == '"' {
			tokenString = tokenString[:len(tokenString)-1]
		}

		token, err := jwt.Parse(string(tokenString), func(token *jwt.Token) (interface{}, error) {
			key := os.Getenv("JWT_SECRET")
			if _, ok := token.Method.(*jwt.SigningMethodHMAC); !ok {
				return nil, errors.New("unexpected signing method")
			}
			return []byte(key), nil
		})

		if err != nil {
			// JWT parse failures are expected for expired/malformed tokens; no action needed.
		} else if !token.Valid {
			// Invalid token; no action needed.
		} else {
			if claims, ok := token.Claims.(jwt.MapClaims); ok && token.Valid {
				// Get the expiry time.  Must be in the future otherwise the parse would have failed earlier.
				idi, oki := claims["id"]
				sessionidi, oks := claims["sessionid"]

				if oki && oks {
					idStr, idOk := idi.(string)
					sessionIdStr, sessOk := sessionidi.(string)

					if idOk && sessOk {
						id, _ := strconv.ParseUint(idStr, 10, 64)
						sessionId, _ := strconv.ParseUint(sessionIdStr, 10, 64)

						expVal, _ := claims["exp"].(float64)
						return id, sessionId, expVal
					}
				}
			}
		}
	}

	return 0, 0, 0
}

// IsAdminOrSupport checks if the user has Admin or Support system role.
func IsAdminOrSupport(myid uint64) bool {
	db := database.DBConn
	var systemrole string
	// ORM migration site e449ae451594 (wave 1).
	result := db.Table("users").Select("systemrole").Where("id = ?", myid).Scan(&systemrole)
	if result.Error != nil {
		log.Printf("Failed to check admin/support role for user %d: %v", myid, result.Error)
		return false
	}
	return systemrole == utils.SYSTEMROLE_SUPPORT || systemrole == utils.SYSTEMROLE_ADMIN
}

// IsChitChatMod reports whether the user may moderate ChitChat: a member of the
// ChitChat Moderation team, or support/admin.
//
// This is the audience for the ChitChat moderation tools — hiding a post, seeing
// the duplicate-of-their-own-post note, and converting a post into a real
// OFFER/WANTED on the member's behalf. Group moderators are deliberately NOT
// included: ChitChat is not group-scoped.
func IsChitChatMod(myid uint64) bool {
	if myid == 0 {
		return false
	}

	if IsAdminOrSupport(myid) {
		return true
	}

	db := database.DBConn
	var teamMemberCount int64
	db.Raw("SELECT COUNT(*) FROM teams_members tm INNER JOIN teams t ON tm.teamid = t.id WHERE t.name = 'ChitChat Moderation' AND tm.userid = ?", myid).Scan(&teamMemberCount)

	return teamMemberCount > 0
}

// IsAdmin checks if the user has the Admin systemrole.
func IsAdmin(myid uint64) bool {
	db := database.DBConn
	var systemrole string
	// ORM migration site 2c0bbedc0eb2 (wave 1).
	db.Table("users").Select("systemrole").Where("id = ?", myid).Scan(&systemrole)
	return systemrole == utils.SYSTEMROLE_ADMIN
}

// Permission constants matching the comma-separated permissions field in the users table.
const (
	PERM_GIFTAID             = "GiftAid"
	PERM_NEWSLETTER          = "Newsletter"
	PERM_NATIONAL_VOLUNTEERS = "NationalVolunteers"
	PERM_SPAM_ADMIN          = "SpamAdmin"
	PERM_TEAMS               = "Teams"
	PERM_BUSINESS_CARDS      = "BusinessCardsAdmin"
	PERM_CLEARANCE           = "Clearance"
)

// HasPermission checks if a user has a specific permission.
// Permissions are stored as a comma-separated string in the users.permissions column.
func HasPermission(userid uint64, perm string) bool {
	db := database.DBConn
	var permissions *string
	// ORM migration site 2f4a87c7cb53 (wave 1).
	db.Table("users").Select("permissions").Where("id = ?", userid).Scan(&permissions)
	if permissions == nil || *permissions == "" {
		return false
	}
	for _, p := range strings.Split(*permissions, ",") {
		if strings.EqualFold(strings.TrimSpace(p), perm) {
			return true
		}
	}
	return false
}

// IsSystemMod checks if the user has system-level Moderator, Support, or Admin role.
func IsSystemMod(myid uint64) bool {
	db := database.DBConn
	var systemrole string
	// ORM migration site 135b17140f36 (wave 1).
	result := db.Table("users").Select("systemrole").Where("id = ?", myid).Scan(&systemrole)
	if result.Error != nil {
		log.Printf("Failed to check system mod role for user %d: %v", myid, result.Error)
		return false
	}
	return systemrole == utils.SYSTEMROLE_MODERATOR || systemrole == utils.SYSTEMROLE_SUPPORT || systemrole == utils.SYSTEMROLE_ADMIN
}

// IsModOfGroup checks if the user is a Moderator or Owner of the given group, or is Admin/Support.
func IsModOfGroup(myid uint64, groupid uint64) bool {
	if IsAdminOrSupport(myid) {
		return true
	}

	if groupid == 0 {
		return false
	}

	db := database.DBConn
	var role string
	// ORM migration site a8691b8ac0e6 (wave 1).
	result := db.Table("memberships").Select("role").Where("userid = ? AND groupid = ?", myid, groupid).Scan(&role)
	if result.Error != nil {
		log.Printf("Failed to check mod role for user %d group %d: %v", myid, groupid, result.Error)
		return false
	}
	return role == utils.ROLE_MODERATOR || role == utils.ROLE_OWNER
}

// IsModOfAnyGroup checks if the user is a Moderator or Owner of any group, or is Admin/Support.
func IsModOfAnyGroup(myid uint64) bool {
	if IsAdminOrSupport(myid) {
		return true
	}

	db := database.DBConn
	var count int64
	// ORM migration site 401a7aa34330 (wave 1).
	db.Table("memberships").Where("userid = ? AND role IN (?, ?)", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Count(&count)
	return count > 0
}

// HashPassword computes sha1(password + salt).
func HashPassword(password, salt string) string {
	h := sha1.New()
	h.Write([]byte(password + salt))
	return hex.EncodeToString(h.Sum(nil))
}

// GetPasswordSalt returns the global password salt from env, with fallback default.
func GetPasswordSalt() string {
	salt := os.Getenv("PASSWORD_SALT")
	if salt == "" {
		salt = "zzzz"
	}
	return salt
}

// VerifyPassword checks a plaintext password against a user's stored Native login.
// We filter by userid only (not uid) because some legacy Native logins have NULL uid.
func VerifyPassword(userID uint64, password string) bool {
	db := database.DBConn

	var logins []struct {
		Credentials string
		Salt        string
	}
	// ORM migration site 8d14c119b9c8 (wave 1).
	db.Table("users_logins").Select("credentials, salt").Where("userid = ? AND type = ?", userID, utils.LOGIN_TYPE_NATIVE).Order("lastaccess DESC").Scan(&logins)

	for _, login := range logins {
		if login.Credentials == "" {
			continue
		}
		salt := login.Salt
		if salt == "" {
			salt = GetPasswordSalt()
		}
		hashed := HashPassword(password, salt)
		if strings.EqualFold(hashed, login.Credentials) {
			return true
		}
	}
	return false
}

// CreateSessionAndJWT creates a sessions row and returns the persistent token data and a JWT.
func CreateSessionAndJWT(userID uint64) (map[string]interface{}, string, error) {
	db := database.DBConn

	// series is a bigint unsigned column — must be numeric. A previous
	// RandomHex(16) value was coerced by MySQL to 0 or MAX uint64 on insert,
	// collapsing the UNIQUE KEY (id, series, token) and breaking the
	// per-device rotation premise.
	series := utils.RandomUint64()
	token := utils.RandomHex(16)

	// Read the new session id from the INSERT's LastInsertId on the write
	// connection. A "SELECT id ... WHERE userid ORDER BY id DESC LIMIT 1" here is
	// routed to a read replica by the read/write split and, under Galera's
	// cross-node apply window, can return the user's PREVIOUS session - so the
	// persistent token/JWT would carry the wrong session id (cf. message create,
	// Discourse 9832). db.DB() returns the source even with dbresolver registered.
	sqlDB, err := db.DB()
	if err != nil {
		return nil, "", fmt.Errorf("database error: %w", err)
	}
	res, err := sqlDB.Exec("INSERT INTO sessions (userid, series, token, date, lastactive) VALUES (?, ?, ?, NOW(), NOW())",
		userID, series, token)
	if err != nil {
		return nil, "", fmt.Errorf("failed to create session: %w", err)
	}
	lastID, err := res.LastInsertId()
	if err != nil || lastID <= 0 {
		return nil, "", fmt.Errorf("failed to create session")
	}
	sessionID := uint64(lastID)

	persistent := map[string]interface{}{
		"id":     sessionID,
		"series": series,
		"token":  token,
		"userid": userID,
	}

	jwtToken := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"id":        strconv.FormatUint(userID, 10),
		"sessionid": strconv.FormatUint(sessionID, 10),
		"exp":       time.Now().Unix() + 30*24*60*60, // 30 days
	})

	secret := os.Getenv("JWT_SECRET")
	jwtString, err := jwtToken.SignedString([]byte(secret))
	if err != nil {
		return nil, "", err
	}

	return persistent, jwtString, nil
}
