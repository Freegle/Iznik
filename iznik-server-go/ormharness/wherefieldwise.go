package ormharness

// AssertGoldenWhereFieldwise is fieldwise.go's AssertGoldenFieldwise
// generalised from a single dynamic-SET UPDATE to a single dynamic-WHERE
// SELECT: N independently-optional WHERE fragments ANDed onto a base
// predicate, where each factor contributes a fixed fragment (or fixed small
// set of fragments) regardless of which other factors are present. logs.go's
// GetLogs (site 6cf1b5aded22) is the site this exists for: ~7 dimensions
// that would be 352 reachable combinations under AssertGoldenShapes'
// exhaustive model, but which - per a human reading of the code, not an
// assumption - do not interact with each other. That is a scale problem,
// not an independence problem, so the same n+2 argument fieldwise.go's
// package doc comment makes for SET lists applies here: prove each factor's
// own contribution once, prove the fully-populated case once, and the rest
// follows from independence rather than needing 352 renders.
//
// WHY THIS IS A SEPARATE TYPE FROM FieldwiseCase/AssertGoldenFieldwise,
// not a generalisation of it:
//
//  1. THE PRECONDITION IS DIFFERENT IN KIND, not just in what pattern it
//     matches. setOrderIsLoadBearing exists because MySQL evaluates a SET
//     list left to right, so an assignment whose VALUE reads a column
//     another assignment WRITES depends on order - a real, textually
//     detectable hazard specific to SET semantics. AND is commutative, so
//     that specific hazard does not exist for a WHERE list; combining any
//     two syntactically independent boolean predicates with AND is safe by
//     construction. That does NOT mean no precondition is needed - see
//     below - it means the precondition has to check a different property.
//
//  2. THE "EMPTY" CASE MEANS SOMETHING DIFFERENT. Every SET-fieldwise site
//     guards its UPDATE with "if len(setClauses) > 0" - no fields set means
//     no statement at all, and assertEmptyUpdate proves exactly that. A
//     SELECT has no equivalent "do nothing" case: GetLogs with every
//     optional filter absent still runs, just against the unfiltered base
//     predicate ("WHERE 1=1"). So there is no assertEmptyUpdate analogue
//     here; the "nothing set" case (declared as "base") is an ordinary
//     rendered statement checked against its own golden, not a special
//     "no SQL came out" assertion.
//
// THE PRECONDITION THIS VERSION USES, verified rather than assumed: the
// n+2 argument depends on "all" being nothing more than the base predicate
// AND-joined with each field's own fragment(s) and each group's
// declared-representative form's own fragment(s) - i.e. that the
// concatenation model the whole scheme rests on is not silently false for
// THIS site. That is checked mechanically: every declared entry (base, each
// field, each group's AllForm) is a full rendered statement - the same
// "whole statement, not just the fragment" convention SET-fieldwise's field
// goldens use - so its own WHERE fragment list also contains base's
// fragment(s) inherited unchanged. Each entry's CONTRIBUTION is therefore
// its fragment list minus base's (an inherited occurrence is not a
// contribution; an extra occurrence beyond what base already has is), and
// "all"'s top-level (paren-depth 0) AND-list is required to equal base's
// fragments unioned with every field's contribution and every group's
// AllForm's contribution, with nothing left over and nothing missing. If a
// factor's contribution does not appear (that count) in "all", or "all"
// contains a fragment no declared factor/base accounts for, the site is
// refused outright - the same "refuse rather than silently accept"
// discipline setOrderIsLoadBearing enforces for SET lists, checking a
// different property because a different property is what can actually go
// wrong for AND rather than SET.
//
// WHAT THIS DOES NOT, AND CANNOT, PROVE: like setOrderIsLoadBearing, this
// is a real but PARTIAL safeguard. It cannot see whether a factor's
// fragment quietly changes CONTENT (not just presence) depending on another
// factor - the same class of interaction message.go's
// buildApplyPatchMessageCoreUpdate and session.go's buildPatchSessionUpdate
// needed a human read to catch (Locationid gating Lat/Lng via a live DB
// read; Displayname gating Firstname/Lastname's NULL fragments). Declaring
// a factor as a GROUP with enumerated forms is how that class of
// interaction gets represented once found by reading the code - exactly as
// FieldwiseCase's doc comment already establishes for SET lists - not
// something this check discovers on its own. Authors still have to read
// the original code's branches before declaring factors; this check only
// catches the mechanical class of mistake (a declared fragment that does
// not actually appear in "all", or vice versa), not a false independence
// claim dressed up in a self-consistent declaration.

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"strings"
	"sync"

	"gorm.io/gorm"
)

// WhereFieldwiseCase is one case in a WHERE-fieldwise proof: either a
// single optional field's fragment(s) rendered alone, one FORM of a
// declared GROUP factor, the required "base" case (every optional factor
// absent - the statement still runs, unlike SET-fieldwise's "empty"), or
// the required "all" case (every field, and every group's declared
// AllForm, present together). Name must match a field name declared for
// this site in wherefieldwise.json, a "<group>:<form>" pair declared under
// that site's "groups", or be exactly "base" or "all".
type WhereFieldwiseCase struct {
	Name  string
	Build func(tx *gorm.DB) *gorm.DB
}

