package parity

// The Go and PHP canonicalisers are two ports of one design, and each runtime checks itself
// against the same corpus: canonical-corpus.json holds pairs that must compare equal, pairs that
// must not, and statements that must be unchanged by a second pass. This file is the Go half.
// iznik-batch/tests/Unit/OrmHarness/CanonicalTest.php is the PHP half, and its copy of the corpus
// is iznik-batch/tests/Support/OrmHarness/canonical-corpus.json.
//
// The corpus is copied into this directory because the Go tests run in a container that holds only
// iznik-server-go, and go:embed cannot reach outside the module. The two copies must be
// byte-identical. `node tools/orm-migration/extract.mjs check` fails when they differ.
//
// Change Canonical in a way that alters a case already here, or add a case, in both copies of the
// corpus and run both suites. A change to one language alone has not shown the two still agree.

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
		t.Fatalf("parity: parsing embedded canonicaliser corpus: %v", err)
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
