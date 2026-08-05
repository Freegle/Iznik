package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// unsubscribeFixture creates a member of one group with a known Link key.
func unsubscribeFixture(t *testing.T, prefix string) (uint64, string) {
	userID := CreateTestUser(t, prefix, "Member")
	groupID := CreateTestGroup(t, prefix)
	db := database.DBConn

	db.Exec("UPDATE users SET relevantallowed = 1, newslettersallowed = 1 WHERE id = ?", userID)
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection, emailfrequency, eventsallowed, volunteeringallowed) "+
		"VALUES (?, ?, 'Member', 'Approved', 24, 1, 1)", userID, groupID)

	key := fmt.Sprintf("unsubkey%v", userID)
	db.Exec("INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, ?, ?, ?)",
		userID, utils.LOGIN_TYPE_LINK, fmt.Sprintf("%v", userID), key)
	t.Cleanup(func() {
		db.Exec("DELETE FROM users_logins WHERE userid = ? AND credentials = ?", userID, key)
	})

	return userID, key
}

// TestUnsubscribeOneClickPost: the RFC 8058 POST must actually turn the category off.
// It previously pointed at a front-end page which answered the POST with a 200 and did
// nothing, so mail clients told members they had unsubscribed when they had not.
func TestUnsubscribeOneClickPost(t *testing.T) {
	userID, key := unsubscribeFixture(t, uniquePrefix("unsubpost"))
	db := database.DBConn

	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/user/unsubscribe?u=%v&k=%s&t=digest", userID, key), nil)
	resp, _ := getApp().Test(req, 60000)
	require.Equal(t, 200, resp.StatusCode)

	var live int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND emailfrequency != 0", userID).Scan(&live)
	assert.Equal(t, int64(0), live, "one-click POST must turn digests off")
}

// TestUnsubscribeIsTargeted: unsubscribing from one category leaves the others alone,
// and never deletes the account.
func TestUnsubscribeIsTargeted(t *testing.T) {
	userID, key := unsubscribeFixture(t, uniquePrefix("unsubtargeted"))
	db := database.DBConn

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/user/unsubscribe?u=%v&k=%s&t=digest", userID, key), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var relevant, newsletters int
	var deleted *string
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&relevant)
	db.Raw("SELECT newslettersallowed FROM users WHERE id = ?", userID).Scan(&newsletters)
	db.Raw("SELECT deleted FROM users WHERE id = ?", userID).Scan(&deleted)

	assert.Equal(t, 1, relevant, "a digest unsubscribe must not touch matched posts")
	assert.Equal(t, 1, newsletters, "a digest unsubscribe must not touch newsletters")
	assert.Nil(t, deleted, "unsubscribing from a category must not delete the account")
}

// TestUnsubscribeSettingsCategories: the JSON-column categories are written even when the
// key is absent, because absent means "on".
func TestUnsubscribeSettingsCategories(t *testing.T) {
	userID, key := unsubscribeFixture(t, uniquePrefix("unsubsettings"))
	db := database.DBConn

	for _, tc := range []struct{ arg, path string }{
		{user.UnsubChat, "$.notifications.email"},
		{user.UnsubNotifications, "$.notificationmails"},
		{user.UnsubEngagement, "$.engagement"},
	} {
		resp, _ := getApp().Test(httptest.NewRequest("POST",
			fmt.Sprintf("/api/user/unsubscribe?u=%v&k=%s&t=%s", userID, key, tc.arg), nil), 60000)
		require.Equal(t, 200, resp.StatusCode)

		var got *string
		db.Raw("SELECT JSON_EXTRACT(settings, ?) FROM users WHERE id = ?", tc.path, userID).Scan(&got)
		require.NotNil(t, got, "%s should have written %s", tc.arg, tc.path)
		assert.Equal(t, "false", *got, "%s should set %s to false", tc.arg, tc.path)
	}

	// The location written by the fixture must survive - we only turn one key off.
	var loc *string
	db.Raw("SELECT JSON_EXTRACT(settings, '$.mylocation.lat') FROM users WHERE id = ?", userID).Scan(&loc)
	assert.NotNil(t, loc, "other settings must not be clobbered")
}

