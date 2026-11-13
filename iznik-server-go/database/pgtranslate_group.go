package database

import "strings"

// MySQL runs without ONLY_FULL_GROUP_BY: a SELECT with GROUP BY may name a column that is neither grouped
// nor aggregated, and takes an arbitrary row's value; it may also ORDER BY such a column, use a select-list
// alias in HAVING, write HAVING with no GROUP BY, and SELECT DISTINCT with an ORDER BY on a column that is not
// selected. PostgreSQL refuses all of these. fixGrouping rewrites them into the form PostgreSQL accepts
// with the same meaning: a bare column becomes ANY_VALUE(column), which is "an arbitrary row's value".

var aggregateNames = map[string]bool{
	"count": true, "sum": true, "min": true, "max": true, "avg": true, "any_value": true,
	"string_agg": true, "bool_and": true, "bool_or": true, "jsonb_agg": true, "json_arrayagg": true,
	"json_objectagg": true, "std": true, "stddev": true, "variance": true, "array_agg": true,
	"bit_and": true, "bit_or": true, "every": true,
}

func hasAggregate(ts []tok) bool {
	for i, t := range ts {
		if t.k == tkWord && aggregateNames[strings.ToLower(t.s)] {
			if n := nextSig(ts, i+1); n >= 0 && isP(ts[n], "(") {
				// a window function is not an aggregate for this purpose
				if cl := matchClose(ts, n); cl >= 0 {
					if o := nextSig(ts, cl+1); o >= 0 && isKw(ts[o], "OVER") {
						continue
					}
				}
				return true
			}
		}
	}
	return false
}

// normExpr is a comparison key for an expression: no spaces, no quotes, lower case.
func normExpr(ts []tok) string {
	var b strings.Builder
	for _, t := range trimToks(ts) {
		if t.k == tkSpace || t.k == tkCmt {
			continue
		}
		s := t.s
		if t.k == tkQuote || t.k == tkTick {
			s = s[1 : len(s)-1]
		}
		b.WriteString(strings.ToLower(s))
	}
	return b.String()
}

// lastSegment is "col" for "t.col" and the whole key otherwise.
func lastSegment(key string) string {
	if i := strings.LastIndexByte(key, '.'); i >= 0 && !strings.ContainsAny(key, "()+-*/,'") {
		return key[i+1:]
	}
	return key
}

func isPlainColumn(ts []tok) bool {
	ts = trimToks(ts)
	switch len(ts) {
	case 1:
		return ts[0].k == tkWord && !isNumberTok(ts[0]) || ts[0].k == tkQuote
	case 3:
		return (ts[0].k == tkWord || ts[0].k == tkQuote) && isP(ts[1], ".") && (ts[2].k == tkWord || ts[2].k == tkQuote)
	}
	return false
}

func hasIdentifier(ts []tok) bool {
	for i, t := range ts {
		if t.k == tkQuote {
			return true
		}
		if t.k == tkWord && !isNumberTok(t) {
			u := strings.ToUpper(t.s)
			switch u {
			case "NULL", "TRUE", "FALSE", "AND", "OR", "NOT", "IS", "IN", "LIKE", "BETWEEN", "CASE", "WHEN", "THEN", "ELSE", "END", "AS", "DISTINCT", "INTERVAL", "DAY", "MINUTE", "HOUR", "SECOND", "MONTH", "YEAR", "WEEK":
				continue
			}
			if n := nextSig(ts, i+1); n >= 0 && isP(ts[n], "(") {
				continue // a function name; its arguments are looked at on their own
			}
			if strings.HasPrefix(t.s, "$") {
				continue
			}
			return true
		}
	}
	return false
}

type selectParts struct {
	selStart int // index after SELECT [DISTINCT]
	distinct int // index of DISTINCT or -1
	from     int // FROM or -1
	where    int
	group    int // GROUP, or -1
	having   int
	order    int
	limit    int
	end      int // exclusive
}

