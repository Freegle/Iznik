import { describe, it, expect, vi } from 'vitest'
import { pruneInstances } from '../prune-instances'

function makeInstances(n: number) {
  // Genuinely ascending, valid ISO timestamps (lexically sortable): index 0 is oldest,
  // index n-1 is newest. i seconds past a fixed base.
  const base = Date.parse('2026-07-01T00:00:00.000Z')
  return Array.from({ length: n }, (_, i) => ({
    id: `id-${i}`,
    updatedAt: new Date(base + i * 1000).toISOString(),
  }))
}

describe('pruneInstances', () => {
  it('deletes all but the `keep` most recent, oldest first', async () => {
    const lister = { listInstances: vi.fn().mockResolvedValue(makeInstances(25)) }
    const deleted: string[] = []
    const deleter = {
      deleteInstance: vi.fn(async (id: string) => {
        deleted.push(id)
      }),
    }

    const count = await pruneInstances(lister, deleter, 20)

    expect(count).toBe(5)
    // The 5 OLDEST (id-0..id-4) are deleted; the 20 most recent are kept.
    expect(deleted.sort()).toEqual(['id-0', 'id-1', 'id-2', 'id-3', 'id-4'])
  })

  it('deletes nothing when at or under the keep threshold', async () => {
    const lister = { listInstances: vi.fn().mockResolvedValue(makeInstances(20)) }
    const deleter = { deleteInstance: vi.fn() }
    expect(await pruneInstances(lister, deleter, 20)).toBe(0)
    expect(deleter.deleteInstance).not.toHaveBeenCalled()
  })

  it('reduces a large backlog down to exactly `keep`', async () => {
    const kept: string[] = makeInstances(1165).map((i) => i.id)
    const lister = { listInstances: vi.fn().mockResolvedValue(makeInstances(1165)) }
    const deleter = {
      deleteInstance: vi.fn(async (id: string) => {
        const idx = kept.indexOf(id)
        if (idx >= 0) kept.splice(idx, 1)
      }),
    }
    const count = await pruneInstances(lister, deleter, 20)
    expect(count).toBe(1145)
    expect(kept).toHaveLength(20)
    // The survivors are the 20 most recent.
    expect(kept).toContain('id-1164')
    expect(kept).not.toContain('id-0')
  })

  it('keeps going when a single delete fails (best-effort)', async () => {
    const lister = { listInstances: vi.fn().mockResolvedValue(makeInstances(23)) }
    const deleter = {
      deleteInstance: vi.fn(async (id: string) => {
        if (id === 'id-1') throw new Error('locked')
      }),
    }
    // 3 candidates (id-0,1,2); id-1 throws, so 2 succeed.
    const count = await pruneInstances(lister, deleter, 20)
    expect(count).toBe(2)
    expect(deleter.deleteInstance).toHaveBeenCalledTimes(3)
  })

  it('tolerates missing updatedAt (sorts them oldest, prunes them first)', async () => {
    const insts = [
      { id: 'no-date-a', updatedAt: '' },
      { id: 'recent', updatedAt: '2026-07-27T12:00:00.000Z' },
      { id: 'no-date-b', updatedAt: '' },
    ] as any
    const lister = { listInstances: vi.fn().mockResolvedValue(insts) }
    const deleted: string[] = []
    const deleter = { deleteInstance: vi.fn(async (id: string) => void deleted.push(id)) }
    await pruneInstances(lister, deleter, 1)
    // 'recent' kept; the two undated ones pruned.
    expect(deleted.sort()).toEqual(['no-date-a', 'no-date-b'])
  })
})
