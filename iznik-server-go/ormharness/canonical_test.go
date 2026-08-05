package ormharness

import "testing"

// assertCanonicalEqual and assertCanonicalNotEqual are the two shapes every
// case below needs: "these differ only cosmetically" and "these differ for
// a real reason, so Canonical must not paper over it".
func assertCanonicalEqual(t *testing.T, a, b string) {
	t.Helper()
	ca, cb := Canonical(a), Canonical(b)
	if ca != cb {
		t.Fatalf("expected canonically equal:\n  a: %s\n  -> %s\n  b: %s\n  -> %s", a, ca, b, cb)
	}
}

func assertCanonicalNotEqual(t *testing.T, a, b string) {
	t.Helper()
	ca, cb := Canonical(a), Canonical(b)
	if ca == cb {
		t.Fatalf("expected canonically different, both reduced to %q:\n  a: %s\n  b: %s", ca, a, b)
	}
}

func TestCanonical_KeywordCase(t *testing.T) {
	assertCanonicalEqual(t,
		"select * from users where id = ?",
		"SELECT * FROM users WHERE id = ?",
	)
	// Identifier case is left alone here on purpose: the very next test pins
	// that identifier case IS significant, so varying "Id"/"Users" as well
	// would assert the opposite of the package's own design.
	assertCanonicalEqual(t,
		"Select id From users Where id = ? Order By id Desc",
		"SELECT id FROM users WHERE id = ? ORDER BY id DESC",
	)
}

// Identifier case is deliberately NOT normalised: only recognised keywords
// and built-in function names are. A case difference in a table or column
// name is exactly the kind of typo Layer 1 exists to catch.
func TestCanonical_IdentifierCaseIsSignificant(t *testing.T) {
	assertCanonicalNotEqual(t,
		"SELECT * FROM Users",
		"SELECT * FROM users",
	)
}

func TestCanonical_Whitespace(t *testing.T) {
	assertCanonicalEqual(t,
		"SELECT   *    FROM\tusers\n WHERE   id=?",
		"SELECT * FROM users WHERE id = ?",
	)
	// Leading/trailing whitespace around the whole statement too.
	assertCanonicalEqual(t,
		"  SELECT * FROM users WHERE id = ?  ",
		"SELECT * FROM users WHERE id = ?",
	)
}

func TestCanonical_BacktickVsBareIdentifiers(t *testing.T) {
	assertCanonicalEqual(t,
		"SELECT `id`, `name` FROM `users` WHERE `id` = ?",
		"SELECT id, name FROM users WHERE id = ?",
	)
}

// A column named after a reserved word only parses at all because it is
// backtick-quoted in the source SQL; classifyKeywords must never promote a
// quoted identifier to a keyword, or the alias-scanning pass in
// renameAliases could misread an ordinary column list as a FROM clause.
// This is taken directly from a real site in the manifest
// (tools/orm-migration/manifest.json): alerts has columns named from and
// to, which only compile as SQL because they are backtick-quoted.
func TestCanonical_BacktickedReservedWordIsNotMistakenForKeyword(t *testing.T) {
	assertCanonicalEqual(t,
		"INSERT INTO alerts (createdby, subject, text, html, `from`, `to`, created) VALUES (?, ?, ?, ?, ?, 'Users', NOW())",
		"insert into alerts (createdby, subject, text, html, `from`, `to`, created) values (?, ?, ?, ?, ?, 'Users', now())",
	)
}

func TestCanonical_DoubleQuotedIdentifiers(t *testing.T) {
	assertCanonicalEqual(t,
		`SELECT "id" FROM "users" WHERE "id" = ?`,
		"SELECT id FROM users WHERE id = ?",
	)
}

