package user

import (
	"fmt"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/getsentry/sentry-go"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
)

type Config struct{}

// extractBearerToken returns the bearer token from the Authorization header, or "".
func extractBearerToken(c *fiber.Ctx) string {
	auth := c.Get("Authorization")
	if strings.HasPrefix(auth, "Bearer ") {
		return strings.TrimPrefix(auth, "Bearer ")
	}
	return ""
}

// tryParseHelperDelegateToken parses a raw JWT as a concierge helper_delegate
// token, returning (offerUserid, helpermsgid, true) on success. It lives in the
// user package (mirroring message/helpertoken.go) to avoid a message->user
// import cycle; the claim format is kept in sync (string-encoded claims,
// purpose=helper_delegate, HMAC, JWT_SECRET).
func tryParseHelperDelegateToken(tokenString string) (offerUserid uint64, helpermsgid uint64, ok bool) {
	if tokenString == "" {
		return 0, 0, false
	}
	secret := os.Getenv("JWT_SECRET")
	if secret == "" {
		return 0, 0, false
	}
	tok, err := jwt.Parse(tokenString, func(t *jwt.Token) (interface{}, error) {
		if _, ok := t.Method.(*jwt.SigningMethodHMAC); !ok {
			return nil, fmt.Errorf("unexpected signing method")
		}
		return []byte(secret), nil
	})
	if err != nil || !tok.Valid {
		return 0, 0, false
	}
	claims, ok2 := tok.Claims.(jwt.MapClaims)
	if !ok2 {
		return 0, 0, false
	}
	if purpose, _ := claims["purpose"].(string); purpose != "helper_delegate" {
		return 0, 0, false
	}
	uidStr, _ := claims["offeruserid"].(string)
	midStr, _ := claims["helpermsgid"].(string)
	var uid, mid uint64
	fmt.Sscanf(uidStr, "%d", &uid)
	fmt.Sscanf(midStr, "%d", &mid)
	if uid == 0 || mid == 0 {
		return 0, 0, false
	}
	return uid, mid, true
}

func NewAuthMiddleware(config Config) fiber.Handler {
	return func(c *fiber.Ctx) error {
		var userIdInDB struct {
			Id         uint64    `gorm:"id"`
			Lastaccess time.Time `gorm:"lastaccess"`
			Systemrole string    `gorm:"systemrole"`
		}

		userIdInJWT, sessionIdInJWT, _ := GetJWTFromRequest(c)

		var wg sync.WaitGroup
		var dbQueryErr error

		if userIdInJWT > 0 {
			// Flag our session for Sentry.
			sentry.ConfigureScope(func(scope *sentry.Scope) {
				scope.SetUser(sentry.User{ID: fmt.Sprint(userIdInJWT)})
			})

			// We have a valid JWT with a user id in it.  But is the user id still in our DB?  And do they still
			// have the same active session?
			wg.Add(1)
			db := database.DBConn

			go func() {
				defer wg.Done()

				// We have a uid.  Check if the user is still present in the DB.
				// Also fetch systemrole for HAProxy rate limit exemption.
				result := db.Raw("SELECT users.id, users.lastaccess, users.systemrole FROM sessions INNER JOIN users ON users.id = sessions.userid WHERE sessions.id = ? AND users.id = ? LIMIT 1;", sessionIdInJWT, userIdInJWT).Scan(&userIdInDB)
				dbQueryErr = result.Error
			}()
		} else {
			// No normal user JWT. If a helper_delegate bearer token is present,
			// expose the offerer identity + scoped msgid in locals so the bulk-offer
			// chat handler can act on the offerer's behalf for that one message and
			// enforce the per-message scope. Normal requests are unaffected.
			if rawTok := extractBearerToken(c); rawTok != "" {
				if offerUID, helperMID, hok := tryParseHelperDelegateToken(rawTok); hok {
					c.Locals("helperUserid", offerUID)
					c.Locals("helperMsgid", helperMID)
				}
			}
		}

		ret := c.Next()
		wg.Wait()

		if userIdInJWT > 0 && (userIdInDB.Id != userIdInJWT) && c.Locals("skipPostAuthCheck") == nil {
			if dbQueryErr != nil {
				// DB query failed (e.g. connection pool exhaustion, timeout) — the JWT
				// may still be valid.  Don't return 401 for a server-side problem.
				fmt.Printf("Auth middleware DB query failed for user %d: %v\n", userIdInJWT, dbQueryErr)
			} else {
				// Query succeeded but found no matching user/session — genuinely invalid JWT.
				// Only override if the handler actually used auth (checked WhoAmI and got
				// a non-zero result). Public endpoints that don't check auth should not
				// be broken by a stale JWT in the request.
				if c.Locals("authUsed") != nil {
					ret = fiber.NewError(fiber.StatusUnauthorized, "JWT for invalid user or session")
				}
			}
		}

		// Store the user's system role in locals for the Loki middleware to set X-User-Role header.
		// This allows HAProxy to exempt mods/support/admin from rate limiting.
		if userIdInDB.Systemrole != "" && userIdInDB.Systemrole != utils.SYSTEMROLE_USER {
			c.Locals("userRole", userIdInDB.Systemrole)
		}

		// Update the last access time for the user if it is null or older than ten minutes.
		if userIdInJWT > 0 && userIdInDB.Id > 0 && (userIdInDB.Lastaccess.IsZero() || userIdInDB.Lastaccess.Before(time.Now().Add(-10*time.Minute))) {
			db := database.DBConn
			db.Exec("UPDATE users SET lastaccess = NOW() WHERE id = ?", userIdInDB.Id)
		}

		// Refresh sessions.lastactive if older than 10 minutes — this gives the session
		// sliding expiry, matching V1 PHP behaviour. Without this the cron purge
		// (purge_sessions.php: DELETE WHERE lastactive < 31 days ago) would delete
		// active sessions 31 days after login regardless of recent use.
		if userIdInJWT > 0 && userIdInDB.Id > 0 {
			db := database.DBConn
			db.Exec("UPDATE sessions SET lastactive = NOW() WHERE id = ? AND lastactive < DATE_SUB(NOW(), INTERVAL 10 MINUTE)", sessionIdInJWT)
		}

		return ret
	}
}
