// rippleextract — Layer-1 simulator data extractor (Go version).
//
// Pulls historical (post, replier) records from MySQL directly, no iznik
// framework dependencies, so it can run anywhere with DB access (e.g. inside
// the iznik-routing-go container which already has MYSQL_HOST/USER/PASSWORD
// in env).
//
// Output JSON matches what cmd/ripplesim expects.
//
// Usage:
//
//	rippleextract --months=12 --limit=10000 --output=/tmp/ripple_sim_data.json
//
// Env required: MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DBNAME.
package main

import (
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"os"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

type Replier struct {
	UserID    int     `json:"user_id"`
	Lat       float64 `json:"lat"`
	Lng       float64 `json:"lng"`
	ReplyTime string  `json:"reply_time"`
	// Email-frequency setting on the membership for the post's group.
	// -1 = immediate, 0 = off (?), 1..24 = digest hours.  Most users are 24
	// (daily digest) so apparent "lead time" includes their digest wait;
	// "true" attention cost is best measured on immediate-only repliers.
	EmailFreq *int `json:"email_freq,omitempty"`
}

type Post struct {
	PostID    int     `json:"post_id"`
	Lat       float64 `json:"lat"`
	Lng       float64 `json:"lng"`
	Arrival   string  `json:"arrival"`
	Type      string  `json:"type"`
	FromUser  int     `json:"fromuser"`
	Outcome   *string `json:"outcome"`
	OutcomeAt *string `json:"outcome_at"`
	// RU category (e.g. "A1" Urban Major Conurbation, "F1" Rural Hamlets)
	// — derived from the post's postcode via transport_postcode_classification.
	// Empty if the postcode isn't classified (rare, mostly outside England).
	RUCategory *string   `json:"ru_category"`
	Repliers   []Replier `json:"repliers"`
}

var (
	months = flag.Int("months", 12, "how far back to pull post arrivals")
	limit  = flag.Int("limit", 10000, "max posts to keep (0 = no cap)")
	output = flag.String("output", "/tmp/ripple_sim_data.json", "output JSON path")
)

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func main() {
	flag.Parse()
	log.SetFlags(log.Ltime)

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=false&readTimeout=300s",
		env("MYSQL_USER", "root"),
		env("MYSQL_PASSWORD", ""),
		env("MYSQL_HOST", "localhost"),
		env("MYSQL_PORT", "3306"),
		env("MYSQL_DBNAME", "iznik"),
	)
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatalf("open: %v", err)
	}
	defer db.Close()
	db.SetMaxOpenConns(4)
	if err := db.Ping(); err != nil {
		log.Fatalf("ping: %v", err)
	}

	cutoff := time.Now().AddDate(0, -*months, 0).Format("2006-01-02 00:00:00")
	log.Printf("extracting posts since %s (limit=%d) → %s", cutoff, *limit, *output)

	// ----- 1. enumerate candidate post IDs -----
	log.Printf("step 1: enumerating posts with at least one reply...")
	q1 := `SELECT m.id
	         FROM messages m
	         INNER JOIN messages_spatial ms ON ms.msgid = m.id
	         WHERE m.arrival >= ?
	           AND m.type IN ('Offer', 'Wanted')
	           AND ms.point IS NOT NULL
	           AND EXISTS (
	               SELECT 1 FROM chat_messages cm
	                WHERE cm.refmsgid = m.id
	                  AND cm.type = 'Interested'
	                  AND cm.reviewrejected = 0
	                  AND cm.reviewrequired = 0
	           )`
	rows, err := db.Query(q1, cutoff)
	if err != nil {
		log.Fatalf("step1 query: %v", err)
	}
	var ids []int
	for rows.Next() {
		var id int
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			log.Fatalf("step1 scan: %v", err)
		}
		ids = append(ids, id)
	}
	rows.Close()
	log.Printf("  ✓ %d candidates", len(ids))
	if len(ids) == 0 {
		log.Printf("nothing to extract")
		return
	}

	// even-stride sample
	if *limit > 0 && len(ids) > *limit {
		stride := (len(ids) + *limit - 1) / *limit
		sampled := make([]int, 0, *limit)
		for i := 0; i < len(ids); i += stride {
			sampled = append(sampled, ids[i])
			if len(sampled) >= *limit {
				break
			}
		}
		log.Printf("  ✓ sampled %d via stride %d", len(sampled), stride)
		ids = sampled
	}

	// ----- 2. fetch metadata in batches -----
	log.Printf("step 2: fetching post metadata...")
	posts := make(map[int]*Post, len(ids))
	const batchSize = 500
	for start := 0; start < len(ids); start += batchSize {
		end := start + batchSize
		if end > len(ids) {
			end = len(ids)
		}
		batch := ids[start:end]
		ph := make([]string, len(batch))
		args := make([]any, len(batch))
		for i, id := range batch {
			ph[i] = "?"
			args[i] = id
		}
		q2 := `SELECT m.id,
		              ST_Y(ms.point) AS lat,
		              ST_X(ms.point) AS lng,
		              DATE_FORMAT(m.arrival, '%Y-%m-%d %H:%i:%s') AS arrival,
		              m.type,
		              IFNULL(m.fromuser, 0),
		              mo.outcome,
		              DATE_FORMAT(mo.timestamp, '%Y-%m-%d %H:%i:%s') AS outcome_at,
		              tpc.ru_category
		         FROM messages m
		         INNER JOIN messages_spatial ms ON ms.msgid = m.id
		         LEFT JOIN messages_outcomes mo
		           ON mo.msgid = m.id
		          AND mo.outcome IN ('Taken','Promised','Withdrawn','Repost')
		         LEFT JOIN locations l ON l.id = m.locationid AND l.type = 'Postcode'
		         LEFT JOIN transport_postcode_classification tpc
		           ON tpc.postcode = REPLACE(l.name, ' ', '')
		         WHERE m.id IN (` + strings.Join(ph, ",") + `)`
		r, err := db.Query(q2, args...)
		if err != nil {
			log.Fatalf("step2 query: %v", err)
		}
		for r.Next() {
			var p Post
			var outcome, outcomeAt, ruCat sql.NullString
			if err := r.Scan(&p.PostID, &p.Lat, &p.Lng, &p.Arrival, &p.Type, &p.FromUser, &outcome, &outcomeAt, &ruCat); err != nil {
				log.Fatalf("step2 scan: %v", err)
			}
			if outcome.Valid {
				p.Outcome = &outcome.String
			}
			if outcomeAt.Valid {
				p.OutcomeAt = &outcomeAt.String
			}
			if ruCat.Valid {
				p.RUCategory = &ruCat.String
			}
			posts[p.PostID] = &p
		}
		r.Close()
	}
	log.Printf("  ✓ %d posts loaded", len(posts))

	// ----- 3. fetch repliers in batches -----
	log.Printf("step 3: fetching repliers...")
	var replierCount, noLocation int
	for start := 0; start < len(ids); start += batchSize {
		end := start + batchSize
		if end > len(ids) {
			end = len(ids)
		}
		batch := ids[start:end]
		ph := make([]string, len(batch))
		args := make([]any, len(batch))
		for i, id := range batch {
			ph[i] = "?"
			args[i] = id
		}
		// Join replier→approxloc for location AND replier→memberships
		// (via the post's group) for the email-frequency setting.  The
		// post's group is messages_groups.groupid.
		q3 := `SELECT cm.refmsgid,
		              cm.userid,
		              DATE_FORMAT(cm.date, '%Y-%m-%d %H:%i:%s') AS reply_time,
		              ual.lat,
		              ual.lng,
		              m.emailfrequency
		         FROM chat_messages cm
		         LEFT JOIN users_approxlocs ual ON ual.userid = cm.userid
		         LEFT JOIN messages_groups mg ON mg.msgid = cm.refmsgid
		         LEFT JOIN memberships m
		           ON m.userid = cm.userid AND m.groupid = mg.groupid
		         WHERE cm.refmsgid IN (` + strings.Join(ph, ",") + `)
		           AND cm.type = 'Interested'
		           AND cm.reviewrejected = 0
		           AND cm.reviewrequired = 0`
		r, err := db.Query(q3, args...)
		if err != nil {
			log.Fatalf("step3 query: %v", err)
		}
		for r.Next() {
			var pid, uid int
			var rt string
			var lat, lng sql.NullFloat64
			var emailFreq sql.NullInt64
			if err := r.Scan(&pid, &uid, &rt, &lat, &lng, &emailFreq); err != nil {
				log.Fatalf("step3 scan: %v", err)
			}
			p, ok := posts[pid]
			if !ok {
				continue
			}
			if !lat.Valid || !lng.Valid {
				noLocation++
				continue
			}
			rep := Replier{
				UserID:    uid,
				Lat:       lat.Float64,
				Lng:       lng.Float64,
				ReplyTime: rt,
			}
			if emailFreq.Valid {
				v := int(emailFreq.Int64)
				rep.EmailFreq = &v
			}
			p.Repliers = append(p.Repliers, rep)
			replierCount++
		}
		r.Close()
		if (start/batchSize+1)%4 == 0 || end == len(ids) {
			log.Printf("  ...batch %d/%d (%d replies so far)",
				start/batchSize+1, (len(ids)+batchSize-1)/batchSize, replierCount)
		}
	}

	// drop posts whose repliers were all geo-unknown
	kept := make([]*Post, 0, len(posts))
	for _, p := range posts {
		if len(p.Repliers) > 0 {
			kept = append(kept, p)
		}
	}
	log.Printf("\nsummary:")
	log.Printf("  posts kept              : %d", len(kept))
	log.Printf("  (post, replier) pairs   : %d", replierCount)
	log.Printf("  repliers w/o location   : %d (dropped)", noLocation)

	// ----- 4. write JSON -----
	tmp := *output + ".tmp"
	f, err := os.Create(tmp)
	if err != nil {
		log.Fatalf("create tmp: %v", err)
	}
	enc := json.NewEncoder(f)
	enc.SetIndent("", "")
	if err := enc.Encode(kept); err != nil {
		f.Close()
		log.Fatalf("encode: %v", err)
	}
	f.Close()
	if err := os.Rename(tmp, *output); err != nil {
		log.Fatalf("rename: %v", err)
	}
	st, _ := os.Stat(*output)
	log.Printf("\nwrote %d bytes to %s", st.Size(), *output)
}
