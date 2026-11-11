package test

import (
	"fmt"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
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

	userID := CreateTestUser(t, prefix+"_user", utils.SYSTEMROLE_USER)
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
	userID := CreateTestUser(t, prefix+"_user", utils.SYSTEMROLE_USER)
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

	tnTwin := CreateTestUser(t, prefix+"_tn", utils.SYSTEMROLE_USER)
	emailTwin := CreateTestUser(t, prefix+"_em", utils.SYSTEMROLE_USER)
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
// "Not your message". Only a TN id says who someone is, and this account has
// none, so it is not merged: the alias moves to the member, and TN can still
// act on its own post, which stays where it was filed. What says the post is
// this member's is its other copy, on the account holding their TN id.
func TestPartnerPromiseActsOnATNPostOnAnAccountWithNoTNId(t *testing.T) {
	prefix := uniquePrefix("partner_promise")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	tnTwin := CreateTestUser(t, prefix+"_tn", utils.SYSTEMROLE_USER)
	emailTwin := CreateTestUser(t, prefix+"_em", utils.SYSTEMROLE_USER)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66667)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66667, tnTwin)
	email := prefix + "-g2@test.com"
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", emailTwin, email)

	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, emailTwin, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 424242, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)
	ownCopy := CreateTestMessage(t, tnTwin, CreateTestGroup(t, prefix+"_2"), prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 424242, ownCopy)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", ownCopy)

	body := `{"tnpostid":"424242","action":"Promise"}`
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?partner=%s&tnuserid=66667&email=%s", partnerKey, url.QueryEscape(email)),
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "the member's promise must succeed")

	var count int64
	db.Table("messages_promises").Where("msgid = ? AND userid = ?", msgID, emailTwin).Count(&count)
	assert.Equal(t, int64(1), count, "the promise is made as the account the post is filed on")

	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ?", msgID).Scan(&fromuser)
	assert.Equal(t, emailTwin, fromuser, "the post stays where it was filed")

	assert.Equal(t, tnTwin, addressOwner(email), "the TN alias goes to the member TN named")
	assert.True(t, userExists(emailTwin), "nothing proves the accounts are one member, so they are not merged")
}

// The prevention half: when the sync presents a known tnuserid with a NEW
// email alias (a TN username rename) before any mail has arrived from it,
// the alias must be attached to the account so the mail ingest never mints a
// twin.
func TestEnsurePartnerIdentifiersAttachesNewAlias(t *testing.T) {
	prefix := uniquePrefix("partner_ensure")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", utils.SYSTEMROLE_USER)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66668)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66668, userID)

	newAlias := prefix + "-renamed-g9@test.com"
	user.EnsurePartnerIdentifiers(db, userID, newAlias)

	var owner uint64
	db.Table("users_emails").Select("userid").Where("email = ?", newAlias).Scan(&owner)
	assert.Equal(t, userID, owner, "the new alias must be attached to the resolved account")
}

// The live failure of 2026-09-19 (TN post 47243586, member "i9"): TN supplies a
// tnuserid and a per-group alias that BOTH resolve to the same account, while
// the message belongs to a THIRD account.
//
// TN mints one alias per TN GROUP - `<username>-g<tngroupid>@user.trashnothing.com`
// - so a member active in two TN groups has two aliases. Before the alias
// back-fill landed (2026-08-11) a second alias arriving for the first time
// minted a second Freegle account, and 96 such pairs are still live.
//
// The third account has no TN id, so nothing says it is another member, and the
// post is TN's own, with a copy on the member's account. Its username differs on
// purpose: a username plays no part.
func TestPartnerPromiseActsOnATNPostOnAnotherAccountWithNoTNId(t *testing.T) {
	prefix := uniquePrefix("partner_sibling")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "test.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// The account TN's identifiers resolve to: it holds BOTH the tnuserid stamp
	// and the alias TN sends, so nothing about the request looks diverged.
	stamped := CreateTestUser(t, prefix+"_stamped", utils.SYSTEMROLE_USER)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66670)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 66670, stamped)
	sentAlias := prefix + "-g4707@test.com"
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", stamped, sentAlias)

	// An account with no TN id that owns the post TN is acting on.
	sibling := CreateTestUser(t, prefix+"_sibling", utils.SYSTEMROLE_USER)
	siblingAlias := "other" + prefix + "-g1586@test.com"
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", sibling, siblingAlias)

	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, sibling, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ?, fromaddr = ? WHERE id = ?", 474747, siblingAlias, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)
	ownCopy := CreateTestMessage(t, stamped, CreateTestGroup(t, prefix+"_2"), prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ?, fromaddr = ? WHERE id = ?", 474747, sentAlias, ownCopy)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", ownCopy)

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

