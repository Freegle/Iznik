import { describe, it, expect } from 'vitest'
import { ref, nextTick } from 'vue'
import {
  groupHeaderStartsCollapsed,
  useGroupHeaderCollapsed,
  GROUP_HEADER_FULL_DAYS,
} from '~/composables/groupHeaderCollapse'

// A feed filtered to one community shows that community's full header. After the first
// week of membership it starts collapsed to a small bar instead.
describe('groupHeaderStartsCollapsed', () => {
  const now = new Date('2026-09-07T09:00:00Z')
  const daysAgo = (days) =>
    new Date(now.getTime() - days * 24 * 60 * 60 * 1000).toISOString()

  it('shows the full header to someone who is not a member', () => {
    expect(groupHeaderStartsCollapsed(null, now)).toBe(false)
    expect(groupHeaderStartsCollapsed(undefined, now)).toBe(false)
  })

  it('shows the full header during the first week after joining', () => {
    expect(groupHeaderStartsCollapsed({ added: daysAgo(0) }, now)).toBe(false)
    expect(groupHeaderStartsCollapsed({ added: daysAgo(3) }, now)).toBe(false)
    expect(groupHeaderStartsCollapsed({ added: daysAgo(6.9) }, now)).toBe(false)
  })

  it('collapses once the first week is over', () => {
    expect(
      groupHeaderStartsCollapsed(
        { added: daysAgo(GROUP_HEADER_FULL_DAYS) },
        now
      )
    ).toBe(true)
    expect(groupHeaderStartsCollapsed({ added: daysAgo(8) }, now)).toBe(true)
    expect(
      groupHeaderStartsCollapsed({ added: '2010-11-02T15:55:30Z' }, now)
    ).toBe(true)
  })

  it('accepts the MySQL-style date string the API sends', () => {
    expect(
      groupHeaderStartsCollapsed({ added: '2026-09-05 08:00:00' }, now)
    ).toBe(false)
    expect(
      groupHeaderStartsCollapsed({ added: '2026-08-01 08:00:00' }, now)
    ).toBe(true)
  })

  it('treats a membership with no join date as long-standing', () => {
    expect(groupHeaderStartsCollapsed({ added: null }, now)).toBe(true)
    expect(groupHeaderStartsCollapsed({}, now)).toBe(true)
    expect(groupHeaderStartsCollapsed({ added: 'not a date' }, now)).toBe(true)
  })

  it('defaults the clock to now', () => {
    expect(groupHeaderStartsCollapsed({ added: '2010-11-02T15:55:30Z' })).toBe(
      true
    )
    expect(
      groupHeaderStartsCollapsed({ added: new Date().toISOString() })
    ).toBe(false)
  })
})

describe('useGroupHeaderCollapsed', () => {
  const now = new Date('2026-09-07T09:00:00Z')
  const clock = () => now
  const daysAgo = (days) =>
    new Date(now.getTime() - days * 24 * 60 * 60 * 1000).toISOString()

  it('starts collapsed for a member of more than a week, open for a newer one', () => {
    const group = ref({ id: 21244 })
    const memberships = ref([{ id: 21244, added: daysAgo(30) }])
    expect(useGroupHeaderCollapsed(group, memberships, clock).value).toBe(true)

    const fresh = ref([{ id: 21244, added: daysAgo(2) }])
    expect(useGroupHeaderCollapsed(group, fresh, clock).value).toBe(false)
  })

  it('stays open for someone who has not joined', () => {
    const group = ref({ id: 21244 })
    const memberships = ref([{ id: 999, added: daysAgo(400) }])
    expect(useGroupHeaderCollapsed(group, memberships, clock).value).toBe(false)
  })

  it('accepts raw auth store rows keyed by groupid', () => {
    const group = ref({ id: '21244' })
    const memberships = ref([{ groupid: 21244, added: daysAgo(30) }])
    expect(useGroupHeaderCollapsed(group, memberships, clock).value).toBe(true)
  })

  it('recomputes when the feed moves to another community', async () => {
    const group = ref({ id: 1 })
    const memberships = ref([
      { id: 1, added: daysAgo(30) },
      { id: 2, added: daysAgo(1) },
    ])
    const collapsed = useGroupHeaderCollapsed(group, memberships, clock)
    expect(collapsed.value).toBe(true)

    collapsed.value = false // "Show details"
    group.value = { id: 2 }
    await nextTick()
    expect(collapsed.value).toBe(false) // new to this one: full header

    group.value = { id: 1 }
    await nextTick()
    expect(collapsed.value).toBe(true) // back to the old one: folded again
  })

  it('does not fold the header back up when the memberships array is merely refreshed', async () => {
    const group = ref({ id: 1 })
    const memberships = ref([{ id: 1, added: daysAgo(30) }])
    const collapsed = useGroupHeaderCollapsed(group, memberships, clock)
    expect(collapsed.value).toBe(true)

    collapsed.value = false
    memberships.value = [{ id: 1, added: daysAgo(30) }] // same data, new array
    await nextTick()
    expect(collapsed.value).toBe(false)
  })

  it('folds up once the join date arrives for a long-standing membership', async () => {
    const group = ref({ id: 1 })
    const memberships = ref([])
    const collapsed = useGroupHeaderCollapsed(group, memberships, clock)
    expect(collapsed.value).toBe(false)

    memberships.value = [{ id: 1, added: daysAgo(30) }]
    await nextTick()
    expect(collapsed.value).toBe(true)
  })
})
