package parity

import (
	"encoding/json"
	"fmt"
	"math"
	"reflect"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Normalise turns what a site returned into a form the two engines can be compared in. The engines
// hand Go different types for the same data, and none of those differences means the rows differ.
//
//   - integers of every width are one number, and a float with no fraction is that integer
//   - a boolean is 1 or 0: MySQL has no boolean, so a comparison or EXISTS() is 1 or 0 there and
//     true or false on PostgreSQL
//   - bytes are text, because MySQL returns text as bytes under some protocols
//   - a time with a zone is the same instant in UTC, and a time written as text is read as a time
//   - a struct is read through its json tags, a pointer through what it points at
//
// NULL stays NULL and is never equal to 0 or to an empty string. Text is compared exactly, so a case
// difference is a difference: the baseline made the columns the code relies on case-insensitive
// with citext, and this is what shows one that was missed.
func Normalise(v any) any {
	return normaliseValue(reflect.ValueOf(v))
}

var (
	timeWithZone    = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}(:?\d{2})?)$`)
	timeWithoutZone = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?$`)
)

func normaliseValue(rv reflect.Value) any {
	if !rv.IsValid() {
		return nil
	}
	switch rv.Kind() {
	case reflect.Interface, reflect.Ptr:
		if rv.IsNil() {
			return nil
		}
		return normaliseValue(rv.Elem())
	case reflect.Bool:
		if rv.Bool() {
			return int64(1)
		}
		return int64(0)
	case reflect.Int, reflect.Int8, reflect.Int16, reflect.Int32, reflect.Int64:
		return rv.Int()
	case reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uint64, reflect.Uintptr:
		if u := rv.Uint(); u <= math.MaxInt64 {
			return int64(u)
		}
		return strconv.FormatUint(rv.Uint(), 10)
	case reflect.Float32, reflect.Float64:
		return normaliseFloat(rv.Float())
	case reflect.String:
		return normaliseText(rv.String())
	case reflect.Slice:
		if rv.IsNil() {
			return []any{}
		}
		if rv.Type().Elem().Kind() == reflect.Uint8 {
			return normaliseText(string(rv.Bytes()))
		}
		fallthrough
	case reflect.Array:
		out := make([]any, rv.Len())
		for i := range out {
			out[i] = normaliseValue(rv.Index(i))
		}
		return out
	case reflect.Map:
		out := make(map[string]any, rv.Len())
		for _, k := range rv.MapKeys() {
			out[fmt.Sprint(k.Interface())] = normaliseValue(rv.MapIndex(k))
		}
		return out
	case reflect.Struct:
		if t, ok := rv.Interface().(time.Time); ok {
			return t.UTC().Format("2006-01-02 15:04:05.999999")
		}
		return normaliseViaJSON(rv.Interface())
	}
	return fmt.Sprint(rv.Interface())
}

func normaliseFloat(f float64) any {
	if f == math.Trunc(f) && math.Abs(f) < 1<<53 {
		return int64(f)
	}
	return f
}

func normaliseText(s string) any {
	switch {
	case timeWithZone.MatchString(s):
		for _, layout := range []string{
			"2006-01-02T15:04:05.999999999Z07:00", "2006-01-02 15:04:05.999999999Z07:00",
			"2006-01-02T15:04:05.999999999Z0700", "2006-01-02 15:04:05.999999999Z0700",
			"2006-01-02T15:04:05.999999999Z07", "2006-01-02 15:04:05.999999999Z07",
		} {
			if t, err := time.Parse(layout, s); err == nil {
				return t.UTC().Format("2006-01-02 15:04:05.999999")
			}
		}
	case timeWithoutZone.MatchString(s):
		for _, layout := range []string{"2006-01-02T15:04:05.999999999", "2006-01-02 15:04:05.999999999"} {
			if t, err := time.Parse(layout, s); err == nil {
				return t.Format("2006-01-02 15:04:05.999999")
			}
		}
	}
	return s
}

