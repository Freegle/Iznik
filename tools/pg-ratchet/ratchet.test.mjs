// Self-tests for the PostgreSQL test ratchet. No database needed:
//   node --test tools/pg-ratchet/ratchet.test.mjs

import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import {
  parseResults,
  formatResults,
  parseList,
  formatList,
  compare,
  parseGoLine,
  parseJunit,
  chunkConfig,
  groupFiles,
} from './lib.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))

test('results round trip', () => {
  const rows = [
    { kind: 'PASS', name: 'test:TestA' },
    { kind: 'FAIL', name: 'test:TestB' },
    { kind: 'CRASH', name: 'x:TestC' },
    { kind: 'SKIP', name: 'x:TestD' },
  ]
  assert.deepEqual(parseResults(formatResults(rows)), rows)
})

test('a name with a newline or tab is written as one line', () => {
  const text = formatResults([{ kind: 'FAIL', name: 'T::t with data set "a\n  b\tc"' }])
  assert.equal(text, 'FAIL\tT::t with data set "a b c"\n')
})

test('results reject a line with no tab or an unknown kind', () => {
  assert.throws(() => parseResults('PASS test:TestA\n'), /tab/)
  assert.throws(() => parseResults('MAYBE\tx\n'), /unknown kind/)
})

test('a failure in the list does not fail the run', () => {
  const r = compare(parseResults('PASS\ta\nFAIL\tb\n'), new Set(['b']))
  assert.equal(r.ok, true)
  assert.deepEqual(r.newFailures, [])
})

test('a failure outside the list fails the run and is named', () => {
  const r = compare(parseResults('PASS\ta\nFAIL\tb\nFAIL\tc\n'), new Set(['b']))
  assert.equal(r.ok, false)
  assert.deepEqual(r.newFailures, ['c'])
})

test('a crash counts as a failure', () => {
  const r = compare(parseResults('CRASH\tc\n'), new Set())
  assert.equal(r.ok, false)
  assert.deepEqual(r.newFailures, ['c'])
})

test('a listed test that passes is reported so the list can shrink', () => {
  const r = compare(parseResults('PASS\ta\nFAIL\tb\n'), new Set(['a', 'b']))
  assert.equal(r.ok, true)
  assert.deepEqual(r.nowPassing, ['a'])
})

test('a skipped listed test is neither passing nor absent', () => {
  const r = compare(parseResults('SKIP\ta\n'), new Set(['a']))
  assert.deepEqual(r.nowPassing, [])
  assert.deepEqual(r.absent, [])
})

test('listed names missing from the run are reported', () => {
  const r = compare(parseResults('PASS\ta\n'), new Set(['gone']))
  assert.deepEqual(r.absent, ['gone'])
})

test('an empty run is a failure, never a pass', () => {
  const r = compare([], new Set())
  assert.equal(r.ok, false)
  assert.equal(r.empty, true)
})

test('the list format is sorted, has comments and round trips', () => {
  const text = formatList(new Set(['b', 'a']), ['one', 'two'])
  assert.equal(text, '# one\n# two\na\nb\n')
  assert.deepEqual([...parseList(text)], ['a', 'b'])
})

test('go lines: top level results and starts, subtests ignored', () => {
  assert.deepEqual(parseGoLine('=== RUN   TestFoo'), { event: 'run', name: 'TestFoo' })
  assert.deepEqual(parseGoLine('--- PASS: TestFoo (0.01s)'), { event: 'pass', name: 'TestFoo' })
  assert.deepEqual(parseGoLine('--- FAIL: TestFoo (0.00s)'), { event: 'fail', name: 'TestFoo' })
  assert.deepEqual(parseGoLine('--- SKIP: TestFoo (0.00s)'), { event: 'skip', name: 'TestFoo' })
  assert.equal(parseGoLine('=== RUN   TestFoo/sub'), null)
  assert.equal(parseGoLine('    --- FAIL: TestFoo/sub (0.00s)'), null)
  assert.equal(parseGoLine('ok  \tpkg\t0.3s'), null)
})

test('go lines: a marker after text with no newline (GORM log lines) is still found', () => {
  assert.deepEqual(parseGoLine('INSERT INTO t VALUES (1)TRACE  ERROR: boom=== RUN   TestAfterLog'), { event: 'run', name: 'TestAfterLog' })
  assert.deepEqual(parseGoLine('[0.1ms] SELECT 1--- FAIL: TestX (0.00s)'), { event: 'fail', name: 'TestX' })
})

const JUNIT = `<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Unit" tests="5">
    <testsuite name="Tests\\Unit\\FooTest" tests="5">
      <testcase name="testPasses" file="/x/FooTest.php" line="10" class="Tests\\Unit\\FooTest" classname="Tests.Unit.FooTest" assertions="1" time="0.01"/>
      <testcase name="testFails" class="Tests\\Unit\\FooTest" classname="Tests.Unit.FooTest" assertions="1" time="0.01">
        <failure type="PHPUnit\\Framework\\ExpectationFailedException">Failed asserting that &quot;a&quot; &amp; &quot;b&quot;.
line two</failure>
      </testcase>
      <testcase name="testErrors" class="Tests\\Unit\\FooTest" classname="Tests.Unit.FooTest" assertions="0" time="0.01">
        <error type="Illuminate\\Database\\QueryException">SQLSTATE[42601]: syntax error</error>
      </testcase>
      <testcase name="testSkipped" class="Tests\\Unit\\FooTest" classname="Tests.Unit.FooTest" assertions="0" time="0.01">
        <skipped/>
      </testcase>
      <testcase name="testWarned with data set #0" class="Tests\\Unit\\FooTest" classname="Tests.Unit.FooTest" assertions="1" time="0.01">
        <warning type="PHPUnit\\Framework\\Warning">a warning</warning>
      </testcase>
    </testsuite>
  </testsuite>
</testsuites>`

