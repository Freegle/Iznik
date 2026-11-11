#!/usr/bin/env node
// Keep every shipped dependency compatible with Freegle's licence, AGPL-3.0-or-later.
//
// A dependency we combine with our code and convey has to carry a licence the AGPL can
// live with. npm, Composer and Go each record what they install in a lock file, so the
// check reads those rather than the manifests: an indirect dependency is shipped just
// the same as a direct one.
//
// THREE MODES:
//   --diff [BASE]  CI gate. Checks the packages a branch adds or upgrades, comparing each
//                  lock file at BASE (default origin/master) with HEAD. This is what holds
//                  the line, because it does not care how the lock file changed.
//   --staged       Commit hook. The same comparison, between HEAD and the index.
//   --all          Every shipped package in the tree. For an audit, or after changing
//                  the policy file.
//
// What is allowed lives in scripts/dependency-licences.json: an SPDX allowlist and a short
// list of named exceptions, each with a reason. Development-only packages (npm `dev`,
// Composer `packages-dev`) are skipped: they run on a developer's machine or in CI and
// are never combined with what we convey. Go has no such split, so every module in
// go.mod is checked, test-only ones included.
//
// npm and Composer write the licence into the lock file. Go does not, so a Go module's
// licence is read from its LICENSE file: in the local module cache when it is there,
// otherwise from the module's zip on proxy.golang.org.
//
// Exit status: 0 when every checked package is allowed, 1 when any is not, 2 on a usage
// or read error.

import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'

const ROOT = execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim()
const POLICY = JSON.parse(fs.readFileSync(path.join(ROOT, 'scripts/dependency-licences.json'), 'utf8'))
const ALLOWED = new Set(POLICY.allowed.map((l) => l.toLowerCase()))

// Old or loose spellings seen in lock files, mapped to the SPDX id they mean. A bare
// "GPL-3.0" is SPDX's deprecated id for GPL-3.0-only; a bare "GPL-2.0" is GPL-2.0-only,
// which is NOT compatible, so it is mapped to that and fails.
const ALIASES = {
  'apache 2.0': 'Apache-2.0',
  'apache2': 'Apache-2.0',
  'apache-2': 'Apache-2.0',
  'bsd': 'BSD-3-Clause',
  'bds-3-clause': 'BSD-3-Clause',
  'gpl-2.0': 'GPL-2.0-only',
  'gpl-2.0+': 'GPL-2.0-or-later',
  'gpl-3.0': 'GPL-3.0-only',
  'gpl-3.0+': 'GPL-3.0-or-later',
  'lgpl-2.1': 'LGPL-2.1-only',
  'lgpl-2.1+': 'LGPL-2.1-or-later',
  'lgpl-3.0': 'LGPL-3.0-only',
  'lgpl-3.0+': 'LGPL-3.0-or-later',
  'agpl-3.0': 'AGPL-3.0-only',
  'public domain': 'Public-Domain',
  'unlicence': 'Unlicense',
  'expat': 'MIT',
}

/** True when an SPDX expression is satisfiable from the allowlist: any OR branch whose
 *  AND terms are all allowed. Anything unparseable, such as "SEE LICENSE IN ...", is
 *  not allowed - the package needs a named exception after a person has read it. */
export function licenceAllowed(expression) {
  if (!expression) return false
  const cleaned = expression.replace(/[()]/g, ' ').replace(/\s+/g, ' ').trim()
  return cleaned.split(/ OR /i).some((branch) =>
    branch.split(/ AND /i).every((term) => {
      const t = term.trim().replace(/ WITH .*$/i, '')
      const canonical = ALIASES[t.toLowerCase()] || t
      return ALLOWED.has(canonical.toLowerCase())
    }),
  )
}

/** The exception that covers a package key such as "npm:@sentry/cli-linux-x64", or null. */
export function exceptionFor(key) {
  if (POLICY.exceptions[key]) return POLICY.exceptions[key]
  for (const [prefix, target] of Object.entries(POLICY.exception_prefixes || {})) {
    if (key.startsWith(prefix)) return POLICY.exceptions[target] || null
  }
  return null
}

// ── Reading the lock files ────────────────────────────────────────────────────

const LOCK_NAMES = new Set(['package-lock.json', 'composer.lock', 'go.mod'])

function git(args) {
  return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 512 * 1024 * 1024 })
}

/** A file's content at a revision ("" for the index), or null when it does not exist there. */
function readAt(rev, file) {
  if (rev === null) {
    const p = path.join(ROOT, file)
    return fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : null
  }
  try {
    return git(['show', `${rev}:${file}`])
  } catch {
    return null
  }
}

