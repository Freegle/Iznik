package ormharness

// Layer 2 of the ORM migration harness (plan section 7.2): result parity on
// fixtures. Layer 1 (canonical.go, golden.go - owned elsewhere in this
// package) proves the new GORM call renders equivalent SQL text. This file
// proves it independently, by actually running both the old raw SQL and the
// new GORM call against the seeded test database and diffing the result
// sets. That catches what string comparison cannot: collation, NULL
// handling and implicit-cast semantics that only show up once the query
// hits the engine.

import (
	"database/sql"
	"fmt"
	"reflect"
	"regexp"
	"sort"
	"strings"
	"testing"

	"gorm.io/gorm"
)

// ReplacementQuery builds, but does not execute, the GORM replacement for a
// raw SQL call site under test - e.g.
//
//	func(tx *gorm.DB) *gorm.DB {
//		return tx.Table("users").Select("id, email").Where("id = ?", 5)
//	}
//
// It must stop short of a terminal method (Find, First, Scan, Row, Rows...):
// AssertResultParity calls Rows() itself so that it can scan both result
// sets through the same generic path.
type ReplacementQuery func(tx *gorm.DB) *gorm.DB

// resultRow is one scanned row, keyed by column name. Values come straight
// out of database/sql with no destination type imposed, so a NULL is a true
// nil, and the concrete Go type reflects whatever the driver decided the
// column's SQL type maps to (int64, float64, []byte, time.Time...). That is
// what makes the comparison NULL-sensitive and cast-sensitive: a NULL can
// never equal "" or 0, and a column that comes back as []byte on one side
// but int64 on the other (an implicit-cast divergence) fails reflect.DeepEqual
// even when its printed form looks the same.
type resultRow map[string]any

// orderByPattern flags a statement as having its own ordering. It is
// deliberately simple (the plan's wording is "contains ORDER BY"): a
// case-insensitive word-boundary match is enough for real call-site SQL,
// and false positives inside a string literal would only make the harness
// stricter (order-sensitive) rather than silently lenient.
var orderByPattern = regexp.MustCompile(`(?is)\border\s+by\b`)

// AssertResultParity runs originalSQL (with args) and replacement against
// db and fails t if the two result sets are not identical: same columns
// (as a set), same row count, and every value equal including its NULL-ness
// and its Go type. When originalSQL contains ORDER BY the rows are compared
// position by position, since the two implementations are then contractually
// obliged to agree on order; otherwise both result sets are sorted into a
// shared canonical order before comparing, because without ORDER BY the
// engine is free to return rows in any order and the old and new query need
// not pick the same one.
func AssertResultParity(t *testing.T, db *gorm.DB, originalSQL string, args []any, replacement ReplacementQuery) {
	t.Helper()

	originalCols, originalRows, err := runRawSQL(db, originalSQL, args)
	if err != nil {
		t.Fatalf("ormharness: original SQL failed: %v\nSQL: %s", err, originalSQL)
	}

	replacementCols, replacementRows, err := runReplacementQuery(db, replacement)
	if err != nil {
		t.Fatalf("ormharness: replacement GORM call failed: %v", err)
	}

	assertSameColumnSet(t, originalCols, replacementCols)

	if orderByPattern.MatchString(originalSQL) {
		assertRowsEqualOrdered(t, originalCols, originalRows, replacementRows)
	} else {
		assertRowsEqualUnordered(t, originalCols, originalRows, replacementRows)
	}
}

// runRawSQL executes the golden raw SQL through GORM's Raw/Rows path (the
// same driver, connection pool and dbresolver routing as the ORM side gets),
// so any difference found downstream is attributable to the query, not to
// how it was run.
func runRawSQL(db *gorm.DB, sqlText string, args []any) ([]string, []resultRow, error) {
	rows, err := db.Raw(sqlText, args...).Rows()
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()

	return scanRowsGenerically(rows)
}

// runReplacementQuery executes the built-but-unexecuted GORM chain the
// caller supplied.
func runReplacementQuery(db *gorm.DB, replacement ReplacementQuery) ([]string, []resultRow, error) {
	rows, err := replacement(db).Rows()
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()

	return scanRowsGenerically(rows)
}

