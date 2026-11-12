package main

// The service's database statements, run for real on both engines.
//
// Each test builds a scratch database on MySQL and on PostgreSQL (internal/dbtest), loads the same
// rows into both, calls the service's own code and expects the same answer from both. The scratch
// databases need a server: they are skipped when none is reachable, and fail instead when
// ROUTING_REQUIRE_DB names the engine (see internal/dbtest).
//
// Rows and what they are for:
//
//	groups     101 Alpha   polygon around the test grid, so it contains the test point
//	           102 Beta    polygon next door
//	           103 Gamma   far away, outside the nearby box
//	           104 Hidden  inside the box but not listable
//	           105 Dot     a point, not a polygon
//	           106 Multi   a multipolygon, outside the box
//	           107 Delta   polygon further east than Beta
//	users      1 and 3 active, 2 not seen for 200 days
//	messages   301 offer inside the test grid with views, replies and attachments
//	           302 wanted inside the grid, taken, promised
//	           303 offer outside the grid; 304 too old; 305 a Taken-type row
//	rippling   301 with a live label, a staged label and leaf rows for two partitions

import (
	"bytes"
	"database/sql"
	"encoding/binary"
	"encoding/json"
	"io"
	"math"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-routing-go/dbengine"
	"github.com/freegle/iznik-routing-go/internal/dbtest"
	"github.com/golang-jwt/jwt/v4"
)

const (
	// A partition fingerprint above the signed 64-bit range, which is what a real one is: it is
	// the FNV-1a hash of the leaf assignment.
	fpLive = uint64(0xcbf29ce484222325)
	fpPrev = uint64(0xaf63bd4c8601b7df)
)

func box(minLng, minLat, maxLng, maxLat string) string {
	return "POLYGON((" + minLng + " " + minLat + "," + maxLng + " " + minLat + "," + maxLng + " " + maxLat +
		"," + minLng + " " + maxLat + "," + minLng + " " + minLat + "))"
}

// useDB makes the scratch database the service's shared pool for the test.
func useDB(t *testing.T, s *dbtest.Scratch) {
	t.Helper()
	groupsDBMu.Lock()
	prev := groupsDB
	groupsDB = s.DB
	groupsDBMu.Unlock()
	t.Cleanup(func() {
		groupsDBMu.Lock()
		groupsDB = prev
		groupsDBMu.Unlock()
	})
}

func seedGroups(t *testing.T, s *dbtest.Scratch) {
	t.Helper()
	cols := []string{"id", "nameshort", "publish", "listable", "polyindex"}
	s.Insert(t, "groups", cols, 101, "Alpha", 1, 1, dbtest.Geom(box("-2.65", "51.40", "-2.55", "51.50")))
	s.Insert(t, "groups", cols, 102, "Beta", 1, 1, dbtest.Geom(box("-2.55", "51.40", "-2.45", "51.50")))
	s.Insert(t, "groups", cols, 103, "Gamma", 1, 1, dbtest.Geom(box("0.00", "53.00", "0.10", "53.10")))
	s.Insert(t, "groups", cols, 104, "Hidden", 1, 0, dbtest.Geom(box("-2.60", "51.42", "-2.58", "51.44")))
	s.Insert(t, "groups", cols, 105, "Dot", 1, 1, dbtest.Geom("POINT(-2.60 51.45)"))
	s.Insert(t, "groups", cols, 106, "Multi", 1, 1, dbtest.Geom(
		"MULTIPOLYGON(((-4.50 52.00,-4.40 52.00,-4.40 52.10,-4.50 52.10,-4.50 52.00)),"+
			"((-4.30 52.00,-4.20 52.00,-4.20 52.10,-4.30 52.10,-4.30 52.00)))"))
	s.Insert(t, "groups", cols, 107, "Delta", 1, 1, dbtest.Geom(box("-2.35", "51.40", "-2.25", "51.50")))
}

