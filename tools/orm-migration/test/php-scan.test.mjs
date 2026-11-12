import { test } from 'node:test';
import assert from 'node:assert/strict';
import { scanPhpSource } from '../lib/php-scan.mjs';

const scan = (body, header = '') => scanPhpSource(`<?php\n${header}\n${body}`, 'app/Services/X.php');

test('finds facade statements and folds concatenation', () => {
  const sites = scan(`class Foo {
    public function run() {
        $rows = DB::select('SELECT id, name '
            . 'FROM users WHERE id = ?', [1]);
        DB::statement("DELETE FROM sessions WHERE id = 1");
        DB::update('UPDATE users SET a = 1');
    }
}`);
  assert.equal(sites.length, 3);
  assert.equal(sites[0].goldenSql, 'SELECT id, name FROM users WHERE id = ?');
  assert.equal(sites[0].class, 'Foo');
  assert.equal(sites[0].function, 'run');
  assert.equal(sites[0].surface, 'statement');
  assert.equal(sites[0].line, 5);
  assert.deepEqual(sites.map((s) => s.kind), ['SELECT', 'DELETE', 'UPDATE']);
});

test('heredoc and nowdoc strings are one string', () => {
  const sites = scan(`function f() {
    DB::select(<<<SQL
        SELECT a
        FROM t
        WHERE b = ?
        SQL, [1]);
    DB::select(<<<'SQL'
        SELECT $notAVariable FROM u
        SQL);
}`);
  assert.equal(sites[0].goldenSql, 'SELECT a FROM t WHERE b = ?');
  assert.equal(sites[1].goldenSql, 'SELECT $notAVariable FROM u');
  assert.equal(sites[1].dynamic, false);
});

test('interpolation makes a site dynamic', () => {
  const [s] = scan(`function f($t) { DB::select("SELECT * FROM $t WHERE id = ?", [1]); }`);
  assert.equal(s.dynamic, true);
  assert.equal(s.sqlSource, 'partial');
  assert.equal(s.goldenSql, 'SELECT * FROM {{expr}} WHERE id = ?');
});

test('class constants are resolved, and a variable statement is a site', () => {
  const sites = scan(`class C {
    const COLS = 'a, b';
    function f($sql) {
        DB::select('SELECT ' . self::COLS . ' FROM t');
        DB::select($sql);
    }
}`);
  assert.equal(sites[0].goldenSql, 'SELECT a, b FROM t');
  assert.equal(sites[0].dynamic, false);
  assert.equal(sites[1].sqlSource, 'variable');
  assert.equal(sites[1].goldenSql, '{{built at runtime: $sql}}');
});

test('a cast in a concatenation does not hide the statement', () => {
  const [s] = scan(`function f($d) { DB::raw('DATE_ADD(NOW(), INTERVAL ' . (int) $d . ' SECOND)'); }`);
  assert.equal(s.goldenSql, 'DATE_ADD(NOW(), INTERVAL {{expr}} SECOND)');
  assert.equal(s.surface, 'expression');
});

test('comments are ignored', () => {
  const sites = scan(`function f() {
    // DB::select('SELECT 1');
    # DB::select('SELECT 2');
    /* DB::select('SELECT 3'); */
    $x = '->whereRaw(1)';
}`);
  assert.equal(sites.length, 0);
});

test('builder fragments are sites with a kind', () => {
  const sites = scan(`function f() {
    User::query()->whereRaw('a > ?', [1])->orderByRaw('FIELD(id, 1, 2)')->selectRaw('COUNT(*) AS c')->havingRaw('c > 1');
}`);
  assert.deepEqual(sites.map((s) => s.method), ['whereRaw', 'orderByRaw', 'selectRaw', 'havingRaw']);
  assert.deepEqual(sites.map((s) => s.kind), ['WHERE', 'ORDER', 'SELECT-LIST', 'HAVING']);
  assert.ok(sites.every((s) => s.surface === 'fragment'));
  assert.ok(sites[1].mysqlisms.includes('concat-ws-or-field'));
});

test('DB::raw is a site only when it holds a dialect feature', () => {
  const sites = scan(`function f() {
    DB::raw('COUNT(*) AS n');
    DB::raw('NOW()');
    DB::raw('IFNULL(a, 0)');
    DB::raw($thing);
}`);
  assert.deepEqual(sites.map((s) => s.goldenSql), ['IFNULL(a, 0)', '{{built at runtime: $thing}}']);
});

test('connection calls are sites, other select and update calls are not', () => {
  const sites = scan(`function f($query) {
    DB::connection('x')->select('SELECT 1');
    $query->update(['a' => 1]);
    $query->select('a', 'b');
    $model->delete();
}`);
  assert.equal(sites.length, 1);
  assert.equal(sites[0].receiver, 'connection');
});

test('PDO calls are sites when named for PDO or given SQL', () => {
  const sites = scan(`function f($db) {
    $this->pdo->prepare('SELECT a FROM t');
    $db->getPdo()->exec('PRAGMA x');
    $handle->prepare('INSERT INTO t VALUES (1)');
    $handle->prepare($notSql);
    $mailer->query('what');
}`);
  assert.deepEqual(sites.map((s) => s.surface), ['pdo', 'pdo', 'pdo']);
  assert.equal(sites.length, 3);
});

test('a keep-raw comment above the statement is the annotation', () => {
  const [s] = scan(`function f() {
    // keep-raw: a window function the builder cannot express.
    $rows = collect(DB::select('SELECT ROW_NUMBER() OVER (ORDER BY id) FROM t'));
}`);
  assert.equal(s.annotation, 'a window function the builder cannot express.');
});

test('inline HTML outside php tags is not scanned', () => {
  const sites = scanPhpSource("<p>DB::select('SELECT 1')</p>\n<?php DB::select('SELECT 2');", 'x.php');
  assert.equal(sites.length, 1);
  assert.equal(sites[0].goldenSql, 'SELECT 2');
  assert.equal(sites[0].line, 2);
});
