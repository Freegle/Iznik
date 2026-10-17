package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"sort"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// The Check queue is "posts that published themselves on a trial community and that no
// moderator has looked at". Four things read that definition - the list, the menu badge,
// "Mark all as checked" and "mark these ids" - and the Check reject reads it too, apart from
// the already-checked rule. They used to be separate hand-written copies that drifted: the
// bucket stamped posts of deleted members the list hid, the ids branch stamped copies a
// moderator had approved, and the list and badge dropped a post the moment its poster left
// the community. These tests pin one definition across all of them.

type checkQueueFixture struct {
	group    uint64
	mod      uint64
	token    string
	otherMod uint64 // checked the post that is already out of the queue
	eligible map[string]uint64
	excluded map[string]uint64
}

func (f checkQueueFixture) eligibleIDs() []uint64 {
	var ids []uint64
	for _, id := range f.eligible {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
	return ids
}

func (f checkQueueFixture) allIDs() []uint64 {
	ids := f.eligibleIDs()
	for _, id := range f.excluded {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
	return ids
}

// newCheckQueueFixture builds one trial community with a moderator and a set of self-published
// posts. The two in `eligible` are in the queue; every post in `excluded` fails exactly one
// rule of the definition.
func newCheckQueueFixture(t *testing.T, name string) checkQueueFixture {
	t.Helper()
	db := database.DBConn
	prefix := uniquePrefix(name)

	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	f := checkQueueFixture{
		group:    CreateTestGroup(t, prefix),
		eligible: map[string]uint64{},
		excluded: map[string]uint64{},
	}
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d", f.group))

	f.mod = CreateTestUser(t, prefix+"_mod", "User")
	f.otherMod = CreateTestUser(t, prefix+"_othermod", "User")
	CreateTestMembership(t, f.mod, f.group, "Moderator")
	CreateTestMembership(t, f.otherMod, f.group, "Moderator")
	_, f.token = CreateTestSession(t, f.mod)

	member := func(suffix, status string) uint64 {
		u := CreateTestUser(t, prefix+"_"+suffix, "User")
		if status != "left" {
			CreateTestMembership(t, u, f.group, "Member")
			if status == "" {
				db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", u, f.group)
			} else {
				db.Exec("UPDATE memberships SET ourPostingStatus = ? WHERE userid = ? AND groupid = ?", status, u, f.group)
			}
		}
		return u
	}
	plain := member("plain", "")
	left := member("left", "left") // posted, then left or was banned: no membership row
	moderated := member("moderated", "MODERATED")
	trusted := member("trusted", "DEFAULT")
	gone := member("gone", "")
	db.Exec("UPDATE users SET deleted = NOW() WHERE id = ?", gone)

	var created []uint64
	add := func(set map[string]uint64, label string, poster uint64, tweak func(id uint64)) {
		id := CreateTestMessage(t, poster, f.group, prefix+" "+label, 52.0, -1.0)
		// The baseline copy: live, self-published, direct, unchecked, arrived 15 minutes ago.
		db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, rippled_in=0, deleted=0, checkedat=NULL, checkedby=NULL WHERE msgid=?", id)
		if tweak != nil {
			tweak(id)
		}
		set[label] = id
		created = append(created, id)
	}

	add(f.eligible, "plain", plain, nil)
	add(f.eligible, "left", left, nil)

	add(f.excluded, "handApproved", plain, func(id uint64) {
		db.Exec("UPDATE messages_groups SET approvedby=?, approvedat=NOW() WHERE msgid=?", f.mod, id)
	})
	add(f.excluded, "moderatedMember", moderated, nil)
	add(f.excluded, "trustedMember", trusted, nil)
	add(f.excluded, "deletedPost", plain, func(id uint64) {
		db.Exec("UPDATE messages SET deleted = NOW() WHERE id=?", id)
	})
	add(f.excluded, "deletedMember", gone, nil)
	add(f.excluded, "tooOld", plain, func(id uint64) {
		db.Exec("UPDATE messages_groups SET arrival = NOW() - INTERVAL 9 DAY WHERE msgid=?", id)
	})
	add(f.excluded, "alreadyChecked", plain, func(id uint64) {
		db.Exec("UPDATE messages_groups SET checkedat=NOW(), checkedby=? WHERE msgid=?", f.otherMod, id)
	})
	add(f.excluded, "rippledIn", plain, func(id uint64) {
		db.Exec("UPDATE messages_groups SET rippled_in=1 WHERE msgid=?", id)
	})
	add(f.excluded, "pending", plain, func(id uint64) {
		db.Exec("UPDATE messages_groups SET collection='Pending', contentcheck_checked_at=NOW() WHERE msgid=?", id)
	})
	add(f.excluded, "deletedCopy", plain, func(id uint64) {
		db.Exec("UPDATE messages_groups SET deleted=1 WHERE msgid=?", id)
	})

	t.Cleanup(func() {
		db.Exec("DELETE FROM logs WHERE msgid IN ?", created)
		db.Exec("DELETE FROM rippling_blocked WHERE msgid IN ?", created)
		db.Exec("DELETE FROM messages_groups WHERE msgid IN ?", created)
		db.Exec("DELETE FROM messages WHERE id IN ?", created)
		db.Exec("UPDATE users SET deleted = NULL WHERE id = ?", gone)
	})

	return f
}

func listCheckQueue(t *testing.T, token string, groupid uint64) []uint64 {
	t.Helper()
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/messages?groupid=%d&collection=Approved&filter=checked&limit=100&jwt=%s", groupid, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var body struct {
		Messages []uint64 `json:"messages"`
	}
	json.NewDecoder(resp.Body).Decode(&body)
	sort.Slice(body.Messages, func(i, j int) bool { return body.Messages[i] < body.Messages[j] })
	return body.Messages
}

func postMarkChecked(t *testing.T, token string, body string) map[string]interface{} {
	t.Helper()
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var out map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&out)
	return out
}