// normaliseViaJSON reads a struct the way the API would send it, then normalises the result, so a
// struct and the map it came from compare the same.
func normaliseViaJSON(v any) any {
	raw, err := json.Marshal(v)
	if err != nil {
		return fmt.Sprintf("unencodable %T: %v", v, err)
	}
	dec := json.NewDecoder(strings.NewReader(string(raw)))
	dec.UseNumber()
	var generic any
	if err := dec.Decode(&generic); err != nil {
		return fmt.Sprintf("undecodable %T: %v", v, err)
	}
	return normaliseGeneric(generic)
}

func normaliseGeneric(g any) any {
	switch x := g.(type) {
	case json.Number:
		if i, err := x.Int64(); err == nil {
			return i
		}
		f, _ := x.Float64()
		return normaliseFloat(f)
	case string:
		return normaliseText(x)
	case bool:
		return normaliseValue(reflect.ValueOf(x))
	case []any:
		for i := range x {
			x[i] = normaliseGeneric(x[i])
		}
		return x
	case map[string]any:
		for k := range x {
			x[k] = normaliseGeneric(x[k])
		}
		return x
	}
	return g
}

// isEmpty reports whether a normalised result carries no information: no rows, no value, zero, or
// an empty string. Two engines agreeing on nothing proves nothing.
func isEmpty(n any) bool {
	switch x := n.(type) {
	case nil:
		return true
	case []any:
		return len(x) == 0
	case map[string]any:
		return len(x) == 0
	case int64:
		return x == 0
	case float64:
		return x == 0
	case string:
		return x == ""
	}
	return false
}

// sortUnordered puts the rows of a normalised slice in a fixed order, for a query with no ORDER BY.
func sortUnordered(n any) any {
	rows, ok := n.([]any)
	if !ok {
		return n
	}
	keys := make([]string, len(rows))
	idx := make([]int, len(rows))
	for i, r := range rows {
		b, _ := json.Marshal(r)
		keys[i] = string(b)
		idx[i] = i
	}
	sort.Slice(idx, func(a, b int) bool { return keys[idx[a]] < keys[idx[b]] })
	out := make([]any, len(rows))
	for i, j := range idx {
		out[i] = rows[j]
	}
	return out
}

// firstDifference describes where two normalised values first differ, as a path.
func firstDifference(a, b any, path string) string {
	switch x := a.(type) {
	case []any:
		y, ok := b.([]any)
		if !ok {
			return fmt.Sprintf("%s: %s against %s", path, show(a), show(b))
		}
		for i := 0; i < len(x) && i < len(y); i++ {
			if d := firstDifference(x[i], y[i], fmt.Sprintf("%s[%d]", path, i)); d != "" {
				return d
			}
		}
		if len(x) != len(y) {
			return fmt.Sprintf("%s: %d rows against %d rows", path, len(x), len(y))
		}
		return ""
	case map[string]any:
		y, ok := b.(map[string]any)
		if !ok {
			return fmt.Sprintf("%s: %s against %s", path, show(a), show(b))
		}
		keys := map[string]bool{}
		for k := range x {
			keys[k] = true
		}
		for k := range y {
			keys[k] = true
		}
		sorted := make([]string, 0, len(keys))
		for k := range keys {
			sorted = append(sorted, k)
		}
		sort.Strings(sorted)
		for _, k := range sorted {
			xv, xok := x[k]
			yv, yok := y[k]
			switch {
			case !xok:
				return fmt.Sprintf("%s.%s: missing against %s", path, k, show(yv))
			case !yok:
				return fmt.Sprintf("%s.%s: %s against missing", path, k, show(xv))
			}
			if d := firstDifference(xv, yv, path+"."+k); d != "" {
				return d
			}
		}
		return ""
	}
	if reflect.DeepEqual(a, b) {
		return ""
	}
	return fmt.Sprintf("%s: %s against %s", path, show(a), show(b))
}

func show(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return fmt.Sprint(v)
	}
	if len(b) > 300 {
		return string(b[:300]) + "..."
	}
	return fmt.Sprintf("%s (%T)", b, v)
}