func TestCanonical_AliasRenaming(t *testing.T) {
	// The plan's own example: implicit vs explicit alias syntax.
	assertCanonicalEqual(t,
		"SELECT * FROM users u WHERE u.id = ?",
		"SELECT * FROM users AS u1 WHERE u1.id = ?",
	)
	// Different alias letters entirely: only the position of first
	// appearance should matter, not the literal text chosen.
	assertCanonicalEqual(t,
		"SELECT u.name FROM users u JOIN memberships m ON m.userid = u.id",
		"SELECT x.name FROM users AS x JOIN memberships AS y ON y.userid = x.id",
	)
	// A genuinely different join shape must still compare unequal: alias
	// renaming must not make unrelated queries look the same.
	assertCanonicalNotEqual(t,
		"SELECT u.name FROM users u JOIN memberships m ON m.userid = u.id",
		"SELECT u.name FROM users u JOIN groups m ON m.userid = u.id",
	)
}

// CAST(expr AS type) and CONVERT(expr, type) use AS for a target type, not
// an alias; renameAliases must not mistake UNSIGNED/CHAR/etc. for an alias
// name it needs to rewrite.
func TestCanonical_AliasRenaming_DoesNotMisfireOnCast(t *testing.T) {
	assertCanonicalEqual(t,
		"SELECT CAST(id AS UNSIGNED) FROM users",
		"select cast(id as unsigned) from users",
	)
}

func TestCanonical_StringLiteralsPreserved(t *testing.T) {
	// Case and internal spacing inside a string literal must survive
	// verbatim: canonicalisation only touches SQL syntax, never data.
	base := "SELECT * FROM t WHERE name = 'John   Smith'"

	assertCanonicalEqual(t, base, "select * from t where name = 'John   Smith'")

	assertCanonicalNotEqual(t, base, "SELECT * FROM t WHERE name = 'john   smith'") // case inside the literal changed
	assertCanonicalNotEqual(t, base, "SELECT * FROM t WHERE name = 'John Smith'")   // whitespace inside the literal collapsed
	assertCanonicalNotEqual(t, base, "SELECT * FROM t WHERE name = 'Jane   Smith'") // different value entirely

	// A word that looks like a keyword must not be touched when it is
	// inside a string.
	assertCanonicalEqual(t,
		"SELECT * FROM t WHERE note = 'select from where'",
		"select * from t where note = 'select from where'",
	)
}

func TestCanonical_PlaceholdersAndLiteralsBothSurvive(t *testing.T) {
	// A statement mixing bound placeholders with literal values (as GORM's
	// clause.OnConflict / hand-written upserts do) must keep both distinct.
	assertCanonicalEqual(t,
		"INSERT INTO chat_roster (chatid, userid, status, lastmsgseen, date) VALUES (?, ?, 'Online', 0, NOW()) ON DUPLICATE KEY UPDATE lastmsgseen = 0",
		"insert into chat_roster (chatid, userid, status, lastmsgseen, date) values (?, ?, 'Online', 0, now()) on duplicate key update lastmsgseen = 0",
	)
	assertCanonicalNotEqual(t,
		"INSERT INTO chat_roster (chatid, userid, status) VALUES (?, ?, 'Online')",
		"INSERT INTO chat_roster (chatid, userid, status) VALUES (?, ?, 'Offline')",
	)
}

func TestCanonical_GenuinelyDifferentStatementsStayDifferent(t *testing.T) {
	assertCanonicalNotEqual(t, "SELECT * FROM a WHERE id = ?", "SELECT * FROM b WHERE id = ?")
	assertCanonicalNotEqual(t, "SELECT id FROM users WHERE id = ?", "SELECT id, email FROM users WHERE id = ?")
	assertCanonicalNotEqual(t, "DELETE FROM chat_messages WHERE id = ?", "DELETE FROM chat_messages WHERE id = ? AND chatid = ?")
}

func TestCanonical_IsIdempotent(t *testing.T) {
	sql := "SELECT u.name FROM `users` AS u WHERE u.id = ? AND note = 'Select From Where'"
	once := Canonical(sql)
	twice := Canonical(once)
	if once != twice {
		t.Fatalf("Canonical is not idempotent:\n  once:  %s\n  twice: %s", once, twice)
	}
}
