package userdump

import (
	"database/sql"
	"fmt"
	"os"
	"strings"
	"sync"
	"time"

	_ "modernc.org/sqlite"
)

// Builder writes a per-user dump into a temporary on-disk SQLite database.
// Tables are created dynamically from the columns of whatever rows are copied
// in, so the dump always tracks the live schema with no hand-maintained column
// lists. The file is removed by the caller after it has been streamed.
type Builder struct {
	db    *sql.DB
	path  string
	mu    sync.Mutex
	stmts map[string]*sql.Stmt
}

// NewBuilder creates an empty SQLite database in a temp file.
func NewBuilder() (*Builder, error) {
	f, err := os.CreateTemp("", "userdump-*.sqlite")
	if err != nil {
		return nil, err
	}
	path := f.Name()
	_ = f.Close()

	db, err := sql.Open("sqlite", path)
	if err != nil {
		_ = os.Remove(path)
		return nil, err
	}
	// A SQLite file is a single-writer store; keep one connection.
	db.SetMaxOpenConns(1)
	for _, pragma := range []string{
		"PRAGMA journal_mode=MEMORY",
		"PRAGMA synchronous=OFF",
		"PRAGMA temp_store=MEMORY",
	} {
		if _, err := db.Exec(pragma); err != nil {
			_ = db.Close()
			_ = os.Remove(path)
			return nil, err
		}
	}
	return &Builder{db: db, path: path, stmts: map[string]*sql.Stmt{}}, nil
}

// Path returns the temp file path.
func (b *Builder) Path() string { return b.path }

// Remove deletes the temp file.
func (b *Builder) Remove() { _ = os.Remove(b.path) }

// Finalize closes prepared statements and the database so the file can be read.
func (b *Builder) Finalize() error {
	b.mu.Lock()
	for _, s := range b.stmts {
		_ = s.Close()
	}
	b.stmts = map[string]*sql.Stmt{}
	b.mu.Unlock()
	return b.db.Close()
}

// EnsureTable creates a table from an explicit column definition list.
func (b *Builder) EnsureTable(name, colDefs string) error {
	_, err := b.db.Exec(fmt.Sprintf("CREATE TABLE IF NOT EXISTS %s (%s)", quoteIdent(name), colDefs))
	return err
}

func (b *Builder) cachedInsert(table string, cols []string) (*sql.Stmt, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	if s, ok := b.stmts[table]; ok {
		return s, nil
	}
	placeholders := strings.TrimSuffix(strings.Repeat("?,", len(cols)), ",")
	quoted := make([]string, len(cols))
	for i, c := range cols {
		quoted[i] = quoteIdent(c)
	}
	s, err := b.db.Prepare(fmt.Sprintf("INSERT INTO %s (%s) VALUES (%s)",
		quoteIdent(table), strings.Join(quoted, ","), placeholders))
	if err != nil {
		return nil, err
	}
	b.stmts[table] = s
	return s, nil
}

// InsertRow inserts a single row into a table created via EnsureTable.
func (b *Builder) InsertRow(table string, cols []string, vals ...interface{}) error {
	s, err := b.cachedInsert(table, cols)
	if err != nil {
		return err
	}
	_, err = s.Exec(vals...)
	return err
}

// InitMeta creates the discovery tables every dump carries.
func (b *Builder) InitMeta() error {
	if err := b.EnsureTable("_meta", `"key" TEXT, "value" TEXT`); err != nil {
		return err
	}
	return b.EnsureTable("_sections",
		`"name" TEXT, "status" TEXT, "rows" INTEGER, "note" TEXT, "ms" INTEGER`)
}

// SetMeta records a key/value pair in _meta (best effort).
func (b *Builder) SetMeta(key, value string) {
	_ = b.InsertRow("_meta", []string{"key", "value"}, key, value)
}

// AddSection records the outcome of one collection section in _sections.
func (b *Builder) AddSection(name, status string, rows int, note string, ms int64) {
	_ = b.InsertRow("_sections", []string{"name", "status", "rows", "note", "ms"}, name, status, rows, note, ms)
}

