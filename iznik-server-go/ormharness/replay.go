package ormharness

// Layer 4 of the ORM migration harness (plan section 7.2): write parity by
// replay. Writes are never shadow-run live (section 5's own words: "writes
// are never shadow-run live" - a live INSERT/UPDATE/DELETE cannot safely be
// speculatively run twice against production data). Instead this file
// provides three pieces of tooling used around a "yesterday"-restore replay:
//
//   - AssertPlanParity: EXPLAIN FORMAT=TREE plan-shape comparison, for CI on
//     manifest sites tagged "hot".
//   - RunUpsertParity: an integration-test scaffold for the specific known
//     trap that GORM's clause.OnConflict mishandles a WHERE condition on the
//     conflict target on some MySQL driver versions (go-gorm/gorm#4355,
//     go-gorm/mysql#39).
//   - DiffTables: a read-only row-level diff between two database copies,
//     for comparing the old write path (run against copy A) with the new
//     write path (run against copy B) after both are replayed from the same
//     "yesterday" snapshot.
//
// The two-database orchestration itself - restoring a snapshot twice,
// running the old path against A and the new path against B - is a runbook
// step (docs/developers/reference/orm-migration-harness.md), not code here:
// it depends on provisioning temporary infrastructure this package has no
// business owning, and the plan is explicit that the actual replay is "a
// thin caller", with DiffTables doing the comparison.

import (
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strings"

	"gorm.io/gorm"
)

// ExplainTree runs `EXPLAIN FORMAT=TREE` for sql/args against db and returns
// the plan text. MySQL calls the single result column "EXPLAIN", but column
// naming for this statement form is not part of any stability guarantee, so
// this scans generically and takes whichever column comes back as a string
// rather than pinning the name.
func ExplainTree(db *gorm.DB, sql string, args ...any) (string, error) {
	var rows []map[string]any
	if err := db.Raw("EXPLAIN FORMAT=TREE "+sql, args...).Scan(&rows).Error; err != nil {
		return "", fmt.Errorf("ormharness: EXPLAIN FORMAT=TREE failed: %w", err)
	}
	if len(rows) == 0 {
		return "", fmt.Errorf("ormharness: EXPLAIN FORMAT=TREE returned no rows for %q", sql)
	}

	for _, v := range rows[0] {
		switch val := v.(type) {
		case string:
			return val, nil
		case []byte:
			return string(val), nil
		}
	}
	return "", fmt.Errorf("ormharness: EXPLAIN FORMAT=TREE returned no string column for %q", sql)
}

// replayEstimatePattern matches the cost/row/timing estimates inside an
// EXPLAIN FORMAT=TREE line, e.g. "(cost=1.25 rows=3)" or, under ANALYZE,
// "(actual time=0.05..0.12 rows=3 loops=1)".
var replayEstimatePattern = regexp.MustCompile(`(cost|rows|actual time|loops)=[0-9]+(\.[0-9]+)?(\.\.[0-9]+(\.[0-9]+)?)?`)

// NormalizeExplainPlan replaces the numeric estimates in an EXPLAIN
// FORMAT=TREE plan with a fixed placeholder, so two plans that are
// structurally identical - same operations, same tables, same indexes, same
// join order - compare equal even though the underlying statistics
// legitimately drift between the old and new statement (different literal
// values in the query, table stats updated between runs, ANALYZE timing
// jitter). Trailing whitespace per line is also trimmed for the same
// "ignore drift that isn't a real plan-shape difference" reason.
func NormalizeExplainPlan(tree string) string {
	normalized := replayEstimatePattern.ReplaceAllStringFunc(tree, func(m string) string {
		name := strings.SplitN(m, "=", 2)[0]
		return name + "=N"
	})

	lines := strings.Split(normalized, "\n")
	for i, line := range lines {
		lines[i] = strings.TrimRight(line, " \t\r")
	}
	return strings.Join(lines, "\n")
}

// PlanParityReport is the result of AssertPlanParity: both plans as MySQL
// returned them, both after NormalizeExplainPlan, and whether the
// normalised forms match.
type PlanParityReport struct {
	OldPlan           string
	NewPlan           string
	NormalizedOldPlan string
	NormalizedNewPlan string
	Equal             bool
}

// Diff returns a readable side-by-side of the normalised plans, or "" if
// they matched.
func (r *PlanParityReport) Diff() string {
	if r.Equal {
		return ""
	}
	return fmt.Sprintf("old plan:\n%s\n\nnew plan:\n%s", r.NormalizedOldPlan, r.NormalizedNewPlan)
}

