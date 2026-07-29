import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { mkdtempSync, rmSync, writeFileSync, readFileSync, existsSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describeUnusableStore, ensureUsableInstanceStore } from '../instance-store.js'

/**
 * On 2026-07-19 iteration 3832 was killed mid-write and left
 * instance-store.json at zero bytes. ai-flower's JSONFileStorage only guards
 * against a MISSING file, so every later run died on JSON.parse('') before the
 * brain ran — two silent days of a monitor that looked idle rather than broken.
 */
describe('describeUnusableStore', () => {
  it('flags the empty file that an interrupted write leaves behind', () => {
    expect(describeUnusableStore('')).toMatch(/empty/)
    expect(describeUnusableStore('   \n')).toMatch(/empty/)
  })

  it('flags a half-written file', () => {
    expect(describeUnusableStore('{"instances": {')).toMatch(/invalid JSON/)
  })

  it('flags valid JSON that is not a store', () => {
    expect(describeUnusableStore('[]')).toMatch(/not an object/)
    expect(describeUnusableStore('null')).toMatch(/not an object/)
    expect(describeUnusableStore('{"workflows": {}}')).toMatch(/instances/)
    expect(describeUnusableStore('{"instances": {}}')).toMatch(/workflows/)
  })

  it('accepts a healthy store, populated or not', () => {
    expect(describeUnusableStore('{"instances": {}, "workflows": {}}')).toBeNull()
    expect(
      describeUnusableStore('{"instances": {"a": {"id": "a"}}, "workflows": {"w": {"id": "w"}}}')
    ).toBeNull()
  })
})

describe('ensureUsableInstanceStore', () => {
  let dir: string
  let file: string

  beforeEach(() => {
    dir = mkdtempSync(join(tmpdir(), 'fsm-store-'))
    file = join(dir, 'instance-store.json')
  })

  afterEach(() => rmSync(dir, { recursive: true, force: true }))

  it('leaves a missing file alone so ai-flower can start fresh', () => {
    expect(ensureUsableInstanceStore(file).repaired).toBe(false)
    expect(existsSync(file)).toBe(false)
  })

  it('leaves a healthy store untouched', () => {
    const healthy = '{"instances": {"a": {"id": "a"}}, "workflows": {}}'
    writeFileSync(file, healthy)

    expect(ensureUsableInstanceStore(file).repaired).toBe(false)
    expect(readFileSync(file, 'utf8')).toBe(healthy)
  })

  it('resets a zero-byte store and keeps the original for inspection', () => {
    writeFileSync(file, '')

    const result = ensureUsableInstanceStore(file, new Date('2026-07-21T18:29:04.000Z'))

    expect(result.repaired).toBe(true)
    expect(result.reason).toMatch(/empty/)
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ instances: {}, workflows: {} })
    expect(existsSync(result.backupPath!)).toBe(true)
    expect(readFileSync(result.backupPath!, 'utf8')).toBe('')
  })

  it('preserves the corrupt content in the backup', () => {
    writeFileSync(file, '{"instances": {')

    const result = ensureUsableInstanceStore(file)

    expect(result.repaired).toBe(true)
    expect(readFileSync(result.backupPath!, 'utf8')).toBe('{"instances": {')
  })

  it('produces a store that round-trips, so the next run starts clean', () => {
    writeFileSync(file, '')
    ensureUsableInstanceStore(file)

    expect(ensureUsableInstanceStore(file).repaired).toBe(false)
  })
})
