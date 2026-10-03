package test

import (
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Trash Nothing is the master for a TN member's location. settings.mylocation on a TN
// account is V1-era data from before it was linked to TN, so it is ignored and
// lastlocation (kept in step with TN by tn:sync) is used instead.

// EH3 6SS, area Edinburgh - seeded by setupLocationTestData.
const tnLocTNPostcode = 1000001

const staleTamesideSetting = `{"mylocation":{"id":5982012,"name":"M34 6PB","lat":53.453008,"lng":-2.103187,` +
	`"area":{"id":170240,"name":"Tameside"}}}`

func createUserWithStaleMylocation(t *testing.T, prefix string, tn bool) uint64 {
	db := database.DBConn
	setupLocationTestData()
	uid := CreateTestUser(t, prefix, "User")
	db.Exec("UPDATE users SET lastlocation = ?, settings = ? WHERE id = ?", tnLocTNPostcode, staleTamesideSetting, uid)
	if tn {
		db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 900000000+uid, uid)
	}
	return uid
}

func TestTNMemberLatLngIgnoresFDSetting(t *testing.T) {
	uid := createUserWithStaleMylocation(t, uniquePrefix("tnloc_ll"), true)

	ll := user.GetLatLng(uid)
	assert.InDelta(t, 55.957571, ll.Lat, 0.001)
	assert.InDelta(t, -3.205333, ll.Lng, 0.001)
}

func TestNonTNMemberLatLngUsesFDSetting(t *testing.T) {
	uid := createUserWithStaleMylocation(t, uniquePrefix("tnloc_llfd"), false)

	ll := user.GetLatLng(uid)
	assert.InDelta(t, 53.453008, ll.Lat, 0.001)
}

func TestTNMemberPublicLocationIgnoresFDSetting(t *testing.T) {
	uid := createUserWithStaleMylocation(t, uniquePrefix("tnloc_pub"), true)

	loc := user.GetPublicLocationForUser(uid)
	require.NotNil(t, loc)
	assert.Equal(t, "Edinburgh", loc.Display)
}

func TestNonTNMemberPublicLocationUsesFDSetting(t *testing.T) {
	uid := createUserWithStaleMylocation(t, uniquePrefix("tnloc_pubfd"), false)

	loc := user.GetPublicLocationForUser(uid)
	require.NotNil(t, loc)
	assert.Equal(t, "Tameside", loc.Display)
}

func TestTNMemberModtoolsLocationNameIgnoresFDSetting(t *testing.T) {
	prefix := uniquePrefix("tnloc_mt")
	uid := createUserWithStaleMylocation(t, prefix, true)
	adminID, token := CreateFullTestUser(t, prefix+"_admin")
	database.DBConn.Exec("UPDATE users SET systemrole = 'Admin' WHERE id = ?", adminID)

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/user/%d?modtools=true&jwt=%s", uid, token), nil))
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	assert.NotContains(t, string(rsp(resp)), "M34 6PB")
}
