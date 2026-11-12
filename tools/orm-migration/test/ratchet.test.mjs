import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { main } from '../extract.mjs';
import {
  scanRepo, loadRules, buildManifest, readManifest, checkManifest, serialise,
} from '../lib/inventory.mjs';

// A small repository with one Go and one PHP production file, built in a
// temporary directory so each test starts from a known state.
function makeRepo(files = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'rawsql-'));
  const base = {
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
    'iznik-server-go/user/user_test.go': `package user
func TestLoad(t *testing.T) {
	db.Exec("DELETE FROM users")
}
`,
    'tools/orm-migration/rules.json': JSON.stringify({ rules: [] }),
  };
  for (const [f, text] of Object.entries({ ...base, ...files })) write(dir, f, text);
  return dir;
}

function write(dir, rel, text) {
  const full = path.join(dir, rel);
  fs.mkdirSync(path.dirname(full), { recursive: true });
  fs.writeFileSync(full, text);
}

function run(dir, ...args) {
  const out = [];
  const err = [];
  const code = main([...args, '--root', dir], {
    log: (m) => out.push(m),
    error: (m) => err.push(m),
  });
  return { code, out: out.join('\n'), err: err.join('\n') };
}

const manifestOf = (dir) => readManifest(path.join(dir, 'tools/orm-migration/manifest.json'));

test('update writes a manifest and check passes on it', () => {
  const dir = makeRepo();
  assert.equal(run(dir, 'update').code, 0);
  const m = manifestOf(dir);
  assert.equal(m.sites.length, 2);
  assert.deepEqual(m.counts, { raw: 2 });
  assert.equal(m.ratchet.baseline, 2);
  assert.equal(m.testFixtures.total, 1);
  assert.equal(run(dir, 'check').code, 0);
});

test('check fails when there is no manifest', () => {
  const dir = makeRepo();
  const r = run(dir, 'check');
  assert.equal(r.code, 1);
  assert.match(r.err, /no manifest/i);
});

test('a new raw SQL site with no manifest entry fails the check', () => {
  const dir = makeRepo();
  run(dir, 'update');
  write(dir, 'iznik-server-go/user/more.go', `package user
func More(db *gorm.DB) {
	db.Exec("DELETE FROM sessions WHERE id = ?", 1)
}
`);
  const r = run(dir, 'check');
  assert.equal(r.code, 1);
  assert.match(r.err, /NEW raw SQL with no manifest entry: iznik-server-go\/user\/more\.go:3/);
});

test('a new PHP site and a new fragment fail the check', () => {
  const dir = makeRepo();
  run(dir, 'update');
  write(dir, 'iznik-batch/app/Services/Bar.php', `<?php
class Bar { function f() { return User::query()->whereRaw('a > 1')->get(); } }
`);
  const r = run(dir, 'check');
  assert.equal(r.code, 1);
  assert.match(r.err, /Bar\.php:2 .*\[fragment\] a > 1/);
});

test('update refuses to raise the baseline, and says what to do', () => {
  const dir = makeRepo();
  run(dir, 'update');
  write(dir, 'iznik-server-go/user/more.go', `package user
func More(db *gorm.DB) { db.Exec("DELETE FROM sessions") }
`);
  const r = run(dir, 'update');
  assert.equal(r.code, 1);
  assert.match(r.err, /keep-raw: <reason>/);
  assert.equal(manifestOf(dir).sites.length, 2);
});

test('a keep-raw comment makes the new site a keep-raw entry without raising the baseline', () => {
  const dir = makeRepo();
  run(dir, 'update');
  write(dir, 'iznik-server-go/user/more.go', `package user
func More(db *gorm.DB) {
	// keep-raw: the table name is chosen at runtime.
	db.Exec("DELETE FROM sessions")
}
`);
  assert.equal(run(dir, 'check').code, 1, 'the manifest has not been updated yet');
  assert.equal(run(dir, 'update').code, 0);
  const m = manifestOf(dir);
  const added = m.sites.find((s) => s.file.endsWith('more.go'));
  assert.equal(added.status, 'keep-raw');
  assert.equal(added.reason, 'the table name is chosen at runtime.');
  assert.equal(added.reasonSource, 'annotation');
  assert.equal(m.ratchet.baseline, 2);
  assert.equal(run(dir, 'check').code, 0);
});

