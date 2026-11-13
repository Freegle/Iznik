package database

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"io"
	"reflect"
	"strings"
	"sync"
	"time"
)

// pgTranslatingConnector wraps a connector so that every statement sent on its connections is
// translated from MySQL to PostgreSQL syntax first (pgtranslate.go). It also gives an INSERT the
// LastInsertId that database/sql callers expect and the pgx driver does not provide: an INSERT into a
// table with an id column is sent with RETURNING id, and the first id comes back as the result.
type pgTranslatingConnector struct {
	inner driver.Connector
	mu    sync.Mutex
	cache sync.Map // sql -> pgStatement
	idx   map[string][][]string
	cols  map[string][]string
	defs  map[string]map[string]any // table -> column -> implicit default of a NOT NULL column
	ready bool
}

type pgStatement struct {
	sql      string
	insertID bool      // an INSERT into a table with an id column, no RETURNING
	jsonArgs bool      // has a JSON constructor whose bound arguments need a type
	nullCols []nullCol // bound parameters of a one-row INSERT that are columns with an implicit default
}

// nullCol ties a bound parameter to the NOT NULL column it is inserted into.
type nullCol struct {
	param int // 1 based
	def   any
}

func newPgTranslatingConnector(inner driver.Connector) *pgTranslatingConnector {
	return &pgTranslatingConnector{inner: inner}
}

func (c *pgTranslatingConnector) Driver() driver.Driver { return c.inner.Driver() }

func (c *pgTranslatingConnector) Connect(ctx context.Context) (driver.Conn, error) {
	conn, err := c.inner.Connect(ctx)
	if err != nil {
		return nil, err
	}
	return &pgTranslatingConn{Conn: conn, c: c}, nil
}

func (c *pgTranslatingConnector) statement(q string) pgStatement {
	if v, ok := c.cache.Load(q); ok {
		return v.(pgStatement)
	}
	t := translatePostgres(q, c)
	st := pgStatement{sql: t, jsonArgs: strings.Contains(t, "jsonb_build_") || strings.Contains(t, "json_set(") || strings.Contains(strings.ToLower(t), "concat(")}
	if table, ok := plainInsertTable(t); ok && isColumnOf(c, table, "id") {
		st.insertID = true
	}
	st.nullCols = c.insertNullCols(t)
	c.cache.Store(q, st)
	return st
}

// plainInsertTable returns the table of an INSERT statement that has no RETURNING clause.
func plainInsertTable(sql string) (string, bool) {
	ts := lexSQL(sql)
	f := nextSig(ts, 0)
	if f < 0 || !isKw(ts[f], "INSERT") {
		return "", false
	}
	for _, t := range ts {
		if isKw(t, "RETURNING") {
			return "", false
		}
	}
	table, _ := insertTarget(ts)
	return table, table != ""
}

// load reads the unique indexes and columns of the public schema once. Unique indexes come primary key
// first, then by name, which is the order of preference for an upsert target.
func (c *pgTranslatingConnector) load() {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.ready {
		return
	}
	c.ready = true
	c.idx = map[string][][]string{}
	c.cols = map[string][]string{}
	c.defs = map[string]map[string]any{}
	db := sql.OpenDB(c.inner)
	defer db.Close()
	// keep-raw: reads pg_catalog for the translator; PostgreSQL only, nothing MySQL has an equivalent of.
	rows, err := db.Query(`SELECT t.relname, i.relname, ix.indisprimary,
		array_to_string(ARRAY(SELECT a.attname FROM unnest(ix.indkey) WITH ORDINALITY k(attnum, ord)
			JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum ORDER BY k.ord), ',')
		FROM pg_index ix JOIN pg_class t ON t.oid = ix.indrelid JOIN pg_class i ON i.oid = ix.indexrelid
		WHERE ix.indisunique AND ix.indpred IS NULL AND ix.indexprs IS NULL AND t.relnamespace = 'public'::regnamespace
		ORDER BY t.relname, ix.indisprimary DESC, i.relname`)
	if err == nil {
		for rows.Next() {
			var table, name, cols string
			var primary bool
			if rows.Scan(&table, &name, &primary, &cols) == nil {
				c.idx[table] = append(c.idx[table], strings.Split(cols, ","))
			}
		}
		rows.Close()
	}
	// keep-raw: reads pg_catalog for the translator; PostgreSQL only, nothing MySQL has an equivalent of.
	rows, err = db.Query(`SELECT c.relname, a.attname, a.attnotnull AND a.attidentity = '' AND a.attgenerated = '' AS plain_not_null, t.typname,
		EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conrelid = c.oid AND k.contype = 'c' AND k.conname = c.relname || '_' || a.attname || '_check') AS has_check
		FROM pg_class c JOIN pg_attribute a ON a.attrelid = c.oid JOIN pg_type t ON t.oid = a.atttypid
		WHERE c.relkind IN ('r', 'p') AND c.relnamespace = 'public'::regnamespace AND a.attnum > 0 AND NOT a.attisdropped
		ORDER BY c.relname, a.attnum`)
	if err == nil {
		for rows.Next() {
			var table, col, typ string
			var notNull, hasCheck bool
			if rows.Scan(&table, &col, &notNull, &typ, &hasCheck) == nil {
				c.cols[table] = append(c.cols[table], col)
				if notNull && !hasCheck {
					if d, ok := implicitDefault(typ); ok {
						if c.defs[table] == nil {
							c.defs[table] = map[string]any{}
						}
						c.defs[table][col] = d
					}
				}
			}
		}
		rows.Close()
	}
}

