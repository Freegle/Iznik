package test

import (
	json2 "encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"net/url"
	"testing"
	"time"
)

func TestIllustrationNoItem(t *testing.T) {
	// Test with no item parameter - should return error.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 2, result.Ret)
	assert.Equal(t, "Item name required", result.Status)
}

func TestIllustrationEmptyItem(t *testing.T) {
	// Test with empty item parameter - should return error.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item=", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 2, result.Ret)
}

func TestIllustrationWhitespaceItem(t *testing.T) {
	// Test with whitespace only item parameter - should return error.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item=%20%20%20", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 2, result.Ret)
}

func TestIllustrationNotCached(t *testing.T) {
	// Test with an item that doesn't exist in cache - should return ret=3.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item=NonexistentItem12345", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 3, result.Ret)
	assert.Equal(t, "Not cached - use generation API", result.Status)
}

func TestIllustrationCached(t *testing.T) {
	// Insert a cached illustration directly into the database.
	testUid := fmt.Sprintf("test-uid-%d", time.Now().UnixNano())
	testItem := fmt.Sprintf("UTTest Sofa %d", time.Now().UnixNano())

	db := database.DBConn
	db.Exec("INSERT INTO ai_images (name, externaluid) VALUES (?, ?)", testItem, testUid)

	// Now request it - should return cached version.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item="+url.QueryEscape(testItem), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 0, result.Ret)
	assert.Equal(t, "Success", result.Status)
	assert.NotNil(t, result.Illustration)
	assert.Equal(t, testUid, result.Illustration.ExternalUID)
	assert.True(t, result.Illustration.Cached)

	// Clean up.
	db.Exec("DELETE FROM ai_images WHERE name = ?", testItem)
}

func TestIllustrationPrefixStripping(t *testing.T) {
	// Insert a cached illustration.
	testUid := fmt.Sprintf("test-uid-prefix-%d", time.Now().UnixNano())
	testItem := "Red Chair"

	db := database.DBConn
	db.Exec("INSERT INTO ai_images (name, externaluid) VALUES (?, ?)", testItem, testUid)

	// Request with OFFER: prefix - should strip it and find the cached item.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item=OFFER:%20Red%20Chair", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 0, result.Ret)
	assert.Equal(t, testUid, result.Illustration.ExternalUID)

	// Request with WANTED: prefix.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/illustration?item=WANTED:%20Red%20Chair", nil))
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 0, result.Ret)
	assert.Equal(t, testUid, result.Illustration.ExternalUID)

	// Clean up.
	db.Exec("DELETE FROM ai_images WHERE name = ?", testItem)
}

func TestIllustrationLocationSuffixStripping(t *testing.T) {
	// Insert a cached illustration.
	testUid := fmt.Sprintf("test-uid-location-%d", time.Now().UnixNano())
	testItem := "Blue Bike"

	db := database.DBConn
	db.Exec("INSERT INTO ai_images (name, externaluid) VALUES (?, ?)", testItem, testUid)

	// Request with location suffix in parentheses - should strip it.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item=Blue%20Bike%20(London)", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 0, result.Ret)
	assert.Equal(t, testUid, result.Illustration.ExternalUID)

	// Clean up.
	db.Exec("DELETE FROM ai_images WHERE name = ?", testItem)
}

// TestIllustrationSuppressedNotReturned verifies that a suppressed AI image
// is NOT returned by the illustration endpoint (AssertFlip: FAILS on buggy code,
// PASSES once illustration.go filters by status = 'active').
//
// Root cause: before the fix, GetIllustration queried ai_images without a
// status filter, so suppressed images were returned during compose and then
// silently blanked by the message API — the user saw their attachment disappear.
func TestIllustrationSuppressedNotReturned(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("illust_suppress")
	suppUID := "freegletusd-suppressed-" + prefix
	itemName := "drawing-" + prefix

	// Insert a suppressed AI image (as set by checkAIImageSuppressQuorum).
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 10, 'suppressed')",
		itemName, suppUID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM ai_images WHERE name = ?", itemName)
	})

	// AssertFlip Step 3b (inverted): should return ret=3 (not cached / don't show),
	// not ret=0 with the suppressed image. FAILS on buggy code.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item="+url.QueryEscape(itemName), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 3, result.Ret,
		"suppressed AI image must not be returned by /illustration — it would be blanked on view, looking like spam suppression")
	assert.Nil(t, result.Illustration,
		"illustration field must be nil for a suppressed image")
}

// TestIllustrationActiveStillReturned ensures the fix doesn't break the happy
// path: active illustrations must still be returned.
func TestIllustrationActiveStillReturned(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("illust_active")
	activeUID := "freegletusd-active-" + prefix
	itemName := "sofa-" + prefix

	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 50, 'active')",
		itemName, activeUID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM ai_images WHERE name = ?", itemName)
	})

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/illustration?item="+url.QueryEscape(itemName), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result misc.IllustrationResult
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, 0, result.Ret, "active illustration must still be returned")
	assert.NotNil(t, result.Illustration)
	assert.Equal(t, activeUID, result.Illustration.ExternalUID)
}
