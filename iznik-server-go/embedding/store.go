package embedding

import (
	"encoding/binary"
	"fmt"
	"math"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
)

// EmbeddingDim is 256-dim Matryoshka truncation of nomic-embed-text-v1.5.
const EmbeddingDim = 256

// Entry holds one message's subject (and optional body) embedding plus metadata.
type Entry struct {
	Msgid      uint64
	Groupid    uint64
	Msgtype    string
	Lat        float64
	Lng        float64
	Subject    string
	Arrival    time.Time
	SubjectVec [EmbeddingDim]float32
	BodyVec    *[EmbeddingDim]float32 // nil when no body embedding stored
}

// Store is the in-memory embedding index.
type Store struct {
	mu      sync.RWMutex
	entries []Entry
}

// Global is the singleton embedding store.
var Global Store

// StartRefresh begins periodic loading of embeddings from the database.
func StartRefresh(interval time.Duration) {
	if err := Global.Load(); err != nil {
		fmt.Printf("WARNING: initial embedding load failed: %v\n", err)
	}

	go func() {
		ticker := time.NewTicker(interval)
		for range ticker.C {
			if err := Global.Load(); err != nil {
				fmt.Printf("WARNING: embedding refresh failed: %v\n", err)
			}
		}
	}()
}

// Load reads all embeddings + spatial metadata from DB.
func (s *Store) Load() error {
	db := database.DBConn
	if db == nil {
		return fmt.Errorf("database not initialized")
	}

	type row struct {
		Msgid            uint64    `gorm:"column:msgid"`
		SubjectEmbedding []byte    `gorm:"column:subject_embedding"`
		BodyEmbedding    []byte    `gorm:"column:body_embedding"`
		Groupid          uint64    `gorm:"column:groupid"`
		Msgtype          string    `gorm:"column:msgtype"`
		Lat              float64   `gorm:"column:lat"`
		Lng              float64   `gorm:"column:lng"`
		Subject          string    `gorm:"column:subject"`
		Arrival          time.Time `gorm:"column:arrival"`
	}

	var rows []row
	result := db.Raw(`
		SELECT me.msgid, me.subject_embedding, me.body_embedding,
		       ms.groupid, ms.msgtype,
		       ST_Y(ms.point) as lat, ST_X(ms.point) as lng,
		       m.subject, ms.arrival
		FROM messages_embeddings me
		INNER JOIN messages_spatial ms ON ms.msgid = me.msgid
		INNER JOIN messages m ON m.id = me.msgid
		WHERE ms.successful = 0 AND ms.promised = 0
	`).Scan(&rows)

	if result.Error != nil {
		return fmt.Errorf("query: %w", result.Error)
	}

	entries := make([]Entry, 0, len(rows))
	for _, r := range rows {
		e, err := decodeEntry(r.Msgid, r.Groupid, r.Msgtype, r.Lat, r.Lng, r.Subject, r.Arrival, r.SubjectEmbedding, r.BodyEmbedding)
		if err != nil {
			continue // wrong-sized subject blob: skip
		}
		entries = append(entries, e)
	}

	s.mu.Lock()
	s.entries = entries
	s.mu.Unlock()

	return nil
}

// decodeEntry builds an Entry from raw DB columns. Subject embedding is
// required and must match EmbeddingDim; body embedding is optional and
// silently skipped if the wrong size.
func decodeEntry(msgid, groupid uint64, msgtype string, lat, lng float64, subject string, arrival time.Time, subjectBytes, bodyBytes []byte) (Entry, error) {
	if len(subjectBytes) != EmbeddingDim*4 {
		return Entry{}, fmt.Errorf("subject embedding wrong size: %d", len(subjectBytes))
	}

	e := Entry{
		Msgid:   msgid,
		Groupid: groupid,
		Msgtype: msgtype,
		Lat:     lat,
		Lng:     lng,
		Subject: subject,
		Arrival: arrival,
	}

	decodeFloats(subjectBytes, e.SubjectVec[:])

	if len(bodyBytes) == EmbeddingDim*4 {
		var body [EmbeddingDim]float32
		decodeFloats(bodyBytes, body[:])
		e.BodyVec = &body
	}

	return e, nil
}

// decodeFloats decodes little-endian float32s from raw bytes into dst.
func decodeFloats(raw []byte, dst []float32) {
	for i := 0; i < len(dst); i++ {
		bits := binary.LittleEndian.Uint32(raw[i*4 : (i+1)*4])
		dst[i] = math.Float32frombits(bits)
	}
}

