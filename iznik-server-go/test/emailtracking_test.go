package test

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/emailtracking"
	"github.com/stretchr/testify/assert"
)

// encodeCompactID mirrors the batch encodeId() that DecodeID reverses: the id's
// minimal big-endian bytes, base64url-encoded without padding.
func encodeCompactID(id uint64) string {
	if id == 0 {
		return "0"
	}
	var b [8]byte
	binary.BigEndian.PutUint64(b[:], id)
	i := 0
	for i < 8 && b[i] == 0 {
		i++
	}
	return base64.RawURLEncoding.EncodeToString(b[i:])
}

// getTestUserSite returns the user site URL for tests
func getTestUserSite() string {
	site := os.Getenv("USER_SITE")
	if site == "" {
		site = "www.ilovefreegle.org"
	}
	return "https://" + site
}

// getTestImageDomain returns the image domain URL for tests
func getTestImageDomain() string {
	domain := os.Getenv("IMAGE_DOMAIN")
	if domain == "" {
		domain = "images.ilovefreegle.org"
	}
	return "https://" + domain
}

// createTestTrackingRecord creates a test email tracking record for testing
func createTestTrackingRecord(t *testing.T) *emailtracking.EmailTracking {
	db := database.DBConn

	tracking := &emailtracking.EmailTracking{
		TrackingID:     "test-" + randomString(16),
		EmailType:      "Test",
		RecipientEmail: "test@example.com",
	}

	result := db.Create(tracking)
	assert.NoError(t, result.Error)

	return tracking
}

// randomString generates a random string for test tracking IDs
func randomString(n int) string {
	const letters = "abcdefghijklmnopqrstuvwxyz0123456789"
	b := make([]byte, n)
	for i := range b {
		b[i] = letters[i%len(letters)]
	}
	return string(b)
}

// cleanupTestTracking removes test tracking records
func cleanupTestTracking(t *testing.T, trackingID string) {
	db := database.DBConn
	db.Where("tracking_id = ?", trackingID).Delete(&emailtracking.EmailTracking{})
}

func TestEmailTrackingPixel(t *testing.T) {
	// Create test tracking record
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	// Request the tracking pixel using bland path
	req := httptest.NewRequest("GET", "/e/d/p/"+tracking.TrackingID, nil)
	resp, err := getApp().Test(req)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusOK, resp.StatusCode)
	assert.Equal(t, "image/gif", resp.Header.Get("Content-Type"))
	assert.Equal(t, "no-store, no-cache, must-revalidate, max-age=0", resp.Header.Get("Cache-Control"))

	// Verify the tracking record was updated
	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)

	assert.NotNil(t, updated.OpenedAt)
	assert.Equal(t, "pixel", *updated.OpenedVia)
}

func TestEmailTrackingPixelInvalidID(t *testing.T) {
	// Request with non-existent tracking ID
	req := httptest.NewRequest("GET", "/e/d/p/nonexistent123", nil)
	resp, err := getApp().Test(req)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusOK, resp.StatusCode) // Still returns GIF
	assert.Equal(t, "image/gif", resp.Header.Get("Content-Type"))
}

func TestEmailTrackingClick(t *testing.T) {
	// Create test tracking record
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	destinationURL := getTestUserSite() + "/give"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(destinationURL))

	// Request click tracking using bland path
	req := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+encodedURL+"&p=cta_button&a=cta", nil)
	resp, err := getApp().Test(req, -1) // -1 to not follow redirects

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode) // 302 redirect
	assert.Equal(t, destinationURL, resp.Header.Get("Location"))

	// Verify the tracking record was updated
	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)

	assert.NotNil(t, updated.OpenedAt)
	assert.Equal(t, "click", *updated.OpenedVia)
	assert.NotNil(t, updated.ClickedAt)
	assert.Equal(t, destinationURL, *updated.ClickedLink)
	assert.Equal(t, uint16(1), updated.LinksClicked)

	// Verify click record was created
	var clicks []emailtracking.EmailTrackingClick
	db.Where("email_tracking_id = ?", updated.ID).Find(&clicks)
	assert.Equal(t, 1, len(clicks))
	assert.Equal(t, destinationURL, clicks[0].LinkURL)
	assert.Equal(t, "cta_button", *clicks[0].LinkPosition)
	assert.Equal(t, "cta", *clicks[0].Action)
}