// A bare <username>@user.trashnothing.com address canonicalises as PHP's canonMail does, and as an
// alias of the same username does, so a canon lookup on either side finds the other's rows.
func TestBareTrashNothingAddressCanonicalisesAsAnAliasDoes(t *testing.T) {
	prefix := uniquePrefix("partner_bare")
	domain := "@user.trashnothing.com"

	// The same canon as an alias of the same username, and as PHP's canonMail: dots in
	// the username kept, the domain's dots dropped.
	assert.Equal(t, prefix+"@usertrashnothingcom", user.CanonicalizePartnerEmail(prefix+domain))
	assert.Equal(t, prefix+"@usertrashnothingcom", user.CanonicalizePartnerEmail(prefix+"-g7"+domain))
	assert.Equal(t, "mary.jane@usertrashnothingcom", user.CanonicalizePartnerEmail("mary.jane@user.trashnothing.com"))

	// A bare address on any other domain is an ordinary address.
	assert.Equal(t, user.CanonicalizeEmail("mary.jane@test.com"), user.CanonicalizePartnerEmail("mary.jane@test.com"))
}

func recordTNAliasReports(t *testing.T) *[]string {
	reports := []string{}
	t.Cleanup(user.SetTNAliasReporter(func(msg string) { reports = append(reports, msg) }))

	return &reports
}

func partnerJoin(t *testing.T, key string, tnuserid uint64, email string, groupID uint64) int {
	target := fmt.Sprintf("/api/memberships?partner=%s&email=%s&groupid=%d", key, url.QueryEscape(email), groupID)
	if tnuserid != 0 {
		target += fmt.Sprintf("&tnuserid=%d", tnuserid)
	}

	resp, err := getApp().Test(httptest.NewRequest("PUT", target, nil), -1)
	require.NoError(t, err)

	return resp.StatusCode
}

func partnerPromise(t *testing.T, key string, tnuserid uint64, email string, tnpostid int) int {
	return partnerAction(t, key, tnuserid, email, fmt.Sprintf(`{"tnpostid":"%d","action":"Promise"}`, tnpostid))
}

func partnerAction(t *testing.T, key string, tnuserid uint64, email string, body string) int {
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?partner=%s&tnuserid=%d&email=%s", key, tnuserid, url.QueryEscape(email)),
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)

	return resp.StatusCode
}

func addressOwner(email string) uint64 {
	var owner uint64
	database.DBConn.Table("users_emails").Select("userid").Where("email = ?", email).Scan(&owner)

	return owner
}

// giveTNAddress makes email an account's only preferred address.
func giveTNAddress(userid uint64, email string) {
	db := database.DBConn
	db.Exec("UPDATE users_emails SET preferred = 0 WHERE userid = ?", userid)
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", userid, email)
}

func isMember(userid, groupID uint64) bool {
	var n int64
	database.DBConn.Table("memberships").Where("userid = ? AND groupid = ?", userid, groupID).Count(&n)

	return n > 0
}