func seedMembers(t *testing.T, s *dbtest.Scratch, now time.Time) {
	t.Helper()
	s.Insert(t, "locations", []string{"id", "name", "type", "lat", "lng", "areaid"}, 201, "Centreville", "Polygon", 51.4545, -2.5879, nil)
	s.Insert(t, "locations", []string{"id", "name", "type", "lat", "lng", "areaid"}, 202, "BS1 1AA", "Postcode", 51.4540, -2.5880, 201)
	s.Insert(t, "locations", []string{"id", "name", "type", "lat", "lng", "areaid"}, 203, "BS1 1AB", "Postcode", 51.4600, -2.5000, 201)

	ucols := []string{"id", "lastaccess", "lastlocation"}
	s.Insert(t, "users", ucols, 1, now.Add(-time.Hour), 202)
	s.Insert(t, "users", ucols, 2, now.Add(-200*24*time.Hour), nil)
	s.Insert(t, "users", ucols, 3, now.Add(-24*time.Hour), nil)

	mcols := []string{"userid", "groupid", "collection"}
	s.Insert(t, "memberships", mcols, 1, 101, "Approved")
	s.Insert(t, "memberships", mcols, 2, 101, "Approved")
	s.Insert(t, "memberships", mcols, 3, 101, "Approved")
	s.Insert(t, "memberships", mcols, 3, 102, "Approved")
	s.Insert(t, "memberships", mcols, 1, 102, "Pending")

	acols := []string{"userid", "lat", "lng", "position"}
	// User 1's postcode (51.4540, -2.5880) is inside Alpha; the blurred point is outside every group.
	s.Insert(t, "users_approxlocs", acols, 1, 51.60, -2.00, dbtest.Geom("POINT(-2.00 51.60)"))
	// User 3 has no postcode, and the point is inside Beta but not Alpha.
	s.Insert(t, "users_approxlocs", acols, 3, 51.43, -2.50, dbtest.Geom("POINT(-2.50 51.43)"))
	s.Insert(t, "users_approxlocs", acols, 2, 51.45, -2.60, dbtest.Geom("POINT(-2.60 51.45)"))

	s.Insert(t, "sessions", []string{"id", "userid", "series", "token"}, 11, 1, 1, "t")
}

func seedPosts(t *testing.T, s *dbtest.Scratch, now time.Time) {
	t.Helper()
	mcols := []string{"id", "message", "type", "fromuser", "subject", "textbody", "tnpostid", "locationid", "arrival"}
	scols := []string{"msgid", "point", "successful", "promised", "groupid", "msgtype", "arrival"}
	post := func(id int, typ, subject string, lng, lat float64, successful, promised int, msgtype string, age time.Duration) {
		s.Insert(t, "messages", mcols, id, "m", typ, 1, subject, "body", nil, 202, now.Add(-age))
		s.Insert(t, "messages_spatial", scols, id, dbtest.Geom(pointWKT(lng, lat)), successful, promised, 101, msgtype, now.Add(-age))
	}
	post(301, "Offer", "OFFER: Chair (Centreville)", -2.585, 51.455, 0, 0, "Offer", time.Hour)
	post(302, "Wanted", "WANTED: Table (Centreville)", -2.570, 51.440, 1, 1, "Wanted", 2*time.Hour)
	post(303, "Offer", "OFFER: Far away", -2.000, 51.600, 0, 0, "Offer", time.Hour)
	post(304, "Offer", "OFFER: Old", -2.590, 51.450, 0, 0, "Offer", 72*time.Hour)
	post(305, "Offer", "OFFER: Taken row", -2.590, 51.450, 0, 0, "Taken", time.Hour)

	lcols := []string{"msgid", "userid", "type", "count"}
	s.Insert(t, "messages_likes", lcols, 301, 1, "View", 3)
	s.Insert(t, "messages_likes", lcols, 301, 2, "View", 2)
	s.Insert(t, "messages_likes", lcols, 301, 3, "Love", 1)

	ccols := []string{"chatid", "userid", "refmsgid", "type", "reviewrejected", "reviewrequired"}
	s.Insert(t, "chat_messages", ccols, 1, 2, 301, "Interested", 0, 0)
	s.Insert(t, "chat_messages", ccols, 2, 3, 301, "Interested", 0, 0)
	s.Insert(t, "chat_messages", ccols, 3, 3, 301, "Interested", 0, 1)
	s.Insert(t, "chat_messages", ccols, 4, 3, 301, "Default", 0, 0)

	acols := []string{"id", "msgid", "contenttype", "primary", "externaluid"}
	s.Insert(t, "messages_attachments", acols, 501, 301, "image/jpeg", 0, "uid-second")
	s.Insert(t, "messages_attachments", acols, 502, 301, "image/jpeg", 1, "uid-primary")

	s.Insert(t, "messages_outcomes", []string{"msgid", "outcome"}, 302, "Taken")

	gcols := []string{"msgid", "groupid", "arrival", "deleted"}
	s.Insert(t, "messages_groups", gcols, 301, 102, now.Add(-30*time.Minute), 0)
	s.Insert(t, "messages_groups", gcols, 301, 101, now.Add(-time.Hour), 0)
	s.Insert(t, "messages_groups", gcols, 302, 101, now.Add(-2*time.Hour), 1)
}

