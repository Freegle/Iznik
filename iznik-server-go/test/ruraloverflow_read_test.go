package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// The overflow rings as ways IN on every read surface. The mail path deliberately
// invites ring-admitted members (UnifiedDigestService overflowBranch), so search, the
// message page and the reply gate must agree with it - the production incident behind
// these tests was mail inviting members that browse-scoped search could not find, the
// message page told "not reached yet", and the reply gate held indefinitely.

// ringSchemaExec makes the shared rippling_reach stand-in carry every column these tests
// touch, whichever test's CREATE TABLE IF NOT EXISTS won. MySQL 8 has no ADD COLUMN IF
// NOT EXISTS, so each ALTER is fired blind and an "already exists" error is ignored -
// db.Exec here never checks errors anyway.
func ringSchemaExec(t *testing.T) {
	t.Helper()
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	for _, alter := range []string{
		"ALTER TABLE rippling_reach ADD COLUMN outer_bound GEOMETRY NULL",
		"ALTER TABLE rippling_reach ADD COLUMN inner_bound GEOMETRY NULL",
		"ALTER TABLE rippling_reach ADD COLUMN overflow_bounds JSON NULL",
		"ALTER TABLE rippling_reach ADD COLUMN schedule LONGTEXT NULL",
		"ALTER TABLE rippling_reach ADD COLUMN arrival TIMESTAMP NULL",
	} {
		db.Exec(alter)
	}
}

// farReach inserts a reach row whose polygon is far from (51.5, -0.1) - the viewer in
// all three tests - with a rural sparse ring (and bbox) that DOES cover them.
func farReachWithSparseRing(t *testing.T, msgID uint64) {
	db := database.DBConn
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status, overflow_bounds) VALUES (?, 53.0, 2.0, "+
		"ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857)), 'expanding', "+
		"JSON_OBJECT('bbox', JSON_ARRAY(-0.3, 51.3, 2.2, 53.2), "+
		"'rural', JSON_OBJECT('sparse', 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))'))) "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), overflow_bounds = VALUES(overflow_bounds)", msgID)
}

// stubRingIndex points the spatial client at a server answering the ring
// containment question the way the real reachoverflow dataset would for these
// fixtures.
//
// Every read surface asks that one question now - browse, search, the badge, the
// message page and the reply gate - because re-deriving it per surface from the
// ring JSON is how they drift apart, and because the JSON form cost 4.8s a page
// load. So a test that seeds a ring has to serve one; without it the fixture
// asserts that rings are dark, which is what an unreachable spatial server
// correctly produces.
//
// The stub answers only for the lanes the caller asks about, which is what makes
// the dense-band viewer's exclusion a real assertion rather than an artefact of
// an empty answer: a dense-band viewer asks about "$.rural.dense" and this ring
// is a sparse one.
func stubRingIndex(t *testing.T, lane string, admits ...uint64) {
	t.Helper()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/reachoverflow/containing" {
			http.NotFound(w, r)
			return
		}
		ids := []uint64{}
		for _, l := range strings.Split(r.URL.Query().Get("lanes"), ",") {
			if l == lane {
				ids = append(ids, admits...)
				break
			}
		}
		w.Header().Set("Content-Type", "application/json")
		json2.NewEncoder(w).Encode(map[string]any{"in": ids, "partial": []uint64{}})
	}))
	t.Cleanup(srv.Close)
	t.Setenv("SPATIAL_KNN_URL", srv.URL)
}

