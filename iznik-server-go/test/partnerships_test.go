package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The partnerships tests work against a made-up council whose boundary contains a
// made-up group, so the group-overlap detection has something real to find.

// createPartnershipAuthority makes an authority covering a square of Scotland.
func createPartnershipAuthority(t *testing.T, prefix string) uint64 {
	db := database.DBConn
	name := "TestAuthority_" + prefix

	result := db.Exec("INSERT INTO authorities (name, polygon) VALUES (?, "+
		"ST_GeomFromText('POLYGON((-4 55, -3 55, -3 57, -4 57, -4 55))', 3857))", name)
	require.NoError(t, result.Error)

	var id uint64
	db.Raw("SELECT id FROM authorities WHERE name = ? ORDER BY id DESC LIMIT 1", name).Scan(&id)
	require.NotZero(t, id)

	t.Cleanup(func() {
		db.Exec("DELETE FROM authorities WHERE id = ?", id)
	})

	return id
}

// createPartnershipGroup makes a published, on-map Freegle group with a real catchment
// polygon sitting inside the test authority, so the overlap calculation returns it.
func createPartnershipGroup(t *testing.T, prefix string) uint64 {
	db := database.DBConn
	name := "TestPGroup_" + prefix

	result := db.Exec(fmt.Sprintf("INSERT INTO `groups` (nameshort, namefull, type, onhere, publish, onmap, "+
		"polyindex, lat, lng) VALUES (?, ?, 'Freegle', 1, 1, 1, "+
		"ST_GeomFromText('POLYGON((-3.9 55.1, -3.1 55.1, -3.1 56.9, -3.9 56.9, -3.9 55.1))', %d), 55.9533, -3.1883)",
		utils.SRID), name, "Test Partnership Group "+prefix)
	require.NoError(t, result.Error)

	var id uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", name).Scan(&id)
	require.NotZero(t, id)

	return id
}

// partnershipsUser makes a user on the Partnerships team, which is the audience for the page.
func partnershipsUser(t *testing.T, prefix string) (uint64, string) {
	db := database.DBConn

	// The team is created by migration; make sure it exists for a bare test schema.
	db.Exec("INSERT IGNORE INTO teams (name, description, type) VALUES ('Partnerships', 'Partnerships', 'Team')")

	var teamID uint64
	db.Raw("SELECT id FROM teams WHERE name = 'Partnerships'").Scan(&teamID)
	require.NotZero(t, teamID)

	userID := CreateTestUser(t, prefix+"_partner", "Moderator")
	db.Exec("INSERT IGNORE INTO teams_members (userid, teamid) VALUES (?, ?)", userID, teamID)

	_, token := CreateTestSession(t, userID)

	return userID, token
}

// createPartnership posts a partnership and returns its id.
func createPartnership(t *testing.T, token string, authorityID uint64, body string) uint64 {
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	require.Equal(t, float64(0), result["ret"], "create should succeed")

	id := uint64(result["id"].(float64))
	require.NotZero(t, id)

	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM partnerships WHERE id = ?", id)
	})

	return id
}

func defaultBody(authorityID uint64) string {
	return fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31",`+
		`"amount":6000,"tagline":"Reuse in your area","description":"Your council supports Freegle",`+
		`"linkurl":"https://example.gov.uk/reuse","status":"Confirmed"}`, authorityID)
}

// A refusal has to withhold the data as well as set the status. Checking only the status
// code missed a bypass where the handler ran anyway and overwrote the refusal with the
// deals, so every one of these asserts on the body too.
func TestPartnershipRequiresLogin(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/partnership", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotContains(t, result, "partnerships")
}

func TestPartnershipRequiresTeamMembership(t *testing.T) {
	prefix := uniquePrefix("PartnershipNoPerm")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotContains(t, result, "partnerships")
}

func TestPartnershipSummaryRequiresLogin(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/partnership/summary", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotContains(t, result, "summary")
}

func TestPartnershipStatsJobsRequireLogin(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/partnership/statsjob", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotContains(t, result, "jobs")
}

// A refused write must not write. The bypass let the handler run on, so a logged-out
// caller could have created a deal.
func TestPartnershipCreateRefusedWithoutLoginCreatesNothing(t *testing.T) {
	prefix := uniquePrefix("PartnershipNoCreate")
	authorityID := createPartnershipAuthority(t, prefix)

	req := httptest.NewRequest("POST", "/api/partnership", strings.NewReader(defaultBody(authorityID)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)

	var count int64
	database.DBConn.Table("partnerships").Where("authorityid = ?", authorityID).Count(&count)
	assert.Equal(t, int64(0), count)
}

func TestPartnershipTeamMemberIsAllowed(t *testing.T) {
	prefix := uniquePrefix("PartnershipPerm")
	_, token := partnershipsUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "partnerships")
}