func TestEmailTrackingClickInvalidURL(t *testing.T) {
	// Create test tracking record
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	// Request with empty URL using bland path
	req := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID, nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, "/", resp.Header.Get("Location")) // Redirects to home
}

// signRedirectURL mirrors the batch EmailTracking::getTrackedLinkUrl HMAC:
// hex(hmac-sha256("redirect:" + url, AMP_SECRET)).
func signRedirectURL(url string, secret string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("redirect:" + url))
	return hex.EncodeToString(mac.Sum(nil))
}

func TestEmailTrackingClickExternalURLUnsigned(t *testing.T) {
	// An external destination without a signature must bounce to home, not
	// redirect - the endpoint would otherwise be an open redirect.
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	destinationURL := "https://www.parkrun.org.uk/sunnyhill/"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(destinationURL))

	req := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+encodedURL+"&p=item_1&a=item", nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, "/", resp.Header.Get("Location"))
}

func TestEmailTrackingClickExternalURLSigned(t *testing.T) {
	// A signed external destination (Community News items link to arbitrary
	// external sites) redirects and records the click.
	t.Setenv("AMP_SECRET", "test-link-signing-secret")

	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	destinationURL := "https://www.parkrun.org.uk/sunnyhill/"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(destinationURL))
	sig := signRedirectURL(destinationURL, "test-link-signing-secret")

	req := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+encodedURL+"&sig="+sig+"&p=item_1&a=item", nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, destinationURL, resp.Header.Get("Location"))

	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)
	assert.NotNil(t, updated.ClickedAt)
	assert.Equal(t, destinationURL, *updated.ClickedLink)
}

func TestEmailTrackingClickExternalURLCuratedCommunityNewsItem(t *testing.T) {
	// Links in Community News emails sent before signing existed carry no
	// signature - but their destinations are curated community_news_items
	// rows, which the handler accepts as a third chance.
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	db := database.DBConn
	destinationURL := "https://www.parkrun.org.uk/legacy-unsigned-" + tracking.TrackingID + "/"

	// community_news_items.areaid has an FK to community_news_areas, so
	// create a throwaway area first; deleting it cascades to the item.
	db.Exec("INSERT INTO community_news_areas (anchorgroupid, name, lat, lng, groupids) VALUES (?, ?, ?, ?, ?)",
		999999901, "Test area", 51.5, -0.1, "[]")
	var areaID uint64
	db.Raw("SELECT id FROM community_news_areas WHERE anchorgroupid = ?", 999999901).Scan(&areaID)
	defer db.Exec("DELETE FROM community_news_areas WHERE id = ?", areaID)

	db.Exec("INSERT INTO community_news_items (areaid, title, snippet, url) VALUES (?, ?, ?, ?)",
		areaID, "Test item", "Test snippet", destinationURL)

	encodedURL := base64.StdEncoding.EncodeToString([]byte(destinationURL))

	req := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+encodedURL+"&p=item_1&a=item", nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, destinationURL, resp.Header.Get("Location"))
}

func TestEmailTrackingClickExternalURLBadSignature(t *testing.T) {
	// A wrong signature must not unlock the redirect.
	t.Setenv("AMP_SECRET", "test-link-signing-secret")

	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	destinationURL := "https://www.parkrun.org.uk/sunnyhill/"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(destinationURL))
	sig := signRedirectURL("https://some-other-url.example.com/", "test-link-signing-secret")

	req := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+encodedURL+"&sig="+sig, nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, "/", resp.Header.Get("Location"))
}