test('--raise-baseline lets a reviewed rise through', () => {
  const dir = makeRepo();
  run(dir, 'update');
  write(dir, 'iznik-server-go/user/more.go', `package user
func More(db *gorm.DB) { db.Exec("DELETE FROM sessions") }
`);
  assert.equal(run(dir, 'update', '--raise-baseline').code, 0);
  assert.equal(manifestOf(dir).ratchet.baseline, 3);
  assert.equal(run(dir, 'check').code, 0);
});

test('against the base branch the baseline may not rise', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const committed = manifestOf(dir);
  const scan = scanRepo(dir);
  const rules = loadRules(path.join(dir, 'tools/orm-migration/rules.json'));
  const base = { ratchet: { baseline: 1 } };
  const { failures } = checkManifest(scan, committed, rules, { baseManifest: base });
  assert.ok(failures.some((f) => /baseline rose from 1 on the base branch to 2/.test(f)), failures.join('\n'));
  const ok = checkManifest(scan, committed, rules, { baseManifest: { ratchet: { baseline: 2 } } });
  assert.deepEqual(ok.failures, []);
});

test('lowering the count lowers the baseline, and a stale baseline fails', () => {
  const dir = makeRepo();
  run(dir, 'update');
  fs.rmSync(path.join(dir, 'iznik-batch/app/Services/Foo.php'));
  const r = run(dir, 'check');
  assert.equal(r.code, 1);
  assert.match(r.err, /baseline 2 is stale, the code has 1/);
  assert.equal(run(dir, 'update').code, 0);
  const m = manifestOf(dir);
  assert.equal(m.ratchet.baseline, 1);
  const gone = m.sites.find((s) => s.file.endsWith('Foo.php'));
  assert.equal(gone.status, 'retired');
  assert.equal(gone.presentInCode, false);
  assert.equal(run(dir, 'check').code, 0);
});

test('changing a site\'s SQL needs the manifest updated, and keeps a keep-raw decision', () => {
  const dir = makeRepo({
    'iznik-server-go/user/user.go': `package user
func Load(db *gorm.DB) {
	// keep-raw: window function.
	db.Raw("SELECT id, ROW_NUMBER() OVER (ORDER BY id) FROM users").Scan(&u)
}
`,
  });
  run(dir, 'update');
  const before = manifestOf(dir).sites.find((s) => s.file.endsWith('user.go'));
  write(dir, 'iznik-server-go/user/user.go', `package user
func Load(db *gorm.DB) {
	// keep-raw: window function.
	db.Raw("SELECT id, name, ROW_NUMBER() OVER (ORDER BY id) FROM users").Scan(&u)
}
`);
  const r = run(dir, 'check');
  assert.equal(r.code, 1);
  assert.match(r.err, /NEW raw SQL/);
  assert.match(r.err, /no longer matches the code/);
  assert.equal(run(dir, 'update').code, 0);
  const m = manifestOf(dir);
  const after = m.sites.filter((s) => s.file.endsWith('user.go'));
  assert.equal(after.length, 1, 'the old entry is replaced, not retired');
  assert.notEqual(after[0].id, before.id);
  assert.equal(after[0].status, 'keep-raw');
  assert.match(after[0].goldenSql, /id, name,/);
});

