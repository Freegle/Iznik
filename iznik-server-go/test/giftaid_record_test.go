package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/donations"
	"github.com/stretchr/testify/assert"
)

// A volunteer records a donor's Gift Aid answer for them (POST /giftaid/record), so nobody has to
// sign in as the donor to do it. "Declined" is the donor's own answer, given some other way than
// the form; "NoResponse" is one more time we asked and heard nothing, and can happen many times.

// recordGiftAid returns the status and the raw body: the test app answers a fiber.NewError with
// the message as plain text, where the real server wraps it in JSON.
func recordGiftAid(t *testing.T, token string, userid uint64, outcome string) (int, string) {
	t.Helper()
	body := fmt.Sprintf(`{"userid":%d,"outcome":%q}`, userid, outcome)
	req := httptest.NewRequest("POST", "/api/giftaid/record?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode, string(rsp(resp))
}

func noResponseList(t *testing.T, token string) []donations.GiftAidNoResponse {
	t.Helper()
	req := httptest.NewRequest("GET", "/api/giftaid?noresponse=true&jwt="+token, nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var result struct {
		Donors []donations.GiftAidNoResponse `json:"donors"`
	}
	json2.Unmarshal(rsp(resp), &result)
	return result.Donors
}

func findNoResponse(list []donations.GiftAidNoResponse, userid uint64) *donations.GiftAidNoResponse {
	for i := range list {
		if list[i].UserID == userid {
			return &list[i]
		}
	}
	return nil
}

func TestRecordGiftAidDeclinedOnBehalf(t *testing.T) {
	prefix := uniquePrefix("ga_rec_decl")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	db := database.DBConn
	defer db.Exec("DELETE FROM giftaid WHERE userid = ?", donorID)

	status, _ := recordGiftAid(t, adminToken, donorID, "Declined")
	assert.Equal(t, 200, status)

	var period string
	var recordedby *uint64
	var deleted *string
	db.Raw("SELECT period, recordedby, deleted FROM giftaid WHERE userid = ?", donorID).Row().Scan(&period, &recordedby, &deleted)
	assert.Equal(t, "Declined", period)
	if assert.NotNil(t, recordedby) {
		assert.Equal(t, adminID, *recordedby)
	}
	assert.Nil(t, deleted)

	// A decline is never queued for review.
	assert.False(t, giftAidListContains(t, adminToken, donorID))
}

func TestRecordGiftAidDeclinedReplacesConsent(t *testing.T) {
	// A donor who gave consent and later writes to say they no longer pay tax.
	prefix := uniquePrefix("ga_rec_withdraw")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	db := database.DBConn
	db.Exec(`INSERT INTO giftaid (userid, timestamp, period, fullname, homeaddress) VALUES (?, '2022-01-01 00:00:00', 'Future', 'Donor Name', '1 Street')`, donorID)
	defer db.Exec("DELETE FROM giftaid WHERE userid = ?", donorID)

	status, _ := recordGiftAid(t, adminToken, donorID, "Declined")
	assert.Equal(t, 200, status)

	var period, fullname string
	var secondsOld int64
	db.Raw("SELECT period, fullname, TIMESTAMPDIFF(SECOND, timestamp, NOW()) FROM giftaid WHERE userid = ?", donorID).
		Row().Scan(&period, &fullname, &secondsOld)
	assert.Equal(t, "Declined", period)
	assert.Equal(t, "Donor Name", fullname)
	assert.Less(t, secondsOld, int64(300), "the decline is dated when it was recorded")
}

func TestRecordGiftAidNoResponseCanRepeat(t *testing.T) {
	prefix := uniquePrefix("ga_rec_nr")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	db := database.DBConn
	db.Exec("INSERT INTO users_donations (userid, type, Payer, PayerDisplayName, GrossAmount, source) VALUES (?, 'BankTransfer', 'Test', 'Test', 25.00, 'BankTransfer')", donorID)
	defer db.Exec("DELETE FROM users_donations WHERE userid = ?", donorID)
	defer db.Exec("DELETE FROM giftaid_asks WHERE userid = ?", donorID)

	status, _ := recordGiftAid(t, adminToken, donorID, "NoResponse")
	assert.Equal(t, 200, status)
	status, _ = recordGiftAid(t, adminToken, donorID, "NoResponse")
	assert.Equal(t, 200, status)

	var asks int64
	db.Raw("SELECT COUNT(*) FROM giftaid_asks WHERE userid = ? AND askedby = ?", donorID, adminID).Scan(&asks)
	assert.Equal(t, int64(2), asks)

	// No declaration is written: "no response" is not an answer.
	var rows int64
	db.Raw("SELECT COUNT(*) FROM giftaid WHERE userid = ?", donorID).Scan(&rows)
	assert.Equal(t, int64(0), rows)

	entry := findNoResponse(noResponseList(t, adminToken), donorID)
	if assert.NotNil(t, entry, "an asked donor with no declaration is listed") {
		assert.Equal(t, int64(2), entry.Asks)
		assert.Equal(t, 25.0, entry.Donations)
		assert.NotNil(t, entry.Email)
		assert.False(t, entry.LastAsked.IsZero())
	}
}

func TestRecordGiftAidNoResponseRefusedOnceAnswered(t *testing.T) {
	prefix := uniquePrefix("ga_rec_nr_ans")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	db := database.DBConn

	for _, period := range []string{"Future", "Declined"} {
		donorID := CreateTestUser(t, prefix+"_"+period, "User")
		db.Exec(`INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, ?, 'Donor', '1 Street')`, donorID, period)

		status, body := recordGiftAid(t, adminToken, donorID, "NoResponse")
		assert.Equal(t, 409, status, period)
		assert.Contains(t, body, "already answered", period)

		var asks int64
		db.Raw("SELECT COUNT(*) FROM giftaid_asks WHERE userid = ?", donorID).Scan(&asks)
		assert.Equal(t, int64(0), asks, period)
		db.Exec("DELETE FROM giftaid WHERE userid = ?", donorID)
	}
}

func TestRecordGiftAidNoResponseAllowedAfterGiveUp(t *testing.T) {
	// "Give Up" in ModTools deletes a declaration whose details could not be used. The donor has
	// then not answered usably, so asking again and hearing nothing can be recorded.
	prefix := uniquePrefix("ga_rec_nr_gu")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	db := database.DBConn
	db.Exec(`INSERT INTO giftaid (userid, period, fullname, homeaddress, deleted) VALUES (?, 'Future', 'Donor', '', NOW())`, donorID)
	defer db.Exec("DELETE FROM giftaid WHERE userid = ?", donorID)
	defer db.Exec("DELETE FROM giftaid_asks WHERE userid = ?", donorID)

	status, _ := recordGiftAid(t, adminToken, donorID, "NoResponse")
	assert.Equal(t, 200, status)
	assert.NotNil(t, findNoResponse(noResponseList(t, adminToken), donorID))
}

func TestRecordGiftAidBadRequests(t *testing.T) {
	prefix := uniquePrefix("ga_rec_bad")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")

	status, _ := recordGiftAid(t, adminToken, donorID, "Maybe")
	assert.Equal(t, 400, status)

	status, _ = recordGiftAid(t, adminToken, 0, "Declined")
	assert.Equal(t, 400, status)

	status, _ = recordGiftAid(t, adminToken, 999999999999, "Declined")
	assert.Equal(t, 404, status)
}

func TestRecordGiftAidNeedsGiftAidPermission(t *testing.T) {
	prefix := uniquePrefix("ga_rec_perm")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	db := database.DBConn

	status, _ := recordGiftAid(t, token, donorID, "Declined")
	assert.Equal(t, 403, status)
	status, _ = recordGiftAid(t, token, donorID, "NoResponse")
	assert.Equal(t, 403, status)

	var rows int64
	db.Raw("SELECT (SELECT COUNT(*) FROM giftaid WHERE userid = ?) + (SELECT COUNT(*) FROM giftaid_asks WHERE userid = ?)", donorID, donorID).Scan(&rows)
	assert.Equal(t, int64(0), rows, "a refused request writes nothing")

	req := httptest.NewRequest("GET", "/api/giftaid?noresponse=true&jwt="+token, nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	resp, _ = getApp().Test(httptest.NewRequest("POST", "/api/giftaid/record", bytes.NewBufferString(`{}`)))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestRecordGiftAidWithGiftAidPermission(t *testing.T) {
	prefix := uniquePrefix("ga_rec_permok")
	volID := CreateTestUser(t, prefix+"_vol", "User")
	db := database.DBConn
	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", volID)
	_, token := CreateTestSession(t, volID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	defer db.Exec("DELETE FROM giftaid_asks WHERE userid = ?", donorID)

	status, _ := recordGiftAid(t, token, donorID, "NoResponse")
	assert.Equal(t, 200, status)
}

func TestNoResponseListLeavesOutAnsweredDonors(t *testing.T) {
	prefix := uniquePrefix("ga_nr_list")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	db := database.DBConn

	asked := CreateTestUser(t, prefix+"_asked", "User")
	declared := CreateTestUser(t, prefix+"_declared", "User")
	never := CreateTestUser(t, prefix+"_never", "User")
	db.Exec("INSERT INTO giftaid_asks (userid, askedby, timestamp) VALUES (?, NULL, '2026-09-01 10:00:00'), (?, NULL, '2026-10-01 10:00:00')", asked, declared)
	db.Exec(`INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, 'Future', 'Donor', '1 Street')`, declared)
	defer db.Exec("DELETE FROM giftaid WHERE userid IN (?, ?, ?)", asked, declared, never)
	defer db.Exec("DELETE FROM giftaid_asks WHERE userid IN (?, ?, ?)", asked, declared, never)

	list := noResponseList(t, adminToken)
	if entry := findNoResponse(list, asked); assert.NotNil(t, entry) {
		assert.Equal(t, int64(1), entry.Asks)
		assert.Nil(t, entry.AskedBy, "the chase-up mail is not a volunteer")
		assert.Equal(t, "2026-09-01", entry.LastAsked.Format("2006-01-02"))
	}
	assert.Nil(t, findNoResponse(list, declared), "a donor who answered is not 'no response'")
	assert.Nil(t, findNoResponse(list, never), "a donor never asked is not 'no response'")

	// Once their decline is recorded, they leave the list.
	status, _ := recordGiftAid(t, adminToken, asked, "Declined")
	assert.Equal(t, 200, status)
	assert.Nil(t, findNoResponse(noResponseList(t, adminToken), asked))
}

func TestSetGiftAidByDonorClearsRecordedBy(t *testing.T) {
	// A volunteer recorded a decline; the donor then fills in the form themselves.
	prefix := uniquePrefix("ga_rec_own")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	_, donorToken := CreateTestSession(t, donorID)
	db := database.DBConn
	db.Exec(`INSERT INTO giftaid (userid, period, fullname, homeaddress, recordedby) VALUES (?, 'Declined', 'Donor', '', ?)`, donorID, adminID)
	defer db.Exec("DELETE FROM giftaid WHERE userid = ?", donorID)

	body := `{"period":"Future","fullname":"Donor Name","homeaddress":"1 Street"}`
	req := httptest.NewRequest("POST", "/api/giftaid?jwt="+donorToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var recordedby *uint64
	db.Raw("SELECT recordedby FROM giftaid WHERE userid = ?", donorID).Row().Scan(&recordedby)
	assert.Nil(t, recordedby)
}

func TestSearchGiftAidSaysWhoRecordedIt(t *testing.T) {
	prefix := uniquePrefix("ga_rec_search")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	db := database.DBConn
	name := "RecordedSearch_" + prefix
	db.Exec(`INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, 'Future', ?, '')`, donorID, name)
	defer db.Exec("DELETE FROM giftaid WHERE userid = ?", donorID)

	status, _ := recordGiftAid(t, adminToken, donorID, "Declined")
	assert.Equal(t, 200, status)

	req := httptest.NewRequest("GET", "/api/giftaid?search="+name+"&jwt="+adminToken, nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var result struct {
		Giftaids []donations.GiftAidListItem `json:"giftaids"`
	}
	json2.Unmarshal(rsp(resp), &result)
	if assert.Len(t, result.Giftaids, 1) {
		assert.Equal(t, "Declined", result.Giftaids[0].Period)
		if assert.NotNil(t, result.Giftaids[0].RecordedBy) {
			assert.Equal(t, adminID, *result.Giftaids[0].RecordedBy)
		}
	}
}

func TestGetOwnGiftAidDoesNotNameTheVolunteer(t *testing.T) {
	prefix := uniquePrefix("ga_rec_ownget")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	donorID := CreateTestUser(t, prefix+"_donor", "User")
	_, donorToken := CreateTestSession(t, donorID)
	db := database.DBConn
	db.Exec(`INSERT INTO giftaid (userid, period, fullname, homeaddress, recordedby) VALUES (?, 'Declined', 'Donor', '', ?)`, donorID, adminID)
	defer db.Exec("DELETE FROM giftaid WHERE userid = ?", donorID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/giftaid?jwt="+donorToken, nil))
	assert.Equal(t, 200, resp.StatusCode)
	assert.NotContains(t, string(rsp(resp)), "recordedby")
}

func TestMergeMovesGiftAidAsks(t *testing.T) {
	prefix := uniquePrefix("merge_gaasks")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)
	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	db.Exec("INSERT INTO giftaid_asks (userid, askedby) VALUES (?, NULL), (?, NULL)", id1, id2)
	defer db.Exec("DELETE FROM giftaid_asks WHERE userid IN (?, ?)", id1, id2)

	mergeUsers(t, adminToken, id1, id2)

	var count int64
	db.Raw("SELECT COUNT(*) FROM giftaid_asks WHERE userid = ?", id2).Scan(&count)
	assert.Equal(t, int64(2), count, "both asks are kept on the merged account")
}
