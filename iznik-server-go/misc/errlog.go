package misc

import (
	"errors"

	"github.com/gofiber/fiber/v2"
)

// ErrorLogLabel says how an error reaching the app's ErrorHandler should be
// logged, and returns "" for the ones not worth a line.
//
// Fiber sends connection-level failures through the SAME ErrorHandler as route
// errors, with no request parsed: fasthttp hands it a net.Error and fiber's
// serverErrorHandler maps anything that is not a timeout to the ErrBadGateway
// sentinel (fiber v2.52.5 app.go:1084). Such an error arrives with a blank
// context, so it reads as "GET /" whatever the client actually sent.
//
// Nothing in this binary returns 502 itself, so that sentinel always means a
// client went away mid-request rather than a response we served. Logging it as
// SERVER ERROR put ~12 lines a minute of load-balancer and scanner resets into
// the same stream as real failures, which is what hides a genuine 500.
// Reproduce with a socket that writes a partial request line then closes with
// SO_LINGER 0: it logs one 502 for "GET /" and serves nobody.
func ErrorLogLabel(err error, code int) string {
	switch {
	case errors.Is(err, fiber.ErrBadGateway):
		return "CLIENT CONNECTION DROPPED"
	case code >= 500:
		return "SERVER ERROR"
	case code == fiber.StatusBadRequest:
		return "BAD REQUEST"
	default:
		return ""
	}
}
