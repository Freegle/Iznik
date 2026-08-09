package user

import (
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/getsentry/sentry-go"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
	"sync"
	"time"
)

type Config struct{}

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
				//
				// ORM migration sites 4853849663f1 and e04bf70e7bee (Tier 3
				// keep-raw review). Both call sites render the same fixed
				// text - the extractor just could not fold it across the two
				// call sites - so each has exactly one rendered form, proven
				// by the retired ormharness (shapes.json /
				// TestTier3Shapes_4853849663f1 /
				// TestTier3Shapes_e04bf70e7bee, removed in d22ba1d6c).
				sessionQuery := func(tx *gorm.DB) *gorm.DB {
					return tx.Table("sessions").
						Select("users.id, users.lastaccess, users.systemrole").
						Joins("INNER JOIN users ON users.id = sessions.userid").
						Where("sessions.id = ? AND users.id = ?", sessionIdInJWT, userIdInJWT).
						Limit(1)
				}
				result := sessionQuery(db).Scan(&userIdInDB)
				dbQueryErr = result.Error

				// Read/write split: the session row is INSERTed on the write host at login, so a
				// read replica can momentarily return zero rows right after login (Galera apply
				// lag). That would make the check below wrongly 401 a valid session. On a miss,
				// confirm against the write host before treating the session as invalid.
				// .Clauses(dbresolver.Write) is a no-op when no replica is configured.
				if dbQueryErr == nil && userIdInDB.Id == 0 {
					result = sessionQuery(db.Clauses(dbresolver.Write)).Scan(&userIdInDB)
					dbQueryErr = result.Error
				}
			}()
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
		// The throttle MUST live in the SQL guard (matching sessions.lastactive below), not
		// only in the app-side check: N parallel requests all read the same stale value, all
		// pass the check, and all UPDATE the same row — and with writes sprayed across the
		// Galera hosts those same-row writes cause certification conflicts (387ms avg,
		// ~197 DB-hours/10d — plans/2026-07-17-db3-cpu-reach-sql-prefilter.md, adjacent
		// fix 1). With the guard, the racers match zero rows and are cheap no-ops. The
		// app-side staleness check is kept as a fast path only: it skips issuing the
		// statement at all when the auth SELECT already saw a fresh value.
		if userIdInJWT > 0 && userIdInDB.Id > 0 && (userIdInDB.Lastaccess.IsZero() || userIdInDB.Lastaccess.Before(time.Now().Add(-10*time.Minute))) {
			db := database.DBConn
			db.Table("users").Where("id = ? AND (lastaccess IS NULL OR lastaccess < DATE_SUB(NOW(), INTERVAL 10 MINUTE))", userIdInDB.Id).
				Update("lastaccess", gorm.Expr("NOW()"))
		}

		// Refresh sessions.lastactive if older than 10 minutes — this gives the session
		// sliding expiry, matching V1 PHP behaviour. Without this the cron purge
		// (purge_sessions.php: DELETE WHERE lastactive < 31 days ago) would delete
		// active sessions 31 days after login regardless of recent use.
		if userIdInJWT > 0 && userIdInDB.Id > 0 {
			db := database.DBConn
			db.Table("sessions").Where("id = ? AND lastactive < DATE_SUB(NOW(), INTERVAL 10 MINUTE)", sessionIdInJWT).
				Update("lastactive", gorm.Expr("NOW()"))
		}

		return ret
	}
}