// findSelect locates the clauses of the SELECT starting at ts[s]. Depth is counted from s.
func findSelect(ts []tok, s int) selectParts {
	p := selectParts{selStart: s + 1, distinct: -1, from: -1, where: -1, group: -1, having: -1, order: -1, limit: -1, end: len(ts)}
	if n := nextSig(ts, s+1); n >= 0 && isKw(ts[n], "DISTINCT") {
		p.distinct = n
		p.selStart = n + 1
	}
	depth := 0
	for i := s + 1; i < len(ts); i++ {
		t := ts[i]
		if isP(t, "(") {
			depth++
			continue
		}
		if isP(t, ")") {
			depth--
			if depth < 0 {
				p.end = i
				return p
			}
			continue
		}
		if depth != 0 || t.k != tkWord {
			continue
		}
		switch strings.ToUpper(t.s) {
		case "FROM":
			if p.from < 0 {
				p.from = i
			}
		case "WHERE":
			if p.where < 0 && p.group < 0 {
				p.where = i
			}
		case "GROUP":
			if n := nextSig(ts, i+1); n >= 0 && isKw(ts[n], "BY") && p.group < 0 {
				p.group = i
			}
		case "HAVING":
			if p.having < 0 {
				p.having = i
			}
		case "ORDER":
			if n := nextSig(ts, i+1); n >= 0 && isKw(ts[n], "BY") && p.order < 0 {
				p.order = i
			}
		case "LIMIT":
			if p.limit < 0 {
				p.limit = i
			}
		case "UNION", "INTERSECT", "EXCEPT", "FOR":
			p.end = i
			return p
		}
	}
	return p
}

// clauseEnd is where the clause starting at start stops: the first of the later clause keywords.
func clauseEnd(p selectParts, start int, later ...int) int {
	end := p.end
	for _, l := range later {
		if l > start && l < end {
			end = l
		}
	}
	return end
}

type selItem struct {
	expr     []tok
	alias    string // lower case, or ""
	asTok    int    // index in expr of AS, or -1
	raw      []tok  // the item as written, with its alias
	aliasTok *tok
}

func parseSelectItems(items []tok) []selItem {
	var out []selItem
	for _, a := range splitArgs(items) {
		a = trimToks(a)
		it := selItem{asTok: -1, raw: a}
		// trailing "AS alias" or "alias"
		depth := 0
		for i, t := range a {
			if isP(t, "(") {
				depth++
			} else if isP(t, ")") {
				depth--
			} else if depth == 0 && isKw(t, "AS") && i+1 < len(a) {
				it.asTok = i
			}
		}
		if it.asTok >= 0 {
			it.expr = trimToks(a[:it.asTok])
			at := trimToks(a[it.asTok+1:])[0]
			it.aliasTok = &at
			it.alias = strings.ToLower(identName(at))
		} else {
			it.expr = a
		}
		out = append(out, it)
	}
	return out
}

// fixGrouping rewrites every SELECT in ts, innermost first.
func fixGrouping(ts []tok) ([]tok, bool) {
	changed := false
	for s := len(ts) - 1; s >= 0; s-- {
		if !isKw(ts[s], "SELECT") {
			continue
		}
		var c bool
		ts, c = fixOneSelect(ts, s)
		changed = changed || c
	}
	return ts, changed
}

