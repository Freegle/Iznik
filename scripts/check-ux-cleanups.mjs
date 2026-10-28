#!/usr/bin/env node
/**
 * UX cleanup notes (iznik-batch/config/ux_cleanups.php): every entry is well formed, and none names an
 * experiment whose switch is still in the code, in any of the three lists:
 * iznik-batch/config/ux_switches.php, iznik-nuxt3/ux-experiments.manifest.json and ux.ServerSwitches
 * in iznik-server-go/ux/serverarm.go. The batch tests cannot see the other two trees, so this holds the
 * cross-stack half. docs/developers/reference/ux-experiments.md, "Corpse control".
 *
 *   node scripts/check-ux-cleanups.mjs
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

export const NAME = /^[A-Za-z0-9_-]{1,64}$/
export const PR = /^https:\/\/github\.com\/Freegle\/Iznik\/pull\/[1-9][0-9]{0,6}$/

const code = (php) =>
  php
    .split('\n')
    .filter((line) => !/^\s*(\*|\/\*|\/\/)/.test(line))
    .join('\n')

export function cleanupEntries(php) {
  return [...code(php).matchAll(/\[\s*'name'\s*=>\s*'([^']*)'\s*,\s*'cleanup_pr'\s*=>\s*'([^']*)'\s*\]/g)].map(
    (m) => ({ name: m[1], pr: m[2] })
  )
}

export function batchSwitches(php) {
  return [...code(php).matchAll(/'name'\s*=>\s*'([^']*)'/g)].map((m) => m[1])
}

export function goSwitches(src) {
  const start = src.indexOf('var ServerSwitches = map[string]ServerSwitch{')
  if (start < 0) throw new Error('ServerSwitches not found in serverarm.go')
  const end = src.indexOf('\n}\n', start)
  return [...src.slice(start, end).matchAll(/^\t"([^"]+)":\s*\{/gm)].map((m) => m[1])
}

export function problems(entries, rawCount, switched) {
  const out = []
  if (entries.length !== rawCount) {
    out.push(
      `ux_cleanups.php holds ${rawCount} entries but only ${entries.length} are written as ['name' => '...', 'cleanup_pr' => '...']`
    )
  }
  const seen = new Set()
  for (const { name, pr } of entries) {
    if (!NAME.test(name)) out.push(`${name}: not a valid experiment name`)
    if (!PR.test(pr)) out.push(`${name}: cleanup_pr ${pr} is not a Freegle/Iznik pull request URL`)
    if (seen.has(name)) out.push(`${name}: listed twice`)
    seen.add(name)
    for (const [where, names] of Object.entries(switched)) {
      if (names.includes(name)) out.push(`${name}: its switch is still in ${where}`)
    }
  }
  return out
}

const here = path.dirname(fileURLToPath(import.meta.url))
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const read = (p) => fs.readFileSync(path.join(here, '..', p), 'utf8')
  const cleanups = read('iznik-batch/config/ux_cleanups.php')
  const entries = cleanupEntries(cleanups)
  const rawCount = (code(cleanups).match(/'cleanup_pr'/g) || []).length
  const manifest = JSON.parse(read('iznik-nuxt3/ux-experiments.manifest.json'))
  const found = problems(entries, rawCount, {
    'iznik-batch/config/ux_switches.php': batchSwitches(read('iznik-batch/config/ux_switches.php')),
    'iznik-nuxt3/ux-experiments.manifest.json': (manifest.experiments).map((s) => s.name),
    'iznik-server-go/ux/serverarm.go': goSwitches(read('iznik-server-go/ux/serverarm.go')),
  })
  if (found.length) {
    console.error(found.join('\n'))
    process.exit(1)
  }
  console.log(`ux_cleanups.php: ${entries.length} entries OK`)
}
