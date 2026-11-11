package misc

import (
	"strings"

	"github.com/gofiber/fiber/v2"
)

// ClientAddress returns the address of the machine that connected to haproxy. Use it for anything
// that decides something from where a request came from.
//
// It is not c.IP(). main.go sets ProxyHeader to X-Forwarded-For and does not validate it, so c.IP()
// returns the header exactly as sent: a client can write any leading entries, and haproxy only adds
// its own line after them. haproxy sets X-Real-IP to the peer address with set-header, which
// replaces whatever the client sent, so that is the one a client cannot choose. Without it (direct
// access, tests) the answer is the socket peer. Neither is ever read from X-Forwarded-For.
//
// getClientIP in lokiMiddleware.go is for log lines only, and trusts the leading X-Forwarded-For entry.
func ClientAddress(c *fiber.Ctx) string {
	if ip := strings.TrimSpace(c.Get("X-Real-IP")); ip != "" {
		return ip
	}

	return c.Context().RemoteIP().String()
}
