// rippleextract — Layer-1 simulator data extractor (Go version).
//
// Pulls historical (post, replier) records from the database directly, no iznik
// framework dependencies, so it can run anywhere with DB access (e.g. inside
// the iznik-routing-go container which already has the database settings
// in env).
//
// Output JSON matches what cmd/ripplesim expects.
//
// Usage:
//
//	rippleextract --months=12 --limit=10000 --output=/tmp/ripple_sim_data.json
//
// Env: DB_ENGINE (mysql, the default, or postgres). For MySQL: MYSQL_HOST, MYSQL_PORT, MYSQL_USER,
// MYSQL_PASSWORD, MYSQL_DBNAME. For PostgreSQL: PGSQL_HOST, PGSQL_PORT, PGSQL_USERNAME,
// PGSQL_PASSWORD, PGSQL_DATABASE.
package main

import (
	"database/sql"
	"encoding/json"
	"flag"
	"log"
	"os"
	"strings"
	"time"

	"github.com/freegle/iznik-routing-go/dbengine"
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

func main() {
	flag.Parse()
	log.SetFlags(log.Ltime)

	engine, err := dbengine.FromEnv()
	if err != nil {
		log.Fatalf("%v", err)
	}
	settings := dbengine.SettingsFromEnv(engine, dbengine.Settings{Host: "localhost", User: "root"})
	dsn := engine.DSN(settings, dbengine.DSNOptions{ParseTime: false, ReadTimeout: 300 * time.Second})
	db, err := sql.Open(engine.Driver(), dsn)
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
	q1 := sqlCandidates()
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
		q2 := sqlPostMetadata(strings.Join(ph, ","))
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
		q3 := sqlRepliers(strings.Join(ph, ","))
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
