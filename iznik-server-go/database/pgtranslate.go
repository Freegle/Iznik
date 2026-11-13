package database

import (
	"strconv"
	"strings"
)

// The Go API's SQL is written for MySQL: backtick quoting, IFNULL, IF(), DATE_SUB with INTERVAL,
// CAST AS UNSIGNED, INSERT IGNORE, ON DUPLICATE KEY UPDATE. On PostgreSQL the connection (pgconn.go)
// passes every statement through translatePostgres first, so one SQL text serves both engines for the
// constructs that have a mechanical equivalent. Statements GORM built for the PostgreSQL dialect pass
// through unchanged. MySQL never reaches this file.
//
// Constructs with no safe mechanical equivalent (GROUP BY on non-grouped columns, JSON path
// semantics, spatial functions on mis-labelled SRIDs) are not touched here: they are ported at the
// call site.

type tokKind byte

const (
	tkWord  tokKind = 'w' // identifier, keyword, number or $n parameter
	tkStr   tokKind = 's' // 'single quoted'
	tkQuote tokKind = 'q' // "double quoted"
	tkTick  tokKind = 'b' // `backtick quoted`
	tkPunct tokKind = 'p'
	tkSpace tokKind = ' '
	tkCmt   tokKind = 'c'
)

type tok struct {
	k tokKind
	s string
}

func isWordByte(c byte) bool {
	return c == '_' || c == '$' || (c >= '0' && c <= '9') || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || c >= 0x80
}

// lexSQL splits a statement into tokens that concatenate back to the exact input.
func lexSQL(sql string) []tok {
	var out []tok
	n := len(sql)
	for i := 0; i < n; {
		c := sql[i]
		switch {
		case c == ' ' || c == '\t' || c == '\n' || c == '\r':
			j := i
			for j < n && (sql[j] == ' ' || sql[j] == '\t' || sql[j] == '\n' || sql[j] == '\r') {
				j++
			}
			out = append(out, tok{tkSpace, sql[i:j]})
			i = j
		case c == '\'' || c == '"' || c == '`':
			j := i + 1
			for j < n {
				if sql[j] == c {
					if j+1 < n && sql[j+1] == c {
						j += 2
						continue
					}
					break
				}
				if sql[j] == '\\' && c == '\'' && j+1 < n {
					j += 2
					continue
				}
				j++
			}
			if j < n {
				j++
			}
			k := tkStr
			if c == '"' {
				k = tkQuote
			} else if c == '`' {
				k = tkTick
			}
			out = append(out, tok{k, sql[i:j]})
			i = j
		case c == '-' && i+1 < n && sql[i+1] == '-':
			j := i
			for j < n && sql[j] != '\n' {
				j++
			}
			out = append(out, tok{tkCmt, sql[i:j]})
			i = j
		case c == '/' && i+1 < n && sql[i+1] == '*':
			j := strings.Index(sql[i+2:], "*/")
			if j < 0 {
				j = n
			} else {
				j = i + 2 + j + 2
			}
			out = append(out, tok{tkCmt, sql[i:j]})
			i = j
		case isWordByte(c):
			j := i
			for j < n && isWordByte(sql[j]) {
				j++
			}
			out = append(out, tok{tkWord, sql[i:j]})
			i = j
		default:
			out = append(out, tok{tkPunct, string(c)})
			i++
		}
	}
	return out
}

func joinToks(ts []tok) string {
	var b strings.Builder
	for _, t := range ts {
		b.WriteString(t.s)
	}
	return b.String()
}

func isKw(t tok, kw string) bool {
	return t.k == tkWord && strings.EqualFold(t.s, kw)
}

func isP(t tok, p string) bool { return t.k == tkPunct && t.s == p }

// nextSig returns the index of the next token at or after i that is not space or comment, or -1.
func nextSig(ts []tok, i int) int {
	for ; i < len(ts); i++ {
		if ts[i].k != tkSpace && ts[i].k != tkCmt {
			return i
		}
	}
	return -1
}

func prevSig(ts []tok, i int) int {
	for ; i >= 0; i-- {
		if ts[i].k != tkSpace && ts[i].k != tkCmt {
			return i
		}
	}
	return -1
}

