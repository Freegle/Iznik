package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/freegle/iznik-server-go/addedorigin"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// addedOriginOf reads added_origin from table (memberships or memberships_history) for one
// member and group; "" when there is no row.
func addedOriginOf(t *testing.T, table string, userid, groupid uint64) string {
	t.Helper()
	var origin string
	database.DBConn.Table(table).Select("added_origin").
		Where("userid = ? AND groupid = ?", userid, groupid).Limit(1).Row().Scan(&origin)
	return origin
}

// recordOriginReports captures what addedorigin reports for the rest of the test. The reporter is
// global, so it keeps only reports that contain needle ("user 12," or "group 34").
func recordOriginReports(t *testing.T, needle string) *[]string {
	t.Helper()
	var mu sync.Mutex
	got := []string{}
	t.Cleanup(addedorigin.SetReporter(func(msg string) {
		mu.Lock()
		defer mu.Unlock()
		if strings.Contains(msg, needle) {
			got = append(got, msg)
		}
	}))
	return &got
}

// Production runs without strict mode, so a value ForInsert accepts but the ENUM lacks is stored
// as an empty string with no error. Both tables' ENUMs must list exactly addedorigin's values, in order.
func TestAddedOriginEnumMatchesTheGoList(t *testing.T) {
	want := "enum('" + strings.Join(addedorigin.All(), "','") + "')"
	for _, table := range []string{"memberships", "memberships_history"} {
		var colType string
		database.DBConn.Raw("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "+
			"AND TABLE_NAME = ? AND COLUMN_NAME = 'added_origin'", table).Scan(&colType)
		assert.Equal(t, want, colType, table)
	}
}

// joinGroup sends PUT /memberships as the holder of token.
func joinGroup(t *testing.T, token string, body map[string]interface{}) int {
	t.Helper()
	payload, _ := json.Marshal(body)
	req := httptest.NewRequest("PUT", "/api/memberships?jwt="+token, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	return resp.StatusCode
}

// AddMembership inserts through the MembershipTable and MembershipHistory structs, so both carry
// the origin the caller gives.
func TestAddMembershipRecordsTheOriginOnBothTables(t *testing.T) {
	prefix := uniquePrefix("memorigin_add")
	group := CreateTestGroup(t, prefix)
	member := CreateTestUser(t, prefix, "User")
	reports := recordOriginReports(t, fmt.Sprintf("user %d,", member))

	assert.True(t, user.AddMembership(member, group, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED,
		utils.FREQUENCY_DAILY, 1, 1, "Joined to reply to a post", addedorigin.Replied))

	assert.Equal(t, "replied", addedOriginOf(t, "memberships", member, group))
	assert.Equal(t, "replied", addedOriginOf(t, "memberships_history", member, group))
	assert.Empty(t, *reports)
}

// An empty string is not one of the ENUM's values. Production runs without strict mode, so MySQL
// would store it as an empty string rather than refuse it. A caller that gives no origin still joins, as
// unknown, and is reported.
func TestAddMembershipWithNoOriginIsUnknownAndReported(t *testing.T) {
	prefix := uniquePrefix("memorigin_addnone")
	group := CreateTestGroup(t, prefix)
	member := CreateTestUser(t, prefix, "User")
	reports := recordOriginReports(t, fmt.Sprintf("user %d,", member))

	assert.True(t, user.AddMembership(member, group, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED,
		utils.FREQUENCY_DAILY, 1, 1, "", ""))

	assert.Equal(t, "unknown", addedOriginOf(t, "memberships", member, group))
	assert.Equal(t, "unknown", addedOriginOf(t, "memberships_history", member, group))
	if assert.Len(t, *reports, 1) {
		assert.Contains(t, (*reports)[0], "user.AddMembership")
	}
}

func TestJoinRecordsTheOriginTheClientSends(t *testing.T) {
	prefix := uniquePrefix("memorigin_join")
	group := CreateTestGroup(t, prefix)
	member := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, member)
	reports := recordOriginReports(t, fmt.Sprintf("user %d,", member))

	// manual is sent as well, as the frontend does for an API from before origin; origin wins.
	assert.Equal(t, 200, joinGroup(t, token, map[string]interface{}{
		"groupid": group, "origin": "posted", "manual": true,
	}))

	assert.Equal(t, "posted", addedOriginOf(t, "memberships", member, group))
	assert.Equal(t, "posted", addedOriginOf(t, "memberships_history", member, group))
	assert.Equal(t, "Auto", joinLogText(t, member, group), "the log text follows origin too")
	assert.Empty(t, *reports)
}

// The Joined log's text comes from origin when the client sends one, so the log and
// added_origin cannot disagree: manual is a Join button, anything else joined automatically.
func TestJoinLogTextFollowsTheOrigin(t *testing.T) {
	prefix := uniquePrefix("memorigin_logtext")
	group := CreateTestGroup(t, prefix)

	for origin, want := range map[string]string{"manual": "Manual", "replied": "Auto", "posted": "Auto", "signup": "Auto"} {
		member := CreateTestUser(t, prefix+"_"+origin, "User")
		_, token := CreateTestSession(t, member)

		assert.Equal(t, 200, joinGroup(t, token, map[string]interface{}{"groupid": group, "origin": origin, "manual": origin != "manual"}))
		assert.Equal(t, want, joinLogText(t, member, group), origin)
	}
}

