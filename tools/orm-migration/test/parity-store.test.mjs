import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { main } from '../extract.mjs';
import { STORES, CORPUS_COPIES, storeText, checkStores, checkApprovedDiffs, checkCorpusCopies } from '../lib/parity-store.mjs';

function makeRepo() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'paritystore-'));
  const files = {
    'iznik-server-go/user/user.go': `package user
func Load(db *gorm.DB) {
	db.Raw("SELECT id FROM users WHERE id = ?", 1).Scan(&u)
}
`,
    'iznik-batch/app/Services/Foo.php': `<?php
class Foo {
    public function run() {
        return DB::select('SELECT id FROM groups WHERE id = ?', [1]);
    }
}
`,
    'tools/orm-migration/rules.json': JSON.stringify({ rules: [] }),
  };
  for (const [f, text] of Object.entries(files)) {
    const full = path.join(dir, f);
    fs.mkdirSync(path.dirname(full), { recursive: true });
    fs.writeFileSync(full, text);
  }
  return dir;
}

function run(dir, ...args) {
  const out = [];
  const err = [];
  const code = main([...args, '--root', dir], { log: (m) => out.push(m), error: (m) => err.push(m) });
  return { code, out: out.join('\n'), err: err.join('\n') };
}

const readStore = (dir, store) => JSON.parse(fs.readFileSync(path.join(dir, store.path), 'utf8'));

test('update writes one golden store per stack, each holding only its own stack', () => {
  const dir = makeRepo();
  assert.equal(run(dir, 'update').code, 0);

  const [goStore, phpStore] = STORES;
  const go = readStore(dir, goStore);
  const php = readStore(dir, phpStore);
  assert.equal(Object.keys(go.sites).length, 1);
  assert.equal(Object.keys(php.sites).length, 1);

  const [goSite] = Object.values(go.sites);
  assert.equal(goSite.file, 'iznik-server-go/user/user.go');
  assert.equal(goSite.goldenSql, 'SELECT id FROM users WHERE id = ?');
  assert.equal(goSite.status, 'raw');
  assert.ok(Object.values(php.sites)[0].file.startsWith('iznik-batch/'));
});

test('check fails when a store is missing or stale and passes once update has run', () => {
  const dir = makeRepo();
  run(dir, 'update');
  assert.equal(run(dir, 'check').code, 0);

  const goPath = path.join(dir, STORES[0].path);
  fs.writeFileSync(goPath, fs.readFileSync(goPath, 'utf8').replace('users WHERE', 'users  WHERE'));
  const stale = run(dir, 'check');
  assert.equal(stale.code, 1);
  assert.match(stale.err, /goldens\.json is out of date/);

  fs.rmSync(goPath);
  assert.match(run(dir, 'check').err, /goldens\.json is missing/);

  run(dir, 'update');
  assert.equal(run(dir, 'check').code, 0);
});

test('a converted site keeps its golden in the store after the raw SQL is gone', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const id = Object.keys(readStore(dir, STORES[0]).sites)[0];

  // Convert: the raw call goes and a test names the id.
  fs.writeFileSync(path.join(dir, 'iznik-server-go/user/user.go'), 'package user\nfunc Load(db *gorm.DB) { db.Table("users").Take(&u) }\n');
  fs.writeFileSync(path.join(dir, 'iznik-server-go/user/user_pure_test.go'), `package user\nfunc TestLoad(t *testing.T) { parity.AssertGoldenSQL(t, "${id}", nil) }\n`);
  assert.equal(run(dir, 'update').code, 0);

  const site = readStore(dir, STORES[0]).sites[id];
  assert.equal(site.status, 'converted');
  assert.equal(site.goldenSql, 'SELECT id FROM users WHERE id = ?');
  assert.equal(run(dir, 'check').code, 0);
});

test('a site with no test naming it is not counted as converted', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const id = Object.keys(readStore(dir, STORES[0]).sites)[0];
  fs.writeFileSync(path.join(dir, 'iznik-server-go/user/user.go'), 'package user\nfunc Load(db *gorm.DB) { db.Table("users").Take(&u) }\n');
  run(dir, 'update');
  const m = JSON.parse(fs.readFileSync(path.join(dir, 'tools/orm-migration/manifest.json'), 'utf8'));
  assert.equal(m.sites.find((x) => x.id === id).status, 'retired');
  assert.equal(readStore(dir, STORES[0]).sites[id], undefined);
});

