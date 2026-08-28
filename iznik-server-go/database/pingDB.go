package database

import (
	"database/sql"
	"fmt"
	"os"
	"sync"
	"time"

	"github.com/gofiber/fiber/v2"
)

type Config struct {
}

// reconnectMutex single-flights reconnection. Without it, every request whose
// ping failed called Close() and InitDatabase() concurrently: N goroutines
// rebuilding and republishing the shared DBConn while other requests were
// mid-query on it. A burst of 23 failed pings did exactly that on db1
// (2026-08-28) and took the process down with a segfault in gorm internals.
var reconnectMutex sync.Mutex

func NewPingMiddleware(config Config) fiber.Handler {
	return func(c *fiber.Ctx) error {
		conn := DBConn
		if conn == nil || conn.Statement == nil {
			return c.Next()
		}
		db, err := conn.DB()
		if err != nil || db == nil {
			return c.Next()
		}

		// Ping the connection to make sure it's ok and re-establish if need be.  We've seen ourselves get stuck
		// in a state where the connection is dead and all requests fail.
		err = db.Ping()

		if err != nil {
			reconnectMutex.Lock()
			if DBConn == conn {
				// First goroutine in does the reconnect; the rest arrive here
				// after the swap, see a fresh DBConn and only re-verify it.
				fmt.Println("Ping failed, reconnecting")
				InitDatabase()

				// Retire the old pool only after in-flight queries have had
				// time to finish. Closing it immediately (as this used to)
				// yanks connections from under requests still using them.
				// Shrink it right away though: the old and new pools briefly
				// coexist against MySQL's max_connections, so idle
				// connections must not linger for the grace period.
				db.SetMaxIdleConns(0)
				db.SetConnMaxLifetime(time.Second)
				go func(old *sql.DB) {
					time.Sleep(time.Minute)
					old.Close()
				}(db)
			}
			cur := DBConn
			reconnectMutex.Unlock()

			newDb, newErr := cur.DB()
			if newErr != nil || newDb == nil || newDb.Ping() != nil {
				fmt.Println("Reconnect failed")
				os.Exit(1)
			}
		}

		return c.Next()
	}
}
