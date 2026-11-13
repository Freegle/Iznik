package database

import "strings"

// topKw returns the index of the first top-level keyword kw in ts at or after from, or -1.
func topKw(ts []tok, from int, kw string) int {
	depth := 0
	for i := from; i < len(ts); i++ {
		if isP(ts[i], "(") {
			depth++
		} else if isP(ts[i], ")") {
			depth--
		} else if depth == 0 && isKw(ts[i], kw) {
			return i
		}
	}
	return -1
}

// joinPart is one "JOIN table [alias] ON condition" of a multi-table UPDATE or DELETE.
type joinPart struct {
	table []tok
	cond  []tok
}

// splitInnerJoins splits "a JOIN b ON x JOIN c ON y" (the tokens after the first table) into the
// joined tables and conditions. It returns false for anything but inner joins.
func splitInnerJoins(ts []tok) ([]joinPart, bool) {
	var parts []joinPart
	i := 0
	for i < len(ts) {
		j := nextSig(ts, i)
		if j < 0 {
			break
		}
		if isKw(ts[j], "INNER") || isKw(ts[j], "CROSS") {
			j = nextSig(ts, j+1)
		}
		if j < 0 || !isKw(ts[j], "JOIN") {
			return nil, false
		}
		on := topKw(ts, j+1, "ON")
		if on < 0 {
			return nil, false
		}
		end := len(ts)
		for k := on + 1; k < len(ts); k++ {
			if isKw(ts[k], "JOIN") || isKw(ts[k], "INNER") || isKw(ts[k], "LEFT") || isKw(ts[k], "RIGHT") || isKw(ts[k], "CROSS") {
				end = k
				break
			}
		}
		for k := j + 1; k < on; k++ {
			if isKw(ts[k], "LEFT") || isKw(ts[k], "RIGHT") {
				return nil, false
			}
		}
		parts = append(parts, joinPart{table: trimToks(ts[j+1 : on]), cond: trimToks(ts[on+1 : end])})
		i = end
		if end < len(ts) && (isKw(ts[end], "LEFT") || isKw(ts[end], "RIGHT")) {
			return nil, false
		}
	}
	return parts, len(parts) > 0
}

func andConds(parts []joinPart, where []tok) []tok {
	var out []tok
	for _, p := range parts {
		if len(out) > 0 {
			out = append(out, sp(), word("AND"), sp())
		}
		out = concatToks(out, []tok{punct("(")}, p.cond, []tok{punct(")")})
	}
	if len(where) > 0 {
		out = concatToks(out, []tok{sp(), word("AND"), sp(), punct("(")}, trimToks(where), []tok{punct(")")})
	}
	return out
}