func pointWKT(lng, lat float64) string {
	return "POINT(" + trimFloat(lng) + " " + trimFloat(lat) + ")"
}

func trimFloat(f float64) string {
	b, _ := json.Marshal(f)
	return string(b)
}

func seedReach(t *testing.T, s *dbtest.Scratch) {
	t.Helper()
	cols := []string{"msgid", "lat", "lng", "outer_bound", "tick", "max_drive_min", "schedule", "rejected_groups",
		"status", "origin_union_secs", "reach_labels", "reach_labels_next", "reach_labels_next_fp"}
	outer := dbtest.Geom(box("-2.60", "51.44", "-2.57", "51.47"))
	s.Insert(t, "rippling_reach", cols, 301, 51.455, -2.585, outer, 3, 30.0, "sched", "[1, 2]", "held", 12.5,
		[]byte{0x01, 0x02}, []byte{0x0a, 0x0b}, dbengine.Current().FingerprintArg(fpLive))
	s.Insert(t, "rippling_reach", cols, 302, 51.440, -2.570, outer, 1, 0.0, nil, nil, "expanding", nil,
		[]byte{0x03}, nil, nil)
	s.Insert(t, "rippling_reach", cols, 303, 51.600, -2.000, outer, 1, 12.3, nil, nil, "expanding", nil,
		[]byte{0x04}, nil, nil)

	lcols := []string{"msgid", "leaf", "fp"}
	fp := dbengine.Current().FingerprintArg
	s.Insert(t, "rippling_reach_leaves", lcols, 301, 7, fp(fpLive))
	s.Insert(t, "rippling_reach_leaves", lcols, 301, 7, fp(fpPrev))
	s.Insert(t, "rippling_reach_leaves", lcols, 302, 7, nil)
	s.Insert(t, "rippling_reach_leaves", lcols, 303, 8, fp(fpLive))
	s.Insert(t, "config", []string{"key", "value"}, reachPartFPConfigKey, "14695981039346656037") // fpLive
}

func getJSON(t *testing.T, app interface {
	Test(*http.Request, ...int) (*http.Response, error)
}, url string) map[string]any {
	t.Helper()
	resp, err := app.Test(httptest.NewRequest(http.MethodGet, url, nil), -1)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		t.Fatalf("%s: status %d: %s", url, resp.StatusCode, raw)
	}
	var out map[string]any
	if err := json.Unmarshal(raw, &out); err != nil {
		var arr []any
		if err2 := json.Unmarshal(raw, &arr); err2 != nil {
			t.Fatalf("%s: not JSON: %s", url, raw)
		}
		return map[string]any{"list": arr}
	}
	return out
}

func near(a, b float64) bool { return math.Abs(a-b) < 1e-4 }

func TestEngine_SessionCheck(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedMembers(t, s, time.Now().UTC())
		useDB(t, s)
		t.Setenv("JWT_SECRET", "test-secret")

		app := newApp(getTestGraph(t), "", true)
		token := func(userID, sessionID string) string {
			tok := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{"id": userID, "sessionid": sessionID})
			str, err := tok.SignedString([]byte("test-secret"))
			if err != nil {
				t.Fatal(err)
			}
			return str
		}
		status := func(userID, sessionID string) int {
			resp, err := app.Test(httptest.NewRequest(http.MethodGet, "/v1/groups/list?jwt="+token(userID, sessionID), nil), -1)
			if err != nil {
				t.Fatal(err)
			}
			return resp.StatusCode
		}
		if got := status("1", "11"); got != 200 {
			t.Errorf("a real session: status %d, want 200", got)
		}
		if got := status("2", "11"); got != 401 {
			t.Errorf("a session that belongs to another user: status %d, want 401", got)
		}
		if got := status("1", "99"); got != 401 {
			t.Errorf("a session that does not exist: status %d, want 401", got)
		}
	})
}

func TestEngine_GroupActives(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedMembers(t, s, time.Now().UTC())
		// Group 101 has three approved members and one of them was last seen 200 days ago.
		if n, err := queryActiveMembers(s.DB, 101); err != nil || n != 2 {
			t.Errorf("group 101: %d, %v; want 2", n, err)
		}
		// Group 102 has one approved member and one pending, who does not count.
		if n, err := queryActiveMembers(s.DB, 102); err != nil || n != 1 {
			t.Errorf("group 102: %d, %v; want 1", n, err)
		}
		if n, err := queryActiveMembers(s.DB, 999); err != nil || n != 0 {
			t.Errorf("no such group: %d, %v; want 0", n, err)
		}
	})
}