// The alias sits on an account holding a different TN user id, so that merge is refused and the
// insert used to be dropped: the address stayed with the wrong member and mail went on being
// misfiled. TN has said whose it is, so it moves, and only it does.
func TestPartnerJoinMovesAliasHeldByAnotherTNMember(t *testing.T) {
	prefix := uniquePrefix("partner_move")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	member := CreateTestUser(t, prefix+"_m", utils.SYSTEMROLE_USER)
	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	stampTN(member, 66701)
	stampTN(holder, 66702)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 200, partnerJoin(t, key, 66701, alias, groupID))

	assert.Equal(t, member, addressOwner(alias), "the alias goes to the member TN named")
	assert.True(t, isMember(member, groupID))
	assert.False(t, isMember(holder, groupID))
	assert.True(t, userExists(holder), "the other member's account is left alone")

	var preferred int64
	db.Table("users_emails").Where("userid = ? AND preferred = 1", holder).Count(&preferred)
	assert.Equal(t, int64(1), preferred, "the account it left must keep a preferred address")

	require.Len(t, *reports, 1)
	assert.Contains(t, (*reports)[0], fmt.Sprintf("user %d", holder), "the report says where it came from")
	assert.Contains(t, (*reports)[0], fmt.Sprintf("user %d", member))
	assert.NotContains(t, (*reports)[0], alias, "a report names ids, never an address")
}

// Only a TN id says who someone is. An account with none proves nothing, even when it holds only
// this member's username, so a partner call never merges it: it just loses the address.
func TestPartnerJoinNeverMergesAnAccountWithNoTNId(t *testing.T) {
	prefix := uniquePrefix("partner_nomerge")
	domain := "@user.trashnothing.com"
	key := insertTestPartnerKey(t, prefix, "user.trashnothing.com")
	reports := recordTNAliasReports(t)

	member := CreateTestUser(t, prefix+"_m", utils.SYSTEMROLE_USER)
	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	stampTN(member, 66703)
	alias := prefix + "-g7" + domain
	giveTNAddress(member, prefix+"-g1"+domain)
	giveTNAddress(holder, alias)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 200, partnerJoin(t, key, 66703, alias, groupID))

	assert.True(t, userExists(holder), "the account must not be merged")
	assert.Equal(t, member, addressOwner(alias))
	assert.Len(t, *reports, 1)
}

// TN names a member we have not met, on an address another member's account holds. Joining that
// account would put the wrong member on the community.
func TestPartnerJoinGivesAnUnknownTNMemberTheirOwnAccount(t *testing.T) {
	prefix := uniquePrefix("partner_unknown")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	stampTN(holder, 66705)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66706)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 200, partnerJoin(t, key, 66706, alias, groupID))

	var created uint64
	db.Raw("SELECT COALESCE(id, 0) FROM users WHERE tnuserid = ?", 66706).Scan(&created)
	require.NotZero(t, created, "the member gets an account of their own")
	assert.NotEqual(t, holder, created)
	assert.Equal(t, created, addressOwner(alias))
	assert.True(t, isMember(created, groupID))
	assert.False(t, isMember(holder, groupID))
	assert.Len(t, *reports, 1)
}

// The member leaving is one we have not met, on an address another member holds. Treating that as
// the other member leaving would remove them from a community they never left.
func TestPartnerLeaveByAnUnknownTNMemberRemovesNobody(t *testing.T) {
	prefix := uniquePrefix("partner_leave")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")

	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	stampTN(holder, 66712)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66713)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	groupID := CreateTestGroup(t, prefix)
	require.Equal(t, 200, partnerJoin(t, key, 66712, alias, groupID))

	target := fmt.Sprintf("/api/memberships?partner=%s&email=%s&groupid=%d&tnuserid=66713", key, url.QueryEscape(alias), groupID)
	resp, err := getApp().Test(httptest.NewRequest("DELETE", target, nil), -1)
	require.NoError(t, err)

	assert.Equal(t, 404, resp.StatusCode)
	assert.True(t, isMember(holder, groupID), "the other member is still on the community")
}

// Without a TN id nobody has said whose the address is, so it stays where it is.
func TestPartnerCallWithoutTNIdMovesNothing(t *testing.T) {
	prefix := uniquePrefix("partner_noid")
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	stampTN(holder, 66707)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 200, partnerJoin(t, key, 0, alias, groupID))

	assert.Equal(t, holder, addressOwner(alias))
	assert.True(t, isMember(holder, groupID))
	assert.Empty(t, *reports)
}

