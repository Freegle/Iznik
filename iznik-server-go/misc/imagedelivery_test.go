package misc

import (
	"os"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestBuildChatImageUrlLiveDomain(t *testing.T) {
	// No imageuid, archived=0 → live IMAGE_DOMAIN with mimg_/tmimg_ prefixes.
	orig := os.Getenv("IMAGE_DOMAIN")
	os.Setenv("IMAGE_DOMAIN", "images.example.com")
	defer os.Setenv("IMAGE_DOMAIN", orig)

	full, thumb := BuildChatImageUrl(42, "", "", 0)
	assert.Equal(t, "https://images.example.com/mimg_42.jpg", full)
	assert.Equal(t, "https://images.example.com/tmimg_42.jpg", thumb)
}

func TestBuildChatImageUrlArchivedDomain(t *testing.T) {
	// archived > 0 → IMAGE_ARCHIVED_DOMAIN instead of IMAGE_DOMAIN.
	orig := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	os.Setenv("IMAGE_ARCHIVED_DOMAIN", "archive.example.com")
	defer os.Setenv("IMAGE_ARCHIVED_DOMAIN", orig)

	full, thumb := BuildChatImageUrl(99, "", "", 1)
	assert.Equal(t, "https://archive.example.com/mimg_99.jpg", full)
	assert.Equal(t, "https://archive.example.com/tmimg_99.jpg", thumb)
}

func TestBuildChatImageUrlExternalDelivery(t *testing.T) {
	// Non-empty imageuid → external delivery URL for both full and thumb
	// (same URL is returned twice — wsrv/caching proxy decides the size).
	full, thumb := BuildChatImageUrl(0, "freegletusd-abc123", "", 0)
	assert.Equal(t, full, thumb)
	assert.Contains(t, full, "abc123")
	// Must NOT contain the "freegletusd-" prefix — it's stripped.
	assert.NotContains(t, full, "freegletusd-")
}

func TestGetImageDeliveryUrlDefaultEnv(t *testing.T) {
	// With DELIVERY and UPLOADS unset, fall back to the hard-coded defaults.
	origDelivery := os.Getenv("IMAGE_DELIVERY")
	origUploads := os.Getenv("UPLOADS")
	os.Unsetenv("IMAGE_DELIVERY")
	os.Unsetenv("UPLOADS")
	defer os.Setenv("IMAGE_DELIVERY", origDelivery)
	defer os.Setenv("UPLOADS", origUploads)

	url := GetImageDeliveryUrl("freegletusd-abcdef", "")
	assert.True(t, strings.HasPrefix(url, "https://delivery.ilovefreegle.org?url="))
	assert.Contains(t, url, "https://uploads.ilovefreegle.org:8080/abcdef")
}

func TestGetImageDeliveryUrlCustomEnv(t *testing.T) {
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	os.Setenv("UPLOADS", "https://uploads.test/")
	defer os.Unsetenv("IMAGE_DELIVERY")
	defer os.Unsetenv("UPLOADS")

	url := GetImageDeliveryUrl("freegletusd-xyz", "")
	assert.Contains(t, url, "https://delivery.test?url=https://uploads.test/xyz")
}

func TestGetImageDeliveryUrlDeliverySuffixStripped(t *testing.T) {
	// Backward-compat: IMAGE_DELIVERY may already include "?url=" suffix.
	// It must be stripped to avoid double ?url=.
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test?url=")
	os.Setenv("UPLOADS", "https://uploads.test/")
	defer os.Unsetenv("IMAGE_DELIVERY")
	defer os.Unsetenv("UPLOADS")

	url := GetImageDeliveryUrl("freegletusd-xyz", "")
	// Exactly one "?url=".
	assert.Equal(t, 1, strings.Count(url, "?url="))
}

func TestGetImageDeliveryUrlShortUIDNotTrimmed(t *testing.T) {
	// UIDs of length <= 12 are kept as-is (no prefix stripping).
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	os.Setenv("UPLOADS", "https://uploads.test/")
	defer os.Unsetenv("IMAGE_DELIVERY")
	defer os.Unsetenv("UPLOADS")

	short := "short"
	url := GetImageDeliveryUrl(short, "")
	assert.Contains(t, url, "https://uploads.test/"+short)
}

func TestGetImageDeliveryUrlWithRotateMods(t *testing.T) {
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	os.Setenv("UPLOADS", "https://uploads.test/")
	defer os.Unsetenv("IMAGE_DELIVERY")
	defer os.Unsetenv("UPLOADS")

	url := GetImageDeliveryUrl("freegletusd-xyz", `{"rotate":90}`)
	assert.Contains(t, url, "&ro=90")
}

func TestGetImageDeliveryUrlBadModsIgnored(t *testing.T) {
	// Malformed mods JSON must NOT panic or produce a broken URL —
	// the mods are silently dropped.
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	os.Setenv("UPLOADS", "https://uploads.test/")
	defer os.Unsetenv("IMAGE_DELIVERY")
	defer os.Unsetenv("UPLOADS")

	url := GetImageDeliveryUrl("freegletusd-xyz", `not json`)
	assert.NotContains(t, url, "&ro=")
}