func TestEngine_NearbyAndListedGroups(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedGroups(t, s)
		useDB(t, s)
		app := newApp(getTestGraph(t), "", false)

		// The point is inside Alpha. Beta and Delta are neighbours, nearer first. Gamma is outside
		// the box, Hidden is not listable, Dot is a point and Multi is outside the box, so none of
		// those four comes back.
		out := getJSON(t, app, "/v1/groups/nearby?lat=51.4545&lng=-2.5879")
		feats, _ := out["features"].([]any)
		type got struct {
			id       int
			name     string
			contains bool
		}
		var seen []got
		for _, f := range feats {
			p := f.(map[string]any)["properties"].(map[string]any)
			seen = append(seen, got{int(p["id"].(float64)), p["nameshort"].(string), p["contains"].(bool)})
		}
		want := []got{{101, "Alpha", true}, {102, "Beta", false}, {107, "Delta", false}}
		if len(seen) != len(want) {
			t.Fatalf("nearby groups %v, want %v", seen, want)
		}
		for i := range want {
			if seen[i] != want[i] {
				t.Errorf("nearby[%d] = %v, want %v", i, seen[i], want[i])
			}
		}
		// The first feature's polygon is Alpha's ring.
		geom := feats[0].(map[string]any)["geometry"].(map[string]any)
		ring := geom["coordinates"].([]any)[0].([]any)
		if len(ring) != 5 {
			t.Errorf("Alpha's ring has %d points, want 5", len(ring))
		}

		list := getJSON(t, app, "/v1/groups/list")["list"].([]any)
		var names []string
		for _, it := range list {
			m := it.(map[string]any)
			names = append(names, m["name"].(string))
			if m["name"] == "Alpha" && (!near(m["lat"].(float64), 51.45) || !near(m["lng"].(float64), -2.60)) {
				t.Errorf("Alpha's centroid is (%v, %v), want (51.45, -2.60)", m["lat"], m["lng"])
			}
		}
		if strings.Join(names, ",") != "Alpha,Beta,Delta,Dot,Gamma,Multi" {
			t.Errorf("groups list %v", names)
		}
	})
}

func TestEngine_GroupBoundaryAndArea(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedGroups(t, s)
		useDB(t, s)
		// The area lookup is cached for ten minutes; start empty so this engine's SQL is what answers.
		groupAreaMu.Lock()
		groupAreaCache = map[int64]groupAreaEntry{}
		groupAreaMu.Unlock()

		seeds, ok := groupSeedNodes(getTestGraph(t), 101)
		if !ok || len(seeds) == 0 {
			t.Errorf("group 101 gave no seed nodes: %v, %v", seeds, ok)
		}
		if _, ok := groupSeedNodes(getTestGraph(t), 999); ok {
			t.Error("a group that does not exist must give no seeds")
		}

		// The area of a polygon group is its ring; a multipolygon is every part; a point is none.
		if rings := groupAreaRings(101); len(rings) != 1 || len(rings[0]) != 5 {
			t.Errorf("Alpha area: %v", rings)
		}
		if rings := groupAreaRings(106); len(rings) != 2 {
			t.Errorf("Multi area has %d rings, want 2", len(rings))
		}
		if rings := groupAreaRings(105); rings != nil {
			t.Errorf("Dot has an area: %v", rings)
		}
		if !groupAreaContains(101, 51.4545, -2.5879) || groupAreaContains(102, 51.4545, -2.5879) {
			t.Error("groupAreaContains is wrong for Alpha and Beta")
		}
	})
}

func TestEngine_ResolvePlace(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedMembers(t, s, time.Now().UTC())
		// BS1 1AA is a few metres away, BS1 1AB is 5 km east and outside the search box.
		pc, place := resolvePlace(s.DB, 51.4545, -2.5879)
		if pc != "BS1 1AA" || place != "Centreville" {
			t.Errorf("resolvePlace = %q, %q; want BS1 1AA, Centreville", pc, place)
		}
		pc, place = resolvePlace(s.DB, 52.5, -1.0)
		if pc != "" || place != "" {
			t.Errorf("nowhere near a postcode: %q, %q", pc, place)
		}
	})
}

