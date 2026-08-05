package ormharness

// SQL text helpers for wherefieldwise.go's precondition check: finding the
// top-level WHERE clause of a full SELECT statement and splitting it into
// its top-level AND-joined fragments. "Top-level" means at paren depth 0 -
// not inside a subquery, a function call, or a parenthesised sub-expression
// like modmailsonly's "((a AND b) OR (c AND d))" fragment, which is one
// fragment, not four. Mirrors matchParen/splitList (golden.go) in style:
// the same depth-and-quote tracking, applied to keywords and " AND "
// instead of a single delimiter byte.

import (
	"fmt"
	"strings"
)

// selectWhereFragments extracts the top-level WHERE clause's AND-joined
// fragment list from a full SELECT statement, stopping at the first
// top-level GROUP BY/ORDER BY/LIMIT or the statement's end. Returns an
// error if no top-level WHERE is found (e.g. a statement with only a
// subquery's WHERE, which is not at depth 0).
func selectWhereFragments(sql string) ([]string, error) {
	trimmed := strings.TrimSpace(sql)

	whereStart := findTopLevelKeyword(trimmed, "WHERE", 0)
	if whereStart < 0 {
		return nil, fmt.Errorf("no top-level WHERE clause found in %q", sql)
	}
	fragStart := whereStart + len("WHERE")

	whereEnd := len(trimmed)
	for _, kw := range []string{"GROUP BY", "ORDER BY", "LIMIT"} {
		if idx := findTopLevelKeyword(trimmed, kw, fragStart); idx >= 0 && idx < whereEnd {
			whereEnd = idx
		}
	}

	return splitTopLevelAnd(strings.TrimSpace(trimmed[fragStart:whereEnd])), nil
}

// findTopLevelKeyword returns the index of the first case-insensitive,
// word-bounded occurrence of keyword in s at or after `from`, considering
// only positions where paren depth (tracked from the start of s, so it is
// correct regardless of where `from` falls) is 0 - i.e. not inside a
// parenthesised subquery or expression. Returns -1 if not found.
func findTopLevelKeyword(s, keyword string, from int) int {
	depth := 0
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
		default:
			if i >= from && depth == 0 && hasKeywordAt(s, i, keyword) {
				return i
			}
		}
	}
	return -1
}

// hasKeywordAt reports whether s contains keyword starting at index i,
// case-insensitively, bounded on both sides by a non-word character (or
// the start/end of s) so "WHERE" does not match inside "SOMEWHERE".
func hasKeywordAt(s string, i int, keyword string) bool {
	if i+len(keyword) > len(s) || !strings.EqualFold(s[i:i+len(keyword)], keyword) {
		return false
	}
	if i > 0 && isWordChar(s[i-1]) {
		return false
	}
	if end := i + len(keyword); end < len(s) && isWordChar(s[end]) {
		return false
	}
	return true
}

func isWordChar(c byte) bool {
	return c == '_' || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9')
}

// splitTopLevelAnd splits a WHERE clause's fragment text on top-level (paren
// depth 0) " AND " boundaries, respecting quoted strings the same way
// splitList (golden.go) does for commas. A fragment that itself contains
// "AND" only inside its own parentheses - e.g. modmailsonly's
// "((logs.type = ? AND logs.subtype IN (?, ?, ?)) OR (...))" - stays one
// fragment, matching how strings.Join(where, " AND ") assembled it in the
// original Go source: each entry in that []string is exactly one fragment,
// regardless of what AND/OR it contains internally.
func splitTopLevelAnd(s string) []string {
	var out []string
	depth, start := 0, 0
	inQuote := byte(0)

	i := 0
	for i < len(s) {
		c := s[i]
		if inQuote != 0 {
			if c == inQuote {
				inQuote = 0
			}
			i++
			continue
		}
		switch c {
		case '\'', '"', '`':
			inQuote = c
			i++
		case '(':
			depth++
			i++
		case ')':
			depth--
			i++
		default:
			if depth == 0 && hasKeywordAt(s, i, "AND") {
				out = append(out, strings.TrimSpace(s[start:i]))
				i += len("AND")
				start = i
			} else {
				i++
			}
		}
	}
	out = append(out, strings.TrimSpace(s[start:]))
	return out
}