// matchClose returns the index of the ')' that closes the '(' at open, or -1.
func matchClose(ts []tok, open int) int {
	depth := 0
	for i := open; i < len(ts); i++ {
		if isP(ts[i], "(") {
			depth++
		} else if isP(ts[i], ")") {
			depth--
			if depth == 0 {
				return i
			}
		}
	}
	return -1
}

// splitArgs splits the tokens between a call's parentheses at top-level commas.
func splitArgs(ts []tok) [][]tok {
	var args [][]tok
	depth := 0
	start := 0
	for i, t := range ts {
		if isP(t, "(") {
			depth++
		} else if isP(t, ")") {
			depth--
		} else if isP(t, ",") && depth == 0 {
			args = append(args, ts[start:i])
			start = i + 1
		}
	}
	return append(args, ts[start:])
}

func trimToks(ts []tok) []tok {
	for len(ts) > 0 && (ts[0].k == tkSpace) {
		ts = ts[1:]
	}
	for len(ts) > 0 && (ts[len(ts)-1].k == tkSpace) {
		ts = ts[:len(ts)-1]
	}
	return ts
}

func word(s string) tok  { return tok{tkWord, s} }
func punct(s string) tok { return tok{tkPunct, s} }
func sp() tok            { return tok{tkSpace, " "} }

func concatToks(parts ...[]tok) []tok {
	var out []tok
	for _, p := range parts {
		out = append(out, p...)
	}
	return out
}

// mixedCaseColumns are the baseline's columns that keep a mixed-case name. MySQL ignores the case of a
// column name; PostgreSQL does not once the name is quoted, so a bare or quoted lower-case spelling is
// put back to the real name.
var mixedCaseColumns = map[string]string{
	"fop":                "FOP",
	"grossamount":        "GrossAmount",
	"ogr_fid":            "OGR_FID",
	"payer":              "Payer",
	"payerdisplayname":   "PayerDisplayName",
	"shape":              "SHAPE",
	"transactionid":      "TransactionID",
	"transactiontype":    "TransactionType",
	"activeusers":        "activeUsers",
	"ourpostingstatus":   "ourPostingStatus",
	"yahooalias":         "yahooAlias",
	"yahoodeliverytype":  "yahooDeliveryType",
	"yahoopostingstatus": "yahooPostingStatus",
	"yahoouserid":        "yahooUserId",
}

