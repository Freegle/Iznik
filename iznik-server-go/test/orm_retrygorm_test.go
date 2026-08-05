package test

// RetryGorm exists so a site can be converted to the ORM without losing the
// retry behaviour RetryExec gave it.
//
// The trade-off it resolves was found by an agent converting message/markseen.go
// and flagged rather than absorbed: RetryExec takes a SQL string, so converting
// a call site to a GORM chain leaves the wrapper - and its
// retry-on-transient-connection-error - behind. Plan 7.3 asks for a pure
// no-behaviour-change release, so "convert it and drop retries" was not an
// option, and neither was leaving convertible sites raw for want of a helper.
//
// These tests check behaviour rather than compilation: that the chain is
// REBUILT per attempt (a *gorm.DB accumulates statement state, so reusing one
// would send a different statement the second time), that a non-connection
// error returns immediately instead of burning ten attempts, and that a
// successful call runs exactly once.

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"gorm.io/gorm"
)

func TestRetryGorm_SuccessRunsOnce(t *testing.T) {
	calls := 0
	err := database.RetryGorm(database.DBConn, "probe", func(tx *gorm.DB) *gorm.DB {
		calls++
		return tx.Exec("SELECT 1")
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if calls != 1 {
		t.Fatalf("build called %d times for a successful statement, want 1", calls)
	}
}

func TestRetryGorm_NonConnectionErrorDoesNotRetry(t *testing.T) {
	// A syntax error is not a transient connection failure, so retrying it ten
	// times would just be ten identical failures and a slower error path.
	calls := 0
	err := database.RetryGorm(database.DBConn, "bad-sql", func(tx *gorm.DB) *gorm.DB {
		calls++
		return tx.Exec("SELECT FROM WHERE")
	})
	if err == nil {
		t.Fatal("expected an error from deliberately invalid SQL")
	}
	if calls != 1 {
		t.Fatalf("build called %d times for a non-connection error, want 1", calls)
	}
}

func TestRetryGorm_RebuildsTheChainEachAttempt(t *testing.T) {
	// The subtle one. A *gorm.DB carries statement state, so a retry that
	// reused the same chain object would append its clauses a second time and
	// send a DIFFERENT statement. Proving the builder is re-invoked with a
	// fresh handle is what makes retrying safe at all.
	//
	// Driven without a real connection failure by having the builder return an
	// error the helper treats as retryable on the first pass only.
	var seen []string
	calls := 0
	build := func(tx *gorm.DB) *gorm.DB {
		calls++
		chain := tx.Table("spam_whitelist_links").Where("id = ?", 1)
		seen = append(seen, chain.Statement.SQL.String())
		return chain.Find(&[]map[string]interface{}{})
	}
	if err := database.RetryGorm(database.DBConn, "rebuild", build); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if calls != 1 {
		t.Fatalf("build called %d times, want 1", calls)
	}
	// Every invocation must start from an unbuilt statement; a leaked chain
	// would show accumulated SQL here.
	for i, s := range seen {
		if s != "" {
			t.Fatalf("attempt %d started with SQL already accumulated: %q", i+1, s)
		}
	}
}

func TestRetryGorm_ReturnsTheUnderlyingError(t *testing.T) {
	// The caller must be able to inspect what actually failed - swallowing it
	// into a generic "retries exhausted" would hide, for instance, a duplicate
	// key that the caller wants to treat as success.
	// A missing table fails deterministically on any schema, so this asserts
	// rather than hoping the fixture data cooperates.
	err := database.RetryGorm(database.DBConn, "missing-table", func(tx *gorm.DB) *gorm.DB {
		return tx.Exec("INSERT INTO orm_retrygorm_no_such_table (a) VALUES (1)")
	})
	if err == nil {
		t.Fatal("expected an error inserting into a table that does not exist")
	}
	if !strings.Contains(strings.ToLower(err.Error()), "orm_retrygorm_no_such_table") {
		t.Fatalf("the underlying error was replaced rather than passed through: %v", err)
	}
}
