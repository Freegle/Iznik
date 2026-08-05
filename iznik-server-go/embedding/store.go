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
	Fromuser   uint64
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

// StartRefresh loads embeddings from the database at startup, then keeps the
// store current with an incremental Refresh() on each tick (see Refresh).
func StartRefresh(interval time.Duration) {
	if err := Global.Load(); err != nil {
		fmt.Printf("WARNING: initial embedding load failed: %v\n", err)
	}

	go func() {
		ticker := time.NewTicker(interval)
		for range ticker.C {
			if err := Global.Refresh(); err != nil {
				fmt.Printf("WARNING: embedding refresh failed: %v\n", err)
			}
		}
	}()
}

// embeddingRow mirrors the columns fetched by fetchEntries.
type embeddingRow struct {
	Msgid            uint64    `gorm:"column:msgid"`
	Fromuser         uint64    `gorm:"column:fromuser"`
	SubjectEmbedding []byte    `gorm:"column:subject_embedding"`
	BodyEmbedding    []byte    `gorm:"column:body_embedding"`
	Groupid          uint64    `gorm:"column:groupid"`
	Msgtype          string    `gorm:"column:msgtype"`
	Lat              float64   `gorm:"column:lat"`
	Lng              float64   `gorm:"column:lng"`
	Subject          string    `gorm:"column:subject"`
	Arrival          time.Time `gorm:"column:arrival"`
}

// fetchEntries runs the open-embedded-message SELECT shared by Load and
// Refresh, optionally narrowed by extraWhere/args (e.g. "AND me.msgid IN
// (?)"), and decodes the rows into Entries.
func fetchEntries(extraWhere string, args ...interface{}) ([]Entry, error) {
	db := database.DBConn
	if db == nil {
		return nil, fmt.Errorf("database not initialized")
	}

	// extraWhere has
	// exactly two callers: Load passes "" and Refresh passes
	// " AND me.msgid IN (?)" - 2 possible rendered forms, both declared in
	// ormharness/shapes.json and proven by TestTier3Shapes_15d5998c44f2
	// (iznik-server-go/test).
	// WHERE built as a single string for ONE Where() call: GORM's
	// clause.Where wraps any fragment containing "AND"/"OR" in an extra
	// paren pair once there is more than one Where expression to combine
	// (clause/where.go buildExprs), which would diverge from the golden.
	whereSQL := "ms.successful = 0 AND ms.promised = 0" + extraWhere

	var rows []embeddingRow
	tx := db.Table("messages_embeddings me").
		Select("me.msgid, m.fromuser, me.subject_embedding, me.body_embedding, "+
			"ms.groupid, ms.msgtype, ST_Y(ms.point) as lat, ST_X(ms.point) as lng, "+
			"m.subject, ms.arrival").
		Joins("INNER JOIN messages_spatial ms ON ms.msgid = me.msgid").
		Joins("INNER JOIN messages m ON m.id = me.msgid").
		Where(whereSQL, args...)

	if result := tx.Scan(&rows); result.Error != nil {
		return nil, fmt.Errorf("query: %w", result.Error)
	}

	entries := make([]Entry, 0, len(rows))
	for _, r := range rows {
		e, err := decodeEntry(r.Msgid, r.Fromuser, r.Groupid, r.Msgtype, r.Lat, r.Lng, r.Subject, r.Arrival, r.SubjectEmbedding, r.BodyEmbedding)
		if err != nil {
			continue // wrong-sized subject blob: skip
		}
		entries = append(entries, e)
	}
	return entries, nil
}

// Load reads all embeddings + spatial metadata from DB.
func (s *Store) Load() error {
	entries, err := fetchEntries("")
	if err != nil {
		return err
	}

	s.mu.Lock()
	s.entries = entries
	s.mu.Unlock()

	return nil
}

