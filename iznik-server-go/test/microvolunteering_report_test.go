package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/utils"
)

// addRippledCopy adds a rippled-in (rippled_in=1) Approved copy of msgid on groupid.
func addRippledCopy(msgid, groupid uint64) {
	database.DBConn.Exec(
		"INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
			"VALUES (?, ?, NOW(), 'Approved', 0, 1)", msgid, groupid)
}

// addReachRow seeds a rippling_reach row for msgid with the given status.
func addReachRow(msgid uint64, status string) {
	database.DBConn.Exec(
		"INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, "+
			"total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "+
			"VALUES (?, 51.5, -0.1, ST_GeomFromText('POLYGON((-0.1 51.5, -0.2 51.5, -0.2 51.6, -0.1 51.6, -0.1 51.5))', ?), "+
			"NOW(), 'drive', 1, 3, 90, 30, NULL, NULL, ?, NOW(), NOW())", msgid, utils.SRID, status)
}

func collectionOf(msgid, groupid uint64) string {
	var c string
	database.DBConn.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgid, groupid).Scan(&c)
	return c
}

func reachStatusOf(msgid uint64) string {
	var s string
	database.DBConn.Raw("SELECT status FROM rippling_reach WHERE msgid = ?", msgid).Scan(&s)
	return s
}

// A quorum of member reports pulls the post to Pending on ALL its groups (home + rippled)
// and freezes the reach.
func TestReportVerdictMemberQuorumPendsAllGroupsAndFreezes(t *testing.T) {
	db := database.DBConn
	poster := CreateTestUser(t, "rvposter", "User")
	origin := CreateTestGroup(t, "rvorigin")
	rippled := CreateTestGroup(t, "rvrippled")
	m1 := CreateTestUser(t, "rvmem1", "User")
	m2 := CreateTestUser(t, "rvmem2", "User")
	msgid := CreateTestMessage(t, poster, origin, "OFFER: rvquorum", 51.5, -0.1)
	addRippledCopy(msgid, rippled)
	addReachRow(msgid, "expanding")

	// One member report → below quorum → nothing pended.
	microvolunteering.RecordReportVerdict(db, m1, msgid, origin, "spam")
	if collectionOf(msgid, origin) != utils.COLLECTION_APPROVED || collectionOf(msgid, rippled) != utils.COLLECTION_APPROVED {
		t.Fatalf("one member report must not pend anything: origin=%s rippled=%s",
			collectionOf(msgid, origin), collectionOf(msgid, rippled))
	}

	// Second, different member → quorum → all groups Pending + reach frozen.
	microvolunteering.RecordReportVerdict(db, m2, msgid, rippled, "spam")
	if collectionOf(msgid, origin) != utils.COLLECTION_PENDING {
		t.Errorf("origin must be Pending after quorum, got %s", collectionOf(msgid, origin))
	}
	if collectionOf(msgid, rippled) != utils.COLLECTION_PENDING {
		t.Errorf("rippled copy must be Pending after quorum, got %s", collectionOf(msgid, rippled))
	}
	if reachStatusOf(msgid) != "held" {
		t.Errorf("reach must be frozen 'held' once the origin is Pending, got %s", reachStatusOf(msgid))
	}
}

// A moderator's report counts as quorum on its own: EVERY community's copy (origin and
// rippled) goes to Pending and the reach is frozen, so the post leaves browse and can
// never make the next digest (digests only send Approved copies). Edward's decision on
// Discourse 9862: a scoped mod report left 11 of 12 copies live, so the reported post
// kept appearing in browse and the daily digest.
func TestReportVerdictModReportIsQuorum(t *testing.T) {
	db := database.DBConn
	poster := CreateTestUser(t, "rvmodposter", "User")
	origin := CreateTestGroup(t, "rvmodorigin")
	rippled := CreateTestGroup(t, "rvmodrippled")
	mod := CreateTestUser(t, "rvmodreporter", "User")
	CreateTestMembership(t, mod, rippled, "Moderator") // moderator of the rippled group only
	msgid := CreateTestMessage(t, poster, origin, "OFFER: rvmodscope", 51.5, -0.1)
	addRippledCopy(msgid, rippled)
	addReachRow(msgid, "expanding")

	microvolunteering.RecordReportVerdict(db, mod, msgid, rippled, "not ok here")

	if collectionOf(msgid, rippled) != utils.COLLECTION_PENDING {
		t.Errorf("a mod's report must pend the reported group's copy, got %s", collectionOf(msgid, rippled))
	}
	if collectionOf(msgid, origin) != utils.COLLECTION_PENDING {
		t.Errorf("a mod's report must pend the origin copy too, got %s", collectionOf(msgid, origin))
	}
	if reachStatusOf(msgid) != "held" {
		t.Errorf("a mod's report must freeze the reach (origin Pending), got %s", reachStatusOf(msgid))
	}
}

// A member who is NOT a moderator of the reported group still needs the quorum - one
// member report alone must not pend anything anywhere.
func TestReportVerdictMemberBelowQuorumScopedNothing(t *testing.T) {
	db := database.DBConn
	poster := CreateTestUser(t, "rvbmposter", "User")
	origin := CreateTestGroup(t, "rvbmorigin")
	member := CreateTestUser(t, "rvbmmember", "User")
	CreateTestMembership(t, member, origin, "Member")
	msgid := CreateTestMessage(t, poster, origin, "OFFER: rvbelowquorum", 51.5, -0.1)
	addReachRow(msgid, "expanding")

	microvolunteering.RecordReportVerdict(db, member, msgid, origin, "spam")

	if collectionOf(msgid, origin) != utils.COLLECTION_APPROVED {
		t.Errorf("a single member report must not pend the post, got %s", collectionOf(msgid, origin))
	}
	if reachStatusOf(msgid) == "held" {
		t.Errorf("a single member report must not freeze the reach")
	}
}

// Reporting your own post does not count toward the quorum.
func TestReportVerdictOwnPostIgnored(t *testing.T) {
	db := database.DBConn
	poster := CreateTestUser(t, "rvownposter", "User")
	origin := CreateTestGroup(t, "rvownorigin")
	msgid := CreateTestMessage(t, poster, origin, "OFFER: rvownpost", 51.5, -0.1)

	microvolunteering.RecordReportVerdict(db, poster, msgid, origin, "spam")

	if collectionOf(msgid, origin) != utils.COLLECTION_APPROVED {
		t.Errorf("reporting your own post must not pend it, got %s", collectionOf(msgid, origin))
	}
}