func TestPartnershipAdminIsAllowedWithoutTeam(t *testing.T) {
	prefix := uniquePrefix("PartnershipAdmin")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestPartnershipCreateDefaultsNameToAuthority(t *testing.T) {
	prefix := uniquePrefix("PartnershipCreate")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	p := result["partnership"].(map[string]interface{})

	assert.Equal(t, "TestAuthority_"+prefix, p["name"], "name defaults to the council's own name")
	assert.Equal(t, "2026-04-01", p["startdate"], "dates come back as plain YYYY-MM-DD")
	assert.Equal(t, "2027-03-31", p["enddate"])
	assert.Equal(t, float64(6000), p["amount"])
	assert.Equal(t, "Confirmed", p["status"])
	assert.Equal(t, "Reuse in your area", p["tagline"])
}

func TestPartnershipCreateRejectsMissingAuthority(t *testing.T) {
	prefix := uniquePrefix("PartnershipNoAuth")
	_, token := partnershipsUser(t, prefix)

	body := `{"startdate":"2026-04-01","enddate":"2027-03-31"}`
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPartnershipCreateRejectsUnknownAuthority(t *testing.T) {
	prefix := uniquePrefix("PartnershipBadAuth")
	_, token := partnershipsUser(t, prefix)

	body := `{"authorityid":999999999,"startdate":"2026-04-01","enddate":"2027-03-31"}`
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestPartnershipCreateRejectsMissingDates(t *testing.T) {
	prefix := uniquePrefix("PartnershipNoDates")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityid":%d}`, authorityID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

// getPartnership fetches one partnership's full detail.
func getPartnership(t *testing.T, token string, id uint64) map[string]interface{} {
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	return result
}

// findGroup picks one group out of a partnership's detail, or nil.
func findGroup(detail map[string]interface{}, groupID uint64) map[string]interface{} {
	for _, g := range detail["groups"].([]interface{}) {
		gm := g.(map[string]interface{})
		if uint64(gm["groupid"].(float64)) == groupID {
			return gm
		}
	}

	return nil
}

func patchGroup(t *testing.T, token string, id uint64, body string) {
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d/group?jwt=%s", id, token),
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)
}

func sponsorshipCount(groupID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM groups_sponsorship WHERE groupid = ?", groupID).Scan(&n)

	return n
}

func TestPartnershipDetectsCoveredGroups(t *testing.T) {
	prefix := uniquePrefix("PartnershipGroups")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	g := findGroup(getPartnership(t, token, id), groupID)
	require.NotNil(t, g, "the group inside the council boundary is covered by the deal")
	assert.Equal(t, "Boundary", g["source"])
	require.NotNil(t, g["overlap"], "the page shows how much of the community is inside the boundary")
	assert.InDelta(t, 1.0, g["overlap"].(float64), 0.001)
}

// createGrazingGroup makes a community that is mostly east of the test authority's -3 edge:
// 0.02 of its width is inside, and it covers a sliver of the authority. Southend against
// Essex County is the real case.
func createGrazingGroup(t *testing.T, prefix string) uint64 {
	db := database.DBConn
	name := "TestPGroupGraze_" + prefix
	db.Exec(fmt.Sprintf("INSERT INTO `groups` (nameshort, namefull, type, onhere, publish, onmap, "+
		"polyindex, lat, lng) VALUES (?, ?, 'Freegle', 1, 1, 1, "+
		"ST_GeomFromText('POLYGON((-3.02 55.5, -2.02 55.5, -2.02 55.6, -3.02 55.6, -3.02 55.5))', %d), 55.55, -2.5)",
		utils.SRID), name, name)
	var id uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ?", name).Scan(&id)
	require.NotZero(t, id)

	return id
}

// A community that only grazes the boundary is not covered: listing Southend as part of Essex
// County's deal over 1% of its area is not honest. Communities added by hand are unaffected.
func TestPartnershipIgnoresACommunityThatOnlyGrazesTheBoundary(t *testing.T) {
	prefix := uniquePrefix("PartnershipGraze")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	insideID := createPartnershipGroup(t, prefix)
	grazeID := createGrazingGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	assert.NotNil(t, findGroup(getPartnership(t, token, id), insideID))
	assert.Nil(t, findGroup(getPartnership(t, token, id), grazeID))
	assert.Equal(t, int64(0), sponsorshipCount(grazeID))
}

// A grazing community can still be added by hand, and then counts in full.
func TestPartnershipCanAddAGrazingCommunityByHand(t *testing.T) {
	prefix := uniquePrefix("PartnershipGrazeAdd")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	grazeID := createGrazingGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))
	patchGroup(t, token, id, fmt.Sprintf(`{"action":"Add","groupid":%d}`, grazeID))

	g := findGroup(getPartnership(t, token, id), grazeID)
	require.NotNil(t, g)
	assert.Equal(t, "Added", g["source"])
}

// The authority stats page still lists the grazing community, weighted by its small share:
// that is intended. It is flagged so the Partnerships page can leave it out.
func TestAuthorityStatsPageStillListsAGrazingCommunity(t *testing.T) {
	prefix := uniquePrefix("AuthorityGraze")
	authorityID := createPartnershipAuthority(t, prefix)
	insideID := createPartnershipGroup(t, prefix)
	grazeID := createGrazingGroup(t, prefix)

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/authority/%d", authorityID), nil))
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var body struct {
		Groups []struct {
			ID          uint64  `json:"id"`
			Overlap     float64 `json:"overlap"`
			Coverage    float64 `json:"coverage"`
			Significant bool    `json:"significant"`
		} `json:"groups"`
	}
	require.NoError(t, json2.NewDecoder(resp.Body).Decode(&body))

	found := map[uint64]bool{}
	for _, g := range body.Groups {
		found[g.ID] = g.Significant
		if g.ID == grazeID {
			assert.InDelta(t, 0.02, g.Overlap, 0.005)
			assert.Less(t, g.Coverage, 0.05)
		}
	}

	assert.Contains(t, found, grazeID, "the stats page keeps every community that touches the council")
	assert.False(t, found[grazeID])
	assert.True(t, found[insideID])
}

func TestPartnershipCreateCanLeaveOutAndAddCommunities(t *testing.T) {
	prefix := uniquePrefix("PartnershipCreateGroups")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	insideID := createPartnershipGroup(t, prefix)
	outsideID := CreateTestGroup(t, prefix+"_outside")

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","status":"Confirmed",`+
		`"excludegroupids":[%d],"includegroupids":[%d]}`, authorityID, insideID, outsideID)
	id := createPartnership(t, token, authorityID, body)

	detail := getPartnership(t, token, id)

	inside := findGroup(detail, insideID)
	require.NotNil(t, inside)
	assert.Equal(t, "Removed", inside["source"])
	assert.Equal(t, int64(0), sponsorshipCount(insideID))

	outside := findGroup(detail, outsideID)
	require.NotNil(t, outside)
	assert.Equal(t, "Added", outside["source"])
	assert.Nil(t, outside["overlap"], "a community outside the boundary has no overlap")
	assert.Equal(t, int64(1), sponsorshipCount(outsideID))
}

