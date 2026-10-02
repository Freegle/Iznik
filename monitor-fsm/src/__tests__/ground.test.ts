import { describe, it, expect, vi, afterEach } from 'vitest'
import { writeFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { main } from '../ground.js'

afterEach(() => { vi.restoreAllMocks() })

const file = (text: string) => {
  const p = join(mkdtempSync(join(tmpdir(), 'ground-')), 'body.md')
  writeFileSync(p, text)
  return p
}

describe('ground.js check-pr', () => {
  it('exits 1 for a description with no live evidence', async () => {
    vi.spyOn(console, 'log').mockImplementation(() => {})
    expect(await main(['check-pr', file('## Evidence\nA test.\n')])).toBe(1)
  })

  it('exits 0 for a grounded description', async () => {
    vi.spyOn(console, 'log').mockImplementation(() => {})
    expect(await main(['check-pr', file('## Live evidence\nSentry issue 7683112976: 12 events.\n')])).toBe(0)
  })

  it('exits 2 and prints usage for an unknown command', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    expect(await main(['nope'])).toBe(2)
  })
})