test('moving a site down the file is not a change', () => {
  const dir = makeRepo();
  run(dir, 'update');
  write(dir, 'iznik-server-go/user/user.go', `package user

// A new comment pushes everything down.

func Load(db *gorm.DB) {
	db.Raw("SELECT id FROM users WHERE id = ?", 1).Scan(&u)
}
`);
  assert.equal(run(dir, 'check').code, 0);
  assert.equal(run(dir, 'update').code, 0);
  assert.equal(manifestOf(dir).sites.find((s) => s.file.endsWith('user.go')).line, 6);
});

test('two identical statements in one file are two entries', () => {
  const dir = makeRepo({
    'iznik-server-go/user/user.go': `package user
func A(db *gorm.DB) { db.Raw("SELECT 1 FROM t") }
func B(db *gorm.DB) { db.Raw("SELECT 1 FROM t") }
`,
  });
  run(dir, 'update');
  const ids = manifestOf(dir).sites.filter((s) => s.file.endsWith('user.go')).map((s) => s.id);
  assert.equal(new Set(ids).size, 2);
});

test('test files are counted, not inventoried, and adding fixtures does not fail the check', () => {
  const dir = makeRepo();
  run(dir, 'update');
  write(dir, 'iznik-server-go/user/other_test.go', `package user
func TestOther(t *testing.T) { db.Exec("DELETE FROM a"); db.Exec("DELETE FROM b") }
`);
  assert.equal(run(dir, 'check').code, 0);
  run(dir, 'update');
  assert.equal(manifestOf(dir).testFixtures.total, 3);
});

test('a hand edit to the recorded SQL or status is caught', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const file = path.join(dir, 'tools/orm-migration/manifest.json');
  const m = readManifest(file);
  m.sites[0].goldenSql = 'SELECT something else';
  fs.writeFileSync(file, serialise(m));
  assert.equal(run(dir, 'check').code, 1);
  run(dir, 'update');
  const m2 = readManifest(file);
  m2.sites[0].status = 'converted';
  fs.writeFileSync(file, serialise(m2));
  const r = run(dir, 'check');
  assert.equal(r.code, 1);
  assert.match(r.err, /converted entry .* has no parity test/);
});

test('keep-raw needs a reason', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const file = path.join(dir, 'tools/orm-migration/manifest.json');
  const m = readManifest(file);
  m.sites[0].status = 'keep-raw';
  fs.writeFileSync(file, serialise(m));
  assert.match(run(dir, 'check').err, /keep-raw entry .* has no reason/);
});

test('a keep-raw decision recorded only in the manifest is carried forward', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const file = path.join(dir, 'tools/orm-migration/manifest.json');
  const m = readManifest(file);
  m.sites[0].status = 'keep-raw';
  m.sites[0].reason = 'Reviewed: the statement is clearer as SQL.';
  fs.writeFileSync(file, serialise(m));
  assert.equal(run(dir, 'update').code, 0);
  const after = manifestOf(dir);
  assert.equal(after.sites[0].status, 'keep-raw');
  assert.equal(after.sites[0].reason, 'Reviewed: the statement is clearer as SQL.');
  assert.equal(after.ratchet.baseline, 1);
  assert.equal(run(dir, 'check').code, 0);
});

test('removing the annotation puts an annotation-sourced entry back to raw', () => {
  const dir = makeRepo({
    'iznik-server-go/user/user.go': `package user
func Load(db *gorm.DB) {
	// keep-raw: because.
	db.Raw("SELECT id FROM users").Scan(&u)
}
`,
  });
  run(dir, 'update');
  assert.equal(manifestOf(dir).ratchet.baseline, 1);
  write(dir, 'iznik-server-go/user/user.go', `package user
func Load(db *gorm.DB) {
	db.Raw("SELECT id FROM users").Scan(&u)
}
`);
  const r = run(dir, 'update');
  assert.equal(r.code, 1, 'the site is raw again, so the count rose');
});