func fixOneSelect(ts []tok, s int) ([]tok, bool) {
	p := findSelect(ts, s)
	if p.from < 0 {
		return ts, false
	}
	selEnd := p.from
	items := parseSelectItems(ts[p.selStart:selEnd])
	if len(items) == 0 {
		return ts, false
	}
	inUnion := topKw(ts, s, "UNION") >= 0 || unionBefore(ts, s)
	// A select-list EXISTS(...) is 0 or 1 in MySQL, and sits in a UNION beside integer columns.
	var rebuilt []tok
	existsFound := false
	for i, it := range items {
		if i > 0 {
			rebuilt = append(rebuilt, punct(","), sp())
		}
		e := it.expr
		if inUnion && isComparisonItem(e) {
			rebuilt = append(rebuilt, punct("("), punct("("))
			rebuilt = append(rebuilt, e...)
			rebuilt = append(rebuilt, punct(")"), punct(")"), punct(":"), punct(":"), word("int"))
			if it.asTok >= 0 {
				rebuilt = append(rebuilt, sp(), word("AS"), sp(), *it.aliasTok)
			}
			existsFound = true
			continue
		}
		if len(e) > 2 && (isKw(e[0], "EXISTS") && callEnd(e, 0) == len(e)-1) {
			rebuilt = append(rebuilt, punct("("), word("CASE"), sp(), word("WHEN"), sp())
			rebuilt = append(rebuilt, e...)
			rebuilt = append(rebuilt, sp(), word("THEN"), sp(), word("1"), sp(), word("ELSE"), sp(), word("0"), sp(), word("END"), punct(")"))
			if it.asTok >= 0 {
				rebuilt = append(rebuilt, sp(), word("AS"), sp(), *it.aliasTok)
			}
			existsFound = true
			continue
		}
		rebuilt = append(rebuilt, it.raw...)
	}
	if existsFound {
		rep := concatToks(ts[:p.selStart], []tok{sp()}, rebuilt, []tok{sp()}, ts[selEnd:])
		return fixOneSelectAfter(rep, s)
	}
	for _, it := range items {
		if len(it.expr) == 1 && isP(it.expr[0], "*") || (len(it.expr) == 3 && isP(it.expr[2], "*")) {
			return ts, false
		}
	}

	hasOrderOnly := p.order >= 0
	_ = hasOrderOnly

	// HAVING with no GROUP BY and no aggregate is a WHERE.
	changed := false
	if p.having >= 0 && p.group < 0 {
		hEnd := clauseEnd(p, p.having, p.order, p.limit)
		cond := ts[p.having+1 : hEnd]
		if !hasAggregate(cond) {
			cond = substituteAliases(cond, items)
			var rep []tok
			if p.where >= 0 {
				wEnd := p.having
				rep = concatToks(ts[:p.where+1], []tok{sp(), punct("(")}, trimToks(ts[p.where+1:wEnd]), []tok{punct(")"), sp(), word("AND"), sp(), punct("(")}, trimToks(cond), []tok{punct(")"), sp()}, ts[hEnd:])
			} else {
				rep = concatToks(ts[:p.having], []tok{word("WHERE"), sp()}, trimToks(cond), []tok{sp()}, ts[hEnd:])
			}
			return fixOneSelectAfter(rep, s)
		}
	}

	if p.group < 0 && p.distinct >= 0 && p.order >= 0 {
		// SELECT DISTINCT ... ORDER BY an expression that is not selected.
		oEnd := clauseEnd(p, p.order, p.limit)
		sel := map[string]bool{}
		for _, it := range items {
			sel[normExpr(it.expr)] = true
			if it.alias != "" {
				sel[it.alias] = true
			}
		}
		bad := false
		for _, o := range splitArgs(ts[afterBy(ts, p.order):oEnd]) {
			e := orderExpr(o)
			if !sel[normExpr(e)] && !sel[lastSegment(normExpr(e))] && !isNumberTok(trimToks(e)[0]) && hasIdentifier(e) {
				bad = true
			}
		}
		if bad {
			// DISTINCT is GROUP BY every selected expression.
			var grp []tok
			for i, it := range items {
				if hasAggregate(it.expr) {
					return ts, changed
				}
				if i > 0 {
					grp = append(grp, punct(","), sp())
				}
				grp = append(grp, it.expr...)
			}
			insertAt := p.order
			rep := concatToks(ts[:p.distinct], ts[p.distinct+1:insertAt], []tok{word("GROUP"), sp(), word("BY"), sp()}, grp, []tok{sp()}, ts[insertAt:])
			return fixOneSelectAfter(rep, s)
		}
		return ts, changed
	}

	if p.group < 0 {
		return ts, changed
	}

	gEnd := clauseEnd(p, p.group, p.having, p.order, p.limit)
	groupToks := ts[afterBy(ts, p.group):gEnd]
	groups := map[string]bool{}
	for _, g := range splitArgs(groupToks) {
		g = trimToks(g)
		if len(g) == 1 && isNumberTok(g[0]) { // GROUP BY 1
			n := 0
			for _, c := range g[0].s {
				n = n*10 + int(c-'0')
			}
			if n >= 1 && n <= len(items) {
				groups[normExpr(items[n-1].expr)] = true
				groups[lastSegment(normExpr(items[n-1].expr))] = true
			}
			continue
		}
		k := normExpr(g)
		groups[k] = true
		groups[lastSegment(k)] = true
		// GROUP BY alias
		for _, it := range items {
			if it.alias != "" && it.alias == k {
				groups[normExpr(it.expr)] = true
				groups[lastSegment(normExpr(it.expr))] = true
			}
		}
	}

	ok := func(e []tok) bool {
		if hasAggregate(e) || !hasIdentifier(e) || hasWindow(e) {
			return true
		}
		k := normExpr(e)
		return groups[k] || groups[lastSegment(k)]
	}
	wrapExpr := func(e []tok) []tok {
		return concatToks([]tok{word("ANY_VALUE"), punct("(")}, trimToks(e), []tok{punct(")")})
	}

	// Select list.
	var newSel []tok
	selChanged := false
	for i, it := range items {
		if i > 0 {
			newSel = append(newSel, punct(","), sp())
		}
		if ok(it.expr) {
			newSel = append(newSel, it.raw...)
			continue
		}
		newSel = append(newSel, wrapExpr(it.expr)...)
		if it.aliasTok != nil {
			newSel = append(newSel, sp(), word("AS"), sp(), *it.aliasTok)
		} else if isPlainColumn(it.expr) {
			newSel = append(newSel, sp(), word("AS"), sp(), tok{tkQuote, `"` + identName(it.expr[len(it.expr)-1]) + `"`})
		}
		selChanged = true
	}

	// ORDER BY.
	var newOrder []tok
	orderChanged := false
	oEnd := p.end
	if p.order >= 0 {
		oEnd = clauseEnd(p, p.order, p.limit)
		aliases := map[string]bool{}
		for _, it := range items {
			if it.alias != "" {
				aliases[it.alias] = true
			}
		}
		for i, o := range splitArgs(ts[afterBy(ts, p.order):oEnd]) {
			if i > 0 {
				newOrder = append(newOrder, punct(","), sp())
			}
			e := orderExpr(o)
			dir := orderDir(o)
			te := trimToks(e)
			if len(te) == 1 && (isNumberTok(te[0]) || aliases[strings.ToLower(identName(te[0]))]) || ok(e) {
				newOrder = append(newOrder, trimToks(o)...)
				continue
			}
			newOrder = append(newOrder, wrapExpr(e)...)
			newOrder = append(newOrder, dir...)
			orderChanged = true
		}
	}

	// HAVING: aliases are replaced by what they name.
	havingChanged := false
	var newHaving []tok
	if p.having >= 0 {
		hEnd := clauseEnd(p, p.having, p.order, p.limit)
		cond := ts[p.having+1 : hEnd]
		sub := substituteAliases(cond, items)
		if normExpr(sub) != normExpr(cond) {
			havingChanged = true
			newHaving = sub
		}
	}

	if !selChanged && !orderChanged && !havingChanged {
		return ts, changed
	}
	out := make([]tok, 0, len(ts)+16)
	out = append(out, ts[:p.selStart]...)
	out = append(out, sp())
	out = append(out, newSel...)
	out = append(out, sp())
	cur := p.from
	if havingChanged {
		hEnd := clauseEnd(p, p.having, p.order, p.limit)
		out = append(out, ts[cur:p.having+1]...)
		out = append(out, sp())
		out = append(out, trimToks(newHaving)...)
		out = append(out, sp())
		cur = hEnd
	}
	if orderChanged {
		out = append(out, ts[cur:afterBy(ts, p.order)]...)
		out = append(out, sp())
		out = append(out, newOrder...)
		out = append(out, sp())
		cur = oEnd
	}
	out = append(out, ts[cur:]...)
	return out, true
}

