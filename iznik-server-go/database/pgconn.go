package database

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"io"
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
	ready bool
}

type pgStatement struct {
	sql      string
	insertID bool // an INSERT into a table with an id column, no RETURNING
	jsonArgs bool // has a JSON constructor whose bound arguments need a type
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
	st := pgStatement{sql: t, jsonArgs: strings.Contains(t, "jsonb_build_") || strings.Contains(t, "json_set(")}
	if table, ok := plainInsertTable(t); ok && isColumnOf(c, table, "id") {
		st.insertID = true
	}
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
	rows, err = db.Query(`SELECT c.relname, a.attname FROM pg_class c JOIN pg_attribute a ON a.attrelid = c.oid
		WHERE c.relkind IN ('r', 'p') AND c.relnamespace = 'public'::regnamespace AND a.attnum > 0 AND NOT a.attisdropped
		ORDER BY c.relname, a.attnum`)
	if err == nil {
		for rows.Next() {
			var table, col string
			if rows.Scan(&table, &col) == nil {
				c.cols[table] = append(c.cols[table], col)
			}
		}
		rows.Close()
	}
}

func (c *pgTranslatingConnector) columns(table string) []string {
	c.load()
	return c.cols[strings.ToLower(table)]
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
	return p.Conn.(driver.QueryerContext).QueryContext(ctx, st.sql, args)
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
		if !(isKw(ts[i], "jsonb_build_object") || isKw(ts[i], "jsonb_build_array") || isKw(ts[i], "json_set")) {
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
			typ := "text"
			switch v := args[idx-1].Value.(type) {
			case int64:
				typ = "bigint"
			case float64:
				typ = "double precision"
			case bool:
				typ = "boolean"
			case time.Time:
				typ = "timestamptz"
			case string, []byte, nil:
				typ = "text"
			default:
				_ = v
			}
			ts[j].s = t.s + "::" + typ
			changed = true
		}
	}
	if !changed {
		return sql
	}
	return joinToks(ts)
}
