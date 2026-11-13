package database

import (
	"context"
	"database/sql/driver"
	"errors"
	"fmt"
	"io"
	"strings"
	"sync"
	"testing"
)

// These tests run without a database: the connection under the translating one is a fake that records what
// it is sent.

// readyConnector is a translating connector whose catalogue is already loaded (and empty), so that it never
// tries to read pg_catalog.
func readyConnector() *pgTranslatingConnector {
	return &pgTranslatingConnector{
		ready: true,
		idx:   map[string][][]string{},
		cols:  map[string][]string{},
		defs:  map[string]map[string]any{},
		lens:  map[string]map[string]int{},
	}
}

type fakeRows struct{ closed *[]string }

func (r fakeRows) Columns() []string { return []string{"a"} }
func (r fakeRows) Close() error {
	*r.closed = append(*r.closed, "<rows closed>")
	return nil
}
func (r fakeRows) Next([]driver.Value) error { return io.EOF }

// fakeConn records every statement, and fails the ones whose text contains failOn.
type fakeConn struct {
	sent   []string
	failOn string
}

func (f *fakeConn) Prepare(string) (driver.Stmt, error) { return nil, errors.New("not used") }
func (f *fakeConn) Close() error                        { return nil }
func (f *fakeConn) Begin() (driver.Tx, error)           { return nil, errors.New("not used") }

func (f *fakeConn) ExecContext(_ context.Context, q string, _ []driver.NamedValue) (driver.Result, error) {
	f.sent = append(f.sent, q)
	if f.failOn != "" && strings.Contains(q, f.failOn) {
		return nil, errors.New("refused")
	}
	return driver.RowsAffected(0), nil
}

func (f *fakeConn) QueryContext(_ context.Context, q string, _ []driver.NamedValue) (driver.Rows, error) {
	f.sent = append(f.sent, q)
	if f.failOn != "" && strings.Contains(q, f.failOn) {
		return nil, errors.New("refused")
	}
	return fakeRows{closed: &f.sent}, nil
}

func TestMaxExecutionTimeMs(t *testing.T) {
	cases := []struct {
		in   string
		want int
	}{
		{"SELECT /*+ MAX_EXECUTION_TIME(20000) */ msgid FROM t", 20000},
		{"SELECT /*+ MAX_EXECUTION_TIME( 1500 ) */ DISTINCT mem.userid FROM memberships mem", 1500},
		{"SELECT /*+ NO_MERGE(x) MAX_EXECUTION_TIME(300) */ 1", 300},
		{"SELECT msgid FROM t", 0},
		// Only an optimizer hint counts: not an ordinary comment, a string or a column.
		{"SELECT /* MAX_EXECUTION_TIME(20000) */ 1", 0},
		{"SELECT 'MAX_EXECUTION_TIME(20000)'", 0},
		{"SELECT MAX_EXECUTION_TIME FROM t", 0},
		{"SELECT /*+ MAX_EXECUTION_TIME() */ 1", 0},
		{"SELECT /*+ MAX_EXECUTION_TIME(99999999999999999999) */ 1", 0},
	}
	for _, c := range cases {
		if got := maxExecutionTimeMs(c.in); got != c.want {
			t.Errorf("maxExecutionTimeMs(%q) = %d, want %d", c.in, got, c.want)
		}
	}
}

// A SELECT with the hint is sent between a SET and a RESET of statement_timeout, and the RESET waits for the
// rows to be closed. A statement without the hint is sent alone.
func TestHintedQueryRunsUnderStatementTimeout(t *testing.T) {
	inner := &fakeConn{}
	p := &pgTranslatingConn{Conn: inner, c: readyConnector()}

	rows, err := p.QueryContext(context.Background(), "SELECT /*+ MAX_EXECUTION_TIME(20000) */ msgid FROM t WHERE a = ?", nil)
	if err != nil {
		t.Fatal(err)
	}
	if len(inner.sent) != 2 || inner.sent[0] != "SET statement_timeout = 20000" || !strings.Contains(inner.sent[1], "msgid FROM t WHERE a = $1") {
		t.Fatalf("before the rows are closed, sent %q", inner.sent)
	}
	if err := rows.Close(); err != nil {
		t.Fatal(err)
	}
	// A second Close must not send a second RESET.
	rows.Close()
	want := []string{"<rows closed>", "RESET statement_timeout", "<rows closed>"}
	if got := inner.sent[2:]; fmt.Sprint(got) != fmt.Sprint(want) {
		t.Errorf("after closing, sent %q, want %q", got, want)
	}
	if !p.IsValid() {
		t.Error("the connection is fit for reuse once the limit is off")
	}

	inner.sent = nil
	rows, err = p.QueryContext(context.Background(), "SELECT msgid FROM t", nil)
	if err != nil {
		t.Fatal(err)
	}
	rows.Close()
	if fmt.Sprint(inner.sent) != fmt.Sprint([]string{"SELECT msgid FROM t", "<rows closed>"}) {
		t.Errorf("a statement with no hint sent %q", inner.sent)
	}
}

// When the limited statement fails, the limit still comes off.
func TestHintedQueryThatFailsStillResets(t *testing.T) {
	inner := &fakeConn{failOn: "msgid"}
	p := &pgTranslatingConn{Conn: inner, c: readyConnector()}
	if _, err := p.QueryContext(context.Background(), "SELECT /*+ MAX_EXECUTION_TIME(20000) */ msgid FROM t", nil); err == nil {
		t.Fatal("the statement's error must come back")
	}
	if n := len(inner.sent); n != 3 || inner.sent[n-1] != "RESET statement_timeout" {
		t.Errorf("sent %q, want the RESET last", inner.sent)
	}
	if !p.IsValid() {
		t.Error("the connection is still fit: the limit came off")
	}
}

