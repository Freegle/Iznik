package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// Discourse 10239. A community a post merely rippled into may not write to its poster
// about it, because the poster has nothing to do with that community. Once the poster is
// an ordinary member there - they joined it, moved into it, or dealt with it - that is no
// longer true, and its moderators may write to them about the post like any other.

// rippledInPostToOrdinaryMember is rippledInPost where the poster is an ordinary member of
// the community the post rippled into.
func rippledInPostToOrdinaryMember(t *testing.T, prefix string, collection string) (posterID, rippled, msgID uint64, modToken string) {
	t.Helper()

	posterID = CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, rippled, msgID = rippledInPost(t, prefix, posterID, collection)
	CreateTestMembership(t, posterID, rippled, "Member")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken = CreateTestSession(t, modID)

	return posterID, rippled, msgID, modToken
}

func TestReplyFromRippledInGroupReachesAnOrdinaryMember(t *testing.T) {
	_, rippled, msgID, modToken := rippledInPostToOrdinaryMember(t, uniquePrefix("ordreply"), "Approved")

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reply", "groupid": rippled,
		"subject": "Re: OFFER", "body": "A member here says it does not work.",
	})
	assert.Equal(t, fiber.StatusOK, status)
	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_reply", msgID, rippled),
		"the poster is an ordinary member of this community, so its reply reaches them")
}

func TestReplyFromRippledInGroupStillRefusedForARippleOnlyMember(t *testing.T) {
	prefix := uniquePrefix("ripreplyonly")
	posterID, rippled, msgID, modToken := rippledInPostToOrdinaryMember(t, prefix, "Approved")
	database.DBConn.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", posterID, rippled)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reply", "groupid": rippled,
		"subject": "Re: OFFER", "body": "Hello",
	})
	assert.Equal(t, fiber.StatusForbidden, status,
		"a membership rippling made is still not a relationship with the community")
}

func TestRejectOnRippledInGroupTellsAnOrdinaryMember(t *testing.T) {
	_, rippled, msgID, modToken := rippledInPostToOrdinaryMember(t, uniquePrefix("ordreject"), "Pending")

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reject", "groupid": rippled,
		"subject": "Sorry", "body": "We don't take live animals.",
	})
	assert.Equal(t, fiber.StatusOK, status)
	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_rejected", msgID, rippled))
}

func TestDeleteOnRippledInGroupTellsAnOrdinaryMember(t *testing.T) {
	_, rippled, msgID, modToken := rippledInPostToOrdinaryMember(t, uniquePrefix("orddelete"), "Approved")

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Delete", "groupid": rippled,
		"subject": "Re: OFFER", "body": "We don't take live animals.",
	})
	assert.Equal(t, fiber.StatusOK, status)
	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_rejected", msgID, rippled))
}

func TestApproveOnRippledInGroupTellsAnOrdinaryMember(t *testing.T) {
	_, rippled, msgID, modToken := rippledInPostToOrdinaryMember(t, uniquePrefix("ordapprove"), "Pending")

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupid": rippled,
		"subject": "Welcome", "body": "Approved with a note.",
	})
	assert.Equal(t, fiber.StatusOK, status)
	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_approved", msgID, rippled))
}

// ModTools needs to know, for each community the post is on, whether its moderators may
// write to the poster, to decide which buttons to offer.
func TestMessageGroupsSayWhetherModsCanWriteToThePoster(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("canmsg")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	origin, rippled, msgID := rippledInPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, origin, "Moderator")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	canMessage := func() map[uint64]interface{} {
		resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil))
		assert.Equal(t, 200, resp.StatusCode)
		var msg map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&msg)
		out := map[uint64]interface{}{}
		groups, _ := msg["groups"].([]interface{})
		for _, g := range groups {
			row := g.(map[string]interface{})
			out[uint64(row["groupid"].(float64))] = row["canmessageposter"]
		}
		return out
	}

	got := canMessage()
	assert.Equal(t, true, got[origin], "the home community may always write to its poster")
	assert.Equal(t, false, got[rippled], "the poster has nothing to do with the community it rippled into")

	CreateTestMembership(t, posterID, rippled, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", posterID, rippled)
	assert.Equal(t, false, canMessage()[rippled], "a membership rippling made does not change that")

	db.Exec("UPDATE memberships SET rippled = 0, implicit = 1, added_origin = 'tn_unaddressed' "+
		"WHERE userid = ? AND groupid = ?", posterID, rippled)
	assert.Equal(t, false, canMessage()[rippled], "nor does one TrashNothing placed them on")

	db.Exec("UPDATE memberships SET implicit = 0 WHERE userid = ? AND groupid = ?", posterID, rippled)
	assert.Equal(t, true, canMessage()[rippled], "an ordinary membership does")
}
