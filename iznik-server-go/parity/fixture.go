package parity

import (
	"fmt"

	"gorm.io/gorm"
)

// IDBase is the first id a fixture row may use. The suites share one database and other tests
// create their own rows, so fixture ids sit in a band no one else uses. It fits an unsigned
// 32-bit column.
const IDBase = 2000000000

// ID returns the nth fixture id.
func ID(n int) uint64 { return IDBase + uint64(n) }

// Row is one table row, by column name. A column the row leaves out takes its default, so a row
// has to name every NOT NULL column that has none: PostgreSQL refuses what MySQL fills in quietly.
type Row map[string]any

// ByDialect is a column value that differs between the engines. Load picks the one for the engine
// it is loading into.
type ByDialect struct {
	MySQL    any
	Postgres any
}

// Geometry is a spatial column value from well-known text, such as "POINT(-3.2 55.9)". The
// MySQL columns carry the SRID label 3857 and the PostgreSQL columns 4326 (the baseline relabels,
// it does not reproject), and both hold longitude and latitude in degrees.
func Geometry(wkt string) ByDialect {
	return ByDialect{
		MySQL:    gorm.Expr("ST_GeomFromText(?, 3857)", wkt),
		Postgres: gorm.Expr("ST_GeomFromText(?, 4326)", wkt),
	}
}

// TableRows is the rows to insert into one table.
type TableRows struct {
	Table string
	Rows  []Row
}

// Fixture is the rows a site's test needs, in insert order. List a parent table before the tables
// that refer to it: the foreign keys are real on both engines.
type Fixture []TableRows

// Table builds one entry of a Fixture.
func Table(name string, rows ...Row) TableRows { return TableRows{Table: name, Rows: rows} }

// Load inserts the fixture through GORM, so quoting and placeholders follow the engine. Call it
// inside a transaction that the caller rolls back.
func (f Fixture) Load(tx *gorm.DB) error {
	d := Dialect(tx.Dialector.Name())
	for _, t := range f {
		for i, r := range t.Rows {
			if len(r) == 0 {
				return fmt.Errorf("fixture table %s row %d has no columns", t.Table, i)
			}
			r = r.resolve(d)
			// On MySQL an ordinary test row can be sitting on a fixture id. A fixture insert moves the
			// table's AUTO_INCREMENT up to the fixture range and a rollback does not move it back; the
			// reset after each run (resetAutoIncrement) is skipped when the table is busy, and rows
			// that other tests then create are numbered from there. Such a row is moved out of the
			// way for the length of this transaction, and the rollback puts it back.
			if id, ok := r["id"]; ok && d == MySQL {
				// keep-raw: test harness, the table name varies per fixture
				if err := tx.Exec("DELETE FROM `"+t.Table+"` WHERE id = ?", id).Error; err != nil {
					return fmt.Errorf("fixture table %s row %d: clearing id %v: %w", t.Table, i, id, err)
				}
			}
			// One statement per row. A batch of maps takes its columns from the first row, and a
			// row that lacked one would get NULL where its default was meant.
			if err := tx.Table(t.Table).Create(map[string]any(r)).Error; err != nil {
				return fmt.Errorf("fixture table %s row %d: %w", t.Table, i, err)
			}
		}
	}
	return nil
}

// resolve returns the row with every ByDialect value replaced by the one for d.
func (r Row) resolve(d Dialect) Row {
	out := make(Row, len(r))
	for k, v := range r {
		if bd, ok := v.(ByDialect); ok {
			if d == Postgres {
				v = bd.Postgres
			} else {
				v = bd.MySQL
			}
		}
		out[k] = v
	}
	return out
}
