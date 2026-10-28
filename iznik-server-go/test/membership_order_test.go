package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// GET /memberships lists newest membership first, or oldest first with order=oldest, and pages with a
// cursor that follows the order.

type orderedPage struct {
	Members []struct {
		ID     uint64 `json:"id"`
		Userid uint64 `json:"userid"`
	} `json:"members"`
	Context interface{} `json:"context"`
	Order   string      `json:"order"`
}

func getOrderedPage(t *testing.T, url string) orderedPage {
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var page orderedPage
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&page))
	return page
}

// A community with a moderator and n members, returning the moderator's token and the members' user ids in
// the order they joined.
func orderedGroup(t *testing.T, prefix string, n int) (uint64, string, []uint64) {
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	var ids []uint64
	for i := 0; i < n; i++ {
		uid := CreateTestUser(t, fmt.Sprintf("%s_m%02d", prefix, i), "User")
		CreateTestMembership(t, uid, groupID, "Member")
		ids = append(ids, uid)
	}
	return groupID, token, ids
}

func userids(page orderedPage) []uint64 {
	var out []uint64
	for _, m := range page.Members {
		out = append(out, m.Userid)
	}
	return out
}

func TestMembershipsOrder_NewestByDefaultAndSaysSo(t *testing.T) {
	prefix := uniquePrefix("mord_new")
	groupID, token, ids := orderedGroup(t, prefix, 3)

	page := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&limit=10&jwt=%s", groupID, token))
	assert.Equal(t, "newest", page.Order)
	got := userids(page)
	// The moderator joined first, so is last.
	assert.Equal(t, []uint64{ids[2], ids[1], ids[0]}, got[:3])
}

func TestMembershipsOrder_OldestPagesForwards(t *testing.T) {
	prefix := uniquePrefix("mord_old")
	groupID, token, ids := orderedGroup(t, prefix, 4)

	base := fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&order=oldest&limit=3&jwt=%s", groupID, token)
	page1 := getOrderedPage(t, base)
	assert.Equal(t, "oldest", page1.Order)
	// The moderator joined first.
	got1 := userids(page1)
	assert.Len(t, got1, 3)
	assert.Equal(t, []uint64{ids[0], ids[1]}, got1[1:])
	assert.NotNil(t, page1.Context, "a full page carries a cursor")

	page2 := getOrderedPage(t, fmt.Sprintf("%s&context=%d", base, uint64(page1.Context.(float64))))
	assert.Equal(t, []uint64{ids[2], ids[3]}, userids(page2), "the next page continues after the cursor, oldest first")
	assert.Nil(t, page2.Context)
}

func TestMembershipsOrder_NewestPagesBackwards(t *testing.T) {
	prefix := uniquePrefix("mord_newp")
	groupID, token, ids := orderedGroup(t, prefix, 4)

	base := fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&order=newest&limit=2&jwt=%s", groupID, token)
	page1 := getOrderedPage(t, base)
	assert.Equal(t, []uint64{ids[3], ids[2]}, userids(page1))
	page2 := getOrderedPage(t, fmt.Sprintf("%s&context=%d", base, uint64(page1.Context.(float64))))
	assert.Equal(t, []uint64{ids[1], ids[0]}, userids(page2))
}

func TestMembershipsOrder_AnythingElseIsNewest(t *testing.T) {
	prefix := uniquePrefix("mord_bad")
	groupID, token, ids := orderedGroup(t, prefix, 2)

	page := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&order=sideways&limit=10&jwt=%s", groupID, token))
	assert.Equal(t, "newest", page.Order)
	assert.Equal(t, ids[1], userids(page)[0])
}

func TestMembershipsOrder_WorksWithAFilter(t *testing.T) {
	prefix := uniquePrefix("mord_filt")
	groupID, token, ids := orderedGroup(t, prefix, 3)
	db := database.DBConn
	// Notes on the first and last member only: filter 1 is "With notes".
	db.Exec("INSERT INTO users_comments (userid, groupid, byuserid, user1) VALUES (?, ?, ?, 'note')", ids[0], groupID, ids[0])
	db.Exec("INSERT INTO users_comments (userid, groupid, byuserid, user1) VALUES (?, ?, ?, 'note')", ids[2], groupID, ids[2])

	oldest := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&filter=1&order=oldest&limit=10&jwt=%s", groupID, token))
	assert.Equal(t, "oldest", oldest.Order)
	assert.Equal(t, []uint64{ids[0], ids[2]}, userids(oldest))

	newest := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&filter=1&limit=10&jwt=%s", groupID, token))
	assert.Equal(t, []uint64{ids[2], ids[0]}, userids(newest))
}

func TestMembershipsOrder_WorksWithASearch(t *testing.T) {
	prefix := uniquePrefix("mord_srch")
	groupID, token, ids := orderedGroup(t, prefix, 3)

	// Every member's name starts with the prefix; the moderator's ends in _mod, so search the members' part.
	oldest := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&search=%s_m&order=oldest&limit=10&jwt=%s", groupID, prefix, token))
	assert.Equal(t, "oldest", oldest.Order)
	// _m also matches _mod, who joined first.
	got := userids(oldest)
	assert.Equal(t, []uint64{ids[0], ids[1], ids[2]}, got[len(got)-3:])

	byID := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&search=%d&order=oldest&limit=10&jwt=%s", groupID, ids[1], token))
	assert.Equal(t, []uint64{ids[1]}, userids(byID))
}

func TestMembershipsOrder_BannedFollowsTheOrder(t *testing.T) {
	prefix := uniquePrefix("mord_ban")
	groupID, token, ids := orderedGroup(t, prefix, 3)
	for _, id := range ids {
		createBannedMember(t, id, groupID)
	}

	oldest := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&filter=5&order=oldest&limit=2&jwt=%s", groupID, token))
	assert.Equal(t, "oldest", oldest.Order)
	assert.Equal(t, []uint64{ids[0], ids[1]}, userids(oldest))
	next := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&filter=5&order=oldest&limit=2&context=%d&jwt=%s", groupID, uint64(oldest.Context.(float64)), token))
	assert.Equal(t, []uint64{ids[2]}, userids(next))

	newest := getOrderedPage(t, fmt.Sprintf("/api/memberships?groupid=%d&filter=5&limit=10&jwt=%s", groupID, token))
	assert.Equal(t, []uint64{ids[2], ids[1], ids[0]}, userids(newest))
}

func TestMembershipsOrder_ModMailsFollowTheOrder(t *testing.T) {
	prefix := uniquePrefix("mord_mm")
	groupID, token, ids := orderedGroup(t, prefix, 2)
	db := database.DBConn
	db.Exec("UPDATE memberships SET added = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE userid = ? AND groupid = ?", ids[0], groupID)
	for _, id := range ids {
		db.Exec("INSERT INTO logs (type, subtype, groupid, user, byuser, timestamp, text) VALUES ('User', 'Mailed', ?, ?, 1, NOW(), 'test modmail')", groupID, id)
	}

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/memberships?groupid=%d&filter=6&order=oldest&jwt=%s", groupID, token), nil), -1)
	assert.NoError(t, err)
	var members []map[string]interface{}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&members))
	assert.Len(t, members, 2)
	assert.Equal(t, float64(ids[0]), members[0]["userid"], "oldest joiner first")
	assert.Equal(t, float64(ids[1]), members[1]["userid"])
}
