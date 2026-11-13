package database

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"fmt"
	"reflect"
	"strconv"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"
)

// MySQL moves freely between booleans, numbers and strings: a comparison is 0 or 1, a number can be sent
// for a text column and a bool for a tinyint. The code the Go API runs was written for that. These pieces
// give the PostgreSQL driver the same leniency, at the edge: parameters going in and booleans coming back.

type boolAsInt bool

func (b boolAsInt) Int64Value() (pgtype.Int8, error) {
	if b {
		return pgtype.Int8{Int64: 1, Valid: true}, nil
	}
	return pgtype.Int8{Int64: 0, Valid: true}, nil
}

// boolIntCodec lets a Go bool be sent for a smallint, integer or bigint parameter.
type boolIntCodec struct{ pgtype.Codec }

// boolIntPlan converts the bool to its integer before the integer plan encodes it: a plan is handed the
// original value, not the one it was planned for.
type boolIntPlan struct{ inner pgtype.EncodePlan }

func (p boolIntPlan) Encode(value any, buf []byte) ([]byte, error) {
	switch v := value.(type) {
	case bool:
		value = boolAsInt(v)
	case *bool:
		if v == nil {
			return nil, nil
		}
		value = boolAsInt(*v)
	}
	return p.inner.Encode(value, buf)
}

func (c boolIntCodec) PlanEncode(m *pgtype.Map, oid uint32, format int16, value any) pgtype.EncodePlan {
	switch v := value.(type) {
	case bool:
		return boolIntPlan{c.Codec.PlanEncode(m, oid, format, boolAsInt(v))}
	case *bool:
		if v != nil {
			return boolIntPlan{c.Codec.PlanEncode(m, oid, format, boolAsInt(*v))}
		}
	}
	return c.Codec.PlanEncode(m, oid, format, value)
}

// numberTextCodec lets a Go number be sent for a text parameter, as MySQL compares a text column with a number.
type numberTextCodec struct{ pgtype.Codec }

type numberTextPlan struct{}

func (numberTextPlan) Encode(value any, buf []byte) ([]byte, error) {
	rv := reflect.ValueOf(value)
	for rv.Kind() == reflect.Ptr {
		if rv.IsNil() {
			return nil, nil
		}
		rv = rv.Elem()
	}
	switch rv.Kind() {
	case reflect.Int, reflect.Int8, reflect.Int16, reflect.Int32, reflect.Int64:
		return strconv.AppendInt(buf, rv.Int(), 10), nil
	case reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uint64:
		return strconv.AppendUint(buf, rv.Uint(), 10), nil
	case reflect.Float32, reflect.Float64:
		return append(buf, fmt.Sprint(rv.Float())...), nil
	case reflect.Bool:
		if rv.Bool() {
			return append(buf, '1'), nil
		}
		return append(buf, '0'), nil
	}
	return nil, fmt.Errorf("cannot encode %T as text", value)
}

func (c numberTextCodec) PlanEncode(m *pgtype.Map, oid uint32, format int16, value any) pgtype.EncodePlan {
	if p := c.Codec.PlanEncode(m, oid, format, value); p != nil {
		return p
	}
	rv := reflect.ValueOf(value)
	for rv.Kind() == reflect.Ptr && !rv.IsNil() {
		rv = rv.Elem()
	}
	switch rv.Kind() {
	case reflect.Int, reflect.Int8, reflect.Int16, reflect.Int32, reflect.Int64,
		reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uint64,
		reflect.Float32, reflect.Float64, reflect.Bool:
		if format == pgtype.TextFormatCode {
			return numberTextPlan{}
		}
	}
	return nil
}

// registerLenientTypes is the pgx AfterConnect hook that installs the codecs above.
func registerLenientTypes(_ context.Context, conn *pgx.Conn) error {
	m := conn.TypeMap()
	for _, oid := range []uint32{pgtype.Int2OID, pgtype.Int4OID, pgtype.Int8OID} {
		if t, ok := m.TypeForOID(oid); ok {
			m.RegisterType(&pgtype.Type{Name: t.Name, OID: t.OID, Codec: boolIntCodec{t.Codec}})
		}
	}
	for _, oid := range []uint32{pgtype.TextOID, pgtype.VarcharOID, pgtype.BPCharOID} {
		if t, ok := m.TypeForOID(oid); ok {
			m.RegisterType(&pgtype.Type{Name: t.Name, OID: t.OID, Codec: numberTextCodec{t.Codec}})
		}
	}
	return nil
}

// pgRows makes PostgreSQL's results look like MySQL's to database/sql: the booleans PostgreSQL returns for
// comparisons are 1 and 0, and text comes as bytes, as MySQL's driver returns it, so that a json.RawMessage
// or []byte can be scanned from a text column and GORM gives a map the string.
type pgRows struct{ driver.Rows }

func newPgRows(r driver.Rows) *pgRows { return &pgRows{r} }

func (r *pgRows) Next(dest []driver.Value) error {
	if err := r.Rows.Next(dest); err != nil {
		return err
	}
	for i, v := range dest {
		switch x := v.(type) {
		case bool:
			if x {
				dest[i] = int64(1)
			} else {
				dest[i] = int64(0)
			}
		case string:
			dest[i] = []byte(x)
		}
	}
	return nil
}

func (r *pgRows) ColumnTypeScanType(i int) reflect.Type {
	if s, ok := r.Rows.(driver.RowsColumnTypeScanType); ok {
		t := s.ColumnTypeScanType(i)
		if t == reflect.TypeOf(false) {
			return reflect.TypeOf(int64(0))
		}
		if t != nil && t.Kind() == reflect.String {
			return reflect.TypeOf(sql.RawBytes{})
		}
		return t
	}
	return reflect.TypeOf("")
}

func (r *pgRows) ColumnTypeDatabaseTypeName(i int) string {
	if s, ok := r.Rows.(driver.RowsColumnTypeDatabaseTypeName); ok {
		return s.ColumnTypeDatabaseTypeName(i)
	}
	return ""
}

func (r *pgRows) ColumnTypeLength(i int) (int64, bool) {
	if s, ok := r.Rows.(driver.RowsColumnTypeLength); ok {
		return s.ColumnTypeLength(i)
	}
	return 0, false
}

func (r *pgRows) ColumnTypeNullable(i int) (bool, bool) {
	if s, ok := r.Rows.(driver.RowsColumnTypeNullable); ok {
		return s.ColumnTypeNullable(i)
	}
	return false, false
}

func (r *pgRows) ColumnTypePrecisionScale(i int) (int64, int64, bool) {
	if s, ok := r.Rows.(driver.RowsColumnTypePrecisionScale); ok {
		return s.ColumnTypePrecisionScale(i)
	}
	return 0, 0, false
}
