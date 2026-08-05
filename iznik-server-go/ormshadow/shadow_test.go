package ormshadow

// Pure unit tests for the Layer 3 pieces that do not need a live database:
// the batch-state parsing/caching state machine and row-digest hashing. DB-
// backed behaviour (CurrentBatchState actually reading the config table,
// ShadowRead's end-to-end behaviour per state) lives in
// iznik-server-go/test, per this codebase's split between pure ormharness
// tests and DB-backed test/*_test.go (see test/main_test.go).

import (
	"testing"
	"time"
)

func TestParseBatchState(t *testing.T) {
	cases := []struct {
		raw     string
		want    BatchState
		wantOK  bool
		comment string
	}{
		{"off", BatchOff, true, "plain off"},
		{"OFF", BatchOff, true, "case-insensitive"},
		{" off ", BatchOff, true, "surrounding whitespace trimmed"},
		{"shadow", BatchShadow, true, "plain shadow"},
		{"Shadow", BatchShadow, true, "case-insensitive"},
		{"live", BatchLive, true, "plain live"},
		{"new-live", BatchLive, true, "plan's own phrase, accepted as an alias"},
		{"NEW-LIVE", BatchLive, true, "alias is case-insensitive too"},
		{"", BatchOff, false, "empty is invalid, not a silent off"},
		{"onnn", BatchOff, false, "typo must not be silently treated as a real state"},
		{"ON", BatchOff, false, "not a recognised value"},
	}

	for _, c := range cases {
		got, ok := ParseBatchState(c.raw)
		if got != c.want || ok != c.wantOK {
			t.Errorf("ParseBatchState(%q) = (%v, %v), want (%v, %v) [%s]", c.raw, got, ok, c.want, c.wantOK, c.comment)
		}
	}
}

func TestBatchStateString(t *testing.T) {
	cases := []struct {
		state BatchState
		want  string
	}{
		{BatchOff, "off"},
		{BatchShadow, "shadow"},
		{BatchLive, "live"},
		{BatchState(99), "off"}, // Unknown value defaults to the safe string too.
	}
	for _, c := range cases {
		if got := c.state.String(); got != c.want {
			t.Errorf("BatchState(%d).String() = %q, want %q", c.state, got, c.want)
		}
	}
}

func TestParseBatchState_StringRoundTrip(t *testing.T) {
	// Every state's own String() output must parse back to itself, since
	// that is exactly what an operator does: read the current value from
	// the config table (or from a status page rendering String()), and
	// write the same word back.
	for _, state := range []BatchState{BatchOff, BatchShadow, BatchLive} {
		parsed, ok := ParseBatchState(state.String())
		if !ok || parsed != state {
			t.Errorf("ParseBatchState(%q.String()) = (%v, %v), want (%v, true)", state, parsed, ok, state)
		}
	}
}

func TestInvalidateBatchStateCache(t *testing.T) {
	// Exercises the cache state machine directly (no DB needed): seed an
	// entry, invalidate it, confirm it is gone. CurrentBatchState's DB-
	// reading half is covered in test/ against the real config table.
	shadowBatchStateCacheMu.Lock()
	shadowBatchStateCache["unit-test-batch"] = shadowCachedBatchState{state: BatchLive, fetchedAt: time.Now()}
	shadowBatchStateCacheMu.Unlock()

	InvalidateBatchStateCache("unit-test-batch")

	shadowBatchStateCacheMu.RLock()
	_, ok := shadowBatchStateCache["unit-test-batch"]
	shadowBatchStateCacheMu.RUnlock()

	if ok {
		t.Error("expected cache entry to be removed after InvalidateBatchStateCache")
	}
}

// shadowDigestRow is a small struct with a nullable field (a pointer, as the
// package doc comments direct callers to use) for exercising ShadowRowDigest.
type shadowDigestRow struct {
	ID    int
	Name  string
	Extra *string
}

func strPtr(s string) *string { return &s }