test('a converted site is recorded when its raw SQL goes and a parity test names its id', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const id = manifestOf(dir).sites.find((s) => s.file.endsWith('user.go')).id;
  write(dir, 'iznik-server-go/user/user.go', 'package user\nfunc Load(db *gorm.DB) { db.Where("id = ?", 1).Find(&u) }\n');
  write(dir, 'iznik-server-go/user/user_test.go', `package user
func TestLoadParity(t *testing.T) { assertParity(t, "${id}") }
`);
  assert.equal(run(dir, 'update').code, 0);
  const site = manifestOf(dir).sites.find((s) => s.id === id);
  assert.equal(site.status, 'converted');
  assert.equal(site.presentInCode, false);
  assert.equal(run(dir, 'check').code, 0);
  // Remove the test and the entry is no longer proven.
  write(dir, 'iznik-server-go/user/user_test.go', 'package user\n');
  assert.match(run(dir, 'check').err, /converted entry .* has no parity test/);
});

test('rules: excluded and keep-raw apply by file, directory and surface; a rule needs a reason', () => {
  const dir = makeRepo({
    'tools/orm-migration/rules.json': JSON.stringify({
      rules: [
        { file: 'iznik-server-go/user/user.go', status: 'excluded', reason: 'Other engine.' },
        { file: 'iznik-batch/app/Services/', surface: 'pdo', status: 'excluded', reason: 'Local file.' },
      ],
    }),
  });
  run(dir, 'update');
  const m = manifestOf(dir);
  assert.equal(m.sites.find((s) => s.file.endsWith('user.go')).status, 'excluded');
  assert.equal(m.sites.find((s) => s.file.endsWith('Foo.php')).status, 'raw', 'surface did not match');
  assert.deepEqual(m.counts, { excluded: 1, raw: 1 });
  assert.equal(m.ratchet.baseline, 1);

  const bad = makeRepo({ 'tools/orm-migration/rules.json': JSON.stringify({ rules: [{ file: 'x/', reason: ' ' }] }) });
  assert.throws(() => run(bad, 'update'), /no reason/);
  const worse = makeRepo({ 'tools/orm-migration/rules.json': JSON.stringify({ rules: [{ file: 'x/', status: 'converted', reason: 'r' }] }) });
  assert.throws(() => run(worse, 'update'), /rules may only set/);
});

test('the manifest is one line per site and identical on a second run', () => {
  const dir = makeRepo();
  run(dir, 'update');
  const first = fs.readFileSync(path.join(dir, 'tools/orm-migration/manifest.json'), 'utf8');
  run(dir, 'update');
  const second = fs.readFileSync(path.join(dir, 'tools/orm-migration/manifest.json'), 'utf8');
  assert.equal(first, second);
  assert.equal(first.split('\n').filter((l) => l.startsWith('    {"id"')).length, 2);
  JSON.parse(first);
});

test('buildManifest on its own reports status counts and a baseline equal to open sites', () => {
  const dir = makeRepo();
  const { manifest } = buildManifest(scanRepo(dir), null, []);
  assert.equal(manifest.ratchet.baseline, 2);
  assert.deepEqual(manifest.counts, { raw: 2 });
});

test('a keep-raw marker that no site picks up is reported and does not fail the check', () => {
  const dir = makeRepo({
    'iznik-server-go/user/note.go': `package user
// keep-raw: this sits on code that is not SQL
const columns = "a, b"
`,
  });
  assert.equal(run(dir, 'update').code, 0);
  const r = run(dir, 'check');
  assert.equal(r.code, 0);
  assert.match(r.out, /1 keep-raw markers sit on code/);
  assert.match(run(dir, 'orphans').out, /note\.go:2/);
});

test('an upsert clause is a site that raises the count when added without a marker', () => {
  const dir = makeRepo();
  assert.equal(run(dir, 'update').code, 0);
  write(dir, 'iznik-server-go/user/up.go', `package user
func Up(db *gorm.DB) {
	db.Clauses(clause.Insert{Modifier: "IGNORE"}).Create(&u)
}
`);
  const r = run(dir, 'check');
  assert.equal(r.code, 1);
  assert.match(r.err, /\[clause\]/);
});
