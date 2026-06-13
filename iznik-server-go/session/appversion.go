package session

import "time"

// webversionOlderThan reports whether an ISO-8601 webversion (the BUILD_DATE that
// every client sends on GET /session) is strictly older than minVersion, the
// configured app_min_webversion threshold.
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