// TestBrowseScopedSearch_RingAdmittedPostSearchable: a post the feed shows via the
// viewer's band ring must also be findable by searching - scrollable-but-unsearchable
// was the search half of the incident.
func TestBrowseScopedSearch_RingAdmittedPostSearchable(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "1")
	db := database.DBConn
	ringSchemaExec(t)

	prefix := uniquePrefix("ringsearch")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, "Member")
	ringed := CreateTestMessage(t, posterID, group, "Quibblewick Chair ring admits viewer (ringsearch)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", ringed)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", ringed)

	farReachWithSparseRing(t, ringed)
	stubRingIndex(t, "$.rural.sparse", ringed)

	// A sparse-band viewer the ring covers.
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), "+
		"'$.mylocation', JSON_OBJECT('lat', 51.5, 'lng', -0.1), "+
		"'$.browseDensityBand', 'sparse') WHERE id = ?", viewerID)

	// A dense-band viewer at the same spot: the post carries only a sparse ring, so
	// their band earns them nothing and the far polygon excludes them.
	denseID, denseToken := CreateFullTestUser(t, prefix+"_dense")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), "+
		"'$.mylocation', JSON_OBJECT('lat', 51.5, 'lng', -0.1), "+
		"'$.browseDensityBand', 'dense') WHERE id = ?", denseID)

	words := message.GetWords("Quibblewick Chair ring admits viewer (ringsearch)")
	search := func(tok string) map[uint64]bool {
		resp, _ := getApp().Test(httptest.NewRequest("GET",
			"/api/message/search/"+words[0]+"?browse=1&jwt="+tok, nil), 60000)
		assert.Equal(t, 200, resp.StatusCode)
		var results []message.SearchResult
		json2.Unmarshal(rsp(resp), &results)
		got := map[uint64]bool{}
		for _, r := range results {
			got[r.Msgid] = true
		}
		return got
	}

	assert.True(t, search(token)[ringed],
		"browse-scoped search finds a post whose ring admits the viewer's band")
	assert.False(t, search(denseToken)[ringed],
		"a band the post carries no ring for is still excluded by the far polygon")
}

// TestReachBlocked_RingAdmittedViewerNotBlocked: the message page banner and reply
// eligibility come from ReachBlockedSet, which must not call a ring-admitted viewer
// blocked - they may have been mailed the post.
func TestReachBlocked_RingAdmittedViewerNotBlocked(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "1")
	db := database.DBConn
	ringSchemaExec(t)

	prefix := uniquePrefix("ringblocked")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, "Member")
	msgID := CreateTestMessage(t, posterID, group, "OFFER: ring blocked test item", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	farReachWithSparseRing(t, msgID)

	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), "+
		"'$.browseDensityBand', 'sparse') WHERE id = ?", viewerID)
	stubRingIndex(t, "$.rural.sparse", msgID)

	// No viewer: the far polygon blocks the point, ring or no ring - the match mailers
	// check from the post's own location and must stay strict.
	blocked := message.ReachBlockedSet(0, []uint64{msgID}, 51.5, -0.1)
	assert.True(t, blocked[msgID], "with no viewer the far reach blocks the point")

	// The sparse-band viewer's ring rescues them.
	blocked = message.ReachBlockedSet(viewerID, []uint64{msgID}, 51.5, -0.1)
	assert.False(t, blocked[msgID], "a viewer admitted by their band's ring is not blocked")
}

// TestReachBlocked_ClusterRingNeverRescuesTheMailer: the matched-posts mailer
// (message/postmatches.go, "matched-posts email") checks reach from a POST's location with
// no viewer, and must stay strict. The cluster lane is pull-only, so a wedge must never be
// what lets a post into an email.
//
// This plants a real CLUSTER ring, which the rural fixture above deliberately does not: a
// test whose fixture carries only a rural key passes for the wrong reason, because the
// cluster paths then query a missing key and COALESCE to false whatever the code does.
func TestReachBlocked_ClusterRingNeverRescuesTheMailer(t *testing.T) {
	t.Setenv("RIPPLE_CLUSTER_ANCHOR_ENABLED", "1")
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "1")
	db := database.DBConn
	ringSchemaExec(t)

	prefix := uniquePrefix("clustermailer")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, "Member")
	msgID := CreateTestMessage(t, posterID, group, "OFFER: cluster mailer test item", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// Far reach, plus a cluster wedge that DOES cover (51.5, -0.1).
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status, overflow_bounds) VALUES (?, 53.0, 2.0, "+
		"ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857)), 'expanding', "+
		"JSON_OBJECT('bbox', JSON_ARRAY(-0.3, 51.3, 2.2, 53.2), "+
		"'cluster', JSON_OBJECT('w1', 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))'))) "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), overflow_bounds = VALUES(overflow_bounds)", msgID)

	stubRingIndex(t, "$.cluster.w1", msgID)

	// No viewer: the mailer's call. The wedge covers the point, and must not rescue it.
	// ViewerOverflowPaths returns nothing without a viewer, so the ring index is never
	// even asked - which is the guarantee, not an accident of this stub.
	blocked := message.ReachBlockedSet(0, []uint64{msgID}, 51.5, -0.1)
	assert.True(t, blocked[msgID],
		"a cluster wedge must never admit a viewer-less caller: postmatches feeds the matched-posts email")

	// The same wedge, same point, with a real viewer: browse and reply DO admit.
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	blocked = message.ReachBlockedSet(viewerID, []uint64{msgID}, 51.5, -0.1)
	assert.False(t, blocked[msgID],
		"the same wedge admits a real viewer, so the lane is on and the fixture is sound")
}