// scanRowsGenerically reads every row of rows into a resultRow keyed by
// column name, without knowing the shape of the query in advance. Scanning
// into *any destinations (rather than a typed struct or sql.RawBytes) makes
// database/sql's MySQL driver hand back its native representation per
// column - nil for NULL, int64/float64/[]byte/time.Time otherwise - which is
// exactly the type-preserving, NULL-preserving behaviour Layer 2 needs and
// works for any site's result shape with no per-site scaffolding.
func scanRowsGenerically(rows *sql.Rows) ([]string, []resultRow, error) {
	cols, err := rows.Columns()
	if err != nil {
		return nil, nil, err
	}

	var out []resultRow
	for rows.Next() {
		raw := make([]any, len(cols))
		ptrs := make([]any, len(cols))
		for i := range raw {
			ptrs[i] = &raw[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil, nil, err
		}

		row := make(resultRow, len(cols))
		for i, c := range cols {
			row[c] = raw[i]
		}
		out = append(out, row)
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}

	return cols, out, nil
}

// assertSameColumnSet fails t if the two queries did not select the same
// columns. Compared as a set (sorted) rather than positionally: Layer 1
// already pins the SQL text (and therefore column order) for converted
// sites, so Layer 2's job is to catch data divergence, not re-litigate
// column ordering.
func assertSameColumnSet(t *testing.T, originalCols, replacementCols []string) {
	t.Helper()

	a := append([]string(nil), originalCols...)
	b := append([]string(nil), replacementCols...)
	sort.Strings(a)
	sort.Strings(b)

	if !reflect.DeepEqual(a, b) {
		t.Fatalf("ormharness: column sets differ\n  original:    %v\n  replacement: %v", originalCols, replacementCols)
	}
}

// assertRowsEqualOrdered compares two result sets position by position.
func assertRowsEqualOrdered(t *testing.T, cols []string, original, replacement []resultRow) {
	t.Helper()

	if len(original) != len(replacement) {
		t.Fatalf("ormharness: row count differs (order-sensitive comparison): original=%d replacement=%d", len(original), len(replacement))
	}

	for i := range original {
		assertRowEqual(t, cols, fmt.Sprintf("row %d", i), original[i], replacement[i])
	}
}

// assertRowsEqualUnordered compares two result sets as multisets: both are
// sorted into the same canonical order (a deterministic string encoding of
// each row, so equal rows always sort adjacently and identically on both
// sides) before comparing position by position. This is only a sort key,
// not the equality test itself - assertRowEqual still does the real,
// type-and-NULL-sensitive comparison once the rows are lined up.
func assertRowsEqualUnordered(t *testing.T, cols []string, original, replacement []resultRow) {
	t.Helper()

	if len(original) != len(replacement) {
		t.Fatalf("ormharness: row count differs (unordered comparison): original=%d replacement=%d", len(original), len(replacement))
	}

	sortedOriginal := sortRowsCanonically(cols, original)
	sortedReplacement := sortRowsCanonically(cols, replacement)

	for i := range sortedOriginal {
		assertRowEqual(t, cols, fmt.Sprintf("row %d (canonical order, no ORDER BY on the original query)", i), sortedOriginal[i], sortedReplacement[i])
	}
}

func sortRowsCanonically(cols []string, rows []resultRow) []resultRow {
	out := append([]resultRow(nil), rows...)
	sort.SliceStable(out, func(i, j int) bool {
		return canonicalRowKey(cols, out[i]) < canonicalRowKey(cols, out[j])
	})
	return out
}

// canonicalRowKey renders a row as a single string for sorting purposes
// only - it does not need to be collision-free between genuinely different
// rows in general, only stable and applied identically to both result sets,
// which is enough to line up two multisets that are actually permutations
// of each other. formatValue's NULL/type distinctions carry through, so
// e.g. a NULL score and a zero score still sort (and later compare)
// differently.
func canonicalRowKey(cols []string, row resultRow) string {
	var b strings.Builder
	for _, c := range cols {
		b.WriteString(c)
		b.WriteByte('=')
		b.WriteString(formatValue(row[c]))
		b.WriteByte('\x1f') // unit separator: cheap, unlikely-to-collide field delimiter
	}
	return b.String()
}

// assertRowEqual compares a single row's values column by column, using
// reflect.DeepEqual so that a NULL (nil) never matches "" or 0, and a value
// that came back as a different Go type on each side (an implicit-cast
// divergence) is caught even when the two would print the same.
func assertRowEqual(t *testing.T, cols []string, rowLabel string, original, replacement resultRow) {
	t.Helper()

	for _, c := range cols {
		originalValue, replacementValue := original[c], replacement[c]
		if !reflect.DeepEqual(originalValue, replacementValue) {
			t.Fatalf("ormharness: %s, column %q differs:\n  original:    %s\n  replacement: %s",
				rowLabel, c, formatValue(originalValue), formatValue(replacementValue))
		}
	}
}

// formatValue renders a scanned column value for failure messages (and as
// the building block for canonicalRowKey), keeping NULL visually distinct
// from an empty string or a zero value.
func formatValue(v any) string {
	if v == nil {
		return "NULL"
	}

	if b, ok := v.([]byte); ok {
		return fmt.Sprintf("%q (%T)", string(b), v)
	}

	return fmt.Sprintf("%v (%T)", v, v)
}