test('an approved diff needs both the SQL and the reason', () => {
  assert.deepEqual(checkApprovedDiffs([{ id: 'a', file: 'f', line: 1 }]), []);
  assert.deepEqual(checkApprovedDiffs([{ id: 'a', file: 'f', line: 1, approvedDiff: { sql: 'SELECT 1', reason: 'Same statement.' } }]), []);
  assert.equal(checkApprovedDiffs([{ id: 'a', file: 'f', line: 1, approvedDiff: { sql: 'SELECT 1', reason: ' ' } }]).length, 1);
  assert.equal(checkApprovedDiffs([{ id: 'a', file: 'f', line: 1, approvedDiff: 'SELECT 1' }]).length, 1);
});

test('the approved diff travels into the store', () => {
  const sites = [
    { id: 'aaaaaaaaaaaa', file: 'iznik-server-go/x.go', function: 'F', surface: 'statement', status: 'converted', goldenSql: 'SELECT 1', approvedDiff: { sql: 'SELECT 2', reason: 'r' } },
  ];
  const parsed = JSON.parse(storeText(sites, STORES[0]));
  assert.deepEqual(parsed.sites.aaaaaaaaaaaa.approvedDiff, { sql: 'SELECT 2', reason: 'r' });
  assert.deepEqual(checkStores(os.tmpdir(), sites).length, 2);
});

test('the two canonicaliser corpus copies must match', () => {
  const dir = makeRepo();
  assert.deepEqual(checkCorpusCopies(dir), []);
  for (const p of CORPUS_COPIES) {
    fs.mkdirSync(path.dirname(path.join(dir, p)), { recursive: true });
    fs.writeFileSync(path.join(dir, p), '{"equalPairs":[]}\n');
  }
  assert.deepEqual(checkCorpusCopies(dir), []);
  fs.writeFileSync(path.join(dir, CORPUS_COPIES[1]), '{"equalPairs":[["a","b"]]}\n');
  assert.equal(checkCorpusCopies(dir).length, 1);
  assert.match(run(dir, 'update').out, /written/);
  assert.equal(run(dir, 'check').code, 1);
});

test('approve records the approved diff in the manifest and the stores, and refuses without a reason', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const id = Object.keys(readStore(dir, STORES[0]).sites)[0];

  assert.equal(run(dir, 'approve', id, '--sql', 'UPDATE t SET a = ?').code, 1);
  assert.equal(run(dir, 'approve', 'ffffffffffff', '--sql', 'SELECT 1', '--reason', 'r').code, 1);

  const ok = run(dir, 'approve', id, '--sql', 'SELECT id FROM users WHERE id = ?  ', '--reason', 'Same statement.');
  assert.equal(ok.code, 0);
  assert.deepEqual(readStore(dir, STORES[0]).sites[id].approvedDiff, { sql: 'SELECT id FROM users WHERE id = ?', reason: 'Same statement.' });
  assert.equal(run(dir, 'check').code, 0);

  // The decision survives regeneration.
  run(dir, 'update');
  assert.equal(readStore(dir, STORES[0]).sites[id].approvedDiff.reason, 'Same statement.');
});

test('the parity package is test support, so its fixture SQL is not a production site', () => {
  const dir = makeRepo();
  fs.mkdirSync(path.join(dir, 'iznik-server-go/parity'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'iznik-server-go/parity/fixture.go'), 'package parity\nfunc G() any { return gorm.Expr("ST_GeomFromText(?, 4326)", w) }\n');
  run(dir, 'update');
  const m = JSON.parse(fs.readFileSync(path.join(dir, 'tools/orm-migration/manifest.json'), 'utf8'));
  assert.ok(!m.sites.some((s) => s.file.startsWith('iznik-server-go/parity/')));
  assert.ok(m.testFixtures.files['iznik-server-go/parity/fixture.go'] >= 1);
});