func TestEngine_ActiveMembersInBox(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedGroups(t, s)
		seedMembers(t, s, time.Now().UTC())

		// The box holds the approximate points of all three users.
		members, err := queryActiveMembersInBox(s.DB, 51.30, 51.65, -2.70, -1.90)
		if err != nil {
			t.Fatal(err)
		}
		type row struct {
			gid      int64
			lat, lng float64
		}
		var rows []row
		for _, m := range members {
			rows = append(rows, row{m.groupID, m.lat, m.lng})
		}
		sort.Slice(rows, func(i, j int) bool { return rows[i].gid < rows[j].gid })
		// User 1 is counted at the postcode (inside Alpha), not at the blurred point. User 3 is counted
		// in Beta, where the approximate point falls; their Alpha membership puts them outside Alpha so
		// they do not count there. User 2 was last seen 200 days ago.
		if len(rows) != 2 || rows[0].gid != 101 || rows[1].gid != 102 ||
			!near(rows[0].lat, 51.4540) || !near(rows[0].lng, -2.5880) ||
			!near(rows[1].lat, 51.43) || !near(rows[1].lng, -2.50) {
			t.Errorf("members in box: %+v", rows)
		}

		// The box test is on the approximate point, so user 1 drops out of a box that does not hold it.
		members, err = queryActiveMembersInBox(s.DB, 51.30, 51.55, -2.70, -2.30)
		if err != nil {
			t.Fatal(err)
		}
		if len(members) != 1 || members[0].groupID != 102 {
			t.Errorf("small box: %+v", members)
		}
	})
}

func TestEngine_PostsForMemberAndDigest(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		now := time.Now().UTC().Truncate(time.Second)
		seedGroups(t, s)
		seedMembers(t, s, now)
		seedPosts(t, s, now)
		useDB(t, s)
		app := newApp(getTestGraph(t), "", false)

		out := getJSON(t, app, "/v1/posts-for-member?lat=51.4545&lng=-2.5879&max_minutes=60")
		posts, _ := out["posts"].([]any)
		var ids []int
		for _, p := range posts {
			m := p.(map[string]any)
			ids = append(ids, int(m["msgid"].(float64)))
			switch int(m["msgid"].(float64)) {
			case 301:
				if m["msgtype"] != "Offer" || m["successful"] != false || m["promised"] != false ||
					m["groupid"].(float64) != 101 || !near(m["lng"].(float64), -2.585) || !near(m["lat"].(float64), 51.455) {
					t.Errorf("post 301: %v", m)
				}
			case 302:
				if m["msgtype"] != "Wanted" || m["successful"] != true || m["promised"] != true {
					t.Errorf("post 302: %v", m)
				}
			}
		}
		// Newest first. 303 is outside the isochrone, 304 is older than the day, 305 is a Taken row.
		if len(ids) != 2 || ids[0] != 301 || ids[1] != 302 {
			t.Fatalf("posts for member: %v", ids)
		}

		d := getJSON(t, app, "/v1/digest-simulator?lat=51.4545&lng=-2.5879&max_minutes=60")
		homes, _ := d["home_groups"].([]any)
		if len(homes) != 1 || homes[0].(map[string]any)["id"].(float64) != 101 || homes[0].(map[string]any)["name"] != "Alpha" {
			t.Errorf("home groups: %v", d["home_groups"])
		}
		if poly, _ := homes[0].(map[string]any)["polygon"].(map[string]any); poly["type"] != "Polygon" {
			t.Errorf("home group polygon is not GeoJSON: %v", homes[0])
		}
		picks, _ := d["top_picks"].([]any)
		if len(picks) != 1 {
			t.Fatalf("top picks: %v", d["top_picks"])
		}
		p := picks[0].(map[string]any)
		if p["msgid"].(float64) != 301 || p["views"].(float64) != 5 || p["replies"].(float64) != 2 ||
			p["groupname"] != "Alpha" || p["thumb_attachment_id"].(float64) != 502 ||
			p["thumb_externaluid"] != "uid-primary" || p["has_outcome"] != false || p["home_group"] != true ||
			p["msgtype"] != "Offer" || p["fromuser"].(float64) != 1 {
			t.Errorf("top pick: %v", p)
		}
		came, _ := d["came_and_went"].([]any)
		if len(came) != 1 || came[0].(map[string]any)["msgid"].(float64) != 302 ||
			came[0].(map[string]any)["has_success"] != true {
			t.Errorf("came and went: %v", d["came_and_went"])
		}
	})
}

func TestEngine_OriginGroupAndPartitionFingerprint(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		now := time.Now().UTC()
		seedMembers(t, s, now)
		seedPosts(t, s, now)
		seedReach(t, s)
		useDB(t, s)

		// 301 arrived on 101 first, then on 102. The only row for 302 is deleted.
		if got := originGroupForMsgidFn(301); got != 101 {
			t.Errorf("origin group of 301 = %d, want 101", got)
		}
		if got := originGroupForMsgidFn(302); got != 0 {
			t.Errorf("origin group of 302 = %d, want 0 (deleted)", got)
		}

		// reachExpectedPartFP opens its own connection from the environment.
		s.SetEnv(t)
		fp, ok := reachExpectedPartFP()
		if !ok || fp != fpLive {
			t.Errorf("reachExpectedPartFP = %d, %v; want %d", fp, ok, fpLive)
		}
	})
}