// Refresh incrementally reconciles the store against the DB instead of
// re-reading every row's BLOBs: it drops entries whose message is no longer
// open, and fetches blobs only for open messages the store doesn't already
// have. This replaces a periodic full Load() (measured at ~3.8s on prod for
// ~109k rows) with a cheap id-only diff plus a small blob fetch.
//
// Falls back to a full Load() if the store is currently empty (startup /
// first tick).
//
// Known limitation: if an existing message's embedding blob is regenerated in
// place (e.g. `embeddings:regenerate` after a model change), Refresh() will
// not pick up the new blob for a msgid it already holds. messages_embeddings
// has no updated-at column suitable for a cheap diff: created_at is DEFAULT
// CURRENT_TIMESTAMP only (no ON UPDATE CURRENT_TIMESTAMP — see migration
// 2026_04_14_000001_create_messages_embeddings_table.php), and
// EmbeddingService::processMessages upserts via INSERT ... ON DUPLICATE KEY
// UPDATE that doesn't touch created_at anyway. In practice
// embeddings:regenerate is followed by an apiv2 restart, which does a full
// Load() and picks up the regenerated blobs.
func (s *Store) Refresh() error {
	if s.Count() == 0 {
		return s.Load()
	}

	db := database.DBConn
	if db == nil {
		return fmt.Errorf("database not initialized")
	}

	var openIds []uint64
	if err := db.Table("messages_embeddings me").
		Joins("INNER JOIN messages_spatial ms ON ms.msgid = me.msgid").
		Where("ms.successful = 0 AND ms.promised = 0").
		Pluck("me.msgid", &openIds).Error; err != nil {
		return fmt.Errorf("refresh id query: %w", err)
	}

	open := make(map[uint64]bool, len(openIds))
	for _, id := range openIds {
		open[id] = true
	}

	s.mu.RLock()
	have := make(map[uint64]bool, len(s.entries))
	for i := range s.entries {
		have[s.entries[i].Msgid] = true
	}
	s.mu.RUnlock()

	var added []uint64
	for _, id := range openIds {
		if !have[id] {
			added = append(added, id)
		}
	}

	var newEntries []Entry
	if len(added) > 0 {
		var err error
		newEntries, err = fetchEntries(" AND me.msgid IN (?)", added)
		if err != nil {
			return fmt.Errorf("refresh fetch new: %w", err)
		}
	}

	s.mu.Lock()
	kept := make([]Entry, 0, len(s.entries)+len(newEntries))
	for i := range s.entries {
		if open[s.entries[i].Msgid] {
			kept = append(kept, s.entries[i])
		}
	}
	s.entries = append(kept, newEntries...)
	s.mu.Unlock()

	return nil
}

// decodeEntry builds an Entry from raw DB columns. Subject embedding is
// required and must match EmbeddingDim; body embedding is optional and
// silently skipped if the wrong size.
func decodeEntry(msgid, fromuser, groupid uint64, msgtype string, lat, lng float64, subject string, arrival time.Time, subjectBytes, bodyBytes []byte) (Entry, error) {
	if len(subjectBytes) != EmbeddingDim*4 {
		return Entry{}, fmt.Errorf("subject embedding wrong size: %d", len(subjectBytes))
	}

	e := Entry{
		Msgid:    msgid,
		Fromuser: fromuser,
		Groupid:  groupid,
		Msgtype:  msgtype,
		Lat:      lat,
		Lng:      lng,
		Subject:  subject,
		Arrival:  arrival,
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

// DecodeVector decodes a raw subject/body embedding BLOB (EmbeddingDim
// little-endian float32s) into a slice. Used by callers that read an embedding
// straight from the DB (e.g. the similar-posts endpoint when the source message
// is not in the in-memory store).
func DecodeVector(raw []byte) ([]float32, error) {
	if len(raw) != EmbeddingDim*4 {
		return nil, fmt.Errorf("embedding wrong size: %d", len(raw))
	}
	vec := make([]float32, EmbeddingDim)
	decodeFloats(raw, vec)
	return vec, nil
}

// FindByMsgid returns a copy of the store entry for msgid, and whether it was
// found. Used to reuse an already-loaded message's embedding without a DB read.
func (s *Store) FindByMsgid(msgid uint64) (Entry, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	for i := range s.entries {
		if s.entries[i].Msgid == msgid {
			return s.entries[i], true
		}
	}
	return Entry{}, false
}

// Evict removes the entry for msgid from the in-memory store, if present, and
// reports whether one was removed. Used when a message's embedding is invalidated
// on edit: the DB row is deleted so the batch re-embeds the new content, but
// Refresh() is presence-keyed (see its "Known limitation") and would otherwise keep
// the STALE blob for a msgid it already holds if the delete+re-embed both land
// between two refresh ticks. Evicting forces the next Refresh to treat the msgid as
// new and reload the regenerated embedding, so vector search stops matching the old
// wording (Discourse 9954).
func (s *Store) Evict(msgid uint64) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	for i := range s.entries {
		if s.entries[i].Msgid == msgid {
			s.entries = append(s.entries[:i], s.entries[i+1:]...)
			return true
		}
	}
	return false
}

// VectorSearchResult from vector search. SubjectCos and BodyCos are the pure
// per-field cosines; HasBody distinguishes "body exists but cosine is 0" from
// "no body embedding" (BodyCos is 0 in both cases). The caller decides how to
// tier/order results — this struct carries the raw signal.
type VectorSearchResult struct {
	Msgid      uint64    `json:"id"`
	Fromuser   uint64    `json:"-"` // Used to exclude a post's own author from similar results
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
// allowedIDs, when non-nil, restricts the scan to those msgids - used by browse-scoped
// search to make the top-K selection happen WITHIN the member's feed universe rather than
// filtering afterwards (which would let out-of-feed posts crowd feed posts out of the
// candidate set). nil = no restriction.
func (s *Store) Search(query []float32, limit int, msgtype string, groupids []uint64,
	allowedIDs map[uint64]bool, swlat, swlng, nelat, nelng float32) []VectorSearchResult {

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
		if allowedIDs != nil && !allowedIDs[e.Msgid] {
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
			Fromuser:   e.Fromuser,
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