func TestPartnershipRemoveGroupDropsItsSponsorship(t *testing.T) {
	prefix := uniquePrefix("PartnershipDropGroup")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	patchGroup(t, token, id, fmt.Sprintf(`{"action":"Remove","groupid":%d}`, groupID))
	assert.Equal(t, int64(0), sponsorshipCount(groupID))

	g := findGroup(getPartnership(t, token, id), groupID)
	require.NotNil(t, g, "a community left out is remembered, so a boundary check does not bring it back")
	assert.Equal(t, "Removed", g["source"])

	patchGroup(t, token, id, `{"action":"Redetect"}`)
	assert.Equal(t, "Removed", findGroup(getPartnership(t, token, id), groupID)["source"])
	assert.Equal(t, int64(0), sponsorshipCount(groupID))

	// Putting it back makes it a boundary community again.
	patchGroup(t, token, id, fmt.Sprintf(`{"action":"Add","groupid":%d}`, groupID))
	assert.Equal(t, "Boundary", findGroup(getPartnership(t, token, id), groupID)["source"])
	assert.Equal(t, int64(1), sponsorshipCount(groupID))
}

func TestPartnershipAddGroupCreatesSponsorship(t *testing.T) {
	prefix := uniquePrefix("PartnershipAddGroup")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	// A group nowhere near the council - added by hand because the deal covers it anyway.
	outsideGroup := CreateTestGroup(t, prefix+"_outside")

	patchGroup(t, token, id, fmt.Sprintf(`{"action":"Add","groupid":%d}`, outsideGroup))
	assert.Equal(t, int64(1), sponsorshipCount(outsideGroup))
	assert.Equal(t, "Added", findGroup(getPartnership(t, token, id), outsideGroup)["source"])

	// A boundary check leaves a community added by hand alone.
	patchGroup(t, token, id, `{"action":"Redetect"}`)
	assert.NotNil(t, findGroup(getPartnership(t, token, id), outsideGroup))

	// Removing one added by hand forgets it entirely.
	patchGroup(t, token, id, fmt.Sprintf(`{"action":"Remove","groupid":%d}`, outsideGroup))
	assert.Nil(t, findGroup(getPartnership(t, token, id), outsideGroup))
	assert.Equal(t, int64(0), sponsorshipCount(outsideGroup))
}

// A community created inside the boundary after the deal was set up is picked up by the next
// boundary check without anyone adding it.
func TestPartnershipRedetectPicksUpANewCommunity(t *testing.T) {
	prefix := uniquePrefix("PartnershipNewGroup")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	newGroup := createPartnershipGroup(t, prefix+"_new")
	patchGroup(t, token, id, `{"action":"Redetect"}`)

	g := findGroup(getPartnership(t, token, id), newGroup)
	require.NotNil(t, g)
	assert.Equal(t, "Boundary", g["source"])
	assert.Equal(t, int64(1), sponsorshipCount(newGroup))
}

func TestPartnershipStatusesShownToMembers(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatus")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, fmt.Sprintf(
		`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","status":"Quoted"}`, authorityID))

	visible := func() bool {
		var v bool
		database.DBConn.Raw("SELECT visible FROM groups_sponsorship WHERE groupid = ? ORDER BY id DESC LIMIT 1",
			groupID).Scan(&v)

		return v
	}

	for _, tc := range []struct {
		status string
		shown  bool
	}{
		{"Quoted", false},
		{"InPrinciple", false},
		{"Confirmed", true},
		{"Paid", true},
		{"Overdue", true},
	} {
		req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token),
			strings.NewReader(`{"status":"`+tc.status+`"}`))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		require.Equal(t, 200, resp.StatusCode)

		assert.Equal(t, tc.shown, visible(), tc.status)
	}
}

