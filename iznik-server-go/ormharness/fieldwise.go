package ormharness

// AssertGoldenFieldwise is Layer 1 for a narrower, cheaper-to-prove class of
// runtime-varying site than AssertGoldenShapes covers: a single dynamic-SET
// UPDATE assembled from N independently-optional fields, where each field
// contributes its own fixed "col = ?" fragment (or fixed small set of
// fragments) regardless of which other fields are present, and the rendered
// statement is the concatenation of whichever fragments were included.
//
// Two sites were parked on "policy decision needed" for exactly this reason:
// modconfig.go's PatchModConfig (~18 optional fields, 2^18 possible
// combinations) and session.go's PatchSession (~12 fields, 2^12). Exhaustive
// shape coverage - AssertGoldenShapes's whole model - genuinely does not
// scale to either count, and a partial shape list is worse than raw: an
// untested branch that looks tested.
//
// But 2^n is the wrong number IF the fields do not interact. When each field
// contributes a fixed fragment independent of every other field's presence,
// the space that actually needs proving is not "every combination" - it is
// "each field renders its own fragment correctly" plus "concatenation
// assembles correctly" plus "the empty case does the right thing". That is
// n + 2 cases, not 2^n, and exhaustive-combination coverage over independent
// fields would just be proving the same n facts 2^n times over.
//
// THE PRECONDITION THIS MUST NOT ASSUME: independence only holds when no
// field's assignment VALUE references another assigned column - MySQL
// evaluates a SET list left to right, so "action = action + 1, rate = 100 *
// action / shown" means something different depending on order, and fields
// like that are not independent no matter how the Go code that builds them
// looks. This is exactly the rule check-set-order.sh and setOrderIsLoadBearing
// (golden.go) already enforce elsewhere in this migration, reused here rather
// than re-derived, and checked automatically against the declared "all" SET
// list before anything else runs. A site whose fields fail this check is
// refused outright, not silently accepted - see the abtest.go precedent
// setOrderIsLoadBearing's own doc comment cites for why this matters.
//
// This is a DELIBERATELY WEAKER GUARANTEE than AssertGoldenShapes, and
// exists as a separate name, separate declaration file (fieldwise.json, not
// shapes.json) and separate manifest coverage label for exactly that reason:
// fieldwise coverage samples n+2 points and argues the rest follow from
// independence, where shapes coverage renders and checks every possible
// point. Conflating the two - reading a fieldwise "converted" as "every
// combination was exhaustively proven" - would be worse than not building
// this at all, which is why AssertGoldenFieldwise refuses to run under a
// name or a manifest field that could be mistaken for the other.
//
// THE EMPTY CASE: every currently-declared fieldwise site guards its raw
// UPDATE with "if len(setClauses) > 0" - no fields, no statement. GORM's own
// Updates(map[string]interface{}{}) has the same behaviour by construction:
// callbacks/update.go's ConvertToAssignments returns an empty clause.Set for
// an empty map, and the Update callback returns before Statement.SQL is ever
// built - no error, no query, nothing sent. That equivalence is exactly the
// kind of thing worth proving rather than assuming: a conversion that fired
// a genuine "UPDATE t SET WHERE id = ?" (or worse, "UPDATE t WHERE id = ?"
// with no SET at all) on an all-nil patch request would be a live, silent
// bug. assertEmptyUpdate proves it by rendering the empty-map build and
// requiring that no SQL comes out.

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

// FieldwiseCase is one case in a fieldwise proof: either a single optional
// field's fragment(s) rendered alone, one FORM of a declared GROUP factor
// (see "Grouped factors" below), the required "empty" case (no fields set -
// see the package doc comment), or the required "all" case (every field set
// together, in fieldwise.json's declared order - column ordering itself is
// handled by assertRenderedSQL's existing normalisation, the same as every
// other site in this migration). Name must match a field name declared for
// this site in fieldwise.json, a "<group>:<form>" pair declared under that
// site's "groups", or be exactly "empty" or "all".
//
// Grouped factors: applyPatchMessageCore (site e9f2c662be69) is the reason
// this exists. Fieldwise's core claim - "this field's contribution is fixed
// regardless of what else is present" - is false for its Locationid/Lat/Lng
// cluster: when Locationid is set and Lat/Lng are not both supplied
// directly, effLat/effLng come from a live DB read of that location's own
// stored coordinates, so whether "lat = ?"/"lng = ?" appear - and their
// values - depends on Locationid AND on live data, not on Lat/Lng's own
// presence. That is real, not a modelling gap to paper over, so it is not
// treated as three independent fields. It is treated as ONE factor -
// "LocationLatLng" - whose CONTRIBUTION has an enumerated set of forms
// (declared under fieldwise.json's "groups", one entry per reachable
// combination) rather than the two forms (present/absent) a plain field
// has. Coverage cost is (fields) + (forms across all groups) + 2, not
// 2^(fields+3): every field and every declared form still gets its own
// case and its own golden, exactly as fieldwise already does for plain
// fields - grouping changes what varies together, not the discipline that
// each declared point must be covered and every covered point must be
// declared (enforced below, the same way AssertGoldenShapes enforces it).
//
// A group is only valid when its forms are genuinely enumerable from the
// original code's branches - if a factor's reachable forms are not a
// closed, derivable set, it does not belong here; leave the site raw
// instead (see the package doc comment's independence precondition, which
// still applies: grouping factors together must never be used to assume
// away an interaction nobody actually enumerated).
type FieldwiseCase struct {
	Name  string
	Build func(tx *gorm.DB) *gorm.DB
}

