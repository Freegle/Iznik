import { test } from 'node:test';
import assert from 'node:assert/strict';
import { scanGoSource, tokeniseGo, collectConsts, keepRawNear } from '../lib/go-scan.mjs';

const scan = (src, opts) => scanGoSource(src, 'pkg/x.go', opts);

test('finds Raw and Exec on any receiver and folds concatenated literals', () => {
  const sites = scan(`package x
func (s *Service) Load(db *gorm.DB) {
	db.Raw("SELECT id, name " +
		"FROM users WHERE id = ?", 1).Scan(&u)
	tx.Exec("UPDATE users SET name = ? WHERE id = ?", "a", 1)
}`);
  assert.equal(sites.length, 2);
  assert.equal(sites[0].goldenSql, 'SELECT id, name FROM users WHERE id = ?');
  assert.equal(sites[0].function, 'Service.Load');
  assert.equal(sites[0].method, 'Raw');
  assert.equal(sites[0].line, 3);
  assert.equal(sites[0].kind, 'SELECT');
  assert.deepEqual(sites[0].tables, ['users']);
  assert.equal(sites[1].kind, 'UPDATE');
  assert.equal(sites[1].args, 2);
});

test('reads backtick strings, and ignores SQL in comments', () => {
  const sites = scan('package x\nfunc f() {\n\t// db.Raw("SELECT 1")\n\t/* db.Exec("DELETE FROM a") */\n\tdb.Raw(`SELECT a\n\tFROM `+"`b`"+`\n\tWHERE c = ?`, 1)\n}\n');
  assert.equal(sites.length, 1);
  assert.equal(sites[0].goldenSql, 'SELECT a FROM `b` WHERE c = ?');
  assert.ok(sites[0].mysqlisms.includes('backticks'));
});

test('a quote or bracket inside SQL does not end the call early', () => {
  const sites = scan(`package x
func f() {
	db.Raw("SELECT ')' AS p, '\\"' AS q FROM t WHERE a IN (?, ?)", 1, 2)
}`);
  assert.equal(sites.length, 1);
  assert.equal(sites[0].goldenSql, `SELECT ')' AS p, '"' AS q FROM t WHERE a IN (?, ?)`);
  assert.equal(sites[0].args, 2);
});

test('resolves package constants and Sprintf with constant arguments', () => {
  const consts = collectConsts(tokeniseGo(`package x
const cols = "id, name"
const (
	table = "users"
	SRID  = 3857
)
`).toks);
  assert.equal(consts.get('cols'), 'id, name');
  assert.equal(consts.get('SRID'), '3857');
  const sites = scan(`package x
func f() {
	db.Raw("SELECT " + cols + " FROM " + table)
	db.Raw(fmt.Sprintf("SELECT ST_GeomFromText(?, %d)", SRID))
	db.Raw(fmt.Sprintf("SELECT * FROM t LIMIT %d", n))
}`, { consts });
  assert.equal(sites[0].goldenSql, 'SELECT id, name FROM users');
  assert.equal(sites[0].dynamic, false);
  assert.equal(sites[1].goldenSql, 'SELECT ST_GeomFromText(?, 3857)');
  assert.equal(sites[1].dynamic, false);
  assert.equal(sites[2].dynamic, true);
  assert.equal(sites[2].goldenSql, 'SELECT * FROM t LIMIT %d');
});

test('resolves exported constants of another package', () => {
  const sites = scan('package x\nfunc f() { db.Raw("SELECT ?, " + utils.COLS + " FROM t") }', {
    crossConsts: new Map([['utils.COLS', 'a, b']]),
  });
  assert.equal(sites[0].goldenSql, 'SELECT ?, a, b FROM t');
  assert.equal(sites[0].dynamic, false);
});

test('a statement held in a variable is a site that cannot be read', () => {
  const sites = scan('package x\nfunc f() { db.Raw(query, 1) }');
  assert.equal(sites.length, 1);
  assert.equal(sites[0].sqlSource, 'variable');
  assert.equal(sites[0].dynamic, true);
  assert.equal(sites[0].goldenSql, '{{built at runtime: query}}');
  assert.equal(sites[0].kind, 'DYNAMIC');
});

test('a statement that starts with a variable is partial', () => {
  const sites = scan('package x\nfunc f() { db.Raw(base + " GROUP BY id") }');
  assert.equal(sites[0].sqlSource, 'partial');
  assert.equal(sites[0].goldenSql, '{{expr}} GROUP BY id');
});

test('Exec and Query on things that are not databases are not sites', () => {
  const sites = scan(`package x
func h(c *fiber.Ctx) {
	c.Query("start", "")
	tmpl.Execute(w, nil)
	cmd.Exec("ls")
	c.Query(name)
	db.Query(name)
}`);
  // Only db.Query(name), a variable on a database handle, counts.
  assert.equal(sites.length, 1);
  assert.equal(sites[0].receiver, 'db');
});

test('an ambiguous leading word needs a database receiver', () => {
  const sites = scan(`package x
func f(c *fiber.Ctx) {
	c.Exec("SET foo")
	db.Exec("SET foo = 1")
	other.Exec("INSERT INTO t VALUES (1)")
}`);
  assert.deepEqual(sites.map((s) => s.receiver), ['db', 'other']);
});

