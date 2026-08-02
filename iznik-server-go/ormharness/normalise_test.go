package ormharness

// Unit tests for the four comparison normalisations in golden.go.
//
// These exist because of a specific mistake. normaliseColumnOrder has two
// branches, INSERT and UPDATE. It was validated with a single site-level test
// that happened to be an UPDATE, which passed, and the category was declared
// solved. The INSERT branch had never worked at all: its regexp could not match
// "insert into logs(...)" (no space before the paren, so a greedy \S+ swallowed
// it) and could not span "values (now(), ...)" (nested parens defeat a [^()]*
// class). That went unnoticed until a later batch happened to produce six
// INSERTs and six tests failed at once.
//
// One passing test on one shape is not evidence about the other shape. Each
// branch is pinned here, along with the cases each normalisation must NOT
// touch, since a normalisation that is too eager silently accepts real bugs.

import "testing"

func TestNormaliseColumnOrder_Insert(t *testing.T) {
	// The exact shape that defeated the regexp: no space before the paren, and
	// a function call with its own parens among the values.
	golden := "insert into logs(timestamp, type, subtype, user, byuser, text) values (now(), ?, ?, ?, ?, ?)"
	render := "insert into logs(byuser, subtype, text, timestamp, type, user) values (?, ?, ?, now(), ?, ?)"

	if normaliseColumnOrder(golden) != normaliseColumnOrder(render) {
		t.Fatalf("column reordering not normalised:\n golden -> %s\n render -> %s",
			normaliseColumnOrder(golden), normaliseColumnOrder(render))
	}
}

func TestNormaliseColumnOrder_InsertWithNestedFunctionArgs(t *testing.T) {
	// json_object(...) carries commas and parens of its own, which must not be
	// split or counted when finding the end of the VALUES list.
	golden := "insert into background_tasks(task_type, data) values (?, json_object('chatid', ?, 'userid', ?))"
	render := "insert into background_tasks(data, task_type) values (json_object('chatid', ?, 'userid', ?), ?)"

	if normaliseColumnOrder(golden) != normaliseColumnOrder(render) {
		t.Fatalf("nested function args not handled:\n golden -> %s\n render -> %s",
			normaliseColumnOrder(golden), normaliseColumnOrder(render))
	}
}

func TestNormaliseColumnOrder_Update(t *testing.T) {
	golden := "update memberships set reviewedat = now(), reviewrequestedat = null, heldby = null where userid = ? and groupid = ?"
	render := "update memberships set heldby = null, reviewedat = now(), reviewrequestedat = null where userid = ? and groupid = ?"

	if normaliseColumnOrder(golden) != normaliseColumnOrder(render) {
		t.Fatalf("SET reordering not normalised:\n golden -> %s\n render -> %s",
			normaliseColumnOrder(golden), normaliseColumnOrder(render))
	}
}

func TestNormaliseColumnOrder_KeepsColumnBoundToItsValue(t *testing.T) {
	// The safety property the whole normalisation rests on: reordering is
	// accepted, but mispairing a column with someone else's value is not.
	correct := "insert into t(a, b) values (?, now())"
	swapped := "insert into t(a, b) values (now(), ?)"

	if normaliseColumnOrder(correct) == normaliseColumnOrder(swapped) {
		t.Fatal("a column paired with the wrong value was accepted; the normalisation must only reorder, never repair")
	}
}

func TestNormaliseColumnOrder_LeavesMultiRowInsertAlone(t *testing.T) {
	// Reordering one row of a multi-row insert would be meaningless, so it is
	// declined rather than half-applied.
	sql := "insert into t(b, a) values (?, ?), (?, ?)"
	if normaliseColumnOrder(sql) != sql {
		t.Fatalf("multi-row VALUES should be left untouched, got %s", normaliseColumnOrder(sql))
	}
}

func TestNormaliseColumnOrder_LeavesInsertSelectAlone(t *testing.T) {
	sql := "insert into t(b, a) select x, y from u"
	if normaliseColumnOrder(sql) != sql {
		t.Fatalf("INSERT ... SELECT should be left untouched, got %s", normaliseColumnOrder(sql))
	}
}