func idsJSON(ids []uint64) string {
	parts := make([]string, len(ids))
	for i, id := range ids {
		parts[i] = fmt.Sprintf("%d", id)
	}
	return "[" + strings.Join(parts, ",") + "]"
}

// stampedBy returns the posts on the community whose copy carries this moderator's check.
func stampedBy(group, mod uint64) []uint64 {
	var ids []uint64
	database.DBConn.Raw("SELECT msgid FROM messages_groups WHERE groupid = ? AND checkedby = ? ORDER BY msgid", group, mod).Scan(&ids)
	return ids
}

func TestCheckQueueListIsTheDefinition(t *testing.T) {
	f := newCheckQueueFixture(t, "cq_list")

	assert.Equal(t, f.eligibleIDs(), listCheckQueue(t, f.token, f.group),
		"the list holds the self-published, unchecked, in-window posts of members with no posting status - and a post whose poster has left the community stays in it")
}

func TestCheckQueueBadgeIsTheDefinition(t *testing.T) {
	f := newCheckQueueFixture(t, "cq_badge")

	assert.Equal(t, float64(len(f.eligible)), getSessionWork(t, f.token)["checked"],
		"the menu badge counts the same posts the list shows")
}

func TestCheckQueueMarkAllIsTheDefinition(t *testing.T) {
	f := newCheckQueueFixture(t, "cq_bucket")

	out := postMarkChecked(t, f.token, fmt.Sprintf(`{"groupid": %d, "filter": "checked"}`, f.group))
	assert.Equal(t, float64(len(f.eligible)), out["checked"])
	assert.Equal(t, f.eligibleIDs(), stampedBy(f.group, f.mod),
		"marking the bucket stamps exactly the posts in the queue: not a deleted post, not a deleted member's, not a moderator-approved copy")
}

