package ormharness

// Tier 9 of the keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, §4): sites whose rendered
// SQL has a branch/tuple count that is a runtime VALUE (len(groupIDs),
// len(chunk)), not one of a small, enumerable set of shapes. shapes.json's
// AssertGoldenShapes (shapes.go) exists for the latter - a fixed, exhaustive
// list of named forms - and its own doc comment explains why exhaustiveness
// is the whole point: "a conversion tested on two of a site's five branches
// is worse than leaving it raw, because it looks tested". That reasoning is
// exactly why AssertGoldenShapes cannot be stretched to cover these sites by
// just declaring a couple of sample counts (e.g. n=1, n=2): a UNION ALL whose
// arm count is len(groupIDs), or a multi-row INSERT whose tuple count is
// len(chunk) (1..100 for message/markseen.go's insertViewBatch), has no
// small enumerable set of "shapes" - sampling two counts would look tested
// while covering a sliver of the real range, the precise anti-pattern
// shapes.go warns against.
//
// The fix is not more samples, it is a different kind of golden: a FUNCTION
// of n, not a literal string. wantSQL(n) generates the expected SQL for ANY
// n; AssertGoldenParametrizedShape checks the site's actual output against
// it at several n, rather than checking one rendered string against one
// recorded string. Coverage is not, and cannot be, exhaustive - n is
// unbounded or bounded only by an application-level cap (100 for
// markseen.go, an arbitrary number of groups for message_list.go) - so the
// trustworthiness AssertGoldenShapes gets from exhaustiveness has to come
// from somewhere else here: from the comparison being against a GENERATING
// MODEL of the SQL rather than a fixed point, and from every n that WAS
// checked being named explicitly in the test (visible in the diff, not
// hidden in a JSON file), so a reviewer can see exactly what was and was not
// exercised rather than have to trust an "exhaustive" claim.
//
// Each call site's own test is expected to name at least the smallest real n
// (0 or 1 - whichever is reachable), a middle n large enough to prove the
// per-branch/tuple JOIN behaviour rather than a trivial single-branch
// statement, and the largest n the call site can actually produce (an
// application-level cap, when one exists). AssertGoldenParametrizedShape
// enforces only the mechanical minimum (at least 3 distinct n, no
// duplicates) - it cannot know a given site's real boundaries, so the
// boundary choice itself is a per-test-file review point, not something this
// function can check for you.

import (
	"strings"
)

// ParametrizedShapeCase is one comparison point for
// AssertGoldenParametrizedShape: a branch/repeat count N, and the SQL/Args
// the site under test actually produced for that N - ordinarily by calling
// the real production builder function (e.g. buildMTUnionAllMsgIDQuery)
// directly with a synthetic input of length N, so what is being checked is
// the same code path production runs, not a reimplementation of it.
type ParametrizedShapeCase struct {
	N    int
	SQL  string
	Args []interface{}
}

// AssertGoldenParametrizedShape proves a runtime-varying site whose repeat
// count is a value (UNION ALL branches, multi-row VALUES tuples, ...), not a
// name from a fixed, declarable set. It differs from AssertGoldenSQL and
// AssertGoldenShapes in a load-bearing way: it does not look the site up in
// the manifest, because there is no single goldenSql (or fixed named-shape
// set) to record for it - the extractor itself records these as an
// unresolved "{{expr}}"/"%d" placeholder, which is exactly the situation
// that makes Layer 1 text parity impossible "by construction" (see the
// keep-raw reasons for message_list.go's ListMessagesMT and
// markseen.go's insertViewBatch). siteID is carried through into every
// failure message purely so a failure names the real site, the same
// traceability AssertGoldenSQL's manifest lookup gives for free.
//
// wantSQL is the template: given a branch/tuple count n, it must return the
// exact SQL the production code should have produced for that n. This is
// what makes the assertion a proof about a FUNCTION of n rather than a
// handful of fixed strings - wantSQL(n) is called for every n in cases, not
// written out by hand for each one.
//
// wantArgCount, if non-nil, is checked the same way for the number of bind
// arguments a case's SQL should carry at that n; pass nil to skip this (some
// sites bind a fixed number of args regardless of n, e.g. one "?" per
// repeated branch template rather than per branch instance, in which case
// the caller may prefer to just rely on the placeholder-count check below).
//
// Every case is ALSO checked for internal consistency regardless of
// wantArgCount: strings.Count(c.SQL, "?") must equal len(c.Args), the same
// invariant AssertGoldenSQL enforces for ordinary sites (golden.go) - a
// chain that renders the right SQL text with the wrong argument count still
// fails at runtime, and text comparison alone is blind to that.
//
// cases must supply at least 3 distinct n (no duplicates) - see the package
// doc comment above for why that is a floor, not a target: a real site's own
// test should also span 0-or-1, a middle n, and an application cap where one
// exists.
func AssertGoldenParametrizedShape(t TestingT, siteID string, wantSQL func(n int) string, wantArgCount func(n int) int, cases []ParametrizedShapeCase) {
	t.Helper()

	if len(cases) < 3 {
		t.Fatalf("ormharness: site %q: AssertGoldenParametrizedShape needs at least 3 cases spanning the "+
			"boundaries a real call site can produce - got %d. Sampling too few points cannot show wantSQL "+
			"was exercised past a single value, and the whole point of a parametrized shape is proving a "+
			"FUNCTION of n, not pinning one n.", siteID, len(cases))
	}

	seenN := make(map[int]bool, len(cases))
	for _, c := range cases {
		if seenN[c.N] {
			t.Fatalf("ormharness: site %q: n=%d supplied more than once in cases - each n should appear at most once", siteID, c.N)
		}
		seenN[c.N] = true

		wantRaw := wantSQL(c.N)
		wantCanon := Canonical(wantRaw)
		gotCanon := Canonical(c.SQL)
		if wantCanon != gotCanon {
			t.Fatalf("ormharness: site %q: parametrized shape mismatch at n=%d\n  want: %s\n  got:  %s\n"+
				"  canonical want: %s\n  canonical got:  %s",
				siteID, c.N, wantRaw, c.SQL, wantCanon, gotCanon)
		}

		if wantArgCount != nil {
			if want, got := wantArgCount(c.N), len(c.Args); want != got {
				t.Fatalf("ormharness: site %q: at n=%d, expected %d bind argument(s), the actual call supplied %d",
					siteID, c.N, want, got)
			}
		}

		if want, got := strings.Count(c.SQL, "?"), len(c.Args); want != got {
			t.Fatalf("ormharness: site %q: at n=%d, the SQL has %d placeholder(s) but %d bind argument(s) were "+
				"supplied - the text can match wantSQL exactly while this is still wrong.\nSQL: %s",
				siteID, c.N, want, got, c.SQL)
		}
	}
}