// fixOneSelectAfter reruns the rewrite on the SELECT at s after a first rewrite changed its shape.
func fixOneSelectAfter(ts []tok, s int) ([]tok, bool) {
	out, _ := fixOneSelect(ts, s)
	return out, true
}

// orderExpr is an ORDER BY item without its ASC or DESC.
func orderExpr(o []tok) []tok {
	o = trimToks(o)
	if n := len(o); n > 1 && (isKw(o[n-1], "ASC") || isKw(o[n-1], "DESC")) {
		return trimToks(o[:n-1])
	}
	return o
}

func orderDir(o []tok) []tok {
	o = trimToks(o)
	if n := len(o); n > 1 && (isKw(o[n-1], "ASC") || isKw(o[n-1], "DESC")) {
		return []tok{sp(), o[n-1]}
	}
	return nil
}

// substituteAliases replaces a select-list alias used in a condition by the expression it names.
func substituteAliases(cond []tok, items []selItem) []tok {
	var out []tok
	for i, t := range cond {
		if t.k == tkWord {
			p := prevSig(cond, i-1)
			n := nextSig(cond, i+1)
			if (p < 0 || !isP(cond[p], ".")) && (n < 0 || !isP(cond[n], "(")) {
				replaced := false
				for _, it := range items {
					if it.alias != "" && it.alias == strings.ToLower(t.s) && !(len(it.expr) == 1 && strings.EqualFold(identName(it.expr[0]), t.s)) {
						out = append(out, punct("("))
						out = append(out, it.expr...)
						out = append(out, punct(")"))
						replaced = true
						break
					}
				}
				if replaced {
					continue
				}
			}
		}
		out = append(out, t)
	}
	return out
}