func (c *pgTranslatingConnector) columns(table string) []string {
	c.load()
	return c.cols[strings.ToLower(table)]
}

func (c *pgTranslatingConnector) uniques(table string) [][]string {
	c.load()
	return c.idx[strings.ToLower(table)]
}

func (c *pgTranslatingConnector) unique(table string, cols []string) []string {
	if table == "" || len(cols) == 0 {
		return nil
	}
	c.load()
	have := map[string]bool{}
	for _, col := range cols {
		have[strings.ToLower(col)] = true
	}
	for _, ix := range c.idx[strings.ToLower(table)] {
		ok := true
		for _, col := range ix {
			if !have[strings.ToLower(col)] {
				ok = false
				break
			}
		}
		if ok {
			return ix
		}
	}
	return nil
}

type pgTranslatingConn struct {
	driver.Conn
	c *pgTranslatingConnector
}

func (p *pgTranslatingConn) Prepare(q string) (driver.Stmt, error) {
	return p.Conn.Prepare(p.c.statement(q).sql)
}

func (p *pgTranslatingConn) PrepareContext(ctx context.Context, q string) (driver.Stmt, error) {
	st := p.c.statement(q).sql
	if pc, ok := p.Conn.(driver.ConnPrepareContext); ok {
		return pc.PrepareContext(ctx, st)
	}
	return p.Conn.Prepare(st)
}

type pgInsertResult struct {
	id, n int64
}

func (r pgInsertResult) LastInsertId() (int64, error) { return r.id, nil }
func (r pgInsertResult) RowsAffected() (int64, error) { return r.n, nil }

func (p *pgTranslatingConn) ExecContext(ctx context.Context, q string, args []driver.NamedValue) (driver.Result, error) {
	st := p.c.statement(q)
	if st.jsonArgs {
		st.sql = typeJSONParams(st.sql, args)
	}
	if st.nullCols != nil {
		args = fillNulls(args, st.nullCols)
	}
	if !st.insertID {
		return p.Conn.(driver.ExecerContext).ExecContext(ctx, st.sql, args)
	}
	// Send the INSERT with RETURNING id so that LastInsertId works, as it does on MySQL: the id of the
	// first row inserted, or 0 when nothing was.
	rows, err := p.Conn.(driver.QueryerContext).QueryContext(ctx, strings.TrimRight(strings.TrimSpace(st.sql), "; ")+" RETURNING id", args)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	res := pgInsertResult{}
	dest := make([]driver.Value, 1)
	for {
		if err := rows.Next(dest); err != nil {
			if err == io.EOF {
				break
			}
			return nil, err
		}
		if res.n == 0 {
			switch v := dest[0].(type) {
			case int64:
				res.id = v
			case int32:
				res.id = int64(v)
			}
		}
		res.n++
	}
	return res, nil
}

func (p *pgTranslatingConn) QueryContext(ctx context.Context, q string, args []driver.NamedValue) (driver.Rows, error) {
	st := p.c.statement(q)
	if st.jsonArgs {
		st.sql = typeJSONParams(st.sql, args)
	}
	if st.nullCols != nil {
		args = fillNulls(args, st.nullCols)
	}
	rows, err := p.Conn.(driver.QueryerContext).QueryContext(ctx, st.sql, args)
	if err != nil {
		return nil, err
	}
	return newPgRows(rows), nil
}

func (p *pgTranslatingConn) BeginTx(ctx context.Context, opts driver.TxOptions) (driver.Tx, error) {
	return p.Conn.(driver.ConnBeginTx).BeginTx(ctx, opts)
}

func (p *pgTranslatingConn) Ping(ctx context.Context) error {
	return p.Conn.(driver.Pinger).Ping(ctx)
}

func (p *pgTranslatingConn) ResetSession(ctx context.Context) error {
	if r, ok := p.Conn.(driver.SessionResetter); ok {
		return r.ResetSession(ctx)
	}
	return nil
}

func (p *pgTranslatingConn) IsValid() bool {
	if v, ok := p.Conn.(driver.Validator); ok {
		return v.IsValid()
	}
	return true
}

func (p *pgTranslatingConn) CheckNamedValue(nv *driver.NamedValue) error {
	return p.Conn.(driver.NamedValueChecker).CheckNamedValue(nv)
}