// translatePostgres rewrites MySQL-dialect SQL into PostgreSQL. It is pure and idempotent.
func translatePostgres(sql string, cat catalog) string {
	if strings.Contains(sql, "$$") {
		return sql // a dollar-quoted body (CREATE FUNCTION, DO) is PostgreSQL already
	}
	ts := lexSQL(sql)
	changed := false

	// A statement sent straight to database/sql (not through GORM) has ? placeholders; PostgreSQL wants $n.
	hasNumbered := false
	for _, t := range ts {
		if t.k == tkWord && len(t.s) > 1 && t.s[0] == '$' && t.s[1] >= '0' && t.s[1] <= '9' {
			hasNumbered = true
			break
		}
	}
	if !hasNumbered {
		n := 0
		for i, t := range ts {
			if isP(t, "?") {
				n++
				ts[i] = tok{tkWord, "$" + strconv.Itoa(n)}
				changed = true
			}
		}
	}

	// Identifiers.
	for i, t := range ts {
		switch t.k {
		case tkTick:
			name := t.s[1 : len(t.s)-1]
			if c, ok := mixedCaseColumns[strings.ToLower(name)]; ok {
				name = c
			}
			ts[i] = tok{tkQuote, `"` + name + `"`}
			changed = true
		case tkQuote:
			name := t.s[1 : len(t.s)-1]
			if len(name) > 2 && name[0] == '`' && name[len(name)-1] == '`' {
				name = name[1 : len(name)-1]
				ts[i] = tok{tkQuote, `"` + name + `"`}
				changed = true
			}
			if c, ok := mixedCaseColumns[name]; ok && c != name {
				ts[i] = tok{tkQuote, `"` + c + `"`}
				changed = true
			}
		case tkWord:
			if c, ok := mixedCaseColumns[strings.ToLower(t.s)]; ok {
				// Only where it is used as a column: not after AS, and not a function name.
				p := prevSig(ts, i-1)
				nx := nextSig(ts, i+1)
				if (p < 0 || !isKw(ts[p], "AS")) && (nx < 0 || !isP(ts[nx], "(")) {
					ts[i] = tok{tkQuote, `"` + c + `"`}
					changed = true
				}
			}
		}
	}

	for i, t := range ts {
		if t.k != tkWord {
			continue
		}
		nx := nextSig(ts, i+1)
		callNext := nx >= 0 && isP(ts[nx], "(")
		switch {
		case strings.EqualFold(t.s, "IFNULL") && callNext:
			ts[i].s = "COALESCE"
			changed = true
		case strings.EqualFold(t.s, "RAND") && callNext:
			ts[i].s = "random"
			changed = true
		case strings.EqualFold(t.s, "CURDATE") && callNext:
			ts[i].s = "CURRENT_DATE"
			if cl := matchClose(ts, nx); cl > 0 {
				for k := nx; k <= cl; k++ {
					ts[k] = tok{tkSpace, ""}
				}
			}
			changed = true
		case strings.EqualFold(t.s, "DATABASE") && callNext:
			ts[i].s = "current_schema"
			changed = true
		}
	}

	// A column type's UNSIGNED modifier (INT UNSIGNED) has no equivalent.
	for i := 0; i < len(ts); i++ {
		if isKw(ts[i], "UNSIGNED") {
			if p := prevSig(ts, i-1); p >= 0 && !isKw(ts[p], "AS") {
				ts[i] = tok{tkSpace, ""}
				changed = true
			}
		}
	}

	// CAST(x AS UNSIGNED [INTEGER]) and SIGNED.
	for i := 0; i < len(ts); i++ {
		if isKw(ts[i], "UNSIGNED") || isKw(ts[i], "SIGNED") {
			p := prevSig(ts, i-1)
			if p >= 0 && isKw(ts[p], "AS") {
				ts[i].s = "bigint"
				if n := nextSig(ts, i+1); n >= 0 && (isKw(ts[n], "INTEGER") || isKw(ts[n], "INT")) {
					ts[n] = tok{tkSpace, ""}
				}
				changed = true
			}
		}
	}

	// INTERVAL <n> <UNIT>.
	for i := 0; i < len(ts); i++ {
		if !isKw(ts[i], "INTERVAL") {
			continue
		}
		o := nextSig(ts, i+1)
		if o < 0 {
			continue
		}
		var operand []tok
		end := o
		if isP(ts[o], "(") {
			cl := matchClose(ts, o)
			if cl < 0 {
				continue
			}
			operand = ts[o : cl+1]
			end = cl
		} else if ts[o].k == tkStr {
			continue // already INTERVAL '10 minute'
		} else if isP(ts[o], "?") || ts[o].k == tkWord {
			operand = ts[o : o+1]
			end = o
		} else if isP(ts[o], "-") && o+1 < len(ts) {
			operand = ts[o : o+2]
			end = o + 1
		} else {
			continue
		}
		u := nextSig(ts, end+1)
		if u < 0 || ts[u].k != tkWord {
			continue
		}
		unit := strings.ToLower(ts[u].s)
		switch unit {
		case "microsecond", "second", "minute", "hour", "day", "week", "month", "year":
		default:
			continue
		}
		var rep []tok
		if len(operand) == 1 && operand[0].k == tkWord && operand[0].s != "" && operand[0].s[0] >= '0' && operand[0].s[0] <= '9' {
			rep = []tok{word("INTERVAL"), sp(), {tkStr, "'" + operand[0].s + " " + unit + "'"}}
		} else {
			rep = concatToks([]tok{punct("("), punct("(")}, operand, []tok{punct(")"), sp(), punct("*"), sp(), word("INTERVAL"), sp(), {tkStr, "'1 " + unit + "'"}, punct(")")})
		}
		ts = concatToks(ts[:i], rep, ts[u+1:])
		changed = true
	}

	// DATE_SUB(a, b), DATE_ADD(a, b), SUBDATE, ADDDATE.
	for i := len(ts) - 1; i >= 0; i-- {
		if ts[i].k != tkWord {
			continue
		}
		op := ""
		switch strings.ToUpper(ts[i].s) {
		case "DATE_SUB", "SUBDATE":
			op = "-"
		case "DATE_ADD", "ADDDATE":
			op = "+"
		default:
			continue
		}
		o := nextSig(ts, i+1)
		if o < 0 || !isP(ts[o], "(") {
			continue
		}
		cl := matchClose(ts, o)
		if cl < 0 {
			continue
		}
		args := splitArgs(ts[o+1 : cl])
		if len(args) != 2 {
			continue
		}
		rep := concatToks([]tok{punct("(")}, trimToks(args[0]), []tok{sp(), punct(op), sp()}, trimToks(args[1]), []tok{punct(")")})
		ts = concatToks(ts[:i], rep, ts[cl+1:])
		changed = true
	}

	// IF(a, b, c) -> CASE WHEN a THEN b ELSE c END, innermost first.
	for i := len(ts) - 1; i >= 0; i-- {
		if !isKw(ts[i], "IF") {
			continue
		}
		o := nextSig(ts, i+1)
		if o < 0 || !isP(ts[o], "(") {
			continue
		}
		cl := matchClose(ts, o)
		if cl < 0 {
			continue
		}
		args := splitArgs(ts[o+1 : cl])
		if len(args) != 3 {
			continue
		}
		rep := concatToks([]tok{word("CASE"), sp(), word("WHEN"), sp()}, trimToks(args[0]), []tok{sp(), word("THEN"), sp()}, trimToks(args[1]), []tok{sp(), word("ELSE"), sp()}, trimToks(args[2]), []tok{sp(), word("END")})
		ts = concatToks(ts[:i], []tok{punct("(")}, rep, []tok{punct(")")}, ts[cl+1:])
		changed = true
	}

	var more bool
	ts, more = translateMore(ts, cat)
	changed = changed || more

	if !changed {
		return sql
	}
	return joinToks(ts)
}

