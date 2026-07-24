package userdump

import (
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// normalizeValue converts driver values into something SQLite can store, and
// replaces large binary blobs with a size placeholder so dumps stay small.
func TestNormalizeValue(t *testing.T) {
	t.Run("nil passes through as nil", func(t *testing.T) {
		assert.Nil(t, normalizeValue(nil, false))
		assert.Nil(t, normalizeValue(nil, true))
	})

	t.Run("binary []byte over 256 bytes becomes a size placeholder", func(t *testing.T) {
		big := make([]byte, 257)
		got := normalizeValue(big, true)
		assert.Equal(t, "[BINARY len=257]", got)
	})

	t.Run("binary []byte at exactly 256 bytes is kept as raw bytes", func(t *testing.T) {
		exact := make([]byte, 256)
		for i := range exact {
			exact[i] = byte(i % 256)
		}
		got := normalizeValue(exact, true)
		gotBytes, ok := got.([]byte)
		if assert.True(t, ok, "256-byte binary value must stay []byte, not be stringified") {
			assert.Equal(t, exact, gotBytes)
		}
	})

	t.Run("binary []byte just over the 256 cutoff is placeholder-ed, one byte past exact", func(t *testing.T) {
		over := make([]byte, 257)
		got := normalizeValue(over, true)
		assert.Equal(t, "[BINARY len=257]", got)
	})

	t.Run("non-binary []byte is converted to a plain string", func(t *testing.T) {
		got := normalizeValue([]byte("hello"), false)
		assert.Equal(t, "hello", got)
	})

	t.Run("non-binary []byte over 256 bytes is still stringified, not placeholder-ed", func(t *testing.T) {
		big := make([]byte, 500)
		for i := range big {
			big[i] = 'x'
		}
		got := normalizeValue(big, false)
		gotStr, ok := got.(string)
		if assert.True(t, ok) {
			assert.Len(t, gotStr, 500)
		}
	})

	t.Run("time.Time is formatted as a MySQL-style datetime string", func(t *testing.T) {
		tm := time.Date(2026, 7, 24, 13, 45, 30, 0, time.UTC)
		got := normalizeValue(tm, false)
		assert.Equal(t, "2026-07-24 13:45:30", got)
	})

	t.Run("default passthrough for other types (int64, string, float64)", func(t *testing.T) {
		assert.Equal(t, int64(42), normalizeValue(int64(42), false))
		assert.Equal(t, "already a string", normalizeValue("already a string", false))
		assert.Equal(t, 3.14, normalizeValue(3.14, false))
	})
}

func TestQuoteIdent(t *testing.T) {
	assert.Equal(t, `"users"`, quoteIdent("users"))
	assert.Equal(t, `"col""with""quotes"`, quoteIdent(`col"with"quotes`), "embedded double-quotes must be doubled, not escaped with backslash")
	assert.Equal(t, `""`, quoteIdent(""))
}

func TestIsBinaryType(t *testing.T) {
	cases := []struct {
		in   string
		want bool
	}{
		{"BLOB", true},
		{"TINYBLOB", true},
		{"MEDIUMBLOB", true},
		{"LONGBLOB", true},
		{"BINARY", true},
		{"VARBINARY", true},
		{"GEOMETRY", true},
		{"POINT", true},
		{"POLYGON", true},
		{"LINESTRING", true},
		{"MULTIPOLYGON", true}, // contains "POLYGON"
		{"VARCHAR", false},
		{"TEXT", false},
		{"INT", false},
		{"", false},
	}
	for _, c := range cases {
		t.Run(c.in, func(t *testing.T) {
			assert.Equal(t, c.want, isBinaryType(c.in))
		})
	}
}

func TestIsIntType(t *testing.T) {
	cases := []struct {
		in   string
		want bool
	}{
		{"INT", true},
		{"INTEGER", true},
		{"BIGINT", true},
		{"TINYINT", true},
		{"SMALLINT", true},
		{"MEDIUMINT", true},
		{"YEAR", true},    // exact-match special case, not a prefix match
		{"YEARLY", false}, // must NOT prefix-match YEAR - only the exact string qualifies
		{"VARCHAR", false},
		{"DECIMAL", false},
		{"", false},
	}
	for _, c := range cases {
		t.Run(c.in, func(t *testing.T) {
			assert.Equal(t, c.want, isIntType(c.in))
		})
	}
}

func TestIsRealType(t *testing.T) {
	cases := []struct {
		in   string
		want bool
	}{
		{"DECIMAL", true},
		{"FLOAT", true},
		{"DOUBLE", true},
		{"NUMERIC", true},
		{"DOUBLE PRECISION", true},
		{"INT", false},
		{"VARCHAR", false},
		{"", false},
	}
	for _, c := range cases {
		t.Run(c.in, func(t *testing.T) {
			assert.Equal(t, c.want, isRealType(c.in))
		})
	}
}

// Sanity check that quoteIdent output round-trips through the strings package
// the way callers expect (used to build column lists in generated SQL).
func TestQuoteIdent_JoinUsage(t *testing.T) {
	cols := []string{"id", "name"}
	quoted := make([]string, len(cols))
	for i, c := range cols {
		quoted[i] = quoteIdent(c)
	}
	assert.Equal(t, `"id","name"`, strings.Join(quoted, ","))
}
