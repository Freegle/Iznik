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
		`"linkurl":"https://example.gov.uk/reuse","agreed":true}`, authorityID)
}

func TestPartnershipRequiresLogin(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/partnership", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestPartnershipRequiresTeamMembership(t *testing.T) {
	prefix := uniquePrefix("PartnershipNoPerm")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
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
	assert.Equal(t, true, p["agreed"])
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

func TestPartnershipDetectsCoveredGroups(t *testing.T) {
	prefix := uniquePrefix("PartnershipGroups")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/%d/group?jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	groups := result["groups"].([]interface{})
	found := false
	for _, g := range groups {
		if uint64(g.(map[string]interface{})["groupid"].(float64)) == groupID {
			found = true
		}
	}
	assert.True(t, found, "the group inside the council boundary is covered by the deal")
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
	assert.True(t, sponsor.Visible, "an agreed, visible deal shows on the member site")
	assert.Equal(t, 6000, sponsor.Amount)
}

func TestPartnershipNotAgreedIsHiddenFromMembers(t *testing.T) {
	prefix := uniquePrefix("PartnershipUnagreed")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","amount":100,"agreed":false}`,
		authorityID)
	createPartnership(t, token, authorityID, body)

	db := database.DBConn
	var visible bool
	db.Raw("SELECT visible FROM groups_sponsorship WHERE groupid = ? ORDER BY id DESC LIMIT 1", groupID).Scan(&visible)

	assert.False(t, visible, "a deal that is not agreed yet must not be advertised")
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

func TestPartnershipRemoveGroupDropsItsSponsorship(t *testing.T) {
	prefix := uniquePrefix("PartnershipDropGroup")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)
	groupID := createPartnershipGroup(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	body := fmt.Sprintf(`{"action":"Remove","groupid":%d}`, groupID)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d/group?jwt=%s", id, token),
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	db := database.DBConn
	var sponsorships int64
	db.Raw("SELECT COUNT(*) FROM groups_sponsorship WHERE groupid = ?", groupID).Scan(&sponsorships)
	assert.Equal(t, int64(0), sponsorships)

	var links int64
	db.Raw("SELECT COUNT(*) FROM partnerships_groups WHERE partnershipid = ? AND groupid = ?", id, groupID).Scan(&links)
	assert.Equal(t, int64(0), links)
}

func TestPartnershipAddGroupCreatesSponsorship(t *testing.T) {
	prefix := uniquePrefix("PartnershipAddGroup")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	id := createPartnership(t, token, authorityID, defaultBody(authorityID))

	// A group nowhere near the council - added by hand because the deal covers it anyway.
	outsideGroup := CreateTestGroup(t, prefix+"_outside")

	body := fmt.Sprintf(`{"action":"Add","groupid":%d}`, outsideGroup)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/partnership/%d/group?jwt=%s", id, token),
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	db := database.DBConn
	var sponsorships int64
	db.Raw("SELECT COUNT(*) FROM groups_sponsorship WHERE groupid = ?", outsideGroup).Scan(&sponsorships)
	assert.Equal(t, int64(1), sponsorships)
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
	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2029-03-31","amount":9000,"agreed":true}`,
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

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2028-03-31","amount":9000,"agreed":true}`,
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

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2028-03-31","amount":9000,"agreed":true}`,
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
	assert.GreaterOrEqual(t, summary["total"].(float64), float64(6000))
	assert.GreaterOrEqual(t, summary["invoiced"].(float64), float64(2000))
	assert.GreaterOrEqual(t, summary["paid"].(float64), float64(2000))
	assert.Contains(t, summary, "years")

	// The financial year the deal sits in must appear in the graph data.
	years := summary["years"].([]interface{})
	found := false
	for _, y := range years {
		if y.(map[string]interface{})["financialyear"].(float64) == 2026 {
			found = true
			assert.Contains(t, y.(map[string]interface{}), "agreed")
			assert.Contains(t, y.(map[string]interface{}), "pipeline")
		}
	}
	assert.True(t, found, "2026/27 income shows in the per-year breakdown")
}

func TestPartnershipSummarySeparatesAgreedFromPipeline(t *testing.T) {
	prefix := uniquePrefix("PartnershipPipeline")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	// Deliberately not agreed: hoped-for money must not be counted as income.
	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2026-04-01","enddate":"2027-03-31","amount":5000,"agreed":false}`,
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
			assert.GreaterOrEqual(t, year["pipeline"].(float64), float64(5000))
		}
	}
}

func TestPartnershipExpiringFlag(t *testing.T) {
	prefix := uniquePrefix("PartnershipExpiring")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	// Ends in a month, so it should be flagged as running out.
	soon := time.Now().AddDate(0, 1, 0).Format("2006-01-02")
	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2020-04-01","enddate":"%s","amount":1000,"agreed":true}`,
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

	body := fmt.Sprintf(`{"authorityid":%d,"startdate":"2019-04-01","enddate":"2020-03-31","amount":1000,"agreed":true}`,
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