function lockFilesAt(rev) {
  const out = rev === null ? git(['ls-files']) : rev === '' ? git(['ls-files', '--cached']) : git(['ls-tree', '-r', '--name-only', rev])
  return out.split('\n').filter((f) => LOCK_NAMES.has(path.basename(f)) && !/(^|\/)(node_modules|vendor)\//.test(f))
}

const npmLicence = (l) => (Array.isArray(l) ? l.map(npmLicence).join(' OR ') : typeof l === 'object' && l ? l.type : l) || ''

/** Shipped packages in one lock file, as a Map of key -> { name, version, licence, file }. */
export function packagesIn(file, text) {
  const found = new Map()
  if (!text) return found
  const base = path.basename(file)

  if (base === 'package-lock.json') {
    const lock = JSON.parse(text)
    for (const [where, p] of Object.entries(lock.packages || {})) {
      if (!where || p.dev || p.devOptional || p.link) continue
      const name = p.name || where.replace(/^.*node_modules\//, '')
      found.set(`npm:${name}@${p.version}`, { eco: 'npm', name, version: p.version, licence: npmLicence(p.license), file })
    }
  } else if (base === 'composer.lock') {
    const lock = JSON.parse(text)
    for (const p of lock.packages || []) {
      // Composer lists several licences as a choice: any one of them may be taken.
      const licence = Array.isArray(p.license) ? p.license.join(' OR ') : p.license || ''
      found.set(`composer:${p.name}@${p.version}`, { eco: 'composer', name: p.name, version: p.version, licence, file })
    }
  } else if (base === 'go.mod') {
    // go.mod lists every module in the build list, direct and indirect, from go 1.17 on.
    const body = text.replace(/\/\/.*$/gm, '')
    const lines = [...body.matchAll(/^\s*require\s+(\S+)\s+(\S+)\s*$/gm)].map((m) => [m[1], m[2]])
    for (const block of body.matchAll(/^\s*require\s*\(([\s\S]*?)\)/gm)) {
      for (const m of block[1].matchAll(/^\s*(\S+)\s+(\S+)\s*$/gm)) lines.push([m[1], m[2]])
    }
    // A replace points a module at a local path (our own code, not a dependency) or at
    // another module, whose licence is the one that ships.
    const replacements = new Map()
    for (const m of body.matchAll(/^\s*(?:replace\s+)?(\S+)(?:\s+v\S+)?\s+=>\s+(\S+)(?:\s+(v\S+))?\s*$/gm)) {
      replacements.set(m[1], m[2].startsWith('.') ? null : [m[2], m[3]])
    }
    for (const [required, requiredVersion] of lines) {
      const r = replacements.get(required)
      if (r === null) continue
      const [name, version] = r || [required, requiredVersion]
      found.set(`go:${name}@${version}`, { eco: 'go', name, version, licence: null, file })
    }
  }
  return found
}

// ── Go licences ───────────────────────────────────────────────────────────────

/** Name the licence in a LICENSE file's text, or null. Only the licences Go modules
 *  commonly carry are recognised; anything else needs a person to read it. */
export function licenceFromText(t) {
  if (/GNU AFFERO GENERAL PUBLIC LICENSE/i.test(t)) return 'AGPL-3.0-only'
  if (/GNU LESSER GENERAL PUBLIC LICENSE[\s\S]{0,80}Version 3/i.test(t)) return 'LGPL-3.0-only'
  if (/GNU LESSER GENERAL PUBLIC LICENSE[\s\S]{0,80}Version 2\.1/i.test(t)) return 'LGPL-2.1-only'
  if (/GNU GENERAL PUBLIC LICENSE[\s\S]{0,80}Version 3/i.test(t)) return 'GPL-3.0-only'
  if (/GNU GENERAL PUBLIC LICENSE[\s\S]{0,80}Version 2/i.test(t)) return /any later version/i.test(t) ? 'GPL-2.0-or-later' : 'GPL-2.0-only'
  if (/Elastic License 2\.0/i.test(t)) return 'Elastic-2.0'
  if (/Business Source License/i.test(t)) return 'BUSL-1.1'
  if (/Server Side Public License/i.test(t)) return 'SSPL-1.0'
  if (/Eclipse Public License/i.test(t)) return 'EPL'
  if (/Apache License[\s\S]{0,80}Version 2\.0/i.test(t)) return 'Apache-2.0'
  if (/Mozilla Public License,? (version|v\.?) ?2\.0/i.test(t)) return 'MPL-2.0'
  if (/ISC License|Permission to use, copy, modify, and\/or distribute this software for any purpose/i.test(t)) return 'ISC'
  if (/Permission is hereby granted, free of charge/i.test(t)) return 'MIT'
  if (/Redistribution and use in source and binary forms/i.test(t)) {
    return /Neither the name|names of (its|the) contributors/i.test(t) ? 'BSD-3-Clause' : 'BSD-2-Clause'
  }
  if (/This is free and unencumbered software released into the public domain/i.test(t)) return 'Unlicense'
  return null
}

const LICENCE_FILE = /^(LICEN[SC]E|COPYING|MIT[-_]LICEN[SC]E)(\.(md|txt))?$/i

// Go escapes upper-case letters in module paths as "!" plus the lower-case letter.
const goEscape = (s) => s.replace(/[A-Z]/g, (c) => '!' + c.toLowerCase())

function goModCache() {
  try {
    return execFileSync('go', ['env', 'GOMODCACHE'], { encoding: 'utf8' }).trim()
  } catch {
    return path.join(os.homedir(), 'go/pkg/mod')
  }
}

function goLicence(name, version) {
  const dir = path.join(goModCache(), goEscape(name) + '@' + version)
  if (fs.existsSync(dir)) {
    const f = fs.readdirSync(dir).find((x) => LICENCE_FILE.test(x))
    return f ? licenceFromText(fs.readFileSync(path.join(dir, f), 'utf8')) : null
  }

  // Not cached: fetch the module zip from the proxy and read the licence file at its top.
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'golicence-'))
  try {
    const zip = path.join(tmp, 'm.zip')
    execFileSync('curl', ['-sfL', '--retry', '3', '-o', zip, `https://proxy.golang.org/${goEscape(name)}/@v/${goEscape(version)}.zip`])
    const prefix = `${name}@${version}/`
    const entries = execFileSync('unzip', ['-Z1', zip], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).split('\n')
    const entry = entries.find((e) => e.startsWith(prefix) && LICENCE_FILE.test(e.slice(prefix.length)))
    return entry ? licenceFromText(execFileSync('unzip', ['-p', zip, entry], { encoding: 'utf8' })) : null
  } catch {
    return undefined // could not look
  } finally {
    fs.rmSync(tmp, { recursive: true, force: true })
  }
}

