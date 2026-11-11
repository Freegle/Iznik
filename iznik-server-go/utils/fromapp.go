package utils

import (
	"net/url"

	"github.com/gofiber/fiber/v2"
)

// RequestFromApp says whether a request came from the Freegle iOS or Android app. The app is the website
// bundled into a Capacitor WebView, which loads it from https://localhost on Android and
// capacitor://localhost on iOS, so its Origin (or, failing that, its Referer) names that host with no port.
// The website never does, and a developer's local site is http:// with a port.
//
// UX experiments are not run in the app, so server code that decides an arm asks this first.
func RequestFromApp(c *fiber.Ctx) bool {
	from := c.Get(fiber.HeaderOrigin)
	if from == "" {
		from = c.Get(fiber.HeaderReferer)
	}
	return originIsApp(from)
}

func originIsApp(from string) bool {
	if from == "" {
		return false
	}
	u, err := url.Parse(from)
	if err != nil || u.Host != "localhost" {
		return false
	}
	switch u.Scheme {
	case "https", "capacitor", "ionic":
		return true
	}
	return false
}
