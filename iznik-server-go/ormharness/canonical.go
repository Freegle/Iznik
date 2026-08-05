package ormharness

// Layer 1 of the ORM migration harness (plan section 7.2, database-migration-
// evaluation-2026-07.md): SQL-text parity. This file is the canonicaliser
// that lets a hand-written golden SQL string and a GORM-rendered SQL string
// be compared for equivalence even though they are very unlikely to be
// byte-identical: GORM and our own SQL authors make different, legitimate
// choices about keyword case, whitespace, identifier quoting and alias
// names. golden.go (also this package) is the assertion built on top of it.
//
// This is a heuristic tokeniser, not a real SQL parser. That is a deliberate
// scope decision: a full parser would need to understand every MySQL
// dialect quirk this 1,600-plus-site corpus contains, for a payoff Layer 2
// (result parity against the real database, in resultparity.go) already
// delivers more reliably. Layer 1's job is to catch the common case fast -
// "did the shape of the SQL survive the rewrite" - and to fail loudly rather
// than silently on anything it cannot make sense of.

import (
	"strconv"
	"strings"
)

// Canonical normalises a SQL statement so that two statements which differ
// only in keyword case, incidental whitespace, identifier quoting style or
// alias naming compare equal as strings. Both the golden SQL and the
// GORM-rendered SQL must be passed through this same function before
// comparison; it is not meaningful on its own.
func Canonical(sql string) string {
	tokens := tokenize(sql)
	tokens = classifyKeywords(tokens)
	tokens = renameAliases(tokens)
	return joinTokens(tokens)
}

// tokenKind classifies a lexical token so later passes know what they are
// allowed to do to it: keywords may have their case normalised, string
// literals must never be touched, and so on.
type tokenKind int

const (
	tIdent tokenKind = iota
	tKeyword
	tString
	tNumber
	tPlaceholder
	tPunct
)

// token is one lexical unit. quoted records whether an identifier came from
// a backtick or double-quoted span in the source: a quoted identifier can
// never be reinterpreted as a keyword (that is exactly what quoting a
// reserved word such as SQL's from as a column name is for), so
// classifyKeywords must leave it alone.
type token struct {
	kind   tokenKind
	text   string
	quoted bool
}

// tokenize turns a SQL string into a token stream. It is careful to treat
// string literals as opaque spans (scanned character by character, quotes
// and all) so that nothing inside them - keywords, punctuation, alias-
// looking words - is ever touched by later passes; that is what "'Some
// Value' must keep its case and spacing" requires.
func tokenize(sql string) []token {
	var out []token
	n := len(sql)
	i := 0

	for i < n {
		c := sql[i]

		switch {
		case isSpace(c):
			i++

		case c == '-' && i+1 < n && sql[i+1] == '-':
			// Line comment. Dropped entirely: it carries no SQL semantics,
			// and keeping it would make two statements that differ only by
			// a comment compare unequal for no useful reason.
			j := i + 2
			for j < n && sql[j] != '\n' {
				j++
			}
			i = j

		case c == '/' && i+1 < n && sql[i+1] == '*':
			j := i + 2
			for j < n && !(sql[j] == '*' && j+1 < n && sql[j+1] == '/') {
				j++
			}
			if j < n {
				j += 2
			} else {
				j = n
			}
			i = j

		case c == '\'':
			end := scanQuoted(sql, i, '\'')
			out = append(out, token{kind: tString, text: sql[i:end]})
			i = end

		case c == '`':
			end := scanQuoted(sql, i, '`')
			out = append(out, token{kind: tIdent, text: unquote(sql[i+1:end-1], '`'), quoted: true})
			i = end

		case c == '"':
			// ANSI-style quoted identifier. MySQL treats '"' as a string
			// delimiter unless sql_mode includes ANSI_QUOTES, but the plan
			// explicitly calls out double quotes alongside backticks as an
			// identifier-quoting style to normalise away, so we honour
			// that here. In practice this corpus's double quotes only ever
			// occur inside single-quoted JSON payloads, which the '\''
			// branch above already consumes whole, so this branch is a
			// defensive completeness measure rather than a load-bearing
			// one for the current manifest.
			end := scanQuoted(sql, i, '"')
			out = append(out, token{kind: tIdent, text: unquote(sql[i+1:end-1], '"'), quoted: true})
			i = end

		case c == '?':
			out = append(out, token{kind: tPlaceholder, text: "?"})
			i++

		case isDigit(c):
			j := i + 1
			for j < n && (isDigit(sql[j]) || sql[j] == '.' || sql[j] == 'e' || sql[j] == 'E' ||
				((sql[j] == '+' || sql[j] == '-') && j > i && (sql[j-1] == 'e' || sql[j-1] == 'E'))) {
				j++
			}
			out = append(out, token{kind: tNumber, text: sql[i:j]})
			i = j

		case isIdentStart(c):
			j := i + 1
			for j < n && isIdentPart(sql[j]) {
				j++
			}
			out = append(out, token{kind: tIdent, text: sql[i:j]})
			i = j

		default:
			if i+1 < n {
				switch sql[i : i+2] {
				case "<=", ">=", "<>", "!=", ":=":
					out = append(out, token{kind: tPunct, text: sql[i : i+2]})
					i += 2
					continue
				}
			}
			out = append(out, token{kind: tPunct, text: string(c)})
			i++
		}
	}

	return out
}