// TestUnsubscribeAll: "all" turns off every category.
func TestUnsubscribeAll(t *testing.T) {
	userID, key := unsubscribeFixture(t, uniquePrefix("unsuball"))
	db := database.DBConn

	resp, _ := getApp().Test(httptest.NewRequest("POST",
		fmt.Sprintf("/api/user/unsubscribe?u=%v&k=%s&t=all", userID, key), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var relevant, newsletters int
	var live int64
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&relevant)
	db.Raw("SELECT newslettersallowed FROM users WHERE id = ?", userID).Scan(&newsletters)
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND (emailfrequency != 0 OR eventsallowed != 0 OR volunteeringallowed != 0)", userID).Scan(&live)

	assert.Equal(t, 0, relevant)
	assert.Equal(t, 0, newsletters)
	assert.Equal(t, int64(0), live)
}

// TestUnsubscribeAllExceptReplies: stops the bulk mail but leaves chat on, so someone who
// offers a sofa still hears when a neighbour replies.
func TestUnsubscribeAllExceptReplies(t *testing.T) {
	userID, key := unsubscribeFixture(t, uniquePrefix("unsubexcept"))
	db := database.DBConn

	resp, _ := getApp().Test(httptest.NewRequest("POST",
		fmt.Sprintf("/api/user/unsubscribe?u=%v&k=%s&t=%s", userID, key, user.UnsubAllExceptReplies), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var relevant, newsletters int
	var live int64
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&relevant)
	db.Raw("SELECT newslettersallowed FROM users WHERE id = ?", userID).Scan(&newsletters)
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND emailfrequency != 0", userID).Scan(&live)

	assert.Equal(t, 0, relevant)
	assert.Equal(t, 0, newsletters)
	assert.Equal(t, int64(0), live)

	// The one that must survive.
	var chat *string
	db.Raw("SELECT JSON_EXTRACT(settings, '$.notifications.email') FROM users WHERE id = ?", userID).Scan(&chat)
	assert.Nil(t, chat, "replies to your posts must be left switched on")

	var deleted *string
	db.Raw("SELECT deleted FROM users WHERE id = ?", userID).Scan(&deleted)
	assert.Nil(t, deleted, "stopping email must never delete the account")
}

// TestUnsubscribeUnknownTypeFallsBackToAll: a mangled address must not silently do
// nothing - the member asked to stop.
func TestUnsubscribeUnknownTypeFallsBackToAll(t *testing.T) {
	userID, key := unsubscribeFixture(t, uniquePrefix("unsubunknown"))
	db := database.DBConn

	resp, _ := getApp().Test(httptest.NewRequest("POST",
		fmt.Sprintf("/api/user/unsubscribe?u=%v&k=%s&t=nonsense", userID, key), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var relevant int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&relevant)
	assert.Equal(t, 0, relevant)
}

// TestUnsubscribeBadKey: a wrong key is rejected and nothing changes. Without this an
// unsubscribe URL could be forged for any member from their id alone.
func TestUnsubscribeBadKey(t *testing.T) {
	userID, _ := unsubscribeFixture(t, uniquePrefix("unsubbadkey"))
	db := database.DBConn

	resp, _ := getApp().Test(httptest.NewRequest("POST",
		fmt.Sprintf("/api/user/unsubscribe?u=%v&k=WRONGKEY&t=digest", userID), nil), 60000)
	require.Equal(t, 403, resp.StatusCode)

	var live int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND emailfrequency != 0", userID).Scan(&live)
	assert.Equal(t, int64(1), live, "a bad key must not change anything")
}

// TestUnsubscribeMissingParams: no u/k → 400.
func TestUnsubscribeMissingParams(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/user/unsubscribe", nil), 60000)
	require.Equal(t, 400, resp.StatusCode)
}

// TestUnsubscribeRouteNotShadowedByUserID: /user/unsubscribe has to be registered before
// /user/:id?, otherwise "unsubscribe" is parsed as a user id and the endpoint is
// unreachable - the same trap relevantoff documents.
func TestUnsubscribeRouteNotShadowedByUserID(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/unsubscribe", nil), 60000)
	assert.Equal(t, 400, resp.StatusCode, "should reach the handler and complain about params, not fall through to GetUser")
}

// TestUnsubscribeTypesMatchBatch: the same category map is implemented in iznik-batch for
// the mailto: arm of the header, and apiv2 and batch-prod are on different hosts so
// neither can call the other. Neither test container can see the other language's tree,
// so the actual cross-language diff lives in scripts/check-unsubscribe-categories.mjs;
// this pins the Go side so a change here is deliberate and shows up in review next to the
// PHP one.
func TestUnsubscribeTypesMatchBatch(t *testing.T) {
	assert.Equal(t,
		"digest,events,volunteering,newsletter,relevant,chat,notifications,engagement,all,allexceptreplies",
		strings.Join(user.UnsubscribeTypes, ","))

	for _, one := range user.UnsubscribeTypes {
		assert.NotEmpty(t, user.UnsubscribeDescription(one), "%s needs a member-facing description", one)
	}
}
