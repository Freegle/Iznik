// node --test scripts/check-portable-migrations.test.mjs
import test from 'node:test'
import assert from 'node:assert/strict'
import { findUnmarked } from './check-portable-migrations.mjs'

test('a Blueprint-only migration has no raw sites', () => {
  const src = `<?php
Schema::create('things', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});`
  assert.deepEqual(findUnmarked(src), [])
})

test('a raw statement with no marker is found', () => {
  const src = `<?php
DB::statement('ALTER TABLE things ADD FULLTEXT (name)');
DB::table('things')->whereRaw('a = 1')->get();`
  const r = findUnmarked(src)
  assert.deepEqual(r.map((x) => x.line), [2, 3])
})

test('a marker on the same line or the line above passes', () => {
  const src = `<?php
// mysql-only: FULLTEXT has no PostgreSQL form
DB::statement('ALTER TABLE things ADD FULLTEXT (name)');
DB::unprepared('SELECT 1'); // mysql-only: one-off data repair
`
  assert.deepEqual(findUnmarked(src), [])
})

test('a marker with no reason does not count', () => {
  assert.equal(findUnmarked('// mysql-only:\nDB::statement("x");').length, 1)
})

test('DB::table and Schema calls are not raw', () => {
  assert.deepEqual(findUnmarked("DB::table('t')->where('a', 1)->update(['b' => 2]);\nSchema::table('t', fn () => null);"), [])
})
