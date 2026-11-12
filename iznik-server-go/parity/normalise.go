package parity

// Comparison normalisations for Layer 1. Each one lets two statements that differ
// only in a way that cannot change what they do compare equal: the number of
// values in an IN list, the order of the columns in an INSERT or a SET list, and
// a bound LIMIT or OFFSET. Each is applied to both sides, never to one alone, and
// each declines where reordering would change the meaning.

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
)

// inListPattern matches an IN or NOT IN whose operand is a run of bind
// placeholders, with or without surrounding parentheses: "in ?", "in (?)",
// "in (?,?,?)". Deliberately narrow - an IN list of literals such as
// "in (1,2,3)" is left alone, because that is real content rather than an
// artefact of how many values happened to be bound.
var inListPattern = regexp.MustCompile(`(?i)\bin\s*(?:\(\s*\?(?:\s*,\s*\?)*\s*\)|\?)`)

// collapseInLists rewrites every placeholder IN list to the single canonical
// form "in ?", so a golden captured before slice expansion compares equal to a
// render captured after it. Applied to both sides of the comparison, never to
// one alone.
func collapseInLists(sql string) string {
	return inListPattern.ReplaceAllString(sql, "in ?")
}

// insertPrefixPattern matches only as far as the table name. The column list
// and the VALUES list are then scanned with a paren matcher rather than a
// regexp, because both routinely contain nested parentheses - "values (now(),
// ?, ?)" and "json_object('chatid', ?, 'userid', ?)" are ordinary here - and a
// character-class like [^()]* silently fails to match those.
//
// The table name is matched with [^\s(]+ rather than \S+ for the same reason:
// the codebase writes "insert into logs(timestamp, ...)" with no space, and a
// greedy \S+ swallows the opening paren.
//
// REPLACE is included alongside INSERT and INSERT IGNORE. A clause.Insert{Modifier: "REPLACE"}
// chain (database.RegisterCustomClauseBuilders) renders "REPLACE INTO t (cols) VALUES (...)", the
// same shape with a different leading keyword, and GORM sorts a map-valued Create's columns
// alphabetically whichever keyword renders. A hand-written golden rarely is, so the
// column-pairing-preserving reorder has to be attempted for it too.
var insertPrefixPattern = regexp.MustCompile(`(?is)^(?:insert(?:\s+ignore)?|replace)\s+into\s+[^\s(]+\s*\(`)

// updateSetPattern captures the SET list of an UPDATE, up to WHERE or the end.
var updateSetPattern = regexp.MustCompile(`(?is)^(update\s+.*?\sset\s+)(.*?)(\s+where\s.*|\s*)$`)

// matchParen returns the index of the parenthesis closing the one at open, or
// -1 if unbalanced. Quoted spans are skipped so a paren inside a string literal
// does not affect the depth.
func matchParen(s string, open int) int {
	depth := 0
	inQuote := byte(0)
	for i := open; i < len(s); i++ {
		c := s[i]
		if inQuote != 0 {
			if c == inQuote {
				inQuote = 0
			}
			continue
		}
		switch c {
		case '\'', '"', '`':
			inQuote = c
		case '(':
			depth++
		case ')':
			depth--
			if depth == 0 {
				return i
			}
		}
	}
	return -1
}

