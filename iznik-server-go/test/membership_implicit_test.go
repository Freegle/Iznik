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