// joinLogText reads the text of the member's Group/Joined log on group.
func joinLogText(t *testing.T, userid, groupid uint64) string {
	t.Helper()
	var text string
	database.DBConn.Raw("SELECT COALESCE(text, '') FROM logs WHERE type = 'Group' AND subtype = 'Joined' AND user = ? AND groupid = ? ORDER BY id DESC LIMIT 1",
		userid, groupid).Scan(&text)
	return text
}

// A client from before origin existed sends only the manual flag: true from a Join button, false
// from the reply flow.
func TestJoinWithoutOriginFallsBackToTheManualFlag(t *testing.T) {
	prefix := uniquePrefix("memorigin_manual")
	group := CreateTestGroup(t, prefix)

	for manual, want := range map[bool]string{true: "manual", false: "replied"} {
		member := CreateTestUser(t, fmt.Sprintf("%s_%v", prefix, manual), "User")
		_, token := CreateTestSession(t, member)

		assert.Equal(t, 200, joinGroup(t, token, map[string]interface{}{"groupid": group, "manual": manual}))
		assert.Equal(t, want, addedOriginOf(t, "memberships", member, group))
		assert.Equal(t, want, addedOriginOf(t, "memberships_history", member, group))
	}
}

func TestJoinWithNeitherOriginNorManualIsUnknownAndReported(t *testing.T) {
	prefix := uniquePrefix("memorigin_none")
	group := CreateTestGroup(t, prefix)
	member := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, member)
	reports := recordOriginReports(t, fmt.Sprintf("user %d,", member))

	assert.Equal(t, 200, joinGroup(t, token, map[string]interface{}{"groupid": group}),
		"a join must never fail because its origin is missing")

	assert.Equal(t, "unknown", addedOriginOf(t, "memberships", member, group))
	assert.Equal(t, "unknown", addedOriginOf(t, "memberships_history", member, group))
	if assert.Len(t, *reports, 1) {
		assert.Contains(t, (*reports)[0], "membership.addMemberToGroup")
	}
}

// rippled, tn_unaddressed, partner, moderator, subscribed and unknown are the server's to decide.
func TestJoinRefusesAnOriginTheClientMayNotSet(t *testing.T) {
	prefix := uniquePrefix("memorigin_refuse")
	group := CreateTestGroup(t, prefix)
	member := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, member)

	for _, origin := range []string{"rippled", "tn_unaddressed", "partner", "moderator", "unknown", "nonsense"} {
		assert.Equal(t, 400, joinGroup(t, token, map[string]interface{}{"groupid": group, "origin": origin}), origin)
	}
	assert.Equal(t, "", addedOriginOf(t, "memberships", member, group), "no membership was created")
}

func TestModeratorAddingSomeoneIsAModeratorAddWhateverTheRequestSays(t *testing.T) {
	prefix := uniquePrefix("memorigin_modadd")
	group := CreateTestGroup(t, prefix)
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	member := CreateTestUser(t, prefix+"_member", "User")

	assert.Equal(t, 200, joinGroup(t, modToken, map[string]interface{}{
		"userid": member, "groupid": group, "origin": "manual",
	}))

	assert.Equal(t, "moderator", addedOriginOf(t, "memberships", member, group))
	assert.Equal(t, "moderator", addedOriginOf(t, "memberships_history", member, group))
}

func TestPartnerJoinIsPartner(t *testing.T) {
	prefix := uniquePrefix("memorigin_partner")
	group := CreateTestGroup(t, prefix)
	key := insertTestPartnerKey(t, prefix, "test.com")

	url := fmt.Sprintf("/api/memberships?partner=%s&email=%s-gpartner@test.com&groupid=%d", key, prefix, group)
	resp, err := getApp().Test(httptest.NewRequest("PUT", url, nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&result))
	member := uint64(result["fduserid"].(float64))

	assert.Equal(t, "partner", addedOriginOf(t, "memberships", member, group))
	assert.Equal(t, "partner", addedOriginOf(t, "memberships_history", member, group))
}

// PUT /user with a groupid and no origin still creates the membership, as unknown, and reports
// it, so a signup form that forgets the origin shows up.
func TestSignupWithAGroupAndNoOriginIsUnknownAndReported(t *testing.T) {
	prefix := uniquePrefix("memorigin_signup")
	group := CreateTestGroup(t, prefix)

	// The account does not exist yet, so the report is found by its group.
	reports := recordOriginReports(t, fmt.Sprintf("group %d", group))

	payload, _ := json.Marshal(map[string]interface{}{
		"email": prefix + "@test.com", "firstname": "No", "lastname": "Origin", "groupid": group,
	})
	req := httptest.NewRequest("PUT", "/api/user", bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&result))
	member := uint64(result["id"].(float64))

	assert.Equal(t, "unknown", addedOriginOf(t, "memberships", member, group))
	assert.Equal(t, "unknown", addedOriginOf(t, "memberships_history", member, group))
	if assert.Len(t, *reports, 1) {
		assert.Contains(t, (*reports)[0], "user.PutUser")
	}
}

func TestSignupRefusesAnOriginTheClientMayNotSet(t *testing.T) {
	prefix := uniquePrefix("memorigin_signupbad")
	group := CreateTestGroup(t, prefix)
	email := prefix + "@test.com"

	payload, _ := json.Marshal(map[string]interface{}{
		"email": email, "firstname": "Bad", "lastname": "Origin", "groupid": group, "origin": "partner",
	})
	req := httptest.NewRequest("PUT", "/api/user", bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	var accounts int64
	database.DBConn.Table("users_emails").Where("email = ?", email).Count(&accounts)
	assert.Equal(t, int64(0), accounts, "the refusal comes before the account is created")
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
