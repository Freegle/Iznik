package ormshadow

// Layer 3 of the ORM migration harness (plan section 7.2): shadow execution
// in production for READ call sites, per conversion batch. Layers 1 and 2
// (iznik-server-go/ormharness's canonical.go/golden.go/resultparity.go)
// prove parity offline, against the manifest's golden SQL and the seeded
// test database. This file is what actually de-risks a batch against live
// production data and traffic shape before anyone trusts the new path: it
// always serves the proven old result, and only ever uses the new (GORM)
// result to compare - never to answer a real request - until the batch's
// flag is explicitly promoted to "live".
//
// This lives in its own package, separate from ormharness, deliberately:
// ormharness's Layer 1/2 helpers take a *testing.T and so import "testing"
// from non-test files - fine for CI/test-only tooling, but this file is
// called from live production API handlers, which must never pull the
// testing package into the served binary.
//
// The operational side of this (how a batch moves off -> shadow -> live, the
// soak thresholds, the Grafana/Loki divergence query) is documented in
// docs/developers/reference/orm-migration-harness.md, not here.

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/misc"
	"gorm.io/gorm"
)

// BatchState is the three-state feature flag that controls how far a
// conversion batch has progressed through the Layer 3 rollout.
type BatchState int

const (
	// BatchOff: pure raw-SQL behaviour. The GORM path never runs; ShadowRead
	// is a transparent pass-through to the old query.
	BatchOff BatchState = iota
	// BatchShadow: the old result is what gets served. The new (GORM) path
	// runs asynchronously purely for comparison; it can never affect what
	// the caller sees.
	BatchShadow
	// BatchLive: the batch has graduated after a clean soak. The new path is
	// authoritative and runs synchronously; the old path is not run at all.
	BatchLive
)

// String renders the state the same way it is stored in the config table,
// so log lines and error messages match what an operator would type there.
func (s BatchState) String() string {
	switch s {
	case BatchShadow:
		return "shadow"
	case BatchLive:
		return "live"
	default:
		return "off"
	}
}

// ParseBatchState parses a config-table value into a BatchState. It accepts
// "new-live" as an alias for "live" because that is the exact phrase plan
// section 7.2 uses ("A batch's flag moves to 'new live' only after a
// soak..."), so a value pasted straight from the plan still works. Any other
// value is invalid; the caller should treat that the same as "off", which is
// what CurrentBatchState does - an unrecognised value must never be silently
// treated as "shadow" or "live".
func ParseBatchState(raw string) (BatchState, bool) {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "off":
		return BatchOff, true
	case "shadow":
		return BatchShadow, true
	case "live", "new-live":
		return BatchLive, true
	default:
		return BatchOff, false
	}
}

// shadowBatchStateConfigKeyPrefix namespaces per-batch shadow-execution flags
// in the shared `config` table (see config/config.go and
// item/reusebenefit.go's LoadCPIData for the established "SELECT value FROM
// config WHERE key = ?" pattern this reuses) so they cannot collide with
// unrelated keys such as ads_enabled or voicepost_rollout_pct.
const shadowBatchStateConfigKeyPrefix = "ormharness.shadow."

// shadowBatchStateCacheTTL bounds how long a batch's state is cached
// in-process. A hot read path cannot afford a config-table round trip on
// every request, but the whole point of a DB-backed flag (rather than an
// env var needing a redeploy) is that ops can flip it without a release, so
// the TTL has to be short enough that a flip takes effect promptly.
const shadowBatchStateCacheTTL = 30 * time.Second

type shadowCachedBatchState struct {
	state     BatchState
	fetchedAt time.Time
}

var (
	shadowBatchStateCacheMu sync.RWMutex
	shadowBatchStateCache   = map[string]shadowCachedBatchState{}
)

// CurrentBatchState returns batchID's shadow-execution state, reading the
// `config` table and caching the result for shadowBatchStateCacheTTL. Any
// read failure - missing row, DB error, unparseable value - defaults to
// BatchOff: the safe default is always "do nothing extra", never "start
// shadowing" or "go live" by accident.
func CurrentBatchState(db *gorm.DB, batchID string) BatchState {
	shadowBatchStateCacheMu.RLock()
	cached, ok := shadowBatchStateCache[batchID]
	shadowBatchStateCacheMu.RUnlock()
	if ok && time.Since(cached.fetchedAt) < shadowBatchStateCacheTTL {
		return cached.state
	}

	state := BatchOff
	var row struct {
		Value string `gorm:"column:value"`
	}
	result := db.Raw("SELECT value FROM config WHERE `key` = ? LIMIT 1", shadowBatchStateConfigKeyPrefix+batchID).Scan(&row)
	if result.Error == nil && row.Value != "" {
		if parsed, ok := ParseBatchState(row.Value); ok {
			state = parsed
		}
	}

	shadowBatchStateCacheMu.Lock()
	shadowBatchStateCache[batchID] = shadowCachedBatchState{state: state, fetchedAt: time.Now()}
	shadowBatchStateCacheMu.Unlock()

	return state
}

