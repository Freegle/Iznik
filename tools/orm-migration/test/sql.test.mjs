import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mysqlismsOf, normaliseSql, tablesOf, statementKind, suppliesOwnFrom } from '../lib/sql.mjs';

test('normaliseSql collapses whitespace and drops the trailing semicolon', () => {
  assert.equal(normaliseSql('  SELECT 1\n  FROM   t ; '), 'SELECT 1 FROM t');
});

test('dialect features that PostgreSQL rejects or spells differently are found', () => {
  const cases = {
    'INSERT INTO t (a) VALUES (1) ON DUPLICATE KEY UPDATE a = 2': 'on-duplicate-key',
    'UPDATE a JOIN b ON a.id = b.id SET a.x = 1': 'update-join',
    'UPDATE a AS x INNER JOIN b ON x.id = b.id SET x.y = 1': 'update-join',
    'DELETE a FROM a JOIN b ON a.id = b.id': 'delete-join',
    'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()': 'database-function',
    'SELECT * FROM t WHERE d > NOW() - INTERVAL 5 MINUTE': 'interval-unit',
    'SELECT IFNULL(a, 0) FROM t': 'ifnull',
    'SELECT ST_Contains(a, b) FROM t': 'spatial',
    "SELECT JSON_EXTRACT(settings, '$.a') FROM t": 'json-functions',
    'SELECT a FROM t FORCE INDEX (x)': 'force-index',
    'SELECT `a` FROM t': 'backticks',
    'SELECT a FROM t LIMIT ?, ?': 'limit-offset-comma',
    'INSERT IGNORE INTO t VALUES (1)': 'insert-ignore',
    'SELECT GET_LOCK(?, 1)': 'get-lock',
  };
  for (const [sql, feature] of Object.entries(cases)) {
    assert.ok(mysqlismsOf(sql).includes(feature), `${feature} in ${sql}`);
  }
});

test('portable SQL has no dialect features', () => {
  for (const sql of [
    'SELECT id, name FROM users WHERE id = ? AND created > NOW()',
    'UPDATE users SET name = ? WHERE id = ?',
    'DELETE FROM users WHERE id IN (SELECT userid FROM banned)',
    'SELECT COALESCE(a, 0), COUNT(*) FROM t GROUP BY a ORDER BY a LIMIT ? OFFSET ?',
  ]) {
    assert.deepEqual(mysqlismsOf(sql), [], sql);
  }
});

test('tables, kind and top-level clauses', () => {
  assert.deepEqual(tablesOf('SELECT * FROM a JOIN b ON a.id = b.id WHERE x IN (SELECT y FROM c)'), ['a', 'b', 'c']);
  assert.deepEqual(tablesOf('DELETE FROM sessions WHERE id = ?'), ['sessions']);
  assert.equal(statementKind('(SELECT 1) UNION (SELECT 2)'), 'SELECT');
  assert.equal(statementKind('WITH x AS (SELECT 1) SELECT * FROM x'), 'SELECT');
  assert.equal(statementKind('DROP TEMPORARY TABLE x'), 'DDL');
  assert.equal(suppliesOwnFrom('SELECT a FROM t'), true);
  assert.equal(suppliesOwnFrom('EXISTS(SELECT 1 FROM t)'), false);
});

test('structural and silent-change features are flagged', () => {
  const cases = {
    'UPDATE t SET a = 1 ORDER BY id LIMIT 5': 'update-delete-order-limit',
    'DELETE FROM t WHERE a = 1 LIMIT 100': 'update-delete-order-limit',
    'SELECT x, COUNT(*) AS n FROM t GROUP BY x HAVING n > 1': 'having-alias',
    'SELECT CAST(a AS SIGNED) FROM t': 'cast-signed',
    'SELECT LEAST(a, b) FROM t': 'least-greatest',
    "SELECT SUBSTRING_INDEX(email, '@', -1) FROM t": 'string-functions',
    'SELECT LOCATE(? , name) FROM t': 'string-functions',
    'SELECT LENGTH(domain) FROM t': 'length-function',
    "SELECT CONCAT(a, b) FROM t": 'concat-function',
    'SELECT heldby IS NOT NULL AS held FROM t': 'boolean-projection',
    'count + VALUES(count)': 'values-function',
    'PDO::lastInsertId()': 'last-insert-id',
  };
  for (const [sql, feature] of Object.entries(cases)) {
    assert.ok(mysqlismsOf(sql).includes(feature), `${feature} in ${sql}`);
  }
});

test('the structural features do not fire on portable SQL', () => {
  for (const sql of [
    'UPDATE t SET a = (SELECT b FROM u ORDER BY id LIMIT 1) WHERE id = ?',
    'SELECT x, COUNT(*) AS n FROM t GROUP BY x HAVING COUNT(*) > 1',
    'SELECT JSON_LENGTH(a), CONCAT_WS(",", a, b) FROM t',
    'INSERT INTO t (a) VALUES (?)',
  ]) {
    const hit = mysqlismsOf(sql).filter((m) => ['update-delete-order-limit', 'having-alias', 'length-function', 'concat-function', 'values-function'].includes(m));
    assert.deepEqual(hit, [], sql);
  }
});