// normaliseColumnOrder rewrites an INSERT's column list, and an UPDATE's SET
// list, into a canonical order.
//
// GORM emits the columns of a map-valued Create, and the assignments of a
// map-valued Updates, in ALPHABETICAL key order. A handwritten statement almost
// never uses that order, so the two diverge on ordering alone. That is a large
// systemic category rather than a handful of sites: writing an approved diff
// for each would be pure toil, and would bury the diffs that record something
// real.
//
// This is safe because it moves each column TOGETHER WITH its value, so the
// column-to-value mapping is preserved exactly. A conversion that paired a
// column with the wrong value still produces different pairs and still fails.
// It is applied to both sides of the comparison, never to one alone, and it
// only touches the single-row INSERT and the SET list; anything else, including
// multi-row VALUES and INSERT ... SELECT, is returned unchanged.
func normaliseColumnOrder(sql string) string {
	trimmed := strings.TrimSpace(sql)

	// An upsert has TWO ordered lists: the INSERT column list and the ON DUPLICATE
	// KEY UPDATE tail. The INSERT branch below rebuilds the column and value lists
	// but copies everything after the closing parenthesis through verbatim, which
	// is where the tail lives. GORM sorts the keys of clause.Assignments(map)
	// exactly as it sorts Updates(map), so a rendered tail is alphabetical while
	// the golden keeps the order the original was written in.
	//
	// Normalising the tail first means the INSERT branch carries the normalised
	// tail through in its verbatim copy, and a statement with an upsert tail but no
	// reorderable column list is still handled.
	trimmed = normaliseDuplicateKeyUpdate(trimmed)

	if loc := insertPrefixPattern.FindStringIndex(trimmed); loc != nil {
		colOpen := loc[1] - 1
		colClose := matchParen(trimmed, colOpen)
		if colClose < 0 {
			return trimmed
		}

		rest := trimmed[colClose+1:]
		lower := strings.ToLower(rest)
		vi := strings.Index(lower, "values")
		if vi < 0 {
			return trimmed // INSERT ... SELECT, or something else: leave alone
		}
		valOpen := strings.Index(rest[vi:], "(")
		if valOpen < 0 {
			return trimmed
		}
		valOpen += vi
		valClose := matchParen(rest, valOpen)
		if valClose < 0 {
			return trimmed
		}
		// A second "(...)" straight after the first means multi-row VALUES,
		// where reordering one row's columns would be meaningless. Leave it.
		if strings.HasPrefix(strings.TrimSpace(rest[valClose+1:]), ",") {
			return trimmed
		}

		cols := splitList(trimmed[colOpen+1 : colClose])
		vals := splitList(rest[valOpen+1 : valClose])
		if len(cols) != len(vals) || len(cols) < 2 {
			return trimmed
		}

		type pair struct{ col, val string }
		pairs := make([]pair, len(cols))
		for i := range cols {
			pairs[i] = pair{cols[i], vals[i]}
		}
		sort.Slice(pairs, func(i, j int) bool { return pairs[i].col < pairs[j].col })

		c := make([]string, len(pairs))
		v := make([]string, len(pairs))
		for i, p := range pairs {
			c[i], v[i] = p.col, p.val
		}
		return trimmed[:colOpen] + "(" + strings.Join(c, ", ") + ") values (" +
			strings.Join(v, ", ") + ")" + rest[valClose+1:]
	}

	if m := updateSetPattern.FindStringSubmatch(trimmed); m != nil {
		assignments := splitList(m[2])
		if len(assignments) > 1 && !setOrderIsLoadBearing(assignments) {
			sort.Strings(assignments)
			return m[1] + strings.Join(assignments, ", ") + m[3]
		}
	}
	return trimmed
}

// normaliseDuplicateKeyUpdate sorts the assignment list of an
// ON DUPLICATE KEY UPDATE clause, moving each column with its value, so that
// GORM's alphabetical ordering can be compared against a golden that kept the
// order its author wrote.
//
// It declines for exactly the same reason the UPDATE branch declines: MySQL
// evaluates these assignments left to right too, so where one reads a column
// another assigns, the order is part of what the statement means. abtest.go is
// the live case - "shown = shown + 1, rate = COALESCE(100 * action / shown, 0)"
// computes rate from the incremented value, and alphabetical order would
// reverse that. Those sites use an explicit ordered clause.Set rather than a
// map, which is why they render in the original order and do not need this.
func normaliseDuplicateKeyUpdate(sql string) string {
	const marker = "on duplicate key update"
	lower := strings.ToLower(sql)
	i := strings.Index(lower, marker)
	if i < 0 {
		return sql
	}

	head, tail := sql[:i+len(marker)], sql[i+len(marker):]
	assignments := splitList(tail)
	if len(assignments) < 2 || setOrderIsLoadBearing(assignments) {
		return sql
	}

	trimmedAssignments := make([]string, len(assignments))
	for j, a := range assignments {
		trimmedAssignments[j] = strings.TrimSpace(a)
	}
	sort.Strings(trimmedAssignments)
	return head + " " + strings.Join(trimmedAssignments, ", ")
}

// setOrderIsLoadBearing reports whether an UPDATE's SET list means something
// different when reordered.
//
// MySQL evaluates assignments left to right, so
//
//	UPDATE t SET action = action + 1, rate = 100 * action / shown
//
// computes rate from the INCREMENTED action, while the reverse order computes
// it from the old one. GORM sorts map keys when building Updates(map[...]),
// which reorders the SET list, so a conversion can quietly change the answer.
//
// That matters here specifically because normaliseColumnOrder sorts SET lists
// on both sides in order to accept harmless reordering - which means that
// without this guard it would accept harmful reordering just as readily. A
// normalisation that hides a class of bug has to know where that class starts.
// Where the order carries meaning, we decline to normalise and let the exact
// text be the thing the golden pins.
//
// Only a cross-reference counts. "action = action + 1" refers to itself, which
// is order-independent; it is one assignment reading ANOTHER assignment's
// column that makes position significant.
func setOrderIsLoadBearing(assignments []string) bool {
	cols := make([]string, 0, len(assignments))
	rhs := make([]string, 0, len(assignments))
	for _, a := range assignments {
		eq := strings.Index(a, "=")
		if eq < 0 {
			return true // cannot parse it, so cannot prove reordering is safe
		}
		col := strings.TrimSpace(a[:eq])
		col = strings.Trim(col, "`")
		if i := strings.LastIndex(col, "."); i >= 0 {
			col = col[i+1:]
		}
		cols = append(cols, col)
		rhs = append(rhs, stripSQLStrings(a[eq+1:]))
	}

	for i, expr := range rhs {
		for j, col := range cols {
			if i == j || col == "" {
				continue
			}
			if regexp.MustCompile(`(?i)\b` + regexp.QuoteMeta(col) + `\b`).MatchString(expr) {
				return true
			}
		}
	}
	return false
}