// AssertPlanParity runs EXPLAIN FORMAT=TREE for the old raw-SQL statement
// and the new GORM statement and returns a report plus a non-nil
// error if their normalised shapes differ. Intended for CI on manifest sites
// tagged "hot" (plan 7.2, Layer 4): a plan-shape regression on a hot path -
// a dropped index, an accidental full scan - is worth catching even though
// layers 1-2 only look at SQL text and result rows, not the execution plan.
//
// newQuery is rendered to literal SQL via db.ToSQL before EXPLAIN runs, so
// the new statement never actually executes. Because ToSQL renders in dry-run
// mode, newQuery MUST end in a terminal call (Find, Count, Create, Delete,
// Update, Take, First) or GORM assembles nothing. Note Scan does not work:
// GORM rejects it under dry-run. This is the opposite of resultparity.go's
// ReplacementQuery, which must stop short of a terminal call because
// AssertResultParity really executes and calls Rows() itself.
func AssertPlanParity(db *gorm.DB, oldSQL string, oldArgs []any, newQuery func(tx *gorm.DB) *gorm.DB) (*PlanParityReport, error) {
	oldPlan, err := ExplainTree(db, oldSQL, oldArgs...)
	if err != nil {
		return nil, fmt.Errorf("ormharness: old statement: %w", err)
	}

	newSQL := db.ToSQL(newQuery)
	if strings.TrimSpace(newSQL) == "" {
		// Same trap as RenderDryRunSQL guards: ToSQL renders in dry-run mode,
		// and GORM only assembles a statement when a terminal call runs. An
		// empty render would otherwise reach EXPLAIN and come back as a MySQL
		// syntax error "near ''", which names nothing useful.
		return nil, fmt.Errorf("ormharness: newQuery produced no SQL: it must end in a terminal call " +
			"(Find, Count, Create, Delete, Update, Take, First). Scan is NOT usable here, " +
			"since GORM rejects it in dry-run mode")
	}

	newPlan, err := ExplainTree(db, newSQL)
	if err != nil {
		return nil, fmt.Errorf("ormharness: new statement: %w", err)
	}

	normOld := NormalizeExplainPlan(oldPlan)
	normNew := NormalizeExplainPlan(newPlan)

	report := &PlanParityReport{
		OldPlan:           oldPlan,
		NewPlan:           newPlan,
		NormalizedOldPlan: normOld,
		NormalizedNewPlan: normNew,
		Equal:             normOld == normNew,
	}
	if !report.Equal {
		return report, fmt.Errorf("ormharness: plan parity mismatch:\n%s", report.Diff())
	}
	return report, nil
}

// UpsertCase describes one upsert-parity scenario for the GORM
// clause.OnConflict WHERE-on-conflict-target trap (go-gorm/gorm#4355,
// go-gorm/mysql#39): a WHERE condition intended to make the DO UPDATE SET
// conditional is silently mishandled on some MySQL driver versions, so a
// site that relies on it can be correct on the old raw
// "ON DUPLICATE KEY UPDATE col = IF(condition, new_value, col)" idiom (MySQL
// has no native WHERE there, hence the IF/CASE) and silently wrong once
// converted. A real site normally needs at least two cases: one where the
// condition holds (the row should change) and one where it does not (the
// row should stay as seeded) - RunUpsertParity only proves the two paths
// agree for whatever seed/condition this case encodes, so both matter.
type UpsertCase struct {
	// Name identifies the scenario in failure messages, e.g.
	// "condition-holds" / "condition-fails".
	Name string
	// Seed writes the pre-upsert row(s) into db. Called once per side (old,
	// new) so the two sides start from identical, independent state.
	Seed func(db *gorm.DB) error
	// Old performs the upsert via the existing raw SQL.
	Old func(db *gorm.DB) error
	// New performs the upsert via the GORM clause.OnConflict replacement
	// under test.
	New func(db *gorm.DB) error
	// Snapshot reads back whatever the upsert should have changed, in
	// whatever shape is convenient for the site (a struct, a map, a scalar).
	// It is compared via encoding/json, so nullable fields should be
	// pointers - see ormshadow.ShadowRowDigest's doc comment for why.
	Snapshot func(db *gorm.DB) (any, error)
}

