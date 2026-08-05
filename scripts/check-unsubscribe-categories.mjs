#!/usr/bin/env node
/**
 * The unsubscribe category map is implemented twice: in PHP for the mailto: arm of
 * List-Unsubscribe (iznik-batch, IncomingMailService) and in Go for the HTTPS one-click arm
 * (iznik-server-go, user.Unsubscribe). apiv2 and batch-prod run on different hosts and
 * batch-prod is outside the compose network, so neither can call the other.
 *
 * Neither test container can see the other language's tree, so this runs on the host:
 *
 *   node scripts/check-unsubscribe-categories.mjs
 *
 * Exits non-zero if the two lists, or the two sets of member-facing descriptions, differ.
 * See docs/developers/reference/unsubscribe.md.
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const phpPath = resolve(root, 'iznik-batch/app/Services/UnsubscribeService.php')
const goPath = resolve(root, 'iznik-server-go/user/unsubscribe.go')

function fail(message) {
  console.error(`unsubscribe categories: ${message}`)
  process.exit(1)
}

function readPhp() {
  const src = readFileSync(phpPath, 'utf8')

  const constants = new Map()
  for (const m of src.matchAll(/public const (TYPE_[A-Z]+) = '([a-z]+)';/g)) {
    constants.set(m[1], m[2])
  }

  const typesBlock = src.match(/public const TYPES = \[([\s\S]*?)\];/)
  if (!typesBlock) fail(`could not find TYPES in ${phpPath}`)
  const types = [...typesBlock[1].matchAll(/self::(TYPE_[A-Z]+),/g)].map((m) => {
    const value = constants.get(m[1])
    if (!value) fail(`could not resolve ${m[1]} in ${phpPath}`)
    return value
  })

  const descBlock = src.match(/public const DESCRIPTIONS = \[([\s\S]*?)\];/)
  if (!descBlock) fail(`could not find DESCRIPTIONS in ${phpPath}`)
  const descriptions = new Map()
  for (const m of descBlock[1].matchAll(/self::(TYPE_[A-Z]+) => '(.*?)',/g)) {
    descriptions.set(constants.get(m[1]), m[2].replace(/\\'/g, "'"))
  }

  return { types, descriptions }
}

function readGo() {
  const src = readFileSync(goPath, 'utf8')

  const constants = new Map()
  for (const m of src.matchAll(/(Unsub[A-Za-z]+)\s+=\s+"([a-z]+)"/g)) {
    constants.set(m[1], m[2])
  }

  const typesBlock = src.match(/var UnsubscribeTypes = \[\]string\{([\s\S]*?)\}/)
  if (!typesBlock) fail(`could not find UnsubscribeTypes in ${goPath}`)
  const types = [...typesBlock[1].matchAll(/(Unsub[A-Za-z]+),/g)].map((m) => {
    const value = constants.get(m[1])
    if (!value) fail(`could not resolve ${m[1]} in ${goPath}`)
    return value
  })

  const descBlock = src.match(/var unsubDescriptions = map\[string\]string\{([\s\S]*?)\n\}/)
  if (!descBlock) fail(`could not find unsubDescriptions in ${goPath}`)
  const descriptions = new Map()
  for (const m of descBlock[1].matchAll(/(Unsub[A-Za-z]+):\s+"(.*?)",/g)) {
    descriptions.set(constants.get(m[1]), m[2])
  }

  return { types, descriptions }
}

const php = readPhp()
const go = readGo()

if (php.types.join(',') !== go.types.join(',')) {
  console.error(`  PHP: ${php.types.join(', ')}`)
  console.error(`  Go:  ${go.types.join(', ')}`)
  fail('category lists differ between iznik-batch and iznik-server-go')
}

for (const type of php.types) {
  const a = php.descriptions.get(type)
  const b = go.descriptions.get(type)
  if (!a) fail(`no PHP description for "${type}"`)
  if (!b) fail(`no Go description for "${type}"`)
  if (a !== b) {
    console.error(`  PHP: ${a}`)
    console.error(`  Go:  ${b}`)
    fail(`descriptions differ for "${type}"`)
  }
}

console.log(`unsubscribe categories: OK (${php.types.length} categories match across PHP and Go)`)
