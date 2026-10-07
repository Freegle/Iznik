import { describe, it, expect, beforeEach, vi } from 'vitest'

// The one place that decides "is this mod action actually held for ME right
// now" - plans/active/2026-09-27-lockdown-switch.md section 11.3: "Support/
// Admin callers are exempt from every refusal." The Go API lets them through
// even while mods is held, so the client must not hide their buttons either -
// otherwise a Support mod trying to clear a genuine backlog during an
// incident would find their own tools missing.
const mockSupportOrAdmin = { value: false }

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ supportOrAdmin: mockSupportOrAdmin }),
}))

const mockLockdownStore = {
  active: false,
  surfaces: {},
  held: vi.fn((surface) => !!(mockLockdownStore.active && mockLockdownStore.surfaces?.[surface])),
  get modsHeld() {
    return !!(this.active && this.surfaces?.mods)
  },
}

vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: () => mockLockdownStore,
}))

describe('useLockdown', () => {
  beforeEach(() => {
    mockSupportOrAdmin.value = false
    mockLockdownStore.active = false
    mockLockdownStore.surfaces = {}
  })

  it('modsHeld is false when there is no lockdown', async () => {
    const { useLockdown } = await import('~/modtools/composables/useLockdown')
    const { modsHeld } = useLockdown()

    expect(modsHeld.value).toBe(false)
  })

  it('modsHeld is true for an ordinary mod once mods is held', async () => {
    mockLockdownStore.active = true
    mockLockdownStore.surfaces = { mods: true }

    const { useLockdown } = await import('~/modtools/composables/useLockdown')
    const { modsHeld } = useLockdown()

    expect(modsHeld.value).toBe(true)
  })

  it('modsHeld stays false for Support/Admin even while mods is held', async () => {
    mockLockdownStore.active = true
    mockLockdownStore.surfaces = { mods: true }
    mockSupportOrAdmin.value = true

    const { useLockdown } = await import('~/modtools/composables/useLockdown')
    const { modsHeld } = useLockdown()

    expect(modsHeld.value).toBe(false)
  })

  it('held(surface) mirrors the store for an ordinary mod', async () => {
    mockLockdownStore.active = true
    mockLockdownStore.surfaces = { posts: true }

    const { useLockdown } = await import('~/modtools/composables/useLockdown')
    const { held } = useLockdown()

    expect(held('posts')).toBe(true)
    expect(held('chitchat')).toBe(false)
  })

  it('held(surface) is false for Support/Admin even when the store says held', async () => {
    mockLockdownStore.active = true
    mockLockdownStore.surfaces = { posts: true }
    mockSupportOrAdmin.value = true

    const { useLockdown } = await import('~/modtools/composables/useLockdown')
    const { held } = useLockdown()

    expect(held('posts')).toBe(false)
  })
})
