package session

import "time"

// webversionOlderThan reports whether an ISO-8601 webversion (the BUILD_DATE that
// every client sends on GET /session) is strictly older than minVersion, the
// configured minimum-build threshold.
//
// It is the comparison behind the server-side "app is out of date" kill switch.
// It fails open (returns false) whenever either value is empty or is not a
// parseable RFC3339/ISO-8601 timestamp:
//   - the threshold is empty until an operator arms it, so an unset config blocks
//     nobody;
//   - legacy clients send non-ISO webversions, which must never be blocked.
func webversionOlderThan(webversion, minVersion string) bool {
	if webversion == "" || minVersion == "" {
		return false
	}

	clientTime, err := time.Parse(time.RFC3339, webversion)
	if err != nil {
		return false
	}

	thresholdTime, err := time.Parse(time.RFC3339, minVersion)
	if err != nil {
		return false
	}

	return clientTime.Before(thresholdTime)
}

// minWebversionConfigKey returns the config key holding the minimum allowed build
// date for the requesting client. ModTools and the Freegle app/web are gated
// separately so each can be forced to update independently of the other: ModTools
// clients send the modtools flag on every request (added by the client BaseAPI),
// so they are gated on app_min_webversion_mt; everything else (the Freegle app and
// the website) uses app_min_webversion. A client that doesn't send the flag falls
// through to the Freegle key, which is the fail-safe direction.
func minWebversionConfigKey(modtools bool) string {
	if modtools {
		return "app_min_webversion_mt"
	}
	return "app_min_webversion"
}