func TestShadowRowDigest_OrderInsensitiveWhenUnordered(t *testing.T) {
	a := []shadowDigestRow{{ID: 1, Name: "x"}, {ID: 2, Name: "y"}}
	b := []shadowDigestRow{{ID: 2, Name: "y"}, {ID: 1, Name: "x"}}

	digestA, err := ShadowRowDigest(a, false)
	if err != nil {
		t.Fatalf("ShadowRowDigest(a): %v", err)
	}
	digestB, err := ShadowRowDigest(b, false)
	if err != nil {
		t.Fatalf("ShadowRowDigest(b): %v", err)
	}

	if digestA != digestB {
		t.Errorf("expected same-content rows in different order to hash identically when ordered=false, got %s != %s", digestA, digestB)
	}
}

func TestShadowRowDigest_OrderSensitiveWhenOrdered(t *testing.T) {
	a := []shadowDigestRow{{ID: 1, Name: "x"}, {ID: 2, Name: "y"}}
	b := []shadowDigestRow{{ID: 2, Name: "y"}, {ID: 1, Name: "x"}}

	digestA, err := ShadowRowDigest(a, true)
	if err != nil {
		t.Fatalf("ShadowRowDigest(a): %v", err)
	}
	digestB, err := ShadowRowDigest(b, true)
	if err != nil {
		t.Fatalf("ShadowRowDigest(b): %v", err)
	}

	if digestA == digestB {
		t.Error("expected differently-ordered rows to hash differently when ordered=true (the query has its own ORDER BY, so order is meaningful)")
	}
}

func TestShadowRowDigest_NullDistinctFromZeroValue(t *testing.T) {
	nilExtra := []shadowDigestRow{{ID: 1, Name: "x", Extra: nil}}
	emptyExtra := []shadowDigestRow{{ID: 1, Name: "x", Extra: strPtr("")}}

	digestNil, err := ShadowRowDigest(nilExtra, true)
	if err != nil {
		t.Fatalf("ShadowRowDigest(nilExtra): %v", err)
	}
	digestEmpty, err := ShadowRowDigest(emptyExtra, true)
	if err != nil {
		t.Fatalf("ShadowRowDigest(emptyExtra): %v", err)
	}

	if digestNil == digestEmpty {
		t.Error("expected NULL (nil pointer) and empty string to hash differently - NULL-distinctness is a hard requirement of Layer 3")
	}
}

func TestShadowRowDigest_DuplicateRowsAreAMultiset(t *testing.T) {
	// Two identical rows must not collapse to the digest of a single row -
	// order-insensitive comparison must still be multiset-correct, not
	// set-correct.
	twoIdentical := []shadowDigestRow{{ID: 1, Name: "x"}, {ID: 1, Name: "x"}}
	oneOfEach := []shadowDigestRow{{ID: 1, Name: "x"}, {ID: 2, Name: "y"}}

	digestDup, err := ShadowRowDigest(twoIdentical, false)
	if err != nil {
		t.Fatalf("ShadowRowDigest(twoIdentical): %v", err)
	}
	digestDistinct, err := ShadowRowDigest(oneOfEach, false)
	if err != nil {
		t.Fatalf("ShadowRowDigest(oneOfEach): %v", err)
	}

	if digestDup == digestDistinct {
		t.Error("expected two identical rows to hash differently from two distinct rows")
	}

	// And a genuinely identical multiset, rebuilt independently, must still
	// match - this is the actual "no false divergence" property Layer 3
	// depends on to avoid noisy alerts.
	twoIdenticalAgain := []shadowDigestRow{{ID: 1, Name: "x"}, {ID: 1, Name: "x"}}
	digestDupAgain, err := ShadowRowDigest(twoIdenticalAgain, false)
	if err != nil {
		t.Fatalf("ShadowRowDigest(twoIdenticalAgain): %v", err)
	}
	if digestDup != digestDupAgain {
		t.Error("expected the same multiset to hash identically across independent calls")
	}
}

func TestShadowRowDigest_EmptyResultSets(t *testing.T) {
	var empty []shadowDigestRow
	digest, err := ShadowRowDigest(empty, false)
	if err != nil {
		t.Fatalf("ShadowRowDigest(empty): %v", err)
	}
	digestAgain, err := ShadowRowDigest(empty, true)
	if err != nil {
		t.Fatalf("ShadowRowDigest(empty): %v", err)
	}
	if digest != digestAgain {
		t.Error("expected an empty result set to hash identically regardless of ordered")
	}
}
