package main

import (
	"database/sql"
	"testing"
	"time"

	"github.com/freegle/iznik-routing-go/internal/dbtest"
)

// The three statements run on both engines against the same rows. A server is needed: see
// internal/dbtest for when this skips and when it fails.
func TestExtractStatementsOnBothEngines(t *testing.T) {
	dbtest.Run(t, func(t *testing.T, s *dbtest.Scratch) {
		arrival := time.Now().UTC().AddDate(0, 0, -10).Truncate(time.Second)
		outcomeAt := arrival.Add(2 * time.Hour)
		replyAt := arrival.Add(time.Hour)

		s.Insert(t, "locations", []string{"id", "name", "type", "lat", "lng"}, 202, "BS1 1AA", "Postcode", 51.4540, -2.5880)
		// The classification is stored in lower case: the postcode match must not care.
		s.Insert(t, "transport_postcode_classification", []string{"postcode", "lat", "lng"}, "bs11aa", 51.454, -2.588)
		s.Exec(t, "UPDATE "+dbtest.Quote(s.Engine, "transport_postcode_classification")+" SET ru_category = ? WHERE postcode = ?", "A1", "bs11aa")

		mcols := []string{"id", "message", "type", "fromuser", "locationid", "arrival"}
		scols := []string{"msgid", "point", "msgtype", "groupid", "arrival"}
		// 1 has a reply and an outcome; 2 has no reply; 3 is older than the cutoff.
		s.Insert(t, "messages", mcols, 1, "m", "Offer", 7, 202, arrival)
		s.Insert(t, "messages_spatial", scols, 1, dbtest.Geom("POINT(-2.585 51.455)"), "Offer", 101, arrival)
		s.Insert(t, "messages", mcols, 2, "m", "Wanted", nil, nil, arrival)
		s.Insert(t, "messages_spatial", scols, 2, dbtest.Geom("POINT(-2.5 51.4)"), "Wanted", 101, arrival)
		s.Insert(t, "messages", mcols, 3, "m", "Offer", 7, 202, arrival.AddDate(0, 0, -60))
		s.Insert(t, "messages_spatial", scols, 3, dbtest.Geom("POINT(-2.5 51.4)"), "Offer", 101, arrival)
		s.Insert(t, "messages_outcomes", []string{"msgid", "outcome", "timestamp"}, 1, "Taken", outcomeAt)

		ccols := []string{"chatid", "userid", "refmsgid", "type", "date", "reviewrejected", "reviewrequired"}
		s.Insert(t, "chat_messages", ccols, 1, 5, 1, "Interested", replyAt, 0, 0)
		s.Insert(t, "chat_messages", ccols, 2, 6, 1, "Interested", replyAt, 1, 0) // rejected on review
		s.Insert(t, "chat_messages", ccols, 3, 8, 3, "Interested", replyAt, 0, 0) // post 3 is too old
		s.Insert(t, "users_approxlocs", []string{"userid", "lat", "lng", "position"}, 5, 51.45, -2.59, dbtest.Geom("POINT(-2.59 51.45)"))
		s.Insert(t, "messages_groups", []string{"msgid", "groupid"}, 1, 101)
		s.Insert(t, "memberships", []string{"userid", "groupid", "collection", "emailfrequency"}, 5, 101, "Approved", 1)

		// Step 1: posts since the cutoff with at least one reply.
		cutoff := time.Now().UTC().AddDate(0, 0, -30).Format("2006-01-02 00:00:00")
		rows, err := s.DB.Query(sqlCandidates(), cutoff)
		if err != nil {
			t.Fatal(err)
		}
		var ids []int
		for rows.Next() {
			var id int
			if err := rows.Scan(&id); err != nil {
				t.Fatal(err)
			}
			ids = append(ids, id)
		}
		rows.Close()
		if len(ids) != 1 || ids[0] != 1 {
			t.Fatalf("candidates %v, want [1]", ids)
		}

		// Step 2: the post, with its outcome and postcode class.
		r, err := s.DB.Query(sqlPostMetadata("?"), 1)
		if err != nil {
			t.Fatal(err)
		}
		var p Post
		var outcome, outAt, ru sql.NullString
		if !r.Next() {
			t.Fatal("no metadata row")
		}
		if err := r.Scan(&p.PostID, &p.Lat, &p.Lng, &p.Arrival, &p.Type, &p.FromUser, &outcome, &outAt, &ru); err != nil {
			t.Fatal(err)
		}
		r.Close()
		const layout = "2006-01-02 15:04:05"
		if p.PostID != 1 || p.Type != "Offer" || p.FromUser != 7 ||
			p.Lat < 51.4549 || p.Lat > 51.4551 || p.Lng > -2.5849 || p.Lng < -2.5851 ||
			p.Arrival != arrival.Format(layout) ||
			outcome.String != "Taken" || outAt.String != outcomeAt.Format(layout) || ru.String != "A1" {
			t.Errorf("metadata: %+v outcome %v at %v class %v", p, outcome, outAt, ru)
		}

		// Post 2 has no user, no outcome and no postcode: the nulls and the default.
		r, err = s.DB.Query(sqlPostMetadata("?"), 2)
		if err != nil {
			t.Fatal(err)
		}
		var p2 Post
		var o2, oa2, ru2 sql.NullString
		if !r.Next() {
			t.Fatal("no metadata row for post 2")
		}
		if err := r.Scan(&p2.PostID, &p2.Lat, &p2.Lng, &p2.Arrival, &p2.Type, &p2.FromUser, &o2, &oa2, &ru2); err != nil {
			t.Fatal(err)
		}
		r.Close()
		if p2.FromUser != 0 || o2.Valid || oa2.Valid || ru2.Valid {
			t.Errorf("post 2: user %d, outcome %v, at %v, class %v", p2.FromUser, o2, oa2, ru2)
		}

		// Step 3: the repliers, with their location and the email frequency on the post's group.
		r, err = s.DB.Query(sqlRepliers("?, ?"), 1, 3)
		if err != nil {
			t.Fatal(err)
		}
		type reply struct {
			post, user int
			at         string
			lat, lng   sql.NullFloat64
			freq       sql.NullInt64
		}
		var got []reply
		for r.Next() {
			var x reply
			if err := r.Scan(&x.post, &x.user, &x.at, &x.lat, &x.lng, &x.freq); err != nil {
				t.Fatal(err)
			}
			got = append(got, x)
		}
		r.Close()
		// Post 3's replier has no location and no membership, and still comes back with nulls.
		if len(got) != 2 {
			t.Fatalf("repliers: %+v", got)
		}
		for _, x := range got {
			switch x.post {
			case 1:
				if x.user != 5 || x.at != replyAt.Format(layout) || !x.lat.Valid || x.lat.Float64 < 51.4499 || x.lat.Float64 > 51.4501 ||
					!x.freq.Valid || x.freq.Int64 != 1 {
					t.Errorf("reply on post 1: %+v", x)
				}
			case 3:
				if x.user != 8 || x.lat.Valid || x.freq.Valid {
					t.Errorf("reply on post 3: %+v", x)
				}
			default:
				t.Errorf("reply on post %d", x.post)
			}
		}
	})
}