// A post stays on the account it was filed on when its alias moves. That account has no TN id and
// the post is TN's own, so TN can still act on it, or moving the alias would bring back the
// "Not your message" this path exists to stop.
func TestPartnerPromiseReachesAPostAfterItsAliasMoved(t *testing.T) {
	prefix := uniquePrefix("partner_moved_post")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	recordTNAliasReports(t)

	member := CreateTestUser(t, prefix+"_m", utils.SYSTEMROLE_USER)
	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	stampTN(member, 66708)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	groupID := CreateTestGroup(t, prefix)

	msgID := CreateTestMessage(t, holder, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ?, fromaddr = ? WHERE id = ?", 484848, alias, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)
	ownCopy := CreateTestMessage(t, member, CreateTestGroup(t, prefix+"_2"), prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 484848, ownCopy)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", ownCopy)

	require.Equal(t, 200, partnerJoin(t, key, 66708, alias, groupID))
	require.Equal(t, member, addressOwner(alias))

	assert.Equal(t, 200, partnerPromise(t, key, 66708, alias, 484848))

	var count int64
	db.Table("messages_promises").Where("msgid = ? AND userid = ?", msgID, holder).Count(&count)
	assert.Equal(t, int64(1), count)
}

// A post on an account with no TN id is nobody's in particular, so being TN's own post is not
// enough: a wrong message id in a call, from a TN bug or a replayed call, must not hand the member
// somebody else's post. With no copy of it on the member's account, nothing ties it to them.
func TestPartnerActionNeedsACopyOnTheMembersAccountToActOnAPostWithNoTNId(t *testing.T) {
	prefix := uniquePrefix("partner_untied")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")

	member := CreateTestUser(t, prefix+"_m", utils.SYSTEMROLE_USER)
	other := CreateTestUser(t, prefix+"_o", utils.SYSTEMROLE_USER)
	stampTN(member, 66721)
	alias := prefix + "-g7@test.com"
	giveTNAddress(member, alias)
	giveTNAddress(other, "other"+prefix+"-g7@test.com")
	groupID := CreateTestGroup(t, prefix)

	msgID := CreateTestMessage(t, other, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 545454, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)

	// A copy of a different TN post on the member's account ties nothing.
	unrelated := CreateTestMessage(t, member, groupID, prefix+" other", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 545455, unrelated)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", unrelated)

	assert.Equal(t, 403, partnerAction(t, key, 66721, alias, fmt.Sprintf(`{"id":%d,"action":"Promise"}`, msgID)))
	assert.Equal(t, 403, partnerPromise(t, key, 66721, alias, 545454))

	patch := httptest.NewRequest("PATCH",
		fmt.Sprintf("/api/message?partner=%s&tnuserid=66721&email=%s", key, url.QueryEscape(alias)),
		strings.NewReader(fmt.Sprintf(`{"id":%d,"subject":"OFFER: hijacked"}`, msgID)))
	patch.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(patch, -1)
	require.NoError(t, err)
	assert.NotEqual(t, 200, resp.StatusCode)

	var count int64
	db.Table("messages_promises").Where("msgid = ?", msgID).Count(&count)
	assert.Equal(t, int64(0), count)

	var subject string
	db.Table("messages").Select("subject").Where("id = ?", msgID).Scan(&subject)
	assert.Equal(t, prefix+" subject", subject)
}

// An account holding a different TN id is another member, whatever address or username it shares
// with this one, and whatever address the post came from. tn:split-shared-account is what moves a
// misfiled post; until then TN cannot act on it for this member.
func TestPartnerPromiseDoesNotReachAPostOnAnotherTNMembersAccount(t *testing.T) {
	prefix := uniquePrefix("partner_othermember")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	recordTNAliasReports(t)

	member := CreateTestUser(t, prefix+"_s", utils.SYSTEMROLE_USER)
	other := CreateTestUser(t, prefix+"_o", utils.SYSTEMROLE_USER)
	stampTN(member, 66710)
	stampTN(other, 66711)
	alias := prefix + "-g4707@test.com"
	giveTNAddress(other, alias)
	groupID := CreateTestGroup(t, prefix)

	msgID := CreateTestMessage(t, other, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ?, fromaddr = ? WHERE id = ?", 494949, alias, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)

	// The post came from the very address TN sends, and that moves to the member on this call.
	assert.Equal(t, 403, partnerPromise(t, key, 66710, alias, 494949))
	assert.Equal(t, member, addressOwner(alias))

	var count int64
	db.Table("messages_promises").Where("msgid = ?", msgID).Count(&count)
	assert.Equal(t, int64(0), count)
}

// The call has to name the member by TN id. Resolving to an account with one through its address
// alone would let an address decide.
func TestPartnerPromiseNeedsATNIdInTheCallToActOnATNPost(t *testing.T) {
	prefix := uniquePrefix("partner_noid_post")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")

	caller := CreateTestUser(t, prefix+"_c", utils.SYSTEMROLE_USER)
	owner := CreateTestUser(t, prefix+"_o", utils.SYSTEMROLE_USER)
	stampTN(caller, 66715)
	alias := prefix + "-g7@test.com"
	giveTNAddress(caller, alias)
	groupID := CreateTestGroup(t, prefix)

	msgID := CreateTestMessage(t, owner, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 505050, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)

	assert.Equal(t, 403, partnerPromise(t, key, 0, alias, 505050))

	// No id in the call and none on the account must not count as a match.
	unstamped := CreateTestUser(t, prefix+"_u", utils.SYSTEMROLE_USER)
	unstampedAlias := "un" + prefix + "-g8@test.com"
	giveTNAddress(unstamped, unstampedAlias)
	assert.Equal(t, 403, partnerPromise(t, key, 0, unstampedAlias, 505050))
}

// A post TN did not send is not TN's to act on.
func TestPartnerPromiseDoesNotReachAPostThatIsNotTNs(t *testing.T) {
	prefix := uniquePrefix("partner_notts")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")

	member := CreateTestUser(t, prefix+"_m", utils.SYSTEMROLE_USER)
	owner := CreateTestUser(t, prefix+"_o", utils.SYSTEMROLE_USER)
	stampTN(member, 66714)
	alias := prefix + "-g7@test.com"
	giveTNAddress(member, alias)
	groupID := CreateTestGroup(t, prefix)

	msgID := CreateTestMessage(t, owner, groupID, prefix+" subject", 51.5, -0.1)

	assert.Equal(t, 403, partnerAction(t, key, 66714, alias, fmt.Sprintf(`{"id":%d,"action":"Promise"}`, msgID)))

	var count int64
	db.Table("messages_promises").Where("msgid = ?", msgID).Count(&count)
	assert.Equal(t, int64(0), count)
}

// Holding the address says nothing about who an account is, so a call naming a TN id no account
// holds resolves to nobody, not to whoever holds the address.
func TestFindTNCandidatesNeverPicksAnAccountByAddressForANamedMember(t *testing.T) {
	prefix := uniquePrefix("partner_noaddr")
	db := database.DBConn

	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66716)

	assert.Empty(t, user.FindTNCandidates(db, 66716, alias))
	assert.Equal(t, []uint64{holder}, user.FindTNCandidates(db, 0, alias), "with no id, only an address is left")
}

// An account with no TN id is no more this member's for holding the address, so it is not given
// their id: they get an account of their own, and it just loses the address.
func TestPartnerJoinNeverStampsAnAccountFoundByItsAddress(t *testing.T) {
	prefix := uniquePrefix("partner_nostamp")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66717)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 200, partnerJoin(t, key, 66717, alias, groupID))

	assert.Equal(t, uint64(0), user.TNIdOf(db, holder), "the account holding the address is not stamped")
	assert.False(t, isMember(holder, groupID))

	var created uint64
	db.Raw("SELECT COALESCE(id, 0) FROM users WHERE tnuserid = ?", 66717).Scan(&created)
	require.NotZero(t, created)
	assert.Equal(t, created, addressOwner(alias))
	assert.True(t, isMember(created, groupID))
	assert.Len(t, *reports, 1)
}

