package test

// Gate tests for image/image.go's doCreate (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md; review finding 5): POST /image can
// attach a NEW image to content that is already live - a message, community event,
// volunteering opportunity, story, noticeboard or ChitChat post - via a wholly
// separate write path from that entity's own gated PATCH edit (a different table:
// messages_attachments, communityevents_images, and so on, never the entity row
// itself). Before this fix that path had no lockdown gate at all, so it reached a
// held post/event/opportunity/story/noticeboard/chitchat item regardless of the
// surface's hold. Rotating an existing image (doRotate) is deliberately unaffected -
// see TestLockdownAllowsImageRotateWhilePostsHeld below.
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so
// these tests never write a real "lockdowns" row and cannot race the other packages'
// test binaries that `go test ./...` runs concurrently against the same test database.
// postsHeld, eventsHeld and chitchatHeld are defined in lockdown_gates_message_test.go,
// lockdown_gates_events_test.go and lockdown_gates_newsfeed_test.go respectively.
// createTestNoticeboard is defined in noticeboard_test.go, same package.

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func attachImage(t *testing.T, token string, body string) *http.Response {
	t.Helper()
	req := httptest.NewRequest("POST", "/api/image?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	return resp
}

func TestLockdownRefusesImageAttachToHeldMessage(t *testing.T) {
	prefix := uniquePrefix("ld_img_msg")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, groupID, "Held image test "+prefix, 55.9533, -3.1883)

	restore := postsHeld()
	defer restore()

	body := fmt.Sprintf(`{"externaluid":"freegletusd-ld-msg-%s","imgtype":"Message","msgid":%d}`, prefix, msgID)
	resp := attachImage(t, token, body)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(0), count, "no attachment must be created on a held post")
}

func TestLockdownRefusesImageAttachToHeldCommunityEvent(t *testing.T) {
	prefix := uniquePrefix("ld_img_ce")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, token := CreateTestSession(t, ownerID)
	eventID := CreateTestCommunityEvent(t, ownerID, groupID)

	restore := eventsHeld()
	defer restore()

	body := fmt.Sprintf(`{"externaluid":"freegletusd-ld-ce-%s","imgtype":"CommunityEvent","communityevent":%d}`, prefix, eventID)
	resp := attachImage(t, token, body)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM communityevents_images WHERE eventid = ?", eventID).Scan(&count)
	assert.Equal(t, int64(0), count, "no image must be attached to a held community event")
}

func TestLockdownRefusesImageAttachToHeldVolunteering(t *testing.T) {
	prefix := uniquePrefix("ld_img_vol")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, token := CreateTestSession(t, ownerID)
	volID := CreateTestVolunteering(t, ownerID, groupID)

	restore := eventsHeld()
	defer restore()

	body := fmt.Sprintf(`{"externaluid":"freegletusd-ld-vol-%s","imgtype":"Volunteering","volunteering":%d}`, prefix, volID)
	resp := attachImage(t, token, body)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM volunteering_images WHERE opportunityid = ?", volID).Scan(&count)
	assert.Equal(t, int64(0), count, "no image must be attached to a held volunteering opportunity")
}

func TestLockdownRefusesImageAttachToHeldStory(t *testing.T) {
	prefix := uniquePrefix("ld_img_story")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, token := CreateTestSession(t, ownerID)
	storyID := CreateTestStory(t, ownerID, prefix+" headline", "A test story", false, true)

	restore := eventsHeld()
	defer restore()

	body := fmt.Sprintf(`{"externaluid":"freegletusd-ld-story-%s","imgtype":"Story","story":%d}`, prefix, storyID)
	resp := attachImage(t, token, body)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM users_stories_images WHERE storyid = ?", storyID).Scan(&count)
	assert.Equal(t, int64(0), count, "no image must be attached to a held story")
}

func TestLockdownRefusesImageAttachToHeldNewsfeed(t *testing.T) {
	prefix := uniquePrefix("ld_img_nf")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, token := CreateTestSession(t, ownerID)
	nfID := CreateTestNewsfeed(t, ownerID, 52.2, -0.1, "Held image test "+prefix)

	restore := chitchatHeld()
	defer restore()

	body := fmt.Sprintf(`{"externaluid":"freegletusd-ld-nf-%s","imgtype":"Newsfeed","newsfeed":%d}`, prefix, nfID)
	resp := attachImage(t, token, body)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM newsfeed_images WHERE newsfeedid = ?", nfID).Scan(&count)
	assert.Equal(t, int64(0), count, "no image must be attached to a held ChitChat post")
}

// Noticeboard content has no per-member ownership at all (image.go's ownsImageParent
// treats it, like Group and Newsletter, as mod-managed - see the "default" case
// there), so only a system moderator can even reach the parentID != 0 branch this
// gate sits in. A system moderator is not Support/Admin, so GateMember still refuses
// them - the same as any other member-facing surface gate.
func TestLockdownRefusesImageAttachToHeldNoticeboard(t *testing.T) {
	prefix := uniquePrefix("ld_img_nb")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	nbID := createTestNoticeboard(t, posterID)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	db := database.DBConn
	db.Exec("UPDATE users SET systemrole = 'Moderator' WHERE id = ?", modID)
	_, token := CreateTestSession(t, modID)

	restore := eventsHeld()
	defer restore()

	body := fmt.Sprintf(`{"externaluid":"freegletusd-ld-nb-%s","imgtype":"Noticeboard","noticeboard":%d}`, prefix, nbID)
	resp := attachImage(t, token, body)
	assertLockdownRefused(t, resp)

	var count int64
	db.Raw("SELECT COUNT(*) FROM noticeboards_images WHERE noticeboardid = ?", nbID).Scan(&count)
	assert.Equal(t, int64(0), count, "no image must be attached to a held noticeboard")
}

// Rotating an existing image is a separate operation (doRotate) from attaching a new
// one, and the review explicitly asked for it to stay allowed while a surface is
// held - it does not publish new content, only re-orients what a moderator has
// already seen.
func TestLockdownAllowsImageRotateWhilePostsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_img_rotate")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, groupID, "Rotate held test "+prefix, 55.9533, -3.1883)

	// Create the image before the hold starts - only the attach path is gated.
	createBody := fmt.Sprintf(`{"externaluid":"freegletusd-ld-rotate-%s","imgtype":"Message","msgid":%d}`, prefix, msgID)
	createResp := attachImage(t, token, createBody)
	assert.Equal(t, fiber.StatusOK, createResp.StatusCode)
	var createResult map[string]interface{}
	json.Unmarshal(rsp(createResp), &createResult)
	imageID := uint64(createResult["id"].(float64))
	assert.NotZero(t, imageID)

	restore := postsHeld()
	defer restore()

	rotateBody := fmt.Sprintf(`{"id":%d,"rotate":90,"type":"Message"}`, imageID)
	rotateResp := attachImage(t, token, rotateBody)
	assert.Equal(t, fiber.StatusOK, rotateResp.StatusCode, "rotating an existing image must stay allowed while posts is held")
}