// RunUpsertParity seeds, runs and snapshots an UpsertCase against oldDB and
// newDB independently (two connections - to two isolated schemas/databases
// for a real cross-driver run, or to the same test database under this
// codebase's usual parallel-test isolation for a same-dialect smoke test -
// see iznik-server-go/test/main_test.go) and returns an error describing any
// divergence between the two resulting snapshots. It does not depend on
// *testing.T, so it works equally from a Go test (t.Fatal(err) on failure)
// or non-test CI tooling.
func RunUpsertParity(oldDB, newDB *gorm.DB, tc UpsertCase) error {
	if err := tc.Seed(oldDB); err != nil {
		return fmt.Errorf("ormharness: upsert case %q: seeding old side: %w", tc.Name, err)
	}
	if err := tc.Seed(newDB); err != nil {
		return fmt.Errorf("ormharness: upsert case %q: seeding new side: %w", tc.Name, err)
	}
	if err := tc.Old(oldDB); err != nil {
		return fmt.Errorf("ormharness: upsert case %q: old path: %w", tc.Name, err)
	}
	if err := tc.New(newDB); err != nil {
		return fmt.Errorf("ormharness: upsert case %q: new path: %w", tc.Name, err)
	}

	oldSnapshot, err := tc.Snapshot(oldDB)
	if err != nil {
		return fmt.Errorf("ormharness: upsert case %q: snapshotting old side: %w", tc.Name, err)
	}
	newSnapshot, err := tc.Snapshot(newDB)
	if err != nil {
		return fmt.Errorf("ormharness: upsert case %q: snapshotting new side: %w", tc.Name, err)
	}

	oldJSON, err := json.Marshal(oldSnapshot)
	if err != nil {
		return fmt.Errorf("ormharness: upsert case %q: encoding old snapshot: %w", tc.Name, err)
	}
	newJSON, err := json.Marshal(newSnapshot)
	if err != nil {
		return fmt.Errorf("ormharness: upsert case %q: encoding new snapshot: %w", tc.Name, err)
	}

	if string(oldJSON) != string(newJSON) {
		return fmt.Errorf("ormharness: upsert case %q: old and new paths diverged:\nold: %s\nnew: %s", tc.Name, oldJSON, newJSON)
	}
	return nil
}

// TableDiff describes one row-level difference found by DiffTables between
// two "yesterday"-restored database copies. Key holds the table's
// primary-key column values (discovered from information_schema, not
// hardcoded, so the caller does not need to know each table's shape).
type TableDiff struct {
	Table string
	Key   map[string]any
	InA   map[string]any // nil if the row is absent from copy A (old write path).
	InB   map[string]any // nil if the row is absent from copy B (new write path).
}

// replayValidIdentifier guards the table names DiffTables interpolates into
// a `SELECT * FROM `table“ (table names cannot be bind parameters in
// MySQL). Table names here come from the replay runbook's own
// configuration, not end-user input, but there is no reason not to check.
var replayValidIdentifier = regexp.MustCompile(`^[A-Za-z0-9_]+$`)

// replayDefaultMaxRows bounds how many rows DiffTables reads per table, per
// side, as a guard against accidentally pointing it at an unbounded table.
// The replay runbook scopes `tables` to what a single write actually
// touches, which should be nowhere near this in practice.
const replayDefaultMaxRows = 200000

// DiffTables compares the given tables between copy A (old write path
// output) and copy B (new write path output) and returns, per table, up to
// maxDiffsPerTable row-level differences in a deterministic order (sorted by
// primary key). It is read-only: see this file's top-of-file comment for why
// the two-database provisioning itself is a runbook step, not here.
func DiffTables(a, b *gorm.DB, tables []string, maxDiffsPerTable int) ([]TableDiff, error) {
	var diffs []TableDiff

	for _, table := range tables {
		pk, err := replayPrimaryKeyColumns(a, table)
		if err != nil {
			return diffs, fmt.Errorf("ormharness: table %q: %w", table, err)
		}
		if len(pk) == 0 {
			return diffs, fmt.Errorf("ormharness: table %q has no primary key - DiffTables needs one to key rows", table)
		}

		rowsA, err := replayFetchTableRows(a, table, replayDefaultMaxRows)
		if err != nil {
			return diffs, fmt.Errorf("ormharness: table %q: reading copy A: %w", table, err)
		}
		rowsB, err := replayFetchTableRows(b, table, replayDefaultMaxRows)
		if err != nil {
			return diffs, fmt.Errorf("ormharness: table %q: reading copy B: %w", table, err)
		}

		byKeyA := replayIndexByKey(rowsA, pk)
		byKeyB := replayIndexByKey(rowsB, pk)

		allKeys := make(map[string]struct{}, len(byKeyA)+len(byKeyB))
		for k := range byKeyA {
			allKeys[k] = struct{}{}
		}
		for k := range byKeyB {
			allKeys[k] = struct{}{}
		}
		sortedKeys := make([]string, 0, len(allKeys))
		for k := range allKeys {
			sortedKeys = append(sortedKeys, k)
		}
		sort.Strings(sortedKeys)

		found := 0
		for _, key := range sortedKeys {
			if found >= maxDiffsPerTable {
				break
			}
			rowA, inA := byKeyA[key]
			rowB, inB := byKeyB[key]
			if inA && inB && replayRowsEqual(rowA, rowB) {
				continue
			}

			var keySample map[string]any
			var recA, recB map[string]any
			if inA {
				keySample = replayKeyValues(rowA, pk)
				recA = rowA
			}
			if inB {
				if keySample == nil {
					keySample = replayKeyValues(rowB, pk)
				}
				recB = rowB
			}

			diffs = append(diffs, TableDiff{Table: table, Key: keySample, InA: recA, InB: recB})
			found++
		}
	}

	return diffs, nil
}