// scanQuoted returns the index just past the closing quote of a quoted span
// starting at start (which must point at the opening quote character q). It
// understands both styles of escape MySQL accepts inside a quoted span:
// backslash-escaping and writing the quote character twice in a row, so an
// escaped quote inside the literal does not terminate it early.
func scanQuoted(sql string, start int, q byte) int {
	n := len(sql)
	j := start + 1
	for j < n {
		if sql[j] == '\\' && j+1 < n {
			j += 2
			continue
		}
		if sql[j] == q {
			if j+1 < n && sql[j+1] == q {
				j += 2
				continue
			}
			return j + 1
		}
		j++
	}
	return n // unterminated: take the rest of the string rather than panic
}

// unquote collapses a doubled escaped quote character back to one, for the
// identifier text held inside a quoted span (backslash escapes are left
// alone deliberately: an identifier is never expected to contain one, and
// leaving unrecognised escapes untouched is safer than guessing).
func unquote(inner string, q byte) string {
	doubled := string([]byte{q, q})
	return strings.ReplaceAll(inner, doubled, string(q))
}

func isSpace(c byte) bool { return c == ' ' || c == '\t' || c == '\n' || c == '\r' }
func isDigit(c byte) bool { return c >= '0' && c <= '9' }
func isIdentStart(c byte) bool {
	return c == '_' || c == '$' || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')
}
func isIdentPart(c byte) bool { return isIdentStart(c) || isDigit(c) }

// classifyKeywords promotes bare (unquoted) identifiers that spell a known
// SQL keyword or built-in function to tKeyword, lowercasing them in the
// process. Deliberately conservative: ordinary identifiers (table names,
// column names, aliases) are left exactly as written, case included,
// because a case difference there is exactly the kind of typo a parity
// check exists to catch, not something to normalise away.
func classifyKeywords(tokens []token) []token {
	for i := range tokens {
		t := &tokens[i]
		if t.kind != tIdent || t.quoted {
			continue
		}
		if isKeywordText(t.text) {
			t.kind = tKeyword
			t.text = strings.ToLower(t.text)
		}
	}
	return tokens
}

func isKeywordText(text string) bool {
	up := strings.ToUpper(text)
	if sqlKeywords[up] {
		return true
	}
	// MySQL's spatial function family (ST_Distance_Sphere, MBRContains, ...)
	// is too large to enumerate one by one; the manifest's own extractor
	// recognises it the same way (see the "spatial" pattern in
	// tools/orm-migration/extract.go).
	return strings.HasPrefix(up, "ST_") || strings.HasPrefix(up, "MBR")
}

func buildKeywordSet(words ...string) map[string]bool {
	m := make(map[string]bool, len(words))
	for _, w := range words {
		m[w] = true
	}
	return m
}