// A member we have not met gets an account of their own, and the post stays on the account it was
// filed on, which holds no TN id. The new account has no copy of the post, so nothing ties it to
// this member and TN cannot act on it until tn:split-shared-account moves it.
func TestPartnerPromiseByAnUnknownTNMemberDoesNotReachAPostOnlyOnAnotherAccount(t *testing.T) {
	prefix := uniquePrefix("partner_unknown_post")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	recordTNAliasReports(t)

	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	alias := prefix + "-g7@test.com"
	giveTNAddress(holder, alias)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66718)
	groupID := CreateTestGroup(t, prefix)

	msgID := CreateTestMessage(t, holder, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", 515151, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)

	assert.Equal(t, 403, partnerPromise(t, key, 66718, alias, 515151))

	var count int64
	db.Table("messages_promises").Where("msgid = ?", msgID).Count(&count)
	assert.Equal(t, int64(0), count)

	var created uint64
	db.Raw("SELECT COALESCE(id, 0) FROM users WHERE tnuserid = ?", 66718).Scan(&created)
	assert.Equal(t, created, addressOwner(alias))
}

// TN always has its member's id, so a TN address on its own is a call that has not said who it is
// about. Other partners have no such id, and are still matched on the address.
func TestPartnerCallOnTNsDomainMustNameATNId(t *testing.T) {
	prefix := uniquePrefix("partner_needid")
	key := insertTestPartnerKey(t, prefix, "user.trashnothing.com")

	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	stampTN(holder, 66719)
	alias := prefix + "-g7@user.trashnothing.com"
	giveTNAddress(holder, alias)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 400, partnerJoin(t, key, 0, alias, groupID))
	assert.False(t, isMember(holder, groupID))

	target := fmt.Sprintf("/api/memberships?partner=%s&email=%s&groupid=%d", key, url.QueryEscape(alias), groupID)
	resp, err := getApp().Test(httptest.NewRequest("DELETE", target, nil), -1)
	require.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	assert.Equal(t, 400, partnerPromise(t, key, 0, alias, 525252))
}

