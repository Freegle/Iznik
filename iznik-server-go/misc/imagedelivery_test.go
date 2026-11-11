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
	// archived > 0: the full image from IMAGE_ARCHIVED_DOMAIN, and the thumbnail made from it by
	// the delivery resizer, because the tmimg_ thumbnail blobs are not copied off Azure.
	t.Setenv("IMAGE_ARCHIVED_DOMAIN", "archive.example.com")
	t.Setenv("IMAGE_DELIVERY", "https://delivery.test")

	full, thumb := BuildChatImageUrl(99, "", "", 1)
	assert.Equal(t, "https://archive.example.com/mimg_99.jpg", full)
	assert.Equal(t, "https://delivery.test?w=250&h=250&url=https%3A%2F%2Farchive.example.com%2Fmimg_99.jpg", thumb)
}

func TestArchivedImageUrlsStillOnAzureUseV1Thumbnails(t *testing.T) {
	// Until the address is switched to our archive, nothing about members' Azure traffic changes.
	t.Setenv("IMAGE_ARCHIVED_DOMAIN", "freegle.blob.core.windows.net/images")
	t.Setenv("IMAGE_DELIVERY", "https://delivery.test")

	full, thumb := ArchivedImageUrls("img", 7)
	assert.Equal(t, "https://freegle.blob.core.windows.net/images/img_7.jpg", full)
	assert.Equal(t, "https://freegle.blob.core.windows.net/images/timg_7.jpg", thumb)

	full, thumb = BuildChatImageUrl(8, "", "", 1)
	assert.Equal(t, "https://freegle.blob.core.windows.net/images/mimg_8.jpg", full)
	assert.Equal(t, "https://freegle.blob.core.windows.net/images/tmimg_8.jpg", thumb)
}

func TestArchivedImageUrlsWithAPathInTheDomain(t *testing.T) {
	// Production serves the archive from a path on an existing host.
	t.Setenv("IMAGE_ARCHIVED_DOMAIN", "images.example.com/archive")
	t.Setenv("IMAGE_DELIVERY", "https://delivery.test")

	for prefix, want := range map[string]string{"img": "img_5.jpg", "fimg": "fimg_5.jpg", "uimg": "uimg_5.jpg"} {
		full, thumb := ArchivedImageUrls(prefix, 5)
		assert.Equal(t, "https://images.example.com/archive/"+want, full)
		assert.Equal(t, "https://delivery.test?w=250&h=250&url=https%3A%2F%2Fimages.example.com%2Farchive%2F"+want, thumb)
		assert.NotContains(t, thumb, "/t"+want, "no link to a V1 thumbnail blob")
	}
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

func TestGetExternalImageDeliveryUrl(t *testing.T) {
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	defer os.Unsetenv("IMAGE_DELIVERY")

	url := GetExternalImageDeliveryUrl("https://photos.example.com/a b.jpg", "")
	assert.Equal(t, "https://delivery.test?url=https%3A%2F%2Fphotos.example.com%2Fa+b.jpg", url)
}

func TestGetExternalImageDeliveryUrlWithRotate(t *testing.T) {
	// V1 puts ro before url.
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	defer os.Unsetenv("IMAGE_DELIVERY")

	url := GetExternalImageDeliveryUrl("https://photos.example.com/a.jpg", `{"rotate":180}`)
	assert.Equal(t, "https://delivery.test?ro=180&url=https%3A%2F%2Fphotos.example.com%2Fa.jpg", url)
}

func TestGetExternalImageDeliveryUrlBadModsIgnored(t *testing.T) {
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	defer os.Unsetenv("IMAGE_DELIVERY")

	url := GetExternalImageDeliveryUrl("https://photos.example.com/a.jpg", "not json")
	assert.NotContains(t, url, "ro=")
}

func TestGetArchivedImageDeliveryUrl(t *testing.T) {
	// The archive branch of V1 Attachment::canRedirect: w & h & url, with the
	// archived-domain image URL escaped.
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	os.Setenv("IMAGE_ARCHIVED_DOMAIN", "archive.test")
	defer os.Unsetenv("IMAGE_DELIVERY")
	defer os.Unsetenv("IMAGE_ARCHIVED_DOMAIN")

	url := GetArchivedImageDeliveryUrl("img", 123, 250, 250)
	assert.Equal(t, "https://delivery.test?w=250&h=250&url=https%3A%2F%2Farchive.test%2Fimg_123.jpg", url)
}

func TestGetArchivedImageDeliveryUrlNoResize(t *testing.T) {
	os.Setenv("IMAGE_DELIVERY", "https://delivery.test")
	os.Setenv("IMAGE_ARCHIVED_DOMAIN", "archive.test")
	defer os.Unsetenv("IMAGE_DELIVERY")
	defer os.Unsetenv("IMAGE_ARCHIVED_DOMAIN")

	url := GetArchivedImageDeliveryUrl("mimg", 7, 0, 0)
	assert.Equal(t, "https://delivery.test?url=https%3A%2F%2Farchive.test%2Fmimg_7.jpg", url)
}