func TestPartnershipRejectsUnknownStatus(t *testing.T) {
	prefix := uniquePrefix("PartnershipBadStatus")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	for _, body := range []string{
		fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","status":"Agreed"}`, authorityID),
		fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","renewal":"Maybe"}`, authorityID),
		fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","contacts":[{"name":"A","role":"Boss"}]}`, authorityID),
	} {
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership?jwt=%s", token), strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		assert.Equal(t, 400, resp.StatusCode, body)
	}
}

func TestPartnershipRenewalAndBulkDiscount(t *testing.T) {
	prefix := uniquePrefix("PartnershipRenewal")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, fmt.Sprintf(
		`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","amount":2700,"fullprice":3000,"renewal":"Unsure"}`,
		authorityID))

	p := getPartnership(t, token, id)["partnership"].(map[string]interface{})
	assert.Equal(t, "Unsure", p["renewal"])
	assert.Equal(t, float64(3000), p["fullprice"])
	assert.Equal(t, "Quoted", p["status"], "a new deal starts as a quote")

	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token),
		strings.NewReader(`{"renewal":"","fullprice":0}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	p = getPartnership(t, token, id)["partnership"].(map[string]interface{})
	assert.Nil(t, p["renewal"], "an empty renewal clears the traffic light")
	assert.Nil(t, p["fullprice"], "zero means there was no bulk discount")
}

func TestPartnershipContacts(t *testing.T) {
	prefix := uniquePrefix("PartnershipContacts")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, fmt.Sprintf(
		`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","status":"Confirmed","contacts":[`+
			`{"name":"Fred Finance","email":"fred@example.gov.uk","role":"Finance"},`+
			`{"name":"","email":"","role":"Other"},`+
			`{"name":"Wendy Waste","email":"wendy@example.gov.uk","role":"Waste"}]}`, authorityID))

	contacts := getPartnership(t, token, id)["contacts"].([]interface{})
	require.Len(t, contacts, 2, "a blank line is not a contact")
	assert.Equal(t, "Finance", contacts[0].(map[string]interface{})["role"])
	assert.Equal(t, "Wendy Waste", contacts[1].(map[string]interface{})["name"])

	// The member-site sponsorship carries the waste-team contact, not the first one entered.
	var sponsorContact string
	database.DBConn.Raw("SELECT contactname FROM groups_sponsorship WHERE groupid = ? ORDER BY id DESC LIMIT 1",
		groupID).Scan(&sponsorContact)
	assert.Equal(t, "Wendy Waste", sponsorContact)

	// Sending the list replaces it.
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token),
		strings.NewReader(`{"contacts":[{"name":"Only One","email":"one@example.gov.uk","role":"Other"}]}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	contacts = getPartnership(t, token, id)["contacts"].([]interface{})
	require.Len(t, contacts, 1)
	assert.Equal(t, "Only One", contacts[0].(map[string]interface{})["name"])
}

func TestPartnershipUploadedLogoBecomesADeliveryURL(t *testing.T) {
	prefix := uniquePrefix("PartnershipLogo")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	db := database.DBConn
	uid := "freegletusd-" + prefix
	db.Exec("INSERT INTO groups_images (externaluid, contenttype) VALUES (?, 'image/jpeg')", uid)
	var imageID uint64
	db.Raw("SELECT id FROM groups_images WHERE externaluid = ?", uid).Scan(&imageID)
	require.NotZero(t, imageID)
	t.Cleanup(func() { db.Exec("DELETE FROM groups_images WHERE id = ?", imageID) })

	id := createPartnership(t, token, authorityID, fmt.Sprintf(
		`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","imageid":%d}`, authorityID, imageID))

	p := getPartnership(t, token, id)["partnership"].(map[string]interface{})
	require.NotNil(t, p["imageurl"])
	assert.Contains(t, p["imageurl"], "?url=")
	assert.Contains(t, p["imageurl"], prefix, "the delivery URL points at the uploaded file")
	assert.NotContains(t, p["imageurl"], "freegletusd-")
}

func TestPartnershipHistoryListsEveryDealWithTheCouncil(t *testing.T) {
	prefix := uniquePrefix("PartnershipHistory")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	older := createPartnership(t, token, authorityID, fmt.Sprintf(
		`{"authorityid":%d,"startdate":"2022-04-01","enddate":"2023-03-31","amount":500,"status":"Paid"}`, authorityID))
	newer := createPartnership(t, token, authorityID, fmt.Sprintf(
		`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","amount":900,"status":"Confirmed"}`, authorityID))

	history := getPartnership(t, token, newer)["history"].([]interface{})
	require.Len(t, history, 2)
	assert.Equal(t, float64(newer), history[0].(map[string]interface{})["id"], "newest first")
	assert.Equal(t, float64(older), history[1].(map[string]interface{})["id"])
	assert.Equal(t, "Paid", history[1].(map[string]interface{})["status"])
}