// A post from the posts API has no fromaddr, so the address's domain cannot say it is TN's. Its
// tnpostid can.
func TestPartnerKeyAloneActsOnATNPostWithNoAddress(t *testing.T) {
	prefix := uniquePrefix("partner_keyonly")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "user.trashnothing.com")

	owner := CreateTestUser(t, prefix+"_o", utils.SYSTEMROLE_USER)
	stampTN(owner, 66720)
	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, owner, groupID, prefix+" subject", 51.5, -0.1)
	db.Exec("UPDATE messages SET tnpostid = ?, fromaddr = NULL WHERE id = ?", 535353, msgID)
	defer db.Exec("UPDATE messages SET tnpostid = NULL WHERE id = ?", msgID)

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?partner=%s", key),
		strings.NewReader(`{"tnpostid":"535353","action":"Promise"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	db.Table("messages_promises").Where("msgid = ? AND userid = ?", msgID, owner).Count(&count)
	assert.Equal(t, int64(1), count)
}

// A deleted account proves no more than a live one, and only keeps an address until it is
// forgotten, so the address goes to the member TN named. It used to be silently dropped.
func TestPartnerJoinTakesAnAddressFromADeletedAccount(t *testing.T) {
	prefix := uniquePrefix("partner_deleted")
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	member := CreateTestUser(t, prefix+"_m", utils.SYSTEMROLE_USER)
	stampTN(member, 66721)
	deleted := CreateDeletedTestUser(t, prefix+"_d")
	alias := prefix + "-g7@test.com"
	giveTNAddress(deleted, alias)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 200, partnerJoin(t, key, 66721, alias, groupID))

	assert.Equal(t, member, addressOwner(alias))
	assert.Len(t, *reports, 1)
}

// A member we have not met used to get an account with no address at all: the insert failed on the
// unique index with nothing to say so.
func TestPartnerJoinGivesANewMemberAnAddressHeldByADeletedAccount(t *testing.T) {
	prefix := uniquePrefix("partner_deleted_new")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	recordTNAliasReports(t)

	deleted := CreateDeletedTestUser(t, prefix+"_d")
	alias := prefix + "-g7@test.com"
	giveTNAddress(deleted, alias)
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", 66722)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 200, partnerJoin(t, key, 66722, alias, groupID))

	var created uint64
	db.Raw("SELECT COALESCE(id, 0) FROM users WHERE tnuserid = ?", 66722).Scan(&created)
	require.NotZero(t, created)
	assert.Equal(t, created, addressOwner(alias))
}

// partnerCreatedAccounts counts the accounts CreatePartnerUser named from this address.
func partnerCreatedAccounts(localpart string) int64 {
	var n int64
	database.DBConn.Table("users").Where("fullname = ?", strings.Title(localpart)).Count(&n) //nolint:staticcheck

	return n
}

// users.tnuserid is unique, so while a deleted account holds the id no new account can have it. Each
// call used to make another account with no id and move the address to it, one more for every call.
func TestPartnerJoinIsRefusedWhileADeletedAccountHoldsTheTNId(t *testing.T) {
	prefix := uniquePrefix("partner_held")
	db := database.DBConn
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	deleted := CreateDeletedTestUser(t, prefix+"_d")
	stampTN(deleted, 66723)
	localpart := fmt.Sprintf("heldjoin%d", deleted)
	alias := localpart + "-g7@test.com"
	groupID := CreateTestGroup(t, prefix)

	for i := 0; i < 3; i++ {
		assert.Equal(t, 403, partnerJoin(t, key, 66723, alias, groupID))
	}

	assert.Zero(t, partnerCreatedAccounts(localpart), "no account is made for the member")
	assert.Zero(t, addressOwner(alias), "the address goes nowhere")
	assert.Empty(t, *reports)

	var members int64
	db.Table("memberships").Where("groupid = ?", groupID).Count(&members)
	assert.Zero(t, members)
}

// The deleted account usually still holds the member's address too, and it stays there.
func TestPartnerJoinLeavesAnAddressOnTheDeletedAccountHoldingTheTNId(t *testing.T) {
	prefix := uniquePrefix("partner_held_addr")
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	deleted := CreateDeletedTestUser(t, prefix+"_d")
	stampTN(deleted, 66724)
	localpart := fmt.Sprintf("heldaddr%d", deleted)
	alias := localpart + "-g7@test.com"
	giveTNAddress(deleted, alias)
	groupID := CreateTestGroup(t, prefix)

	assert.Equal(t, 403, partnerJoin(t, key, 66724, alias, groupID))

	assert.Zero(t, partnerCreatedAccounts(localpart))
	assert.Equal(t, deleted, addressOwner(alias))
	assert.Empty(t, *reports)
}

func TestPartnerActionIsRefusedWhileADeletedAccountHoldsTheTNId(t *testing.T) {
	prefix := uniquePrefix("partner_held_act")
	key := insertTestPartnerKey(t, prefix, "test.com")
	reports := recordTNAliasReports(t)

	deleted := CreateDeletedTestUser(t, prefix+"_d")
	stampTN(deleted, 66725)
	holder := CreateTestUser(t, prefix+"_h", utils.SYSTEMROLE_USER)
	localpart := fmt.Sprintf("heldact%d", deleted)
	alias := localpart + "-g7@test.com"
	giveTNAddress(holder, alias)

	assert.Equal(t, 403, partnerPromise(t, key, 66725, alias, 535354))

	assert.Zero(t, partnerCreatedAccounts(localpart))
	assert.Equal(t, holder, addressOwner(alias))
	assert.Empty(t, *reports)
}