// whereGroupEntry is one GROUP factor's declaration: its enumerated forms,
// and which of those forms is the one used inside "all". AllForm must name
// a real key of Forms - checked at assertion time, not just assumed.
type whereGroupEntry struct {
	Forms   map[string]string `json:"forms"`
	AllForm string            `json:"allForm"`
}

// whereFieldwiseSiteEntry is one site's declaration in wherefieldwise.json.
type whereFieldwiseSiteEntry struct {
	// Fields maps each independently-optional field's name to the full
	// SELECT statement it alone would produce (every other field/group
	// absent).
	Fields map[string]string `json:"fields"`
	// Groups maps each GROUPED factor's name to its enumerated forms - see
	// whereGroupEntry. Optional - a site with no interacting-but-enumerable
	// factor needs none.
	Groups map[string]whereGroupEntry `json:"groups,omitempty"`
	// Base is the full statement with every optional field/group absent -
	// the WHERE-fieldwise analogue of SET-fieldwise's "empty", but a real
	// rendered statement rather than "no SQL" (see the package doc comment).
	Base string `json:"base"`
	// All is the full statement with every field, and every group's
	// declared AllForm, present together.
	All string `json:"all"`
}

type whereFieldwiseFile struct {
	Sites map[string]whereFieldwiseSiteEntry `json:"sites"`
}

var (
	whereFieldwiseOnce sync.Once
	whereFieldwiseData map[string]whereFieldwiseSiteEntry
	whereFieldwiseErr  error

	// whereFieldwisePathOverride mirrors fieldwisePathOverride (fieldwise.go)
	// for this package's own tests.
	whereFieldwisePathOverride string
)

//go:embed wherefieldwise.json
var embeddedWhereFieldwise []byte

// loadWhereFieldwise parses and caches wherefieldwise.json, preferring a
// test override when one is set. Mirrors loadFieldwise (fieldwise.go).
func loadWhereFieldwise() (map[string]whereFieldwiseSiteEntry, error) {
	whereFieldwiseOnce.Do(func() {
		raw := embeddedWhereFieldwise
		source := "embedded wherefieldwise.json"

		if whereFieldwisePathOverride != "" {
			source = whereFieldwisePathOverride
			var err error
			raw, err = os.ReadFile(whereFieldwisePathOverride)
			if err != nil {
				whereFieldwiseErr = fmt.Errorf("reading wherefieldwise data at %s: %w", whereFieldwisePathOverride, err)
				return
			}
		}

		var wf whereFieldwiseFile
		if err := json.Unmarshal(raw, &wf); err != nil {
			whereFieldwiseErr = fmt.Errorf("parsing %s: %w", source, err)
			return
		}
		whereFieldwiseData = wf.Sites
	})
	return whereFieldwiseData, whereFieldwiseErr
}

// resetWhereFieldwiseCacheForTest clears loadWhereFieldwise's cache. Only
// this package's own tests call this.
func resetWhereFieldwiseCacheForTest() {
	whereFieldwiseOnce = sync.Once{}
	whereFieldwiseData = nil
	whereFieldwiseErr = nil
}