func TestPartnershipSummaryFollowsThePipeline(t *testing.T) {
	prefix := uniquePrefix("PartnershipStages")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	summary := func() map[string]interface{} {
		req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/summary?jwt=%s", token), nil)
		resp, _ := getApp().Test(req)

		var result map[string]interface{}
		json2.Unmarshal(rsp(resp), &result)

		return result["summary"].(map[string]interface{})
	}

	before := summary()

	for _, status := range []string{"Quoted", "InPrinciple", "Overdue"} {
		createPartnership(t, token, authorityID, fmt.Sprintf(
			`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","amount":100,"status":"%s"}`,
			authorityID, status))
	}

	after := summary()
	for _, key := range []string{"quoted", "inprinciple", "committed", "overdue"} {
		assert.InDelta(t, 100, after[key].(float64)-before[key].(float64), 0.001, key)
	}
	assert.InDelta(t, 100, after["tocome"].(float64)-before["tocome"].(float64), 0.001,
		"committed money not yet received is still to come")
}

func TestPartnershipWritesSponsorshipRows(t *testing.T) {
	prefix := uniquePrefix("PartnershipSponsor")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	createPartnership(t, token, authorityID, defaultBody(authorityID))

	db := database.DBConn
	var sponsor struct {
		Name    string
		Tagline *string
		Linkurl *string
		Visible bool
		Amount  int
	}
	db.Raw("SELECT name, tagline, linkurl, visible, amount FROM groups_sponsorship WHERE groupid = ? "+
		"ORDER BY id DESC LIMIT 1", groupID).Scan(&sponsor)

	assert.Equal(t, "TestAuthority_"+prefix, sponsor.Name)
	require.NotNil(t, sponsor.Tagline)
	assert.Equal(t, "Reuse in your area", *sponsor.Tagline)
	require.NotNil(t, sponsor.Linkurl)
	assert.Equal(t, "https://example.gov.uk/reuse", *sponsor.Linkurl)
	assert.True(t, sponsor.Visible, "a confirmed, visible deal shows on the member site")
	assert.Equal(t, 6000, sponsor.Amount)
}

func TestPartnershipNotAgreedIsHiddenFromMembers(t *testing.T) {
	prefix := uniquePrefix("PartnershipUnagreed")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","amount":100,"status":"InPrinciple"}`,
		authorityID)
	createPartnership(t, token, authorityID, body)

	db := database.DBConn
	var visible bool
	db.Raw("SELECT visible FROM groups_sponsorship WHERE groupid = ? ORDER BY id DESC LIMIT 1", groupID).Scan(&visible)

	assert.False(t, visible, "a deal only agreed in principle must not be advertised")
}

func TestPartnershipUpdateChangesSponsorship(t *testing.T) {
	prefix := uniquePrefix("PartnershipEdit")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	body := `{"tagline":"New tagline","description":"New description","linkurl":"https://example.gov.uk/new"}`
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	db := database.DBConn
	var sponsor struct {
		Tagline     *string
		Description *string
		Linkurl     *string
	}
	db.Raw("SELECT tagline, description, linkurl FROM groups_sponsorship WHERE groupid = ? "+
		"ORDER BY id DESC LIMIT 1", groupID).Scan(&sponsor)

	require.NotNil(t, sponsor.Tagline)
	assert.Equal(t, "New tagline", *sponsor.Tagline)
	require.NotNil(t, sponsor.Description)
	assert.Equal(t, "New description", *sponsor.Description)
	require.NotNil(t, sponsor.Linkurl)
	assert.Equal(t, "https://example.gov.uk/new", *sponsor.Linkurl)
}

func TestPartnershipUpdateCanClearTagline(t *testing.T) {
	prefix := uniquePrefix("PartnershipClear")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	body := `{"tagline":""}`
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	db := database.DBConn
	var tagline *string
	db.Raw("SELECT tagline FROM partnerships WHERE id = ?", id).Scan(&tagline)

	assert.Nil(t, tagline, "an empty tagline clears the field rather than being ignored")
}