// insertTarget returns the table and column list of an INSERT INTO t (a, b, ...) statement head.
func insertTarget(ts []tok) (string, []string) {
	i := nextSig(ts, 0)
	if i < 0 || !(isKw(ts[i], "INSERT") || isKw(ts[i], "REPLACE")) {
		return "", nil
	}
	i = nextSig(ts, i+1)
	if i >= 0 && isKw(ts[i], "INTO") {
		i = nextSig(ts, i+1)
	}
	if i < 0 {
		return "", nil
	}
	table := identName(ts[i])
	i = nextSig(ts, i+1)
	if i >= 0 && isP(ts[i], ".") { // schema.table
		i = nextSig(ts, i+1)
		table = identName(ts[i])
		i = nextSig(ts, i+1)
	}
	if i < 0 || !isP(ts[i], "(") {
		return table, nil
	}
	cl := matchClose(ts, i)
	if cl < 0 {
		return table, nil
	}
	var cols []string
	for _, a := range splitArgs(ts[i+1 : cl]) {
		a = trimToks(a)
		if len(a) != 1 {
			return table, nil
		}
		cols = append(cols, identName(a[0]))
	}
	return table, cols
}

func identName(t tok) string {
	s := t.s
	if t.k == tkTick || t.k == tkQuote {
		s = s[1 : len(s)-1]
	}
	return s
}

// appendOnConflictDoNothing adds ON CONFLICT DO NOTHING at the end of an INSERT, before RETURNING.
func appendOnConflictDoNothing(ts []tok) []tok {
	end := len(ts)
	depth := 0
	for i, t := range ts {
		if isP(t, "(") {
			depth++
		} else if isP(t, ")") {
			depth--
		} else if depth == 0 && isKw(t, "RETURNING") {
			end = i
			break
		}
	}
	for end > 0 && (ts[end-1].k == tkSpace || isP(ts[end-1], ";")) {
		end--
	}
	ins := []tok{sp(), word("ON"), sp(), word("CONFLICT"), sp(), word("DO"), sp(), word("NOTHING")}
	return concatToks(ts[:end], ins, ts[end:])
}
