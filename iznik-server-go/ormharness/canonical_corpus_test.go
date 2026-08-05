package ormharness

// Reciprocal half of the cross-language canonicaliser pinning described in
// tools/orm-migration/canonical-corpus.json's own header comment.
//
// READS canonical-corpus.json IN THIS DIRECTORY, NOT tools/orm-migration/'s
// COPY DIRECTLY - discovered the hard way, the same failure mode
// golden.go's own manifest-embedding comment already documents for
// manifest.json: "the apiv2 container mounts iznik-server-go as /app, so
// anything living above iznik-server-go in the repo simply does not exist
// at runtime". Confirmed here too - the Dockerfile's build context is
// iznik-server-go itself (`COPY . .` run from within that directory), so
// tools/orm-migration/ is never even copied into the image `go test` runs
// against, and a relative-path read that tries to escape upward failed with
// exactly that "no such file" error on the first real run. go:embed cannot
// reach outside its own module tree either (embed patterns may not use
// ".."), so the fix is the same shape as manifest.json's: a copy of the
// corpus lives IN this directory too, embedded into the test binary.
//
// tools/orm-migration/canonical-corpus.json remains the file to actually
// EDIT - its own header explains why the corpus exists and how to keep both
// languages' tests passing. This file and
// iznik-batch/tests/Support/OrmHarness/canonical-corpus.json are read-only
// copies kept byte-identical to it, checked by
// tools/orm-migration/check-canonical-corpus-sync.sh (wired into gate (q) in
// ci-ratchet.sh) - the same drift risk a lone shared file was meant to
// avoid, now guarded mechanically instead of by trusting nobody forgets a
// copy.
//
// The Laravel ORM migration (iznik-batch) needed its own SQL canonicaliser
// for Layer 1 (plan section 7.2) - Go and PHP are different runtimes, so
// there is no way to literally share this file's Canonical() function with
// PHPUnit without adding a compiled-binary subprocess dependency to the
// Laravel test suite, which was rejected as impractical (see
// iznik-batch/tests/Support/OrmHarness/Canonical.php's header for the full
// reasoning). What exists instead is a faithful line-by-line PORT of
// Canonical() to PHP, plus this: a shared JSON corpus, derived from THIS
// package's own canonical_test.go, checked by a PHPUnit test
// (iznik-batch/tests/Unit/OrmHarness/CanonicalTest.php) against the PHP
// port AND by this file against the Go original. Two independently-
// maintained canonicalisers drift the moment someone fixes a bug or adds a
// keyword on only one side; this is what turns that drift into a failing
// test on both sides rather than a silent divergence nobody notices until
// a manifest's notion of "equal SQL" stops matching the other's.
//
// If you change Canonical() in a way that changes its behaviour on any case
// already in the corpus, or add a case here, add the matching case to
// tools/orm-migration/canonical-corpus.json and re-run the PHP suite too -
// a change that only updates this file has NOT proven the two implementations
// still agree, only that this one is internally consistent.
//
// This file adds no new production code and does not touch canonical.go,
// golden.go, or any other existing file in this package - it is purely
// additive, consuming Canonical() as it is already exported.

import (
	_ "embed"
	"encoding/json"
	"testing"
)

type canonicalCorpus struct {
	EqualPairs    [][2]string `json:"equalPairs"`
	NotEqualPairs [][2]string `json:"notEqualPairs"`
	Idempotent    []string    `json:"idempotent"`
}

//go:embed canonical-corpus.json
var embeddedCanonicalCorpus []byte

func loadCanonicalCorpus(t *testing.T) canonicalCorpus {
	t.Helper()

	var c canonicalCorpus
	if err := json.Unmarshal(embeddedCanonicalCorpus, &c); err != nil {
		t.Fatalf("ormharness: parsing embedded canonicaliser corpus: %v", err)
	}
	return c
}

func TestCanonical_SharedCorpus_EqualPairs(t *testing.T) {
	corpus := loadCanonicalCorpus(t)
	for i, pair := range corpus.EqualPairs {
		a, b := pair[0], pair[1]
		t.Run("", func(t *testing.T) {
			ca, cb := Canonical(a), Canonical(b)
			if ca != cb {
				t.Fatalf("corpus case %d: expected canonically equal:\n  a: %s\n  -> %s\n  b: %s\n  -> %s", i, a, ca, b, cb)
			}
		})
	}
}

func TestCanonical_SharedCorpus_NotEqualPairs(t *testing.T) {
	corpus := loadCanonicalCorpus(t)
	for i, pair := range corpus.NotEqualPairs {
		a, b := pair[0], pair[1]
		t.Run("", func(t *testing.T) {
			ca, cb := Canonical(a), Canonical(b)
			if ca == cb {
				t.Fatalf("corpus case %d: expected canonically different, both reduced to %q:\n  a: %s\n  b: %s", i, ca, a, b)
			}
		})
	}
}

func TestCanonical_SharedCorpus_Idempotent(t *testing.T) {
	corpus := loadCanonicalCorpus(t)
	for i, sql := range corpus.Idempotent {
		t.Run("", func(t *testing.T) {
			once := Canonical(sql)
			twice := Canonical(once)
			if once != twice {
				t.Fatalf("corpus case %d: Canonical is not idempotent:\n  once:  %s\n  twice: %s", i, once, twice)
			}
		})
	}
}
