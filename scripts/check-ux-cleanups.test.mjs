import { test } from 'node:test'
import assert from 'node:assert/strict'
import { cleanupEntries, batchSwitches, goSwitches, problems } from './check-ux-cleanups.mjs'

const php = `<?php
// ['name' => 'commented', 'cleanup_pr' => 'x']
return [
    ['name' => 'a-b', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/12'],
];`

test('reads entries and skips comments', () => {
  assert.deepEqual(cleanupEntries(php), [{ name: 'a-b', pr: 'https://github.com/Freegle/Iznik/pull/12' }])
})

test('reads batch and go switch names', () => {
  assert.deepEqual(batchSwitches(" * 'name' => 'doc',\nreturn [\n  [\n 'name' => 'live',\n"), ['live'])
  assert.deepEqual(goSwitches('var ServerSwitches = map[string]ServerSwitch{\n\t"x-y": {\n\t\tName: "x-y",\n\t},\n}\n'), ['x-y'])
})

test('flags bad entries and live switches', () => {
  const bad = [
    { name: 'bad name', pr: 'https://example.com/1' },
    { name: 'live', pr: 'https://github.com/Freegle/Iznik/pull/5' },
    { name: 'live', pr: 'https://github.com/Freegle/Iznik/pull/5' },
  ]
  const found = problems(bad, 4, { 'ux_switches.php': ['live'] })
  assert.equal(found.filter((p) => p.includes('not a valid')).length, 1)
  assert.equal(found.filter((p) => p.includes('not a Freegle')).length, 1)
  assert.equal(found.filter((p) => p.includes('listed twice')).length, 1)
  assert.equal(found.filter((p) => p.includes('still in')).length, 2)
  assert.equal(found.filter((p) => p.includes('holds 4')).length, 1)
})