func withLiveEngines(t *testing.T, live, prev uint64) {
	t.Helper()
	setReachLive(&ReachEngine{partFP: live})
	if prev != 0 {
		reachPrevPtr.Store(&ReachEngine{partFP: prev})
	}
	t.Cleanup(func() {
		setReachLive(nil)
		reachPrevPtr.Store(nil)
	})
}

func TestEngine_ReachEvalLoaders(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		now := time.Now().UTC()
		seedMembers(t, s, now)
		seedPosts(t, s, now)
		seedReach(t, s)
		useDB(t, s)

		// No engine loaded: the live label is used, the staged one ignored.
		rows, err := evalRowLoader([]uint64{301, 302, 303, 999})
		if err != nil {
			t.Fatal(err)
		}
		byID := map[uint64]evalRow{}
		for _, r := range rows {
			byID[r.msgid] = r
		}
		if len(byID) != 3 {
			t.Fatalf("rows for 301, 302, 303 and a missing post: %d", len(rows))
		}
		if byID[303].maxMin != 12.3 {
			t.Errorf("drive budget of 303: %v", byID[303].maxMin)
		}
		r := byID[301]
		if string(r.blob) != "\x01\x02" || r.tick != 3 || r.maxMin != 30 || r.schedule != "sched" ||
			!r.held || r.originGid != 101 || !r.unionKnown || r.unionSecs != 12.5 {
			t.Errorf("row 301 with no engine: %+v", r)
		}
		var rej []int
		if err := json.Unmarshal([]byte(r.rejected), &rej); err != nil || len(rej) != 2 || rej[0] != 1 || rej[1] != 2 {
			t.Errorf("rejected groups %q: %v", r.rejected, err)
		}
		if r2 := byID[302]; string(r2.blob) != "\x03" || r2.unionKnown || r2.held || r2.originGid != 0 || r2.schedule != "" {
			t.Errorf("row 302: %+v", r2)
		}

		// An engine whose partition matches the staged stamp reads the staged label instead.
		withLiveEngines(t, fpLive, 0)
		rows, err = evalRowLoader([]uint64{301})
		if err != nil || len(rows) != 1 || string(rows[0].blob) != "\x0a\x0b" {
			t.Errorf("staged label with the matching engine: %+v, %v", rows, err)
		}
		// One whose partition does not match keeps the live label.
		withLiveEngines(t, fpPrev, 0)
		rows, err = evalRowLoader([]uint64{301})
		if err != nil || len(rows) != 1 || string(rows[0].blob) != "\x01\x02" {
			t.Errorf("live label with another engine: %+v, %v", rows, err)
		}

		// Leaf candidates: with one build, that build's rows and rows with no build. With two, both.
		withLiveEngines(t, fpLive, 0)
		ids, err := leafRowLoader(7)
		sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
		if err != nil || len(ids) != 2 || ids[0] != 301 || ids[1] != 302 {
			t.Errorf("leaf 7 with one build: %v, %v", ids, err)
		}
		withLiveEngines(t, fpLive, fpPrev)
		ids, err = leafRowLoader(7)
		sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
		if err != nil || len(ids) != 3 || ids[0] != 301 || ids[1] != 301 || ids[2] != 302 {
			t.Errorf("leaf 7 with two builds: %v, %v", ids, err)
		}
		setReachLive(nil)
		reachPrevPtr.Store(nil)
		ids, err = leafRowLoader(7)
		if err != nil || len(ids) != 3 {
			t.Errorf("leaf 7 with no engine: %v, %v", ids, err)
		}
	})
}

func TestEngine_LabelExportRows(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedReach(t, s)
		useDB(t, s)
		// 302 has no drive budget, so it is not exported.
		rows := loadLabelExportRows(0)
		if len(rows) != 2 || rows[0].msgid != 301 || rows[1].msgid != 303 ||
			!near(rows[0].lat, 51.455) || !near(rows[0].lng, -2.585) || rows[0].driveMin != 30 {
			t.Errorf("export rows: %+v", rows)
		}
		// The column is a single-precision float on both engines. 12.3 is not exact in float32, so
		// this fails if one engine widens the stored value to 12.300000190734863.
		if len(rows) == 2 && rows[1].driveMin != 12.3 {
			t.Errorf("drive budget of 303: %v", rows[1].driveMin)
		}
		if rows := loadLabelExportRows(1); len(rows) != 1 || rows[0].msgid != 301 {
			t.Errorf("limited export: %+v", rows)
		}
	})
}