// translateJoinDML rewrites MySQL's multi-table UPDATE and DELETE (inner joins only) into
// UPDATE ... FROM and DELETE ... USING.
func translateJoinDML(ts []tok) ([]tok, bool) {
	first := nextSig(ts, 0)
	if first < 0 {
		return ts, false
	}
	if isKw(ts[first], "UPDATE") {
		join := -1
		set := topKw(ts, first+1, "SET")
		if set < 0 {
			return ts, false
		}
		for k := first + 1; k < set; k++ {
			if isKw(ts[k], "JOIN") || isKw(ts[k], "INNER") {
				join = k
				break
			}
		}
		if join < 0 {
			return ts, false
		}
		parts, ok := splitInnerJoins(ts[join:set])
		if !ok {
			return ts, false
		}
		target := trimToks(ts[first+1 : join])
		where := topKw(ts, set+1, "WHERE")
		var assigns, whereToks []tok
		if where >= 0 {
			assigns, whereToks = ts[set+1:where], ts[where+1:]
		} else {
			assigns = ts[set+1:]
		}
		// The left-hand side of an assignment may not be qualified in PostgreSQL.
		var sets []tok
		for _, a := range splitArgs(assigns) {
			a = trimToks(a)
			if len(a) >= 3 && isP(a[1], ".") {
				a = a[2:]
				a = trimToks(a)
			}
			if len(sets) > 0 {
				sets = append(sets, punct(","), sp())
			}
			sets = append(sets, a...)
		}
		var from []tok
		for i, p := range parts {
			if i > 0 {
				from = append(from, punct(","), sp())
			}
			from = append(from, p.table...)
		}
		out := concatToks([]tok{word("UPDATE"), sp()}, target, []tok{sp(), word("SET"), sp()}, sets, []tok{sp(), word("FROM"), sp()}, from, []tok{sp(), word("WHERE"), sp()}, andConds(parts, whereToks))
		return out, true
	}
	if isKw(ts[first], "DELETE") {
		from := topKw(ts, first+1, "FROM")
		if from < 0 || from == nextSig(ts, first+1) && false {
			return ts, false
		}
		targets := trimToks(ts[first+1 : from])
		if len(targets) != 1 || targets[0].k == tkPunct {
			return ts, false // DELETE FROM t ..., or several targets
		}
		join := -1
		for k := from + 1; k < len(ts); k++ {
			if isKw(ts[k], "JOIN") || isKw(ts[k], "INNER") {
				join = k
				break
			}
		}
		if join < 0 {
			return ts, false
		}
		where := topKw(ts, join, "WHERE")
		var joined, whereToks []tok
		if where >= 0 {
			joined, whereToks = ts[join:where], ts[where+1:]
		} else {
			joined = ts[join:]
		}
		parts, ok := splitInnerJoins(joined)
		if !ok {
			return ts, false
		}
		var using []tok
		for i, p := range parts {
			if i > 0 {
				using = append(using, punct(","), sp())
			}
			using = append(using, p.table...)
		}
		out := concatToks([]tok{word("DELETE"), sp(), word("FROM"), sp()}, trimToks(ts[from+1:join]), []tok{sp(), word("USING"), sp()}, using, []tok{sp(), word("WHERE"), sp()}, andConds(parts, whereToks))
		return out, true
	}
	return ts, false
}

var _ = strings.ToLower

// translateLimitedDML rewrites a single-table UPDATE or DELETE that has ORDER BY or LIMIT, which PostgreSQL
// does not allow, into one that picks the rows by ctid in a subselect.
func translateLimitedDML(ts []tok) ([]tok, bool) {
	first := nextSig(ts, 0)
	if first < 0 {
		return ts, false
	}
	upd := isKw(ts[first], "UPDATE")
	if !upd && !isKw(ts[first], "DELETE") {
		return ts, false
	}
	order := topKw(ts, first+1, "ORDER")
	limit := topKw(ts, first+1, "LIMIT")
	cut := order
	if cut < 0 || (limit >= 0 && limit < cut) {
		cut = limit
	}
	if cut < 0 {
		return ts, false
	}
	var table, head []tok
	var whereAt int
	if upd {
		tbl := nextSig(ts, first+1)
		set := nextSig(ts, tbl+1)
		if tbl < 0 || set < 0 || !isKw(ts[set], "SET") {
			return ts, false
		}
		table = []tok{ts[tbl]}
		whereAt = topKw(ts, set+1, "WHERE")
		end := cut
		if whereAt >= 0 {
			end = whereAt
		}
		head = concatToks([]tok{word("UPDATE"), sp(), ts[tbl], sp()}, trimToks(ts[set:end]))
	} else {
		from := nextSig(ts, first+1)
		if from < 0 || !isKw(ts[from], "FROM") {
			return ts, false
		}
		tbl := nextSig(ts, from+1)
		if tbl < 0 {
			return ts, false
		}
		if n := nextSig(ts, tbl+1); n >= 0 && n < cut && !isKw(ts[n], "WHERE") {
			return ts, false // an alias or a join
		}
		table = []tok{ts[tbl]}
		whereAt = topKw(ts, tbl+1, "WHERE")
		head = []tok{word("DELETE"), sp(), word("FROM"), sp(), ts[tbl]}
	}
	var where []tok
	if whereAt >= 0 && whereAt < cut {
		where = trimToks(ts[whereAt+1 : cut])
	}
	tail := trimToks(ts[cut:])
	sub := concatToks([]tok{word("SELECT"), sp(), word("ctid"), sp(), word("FROM"), sp()}, table)
	if len(where) > 0 {
		sub = concatToks(sub, []tok{sp(), word("WHERE"), sp()}, where)
	}
	sub = concatToks(sub, []tok{sp()}, tail)
	out := concatToks(head, []tok{sp(), word("WHERE"), sp(), word("ctid"), sp(), word("IN"), sp(), punct("(")}, sub, []tok{punct(")")})
	return out, true
}
