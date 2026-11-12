package parity

import (
	"reflect"
	"testing"
	"time"
)

type sample struct {
	ID    uint64    `json:"id"`
	Name  string    `json:"name"`
	Seen  bool      `json:"seen"`
	When  time.Time `json:"when"`
	Count *int      `json:"count"`
}

func TestNormalise_TheEnginesTypesForTheSameDataAreOneValue(t *testing.T) {
	cases := []struct {
		name string
		a, b any
	}{
		{"integer widths", int32(5), uint64(5)},
		{"float without a fraction", float64(5), int64(5)},
		{"bytes and text", []byte("hello"), "hello"},
		{"boolean and 0/1", true, int64(1)},
		{"false and 0", false, uint8(0)},
		{"a time in another zone", time.Date(2026, 1, 2, 10, 0, 0, 0, time.FixedZone("x", 3600)), time.Date(2026, 1, 2, 9, 0, 0, 0, time.UTC)},
		{"a time written as text", "2026-01-02T09:00:00Z", time.Date(2026, 1, 2, 9, 0, 0, 0, time.UTC)},
		{"a time with an offset as text", "2026-01-02 10:00:00+01:00", time.Date(2026, 1, 2, 9, 0, 0, 0, time.UTC)},
		{"a map row with bytes against strings",
			map[string]any{"a": []byte("x"), "b": int64(1)}, map[string]any{"a": "x", "b": true}},
		{"nil slice and empty slice", []uint64(nil), []uint64{}},
	}
	for _, c := range cases {
		if d := firstDifference(Normalise(c.a), Normalise(c.b), "v"); d != "" {
			t.Errorf("%s: should be equal, differ at %s", c.name, d)
		}
	}
}

func TestNormalise_RealDifferencesSurvive(t *testing.T) {
	cases := []struct {
		name string
		a, b any
	}{
		{"null and zero", nil, int64(0)},
		{"null and empty text", nil, ""},
		{"zero and empty text", int64(0), ""},
		{"case", "Approved", "approved"},
		{"a trailing space", "a", "a "},
		{"1 and 2", int64(1), int64(2)},
		{"1.5 and 1.6", 1.5, 1.6},
		{"an instant an hour apart", time.Date(2026, 1, 2, 9, 0, 0, 0, time.UTC), time.Date(2026, 1, 2, 10, 0, 0, 0, time.UTC)},
		{"a missing column", map[string]any{"a": 1}, map[string]any{"a": 1, "b": 2}},
		{"a missing row", []any{1, 2}, []any{1}},
	}
	for _, c := range cases {
		if d := firstDifference(Normalise(c.a), Normalise(c.b), "v"); d == "" {
			t.Errorf("%s: should differ and does not", c.name)
		}
	}
}

func TestNormalise_AStructIsReadThroughItsJSONTags(t *testing.T) {
	n := 3
	when := time.Date(2026, 1, 2, 9, 0, 0, 0, time.FixedZone("x", 3600))
	fromStruct := Normalise(sample{ID: 7, Name: "a", Seen: true, When: when, Count: &n})
	fromMap := Normalise(map[string]any{"id": []byte("7"), "name": "a", "seen": int64(1), "when": "2026-01-02 08:00:00", "count": int64(3)})

	// The id is text in one and a number in the other: that is a real difference, and it shows.
	if d := firstDifference(fromStruct, fromMap, "v"); d == "" {
		t.Fatalf("a number and the text of that number are different values")
	}

	fromMap = Normalise(map[string]any{"id": uint64(7), "name": "a", "seen": int64(1), "when": "2026-01-02 08:00:00", "count": int64(3)})
	if d := firstDifference(fromStruct, fromMap, "v"); d != "" {
		t.Fatalf("a struct and the map it came from should match, differ at %s", d)
	}
}

func TestIsEmpty(t *testing.T) {
	for _, v := range []any{nil, []uint64{}, map[string]any{}, int64(0), "", false} {
		if !isEmpty(Normalise(v)) {
			t.Errorf("%#v should be empty", v)
		}
	}
	for _, v := range []any{[]uint64{1}, int64(3), "x", true, map[string]any{"a": nil}} {
		if isEmpty(Normalise(v)) {
			t.Errorf("%#v should not be empty", v)
		}
	}
}

func TestSortUnordered_IsStableAcrossInputOrder(t *testing.T) {
	a := sortUnordered(Normalise([]map[string]any{{"id": 2}, {"id": 1}, {"id": 3}}))
	b := sortUnordered(Normalise([]map[string]any{{"id": 3}, {"id": 2}, {"id": 1}}))
	if !reflect.DeepEqual(a, b) {
		t.Fatalf("the same rows in two orders should sort alike: %v %v", a, b)
	}
}

func TestFirstDifference_NamesThePath(t *testing.T) {
	a := Normalise([]map[string]any{{"id": 1, "name": "x"}, {"id": 2, "name": "y"}})
	b := Normalise([]map[string]any{{"id": 1, "name": "x"}, {"id": 2, "name": "z"}})
	if d := firstDifference(a, b, "result"); d == "" || d[:len("result[1].name")] != "result[1].name" {
		t.Fatalf("the difference should name the row and column, got %q", d)
	}
}