// typeJSONParams gives a bound parameter that is a whole argument of a JSON constructor the type of the Go
// value sent for it. PostgreSQL cannot infer a type there, MySQL does not need one.
func typeJSONParams(sql string, args []driver.NamedValue) string {
	ts := lexSQL(sql)
	changed := false
	for i := 0; i < len(ts); i++ {
		if !(isKw(ts[i], "jsonb_build_object") || isKw(ts[i], "jsonb_build_array") || isKw(ts[i], "json_set") || isKw(ts[i], "concat")) {
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
		for j := o + 1; j < cl; j++ {
			t := ts[j]
			if t.k != tkWord || len(t.s) < 2 || t.s[0] != '$' {
				continue
			}
			p, n := prevSig(ts, j-1), nextSig(ts, j+1)
			if p < 0 || n < 0 || !(isP(ts[p], "(") || isP(ts[p], ",")) || !(isP(ts[n], ",") || isP(ts[n], ")")) {
				continue
			}
			idx := 0
			for _, c := range t.s[1:] {
				if c < '0' || c > '9' {
					idx = 0
					break
				}
				idx = idx*10 + int(c-'0')
			}
			if idx < 1 || idx > len(args) {
				continue
			}
			typ := jsonParamType(args[idx-1].Value)
			ts[j].s = t.s + "::" + typ
			changed = true
		}
	}
	if !changed {
		return sql
	}
	return joinToks(ts)
}

// jsonParamType is the PostgreSQL type for a Go value sent as an argument of a JSON constructor.
func jsonParamType(v any) string {
	if t, ok := v.(time.Time); ok && !t.IsZero() {
		return "timestamptz"
	}
	rv := reflect.ValueOf(v)
	for rv.Kind() == reflect.Ptr && !rv.IsNil() {
		rv = rv.Elem()
	}
	switch rv.Kind() {
	case reflect.Int, reflect.Int8, reflect.Int16, reflect.Int32, reflect.Int64,
		reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uint64:
		return "bigint"
	case reflect.Float32, reflect.Float64:
		return "double precision"
	case reflect.Bool:
		return "boolean"
	}
	return "text"
}

// implicitDefault is the value MySQL, run non-strict, stores in a NOT NULL column of a type when NULL is sent.
func implicitDefault(typ string) (any, bool) {
	switch typ {
	case "int2", "int4", "int8", "numeric", "float4", "float8":
		return int64(0), true
	case "text", "varchar", "bpchar", "citext":
		return "", true
	case "bool":
		return false, true
	case "bytea":
		return []byte{}, true
	}
	return nil, false
}

// insertNullCols finds, in a one-row INSERT whose values are all bound parameters, the parameters that go
// to NOT NULL columns with an implicit default. MySQL, run non-strict, stores the default for a NULL sent to
// such a column; PostgreSQL refuses it, so the connection sends the default.
func (c *pgTranslatingConnector) insertNullCols(sql string) []nullCol {
	if !strings.Contains(sql, "$") {
		return nil
	}
	ts := lexSQL(sql)
	f := nextSig(ts, 0)
	if f < 0 || !isKw(ts[f], "INSERT") {
		return nil
	}
	table, cols := insertTarget(ts)
	if table == "" || len(cols) == 0 {
		return nil
	}
	c.load()
	defs := c.defs[strings.ToLower(table)]
	if len(defs) == 0 {
		return nil
	}
	v := topKw(ts, 0, "VALUES")
	if v < 0 {
		return nil
	}
	o := nextSig(ts, v+1)
	if o < 0 || !isP(ts[o], "(") {
		return nil
	}
	cl := matchClose(ts, o)
	if cl < 0 {
		return nil
	}
	if n := nextSig(ts, cl+1); n >= 0 && isP(ts[n], ",") {
		return nil // several rows
	}
	vals := splitArgs(ts[o+1 : cl])
	if len(vals) != len(cols) {
		return nil
	}
	var out []nullCol
	for i, val := range vals {
		val = trimToks(val)
		if len(val) != 1 || val[0].k != tkWord || len(val[0].s) < 2 || val[0].s[0] != '$' {
			continue
		}
		idx := 0
		for _, ch := range val[0].s[1:] {
			if ch < '0' || ch > '9' {
				idx = 0
				break
			}
			idx = idx*10 + int(ch-'0')
		}
		if d, ok := defs[strings.ToLower(cols[i])]; ok && idx > 0 {
			out = append(out, nullCol{param: idx, def: d})
		}
	}
	return out
}

// fillNulls replaces a NULL sent to a NOT NULL column by the column's implicit default.
func fillNulls(args []driver.NamedValue, cols []nullCol) []driver.NamedValue {
	var out []driver.NamedValue
	for _, nc := range cols {
		if nc.param > len(args) {
			continue
		}
		v := args[nc.param-1].Value
		isNil := v == nil
		if !isNil {
			if rv := reflect.ValueOf(v); rv.Kind() == reflect.Ptr && rv.IsNil() {
				isNil = true
			}
		}
		if !isNil {
			continue
		}
		if out == nil {
			out = append([]driver.NamedValue(nil), args...)
		}
		out[nc.param-1].Value = nc.def
	}
	if out == nil {
		return args
	}
	return out
}