// CopyMySQLRows copies a *sql.Rows result set into a SQLite table of the same
// name, creating the table from the result's columns. redact (if non-nil)
// reports columns whose values must be replaced with [REDACTED].
func (b *Builder) CopyMySQLRows(table string, rows *sql.Rows, redact func(col string) bool) (int, error) {
	cols, err := rows.Columns()
	if err != nil {
		return 0, err
	}
	colTypes, err := rows.ColumnTypes()
	if err != nil {
		return 0, err
	}

	defs := make([]string, len(cols))
	binaryCol := make([]bool, len(cols))
	for i, c := range cols {
		aff := "TEXT"
		dbType := strings.ToUpper(colTypes[i].DatabaseTypeName())
		switch {
		case isBinaryType(dbType):
			aff = "BLOB"
			binaryCol[i] = true
		case isIntType(dbType):
			aff = "INTEGER"
		case isRealType(dbType):
			aff = "REAL"
		}
		defs[i] = quoteIdent(c) + " " + aff
	}
	if _, err := b.db.Exec(fmt.Sprintf("CREATE TABLE IF NOT EXISTS %s (%s)",
		quoteIdent(table), strings.Join(defs, ", "))); err != nil {
		return 0, err
	}

	placeholders := strings.TrimSuffix(strings.Repeat("?,", len(cols)), ",")
	quoted := make([]string, len(cols))
	for i, c := range cols {
		quoted[i] = quoteIdent(c)
	}
	stmt, err := b.db.Prepare(fmt.Sprintf("INSERT INTO %s (%s) VALUES (%s)",
		quoteIdent(table), strings.Join(quoted, ","), placeholders))
	if err != nil {
		return 0, err
	}
	defer stmt.Close()

	scan := make([]interface{}, len(cols))
	ptrs := make([]interface{}, len(cols))
	for i := range scan {
		ptrs[i] = &scan[i]
	}

	n := 0
	for rows.Next() {
		if err := rows.Scan(ptrs...); err != nil {
			return n, err
		}
		out := make([]interface{}, len(cols))
		for i := range cols {
			if redact != nil && redact(cols[i]) {
				out[i] = "[REDACTED]"
				continue
			}
			out[i] = normalizeValue(scan[i], binaryCol[i])
		}
		if _, err := stmt.Exec(out...); err != nil {
			return n, err
		}
		n++
	}
	return n, rows.Err()
}

// normalizeValue converts driver values into something SQLite can store, and
// replaces large binary blobs with a size placeholder so dumps stay small.
func normalizeValue(v interface{}, isBinary bool) interface{} {
	switch x := v.(type) {
	case nil:
		return nil
	case []byte:
		if isBinary {
			if len(x) > 256 {
				return fmt.Sprintf("[BINARY len=%d]", len(x))
			}
			return x // store small binary/geometry as a BLOB, not garbled text
		}
		return string(x)
	case time.Time:
		return x.Format("2006-01-02 15:04:05")
	default:
		return x
	}
}

func quoteIdent(s string) string {
	return `"` + strings.ReplaceAll(s, `"`, `""`) + `"`
}

func isBinaryType(t string) bool {
	return strings.Contains(t, "BLOB") || strings.Contains(t, "BINARY") ||
		strings.Contains(t, "GEOM") || strings.Contains(t, "POINT") ||
		strings.Contains(t, "POLYGON") || strings.Contains(t, "LINESTRING")
}

func isIntType(t string) bool {
	return strings.HasPrefix(t, "INT") || strings.HasPrefix(t, "BIGINT") ||
		strings.HasPrefix(t, "TINYINT") || strings.HasPrefix(t, "SMALLINT") ||
		strings.HasPrefix(t, "MEDIUMINT") || t == "YEAR"
}

func isRealType(t string) bool {
	return strings.Contains(t, "DECIMAL") || strings.Contains(t, "FLOAT") ||
		strings.Contains(t, "DOUBLE") || strings.Contains(t, "NUMERIC")
}