// When the limit cannot be taken off (a broken connection, or an aborted transaction whose rollback will
// undo it), the pool must not reuse the connection.
func TestConnectionIsUnfitWhenTheLimitCannotComeOff(t *testing.T) {
	inner := &fakeConn{failOn: "RESET"}
	p := &pgTranslatingConn{Conn: inner, c: readyConnector()}
	if _, err := p.ExecContext(context.Background(), "SELECT /*+ MAX_EXECUTION_TIME(500) */ 1", nil); err != nil {
		t.Fatal(err)
	}
	if fmt.Sprint(inner.sent) != fmt.Sprint([]string{"SET statement_timeout = 500", "SELECT /*+ MAX_EXECUTION_TIME(500) */ 1", "RESET statement_timeout"}) {
		t.Errorf("sent %q", inner.sent)
	}
	if p.IsValid() {
		t.Error("a connection that may still carry the limit must not go back to the pool")
	}
}

// When the limit cannot be set, the statement is not sent without it.
func TestHintedQueryIsNotSentWhenTheLimitCannotBeSet(t *testing.T) {
	inner := &fakeConn{failOn: "SET statement_timeout"}
	p := &pgTranslatingConn{Conn: inner, c: readyConnector()}
	if _, err := p.QueryContext(context.Background(), "SELECT /*+ MAX_EXECUTION_TIME(20000) */ msgid FROM t", nil); err == nil {
		t.Fatal("want the error from the SET")
	}
	if len(inner.sent) != 1 {
		t.Errorf("sent %q, want only the SET", inner.sent)
	}
}

// The translation cache gives back what was put in, keeps a text that is still used across a rotation, and
// stays bounded however many distinct texts go through it.
func TestStatementCacheIsBounded(t *testing.T) {
	var sc pgStatementCache
	if _, ok := sc.get("SELECT 1"); ok {
		t.Fatal("an empty cache has nothing")
	}
	sc.put("SELECT hot", pgStatement{sql: "hot"})

	total := 3*pgStatementCacheMaxEntries + 7
	for i := 0; i < total; i++ {
		sc.put(fmt.Sprintf("SELECT * FROM t WHERE id = %d", i), pgStatement{sql: "x"})
		// The hot statement is read all the way through, as a real one is.
		if st, ok := sc.get("SELECT hot"); !ok || st.sql != "hot" {
			t.Fatalf("the statement in use was lost after %d others", i)
		}
	}
	if n := sc.size(); n > 2*pgStatementCacheMaxEntries+2 {
		t.Errorf("cache holds %d texts after %d distinct ones, want at most two generations", n, total)
	}
	if _, ok := sc.get("SELECT * FROM t WHERE id = 0"); ok {
		t.Error("a text used once, three generations ago, should have gone")
	}
	if st, ok := sc.get(fmt.Sprintf("SELECT * FROM t WHERE id = %d", total-1)); !ok || st.sql != "x" {
		t.Error("the newest text should be there")
	}
}

// Large texts fill a generation by size before they fill it by count.
func TestStatementCacheIsBoundedBySize(t *testing.T) {
	var sc pgStatementCache
	big := strings.Repeat("x", 1<<20)
	for i := 0; i < 200; i++ {
		sc.put(fmt.Sprintf("%s%d", big, i), pgStatement{sql: "y"})
	}
	if n, most := sc.size(), 2*(pgStatementCacheMaxBytes>>20)+2; n > most {
		t.Errorf("cache holds %d one-megabyte texts, want at most %d", n, most)
	}
}

func TestStatementCacheConcurrentUse(t *testing.T) {
	var sc pgStatementCache
	var wg sync.WaitGroup
	for g := 0; g < 8; g++ {
		wg.Add(1)
		go func(g int) {
			defer wg.Done()
			for i := 0; i < 2*pgStatementCacheMaxEntries; i++ {
				q := fmt.Sprintf("SELECT %d, %d", g, i)
				sc.put(q, pgStatement{sql: q})
				if st, ok := sc.get(q); ok && st.sql != q {
					t.Errorf("got %q for %q", st.sql, q)
				}
				sc.get("SELECT shared")
				sc.put("SELECT shared", pgStatement{sql: "shared"})
			}
		}(g)
	}
	wg.Wait()
	if n := sc.size(); n > 2*pgStatementCacheMaxEntries+16 {
		t.Errorf("cache holds %d texts, want at most two generations", n)
	}
}

// The connector translates a text once and then serves it from the cache, hint and all.
func TestConnectorStatementIsCached(t *testing.T) {
	c := readyConnector()
	q := "SELECT /*+ MAX_EXECUTION_TIME(20000) */ IFNULL(a, 0) FROM t WHERE b = ?"
	first := c.statement(q)
	if first.timeoutMs != 20000 || !strings.Contains(first.sql, "COALESCE(a, 0) FROM t WHERE b = $1") {
		t.Errorf("statement = %+v", first)
	}
	if c.cache.size() != 1 {
		t.Errorf("cache size = %d, want 1", c.cache.size())
	}
	if again := c.statement(q); again.sql != first.sql || again.timeoutMs != first.timeoutMs || c.cache.size() != 1 {
		t.Errorf("second call = %+v, cache size %d", again, c.cache.size())
	}
}
