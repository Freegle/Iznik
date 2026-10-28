package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

func TestMembersListCarriesImplicit(t *testing.T) {
	prefix := uniquePrefix("memimplicit_list")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)

	rippled := CreateTestUser(t, prefix+"_rippled", "User")
	rippledMembership := CreateTestMembership(t, rippled, group, "Member")
	db.Exec("UPDATE memberships SET rippled = 1, implicit = 1 WHERE id = ?", rippledMembership)

	ordinary := CreateTestUser(t, prefix+"_ordinary", "User")
	CreateTestMembership(t, ordinary, group, "Member")

	banned := CreateTestUser(t, prefix+"_banned", "User")
	db.Exec("INSERT INTO users_banned (userid, groupid, date) VALUES (?, ?, NOW())", banned, group)

	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)

	type member struct {
		Userid   uint64 `json:"userid"`
		Implicit bool   `json:"implicit"`
	}
	list := func(query string) map[uint64]member {
		req := httptest.NewRequest("GET",
			fmt.Sprintf("/api/memberships?groupid=%d&%s&limit=100&jwt=%s", group, query, modToken), nil)
		resp, err := getApp().Test(req, -1)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)

		var payload struct {
			Members []member `json:"members"`
		}
		assert.NoError(t, json.NewDecoder(resp.Body).Decode(&payload))

		byUser := map[uint64]member{}
		for _, m := range payload.Members {
			byUser[m.Userid] = m
		}
		return byUser
	}

	approved := list("collection=Approved")
	assert.Equal(t, member{Userid: rippled, Implicit: true}, approved[rippled])
	assert.Equal(t, member{Userid: ordinary, Implicit: false}, approved[ordinary])

	// The Banned list (filter 5) is built from users_banned, where there is no membership row to read.
	assert.Equal(t, member{Userid: banned, Implicit: false}, list("filter=5")[banned])
}

func TestUserMembershipsCarryImplicit(t *testing.T) {
	prefix := uniquePrefix("memimplicit_user")
	db := database.DBConn
	member := CreateTestUser(t, prefix, "User")

	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	rippledMembership := CreateTestMembership(t, member, rippledGroup, "Member")
	db.Exec("UPDATE memberships SET rippled = 1, implicit = 1 WHERE id = ?", rippledMembership)

	ordinaryGroup := CreateTestGroup(t, prefix+"_ordinary")
	CreateTestMembership(t, member, ordinaryGroup, "Member")

	byGroup := map[uint64]user.Membership{}
	for _, m := range user.GetMemberships(member) {
		byGroup[m.Groupid] = m
	}

	assert.Equal(t, 1, byGroup[rippledGroup].Implicit)
	assert.Equal(t, 0, byGroup[ordinaryGroup].Implicit)
}

// A TrashNothing poster placed on a group they never chose has implicit = 1 with rippled = 0.
// TN adding them to that group is their consent, so the partner join clears it, with the
// same "became ordinary" log a ripple membership gets.
func TestPartnerJoinClearsATrashNothingImplicitMembership(t *testing.T) {
	prefix := uniquePrefix("memimplicit_partner")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	membershipID := CreateTestMembership(t, userID, groupID, "Member")
	db.Exec("UPDATE memberships SET rippled = 0, implicit = 1 WHERE id = ?", membershipID)

	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 12346)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 12346, userID)
	key := insertTestPartnerKey(t, prefix, "test.com")

	url := fmt.Sprintf("/api/memberships?partner=%s&tnuserid=12346&email=%s@test.com&groupid=%d", key, prefix, groupID)
	resp, err := getApp().Test(httptest.NewRequest("PUT", url, nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var implicit int
	db.Raw("SELECT implicit FROM memberships WHERE id = ?", membershipID).Scan(&implicit)
	assert.Equal(t, 0, implicit, "TN adding them to the group is consent")

	var logs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE user = ? AND groupid = ? AND type = 'Group' AND subtype = 'Joined' "+
		"AND text = 'Ripple-created membership became ordinary: joined'", userID, groupID).Scan(&logs)
	assert.Equal(t, int64(1), logs)
}
