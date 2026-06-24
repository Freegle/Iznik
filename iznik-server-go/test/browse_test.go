package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// POST /scrolldepth records how far down the browse feed a session scrolled. No login
// required; userid is NULL for logged-out browsing.
func TestRecordScrollDepth(t *testing.T) {
	db := database.DBConn
	// A distinctive position so we can find our row without colliding with other sessions.
	pos := 4242
	defer db.Exec("DELETE FROM browse_scroll_depth WHERE max_position = ?", pos)

	body := fmt.Sprintf(`{"maxposition":%d,"itemsavailable":60,"context":"browse"}`, pos)
	req := httptest.NewRequest("POST", "/api/scrolldepth", bytes.NewReader([]byte(body)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var count int
	db.Raw("SELECT COUNT(*) FROM browse_scroll_depth WHERE max_position = ? AND context = 'browse'", pos).Scan(&count)
	assert.Equal(t, 1, count, "a scroll-depth row was recorded")
}

// An out-of-range position is silently ignored (the beacon is fire-and-forget) and writes no row.
func TestRecordScrollDepth_IgnoresOutOfRange(t *testing.T) {
	db := database.DBConn
	body := `{"maxposition":-5,"context":"browse"}`
	req := httptest.NewRequest("POST", "/api/scrolldepth", bytes.NewReader([]byte(body)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var count int
	db.Raw("SELECT COUNT(*) FROM browse_scroll_depth WHERE max_position = -5").Scan(&count)
	assert.Equal(t, 0, count, "an out-of-range position is not recorded")
}

// GET /modtools/scroll/depth returns the scroll-depth curve (Support/Admin only). Position 0 is,
// by construction, reached by 100% of sessions, so that's a stable assertion regardless of what
// other sessions are in the window.
func TestScrollDepthCurve(t *testing.T) {
	prefix := uniquePrefix("scrolldepth")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	ctx := prefix
	db.Exec("INSERT INTO browse_scroll_depth (max_position, context) VALUES (0, ?), (5, ?), (10, ?)", ctx, ctx, ctx)
	defer db.Exec("DELETE FROM browse_scroll_depth WHERE context = ?", ctx)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/scroll/depth?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	positions, _ := result["positions"].([]interface{})
	assert.NotEmpty(t, positions, "the curve has positions")

	first, _ := positions[0].(map[string]interface{})
	assert.Equal(t, float64(0), first["position"], "the curve starts at position 0")
	assert.Equal(t, float64(100), first["pct"], "every session reaches position 0")
	assert.Contains(t, first, "sessions_reaching")
}

// The scroll-depth curve requires login.
func TestScrollDepthCurveRequiresLogin(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/modtools/scroll/depth", nil))
	assert.Equal(t, 401, resp.StatusCode, "the scroll-depth curve requires login")
}

// The scroll-depth curve is Support/Admin only.
func TestScrollDepthCurveRequiresAdmin(t *testing.T) {
	prefix := uniquePrefix("scrolldepth_noauth")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/scroll/depth?jwt=%s", token), nil))
	assert.Equal(t, 403, resp.StatusCode, "non-admin is forbidden from the scroll-depth curve")
}
