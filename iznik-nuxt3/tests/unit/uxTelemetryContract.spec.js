/**
 * Contract check for docs/developers/reference/ux-telemetry.md section 2: flow events are emitted
 * only through composables/useFlowTelemetry.js, which owns the names and adds the envelope. Any
 * other file that sends event_type 'flow', or calls action() with a flow event name, produces
 * events the rollup would count but the composable's rules (one terminal event per instance, no
 * member-typed values) do not cover.
 */
import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { FlowEvent } from '~/composables/useFlowTelemetry'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const OWNER = path.join('composables', 'useFlowTelemetry.js')
const SKIP_DIRS = new Set([
  'node_modules',
  '.nuxt',
  '.output',
  '.git',
  'dist',
  'coverage',
  'tests',
  'ios',
  'android',
  'public',
  'docs',
])
const EXTENSIONS = new Set(['.js', '.mjs', '.ts', '.vue'])

const eventNames = Object.values(FlowEvent).join('|')
// event_type: 'flow'  /  event_type: "flow"  /  'event_type': `flow`
const EVENT_TYPE_FLOW = /event_type['"`]?\s*[:=]\s*['"`]flow['"`]/
// action('step_enter', ...)  logAction("flow_start" ...)  clientLog.action(`help_sought`
const ACTION_WITH_FLOW_EVENT = new RegExp(
  `\\b(?:action|logAction)\\(\\s*['"\`](?:${eventNames})['"\`]`
)

export function violations(source) {
  const found = []
  if (EVENT_TYPE_FLOW.test(source)) found.push("event_type 'flow'")
  const m = source.match(ACTION_WITH_FLOW_EVENT)
  if (m) found.push(`action() with a flow event name: ${m[0]}`)
  return found
}

function* walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) yield* walk(path.join(dir, entry.name))
    } else if (EXTENSIONS.has(path.extname(entry.name))) {
      yield path.join(dir, entry.name)
    }
  }
}

describe('UX telemetry contract', () => {
  describe('the scanner', () => {
    it('catches event_type flow in its spellings', () => {
      expect(violations("action('x', { event_type: 'flow' })")).toHaveLength(1)
      expect(violations('log({ event_type: "flow" })')).toHaveLength(1)
      expect(violations('log({ "event_type": `flow` })')).toHaveLength(1)
    })

    it('catches action() called with every flow event name', () => {
      for (const name of Object.values(FlowEvent)) {
        expect(violations(`action('${name}', {})`)).toHaveLength(1)
        expect(violations(`logAction("${name}")`)).toHaveLength(1)
      }
    })

    it('leaves ordinary events alone', () => {
      expect(
        violations("action('impressions', { event_type: 'impressions' })")
      ).toEqual([])
      expect(violations("action('help_option_click', {})")).toEqual([])
      expect(violations("const t = 'flowchart'")).toEqual([])
    })
  })

  it('finds source files to scan', () => {
    const files = [...walk(root)]
    expect(files.length).toBeGreaterThan(200)
    expect(files.some((f) => f.endsWith(OWNER))).toBe(true)
    expect(files.some((f) => f.includes('node_modules'))).toBe(false)
  })

  it('only useFlowTelemetry.js emits flow events', () => {
    const offenders = []

    for (const file of walk(root)) {
      if (file.endsWith(OWNER)) continue
      const found = violations(fs.readFileSync(file, 'utf8'))
      if (found.length) offenders.push(`${path.relative(root, file)}: ${found}`)
    }

    expect(offenders).toEqual([])
  })
})