func TestEmailTrackingImage(t *testing.T) {
	// Create test tracking record
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	imageURL := getTestImageDomain() + "/test.jpg"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(imageURL))

	// Request image tracking using bland path
	req := httptest.NewRequest("GET", "/e/d/i/"+tracking.TrackingID+"?url="+encodedURL+"&p=item_3&s=75", nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, imageURL, resp.Header.Get("Location"))

	// Verify the tracking record was updated
	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)

	assert.NotNil(t, updated.OpenedAt)
	assert.Equal(t, "image", *updated.OpenedVia)
	assert.Equal(t, uint8(75), *updated.ScrollDepthPercent)

	// The per-load record in email_tracking_images is the source of truth for how
	// many (and which positioned) images loaded. The denormalised
	// email_tracking.images_loaded counter is retired and the column dropped: it
	// caused hot-row lock contention on the parent row and was derivable from these
	// child rows anyway.

	// Verify image load record was created
	var images []emailtracking.EmailTrackingImage
	db.Where("email_tracking_id = ?", updated.ID).Find(&images)
	assert.Equal(t, 1, len(images))
	assert.Equal(t, "item_3", images[0].ImagePosition)
	assert.Equal(t, uint8(75), *images[0].EstimatedScrollPercent)
}

// TestEmailTrackingImageCompactSourceOfTruth exercises the compact/digest image
// route (ImageCompact) - the one seen hammering the cluster with
// "images_loaded = images_loaded + 1" for a single row. Each load must record a
// child email_tracking_images row (the source of truth) and redirect, WITHOUT
// incrementing the parent counter (no per-hit hot-row UPDATE).
func TestEmailTrackingImageCompactSourceOfTruth(t *testing.T) {
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	ref := tracking.TrackingID[:12]
	// type 't' (message photo) builds a delivery URL purely from the id, so no
	// real message row is needed. preset 1, position label "i1".
	idEnc := encodeCompactID(120345)
	path := "/e/d/i/" + ref + "/t/" + idEnc + "/1/i1"

	resp, err := getApp().Test(httptest.NewRequest("GET", path, nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode) // 302 to delivery URL
	assert.NotEmpty(t, resp.Header.Get("Location"))

	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)

	// First-open marking still happens (once, guarded in SQL).
	assert.NotNil(t, updated.OpenedAt)
	assert.Equal(t, "image", *updated.OpenedVia)

	// One child row per load (the source of truth; there is no parent counter).
	var imgCount int64
	db.Model(&emailtracking.EmailTrackingImage{}).Where("email_tracking_id = ?", updated.ID).Count(&imgCount)
	assert.Equal(t, int64(1), imgCount)

	var img emailtracking.EmailTrackingImage
	db.Where("email_tracking_id = ?", updated.ID).First(&img)
	assert.Equal(t, "i1", img.ImagePosition)

	// A second load adds another child row (the per-load record); the count is
	// derived from these rows.
	resp2, _ := getApp().Test(httptest.NewRequest("GET", path, nil), -1)
	assert.Equal(t, http.StatusFound, resp2.StatusCode)
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)
	db.Model(&emailtracking.EmailTrackingImage{}).Where("email_tracking_id = ?", updated.ID).Count(&imgCount)
	assert.Equal(t, int64(2), imgCount)
}

func TestEmailTrackingMDN(t *testing.T) {
	// MDN read receipts are handled by PHP's incoming mail handler
	// which updates the database directly. This test verifies the data model
	// supports MDN tracking by simulating what PHP would do.

	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	// Simulate PHP updating the record when MDN email is received
	db := database.DBConn
	now := time.Now()
	openedVia := "mdn"
	db.Model(&emailtracking.EmailTracking{}).
		Where("tracking_id = ?", tracking.TrackingID).
		Updates(map[string]interface{}{
			"opened_at":  now,
			"opened_via": openedVia,
		})

	// Verify the tracking record was updated
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)

	assert.NotNil(t, updated.OpenedAt)
	assert.Equal(t, "mdn", *updated.OpenedVia)
}

func TestEmailTrackingUnsubscribe(t *testing.T) {
	// Create test tracking record
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	// Unsubscribe is tracked via the click endpoint with action=unsubscribe
	unsubscribeURL := getTestUserSite() + "/unsubscribe"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(unsubscribeURL))

	req := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+encodedURL+"&a=unsubscribe", nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, unsubscribeURL, resp.Header.Get("Location"))

	// Verify the tracking record was updated
	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)

	assert.NotNil(t, updated.UnsubscribedAt)
	assert.NotNil(t, updated.ClickedAt)

	// Verify click record was created with unsubscribe action
	var clicks []emailtracking.EmailTrackingClick
	db.Where("email_tracking_id = ?", updated.ID).Find(&clicks)
	assert.Equal(t, 1, len(clicks))
	assert.Equal(t, "unsubscribe", *clicks[0].Action)
}