// InvalidateBatchStateCache drops the cached state for batchID so the next
// CurrentBatchState call re-reads the config table immediately, rather than
// waiting up to shadowBatchStateCacheTTL. Useful right after an operator
// edits the flag and wants to confirm the change took effect, and in tests.
func InvalidateBatchStateCache(batchID string) {
	shadowBatchStateCacheMu.Lock()
	delete(shadowBatchStateCache, batchID)
	shadowBatchStateCacheMu.Unlock()
}

// shadowMaxConcurrent bounds the total number of in-flight async shadow
// comparisons across all batches and sites at once, so a burst of shadowed
// traffic cannot pile up unbounded goroutines or unbounded extra load on the
// (already-saturated, per plan section 3) read replica. A shadow run that
// cannot get a slot is skipped, not queued - see launchShadowCompare.
const shadowMaxConcurrent = 20

// shadowDefaultTimeout bounds how long a single async shadow comparison's
// new-path query is allowed to run before it is cancelled and logged as a
// timeout. Generous, because a genuinely slow new-path query is itself
// useful signal to have surfaced, not just noise to suppress.
const shadowDefaultTimeout = 5 * time.Second

var shadowSemaphore = make(chan struct{}, shadowMaxConcurrent)

// shadowOrderByPattern flags oldSQL as having its own ordering. Deliberately
// duplicated from resultparity.go's orderByPattern (same regex, same
// reasoning: a case-insensitive word-boundary match is enough for real
// call-site SQL) rather than referencing it, so this file's compilation
// never depends on a file owned by a different harness layer.
var shadowOrderByPattern = regexp.MustCompile(`(?is)\border\s+by\b`)

// ShadowNewQuery builds, but does not execute, the GORM statement under
// evaluation - the same "build up to but not including a terminal call"
// convention Layer 1/2's ReplacementQuery (resultparity.go) uses. ShadowRead
// supplies the *gorm.DB (already carrying the right context/timeout for
// whichever state applies) and calls Scan itself, so the destination type
// only needs to be written once, as ShadowRead's own type parameter.
type ShadowNewQuery func(tx *gorm.DB) *gorm.DB

// ShadowOptions carries the few knobs a call site can reasonably want to
// override; everything else about shadow execution (ordering, concurrency
// cap, which state the batch is in) is worked out automatically.
type ShadowOptions struct {
	// Timeout bounds the async shadow comparison's new-path query. Zero uses
	// shadowDefaultTimeout. Has no effect in BatchLive, where the new path
	// runs synchronously against the caller's own ctx instead.
	Timeout time.Duration
}

// ShadowRead runs a Layer-3 shadow-execution read. batchID selects the
// feature flag (see CurrentBatchState); siteID identifies this specific call
// site for the divergence log, and should be the manifest ID for the site
// being converted. oldSQL/oldArgs is exactly what today's raw call site
// passes to db.Raw - ShadowRead runs it unchanged. newQuery is the GORM
// replacement under evaluation.
//
// Behaviour depends on the batch's current state:
//
//   - BatchOff: newQuery never runs. Pure raw-SQL behaviour, same as before
//     this wrapper existed.
//   - BatchShadow: the old result (from oldSQL) is what's returned here. If
//     it succeeded, newQuery is additionally run in a background goroutine
//     (bounded by shadowMaxConcurrent and opts.Timeout), its rows are hashed
//     and compared against the old rows, and any divergence, error, timeout
//     or panic is logged to Loki with batchID and siteID - see
//     logShadowEvent. None of that can affect the value returned here: a
//     failure in the async path is recovered and logged, never propagated.
//   - BatchLive: newQuery is authoritative. It runs synchronously against
//     ctx and its result (or error) is what's returned; oldSQL is not run at
//     all. This is the state a batch reaches after a clean soak (48 hours
//     minimum, one week for hot paths - see the harness doc) and is the
//     step that actually retires the raw-SQL path for live traffic.
//
// T must be safe to encode with encoding/json (used for row hashing and for
// the "old vs new" comparison in shadow mode) - use pointer fields for
// nullable columns so NULL and the zero value hash differently, matching how
// this codebase already models nullable columns elsewhere (e.g. LogEntry in
// systemlogs/systemlogs.go).
func ShadowRead[T any](ctx context.Context, db *gorm.DB, batchID, siteID, oldSQL string, oldArgs []any, newQuery ShadowNewQuery, opts ShadowOptions) ([]T, error) {
	state := CurrentBatchState(db, batchID)

	if state == BatchLive {
		var rows []T
		err := newQuery(db.WithContext(ctx)).Scan(&rows).Error
		return rows, err
	}

	var oldRows []T
	err := db.Raw(oldSQL, oldArgs...).Scan(&oldRows).Error

	if state == BatchShadow && err == nil {
		launchShadowCompare[T](db, batchID, siteID, oldSQL, oldRows, newQuery, opts.Timeout)
	}

	return oldRows, err
}

