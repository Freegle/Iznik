package parity

import (
	"context"
	"fmt"
	"regexp"
	"strings"
	"testing"

	"gorm.io/gorm"
)

// Option adjusts a Layer 2 assertion.
type Option func(*config)

type config struct {
	ordered    *bool
	allowEmpty bool
	expect     any
	hasExpect  bool
}

// Ordered says the rows come back in an order that matters. By default a site whose golden SQL has
// an ORDER BY is compared in order and any other is compared as a set.
func Ordered() Option { return func(c *config) { b := true; c.ordered = &b } }

// Unordered says the order of the rows does not matter, whatever the golden says.
func Unordered() Option { return func(c *config) { b := false; c.ordered = &b } }

// AllowEmpty accepts a result with no information: no rows, zero or an empty string. By default
// that fails, because two engines that both return nothing agree on nothing, and a query that
// fails on one engine and is ignored by the code looks exactly like that.
func AllowEmpty() Option { return func(c *config) { c.allowEmpty = true } }

// Expect also requires the result to equal want on every engine. Use it where the fixture makes
// the answer obvious, so a test fails when both engines drift together.
func Expect(want any) Option {
	return func(c *config) { c.expect, c.hasExpect = want, true }
}

var orderByPattern = regexp.MustCompile(`(?i)\border\s+by\b`)

// AssertResultParity runs the same call on MySQL and on PostgreSQL against the same fixture and
// fails unless both give the same answer. run receives a transaction on the engine and returns what
// the site returned, which can be a slice, a struct, a map or a scalar. The transaction is rolled
// back, so the fixture never reaches the shared database. A site that writes returns the rows it
// left behind, read back through the same handle.
//
//	parity.AssertResultParity(t, "a1b2c3d4e5f6", fixture, func(db *gorm.DB) any {
//		return myGroupIDs(db, parity.ID(1))
//	}, parity.Expect([]uint64{parity.ID(10), parity.ID(11)}))
//
// The test is skipped when an engine is not there. See OpenEngines.
func AssertResultParity(tb testing.TB, siteID string, fixture Fixture, run func(db *gorm.DB) any, opts ...Option) {
	tb.Helper()
	engines := OpenEngines(tb)
	assertResultParity(tb, engines, siteID, fixture, run, opts...)
}

// assertResultParity is the comparison, with the engines passed in so the harness's own tests can
// supply their own.
func assertResultParity(t TestingT, engines []Engine, siteID string, fixture Fixture, run func(db *gorm.DB) any, opts ...Option) {
	t.Helper()

	site, err := LookupSite(siteID)
	if err != nil {
		t.Fatalf("parity: %v", err)
	}
	if len(engines) == 0 {
		t.Fatalf("parity: site %q: no engine to run on", siteID)
	}

	var cfg config
	for _, o := range opts {
		o(&cfg)
	}
	ordered := orderByPattern.MatchString(site.GoldenSQL)
	if cfg.ordered != nil {
		ordered = *cfg.ordered
	}

	results := make([]any, len(engines))
	for i, e := range engines {
		got, runErrors, err := runOn(e, fixture, run)
		if err != nil {
			t.Fatalf("parity: site %q on %s: %v", siteID, e.Name, err)
		}
		if len(runErrors) > 0 {
			t.Fatalf("parity: site %q on %s: %d statement(s) failed. The site may ignore the error and return an empty result.\n    %s",
				siteID, e.Name, len(runErrors), strings.Join(runErrors, "\n    "))
		}

		n := Normalise(got)
		if !cfg.allowEmpty && isEmpty(n) {
			t.Fatalf("parity: site %q on %s: the result is empty (%s). Two engines agreeing on nothing prove nothing: "+
				"give the fixture rows the query returns, or pass AllowEmpty() if empty is the point", siteID, e.Name, show(n))
		}
		if cfg.hasExpect {
			if d := firstDifference(Normalise(cfg.expect), n, "result"); d != "" {
				t.Fatalf("parity: site %q on %s: the result is not what the fixture should give\n    %s", siteID, e.Name, d)
			}
		}
		if !ordered {
			n = sortUnordered(n)
		}
		results[i] = n
	}

	// With one engine there is nothing to compare: the checks above are all it gets.
	for i := 1; i < len(engines); i++ {
		if d := firstDifference(results[0], results[i], "result"); d != "" {
			order := "compared as a set"
			if ordered {
				order = "compared in order"
			}
			t.Fatalf("parity: site %q gives different rows on %s and %s (%s)\n    first difference: %s\n    %s: %s\n    %s: %s",
				siteID, engines[0].Name, engines[i].Name, order, d,
				engines[0].Name, show(results[0]), engines[i].Name, show(results[i]))
		}
	}
}

// resetAutoIncrement puts MySQL's AUTO_INCREMENT counters back after a rollback. An explicit id above the
// counter moves the counter past it and a rollback does not move it back, so the ids the rest of the suite
// is handed by AUTO_INCREMENT climb out of the fixture's range and, many tests later, reach ids a later
// fixture uses (a user with id IDBase+3001 then exists and the fixture's insert is refused). ALTER TABLE
// ... AUTO_INCREMENT = 1 sets the counter to one above the highest id left in the table. PostgreSQL's
// identity columns ignore explicit ids and need nothing.
func resetAutoIncrement(e Engine, fixture Fixture) {
	if e.Dialect != MySQL {
		return
	}
	// One connection, with a short lock wait: MySQL's default metadata lock wait is a year, and a table
	// still held by a transaction somewhere must cost this call a few seconds and not the whole run.
	_ = e.DB.Connection(func(tx *gorm.DB) error {
		// keep-raw: session setting, which GORM has no builder for
		tx.Exec("SET SESSION lock_wait_timeout = 3")
		for _, t := range fixture {
			// keep-raw: DDL, which GORM has no builder for
			tx.Exec("ALTER TABLE `" + t.Table + "` AUTO_INCREMENT = 1")
		}
		return nil
	})
}

// runOn loads the fixture into a transaction on the engine, runs the site, and rolls back.
func runOn(e Engine, fixture Fixture, run func(db *gorm.DB) any) (result any, failures []string, err error) {
	rec := &errorRecorder{}
	tx := e.DB.Begin().WithContext(context.WithValue(context.Background(), errorKey{}, rec))
	if tx.Error != nil {
		return nil, nil, fmt.Errorf("beginning a transaction: %w", tx.Error)
	}
	defer func() {
		tx.Rollback()
		resetAutoIncrement(e, fixture)
	}()

	if err := fixture.Load(tx); err != nil {
		return nil, nil, fmt.Errorf("loading the fixture: %w", err)
	}

	// A failed statement aborts a PostgreSQL transaction, and every later statement then fails
	// with "current transaction is aborted". Only the first failure is the cause.
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("the site panicked: %v", r)
		}
	}()
	result = run(tx)
	failures = rec.all()
	if len(failures) > 1 {
		failures = failures[:1]
	}
	return result, failures, nil
}