// stripSQLStrings blanks out single-quoted literals so that a column name
// appearing inside one ("note = 'check the rate'") is not mistaken for a
// reference to that column.
func stripSQLStrings(s string) string {
	var b strings.Builder
	inQuote := false
	for i := 0; i < len(s); i++ {
		switch {
		case s[i] == '\\' && inQuote && i+1 < len(s):
			i++
		case s[i] == '\'':
			inQuote = !inQuote
		case !inQuote:
			b.WriteByte(s[i])
		}
	}
	return b.String()
}

// splitList splits a comma-separated SQL fragment at top level, so that commas
// inside a function call such as COALESCE(a, b) do not split it.
func splitList(s string) []string {
	var out []string
	depth, start := 0, 0
	inQuote := byte(0)

	for i := 0; i < len(s); i++ {
		c := s[i]
		if inQuote != 0 {
			if c == inQuote {
				inQuote = 0
			}
			continue
		}
		switch c {
		case '\'', '"', '`':
			inQuote = c
		case '(':
			depth++
		case ')':
			depth--
		case ',':
			if depth == 0 {
				out = append(out, strings.TrimSpace(s[start:i]))
				start = i + 1
			}
		}
	}
	out = append(out, strings.TrimSpace(s[start:]))
	return out
}

// resolveLimitOffset puts the bound LIMIT and OFFSET values back into the
// rendered SQL.
//
// GORM always binds these (clause/limit.go calls AddVar), so a handwritten
// "LIMIT 1" renders as "LIMIT ?" and diverges from its golden. That is a
// purely mechanical difference affecting a large share of sites, and writing
// an approved diff for each would be pure toil.
//
// Substituting the actual value is not merely less work, it is stricter than
// the alternatives. A blanket canonicaliser rule treating "limit N" and
// "limit ?" as equal would let a conversion that changed Limit(1) to Limit(50)
// pass unnoticed; resolving the value catches exactly that. Only LIMIT and
// OFFSET placeholders are resolved: every other bind legitimately stays "?" in
// the golden, because that is how the original was written.
func resolveLimitOffset(sql string, vars []any) string {
	if len(vars) == 0 || !strings.Contains(sql, "?") {
		return sql
	}

	var out strings.Builder
	varIdx := 0
	inQuote := byte(0)

	for i := 0; i < len(sql); i++ {
		c := sql[i]

		// Track quoted spans so a "?" inside a string literal is neither
		// counted as a bind nor rewritten.
		if inQuote != 0 {
			out.WriteByte(c)
			if c == inQuote {
				inQuote = 0
			}
			continue
		}
		if c == '\'' || c == '"' || c == '`' {
			inQuote = c
			out.WriteByte(c)
			continue
		}
		if c != '?' {
			out.WriteByte(c)
			continue
		}

		// A bind placeholder. Decide whether it is the operand of LIMIT or
		// OFFSET by looking at the preceding keyword.
		if varIdx < len(vars) && precededByLimitOrOffset(sql[:i]) {
			out.WriteString(fmt.Sprintf("%v", vars[varIdx]))
		} else {
			out.WriteByte('?')
		}
		varIdx++
	}
	return out.String()
}

// precededByLimitOrOffset reports whether the text immediately before a
// placeholder ends in the LIMIT or OFFSET keyword. MySQL also accepts
// "LIMIT offset, count", so a comma is stepped over too.
func precededByLimitOrOffset(before string) bool {
	trimmed := strings.TrimRight(before, " \t\n\r")
	trimmed = strings.TrimRight(trimmed, ",")
	trimmed = strings.TrimRight(trimmed, " \t\n\r")

	// A "LIMIT ?, ?" pair: the second placeholder follows a resolved number.
	if end := strings.LastIndexAny(trimmed, " \t\n\r"); end >= 0 {
		word := trimmed[end+1:]
		if isAllDigits(word) {
			trimmed = strings.TrimRight(trimmed[:end], " \t\n\r")
		}
	}

	upper := strings.ToUpper(trimmed)
	return strings.HasSuffix(upper, "LIMIT") || strings.HasSuffix(upper, "OFFSET")
}

func isAllDigits(s string) bool {
	if s == "" {
		return false
	}
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}
	return true
}

// dryRunDSN is never dialled. gorm.Open only reaches the network if the
// dialector's Initialize step queries "SELECT VERSION()", which
// SkipInitializeWithVersion below switches off; database/sql.Open itself is
// lazy for the go-sql-driver/mysql driver (it implements