test('database wrappers count, with the SQL argument in the right place', () => {
  const sites = scan(`package x
func f() {
	database.RetryExec(db, "INSERT INTO t (a) VALUES (?)", 1)
	database.RetryQuery(db, &dest, "SELECT a FROM t")
	database.ExecInsertGetID(db, "INSERT INTO u (a) VALUES (?)", 1)
}`);
  assert.deepEqual(sites.map((s) => s.method), ['RetryExec', 'RetryQuery', 'ExecInsertGetID']);
  assert.deepEqual(sites.map((s) => s.kind), ['INSERT', 'SELECT', 'INSERT']);
});

test('database/sql calls are sites', () => {
  const sites = scan(`package x
func f() {
	rows, _ := db.QueryContext(ctx, "SELECT 1")
	row := db.QueryRow("SELECT a FROM t WHERE id = ?", 1)
	stmt, _ := db.Prepare("INSERT INTO t (a) VALUES (?)")
}`);
  // QueryContext puts the context first, so its SQL is not the first argument.
  assert.deepEqual(sites.map((s) => s.method), ['QueryContext', 'QueryRow', 'Prepare']);
});

test('gorm.Expr is a site only when it holds something the other dialect spells differently', () => {
  const sites = scan(`package x
func f() {
	db.Update("a", gorm.Expr("NOW()"))
	db.Update("a", gorm.Expr("a + 1"))
	db.Update("b", gorm.Expr("IFNULL(b, 0) + ?", 1))
	db.Where(clause.Expr{SQL: "x"})
	db.Update("c", gorm.Expr(dynamicSql))
}`);
  assert.deepEqual(sites.map((s) => s.goldenSql), ['IFNULL(b, 0) + ?']);
  assert.equal(sites[0].surface, 'expression');
  assert.equal(sites[0].method, 'gorm.Expr');
  assert.ok(sites[0].mysqlisms.includes('ifnull'));
});

test('Select is SQL only in a function that overrides BuildClauses', () => {
  const plain = scan('package x\nfunc f() { db.Select("id, name") }');
  assert.equal(plain.length, 0);
  const sites = scan(`package x
func f() {
	db.Statement.BuildClauses = []string{"SELECT"}
	db.Select("SELECT a FROM t UNION SELECT b FROM u")
	db.Select("EXISTS(SELECT 1 FROM t)")
}`);
  assert.equal(sites.length, 1);
  assert.equal(sites[0].method, 'Select');
});

test('the enclosing function is named for methods and found for closures', () => {
  const sites = scan(`package x
func Top() {
	go func() {
		db.Exec("DELETE FROM a")
	}()
}
func (r *Repo) Method() { db.Exec("DELETE FROM b") }
var pkgLevel = db.Exec("DELETE FROM c")
`);
  assert.deepEqual(sites.map((s) => s.function), ['Top', 'Repo.Method', '']);
});

test('dialect features and complexity are recorded', () => {
  const [s] = scan(`package x
func f() {
	db.Exec("INSERT INTO t (a, b) VALUES (?, ?) ON DUPLICATE KEY UPDATE b = VALUES(b)", 1, 2)
}`);
  assert.deepEqual(s.mysqlisms, ['on-duplicate-key']);
  assert.equal(s.complexity, 'complex');
  assert.equal(s.wave, 3);
});

test('a keep-raw comment within 25 lines above a site is recorded as its annotation', () => {
  const [s] = scan(`package x
func f() {
	// keep-raw: the builder cannot render a UNION whose arm count follows the input.
	// A second line of the reason.
	db.Raw("SELECT 1 UNION SELECT 2")
}`);
  assert.equal(s.annotation, 'the builder cannot render a UNION whose arm count follows the input. A second line of the reason.');
});

test('a keep-raw comment 26 lines above does not reach the site', () => {
  const filler = '\n'.repeat(26);
  const [s] = scan(`package x
func f() {
	// keep-raw: too far away
${filler}	db.Raw("SELECT 1")
}`);
  assert.equal(s.annotation, undefined);
});

test('keepRawNear works on the line itself', () => {
  assert.equal(keepRawNear(['db.Raw("x") // keep-raw: inline reason'], 1), 'inline reason');
  assert.equal(keepRawNear(['db.Raw("x")'], 1), null);
});

test('GORM chain fragments are sites only when they hold a dialect feature', () => {
  const sites = scan(`package x
func f() {
	db.Where("id = ?", 1)
	db.Where("JSON_EXTRACT(settings, '$.a') = ?", 1)
	db.Order("FIELD(id, 3, 1, 2)")
	db.Joins("JOIN b ON ST_Contains(a.poly, b.pt)")
	db.Select("name, IFNULL(x, 0) AS x")
	db.Where(&User{})
	db.Where(cond)
}`);
  assert.deepEqual(sites.map((s) => s.kind), ['WHERE', 'ORDER', 'JOIN', 'SELECT-LIST']);
  assert.ok(sites.every((s) => s.surface === 'fragment'));
  assert.ok(sites[0].mysqlisms.includes('json-functions'));
});