func TestEngine_LeafKeyCheck(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		count := func(table string) int {
			var n int
			if err := s.DB.QueryRow(sqlLabelsUniqueKey(), table).Scan(&n); err != nil {
				t.Fatalf("%s: %v", table, err)
			}
			return n
		}
		// The live table has UNIQUE (msgid, leaf, fp).
		if n := count("rippling_reach_leaves"); n != 1 {
			t.Errorf("live table: %d keys, want 1", n)
		}
		// A table with the key in another column order, one with a longer key and one with none do not
		// qualify; a table with the right key under another name does.
		s.Exec(t, "CREATE TABLE leaves_wrong_order (msgid bigint, leaf int, fp bigint, UNIQUE (leaf, msgid, fp))")
		s.Exec(t, "CREATE TABLE leaves_no_key (msgid bigint, leaf int, fp bigint)")
		s.Exec(t, "CREATE TABLE leaves_primary_only (msgid bigint, leaf int, fp bigint, PRIMARY KEY (msgid, leaf, fp))")
		s.Exec(t, "CREATE TABLE leaves_good (msgid bigint, leaf int, fp bigint, UNIQUE (msgid, leaf, fp))")
		for table, want := range map[string]int{
			"leaves_wrong_order": 0, "leaves_no_key": 0, "leaves_primary_only": 0, "leaves_good": 1, "no_such_table": 0,
		} {
			if got := count(table); got != want {
				t.Errorf("%s: %d keys, want %d", table, got, want)
			}
		}
	})
}

func TestEngine_InsertLeavesIgnoresDuplicates(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		fp := fpArg(fpLive)
		ins := func(msgid, leaf int) int64 {
			res, err := s.DB.Exec(sqlInsertLeaves("rippling_reach_leaves", "(?, ?, ?)"), msgid, leaf, fp)
			if err != nil {
				t.Fatal(err)
			}
			n, _ := res.RowsAffected()
			return n
		}
		if n := ins(1, 5); n != 1 {
			t.Errorf("new row: %d inserted", n)
		}
		if n := ins(1, 5); n != 0 {
			t.Errorf("the same row again: %d inserted, want 0", n)
		}
		two := sqlInsertLeaves("rippling_reach_leaves", "(?, ?, ?),(?, ?, ?)")
		res, err := s.DB.Exec(two, 1, 5, fp, 1, 6, fp)
		if err != nil {
			t.Fatal(err)
		}
		if n, _ := res.RowsAffected(); n != 1 {
			t.Errorf("one new and one repeated: %d inserted, want 1", n)
		}
		var n int
		if err := s.DB.QueryRow(sqlCountLeaves("rippling_reach_leaves"), fp).Scan(&n, new(int)); err != nil || n != 2 {
			t.Errorf("count: %d, %v; want 2", n, err)
		}
	})
}

func TestEngine_PartitionFingerprintRoundTrip(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		// A fingerprint is written and compared through fpArg, so the same value finds the same row on
		// both engines, including one that does not fit a signed 64-bit column on its own.
		for _, fp := range []uint64{1, math.MaxInt64, math.MaxInt64 + 1, fpLive, math.MaxUint64} {
			s.Exec(t, "DELETE FROM rippling_reach_leaves")
			s.Exec(t, "INSERT INTO rippling_reach_leaves (msgid, leaf, fp) VALUES (?, ?, ?)", 1, 1, fpArg(fp))
			var n int
			if err := s.DB.QueryRow(sqlCountLeaves("rippling_reach_leaves"), fpArg(fp)).Scan(&n, new(int)); err != nil || n != 1 {
				t.Errorf("fingerprint %d: found %d rows, %v", fp, n, err)
			}
			if err := s.DB.QueryRow(sqlCountLeaves("rippling_reach_leaves"), fpArg(fp-1)).Scan(&n, new(int)); err != nil || n != 0 {
				t.Errorf("fingerprint %d: its neighbour matched %d rows, %v", fp, n, err)
			}
		}
	})
}