// replayPrimaryKeyColumns discovers table's primary-key columns via
// information_schema, in ordinal order, so DiffTables works for any table
// without per-table configuration.
func replayPrimaryKeyColumns(db *gorm.DB, table string) ([]string, error) {
	var cols []string
	err := db.Raw(
		`SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
		 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
		 ORDER BY ORDINAL_POSITION`, table).Pluck("COLUMN_NAME", &cols).Error
	return cols, err
}

func replayFetchTableRows(db *gorm.DB, table string, maxRows int) ([]map[string]any, error) {
	if !replayValidIdentifier.MatchString(table) {
		return nil, fmt.Errorf("invalid table name %q", table)
	}
	var rows []map[string]any
	err := db.Raw(fmt.Sprintf("SELECT * FROM `%s` LIMIT ?", table), maxRows).Scan(&rows).Error
	return rows, err
}

func replayIndexByKey(rows []map[string]any, pk []string) map[string]map[string]any {
	index := make(map[string]map[string]any, len(rows))
	for _, row := range rows {
		index[replayRowKey(row, pk)] = row
	}
	return index
}

// replayRowKey builds a stable string key from a row's primary-key column
// values. json.Marshal on a fixed-order slice (rather than the row map
// itself) keeps composite keys correctly ordered and NULL-distinct.
func replayRowKey(row map[string]any, pk []string) string {
	values := make([]any, len(pk))
	for i, col := range pk {
		values[i] = row[col]
	}
	encoded, _ := json.Marshal(values) // Primary-key values are always plain scalars.
	return string(encoded)
}

func replayKeyValues(row map[string]any, pk []string) map[string]any {
	key := make(map[string]any, len(pk))
	for _, col := range pk {
		key[col] = row[col]
	}
	return key
}

// replayRowsEqual compares two rows for exact equality, NULL-distinctly,
// via the same json.Marshal approach as ormshadow.ShadowRowDigest (map keys are
// marshalled in sorted order by encoding/json, so field order never causes
// a false difference).
func replayRowsEqual(a, b map[string]any) bool {
	encodedA, errA := json.Marshal(a)
	encodedB, errB := json.Marshal(b)
	if errA != nil || errB != nil {
		return false
	}
	return string(encodedA) == string(encodedB)
}

// FormatTableDiffs renders diffs as readable multi-line text for a CI log or
// runbook transcript.
func FormatTableDiffs(diffs []TableDiff) string {
	if len(diffs) == 0 {
		return "no differences"
	}

	var b strings.Builder
	for _, d := range diffs {
		fmt.Fprintf(&b, "%s key=%s\n", d.Table, replayMustJSON(d.Key))
		switch {
		case d.InA == nil:
			fmt.Fprintf(&b, "  only in copy B (new write path): %s\n", replayMustJSON(d.InB))
		case d.InB == nil:
			fmt.Fprintf(&b, "  only in copy A (old write path): %s\n", replayMustJSON(d.InA))
		default:
			fmt.Fprintf(&b, "  copy A (old write path): %s\n", replayMustJSON(d.InA))
			fmt.Fprintf(&b, "  copy B (new write path): %s\n", replayMustJSON(d.InB))
		}
	}
	return b.String()
}

func replayMustJSON(v any) string {
	encoded, err := json.Marshal(v)
	if err != nil {
		return fmt.Sprintf("%v", v)
	}
	return string(encoded)
}
