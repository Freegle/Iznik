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

// The live failure of 2026-09-19 (TN post 47243586, member "i9"): TN supplies a
// tnuserid and a per-group alias that BOTH resolve to the same account, while
// the message belongs to a THIRD account carrying a DIFFERENT per-group alias
// of the same TN member.
//
// TN mints one alias per TN GROUP - `<username>-g<tngroupid>@user.trashnothing.com`
// - so a member active in two TN groups has two aliases. Before the alias
// back-fill landed (2026-08-11) a second alias arriving for the first time
// minted a second Freegle account, and 96 such pairs are still live.
//
// FindTNCandidates only ever looked at the tnuserid and the ONE alias in the
// request, so it returned a single candidate, HealTNDivergence and
// actAsOwnerCandidate both no-opped on len < 2, and the Promise 403'd
// "Not your message" against the sibling that actually owns the post.
func TestPartnerPromiseActsAsTNUsernameSibling(t *testing.T) {
	prefix := uniquePrefix("partner_sibling")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// The account TN's identifiers resolve to: it holds BOTH the tnuserid stamp
	// and the alias TN sends, so nothing about the request looks diverged.
	stamped := CreateTestUser(t, prefix+"_stamped", "User")
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66670)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66670, stamped)
	sentAlias := prefix + "-g4707@test.com"
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", stamped, sentAlias)

	// The sibling: same TN username, a different TN group's alias, and it owns
	// the post TN is acting on.
	sibling := CreateTestUser(t, prefix+"_sibling", "User")
	siblingAlias := prefix + "-g1586@test.com"
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", sibling, siblingAlias)

	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, sibling, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ?, fromaddr = ? WHERE id = ?", 474747, siblingAlias, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)

	body := `{"tnpostid":"474747","action":"Promise"}`
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?partner=%s&tnuserid=66670&email=%s", partnerKey, url.QueryEscape(sentAlias)),
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "the promise must land on the sibling that owns the post")

	// Acting as the owner, NOT merging: the review of the 94 live pairs is a
	// separate, gated step, so both accounts must survive this call intact.
	var count int64
	db.Table("messages_promises").Where("msgid = ? AND userid = ?", msgID, sibling).Count(&count)
	assert.Equal(t, int64(1), count, "the promise must be recorded against the owning sibling")

	var stillThere int64
	db.Table("users").Where("id IN (?, ?)", stamped, sibling).Count(&stillThere)
	assert.Equal(t, int64(2), stillThere, "acting as a sibling must not merge the accounts")
}

// The sibling lookup is scoped to the partner's own domain and to the exact TN
// username: a partner must not reach an account whose alias merely starts with
// the same characters, nor one in a different domain.
func TestFindTNSiblingsScopedToUsernameAndDomain(t *testing.T) {
	prefix := uniquePrefix("partner_scope")
	db := database.DBConn

	mine := CreateTestUser(t, prefix+"_mine", "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", mine, prefix+"-g1@test.com")

	sib := CreateTestUser(t, prefix+"_sib", "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", sib, prefix+"-g2@test.com")

	// The shape that actually bites: a LONGER username whose own alias still
	// matches the shorter one's "<username>-g%" narrowing, because the % runs on
	// past the end of the name. iznik-batch merged two unrelated members this way
	// on 2026-09-13, so the exact-username test is load-bearing, not belt and braces.
	longer := CreateTestUser(t, prefix+"_longer", "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", longer, prefix+"-gomes-g3@test.com")

	// Right username, wrong domain - outside the partner's reach.
	otherDomain := CreateTestUser(t, prefix+"_other", "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", otherDomain, prefix+"-g4@elsewhere.com")

	got := user.FindTNSiblings(db, prefix+"-g1@test.com")
	assert.Contains(t, got, sib, "the sibling sharing the TN username must be found")
	assert.NotContains(t, got, longer, "a longer username that shares a prefix is a different member")
	assert.NotContains(t, got, otherDomain, "a different domain is outside the partner's reach")
	assert.NotContains(t, got, mine, "the account the alias itself resolves to is not its own sibling")

	// A non-TN-shaped address has no siblings at all.
	assert.Empty(t, user.FindTNSiblings(db, "plain@test.com"))
}

// Pin what the two columns HOLD, which nothing did before: the direction was
// got wrong once and every test stayed green.
//
// V1's User::addEmail writes canonMail($email) and strrev(canonMail($email)) at
// both its insert sites, and canonMail strips the -gNNNN suffix and the dots out
// of the domain on purpose ("the format we have historically used"). So for a
// partner alias both columns derive from the canon, and every per-group alias of
// one member reduces to the same pair.
func TestCreatePartnerUserStoresV1CanonAndBackwards(t *testing.T) {
	prefix := uniquePrefix("partner_canon")
	db := database.DBConn

	email := prefix + "-g4707@user.trashnothing.com"
	userID, err := user.CreatePartnerUser(db, 0, email)
	require.NoError(t, err)

	var row struct {
		Canon     string `gorm:"column:canon"`
		Backwards string `gorm:"column:backwards"`
	}
	db.Table("users_emails").Select("canon, backwards").
		Where("userid = ? AND email = ?", userID, email).Scan(&row)

	wantCanon := prefix + "@usertrashnothingcom"
	assert.Equal(t, wantCanon, row.Canon,
		"canon drops the per-group suffix and the domain dots, so a member's aliases agree")
	assert.Equal(t, user.ReverseString(wantCanon), row.Backwards,
		"backwards is REVERSE(canon), the definition V1 writes")

	// A second alias of the same member must reduce to the same canon, which is
	// what stops it minting another account.
	assert.Equal(t, wantCanon, user.CanonicalizePartnerEmail(prefix+"-g1586@user.trashnothing.com"))
}
