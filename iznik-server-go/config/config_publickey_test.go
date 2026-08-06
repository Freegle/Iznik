package config

import "testing"

// isPublicConfigKey decides what /config/:key will hand out without any
// authentication, so widening it accidentally exposes operational config to the
// world. These tests pin the allowlist: the ads feature flag, the two version
// prefixes, and nothing else.

func TestIsPublicConfigKey_AllowsTheFeatureFlag(t *testing.T) {
	for _, key := range []string{"ads_enabled"} {
		if !isPublicConfigKey(key) {
			t.Errorf("expected %q to be publicly readable", key)
		}
	}
}

func TestIsPublicConfigKey_AllowsVersionKeysByPrefix(t *testing.T) {
	// Matched by prefix so a new platform or variant needs no code change.
	for _, key := range []string{
		"app_fd_version_latest",
		"app_fd_version_date",
		"app_fd_version_required",
		"app_mt_version_latest",
		"app_mt_version_date",
		"app_mt_version_required",
		"app_fd_version_",
		"app_mt_version_something_added_later",
	} {
		if !isPublicConfigKey(key) {
			t.Errorf("expected version key %q to be publicly readable", key)
		}
	}
}

func TestIsPublicConfigKey_RejectsEverythingElse(t *testing.T) {
	for _, key := range []string{
		"",
		"ads_enabled ",           // trailing space is a different key
		"Ads_enabled",            // matching is case-sensitive
		"app_version_latest",     // real prefixes are app_fd_/app_mt_
		"app_fd_versionlatest",   // underscore is part of the prefix
		"xapp_fd_version_latest", // prefix must be at the start
		"modtools",
		"spam_threshold",
		"smtp_password",
	} {
		if isPublicConfigKey(key) {
			t.Errorf("expected %q NOT to be publicly readable", key)
		}
	}
}