// TestCreateChatMessage_RingAdmittedReplyNotHeld: a ring-admitted replier's message must
// be delivered, not held - a capped post never grows, so a hold would sit until the item
// was taken, silencing exactly the member the mail invited.
func TestCreateChatMessage_RingAdmittedReplyNotHeld(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "1")
	db := database.DBConn
	ringSchemaExec(t)
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL, chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL, replieruserid BIGINT UNSIGNED NOT NULL,
		source ENUM('email','tn','web') NOT NULL DEFAULT 'email',
		lat DOUBLE, lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, releasedat TIMESTAMP NULL,
		INDEX (msgid), INDEX (chatid), INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("ringreply")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), "+
		"'$.mylocation', JSON_OBJECT('lat', 51.5, 'lng', -0.1), "+
		"'$.browseDensityBand', 'sparse') WHERE id = ?", replierID)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: ring reply test item", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", msgID)

	farReachWithSparseRing(t, msgID)
	stubRingIndex(t, "$.rural.sparse", msgID)

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)

	var payload chat.ChatMessage
	payload.Message = "I'd like this please"
	payload.Refmsgid = &msgID
	s, _ := json2.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode, "the ring-admitted reply is accepted")

	var heldCount int
	db.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE msgid = ? AND replieruserid = ?",
		msgID, replierID).Scan(&heldCount)
	assert.Equal(t, 0, heldCount,
		"a ring-admitted reply is delivered, not held: the mail invited this member")
}

// Freezing a reach stops the post being pushed OUTWARD. It does not make anyone reached.
//
// FreezeReachIfOriginPending sets status 'held' when a post's origin copy is pulled back for
// moderation. The mail and push paths must leave such a post alone, because we should not
// advertise something that is under review. But a member OUTSIDE the polygon still has not
// been reached by it, so the message page must still say so and their reply must still be
// held - the same answer they would get on a post that is simply still expanding.
func TestReachBlocked_FrozenReachStillBlocksThoseOutsideIt(t *testing.T) {
	db := database.DBConn
	ringSchemaExec(t)

	prefix := uniquePrefix("heldreach")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, "Member")
	msgID := CreateTestMessage(t, posterID, group, "OFFER: frozen reach test item", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	viewerID := CreateTestUser(t, prefix+"_viewer", "User")

	// A live reach far from the viewer: they are genuinely not reached yet.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 53.0, 2.0, "+
		"ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857), "+
		"ST_Envelope(ST_GeomFromText('POLYGON((2.0 53.0, 2.1 53.0, 2.1 53.1, 2.0 53.1, 2.0 53.0))', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), status = VALUES(status)", msgID)

	blocked := message.ReachBlockedSet(viewerID, []uint64{msgID}, 51.5, -0.1)
	assert.True(t, blocked[msgID], "a live reach that has not arrived yet blocks")

	// Freeze it. The same viewer, the same distance - and the same answer, because freezing
	// changes what we SEND, not who has been reached.
	db.Exec("UPDATE rippling_reach SET status = 'held' WHERE msgid = ?", msgID)

	blocked = message.ReachBlockedSet(viewerID, []uint64{msgID}, 51.5, -0.1)
	assert.True(t, blocked[msgID],
		"a frozen reach still blocks someone outside it: they have not been reached")
}
