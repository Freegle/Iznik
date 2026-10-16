package test

import (
	"bytes"
	"encoding/json"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/modmessaging"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The TN API ingestion joins a poster to the community their post was placed on even when
// they never chose it (messages_groups.mod_messaging_allowed = 0), so moderators can
// moderate them. That membership is the TN equivalent of a ripple-join: a record of where a
// post landed, not a relationship with the community. So it is barred from everything a
// rippled membership (memberships.rippled = 1) is barred from - per community, because a
// "mixed" poster who chose ANOTHER community is a real member there and only there.

// mixedPoster is a member of two communities: `placed`, which they never chose (their only
// post there is unaddressed), and `chosen`, where they posted normally.
func mixedPoster(t *testing.T, prefix string) (poster, placed, chosen uint64) {
	t.Helper()

	poster = CreateTestUser(t, prefix+"_poster", "User")
	placed = CreateTestGroup(t, prefix+"_placed")
	chosen = CreateTestGroup(t, prefix+"_chosen")
	CreateTestMembership(t, poster, placed, "Member")
	CreateTestMembership(t, poster, chosen, "Member")
	makeUnaddressed(CreateTestMessage(t, poster, placed, "OFFER: placed "+prefix, 51.5, -0.1))
	CreateTestMessage(t, poster, chosen, "OFFER: chosen "+prefix, 51.5, -0.1)

	return poster, placed, chosen
}

// modOf creates a moderator of each group and returns their token.
func modOf(t *testing.T, prefix string, groups ...uint64) string {
	t.Helper()

	mod := CreateTestUser(t, prefix+"_mod", "User")
	for _, g := range groups {
		CreateTestMembership(t, mod, g, "Moderator")
	}
	_, token := CreateTestSession(t, mod)

	return token
}

func TestIsUnaddressedOnIsPerCommunity(t *testing.T) {
	db := database.DBConn
	poster, placed, chosen := mixedPoster(t, uniquePrefix("mmaon"))

	assert.True(t, modmessaging.IsUnaddressedOn(db, poster, placed),
		"their only post on this community was placed there without their choosing")
	assert.False(t, modmessaging.IsUnaddressedOn(db, poster, chosen))
	assert.False(t, modmessaging.UserIsUnaddressedOnly(db, poster), "they are a real member elsewhere")
	assert.True(t, modmessaging.CannotBeContactedOn(db, poster, placed))
	assert.False(t, modmessaging.CannotBeContactedOn(db, poster, chosen))

	// A rippled-in copy takes the column default and is not a post on that community.
	elsewhere := CreateTestGroup(t, uniquePrefix("mmaonr"))
	msg := CreateTestMessage(t, poster, placed, "OFFER: ripples "+uniquePrefix("mmaonr"), 51.5, -0.1)
	makeUnaddressed(msg)
	addRippledCopy(msg, elsewhere)
	assert.False(t, modmessaging.IsUnaddressedOn(db, poster, elsewhere))

	assert.False(t, modmessaging.IsUnaddressedOn(db, 0, placed), "no user is not restricted")
	assert.False(t, modmessaging.IsUnaddressedOn(db, poster, 0), "no group is not restricted")
}

func TestIsUnaddressedOnIsFalseForAMemberWhoNeverPostedThere(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mmanopost")
	poster, _, _ := mixedPoster(t, prefix)
	other := CreateTestGroup(t, prefix+"_other")
	CreateTestMembership(t, poster, other, "Member")

	assert.False(t, modmessaging.IsUnaddressedOn(db, poster, other))
}

func TestModCannotStartChatWithAMemberOnACommunityTheyNeverChose(t *testing.T) {
	prefix := uniquePrefix("mmachatg")
	poster, placed, chosen := mixedPoster(t, prefix)
	token := modOf(t, prefix, placed, chosen)

	open := func(group uint64) int {
		body, _ := json.Marshal(map[string]interface{}{
			"chattype": utils.CHAT_TYPE_USER2MOD, "groupid": group, "userid": poster,
		})
		req := httptest.NewRequest("PUT", fmt.Sprintf("/api/chat/rooms?jwt=%s", token), bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, -1)
		require.NoError(t, err)
		return resp.StatusCode
	}

	assert.Equal(t, fiber.StatusForbidden, open(placed), "nobody here for its moderators to talk to")
	assert.Equal(t, fiber.StatusOK, open(chosen), "the community they chose may still talk to them")
}

func TestModCannotModmailAMemberOnACommunityTheyNeverChose(t *testing.T) {
	prefix := uniquePrefix("mmamailg")
	poster, placed, chosen := mixedPoster(t, prefix)
	token := modOf(t, prefix, placed, chosen)

	mail := func(group uint64) int {
		body, _ := json.Marshal(map[string]interface{}{
			"userid": poster, "groupid": group, "action": "Leave Approved Member",
			"subject": "Hello", "body": "A note from your volunteers.",
		})
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/memberships?jwt=%s", token), bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, -1)
		require.NoError(t, err)
		return resp.StatusCode
	}

	assert.Equal(t, fiber.StatusForbidden, mail(placed))
	assert.Equal(t, -1, memberNotifyFlag(t, poster, placed), "no modmail may be queued")
	assert.Equal(t, fiber.StatusOK, mail(chosen))
}

func TestRemovingAMemberWhoNeverChoseTheCommunityIsSilent(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mmarem")
	poster, placed, _ := mixedPoster(t, prefix)
	token := modOf(t, prefix, placed)

	payload, _ := json.Marshal(map[string]interface{}{
		"action": "Delete Approved Member", "userid": poster, "groupid": placed,
		"subject": "Removed", "body": "We don't take live animals.",
	})
	req := httptest.NewRequest("POST", "/api/memberships?jwt="+token, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode, "the removal still goes ahead")

	var remaining int64
	db.Table("memberships").Where("userid = ? AND groupid = ?", poster, placed).Count(&remaining)
	assert.Equal(t, int64(0), remaining)
	assert.Equal(t, 0, memberNotifyFlag(t, poster, placed),
		"and hear nothing about a community they never chose")
}

func TestMembersListFlagsAMixedMemberOnlyWhereTheyNeverChose(t *testing.T) {
	prefix := uniquePrefix("mmalistg")
	poster, placed, chosen := mixedPoster(t, prefix)
	token := modOf(t, prefix, placed, chosen)

	allowed := func(group uint64) interface{} {
		req := httptest.NewRequest("GET",
			fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&limit=100&jwt=%s", group, token), nil)
		resp, err := getApp().Test(req, -1)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)

		var payload struct {
			Members []struct {
				Userid              uint64 `json:"userid"`
				ModMessagingAllowed bool   `json:"mod_messaging_allowed"`
			} `json:"members"`
		}
		require.NoError(t, json.NewDecoder(resp.Body).Decode(&payload))
		for _, m := range payload.Members {
			if m.Userid == poster {
				return m.ModMessagingAllowed
			}
		}
		return nil
	}

	assert.Equal(t, false, allowed(placed), "ModTools hides Chat, Mail and standard messages here")
	assert.Equal(t, true, allowed(chosen))
}

func TestReplyFromRippledInGroupRefusedWhereThePosterNeverChoseIt(t *testing.T) {
	// The poster is an ordinary (rippled = 0) member of the community their post rippled
	// into, but only because an earlier TN post of theirs was placed there. That gives its
	// moderators no more of a relationship than a ripple-join would.
	prefix := uniquePrefix("mmareply")
	posterID, rippled, msgID, modToken := rippledInPostToOrdinaryMember(t, prefix, "Approved")
	makeUnaddressed(CreateTestMessage(t, posterID, rippled, "OFFER: placed "+prefix, 51.5, -0.1))

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reply", "groupid": rippled,
		"subject": "Re: OFFER", "body": "Hello",
	})
	assert.Equal(t, fiber.StatusForbidden, status)
}

func TestGroupStory_ExcludesAMemberWhoNeverChoseTheGroup(t *testing.T) {
	prefix := uniquePrefix("story_placed")
	poster, placed, chosen := mixedPoster(t, prefix)
	storyID := CreateTestStory(t, poster, "Placed Membership "+prefix, "story text", true, true)

	ids := func(group uint64) []uint64 {
		resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/story/group/%d?limit=1000", group), nil))
		assert.Equal(t, 200, resp.StatusCode)
		var out []uint64
		json2.Unmarshal(rsp(resp), &out)
		return out
	}

	assert.NotContains(t, ids(placed), storyID, "a community they never chose must not show their story")
	assert.Contains(t, ids(chosen), storyID)
}