func TestCheckQueueMarkIdsIsTheDefinition(t *testing.T) {
	f := newCheckQueueFixture(t, "cq_ids")

	// Every post of the fixture is named; only the ones in the queue may be stamped. A
	// hand-approved copy or a trusted member's post is not part of Check, and a stamp there
	// would count as a review in the stats and lift the earned-reach hold.
	out := postMarkChecked(t, f.token, fmt.Sprintf(`{"groupid": %d, "ids": %s}`, f.group, idsJSON(f.allIDs())))
	assert.Equal(t, float64(len(f.eligible)), out["checked"])
	assert.Equal(t, f.eligibleIDs(), stampedBy(f.group, f.mod))
}

// The Check reject reads the same definition, except that a post already stamped checked can
// still be pulled back (a microvolunteer quorum stamps without a moderator looking).
func TestCheckQueueRejectIsTheDefinition(t *testing.T) {
	f := newCheckQueueFixture(t, "cq_reject")

	out := postMarkChecked(t, f.token, fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": %s}`, f.group, idsJSON(f.allIDs())))
	expected := append(f.eligibleIDs(), f.excluded["alreadyChecked"])
	sort.Slice(expected, func(i, j int) bool { return expected[i] < expected[j] })
	assert.Equal(t, float64(len(expected)), out["rejected"], "one per copy pulled back")

	var pulled []uint64
	database.DBConn.Raw("SELECT msgid FROM messages_groups WHERE groupid = ? AND collection = 'Pending' AND heldby = ? AND needs_moderator = 1 ORDER BY msgid",
		f.group, f.mod).Scan(&pulled)
	assert.Equal(t, expected, pulled, "only posts in the queue (checked or not) are pulled back")
}

// A plain member, a stranger and a moderator of another community cannot read a community's
// Check queue: it is oversight data (which live posts nobody has looked at), and the
// Approved collection's list is otherwise open to anyone who names the community.
func TestListMessagesMTFilterNeedsAModerator(t *testing.T) {
	f := newCheckQueueFixture(t, "cq_perm")
	prefix := uniquePrefix("cq_perm_users")

	member := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, member, f.group, "Member")
	_, memberToken := CreateTestSession(t, member)
	stranger := CreateTestUser(t, prefix+"_stranger", "User")
	_, strangerToken := CreateTestSession(t, stranger)
	elsewhere := CreateTestGroup(t, prefix+"_elsewhere")
	otherMod := CreateTestUser(t, prefix+"_othermod", "User")
	CreateTestMembership(t, otherMod, elsewhere, "Moderator")
	_, otherModToken := CreateTestSession(t, otherMod)

	status := func(token string, query string) int {
		resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/messages?%s&jwt=%s", query, token), nil))
		assert.NoError(t, err)
		return resp.StatusCode
	}
	named := fmt.Sprintf("groupid=%d&collection=Approved&filter=checked", f.group)

	assert.Equal(t, 403, status(memberToken, named), "a member of the community")
	assert.Equal(t, 403, status(strangerToken, named), "someone with no membership")
	assert.Equal(t, 403, status(otherModToken, named), "a moderator of a different community")
	assert.Equal(t, 200, status(f.token, named), "its own moderator")

	// With no community named a member has no communities to list: empty, not an error.
	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/messages?collection=Approved&filter=checked&jwt=%s", memberToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var body struct {
		Messages []uint64 `json:"messages"`
	}
	json.NewDecoder(resp.Body).Decode(&body)
	assert.Empty(t, body.Messages)

	// A filter this API does not have is refused rather than answered with an unfiltered list.
	assert.Equal(t, 400, status(f.token, fmt.Sprintf("groupid=%d&collection=Approved&filter=bogus", f.group)))
	// The Check queue is posts that are live.
	assert.Equal(t, 400, status(f.token, fmt.Sprintf("groupid=%d&collection=Pending&filter=checked", f.group)))
}