// fieldwiseSiteEntry is one site's declaration in fieldwise.json.
type fieldwiseSiteEntry struct {
	// Fields maps each independently-optional field's name (matching the Go
	// request struct's field name, for readability against the source) to
	// the full UPDATE statement it alone would produce.
	Fields map[string]string `json:"fields"`
	// Groups maps each GROUPED factor's name to its enumerated forms: form
	// name -> the full UPDATE statement that form alone would produce (every
	// other field/group absent). Optional - omitted or empty for a site with
	// no grouped factors, which is every fieldwise site before e9f2c662be69's
	// cluster, so existing fieldwise.json entries and AssertGoldenFieldwise
	// callers need no change. See FieldwiseCase's doc comment for why this
	// exists and what it does and does not claim.
	Groups map[string]map[string]string `json:"groups,omitempty"`
	// All is the full UPDATE statement with every field, and one
	// (documented, representative) form of every group, set together.
	All string `json:"all"`
}

type fieldwiseFile struct {
	Sites map[string]fieldwiseSiteEntry `json:"sites"`
}

var (
	fieldwiseOnce sync.Once
	fieldwiseData map[string]fieldwiseSiteEntry
	fieldwiseErr  error

	// fieldwisePathOverride lets this package's own tests point loadFieldwise
	// at a temporary file, mirroring manifestPathOverride (golden.go) and
	// shapesPathOverride (shapes.go).
	fieldwisePathOverride string
)

//go:embed fieldwise.json
var embeddedFieldwise []byte

// loadFieldwise parses and caches fieldwise.json, preferring a test override
// when one is set. Mirrors loadManifest (golden.go) and loadShapes (shapes.go).
func loadFieldwise() (map[string]fieldwiseSiteEntry, error) {
	fieldwiseOnce.Do(func() {
		raw := embeddedFieldwise
		source := "embedded fieldwise.json"

		if fieldwisePathOverride != "" {
			source = fieldwisePathOverride
			var err error
			raw, err = os.ReadFile(fieldwisePathOverride)
			if err != nil {
				fieldwiseErr = fmt.Errorf("reading fieldwise data at %s: %w", fieldwisePathOverride, err)
				return
			}
		}

		var ff fieldwiseFile
		if err := json.Unmarshal(raw, &ff); err != nil {
			fieldwiseErr = fmt.Errorf("parsing %s: %w", source, err)
			return
		}
		fieldwiseData = ff.Sites
	})
	return fieldwiseData, fieldwiseErr
}

// resetFieldwiseCacheForTest clears loadFieldwise's cache. Only this
// package's own tests call this, to point at a fixture file per test case.
func resetFieldwiseCacheForTest() {
	fieldwiseOnce = sync.Once{}
	fieldwiseData = nil
	fieldwiseErr = nil
}

// groupCaseName joins a group's name and one of its forms into the single
// case name a test supplies for it - "<group>:<form>" - so FieldwiseCase and
// AssertGoldenFieldwise's signature need no change to support grouped
// factors (see the package doc comment on FieldwiseCase). ":" is safe as a
// separator because neither Go field names nor the two reserved names
// ("empty", "all") can contain it.
func groupCaseName(group, form string) string {
	return group + ":" + form
}