func TestNormaliseColumnOrder_LeavesLoadBearingSetOrderAlone(t *testing.T) {
	// MySQL evaluates SET assignments left to right, so this statement computes
	// rate from the ALREADY-incremented action. Sorting the list would still
	// leave action first here (a before r), but sorting is the wrong answer in
	// principle: if the columns were named the other way round, the normalisation
	// would swap them and then declare the two statements equal, hiding a real
	// change in what gets written. Where order carries meaning we compare the
	// exact text instead. Freegle has one live instance of this, engage_mails in
	// user.go, found by tools/orm-migration/check-set-order.sh.
	sql := "update engage_mails set action = action + 1, rate = coalesce(100 * action / shown, 0) where id = ?"
	if normaliseColumnOrder(sql) != sql {
		t.Fatalf("an order-dependent SET list was reordered:\n in  %s\n out %s", sql, normaliseColumnOrder(sql))
	}

	// The same statement written in the opposite order is a DIFFERENT statement,
	// and must not be normalised into equality with the one above.
	swapped := "update engage_mails set rate = coalesce(100 * action / shown, 0), action = action + 1 where id = ?"
	if normaliseColumnOrder(sql) == normaliseColumnOrder(swapped) {
		t.Fatal("two SET lists that compute different values were treated as equal")
	}
}

func TestSetOrderIsLoadBearing(t *testing.T) {
	cases := []struct {
		name string
		set  []string
		want bool
	}{
		{"independent binds", []string{"status = ?", "resolvedat = now()"}, false},
		{"self-reference only", []string{"shown = shown + 1", "status = ?"}, false},
		{"cross-reference", []string{"action = action + 1", "rate = 100 * action / shown"}, true},
		{"cross-reference backwards", []string{"rate = 100 * action / shown", "action = action + 1"}, true},
		// A column name inside a quoted literal is text, not a reference.
		{"column named in a string literal", []string{"note = 'check the rate later'", "rate = ?"}, false},
		// Backticked and table-qualified names still have to be recognised, or a
		// load-bearing order would slip past as unparseable-but-assumed-safe.
		{"backticked column", []string{"`action` = `action` + 1", "rate = action * 2"}, true},
	}
	for _, c := range cases {
		if got := setOrderIsLoadBearing(c.set); got != c.want {
			t.Errorf("%s: setOrderIsLoadBearing(%v) = %v, want %v", c.name, c.set, got, c.want)
		}
	}
}

func TestCollapseInLists(t *testing.T) {
	cases := [][2]string{
		{"where id in ?", "where id in ?"},
		{"where id in (?)", "where id in ?"},
		{"where id in (?,?,?)", "where id in ?"},
		{"where id in (?, ?, ?)", "where id in ?"},
	}
	for _, c := range cases {
		if got := collapseInLists(c[0]); got != c[1] {
			t.Errorf("collapseInLists(%q) = %q, want %q", c[0], got, c[1])
		}
	}

	// NOT IN must not fold into IN, and a list of literals is real content
	// rather than an artefact of how many values were bound.
	if collapseInLists("where id not in (?,?)") == collapseInLists("where id in (?,?)") {
		t.Error("NOT IN collapsed into IN")
	}
	if got := collapseInLists("where id in (1,2,3)"); got != "where id in (1,2,3)" {
		t.Errorf("a literal IN list was rewritten: %q", got)
	}
}

func TestResolveLimitOffset(t *testing.T) {
	// A bound LIMIT is resolved back to its literal so it can match a golden
	// that was written with one.
	if got := resolveLimitOffset("select a from t limit ?", []any{1}); got != "select a from t limit 1" {
		t.Errorf("limit not resolved: %q", got)
	}
	// Non-LIMIT placeholders keep their "?" - that is how the original was
	// written and the golden records it that way.
	got := resolveLimitOffset("select a from t where id = ? limit ?", []any{7, 1})
	if got != "select a from t where id = ? limit 1" {
		t.Errorf("a non-LIMIT bind was wrongly substituted: %q", got)
	}
}