// afterBy is the index just after the BY of the GROUP BY or ORDER BY whose first word is at i.
func afterBy(ts []tok, i int) int {
	return nextSig(ts, i+1) + 1
}

// hasWindow reports whether the expression holds a window function (OVER).
func hasWindow(ts []tok) bool {
	for _, t := range ts {
		if isKw(t, "OVER") {
			return true
		}
	}
	return false
}

// unionBefore reports whether the SELECT at s follows a UNION at the same depth.
func unionBefore(ts []tok, s int) bool {
	depth := 0
	for i := s - 1; i >= 0; i-- {
		if isP(ts[i], ")") {
			depth++
		} else if isP(ts[i], "(") {
			depth--
			if depth < 0 {
				return false
			}
		} else if depth == 0 && isKw(ts[i], "UNION") {
			return true
		}
	}
	return false
}

// isComparisonItem reports whether a select-list item is a comparison (a boolean in PostgreSQL, 0 or 1 in
// MySQL): it has a comparison operator outside any parentheses and outside any CASE.
func isComparisonItem(e []tok) bool {
	e = trimToks(e)
	// (a > b): look inside parentheses that enclose the whole item
	if len(e) > 2 && isP(e[0], "(") && matchClose(e, 0) == len(e)-1 {
		if n := nextSig(e, 1); n >= 0 && isKw(e[n], "SELECT") {
			return false // a scalar subquery
		}
		return isComparisonItem(e[1 : len(e)-1])
	}
	if len(e) == 0 || isKw(e[0], "CASE") {
		return false
	}
	depth, caseDepth := 0, 0
	for i, t := range e {
		// -> ->> @> <@ #> are jsonb operators, not comparisons
		if i > 0 && isP(t, ">") && (isP(e[i-1], "-") || isP(e[i-1], "@") || isP(e[i-1], "#") || isP(e[i-1], ">")) {
			continue
		}
		if isP(t, "<") && i+1 < len(e) && isP(e[i+1], "@") {
			continue
		}
		switch {
		case isP(t, "("):
			depth++
		case isP(t, ")"):
			depth--
		case isKw(t, "CASE"):
			caseDepth++
		case isKw(t, "END"):
			caseDepth--
		case depth == 0 && caseDepth == 0 && (isP(t, "=") || isP(t, ">") || isP(t, "<") || isKw(t, "IS") || isKw(t, "LIKE") || isKw(t, "IN") || isKw(t, "BETWEEN")):
			return true
		}
	}
	return false
}