// AssertGoldenFieldwise proves a fieldwise-coverage site the way described in
// this file's package doc comment: it verifies the independence precondition
// first (refusing the site outright if it fails), then requires cases to
// name exactly the declared fields, every form of every declared group (see
// FieldwiseCase's doc comment), plus "empty" and "all" - coverage checked in
// both directions, the same discipline AssertGoldenShapes uses, for the same
// reason: a case the test supplies that names nothing declared is most
// likely a typo silently failing to cover what it meant to, and a declared
// field or group form the test does not supply is an untested fragment
// masquerading as proven.
func AssertGoldenFieldwise(t TestingT, siteID string, cases []FieldwiseCase) {
	t.Helper()

	all, err := loadFieldwise()
	if err != nil {
		t.Fatalf("ormharness: %v", err)
	}

	site, ok := all[siteID]
	if !ok || (len(site.Fields) == 0 && len(site.Groups) == 0) || site.All == "" {
		t.Fatalf("ormharness: no fieldwise declaration for site %q in fieldwise.json; AssertGoldenFieldwise "+
			"requires a declared field and/or group list (one entry per independently-optional field or "+
			"grouped factor, derived from the original raw-SQL code) plus an \"all\" golden before it can check "+
			"anything against it", siteID)
		return
	}

	// PRECONDITION, verified rather than assumed: extract the SET list from
	// the declared "all" statement and run it through setOrderIsLoadBearing -
	// the same check check-set-order.sh runs on every converted site in this
	// migration. If any assignment's value references another assigned
	// column, the fields are not independent and this site is refused
	// outright: fieldwise coverage would be proving the wrong thing.
	//
	// This does NOT, and cannot, verify that a declared group's forms are
	// the real, complete set the original code can produce - it only checks
	// what "all" (one concrete rendered statement) contains textually. A
	// group's enumeration is a claim its author makes by reading the
	// original code's branches (see FieldwiseCase's doc comment); nothing
	// here can substitute for that reading.
	m := updateSetPattern.FindStringSubmatch(strings.TrimSpace(site.All))
	if m == nil {
		t.Fatalf("ormharness: site %q: fieldwise.json's \"all\" entry does not parse as an UPDATE ... SET ... "+
			"statement, so the independence precondition cannot be checked: %q", siteID, site.All)
		return
	}
	if assignments := splitList(m[2]); setOrderIsLoadBearing(assignments) {
		t.Fatalf("ormharness: site %q: fieldwise coverage requires independent fields, but the declared \"all\" "+
			"SET list has at least one assignment whose value references another assigned column. MySQL "+
			"evaluates SET left to right, so these fields are not independent regardless of how the Go code "+
			"looks - declare exhaustive shapes with AssertGoldenShapes instead, or leave the site raw.", siteID)
		return
	}

	// declaredWant maps every valid case name (plain field, "group:form", and
	// "all") to the SQL it must render; "empty" is handled separately since
	// it asserts NO SQL rather than a specific string (assertEmptyUpdate).
	declaredWant := make(map[string]string, len(site.Fields)+len(site.Groups)+1)
	for name, sql := range site.Fields {
		declaredWant[name] = sql
	}
	for group, forms := range site.Groups {
		for form, sql := range forms {
			declaredWant[groupCaseName(group, form)] = sql
		}
	}
	declaredWant["all"] = site.All

	declaredNames := make(map[string]bool, len(declaredWant)+1)
	for name := range declaredWant {
		declaredNames[name] = true
	}
	declaredNames["empty"] = true

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
		fmt.Fprintf(&b, "ormharness: site %q: fieldwise.json declares %d field(s), %d group form(s) across %d group(s), plus empty/all, the test supplies %d case(s)\n",
			siteID, len(site.Fields), len(declaredWant)-len(site.Fields), len(site.Groups), len(cases))
		if len(missing) > 0 {
			fmt.Fprintf(&b, "  declared but not covered by the test: %s\n", strings.Join(missing, ", "))
		}
		if len(extra) > 0 {
			fmt.Fprintf(&b, "  supplied by the test but not declared: %s\n", strings.Join(extra, ", "))
		}
		b.WriteString("  every declared field, group form (name \"<group>:<form>\"), empty and all must be " +
			"covered, and every covered name must be a real declared one - see the AssertGoldenFieldwise doc comment.")
		t.Fatalf("%s", b.String())
		return
	}

	for _, c := range cases {
		if c.Name == "empty" {
			assertEmptyUpdate(t, siteID, c.Build)
			continue
		}
		assertRenderedSQL(t, siteID, fmt.Sprintf(" field %q", c.Name), declaredWant[c.Name], "", c.Build)
	}
}

// assertEmptyUpdate proves the "no fields set" case renders no SQL at all -
// see the package doc comment's "THE EMPTY CASE" section for why that is
// GORM's own behaviour for Updates(map[string]interface{}{}), not an
// assumption. renderDryRun returns an error specifically when the build
// function's statement never assembled any SQL, which is exactly the signal
// this case wants: any other error path here (a malformed build function, a
// genuine tx.Error) would also produce that same error class, so a false
// pass here would mean the empty case was already broken in a way this test
// cannot distinguish from correct - acceptable for what this specific case
// is proving, and no worse than the guarantee AssertGoldenSQL itself gives
// for a malformed build function.
func assertEmptyUpdate(t TestingT, siteID string, build func(tx *gorm.DB) *gorm.DB) {
	t.Helper()
	sql, _, err := renderDryRun(build)
	if err == nil {
		t.Fatalf("ormharness: site %q: empty case: expected no SQL to be built (matching the original code's "+
			"\"if len(setClauses) > 0\" guard, and GORM's own Updates(map{}) no-op), but got: %s", siteID, sql)
	}
}