// ── Main ──────────────────────────────────────────────────────────────────────

function collect(rev) {
  const all = new Map()
  for (const f of lockFilesAt(rev)) for (const [k, v] of packagesIn(f, readAt(rev, f))) all.set(k, v)
  return all
}

function main(argv) {
  const mode = argv[0] || '--all'
  let toCheck
  if (mode === '--all') {
    toCheck = [...collect(null).values()]
  } else if (mode === '--diff' || mode === '--staged') {
    const base = mode === '--staged' ? 'HEAD' : argv[1] || 'origin/master'
    const before = collect(base)
    const after = collect(mode === '--staged' ? '' : 'HEAD')
    toCheck = [...after].filter(([k]) => !before.has(k)).map(([, v]) => v)
  } else {
    console.error('Usage: check-dependency-licences.mjs [--all | --diff [BASE] | --staged]')
    return 2
  }

  const bad = []
  const unknown = []
  for (const p of toCheck) {
    if (p.eco === 'go') {
      p.licence = goLicence(p.name, p.version)
      if (p.licence === undefined) {
        unknown.push(p)
        continue
      }
    }
    if (licenceAllowed(p.licence)) continue
    if (exceptionFor(`${p.eco}:${p.name}`)) continue
    bad.push(p)
  }

  console.log(`dependency licences: checked ${toCheck.length} package(s)`)
  for (const p of unknown) {
    console.log(`  could not read the licence of ${p.name}@${p.version} (${p.file}); check it by hand`)
  }
  if (bad.length === 0) return 0

  console.log('\nThese dependencies are not compatible with AGPL-3.0-or-later:')
  for (const p of bad) console.log(`  ${p.eco} ${p.name}@${p.version}: ${p.licence || 'no licence found'} (${p.file})`)
  console.log(
    '\nFind an alternative, or, if a person has read the licence and it is compatible (or the package\n' +
      'is never shipped), add a named exception with a reason to scripts/dependency-licences.json.',
  )
  return 1
}

if (import.meta.url === `file://${process.argv[1]}`) process.exit(main(process.argv.slice(2)))