// writeLabelsExport writes a file in the format labels-export produces.
func writeLabelsExport(t *testing.T, path string, fp uint64, posts []exportedPost) {
	t.Helper()
	var buf bytes.Buffer
	put := func(v any) {
		if err := binary.Write(&buf, binary.LittleEndian, v); err != nil {
			t.Fatal(err)
		}
	}
	buf.WriteString(labelsExportMagic)
	put(labelsExportVersion)
	put(fp)
	put(uint64(len(posts)))
	for _, p := range posts {
		put(p.msgid)
		put(float32(1.5))
		put(uint32(len(p.blob)))
		buf.Write(p.blob)
		put(uint32(len(p.leaves)))
		for _, l := range p.leaves {
			put(l)
		}
	}
	if err := os.WriteFile(path, buf.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
}

type exportedPost struct {
	msgid  uint64
	blob   []byte
	leaves []int32
}

func TestEngine_LabelsApply(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		seedReach(t, s)
		s.Exec(t, "DELETE FROM rippling_reach_leaves")
		s.SetEnv(t)

		const fpNew = uint64(0xdeadbeefcafef00d)
		file := filepath.Join(t.TempDir(), "labels.bin")
		// 999 is a post that was purged since the export.
		writeLabelsExport(t, file, fpNew, []exportedPost{
			{301, []byte{0xaa}, []int32{7, 8}},
			{302, []byte{0xbb}, []int32{9}},
			{999, []byte{0xcc}, []int32{1}},
		})
		args := []string{"--file", file, "--expect-fp", strconv.FormatUint(fpNew, 10),
			"--leaves-table", "rippling_reach_leaves", "--sleep-ms", "0"}

		state := func() (staged, leafRows, leafPosts int, label301, live301 []byte) {
			if err := s.DB.QueryRow(sqlCountStaged(), fpArg(fpNew)).Scan(&staged); err != nil {
				t.Fatal(err)
			}
			if err := s.DB.QueryRow(sqlCountLeaves("rippling_reach_leaves"), fpArg(fpNew)).Scan(&leafRows, &leafPosts); err != nil {
				t.Fatal(err)
			}
			if err := s.DB.QueryRow("SELECT reach_labels_next, reach_labels FROM rippling_reach WHERE msgid = 301").Scan(&label301, &live301); err != nil {
				t.Fatal(err)
			}
			return
		}

		// A dry run reads the file and writes nothing.
		reachLabelsApplyCmd(append([]string{"--dry-run"}, args...))
		if staged, leafRows, _, label, _ := state(); staged != 0 || leafRows != 0 || string(label) != "\x0a\x0b" {
			t.Fatalf("dry run wrote: %d staged, %d leaf rows, label %x", staged, leafRows, label)
		}

		reachLabelsApplyCmd(args)
		staged, leafRows, leafPosts, label, live := state()
		if staged != 2 || leafRows != 3 || leafPosts != 2 {
			t.Errorf("after apply: %d staged, %d leaf rows for %d posts; want 2, 3, 2", staged, leafRows, leafPosts)
		}
		if string(label) != "\xaa" {
			t.Errorf("staged label %x, want aa", label)
		}
		if string(live) != "\x01\x02" {
			t.Errorf("the live label was touched: %x", live)
		}

		// Run again: nothing new is written and nothing is duplicated.
		reachLabelsApplyCmd(args)
		if staged, leafRows, _, _, _ := state(); staged != 2 || leafRows != 3 {
			t.Errorf("after a second apply: %d staged, %d leaf rows; want 2, 3", staged, leafRows)
		}
		// And again, passing the posts that are already staged without touching them.
		reachLabelsApplyCmd(append([]string{"--skip-staged"}, args...))
		if staged, leafRows, _, _, _ := state(); staged != 2 || leafRows != 3 {
			t.Errorf("after --skip-staged: %d staged, %d leaf rows; want 2, 3", staged, leafRows)
		}
	})
}

// The PostgreSQL schema stores timestamptz, so a connection must read and write in UTC whatever the
// database or server is set to. The DSN asks for it.
func TestEngine_PostgresSessionIsUTC(t *testing.T) {
	s := dbtest.Open(t, dbengine.Postgres)
	s.Exec(t, `ALTER DATABASE `+dbtest.Quote(dbengine.Postgres, s.Settings.Name)+` SET timezone = 'Asia/Tokyo'`)
	t.Setenv(dbengine.EnvEngine, "postgres")
	s.SetEnv(t)
	db, err := openGroupsDB(groupsDSN())
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	var zone string
	if err := db.QueryRow("SHOW timezone").Scan(&zone); err != nil || zone != "UTC" {
		t.Errorf("session time zone %q, %v; want UTC", zone, err)
	}
	// A pool opened without the setting sees the database's own zone, so the check above is a real one.
	plain, err := sql.Open("pgx", strings.Split(groupsDSN(), "&timezone=")[0])
	if err != nil {
		t.Fatal(err)
	}
	defer plain.Close()
	if err := plain.QueryRow("SHOW timezone").Scan(&zone); err != nil || zone != "Asia/Tokyo" {
		t.Errorf("session time zone without the setting %q, %v; want Asia/Tokyo", zone, err)
	}
}