func TestPartnershipUpdateOfUnknownIdIs404(t *testing.T) {
	prefix := uniquePrefix("PartnershipEdit404")
	_, token := partnershipsUser(t, prefix)

	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/999999999?jwt=%s", token),
		strings.NewReader(`{"tagline":"x"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestPartnershipDeleteRemovesSponsorship(t *testing.T) {
	prefix := uniquePrefix("PartnershipDelete")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	db := database.DBConn
	var before int64
	db.Raw("SELECT COUNT(*) FROM groups_sponsorship WHERE groupid = ?", groupID).Scan(&before)
	require.Equal(t, int64(1), before)

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var after int64
	db.Raw("SELECT COUNT(*) FROM groups_sponsorship WHERE groupid = ?", groupID).Scan(&after)
	assert.Equal(t, int64(0), after, "deleting the deal takes the sponsor off the member site")

	var partnerships int64
	db.Raw("SELECT COUNT(*) FROM partnerships WHERE id = ?", id).Scan(&partnerships)
	assert.Equal(t, int64(0), partnerships)
}

func TestPartnershipGroupActionNeedsGroupid(t *testing.T) {
	prefix := uniquePrefix("PartnershipGroupBad")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d/group?jwt=%s", id, token),
		strings.NewReader(`{"action":"Add"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPartnershipGroupUnknownAction(t *testing.T) {
	prefix := uniquePrefix("PartnershipGroupAct")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d/group?jwt=%s", id, token),
		strings.NewReader(`{"action":"Wibble"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPartnershipPaymentsAndTotals(t *testing.T) {
	prefix := uniquePrefix("PartnershipPay")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	// Two invoices, only one of them settled.
	for _, body := range []string{
		`{"date":"2026-04-15","amount":3000,"paid":"2026-05-01","reference":"INV-1"}`,
		`{"date":"2026-10-15","amount":3000,"reference":"INV-2"}`,
	} {
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/%d/payment?jwt=%s", id, token),
			strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		require.Equal(t, 200, resp.StatusCode)
	}

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	p := result["partnership"].(map[string]interface{})
	assert.Equal(t, float64(6000), p["invoiced"], "both invoices count towards invoiced")
	assert.Equal(t, float64(3000), p["paid"], "only the settled one counts as paid")

	payments := result["payments"].([]interface{})
	require.Len(t, payments, 2)
	first := payments[0].(map[string]interface{})
	assert.Equal(t, "2026-04-15", first["date"])
	assert.Equal(t, "2026-05-01", first["paid"])
}

func TestPartnershipPaymentMarkedPaidThenUnpaid(t *testing.T) {
	prefix := uniquePrefix("PartnershipPayEdit")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/%d/payment?jwt=%s", id, token),
		strings.NewReader(`{"date":"2026-04-15","amount":1000}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var created map[string]interface{}
	json2.Unmarshal(rsp(resp), &created)
	paymentID := uint64(created["id"].(float64))
	require.NotZero(t, paymentID)

	// Mark it paid.
	req = httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d/payment/%d?jwt=%s", id, paymentID, token),
		strings.NewReader(`{"paid":"2026-05-02"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	db := database.DBConn
	var paid *string
	db.Raw("SELECT DATE_FORMAT(paid, '%Y-%m-%d') FROM partnerships_payments WHERE id = ?", paymentID).Scan(&paid)
	require.NotNil(t, paid)
	assert.Equal(t, "2026-05-02", *paid)

	// And back to unpaid - an empty date must clear it, not be ignored.
	req = httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d/payment/%d?jwt=%s", id, paymentID, token),
		strings.NewReader(`{"paid":""}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	db.Raw("SELECT DATE_FORMAT(paid, '%Y-%m-%d') FROM partnerships_payments WHERE id = ?", paymentID).Scan(&paid)
	assert.Nil(t, paid)
}

func TestPartnershipDeletePayment(t *testing.T) {
	prefix := uniquePrefix("PartnershipPayDel")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/%d/payment?jwt=%s", id, token),
		strings.NewReader(`{"date":"2026-04-15","amount":1000}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var created map[string]interface{}
	json2.Unmarshal(rsp(resp), &created)
	paymentID := uint64(created["id"].(float64))

	req = httptest.NewRequest("DELETE", fmt.Sprintf("/api/partnership/%d/payment/%d?jwt=%s", id, paymentID, token), nil)
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM partnerships_payments WHERE id = ?", paymentID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestPartnershipPaymentNeedsDate(t *testing.T) {
	prefix := uniquePrefix("PartnershipPayNoDate")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/%d/payment?jwt=%s", id, token),
		strings.NewReader(`{"amount":1000}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPartnershipProRatesAcrossFinancialYears(t *testing.T) {
	prefix := uniquePrefix("PartnershipFY")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	// A three-year deal, so the money should show across three financial years.
	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2029-03-31","amount":9000,"status":"Confirmed"}`,
		authorityID)
	id := createPartnership(t, token, authorityID, body)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	years := result["years"].([]interface{})
	require.Len(t, years, 3)
	assert.Equal(t, float64(2026), years[0].(map[string]interface{})["financialyear"])
	assert.Equal(t, "2026/27", years[0].(map[string]interface{})["label"])
	assert.Equal(t, float64(2028), years[2].(map[string]interface{})["financialyear"])
}

func TestPartnershipExplicitYearsBeatProRata(t *testing.T) {
	prefix := uniquePrefix("PartnershipFYExplicit")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2028-03-31","amount":9000,"status":"Confirmed"}`,
		authorityID)
	id := createPartnership(t, token, authorityID, body)

	// The council pays most of it up front, so the pro-rata split is wrong.
	years := `{"years":[{"financialyear":2026,"amount":7000},{"financialyear":2027,"amount":2000}]}`
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/partnership/%d/year?jwt=%s", id, token),
		strings.NewReader(years))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	req = httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ = getApp().Test(req)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	got := result["years"].([]interface{})
	require.Len(t, got, 2)
	assert.Equal(t, float64(7000), got[0].(map[string]interface{})["amount"])
	assert.Equal(t, float64(2000), got[1].(map[string]interface{})["amount"])
	assert.Equal(t, "2026/27", got[0].(map[string]interface{})["label"])
}

func TestPartnershipEmptyYearsRestoresProRata(t *testing.T) {
	prefix := uniquePrefix("PartnershipFYReset")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2028-03-31","amount":9000,"status":"Confirmed"}`,
		authorityID)
	id := createPartnership(t, token, authorityID, body)

	for _, years := range []string{
		`{"years":[{"financialyear":2026,"amount":7000},{"financialyear":2027,"amount":2000}]}`,
		`{"years":[]}`,
	} {
		req := httptest.NewRequest("PUT", fmt.Sprintf("/api/partnership/%d/year?jwt=%s", id, token),
			strings.NewReader(years))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		require.Equal(t, 200, resp.StatusCode)
	}

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	got := result["years"].([]interface{})
	require.Len(t, got, 2)
	// Roughly half each - not exactly, because 2027/28 contains a leap day.
	assert.InDelta(t, 4500.0, got[0].(map[string]interface{})["amount"].(float64), 20.0,
		"with no explicit split we go back to pro-rating")
}

func TestPartnershipSummaryTotals(t *testing.T) {
	prefix := uniquePrefix("PartnershipSummary")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/%d/payment?jwt=%s", id, token),
		strings.NewReader(`{"date":"2026-04-15","amount":2000,"paid":"2026-05-01"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	req = httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/summary?jwt=%s", token), nil)
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	summary := result["summary"].(map[string]interface{})
	assert.GreaterOrEqual(t, summary["committed"].(float64), float64(6000))
	assert.GreaterOrEqual(t, summary["invoiced"].(float64), float64(2000))
	assert.GreaterOrEqual(t, summary["received"].(float64), float64(2000))
	assert.Contains(t, summary, "years")

	// The financial year the deal sits in must appear in the graph data.
	years := summary["years"].([]interface{})
	found := false
	for _, y := range years {
		if y.(map[string]interface{})["financialyear"].(float64) == 2026 {
			found = true
			assert.Contains(t, y.(map[string]interface{}), "committed")
			assert.Contains(t, y.(map[string]interface{}), "inprinciple")
		}
	}
	assert.True(t, found, "2026/27 income shows in the per-year breakdown")
}

func TestPartnershipSummarySeparatesInPrincipleFromCommitted(t *testing.T) {
	prefix := uniquePrefix("PartnershipPipeline")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	// Only agreed in principle: hoped-for money must not be counted as income.
	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","amount":5000,"status":"InPrinciple"}`,
		authorityID)
	createPartnership(t, token, authorityID, body)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/summary?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	summary := result["summary"].(map[string]interface{})
	for _, y := range summary["years"].([]interface{}) {
		year := y.(map[string]interface{})
		if year["financialyear"].(float64) == 2026 {
			assert.GreaterOrEqual(t, year["inprinciple"].(float64), float64(5000))
		}
	}
}

func TestPartnershipExpiringFlag(t *testing.T) {
	prefix := uniquePrefix("PartnershipExpiring")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	// Ends in a month, so it should be flagged as running out.
	soon := time.Now().AddDate(0, 1, 0).Format("2006-01-02")
	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2020-04-01","enddate":"%s","amount":1000,"status":"Confirmed"}`,
		authorityID, soon)
	id := createPartnership(t, token, authorityID, body)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	p := result["partnership"].(map[string]interface{})
	assert.Equal(t, true, p["expiring"])
	assert.Equal(t, false, p["expired"])
}

func TestPartnershipExpiredFlag(t *testing.T) {
	prefix := uniquePrefix("PartnershipExpired")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2019-04-01","enddate":"2020-03-31","amount":1000,"status":"Confirmed"}`,
		authorityID)
	id := createPartnership(t, token, authorityID, body)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	p := result["partnership"].(map[string]interface{})
	assert.Equal(t, true, p["expired"])
	assert.Equal(t, false, p["expiring"])
}

func TestPartnershipSingleUnknownIdIs404(t *testing.T) {
	prefix := uniquePrefix("PartnershipGet404")
	_, token := partnershipsUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/999999999?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestPartnershipStatsJobQueued(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatsJob")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityids":[%d],"quarter":"2026-04-01"}`, authorityID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/statsjob?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	jobID := uint64(result["id"].(float64))
	require.NotZero(t, jobID)

	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM partnerships_statsjobs WHERE id = ?", jobID)
	})

	db := database.DBConn
	var job struct {
		Authorityids string
		Quarter      string
		Status       string
	}
	db.Raw("SELECT authorityids, quarter, status FROM partnerships_statsjobs WHERE id = ?", jobID).Scan(&job)

	assert.Equal(t, fmt.Sprintf("%d", authorityID), job.Authorityids)
	assert.Equal(t, "2026-04-01", job.Quarter)
	assert.Equal(t, "Pending", job.Status, "the scheduler picks it up from Pending")
}

func TestPartnershipStatsJobNeedsAuthorities(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatsNone")
	_, token := partnershipsUser(t, prefix)

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/statsjob?jwt=%s", token),
		strings.NewReader(`{"authorityids":[]}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPartnershipStatsJobDefaultsQuarter(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatsQ")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityids":[%d]}`, authorityID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/statsjob?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	jobID := uint64(result["id"].(float64))

	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM partnerships_statsjobs WHERE id = ?", jobID)
	})

	var quarter string
	database.DBConn.Raw("SELECT quarter FROM partnerships_statsjobs WHERE id = ?", jobID).Scan(&quarter)
	assert.Equal(t, "3 months ago", quarter, "same default as the authority:stats command")
}

func TestPartnershipStatsJobListIncludesFiles(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatsList")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityids":[%d]}`, authorityID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/statsjob?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var created map[string]interface{}
	json2.Unmarshal(rsp(resp), &created)
	jobID := uint64(created["id"].(float64))

	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM partnerships_statsjobs WHERE id = ?", jobID)
	})

	db := database.DBConn
	db.Exec("UPDATE partnerships_statsjobs SET status = 'Ready', completed = NOW() WHERE id = ?", jobID)
	db.Exec("INSERT INTO partnerships_statsfiles (jobid, authorityid, filename, size, content) VALUES (?, ?, ?, ?, ?)",
		jobID, authorityID, "Freegle-Statistics-Test.xlsx", 5, []byte("hello"))

	req = httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/statsjob?jwt=%s", token), nil)
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	jobs := result["jobs"].([]interface{})
	var found map[string]interface{}
	for _, j := range jobs {
		if uint64(j.(map[string]interface{})["id"].(float64)) == jobID {
			found = j.(map[string]interface{})
		}
	}
	require.NotNil(t, found, "the job we queued is listed")
	assert.Equal(t, "Ready", found["status"])

	files := found["files"].([]interface{})
	require.Len(t, files, 1)
	assert.Equal(t, "Freegle-Statistics-Test.xlsx", files[0].(map[string]interface{})["filename"])
}