// sqlKeywords is not an attempt at an exhaustive SQL grammar; it is the set
// of words this codebase's actual golden SQL corpus uses in a way where
// case is a stylistic choice rather than meaningful. See
// tools/orm-migration/manifest.json's mysqlisms field for the dialect
// features this list was cross-checked against.
var sqlKeywords = buildKeywordSet(
	// Clause and statement keywords.
	"SELECT", "FROM", "WHERE", "AND", "OR", "NOT", "XOR", "IN", "IS", "NULL",
	"AS", "ON", "JOIN", "INNER", "LEFT", "RIGHT", "FULL", "OUTER", "CROSS",
	"STRAIGHT_JOIN", "USING", "GROUP", "BY", "ORDER", "ASC", "DESC",
	"HAVING", "LIMIT", "OFFSET", "DISTINCT", "UNION", "ALL", "WITH",
	"EXPLAIN", "ANALYZE",

	"INSERT", "INTO", "VALUES", "UPDATE", "SET", "DELETE", "REPLACE",
	"IGNORE", "DUPLICATE", "KEY", "LOW_PRIORITY", "DELAYED", "HIGH_PRIORITY",
	"RETURNING",

	"FOR", "LOCK", "SHARE", "MODE", "EXISTS", "BETWEEN", "LIKE", "CASE",
	"WHEN", "THEN", "ELSE", "END", "CAST", "CONVERT", "COLLATE", "INTERVAL",
	"FORCE", "INDEX", "USE", "DEFAULT", "TRUE", "FALSE",

	"UNSIGNED", "SIGNED", "CHAR", "BINARY", "DATE", "DATETIME", "TIME",
	"TIMESTAMP", "DECIMAL", "JSON",

	"DAY", "HOUR", "MINUTE", "SECOND", "MONTH", "YEAR", "WEEK", "QUARTER",
	"MICROSECOND",

	// Common built-in functions, including the dialect-specific ones the
	// manifest flags via mysqlisms (any-value, date-format, timestampdiff,
	// ifnull, group-concat, unix-timestamp, last-insert-id).
	"COUNT", "SUM", "MAX", "MIN", "AVG", "NOW", "IFNULL", "COALESCE",
	"DATE_FORMAT", "TIMESTAMPDIFF", "TIMESTAMPADD", "DATE_ADD", "DATE_SUB",
	"ANY_VALUE", "LAST_INSERT_ID", "CONCAT", "CONCAT_WS", "GROUP_CONCAT",
	"UNIX_TIMESTAMP", "FROM_UNIXTIME", "SUBSTRING", "SUBSTR", "LOWER",
	"UPPER", "TRIM", "LENGTH", "ROUND", "FLOOR", "CEIL", "CEILING", "ABS",
	"GREATEST", "LEAST", "IF", "RAND", "UUID", "MD5", "SHA1", "SHA2",
	"JSON_EXTRACT", "JSON_UNQUOTE", "JSON_OBJECT", "JSON_ARRAY",
)

// spaceBeforeParenKeywords is a purely cosmetic aid for joinTokens (it makes
// failure diffs easier to read, e.g. "where (" and "in (" rather than
// "where(" and "in("). It has no bearing on correctness: Canonical applies
// the same rule to both sides being compared, so equality never depends on
// it.
var spaceBeforeParenKeywords = buildKeywordSet(
	"WHERE", "AND", "OR", "NOT", "IN", "ON", "HAVING", "VALUES", "SET", "BY",
	"EXISTS", "FROM",
)

