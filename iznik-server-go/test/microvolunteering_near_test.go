package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A post is only shown in browse to members inside its reach, so a review task must not carry it
// to someone far away. Test users live in Edinburgh.

// The oldest unchecked post of the day is offered first. A London post that is older than every
// Edinburgh one must still not be offered to an Edinburgh member.
func TestMicrovolunteering_FarPostIsNotOffered(t *testing.T) {
	prefix := uniquePrefix("mv_far")
	reviewer := CreateTestUser(t, prefix+"_rev", "User")
	_, token := CreateTestSession(t, reviewer)
	poster := CreateTestUser(t, prefix+"_poster", "User")

	far := CreateTestMessage(t, poster, "Far post "+prefix, 51.5074, -0.1278)
	near := CreateTestMessage(t, poster, "Near post "+prefix, 55.9533, -3.1883)
	database.DBConn.Exec("UPDATE messages SET arrival = CURDATE() WHERE id = ?", far)
	database.DBConn.Exec("UPDATE messages SET arrival = CURDATE() + INTERVAL 1 SECOND WHERE id = ?", near)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?types=CheckMessage&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	challenge, _ := body["microvolunteering"].(map[string]interface{})
	if challenge == nil {
		challenge = body
	}

	assert.NotEqual(t, float64(far), challenge["msgid"], "a London post must not be offered in Edinburgh: %v", body)
	assert.Equal(t, float64(near), challenge["msgid"], "%v", body)
}

// A vote can only be cast on a post that could have been offered.
func TestMicrovolunteering_CannotVoteOnFarPost(t *testing.T) {
	prefix := uniquePrefix("mv_farvote")
	reviewer := CreateTestUser(t, prefix+"_rev", "User")
	_, token := CreateTestSession(t, reviewer)
	poster := CreateTestUser(t, prefix+"_poster", "User")

	far := CreateTestMessage(t, poster, "Far vote "+prefix, 51.5074, -0.1278)

	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token,
		strings.NewReader(fmt.Sprintf(`{"msgid":%d,"response":"Reject"}`, far)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM microactions WHERE userid = ? AND msgid = ?", reviewer, far).Scan(&count)
	assert.Equal(t, int64(0), count)
}
