package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// AddMembership inserts through the MembershipTable struct. Its AddedOrigin is unset there, and
// an empty string is not one of the ENUM's values: production's strict mode would refuse the
// join, and this test database (not strict) would store an empty string. Either way the row must
// end up on the column default.
func TestAddMembershipLeavesAddedOriginOnTheColumnDefault(t *testing.T) {
	prefix := uniquePrefix("memorigin_add")
	group := CreateTestGroup(t, prefix)
	member := CreateTestUser(t, prefix, "User")

	assert.True(t, user.AddMembership(member, group, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED,
		utils.FREQUENCY_DAILY, 1, 1, "Joined to reply to a post"))

	var addedOrigin string
	err := database.DBConn.Raw("SELECT added_origin FROM memberships WHERE userid = ? AND groupid = ?",
		member, group).Row().Scan(&addedOrigin)

	assert.NoError(t, err)
	assert.Equal(t, "unknown", addedOrigin)
}

func TestMembersListCarriesAddedOrigin(t *testing.T) {
	prefix := uniquePrefix("memorigin_list")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)

	rippled := CreateTestUser(t, prefix+"_rippled", "User")
	rippledMembership := CreateTestMembership(t, rippled, group, "Member")
	db.Exec("UPDATE memberships SET rippled = 1, implicit = 1, added_origin = 'rippled' WHERE id = ?", rippledMembership)

	ordinary := CreateTestUser(t, prefix+"_ordinary", "User")
	ordinaryMembership := CreateTestMembership(t, ordinary, group, "Member")
	db.Exec("UPDATE memberships SET added_origin = 'manual' WHERE id = ?", ordinaryMembership)

	banned := CreateTestUser(t, prefix+"_banned", "User")
	db.Exec("INSERT INTO users_banned (userid, groupid, date) VALUES (?, ?, NOW())", banned, group)

	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)

	type member struct {
		Userid      uint64 `json:"userid"`
		AddedOrigin string `json:"added_origin"`
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
	assert.Equal(t, member{Userid: rippled, AddedOrigin: "rippled"}, approved[rippled])
	assert.Equal(t, member{Userid: ordinary, AddedOrigin: "manual"}, approved[ordinary])

	// The Banned list (filter 5) is built from users_banned, where there is no membership row to read.
	assert.Equal(t, member{Userid: banned, AddedOrigin: "unknown"}, list("filter=5")[banned])
}

func TestUserMembershipsCarryAddedOrigin(t *testing.T) {
	prefix := uniquePrefix("memorigin_user")
	db := database.DBConn
	member := CreateTestUser(t, prefix, "User")

	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	rippledMembership := CreateTestMembership(t, member, rippledGroup, "Member")
	db.Exec("UPDATE memberships SET rippled = 1, implicit = 1, added_origin = 'rippled' WHERE id = ?", rippledMembership)

	postedGroup := CreateTestGroup(t, prefix+"_posted")
	postedMembership := CreateTestMembership(t, member, postedGroup, "Member")
	db.Exec("UPDATE memberships SET added_origin = 'posted' WHERE id = ?", postedMembership)

	byGroup := map[uint64]user.Membership{}
	for _, m := range user.GetMemberships(member) {
		byGroup[m.Groupid] = m
	}

	assert.Equal(t, "rippled", byGroup[rippledGroup].AddedOrigin)
	assert.Equal(t, "posted", byGroup[postedGroup].AddedOrigin)
}
