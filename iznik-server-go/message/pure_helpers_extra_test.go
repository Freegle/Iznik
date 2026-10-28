package message

import (
	"reflect"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
)

func TestGroupsWithout(t *testing.T) {
	cases := []struct {
		name         string
		groups, drop []uint64
		want         []uint64
	}{
		{"nothing to drop returns input", []uint64{1, 2, 3}, nil, []uint64{1, 2, 3}},
		{"empty drop slice", []uint64{1, 2}, []uint64{}, []uint64{1, 2}},
		{"drop middle keeps order", []uint64{3, 1, 2}, []uint64{1}, []uint64{3, 2}},
		{"drop all gives nil", []uint64{1, 2}, []uint64{2, 1}, nil},
		{"drop unknown", []uint64{1, 2}, []uint64{9}, []uint64{1, 2}},
		{"empty groups", nil, []uint64{1}, nil},
		{"duplicate drops", []uint64{1, 2, 1}, []uint64{1, 1}, []uint64{2}},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := groupsWithout(c.groups, c.drop); !reflect.DeepEqual(got, c.want) {
				t.Errorf("got %#v want %#v", got, c.want)
			}
		})
	}
}

func TestActionSkips(t *testing.T) {
	m := actionSkips(nil, nil)
	if !reflect.DeepEqual(m["heldbyother"], []uint64{}) || !reflect.DeepEqual(m["lockedbyhome"], []uint64{}) {
		t.Errorf("nil inputs must become empty (not null) slices: %#v", m)
	}

	m = actionSkips([]uint64{1, 2}, []uint64{3})
	if !reflect.DeepEqual(m["heldbyother"], []uint64{1, 2}) || !reflect.DeepEqual(m["lockedbyhome"], []uint64{3}) {
		t.Errorf("values not passed through: %#v", m)
	}
	if len(m) != 2 {
		t.Errorf("expected exactly two keys, got %d", len(m))
	}
}

func TestGenerateEditToken(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 50; i++ {
		tok, err := generateEditToken()
		if err != nil {
			t.Fatal(err)
		}
		if len(tok) != 32 {
			t.Fatalf("token length %d, want 32", len(tok))
		}
		if strings.ContainsAny(tok, "+/=") {
			t.Fatalf("token %q is not URL-safe", tok)
		}
		if seen[tok] {
			t.Fatalf("duplicate token %q", tok)
		}
		seen[tok] = true
	}
}

func TestBuildInsertViewBatchQuery(t *testing.T) {
	for n := 1; n <= 4; n++ {
		chunk := make([]uint64, n)
		for i := range chunk {
			chunk[i] = uint64(100 + i)
		}
		sql, args := buildInsertViewBatchQuery(chunk, 7, "src")

		if got := strings.Count(sql, "?"); got != n*4 {
			t.Errorf("n=%d: %d placeholders, want %d", n, got, n*4)
		}
		if len(args) != n*4 {
			t.Errorf("n=%d: %d args, want %d", n, len(args), n*4)
		}
		if strings.Count(sql, "(?, ?, ?, 0, ?)") != n {
			t.Errorf("n=%d: wrong tuple count in %q", n, sql)
		}
		if !strings.HasPrefix(sql, "INSERT INTO messages_likes (msgid, userid, type, pageview, source) VALUES ") ||
			!strings.HasSuffix(sql, " ON DUPLICATE KEY UPDATE timestamp = NOW(), count = count + 1") {
			t.Errorf("n=%d: unexpected shape %q", n, sql)
		}
		for i := 0; i < n; i++ {
			want := []interface{}{chunk[i], uint64(7), utils.MESSAGE_LIKES_VIEW, "src"}
			if !reflect.DeepEqual(args[i*4:i*4+4], want) {
				t.Errorf("n=%d row %d args %#v want %#v", n, i, args[i*4:i*4+4], want)
			}
		}
	}

	// Source may be nil (written as NULL).
	_, args := buildInsertViewBatchQuery([]uint64{1}, 2, nil)
	if args[3] != nil {
		t.Errorf("nil source should stay nil, got %#v", args[3])
	}
}