// The badge counts like the list: communities the moderator is active on, and one per post.
func TestCheckBadgeCountsActiveCommunitiesAndOncePerPost(t *testing.T) {
	f := newCheckQueueFixture(t, "cq_badgeactive")
	db := database.DBConn
	prefix := uniquePrefix("cq_badgeactive_x")

	// A backup moderator (active = 0 in the membership settings) of the same community: the
	// all-communities Check list is built from active communities only, so its badge must be too.
	backup := CreateTestUser(t, prefix+"_backup", "User")
	db.Exec("INSERT INTO memberships (userid, groupid, role, settings) VALUES (?, ?, 'Moderator', ?)", backup, f.group, `{"active":0}`)
	_, backupToken := CreateTestSession(t, backup)
	assert.Equal(t, float64(0), getSessionWork(t, backupToken)["checked"], "a backup moderator's badge does not count a community the list leaves out")
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/messages?collection=Approved&filter=checked&limit=100&jwt=%s", backupToken), nil))
	assert.NoError(t, err)
	var all struct {
		Messages []uint64 `json:"messages"`
	}
	json.NewDecoder(resp.Body).Decode(&all)
	assert.Empty(t, all.Messages, "and the list agrees: nothing for a community they are not active on")

	// One post direct on two trial communities (a cross-post) is one item in the list of
	// every community, and one in the badge.
	second := CreateTestGroup(t, prefix+"_second")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d,%d", f.group, second))
	CreateTestMembership(t, f.mod, second, "Moderator")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, poster, f.group, "Member")
	CreateTestMembership(t, poster, second, "Member")
	cross := CreateTestMessage(t, poster, f.group, prefix+" cross-post", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, rippled_in=0 WHERE msgid=?", cross)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 15 MINUTE), 'Approved', 0, 0)", cross, second)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", cross)
		db.Exec("DELETE FROM messages WHERE id = ?", cross)
		db.Exec("DELETE FROM memberships WHERE userid = ?", backup)
	})

	resp, err = getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/messages?collection=Approved&filter=checked&limit=100&jwt=%s", f.token), nil))
	assert.NoError(t, err)
	var mine struct {
		Messages []uint64 `json:"messages"`
	}
	json.NewDecoder(resp.Body).Decode(&mine)
	assert.Len(t, mine.Messages, len(f.eligible)+1, "the cross-post appears once")
	assert.Equal(t, float64(len(mine.Messages)), getSessionWork(t, f.token)["checked"], "and counts once: the badge is the list's length")
}

// A post that is direct on two of the moderator's trial communities (a cross-post) is pulled
// back on both by one Check reject, held by the moderator on each, with one Hold log per
// community, and the answer counts copies: ModTools reads 0 as "nothing happened".
func TestCheckRejectPullsBackEveryCopyOfACrossPost(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("cq_cross_reject")
	db := database.DBConn

	first := CreateTestGroup(t, prefix+"_first")
	second := CreateTestGroup(t, prefix+"_second")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d,%d", first, second))
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	for _, gid := range []uint64{first, second} {
		CreateTestMembership(t, poster, gid, "Member")
		CreateTestMembership(t, mod, gid, "Moderator")
	}
	_, token := CreateTestSession(t, mod)

	msg := CreateTestMessage(t, poster, first, prefix+" cross-post", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, rippled_in=0 WHERE msgid=?", msg)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 15 MINUTE), 'Approved', 0, 0)", msg, second)
	t.Cleanup(func() {
		db.Exec("DELETE FROM logs WHERE msgid = ?", msg)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
		db.Exec("DELETE FROM messages WHERE id = ?", msg)
	})

	out := postMarkChecked(t, token, fmt.Sprintf(`{"groupid": 0, "reject": true, "ids": [%d]}`, msg))
	assert.Equal(t, float64(2), out["rejected"], "both copies were pulled back")

	var pulled int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND collection = 'Pending' AND heldby = ? AND needs_moderator = 1", msg, mod).Scan(&pulled)
	assert.Equal(t, int64(2), pulled, "held by the moderator on each community")
	var holds int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE msgid = ? AND type = 'Message' AND subtype = 'Hold' AND text = 'Pulled back from Check'", msg).Scan(&holds)
	assert.Equal(t, int64(2), holds, "one Hold log per community acted on, not two on each")
}