// renameAliases rewrites table/join aliases and explicit "AS" aliases
// (table or column) to positional names a1, a2, ... in order of first
// appearance, and rewrites every later bare reference to a renamed alias to
// match. This is what makes "users u" and "users AS u1" compare equal: both
// reduce to "users a1".
//
// It is a heuristic over the token stream, not a scoped SQL parser, so it
// assumes (true for every hand-written and GORM-rendered statement in this
// corpus) that an alias name is not reused for a second, unrelated binding
// within the same statement - e.g. across UNION branches. If that ever
// happens the two occurrences collapse onto the same canonical name, which
// could in theory hide a genuine divergence; Layer 2 (result parity against
// a real database, resultparity.go) is the backstop that would still catch
// it.
func renameAliases(tokens []token) []token {
	canon := map[string]string{}
	drop := map[int]bool{} // indices of redundant "as" tokens to omit entirely

	register := func(name string) {
		key := strings.ToLower(name)
		if _, ok := canon[key]; ok {
			return
		}
		canon[key] = "a" + strconv.Itoa(len(canon)+1)
	}

	var funcStack []string // upper-cased token immediately before each open '(' still in scope

	i := 0
	for i < len(tokens) {
		t := tokens[i]

		switch {
		case t.kind == tPunct && t.text == "(":
			prev := ""
			if i > 0 {
				prev = strings.ToUpper(tokens[i-1].text)
			}
			funcStack = append(funcStack, prev)
			i++

		case t.kind == tPunct && t.text == ")":
			if len(funcStack) > 0 {
				funcStack = funcStack[:len(funcStack)-1]
			}
			i++

		case t.kind == tKeyword && (t.text == "from" || t.text == "join"):
			isList := t.text == "from" // FROM may list several comma-separated tables; JOIN takes exactly one.
			i++
			for {
				i = skipQualifiedName(tokens, i)
				if i >= len(tokens) {
					break
				}
				if tokens[i].kind == tKeyword && tokens[i].text == "as" &&
					i+1 < len(tokens) && tokens[i+1].kind == tIdent {
					register(tokens[i+1].text)
					drop[i] = true
					i += 2
				} else if tokens[i].kind == tIdent {
					register(tokens[i].text)
					i++
				}
				if isList && i < len(tokens) && tokens[i].kind == tPunct && tokens[i].text == "," {
					i++
					continue
				}
				break
			}

		case t.kind == tKeyword && t.text == "as":
			// Guard against CAST(expr AS type) / CONVERT(expr, type):
			// there "AS" introduces a target type, not an alias.
			top := ""
			if len(funcStack) > 0 {
				top = funcStack[len(funcStack)-1]
			}
			if top != "CAST" && top != "CONVERT" && i+1 < len(tokens) && tokens[i+1].kind == tIdent {
				register(tokens[i+1].text)
				drop[i] = true
			}
			i++

		default:
			i++
		}
	}

	if len(canon) == 0 {
		return tokens
	}

	out := make([]token, 0, len(tokens))
	for idx, t := range tokens {
		if drop[idx] {
			continue
		}
		if t.kind == tIdent {
			if name, ok := canon[strings.ToLower(t.text)]; ok {
				out = append(out, token{kind: tIdent, text: name})
				continue
			}
		}
		out = append(out, t)
	}
	return out
}

// skipQualifiedName advances past a possibly schema-qualified name
// (`ident` or `ident.ident`) starting at i, returning the index of the
// first token after it. If tokens[i] is not an identifier (e.g. it is the
// '(' of a derived table), it returns i unchanged, leaving the caller to
// decide there is no alias to find here.
func skipQualifiedName(tokens []token, i int) int {
	if i >= len(tokens) || tokens[i].kind != tIdent {
		return i
	}
	i++
	for i+1 < len(tokens) && tokens[i].kind == tPunct && tokens[i].text == "." && tokens[i+1].kind == tIdent {
		i += 2
	}
	return i
}

// joinTokens renders a token stream back to a single-spaced string. The
// spacing rules exist purely to make mismatches readable in a failure diff;
// they do not affect equality, since both sides of a comparison go through
// the same rules.
func joinTokens(tokens []token) string {
	var b strings.Builder
	for i, t := range tokens {
		if i > 0 && needsSpaceBefore(tokens[i-1], t) {
			b.WriteByte(' ')
		}
		b.WriteString(t.text)
	}
	return b.String()
}

func needsSpaceBefore(prev, cur token) bool {
	if cur.kind == tPunct {
		switch cur.text {
		case ",", ")", ";", ".":
			return false
		case "(":
			return needsSpaceBeforeParen(prev)
		}
	}
	if prev.kind == tPunct && (prev.text == "(" || prev.text == ".") {
		return false
	}
	return true
}

func needsSpaceBeforeParen(prev token) bool {
	return prev.kind == tKeyword && spaceBeforeParenKeywords[strings.ToUpper(prev.text)]
}
