import { describe, it, expect, vi, afterEach } from 'vitest'
import { ref, nextTick } from 'vue'
import {
  badgeTitle,
  applyBadgeToTitle,
  useReactiveTabBadge,
} from '~/composables/useTitleBadge'

describe('badgeTitle', () => {
  it('prefixes the count when there are unread items', () => {
    expect(badgeTitle('Freegle - Home', 3)).toBe('(3) Freegle - Home')
  })

  it('does not prefix when the count is zero', () => {
    expect(badgeTitle('Freegle - Home', 0)).toBe('Freegle - Home')
  })

  it('does not prefix for a negative/falsey count', () => {
    expect(badgeTitle('Freegle - Home', -1)).toBe('Freegle - Home')
  })

  it('does not double-prefix when a count is already present', () => {
    expect(badgeTitle('(2) Freegle - Home', 5)).toBe('(2) Freegle - Home')
  })

  it('returns null when there is no title', () => {
    expect(badgeTitle('', 3)).toBe(null)
    expect(badgeTitle(null, 3)).toBe(null)
    expect(badgeTitle(undefined, 3)).toBe(null)
  })

  it('combines chats + notifications (caller sums them)', () => {
    // The app passes notificationCount + chatCount; verify a typical total.
    expect(badgeTitle('Freegle', 1 + 99)).toBe('(100) Freegle')
  })
})

describe('applyBadgeToTitle (live re-apply on document.title)', () => {
  it('adds the badge to an unbadged title', () => {
    expect(applyBadgeToTitle('Freegle - Home', 3)).toBe('(3) Freegle - Home')
  })

  it('replaces an existing badge with the new count (the live-update case)', () => {
    expect(applyBadgeToTitle('(2) Freegle - Home', 5)).toBe(
      '(5) Freegle - Home'
    )
  })

  it('removes the badge when the count drops to zero', () => {
    expect(applyBadgeToTitle('(2) Freegle - Home', 0)).toBe('Freegle - Home')
  })

  it('leaves an unbadged title unchanged at zero', () => {
    expect(applyBadgeToTitle('Freegle - Home', 0)).toBe('Freegle - Home')
  })

  it('is idempotent — re-applying the same count does not stack prefixes', () => {
    const once = applyBadgeToTitle('Freegle', 4)
    expect(applyBadgeToTitle(once, 4)).toBe('(4) Freegle')
  })
})

describe('useReactiveTabBadge', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('does nothing when document is unavailable (SSR)', () => {
    vi.stubGlobal('document', undefined)

    expect(() => useReactiveTabBadge(() => 3)).not.toThrow()
  })

  it('applies the badge to document.title immediately', () => {
    document.title = 'Freegle - Home'
    const count = ref(3)

    useReactiveTabBadge(() => count.value)

    expect(document.title).toBe('(3) Freegle - Home')
  })

  it('leaves the title unbadged immediately when the count starts at zero', () => {
    document.title = 'Freegle - Home'

    useReactiveTabBadge(() => 0)

    expect(document.title).toBe('Freegle - Home')
  })

  it('re-applies the badge to document.title as the count changes', async () => {
    document.title = 'Freegle - Home'
    const count = ref(0)

    useReactiveTabBadge(() => count.value)
    expect(document.title).toBe('Freegle - Home')

    count.value = 5
    await nextTick()
    expect(document.title).toBe('(5) Freegle - Home')

    count.value = 0
    await nextTick()
    expect(document.title).toBe('Freegle - Home')
  })

  it('preserves a title changed elsewhere (e.g. by route navigation) between updates', async () => {
    document.title = 'Freegle - Home'
    const count = ref(1)

    useReactiveTabBadge(() => count.value)
    expect(document.title).toBe('(1) Freegle - Home')

    // Something else (e.g. useHead on navigation) sets a fresh title.
    document.title = 'Freegle - Post an item'

    count.value = 2
    await nextTick()
    expect(document.title).toBe('(2) Freegle - Post an item')
  })
})