// launchShadowCompare tries to acquire a shadowSemaphore slot without
// blocking the caller (ShadowRead's own request path) and, if it gets one,
// runs the async new-path query and comparison in a background goroutine.
// At capacity it silently skips this one run rather than blocking or
// queueing - a lower shadow-execution rate under load is fine, since the
// soak period (plan 7.2) exists precisely so that statistical coverage
// evens out over 48 hours or a week, not so every single request must be
// shadowed.
func launchShadowCompare[T any](db *gorm.DB, batchID, siteID, oldSQL string, oldRows []T, newQuery ShadowNewQuery, timeout time.Duration) {
	select {
	case shadowSemaphore <- struct{}{}:
	default:
		return
	}

	if timeout <= 0 {
		timeout = shadowDefaultTimeout
	}
	ordered := shadowOrderByPattern.MatchString(oldSQL)

	go func() {
		defer func() { <-shadowSemaphore }()
		defer func() {
			// Belt and braces: a panic anywhere below (including inside the
			// caller-supplied newQuery) must never escape this goroutine.
			// Nothing here is on the path that serves the real request, but
			// an unrecovered panic in a goroutine still crashes the whole
			// process.
			if r := recover(); r != nil {
				logShadowEvent(batchID, siteID, "panic", map[string]any{
					"panic": fmt.Sprintf("%v", r),
					"sql":   oldSQL,
				})
			}
		}()

		// Deliberately context.Background(), not derived from the request
		// that triggered this shadow run: a Fiber handler's c.Context() is a
		// pooled fasthttp.RequestCtx that gets reused for an unrelated
		// request the instant the handler returns, and this goroutine is
		// designed to outlive the handler by design. Using the request's
		// context here would risk reading a context that has already been
		// handed to something else.
		shadowCtx, cancel := context.WithTimeout(context.Background(), timeout)
		defer cancel()

		var newRows []T
		newErr := newQuery(db.WithContext(shadowCtx)).Scan(&newRows).Error

		if shadowCtx.Err() != nil {
			logShadowEvent(batchID, siteID, "timeout", map[string]any{
				"timeout_ms": timeout.Milliseconds(),
				"sql":        oldSQL,
			})
			return
		}
		if newErr != nil {
			logShadowEvent(batchID, siteID, "error", map[string]any{
				"error": newErr.Error(),
				"sql":   oldSQL,
			})
			return
		}

		oldDigest, oldDigestErr := ShadowRowDigest(oldRows, ordered)
		newDigest, newDigestErr := ShadowRowDigest(newRows, ordered)
		if oldDigestErr != nil || newDigestErr != nil {
			logShadowEvent(batchID, siteID, "hash_error", map[string]any{
				"sql": oldSQL,
			})
			return
		}

		if oldDigest != newDigest {
			logShadowEvent(batchID, siteID, "divergence", map[string]any{
				"sql":           oldSQL,
				"old_row_count": len(oldRows),
				"new_row_count": len(newRows),
				"old_hash":      oldDigest,
				"new_hash":      newDigest,
				"ordered":       ordered,
			})
		}
	}()
}

// ShadowRowDigest returns a stable hex digest of rows for shadow-execution
// comparison. Each row is canonicalised via encoding/json, so nullable
// columns must be represented with pointer fields (e.g. *uint64, *string):
// a nil pointer marshals to `null`, distinct from the zero value, which is
// what makes this NULL-distinct with no extra bookkeeping.
//
// When ordered is false, per-row digests are sorted before being combined,
// so two result sets containing the same rows in a different order hash
// identically - duplicates are still handled correctly as a multiset,
// because two identical rows still contribute two identical entries to the
// sorted list. When ordered is true, row order is folded in directly, so a
// re-ordering is treated as a genuine divergence.
func ShadowRowDigest[T any](rows []T, ordered bool) (string, error) {
	rowHashes := make([]string, len(rows))
	for i, row := range rows {
		encoded, err := json.Marshal(row)
		if err != nil {
			return "", fmt.Errorf("ormharness: encoding row %d for shadow digest: %w", i, err)
		}
		sum := sha256.Sum256(encoded)
		rowHashes[i] = hex.EncodeToString(sum[:])
	}

	if !ordered {
		sort.Strings(rowHashes)
	}

	combined := sha256.New()
	for _, h := range rowHashes {
		combined.Write([]byte(h))
	}
	return hex.EncodeToString(combined.Sum(nil)), nil
}

// logShadowEvent writes a Layer-3 record to Loki via the existing
// misc.LokiClient (misc/loki.go's LogCustom), rather than a new logging
// path. kind is one of "divergence", "error", "timeout", "panic" or
// "hash_error" - matched by the Grafana panel that alerts on divergence
// above zero (see the harness doc for the LogQL). site_id stays in the JSON
// body rather than becoming a label, matching this codebase's existing
// convention of keeping higher-cardinality identifiers (trace_id, session_id
// - see misc/loki.go) out of indexed labels; batch_id and event are low
// enough cardinality to index.
func logShadowEvent(batchID, siteID, kind string, extra map[string]any) {
	data := map[string]any{
		"batch_id": batchID,
		"site_id":  siteID,
		"event":    kind,
	}
	for k, v := range extra {
		data[k] = v
	}

	misc.GetLoki().LogCustom("orm_shadow", map[string]string{
		"batch_id": batchID,
		"event":    kind,
	}, data)
}