func TestEmailTrackingStatsUnauthorized(t *testing.T) {
	// Request without authentication
	req := httptest.NewRequest("GET", "/api/modtools/email/stats", nil)
	resp, err := getApp().Test(req)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusUnauthorized, resp.StatusCode)
}

func TestEmailTrackingStatsWithAuth(t *testing.T) {
	// Create a support user with token using existing test utilities
	prefix := uniquePrefix("emailstats")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	// Request with authentication
	req := httptest.NewRequest("GET", "/api/modtools/email/stats?jwt="+token, nil)
	resp, err := getApp().Test(req)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusOK, resp.StatusCode)

	// Parse response
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)

	assert.Contains(t, result, "stats")
	assert.Contains(t, result, "period")
}

func TestEmailTrackingUserEmailsUnauthorized(t *testing.T) {
	// Request without authentication
	req := httptest.NewRequest("GET", "/api/modtools/email/user/123", nil)
	resp, err := getApp().Test(req)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusUnauthorized, resp.StatusCode)
}

func TestEmailTrackingUserEmailsWithAuth(t *testing.T) {
	// Create a support user with token using existing test utilities
	prefix := uniquePrefix("emailuser")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	// Create a test tracking record for the support user
	db := database.DBConn
	tracking := &emailtracking.EmailTracking{
		TrackingID:     "usertest-" + randomString(16),
		EmailType:      "Test",
		UserID:         &userID,
		RecipientEmail: "test@example.com",
	}
	db.Create(tracking)
	defer db.Where("tracking_id = ?", tracking.TrackingID).Delete(&emailtracking.EmailTracking{})

	// Request user emails with authentication
	req := httptest.NewRequest("GET", "/api/modtools/email/user/"+strconv.FormatUint(userID, 10)+"?jwt="+token, nil)
	resp, err := getApp().Test(req)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusOK, resp.StatusCode)

	// Parse response
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Contains(t, result, "emails")
	assert.Contains(t, result, "total")
}

func TestEmailTrackingOnlyRecordsFirstOpen(t *testing.T) {
	// Create test tracking record
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	// First open via pixel
	req1 := httptest.NewRequest("GET", "/e/d/p/"+tracking.TrackingID, nil)
	getApp().Test(req1)

	// Get first opened_at
	db := database.DBConn
	var first emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&first)
	firstOpenedAt := first.OpenedAt
	firstOpenedVia := first.OpenedVia

	// Second open via image (should not update opened_via)
	imageURL := getTestImageDomain() + "/test.jpg"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(imageURL))
	req2 := httptest.NewRequest("GET", "/e/d/i/"+tracking.TrackingID+"?url="+encodedURL+"&p=item_1", nil)
	getApp().Test(req2, -1)

	// Verify opened_at wasn't changed
	var second emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&second)

	assert.Equal(t, firstOpenedAt, second.OpenedAt)
	assert.Equal(t, firstOpenedVia, second.OpenedVia)
	assert.Equal(t, "pixel", *second.OpenedVia)
}

func TestEmailTrackingMultipleClicks(t *testing.T) {
	// Create test tracking record
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	// First click
	firstClickURL := getTestUserSite()
	url1 := base64.StdEncoding.EncodeToString([]byte(firstClickURL))
	req1 := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+url1+"&p=link1", nil)
	getApp().Test(req1, -1)

	// Second click
	url2 := base64.StdEncoding.EncodeToString([]byte(getTestUserSite() + "/give"))
	req2 := httptest.NewRequest("GET", "/e/d/r/"+tracking.TrackingID+"?url="+url2+"&p=link2", nil)
	getApp().Test(req2, -1)

	// Verify click count
	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)

	assert.Equal(t, uint16(2), updated.LinksClicked)

	// First clicked_link should be preserved
	assert.Equal(t, firstClickURL, *updated.ClickedLink)

	// Verify both click records exist
	var clicks []emailtracking.EmailTrackingClick
	db.Where("email_tracking_id = ?", updated.ID).Find(&clicks)
	assert.Equal(t, 2, len(clicks))
}