test('junit: failure and error are FAIL, skipped is SKIP, a warning still passes', () => {
  const t = parseJunit(JUNIT)
  assert.deepEqual(
    t.map((x) => [x.name, x.kind]),
    [
      ['Tests\\Unit\\FooTest::testPasses', 'PASS'],
      ['Tests\\Unit\\FooTest::testFails', 'FAIL'],
      ['Tests\\Unit\\FooTest::testErrors', 'FAIL'],
      ['Tests\\Unit\\FooTest::testSkipped', 'SKIP'],
      ['Tests\\Unit\\FooTest::testWarned with data set #0', 'PASS'],
    ]
  )
  assert.match(t[1].message, /Failed asserting that "a" & "b"/)
})

test('junit: a file with no test cases gives none', () => {
  assert.deepEqual(parseJunit('<testsuites><testsuite name="x" tests="0"/></testsuites>'), [])
})

const PHPUNIT_XML = `<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    </testsuites>
    <logging>
        <junit outputFile="/tmp/laravel-junit.xml"/>
    </logging>
    <source>
        <include><directory>app</directory></include>
    </source>
    <php>
        <env name="APP_ENV" value="testing" force="true"/>
    </php>
</phpunit>`

test('chunk configuration swaps the test list and keeps the environment', () => {
  const x = chunkConfig(PHPUNIT_XML, ['tests/Unit/AFooTest.php', 'tests/Unit/BTest.php'], '/var/www/html')
  assert.match(x, /<file>\/var\/www\/html\/tests\/Unit\/AFooTest.php<\/file>/)
  assert.match(x, /<file>\/var\/www\/html\/tests\/Unit\/BTest.php<\/file>/)
  assert.doesNotMatch(x, /<directory>/)
  assert.doesNotMatch(x, /<logging>/)
  assert.doesNotMatch(x, /<source>/)
  assert.doesNotMatch(x, /noNamespaceSchemaLocation/)
  assert.match(x, /bootstrap="\/var\/www\/html\/tests\/bootstrap.php"/)
  assert.match(x, /<env name="APP_ENV" value="testing" force="true"\/>/)
})

test('chunk configuration works on the real phpunit.xml', () => {
  const real = fs.readFileSync(path.join(here, '..', '..', 'iznik-batch', 'phpunit.xml'), 'utf8')
  const x = chunkConfig(real, ['tests/Unit/AFooTest.php'], '/var/www/html')
  assert.match(x, /<file>\/var\/www\/html\/tests\/Unit\/AFooTest.php<\/file>/)
  assert.doesNotMatch(x, /<directory>tests/)
  assert.match(x, /name="DB_CONNECTION" value="mysql"\/>/)
})

test('files group by directory and split at the chunk size', () => {
  const files = ['tests/Unit/B.php', 'tests/Unit/A.php', 'tests/Feature/Chat/C.php', 'tests/Feature/Chat/D.php', 'tests/Feature/Chat/E.php']
  const chunks = groupFiles(files, 2)
  assert.deepEqual(
    chunks.map((c) => [c.label, c.files]),
    [
      ['tests/Feature/Chat#1', ['tests/Feature/Chat/C.php', 'tests/Feature/Chat/D.php']],
      ['tests/Feature/Chat#2', ['tests/Feature/Chat/E.php']],
      ['tests/Unit', ['tests/Unit/A.php', 'tests/Unit/B.php']],
    ]
  )
})

test('the command line: check passes, fails on a new failure, and update writes the list', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ratchet-'))
  const results = path.join(dir, 'r.txt')
  const list = path.join(dir, 'known.txt')
  const run = (...a) => spawnSync('node', [path.join(here, 'ratchet.mjs'), ...a, '--suite', 'go', '--results', results, '--list', list], { encoding: 'utf8' })
  fs.writeFileSync(results, 'PASS\ta\nFAIL\tb\n')
  let r = run('check')
  assert.equal(r.status, 1)
  assert.match(r.stdout, /new failure: b/)
  r = run('update')
  assert.equal(r.status, 0)
  assert.match(fs.readFileSync(list, 'utf8'), /^b$/m)
  r = run('check')
  assert.equal(r.status, 0)
  assert.match(r.stdout, /RATCHET go: ok/)
  fs.writeFileSync(results, 'PASS\ta\nPASS\tb\n')
  r = run('check')
  assert.equal(r.status, 0)
  assert.match(r.stdout, /now passes: b/)
  // A run far short of the run the list was made from did not cover the suite.
  fs.writeFileSync(list, fs.readFileSync(list, 'utf8').replace(/total-results: \d+/, 'total-results: 100'))
  r = run('check')
  assert.equal(r.status, 1)
  assert.match(r.stdout, /part of the suite did not run/)
  fs.writeFileSync(results, '')
  r = run('check')
  assert.equal(r.status, 1)
  assert.match(r.stdout, /suite did not run/)
  fs.rmSync(dir, { recursive: true })
})