// AssertGoldenWhereFieldwise proves a WHERE-fieldwise-coverage site the way
// described in this file's package doc comment: it verifies the
// concatenation precondition first (refusing the site outright if it
// fails), then requires cases to name exactly the declared fields, every
// form of every declared group, plus "base" and "all" - coverage checked in
// both directions, the same discipline AssertGoldenShapes and
// AssertGoldenFieldwise use.
func AssertGoldenWhereFieldwise(t TestingT, siteID string, cases []WhereFieldwiseCase) {
	t.Helper()

	all, err := loadWhereFieldwise()
	if err != nil {
		t.Fatalf("ormharness: %v", err)
	}

	site, ok := all[siteID]
	if !ok || (len(site.Fields) == 0 && len(site.Groups) == 0) || site.Base == "" || site.All == "" {
		t.Fatalf("ormharness: no where-fieldwise declaration for site %q in wherefieldwise.json; "+
			"AssertGoldenWhereFieldwise requires a declared field and/or group list (one entry per "+
			"independently-optional WHERE factor, derived from the original raw-SQL code) plus \"base\" "+
			"and \"all\" goldens before it can check anything against it", siteID)
		return
	}

	// PRECONDITION: verify "all" really is the base predicate AND-joined
	// with every declared field's fragment(s) and every group's AllForm
	// fragment(s) - see the package doc comment for what this checks and
	// what it cannot.
	if err := verifyWhereConcatenation(site); err != nil {
		t.Fatalf("ormharness: site %q: where-fieldwise concatenation precondition failed: %v\n"+
			"This means \"all\" is not simply the declared fragments AND-joined together, which is what "+
			"the whole n+2-cases argument depends on - either a declared fragment does not actually appear "+
			"in \"all\", \"all\" contains a fragment no declared factor accounts for, or a factor's own "+
			"fragment changes depending on another factor's presence. Declare exhaustive shapes with "+
			"AssertGoldenShapes instead, or leave the site raw.", siteID, err)
		return
	}

	declaredWant := make(map[string]string, len(site.Fields)+len(site.Groups)+1)
	for name, sql := range site.Fields {
		declaredWant[name] = sql
	}
	for group, g := range site.Groups {
		for form, sql := range g.Forms {
			declaredWant[groupCaseName(group, form)] = sql
		}
	}
	declaredWant["base"] = site.Base
	declaredWant["all"] = site.All

	declaredNames := make(map[string]bool, len(declaredWant))
	for name := range declaredWant {
		declaredNames[name] = true
	}

	suppliedNames := make(map[string]bool, len(cases))
	for _, c := range cases {
		suppliedNames[c.Name] = true
	}

	var missing, extra []string
	for name := range declaredNames {
		if !suppliedNames[name] {
			missing = append(missing, name)
		}
	}
	for _, c := range cases {
		if !declaredNames[c.Name] {
			extra = append(extra, c.Name)
		}
	}

	if len(missing) > 0 || len(extra) > 0 {
		sort.Strings(missing)
		sort.Strings(extra)
		var b strings.Builder
		fmt.Fprintf(&b, "ormharness: site %q: wherefieldwise.json declares %d field(s), %d group form(s) across %d group(s), plus base/all, the test supplies %d case(s)\n",
			siteID, len(site.Fields), len(declaredWant)-len(site.Fields)-2, len(site.Groups), len(cases))
		if len(missing) > 0 {
			fmt.Fprintf(&b, "  declared but not covered by the test: %s\n", strings.Join(missing, ", "))
		}
		if len(extra) > 0 {
			fmt.Fprintf(&b, "  supplied by the test but not declared: %s\n", strings.Join(extra, ", "))
		}
		b.WriteString("  every declared field, group form (name \"<group>:<form>\"), base and all must be " +
			"covered, and every covered name must be a real declared one - see the AssertGoldenWhereFieldwise doc comment.")
		t.Fatalf("%s", b.String())
		return
	}

	for _, c := range cases {
		assertRenderedSQL(t, siteID, fmt.Sprintf(" field %q", c.Name), declaredWant[c.Name], "", c.Build)
	}
}

// verifyWhereConcatenation is the precondition check described in the
// package doc comment. Every declared entry (base, each field, each
// group's AllForm) is a FULL rendered statement, so each one's own WHERE
// fragment list ALSO contains base's fragment(s) inherited unchanged (e.g.
// "1=1") - a field golden is "the whole statement with only this field
// set", the same convention SET-fieldwise uses, not just the fragment in
// isolation. Counting every declared entry's fragments verbatim would
// therefore count base's own contribution once per entry rather than once
// overall. So each field/group's CONTRIBUTION is computed as its fragment
// list MINUS base's fragment list (a multiset subtraction: an occurrence
// inherited from base is not a contribution, an extra occurrence beyond
// what base already has is) - what is actually being checked is that "all"
// equals base's fragments, union each field's contribution, union each
// group's AllForm's contribution.
func verifyWhereConcatenation(site whereFieldwiseSiteEntry) error {
	allFrags, err := selectWhereFragments(site.All)
	if err != nil {
		return fmt.Errorf("\"all\": %w", err)
	}

	baseFrags, err := selectWhereFragments(site.Base)
	if err != nil {
		return fmt.Errorf("\"base\": %w", err)
	}

	want := map[string]int{}
	for _, f := range baseFrags {
		want[f]++
	}

	addContribution := func(label, sql string) error {
		frags, err := selectWhereFragments(sql)
		if err != nil {
			return fmt.Errorf("%s: %w", label, err)
		}
		remainingBase := map[string]int{}
		for _, f := range baseFrags {
			remainingBase[f]++
		}
		for _, f := range frags {
			if remainingBase[f] > 0 {
				// Inherited from base, not this entry's own contribution.
				remainingBase[f]--
				continue
			}
			want[f]++
		}
		return nil
	}

	for name, sql := range site.Fields {
		if err := addContribution(fmt.Sprintf("field %q", name), sql); err != nil {
			return err
		}
	}
	for group, g := range site.Groups {
		formSQL, ok := g.Forms[g.AllForm]
		if !ok {
			return fmt.Errorf("group %q: allForm %q is not one of its declared forms", group, g.AllForm)
		}
		if err := addContribution(fmt.Sprintf("group %q allForm %q", group, g.AllForm), formSQL); err != nil {
			return err
		}
	}

	got := map[string]int{}
	for _, f := range allFrags {
		got[f]++
	}

	var mismatches []string
	for f, n := range want {
		if got[f] != n {
			mismatches = append(mismatches, fmt.Sprintf("expected %d occurrence(s) of %q in \"all\", found %d", n, f, got[f]))
		}
	}
	for f, n := range got {
		if want[f] == 0 && n > 0 {
			mismatches = append(mismatches, fmt.Sprintf("\"all\" contains %q, which no declared field/group/base accounts for", f))
		}
	}
	if len(mismatches) > 0 {
		sort.Strings(mismatches)
		return fmt.Errorf("%s", strings.Join(mismatches, "; "))
	}
	return nil
}
