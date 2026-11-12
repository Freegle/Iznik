package database

import (
	"strings"
)

// catalog is what the translator needs to know about the schema: which unique indexes and columns a
// table has. The connector fills it from pg_catalog; tests use a fake.
type catalog interface {
	// unique returns the columns of the unique index an INSERT of cols can conflict on, or nil.
	unique(table string, cols []string) []string
	// uniques returns every unique index of the table, as column lists.
	uniques(table string) [][]string
	// columns returns the table's column names, or nil when the table is not known.
	columns(table string) []string
}

func isColumnOf(cat catalog, table, name string) bool {
	for _, c := range cat.columns(table) {
		if strings.EqualFold(c, name) {
			return true
		}
	}
	return false
}

// translateMore is the second half of translatePostgres: constructs that need the surrounding
// statement (index hints, GROUP_CONCAT, spatial constructors, LIMIT a, b, reserved words used as
// column names, and the INSERT forms).
func translateMore(ts []tok, cat catalog) ([]tok, bool) {
	changed := false

	// Index hints and STRAIGHT_JOIN.
	for i := 0; i < len(ts); i++ {
		if !(isKw(ts[i], "FORCE") || isKw(ts[i], "USE") || isKw(ts[i], "IGNORE")) {
			continue
		}
		n := nextSig(ts, i+1)
		if n < 0 || !(isKw(ts[n], "INDEX") || isKw(ts[n], "KEY")) {
			continue
		}
		o := nextSig(ts, n+1)
		for o >= 0 && !isP(ts[o], "(") {
			if !(isKw(ts[o], "FOR") || isKw(ts[o], "JOIN") || isKw(ts[o], "ORDER") || isKw(ts[o], "GROUP") || isKw(ts[o], "BY")) {
				o = -1
				break
			}
			o = nextSig(ts, o+1)
		}
		if o < 0 {
			continue
		}
		cl := matchClose(ts, o)
		if cl < 0 {
			continue
		}
		ts = concatToks(ts[:i], ts[cl+1:])
		i--
		changed = true
	}
	for i := range ts {
		if isKw(ts[i], "STRAIGHT_JOIN") {
			ts[i].s = "JOIN"
			changed = true
		}
	}

	// Function renames that need no argument changes.
	renames := map[string]string{
		"JSON_OBJECT":        "jsonb_build_object",
		"JSON_ARRAY":         "jsonb_build_array",
		"JSON_ARRAYAGG":      "jsonb_agg",
		"ST_DISTANCE_SPHERE": "ST_DistanceSphere",
		"POINT":              "ST_MakePoint",
		"MBRCONTAINS":        "ST_Contains",
	}
	for i, t := range ts {
		if t.k != tkWord {
			continue
		}
		n := nextSig(ts, i+1)
		if n < 0 || !isP(ts[n], "(") {
			continue
		}
		if r, ok := renames[strings.ToUpper(t.s)]; ok {
			p := prevSig(ts, i-1)
			if p >= 0 && isP(ts[p], ".") {
				continue
			}
			ts[i].s = r
			changed = true
		}
	}

	// JSON_OBJECT(k, v, ...): PostgreSQL cannot type a bound key, so each key is cast to text.
	for i := len(ts) - 1; i >= 0; i-- {
		if !isKw(ts[i], "jsonb_build_object") {
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
		var rep []tok
		for j, a := range args {
			if j > 0 {
				rep = append(rep, punct(","))
			}
			a = trimToks(a)
			if j%2 == 0 && len(a) == 1 && a[0].k == tkWord {
				rep = append(rep, a...)
				rep = append(rep, punct(":"), punct(":"), word("text"))
			} else {
				if j > 0 {
					rep = append(rep, sp())
				}
				rep = append(rep, a...)
			}
		}
		ts = concatToks(ts[:o+1], rep, ts[cl:])
		changed = true
	}

	// CAST(x AS JSON): a number becomes a JSON number, anything else is JSON text.
	for i := len(ts) - 1; i >= 0; i-- {
		if !isKw(ts[i], "CAST") {
			continue
		}
		cl := callEnd(ts, i)
		if cl < 0 {
			continue
		}
		o := nextSig(ts, i+1)
		inner := ts[o+1 : cl]
		as := -1
		depth := 0
		for j, t := range inner {
			if isP(t, "(") {
				depth++
			} else if isP(t, ")") {
				depth--
			} else if depth == 0 && isKw(t, "AS") {
				as = j
			}
		}
		if as < 0 || len(trimToks(inner[as+1:])) != 1 || !isKw(trimToks(inner[as+1:])[0], "JSON") {
			continue
		}
		val := trimToks(inner[:as])
		if len(val) == 1 && isNumberTok(val[0]) {
			ts = concatToks(ts[:i], []tok{word("to_jsonb"), punct("(")}, val, []tok{punct(")")}, ts[cl+1:])
		} else {
			ts = concatToks(ts[:i], []tok{punct("(")}, val, []tok{punct(")"), punct(":"), punct(":"), word("jsonb")}, ts[cl+1:])
		}
		changed = true
	}

	ts, changed = wrapJSONBranches(ts, changed)

	// JSON_SET(doc, p1, v1, p2, v2): one json_set call per pair, and a string value is text.
	for i := len(ts) - 1; i >= 0; i-- {
		if !isKw(ts[i], "JSON_SET") {
			continue
		}
		cl := callEnd(ts, i)
		if cl < 0 {
			continue
		}
		o := nextSig(ts, i+1)
		args := splitArgs(ts[o+1 : cl])
		if len(args) < 3 || len(args)%2 == 0 {
			continue
		}
		expr := trimToks(args[0])
		for n := 1; n+1 < len(args); n += 2 {
			v := trimToks(args[n+1])
			if len(v) == 1 && v[0].k == tkStr {
				v = []tok{v[0], punct(":"), punct(":"), word("text")}
			}
			expr = concatToks([]tok{word("json_set"), punct("(")}, expr, []tok{punct(","), sp()}, trimToks(args[n]), []tok{punct(","), sp()}, v, []tok{punct(")")})
		}
		ts = concatToks(ts[:i], expr, ts[cl+1:])
		changed = true
	}

	// The SRID label of a spatial constructor: the baseline relabels the columns from 3857 to 4326.
	for i := len(ts) - 1; i >= 0; i-- {
		if !(isKw(ts[i], "ST_GeomFromText") || isKw(ts[i], "ST_SetSRID") || isKw(ts[i], "ST_PointFromText") || isKw(ts[i], "ST_GeomFromGeoJSON")) {
			continue
		}
		cl := callEnd(ts, i)
		if cl < 0 {
			continue
		}
		if l := prevSig(ts, cl-1); l >= 0 && ts[l].k == tkWord && ts[l].s == "3857" {
			ts[l].s = "4326"
			changed = true
		}
	}

	// COALESCE(a comparison, 0 or 1): a comparison is a boolean here, 0 or 1 in MySQL.
	for i := 0; i < len(ts); i++ {
		if !isKw(ts[i], "COALESCE") {
			continue
		}
		cl := callEnd(ts, i)
		if cl < 0 {
			continue
		}
		o := nextSig(ts, i+1)
		args := splitArgs(ts[o+1 : cl])
		if len(args) != 2 {
			continue
		}
		last := trimToks(args[1])
		if len(last) != 1 || (last[0].s != "0" && last[0].s != "1") || last[0].k != tkWord {
			continue
		}
		cmp := false
		depth := 0
		for _, t := range args[0] {
			if isP(t, "(") {
				depth++
			} else if isP(t, ")") {
				depth--
			} else if depth == 0 && (isP(t, "=") || isP(t, ">") || isP(t, "<") || isKw(t, "LIKE") || isKw(t, "IN") || isKw(t, "IS") || isKw(t, "AND") || isKw(t, "OR")) {
				cmp = true
			}
		}
		if !cmp {
			continue
		}
		val := "false"
		if last[0].s == "1" {
			val = "true"
		}
		ts = concatToks(ts[:o+1], trimToks(args[0]), []tok{punct(","), sp(), word(val)}, ts[cl:])
		changed = true
	}

	// ST_SRID(g, n) sets the SRID in MySQL.
	for i := len(ts) - 1; i >= 0; i-- {
		if !isKw(ts[i], "ST_SRID") {
			continue
		}
		o := nextSig(ts, i+1)
		if o < 0 || !isP(ts[o], "(") {
			continue
		}
		cl := matchClose(ts, o)
		if cl > 0 && len(splitArgs(ts[o+1:cl])) == 2 {
			ts[i].s = "ST_SetSRID"
			changed = true
		}
	}

	// LINESTRING(a, b, ...) and POLYGON(ring).
	for i := len(ts) - 1; i >= 0; i-- {
		ls := isKw(ts[i], "LINESTRING")
		pg := isKw(ts[i], "POLYGON")
		if !ls && !pg {
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
		inner := ts[o+1 : cl]
		var rep []tok
		if ls {
			rep = concatToks([]tok{word("ST_MakeLine"), punct("("), word("ARRAY"), punct("[")}, inner, []tok{punct("]"), punct(")")})
		} else {
			rep = concatToks([]tok{word("ST_MakePolygon"), punct("(")}, inner, []tok{punct(")")})
		}
		ts = concatToks(ts[:i], rep, ts[cl+1:])
		changed = true
	}

	// MBRIntersects(a, b) is a bounding-box overlap.
	for i := len(ts) - 1; i >= 0; i-- {
		if !isKw(ts[i], "MBRINTERSECTS") {
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
		rep := concatToks([]tok{punct("(")}, trimToks(args[0]), []tok{sp(), punct("&"), punct("&"), sp()}, trimToks(args[1]), []tok{punct(")")})
		ts = concatToks(ts[:i], rep, ts[cl+1:])
		changed = true
	}

	// TIMESTAMPDIFF(UNIT, a, b) takes the unit as a bare word.
	for i := 0; i < len(ts); i++ {
		if !isKw(ts[i], "TIMESTAMPDIFF") {
			continue
		}
		o := nextSig(ts, i+1)
		if o < 0 || !isP(ts[o], "(") {
			continue
		}
		u := nextSig(ts, o+1)
		if u >= 0 && ts[u].k == tkWord {
			ts[u] = tok{tkStr, "'" + strings.ToLower(ts[u].s) + "'"}
			changed = true
		}
	}

	// GROUP_CONCAT([DISTINCT] x [ORDER BY ...] [SEPARATOR 's']).
	for i := len(ts) - 1; i >= 0; i-- {
		if !isKw(ts[i], "GROUP_CONCAT") {
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
		inner := ts[o+1 : cl]
		sep := []tok{{tkStr, "','"}}
		sepAt := -1
		depth := 0
		for j, t := range inner {
			if isP(t, "(") {
				depth++
			} else if isP(t, ")") {
				depth--
			} else if depth == 0 && isKw(t, "SEPARATOR") {
				sepAt = j
			}
		}
		if sepAt >= 0 {
			sep = trimToks(inner[sepAt+1:])
			inner = inner[:sepAt]
		}
		orderAt := -1
		depth = 0
		for j, t := range inner {
			if isP(t, "(") {
				depth++
			} else if isP(t, ")") {
				depth--
			} else if depth == 0 && isKw(t, "ORDER") {
				orderAt = j
				break
			}
		}
		var order []tok
		if orderAt >= 0 {
			order = inner[orderAt:]
			inner = inner[:orderAt]
		}
		inner = trimToks(inner)
		var distinct []tok
		if len(inner) > 0 && isKw(inner[0], "DISTINCT") {
			distinct = []tok{word("DISTINCT"), sp()}
			inner = trimToks(inner[1:])
		}
		args := splitArgs(inner)
		var expr []tok
		if len(args) > 1 {
			expr = concatToks([]tok{word("CONCAT"), punct("(")}, inner, []tok{punct(")")})
		} else {
			expr = inner
		}
		rep := concatToks([]tok{word("string_agg"), punct("(")}, distinct, []tok{punct("(")}, expr, []tok{punct(")"), punct(":"), punct(":"), word("text"), punct(","), sp()}, sep)
		if len(order) > 0 {
			rep = append(rep, sp())
			rep = append(rep, trimToks(order)...)
		}
		rep = append(rep, punct(")"))
		ts = concatToks(ts[:i], rep, ts[cl+1:])
		changed = true
	}

	// LIMIT a, b -> LIMIT b OFFSET a.
	for i := 0; i < len(ts); i++ {
		if !isKw(ts[i], "LIMIT") {
			continue
		}
		a := nextSig(ts, i+1)
		if a < 0 || ts[a].k != tkWord {
			continue
		}
		c := nextSig(ts, a+1)
		if c < 0 || !isP(ts[c], ",") {
			continue
		}
		b := nextSig(ts, c+1)
		if b < 0 || ts[b].k != tkWord {
			continue
		}
		ts = concatToks(ts[:a], []tok{ts[b], sp(), word("OFFSET"), sp(), ts[a]}, ts[b+1:])
		changed = true
	}

	// Words PostgreSQL reserves that MySQL lets a column be called.
	caseDepth := 0
	for i := range ts {
		t := ts[i]
		if t.k != tkWord {
			continue
		}
		switch strings.ToLower(t.s) {
		case "case":
			caseDepth++
		case "end":
			if caseDepth > 0 {
				caseDepth--
			} else {
				ts[i] = tok{tkQuote, `"end"`}
				changed = true
			}
		case "user":
			if n := nextSig(ts, i+1); n >= 0 && isP(ts[n], "(") {
				continue
			}
			p := prevSig(ts, i-1)
			if p >= 0 && (isKw(ts[p], "CREATE") || isKw(ts[p], "DROP") || isKw(ts[p], "FOR") || isKw(ts[p], "TO") || isKw(ts[p], "TABLE")) {
				continue
			}
			ts[i] = tok{tkQuote, `"user"`}
			changed = true
		case "group":
			if n := nextSig(ts, i+1); n >= 0 && isKw(ts[n], "BY") {
				continue
			}
			if p := prevSig(ts, i-1); p >= 0 && isP(ts[p], ".") {
				ts[i] = tok{tkQuote, `"group"`}
				changed = true
			}
		}
	}

	if t, ok := fixGrouping(ts); ok {
		ts = t
		changed = true
	}

	if t, ok := translateJoinDML(ts); ok {
		ts = t
		changed = true
	}

	if cat != nil {
		var c2 bool
		ts, c2 = translateStatement(ts, cat)
		changed = changed || c2
	}
	return ts, changed
}

// translateStatement handles the statements that need the catalog: CREATE TABLE IF NOT EXISTS for a
// table the baseline already has, INSERT IGNORE, REPLACE INTO, ON DUPLICATE KEY UPDATE and a bare
// ON CONFLICT DO UPDATE (which GORM renders when no conflict columns were named).
func translateStatement(ts []tok, cat catalog) ([]tok, bool) {
	first := nextSig(ts, 0)
	if first < 0 {
		return ts, false
	}
	// CREATE TABLE IF NOT EXISTS t (...): the baseline has the table, so there is nothing to create.
	if isKw(ts[first], "CREATE") {
		i := first
		for _, kw := range []string{"TABLE", "IF", "NOT", "EXISTS"} {
			i = nextSig(ts, i+1)
			if i < 0 || !isKw(ts[i], kw) {
				return ts, false
			}
		}
		n := nextSig(ts, i+1)
		if n < 0 {
			return ts, false
		}
		if cat.columns(identName(ts[n])) != nil {
			return []tok{word("SELECT"), sp(), word("1")}, true
		}
		return ts, false
	}

	// ALTER TABLE t ADD COLUMN c ...: nothing to do when the baseline already has the column.
	if isKw(ts[first], "ALTER") {
		t := nextSig(ts, first+1)
		if t < 0 || !isKw(ts[t], "TABLE") {
			return ts, false
		}
		n := nextSig(ts, t+1)
		a := -1
		if n >= 0 {
			a = nextSig(ts, n+1)
		}
		if a < 0 || !isKw(ts[a], "ADD") {
			return ts, false
		}
		c := nextSig(ts, a+1)
		if c >= 0 && isKw(ts[c], "COLUMN") {
			c = nextSig(ts, c+1)
		}
		if c >= 0 && isColumnOf(cat, identName(ts[n]), identName(ts[c])) {
			return []tok{word("SELECT"), sp(), word("1")}, true
		}
		return ts, false
	}

	// UPDATE IGNORE t SET c = v WHERE w: rows whose new key would collide are left alone.
	if isKw(ts[first], "UPDATE") {
		return translateUpdateIgnore(ts, first, cat)
	}

	replace := isKw(ts[first], "REPLACE")
	if !isKw(ts[first], "INSERT") && !replace {
		return ts, false
	}
	table, cols := insertTarget(ts)
	if table == "" {
		return ts, false
	}

	if isKw(ts[first], "INSERT") {
		n := nextSig(ts, first+1)
		if n >= 0 && isKw(ts[n], "IGNORE") {
			ts = concatToks(ts[:n], ts[n+1:])
			return appendOnConflictDoNothing(ts), true
		}
	}

	if replace {
		target := cat.unique(table, cols)
		if target == nil {
			return ts, false
		}
		ts[first] = word("INSERT")
		var set []tok
		in := map[string]bool{}
		for _, c := range target {
			in[strings.ToLower(c)] = true
		}
		for _, c := range cols {
			if in[strings.ToLower(c)] {
				continue
			}
			if len(set) > 0 {
				set = append(set, punct(","), sp())
			}
			set = append(set, tok{tkQuote, `"` + c + `"`}, sp(), punct("="), sp(), word("EXCLUDED"), punct("."), tok{tkQuote, `"` + c + `"`})
		}
		return appendOnConflict(ts, target, set), true
	}

	for i := 0; i < len(ts); i++ {
		if !isKw(ts[i], "ON") {
			continue
		}
		d := nextSig(ts, i+1)
		if d < 0 {
			continue
		}
		var u int
		switch {
		case isKw(ts[d], "DUPLICATE"):
			k := nextSig(ts, d+1)
			if k < 0 || !isKw(ts[k], "KEY") {
				continue
			}
			u = nextSig(ts, k+1)
			if u < 0 || !isKw(ts[u], "UPDATE") {
				continue
			}
		case isKw(ts[d], "CONFLICT"):
			// Only the form with no conflict target: ON CONFLICT DO UPDATE SET.
			x := nextSig(ts, d+1)
			if x < 0 || !isKw(ts[x], "DO") {
				continue
			}
			y := nextSig(ts, x+1)
			if y < 0 || !isKw(ts[y], "UPDATE") {
				continue
			}
			u = nextSig(ts, y+1) // SET
			if u < 0 || !isKw(ts[u], "SET") {
				continue
			}
		default:
			continue
		}
		target := cat.unique(table, cols)
		if target == nil {
			return ts, false
		}
		rest := qualifyUpdate(ts[u+1:], table, cat)
		var assigns []tok
		assigns = append(assigns, rest...)
		return concatToks(ts[:i], onConflictHead(target), []tok{sp(), word("SET"), sp()}, trimToks(assigns)), true
	}
	return ts, false
}

func onConflictHead(target []string) []tok {
	t := []tok{word("ON"), sp(), word("CONFLICT"), sp(), punct("(")}
	for j, c := range target {
		if j > 0 {
			t = append(t, punct(","))
		}
		t = append(t, tok{tkQuote, `"` + c + `"`})
	}
	return append(t, punct(")"), sp(), word("DO"), sp(), word("UPDATE"))
}

func appendOnConflict(ts []tok, target []string, set []tok) []tok {
	for len(ts) > 0 && (ts[len(ts)-1].k == tkSpace || isP(ts[len(ts)-1], ";")) {
		ts = ts[:len(ts)-1]
	}
	if len(set) == 0 {
		return concatToks(ts, []tok{sp(), word("ON"), sp(), word("CONFLICT"), sp(), word("DO"), sp(), word("NOTHING")})
	}
	return concatToks(ts, []tok{sp()}, onConflictHead(target), []tok{sp(), word("SET"), sp()}, set)
}

// qualifyUpdate rewrites the assignments of an upsert for PostgreSQL: VALUES(col) is EXCLUDED.col,
// LAST_INSERT_ID(id) keeps the row's own id, and a bare column name on the right-hand side is the
// existing row, which PostgreSQL wants qualified with the table because EXCLUDED has the same names.
func qualifyUpdate(ts []tok, table string, cat catalog) []tok {
	out := make([]tok, 0, len(ts))
	// Split into assignments so the left-hand side is left alone.
	for _, a := range splitArgs(ts) {
		eq := -1
		depth := 0
		for j, t := range a {
			if isP(t, "(") {
				depth++
			} else if isP(t, ")") {
				depth--
			} else if depth == 0 && isP(t, "=") {
				eq = j
				break
			}
		}
		if len(out) > 0 {
			out = append(out, punct(","))
		}
		if eq < 0 {
			out = append(out, a...)
			continue
		}
		lhs, rhs := a[:eq+1], a[eq+1:]
		out = append(out, lhs...)
		qt := tok{tkQuote, `"` + table + `"`}
		for j := 0; j < len(rhs); j++ {
			t := rhs[j]
			if isKw(t, "VALUES") {
				if o := nextSig(rhs, j+1); o >= 0 && isP(rhs[o], "(") {
					if cl := matchClose(rhs, o); cl > 0 {
						out = append(out, word("EXCLUDED"), punct("."))
						out = append(out, trimToks(rhs[o+1:cl])...)
						j = cl
						continue
					}
				}
			}
			if isKw(t, "LAST_INSERT_ID") {
				if o := nextSig(rhs, j+1); o >= 0 && isP(rhs[o], "(") {
					if cl := matchClose(rhs, o); cl > 0 {
						out = append(out, qt, punct("."))
						out = append(out, trimToks(rhs[o+1:cl])...)
						j = cl
						continue
					}
				}
			}
			if t.k == tkWord || t.k == tkQuote {
				p := prevSig(rhs, j-1)
				n := nextSig(rhs, j+1)
				name := identName(t)
				if (p < 0 || (!isP(rhs[p], ".") && !isKw(rhs[p], "AS"))) && (n < 0 || (!isP(rhs[n], "(") && !isP(rhs[n], "."))) && isColumnOf(cat, table, name) {
					out = append(out, qt, punct("."))
					out = append(out, tok{tkQuote, `"` + name + `"`})
					continue
				}
			}
			out = append(out, t)
		}
	}
	return out
}

// callEnd returns the index of the ')' that closes a call starting at ts[i] (a word followed by '('),
// or -1.
func callEnd(ts []tok, i int) int {
	o := nextSig(ts, i+1)
	if o < 0 || !isP(ts[o], "(") {
		return -1
	}
	return matchClose(ts, o)
}

func isNumberTok(t tok) bool {
	return t.k == tkWord && t.s != "" && t.s[0] >= '0' && t.s[0] <= '9'
}

// wrapJSONBranches handles a number next to a JSON_EXTRACT call as the alternatives of CASE or the
// arguments of COALESCE. MySQL takes a number and a JSON value as the same kind; PostgreSQL will not
// mix a number and jsonb, so the number is made a jsonb value.
func wrapJSONBranches(ts []tok, changed bool) ([]tok, bool) {
	wrap := func(seg []tok) ([]tok, bool) {
		seg = trimToks(seg)
		if len(seg) == 1 && isNumberTok(seg[0]) {
			return []tok{word("to_jsonb"), punct("("), seg[0], punct(")")}, true
		}
		return seg, false
	}
	isJSONCall := func(seg []tok) bool {
		seg = trimToks(seg)
		return len(seg) > 2 && isKw(seg[0], "json_extract") && callEnd(seg, 0) == len(seg)-1
	}
	// CASE ... THEN x ... ELSE y END with simple results.
	for i := 0; i < len(ts); i++ {
		if !isKw(ts[i], "CASE") {
			continue
		}
		depth := 0
		end := -1
		for j := i; j < len(ts); j++ {
			if isP(ts[j], "(") {
				depth++
			} else if isP(ts[j], ")") {
				depth--
			} else if depth == 0 && isKw(ts[j], "END") {
				end = j
				break
			}
		}
		if end < 0 {
			continue
		}
		// result segments: after THEN up to the next WHEN/ELSE/END, and after ELSE up to END.
		type seg struct{ from, to int }
		var segs []seg
		depth = 0
		for j := i + 1; j < end; j++ {
			if isP(ts[j], "(") {
				depth++
			} else if isP(ts[j], ")") {
				depth--
			} else if depth == 0 && (isKw(ts[j], "THEN") || isKw(ts[j], "ELSE")) {
				k := j + 1
				d2 := 0
				for ; k < end; k++ {
					if isP(ts[k], "(") {
						d2++
					} else if isP(ts[k], ")") {
						d2--
					} else if d2 == 0 && (isKw(ts[k], "WHEN") || isKw(ts[k], "ELSE")) {
						break
					}
				}
				segs = append(segs, seg{j + 1, k})
			}
		}
		any := false
		for _, sg := range segs {
			if isJSONCall(ts[sg.from:sg.to]) {
				any = true
			}
		}
		if !any {
			continue
		}
		for n := len(segs) - 1; n >= 0; n-- {
			if rep, ok := wrap(ts[segs[n].from:segs[n].to]); ok {
				ts = concatToks(ts[:segs[n].from], []tok{sp()}, rep, []tok{sp()}, ts[segs[n].to:])
				changed = true
			}
		}
	}
	// COALESCE(a, b, ...).
	for i := 0; i < len(ts); i++ {
		if !isKw(ts[i], "COALESCE") {
			continue
		}
		cl := callEnd(ts, i)
		if cl < 0 {
			continue
		}
		o := nextSig(ts, i+1)
		args := splitArgs(ts[o+1 : cl])
		any := false
		for _, a := range args {
			if isJSONCall(a) {
				any = true
			}
		}
		if !any {
			continue
		}
		var rep []tok
		did := false
		for n, a := range args {
			if n > 0 {
				rep = append(rep, punct(","), sp())
			}
			if w, ok := wrap(a); ok {
				rep = append(rep, w...)
				did = true
			} else {
				rep = append(rep, trimToks(a)...)
			}
		}
		if did {
			ts = concatToks(ts[:o+1], rep, ts[cl:])
			changed = true
		}
	}
	return ts, changed
}

func translateUpdateIgnore(ts []tok, first int, cat catalog) ([]tok, bool) {
	ig := nextSig(ts, first+1)
	if ig < 0 || !isKw(ts[ig], "IGNORE") {
		return ts, false
	}
	tbl := nextSig(ts, ig+1)
	set := nextSig(ts, tbl+1)
	if tbl < 0 || set < 0 || !isKw(ts[set], "SET") {
		return ts, false
	}
	table := identName(ts[tbl])
	where := topKw(ts, set+1, "WHERE")
	var assigns, cond []tok
	if where >= 0 {
		assigns, cond = ts[set+1:where], trimToks(ts[where+1:])
	} else {
		assigns = ts[set+1:]
	}
	newVal := map[string][]tok{}
	for _, a := range splitArgs(assigns) {
		a = trimToks(a)
		if len(a) < 3 || !isP(a[1], "=") {
			return ts, false
		}
		val := trimToks(a[2:])
		if hasIdentifier(val) {
			if len(val) == 1 && strings.EqualFold(identName(val[0]), identName(a[0])) {
				continue // col = col changes nothing
			}
			return ts, false
		}
		newVal[strings.ToLower(identName(a[0]))] = val
	}
	var guards []tok
	for _, ix := range cat.uniques(table) {
		touched := false
		for _, c := range ix {
			if _, ok := newVal[strings.ToLower(c)]; ok {
				touched = true
			}
		}
		if !touched {
			continue
		}
		var conds []tok
		for _, c := range ix {
			if len(conds) > 0 {
				conds = append(conds, sp(), word("AND"), sp())
			}
			col := tok{tkQuote, `"` + c + `"`}
			conds = append(conds, word("ig_x"), punct("."), col, sp(), punct("="), sp())
			if v, ok := newVal[strings.ToLower(c)]; ok {
				conds = append(conds, punct("("))
				conds = append(conds, v...)
				conds = append(conds, punct(")"))
			} else {
				conds = append(conds, tok{tkQuote, `"` + table + `"`}, punct("."), col)
			}
		}
		if len(guards) > 0 {
			guards = append(guards, sp(), word("AND"), sp())
		}
		guards = concatToks(guards, []tok{word("NOT"), sp(), word("EXISTS"), sp(), punct("("), word("SELECT"), sp(), word("1"), sp(), word("FROM"), sp(), tok{tkQuote, `"` + table + `"`}, sp(), word("ig_x"), sp(), word("WHERE"), sp()}, conds, []tok{punct(")")})
	}
	out := concatToks([]tok{word("UPDATE"), sp(), ts[tbl], sp(), word("SET"), sp()}, trimToks(assigns))
	if len(guards) > 0 || len(cond) > 0 {
		out = append(out, sp(), word("WHERE"), sp())
		if len(cond) > 0 {
			out = concatToks(out, []tok{punct("(")}, cond, []tok{punct(")")})
			if len(guards) > 0 {
				out = append(out, sp(), word("AND"), sp())
			}
		}
		out = append(out, guards...)
	}
	return out, true
}