// VectorSearchResult from vector search. SubjectCos and BodyCos are the pure
// per-field cosines; HasBody distinguishes "body exists but cosine is 0" from
// "no body embedding" (BodyCos is 0 in both cases). The caller decides how to
// tier/order results — this struct carries the raw signal.
type VectorSearchResult struct {
	Msgid      uint64    `json:"id"`
	Groupid    uint64    `json:"groupid"`
	Msgtype    string    `json:"type"`
	Lat        float64   `json:"lat"`
	Lng        float64   `json:"lng"`
	SubjectCos float32   `json:"subjectCos"`
	BodyCos    float32   `json:"bodyCos"`
	HasBody    bool      `json:"hasBody"`
	Subject    string    `json:"-"` // Used for hybrid keyword scoring, not serialized
	Arrival    time.Time `json:"-"`
}

// Search performs brute-force cosine similarity on every entry and returns the
// top-K by max(subjectCos, bodyCos). Returning both cosines separately lets the
// caller order subject-matches ahead of body-matches (what users expect:
// a literal "table" in the subject should come before a message that only
// mentions "table" in the body).
func (s *Store) Search(query []float32, limit int, msgtype string, groupids []uint64,
	swlat, swlng, nelat, nelng float32) []VectorSearchResult {

	s.mu.RLock()
	defer s.mu.RUnlock()

	groupSet := make(map[uint64]bool, len(groupids))
	for _, g := range groupids {
		groupSet[g] = true
	}
	hasGroupFilter := len(groupids) > 0
	hasBoxFilter := nelat != 0 || nelng != 0 || swlat != 0 || swlng != 0

	type scored struct {
		idx        int
		subjectCos float32
		bodyCos    float32
		hasBody    bool
		rankScore  float32 // max(subjectCos, bodyCos) — used only for top-K selection
	}

	results := make([]scored, 0, len(s.entries))

	for i := range s.entries {
		e := &s.entries[i]

		if msgtype == "Offer" && e.Msgtype != "Offer" {
			continue
		}
		if msgtype == "Wanted" && e.Msgtype != "Wanted" {
			continue
		}
		if hasGroupFilter && !groupSet[e.Groupid] {
			continue
		}
		if hasBoxFilter {
			lat := float32(e.Lat)
			lng := float32(e.Lng)
			if lat < swlat-0.02 || lat > nelat+0.02 || lng < swlng-0.02 || lng > nelng+0.02 {
				continue
			}
		}

		var subjectCos float32
		for j := 0; j < EmbeddingDim; j++ {
			subjectCos += query[j] * e.SubjectVec[j]
		}

		rankScore := subjectCos
		var bodyCos float32
		hasBody := e.BodyVec != nil
		if hasBody {
			for j := 0; j < EmbeddingDim; j++ {
				bodyCos += query[j] * e.BodyVec[j]
			}
			if bodyCos > rankScore {
				rankScore = bodyCos
			}
		}

		results = append(results, scored{
			idx: i, subjectCos: subjectCos, bodyCos: bodyCos,
			hasBody: hasBody, rankScore: rankScore,
		})
	}

	// Top-K by rankScore descending (selection sort — fine for N < 1000).
	// Caller re-orders into subject/body tiers; this step only bounds the
	// working set of candidates that are strong on at least one field.
	n := len(results)
	if n > limit {
		n = limit
	}
	for i := 0; i < n; i++ {
		maxIdx := i
		for j := i + 1; j < len(results); j++ {
			if results[j].rankScore > results[maxIdx].rankScore {
				maxIdx = j
			}
		}
		results[i], results[maxIdx] = results[maxIdx], results[i]
	}
	if len(results) > limit {
		results = results[:limit]
	}

	out := make([]VectorSearchResult, len(results))
	for i, r := range results {
		e := &s.entries[r.idx]
		out[i] = VectorSearchResult{
			Msgid:      e.Msgid,
			Groupid:    e.Groupid,
			Msgtype:    e.Msgtype,
			Lat:        e.Lat,
			Lng:        e.Lng,
			SubjectCos: r.subjectCos,
			BodyCos:    r.bodyCos,
			HasBody:    r.hasBody,
			Subject:    e.Subject,
			Arrival:    e.Arrival,
		}
	}

	return out
}

// SetEntries replaces the store entries (for testing).
func (s *Store) SetEntries(entries []Entry) {
	s.mu.Lock()
	s.entries = entries
	s.mu.Unlock()
}

// Count returns the number of loaded embeddings.
func (s *Store) Count() int {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.entries)
}
