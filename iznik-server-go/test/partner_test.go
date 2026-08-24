package test

import (
	"fmt"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestValidatePartnerKeyValid(t *testing.T) {
	prefix := uniquePrefix("partner_valid")
	db := database.DBConn

	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", prefix+"_key", "test.com")

	partnerID, partnerName, domain, err := user.ValidatePartnerKey(db, prefix+"_key")
	assert.NoError(t, err)
	assert.Greater(t, partnerID, uint64(0))
	assert.Equal(t, prefix+"_partner", partnerName)
	assert.Equal(t, "test.com", domain)
}

func TestValidatePartnerKeyInvalid(t *testing.T) {
	db := database.DBConn

	_, _, _, err := user.ValidatePartnerKey(db, "nonexistent_key_xyz")
	assert.Error(t, err)
}

func TestFindByTNIdOrEmailByTNId(t *testing.T) {
	prefix := uniquePrefix("partner_findtn")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	// tnuserid is UNIQUE in production, so release it from any user left by an
	// earlier run before claiming it.
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 77777)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77777, userID)

	found := user.FindByTNIdOrEmail(db, 77777, "")
	assert.Equal(t, userID, found)
}

func TestFindByTNIdOrEmailByEmail(t *testing.T) {
	prefix := uniquePrefix("partner_findem")
	db := database.DBConn

	email := prefix + "@test.com"
	userID := CreateTestUser(t, prefix+"_user", "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", userID, email)

	found := user.FindByTNIdOrEmail(db, 0, email)
	assert.Equal(t, userID, found)
}

func TestFindByTNIdOrEmailNotFound(t *testing.T) {
	db := database.DBConn

	found := user.FindByTNIdOrEmail(db, 0, "nonexistent_999@test.com")
	assert.Equal(t, uint64(0), found)
}

func TestCreatePartnerUser(t *testing.T) {
	prefix := uniquePrefix("partner_create")
	db := database.DBConn

	email := prefix + "-gtest@example.com"
	// tnuserid is UNIQUE in production, so release it from any user left by an
	// earlier run before CreatePartnerUser claims it.
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 88888)
	userID, err := user.CreatePartnerUser(db, 88888, email)
	assert.NoError(t, err)
	assert.Greater(t, userID, uint64(0))

	// Verify tnuserid was set.
	var tnuserid uint64
	db.Raw("SELECT COALESCE(tnuserid, 0) FROM users WHERE id = ?", userID).Scan(&tnuserid)
	assert.Equal(t, uint64(88888), tnuserid)

	// Verify email was added.
	var emailCount int64
	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ? AND email = ?", userID, email).Scan(&emailCount)
	assert.Equal(t, int64(1), emailCount)

	// Verify name was extracted from email prefix (before -g).
	// The name extraction replaces underscores with spaces and title-cases.
	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", userID).Scan(&fullname)
	assert.NotEmpty(t, fullname, "Name should be extracted from email")
}

func TestCreatePartnerUserNameFromAtSign(t *testing.T) {
	db := database.DBConn

	email := "john.doe@example.com"
	userID, err := user.CreatePartnerUser(db, 0, email)
	assert.NoError(t, err)
	assert.Greater(t, userID, uint64(0))

	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", userID).Scan(&fullname)
	assert.Equal(t, "John Doe", fullname)
}

// A TN member can end up with TWO Freegle accounts: one carrying the tnuserid
// stamp, another owning the TN email (live case 2026-08-06). Both identities
// must be visible to callers so message actions can act as whichever owns the
// message.
func TestFindTNCandidatesTwinAccounts(t *testing.T) {
	prefix := uniquePrefix("partner_twins")
	db := database.DBConn

	tnTwin := CreateTestUser(t, prefix+"_tn", "User")
	emailTwin := CreateTestUser(t, prefix+"_em", "User")
	// tnuserid is UNIQUE in production - release it from earlier runs.
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66666)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66666, tnTwin)
	email := prefix + "-g1@test.com"
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", emailTwin, email)

	candidates := user.FindTNCandidates(db, 66666, email)
	assert.Equal(t, []uint64{tnTwin, emailTwin}, candidates)

	// The single-value resolver keeps its tnuserid-first preference.
	assert.Equal(t, tnTwin, user.FindByTNIdOrEmail(db, 66666, email))
}

// The live failure: TN promised an item on behalf of its member, supplying
// both tnuserid and email; the message belonged to the email twin, but
// tnuserid-first resolution acted as the other account and the promise 403'd
// "Not your message". The sync's job is to STOP the divergence: the partner
// call must heal the split by merging the email twin into the tnuserid
// account, and the promise must land.
func TestPartnerPromiseHealsTwinAccounts(t *testing.T) {
	prefix := uniquePrefix("partner_promise")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	tnTwin := CreateTestUser(t, prefix+"_tn", "User")
	emailTwin := CreateTestUser(t, prefix+"_em", "User")
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66667)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66667, tnTwin)
	email := prefix + "-g2@test.com"
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", emailTwin, email)

	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, emailTwin, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 424242, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)

	body := `{"tnpostid":"424242","action":"Promise"}`
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?partner=%s&tnuserid=66667&email=%s", partnerKey, url.QueryEscape(email)),
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "the member's promise must succeed")

	var count int64
	db.Table("messages_promises").Where("msgid = ? AND userid = ?", msgID, tnTwin).Count(&count)
	assert.Equal(t, int64(1), count, "the promise must be recorded against the surviving account")

	// The divergence must be healed, not tolerated: the email twin is merged
	// into the tnuserid account and deleted, its message and email move.
	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ?", msgID).Scan(&fromuser)
	assert.Equal(t, tnTwin, fromuser, "the message must belong to the surviving account")

	var emailOwner uint64
	db.Table("users_emails").Select("userid").Where("email = ?", email).Scan(&emailOwner)
	assert.Equal(t, tnTwin, emailOwner, "the TN alias must move to the surviving account")

	var gone int64
	db.Table("users").Where("id = ?", emailTwin).Count(&gone)
	assert.Equal(t, int64(0), gone, "the email twin must be gone after the merge")
}

// The prevention half: when the sync presents a known tnuserid with a NEW
// email alias (a TN username rename) before any mail has arrived from it,
// the alias must be attached to the account so the mail ingest never mints a
// twin.
func TestEnsurePartnerIdentifiersAttachesNewAlias(t *testing.T) {
	prefix := uniquePrefix("partner_ensure")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66668)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66668, userID)

	newAlias := prefix + "-renamed-g9@test.com"
	user.EnsurePartnerIdentifiers(db, userID, 66668, newAlias)

	var owner uint64
	db.Table("users_emails").Select("userid").Where("email = ?", newAlias).Scan(&owner)
	assert.Equal(t, userID, owner, "the new alias must be attached to the resolved account")

	// And the symmetric case: an email-resolved account with no stamp gets it.
	other := CreateTestUser(t, prefix+"_other", "User")
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66669)
	user.EnsurePartnerIdentifiers(db, other, 66669, "")
	var stamped uint64
	db.Raw("SELECT COALESCE(tnuserid, 0) FROM users WHERE id = ?", other).Scan(&stamped)
	assert.Equal(t, uint64(66669), stamped, "an unstamped account must gain the tnuserid")
}