func TestPartnershipStatsFileDownload(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatsDl")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	db := database.DBConn
	db.Exec("INSERT INTO partnerships_statsjobs (authorityids, quarter, status) VALUES (?, '3 months ago', 'Ready')",
		fmt.Sprintf("%d", authorityID))

	var jobID uint64
	db.Raw("SELECT id FROM partnerships_statsjobs ORDER BY id DESC LIMIT 1").Scan(&jobID)
	require.NotZero(t, jobID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM partnerships_statsjobs WHERE id = ?", jobID)
	})

	db.Exec("INSERT INTO partnerships_statsfiles (jobid, authorityid, filename, size, content) VALUES (?, ?, ?, ?, ?)",
		jobID, authorityID, "Freegle-Statistics-Somewhere.xlsx", 9, []byte("spreadsheet"))

	var fileID uint64
	db.Raw("SELECT id FROM partnerships_statsfiles WHERE jobid = ? ORDER BY id DESC LIMIT 1", jobID).Scan(&fileID)
	require.NotZero(t, fileID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/statsfile/%d?jwt=%s", fileID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Content-Disposition"), "Freegle-Statistics-Somewhere.xlsx")
	assert.Equal(t, "spreadsheet", string(rsp(resp)))
}

func TestPartnershipStatsFileUnknownIdIs404(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatsDl404")
	_, token := partnershipsUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/statsfile/999999999?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestPartnershipStatsJobDelete(t *testing.T) {
	prefix := uniquePrefix("PartnershipStatsDel")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	body := fmt.Sprintf(`{"authorityids":[%d]}`, authorityID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership/statsjob?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var created map[string]interface{}
	json2.Unmarshal(rsp(resp), &created)
	jobID := uint64(created["id"].(float64))

	req = httptest.NewRequest("DELETE", fmt.Sprintf("/api/partnership/statsjob/%d?jwt=%s", jobID, token), nil)
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM partnerships_statsjobs WHERE id = ?", jobID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestSessionReportsTeamMembership(t *testing.T) {
	prefix := uniquePrefix("PartnershipSession")
	_, token := partnershipsUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/session?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	me := result["me"].(map[string]interface{})
	require.Contains(t, me, "teams", "ModTools needs to know which teams a volunteer is on")

	teams := me["teams"].([]interface{})
	found := false
	for _, team := range teams {
		if team.(string) == "Partnerships" {
			found = true
		}
	}
	assert.True(t, found)
}

func TestSessionReportsNoTeamsForSomeoneOnNone(t *testing.T) {
	prefix := uniquePrefix("PartnershipSessionUser")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/session?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	me := result["me"].(map[string]interface{})
	require.Contains(t, me, "teams")
	assert.Empty(t, me["teams"])
}

func TestSessionReportsTeamsForAPlainMemberOnATeam(t *testing.T) {
	// Some team members are ordinary members by role - a team's own shared account, for
	// instance - and they still have to be able to reach their team's page.
	prefix := uniquePrefix("PartnershipSessionShared")
	db := database.DBConn

	db.Exec("INSERT IGNORE INTO teams (name, description, type) VALUES ('Partnerships', 'Partnerships', 'Team')")

	var teamID uint64
	db.Raw("SELECT id FROM teams WHERE name = 'Partnerships'").Scan(&teamID)
	require.NotZero(t, teamID)

	userID := CreateTestUser(t, prefix, "User")
	db.Exec("INSERT IGNORE INTO teams_members (userid, teamid) VALUES (?, ?)", userID, teamID)

	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/session?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	me := result["me"].(map[string]interface{})
	require.Contains(t, me, "teams")
	assert.Contains(t, me["teams"], "Partnerships")
}

func TestPartnershipPlainMemberOnTheTeamMayUseThePage(t *testing.T) {
	prefix := uniquePrefix("PartnershipSharedAcct")
	db := database.DBConn

	db.Exec("INSERT IGNORE INTO teams (name, description, type) VALUES ('Partnerships', 'Partnerships', 'Team')")

	var teamID uint64
	db.Raw("SELECT id FROM teams WHERE name = 'Partnerships'").Scan(&teamID)

	userID := CreateTestUser(t, prefix, "User")
	db.Exec("INSERT IGNORE INTO teams_members (userid, teamid) VALUES (?, ?)", userID, teamID)

	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}
