import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModSupportMailDeferrals from '~/modtools/components/ModSupportMailDeferrals.vue'

const mockFetchDeferrals = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    emailtracking: { fetchDeferrals: mockFetchDeferrals },
  }),
}))

// NoticeMessage is auto-imported in the app. Keep the variant: whether this
// page reads as "all clear" or "something is late" is the whole point.
const NoticeMessage = {
  props: ['variant'],
  template: '<div class="notice" :data-variant="variant"><slot /></div>',
}

const stubs = {
  NoticeMessage,
  'v-icon': true,
  'b-badge': { template: '<span class="badge"><slot /></span>' },
  'b-table-simple': { template: '<table><slot /></table>' },
  'b-thead': { template: '<thead><slot /></thead>' },
  'b-tbody': { template: '<tbody><slot /></tbody>' },
  'b-tr': { template: '<tr><slot /></tr>' },
  'b-th': { template: '<th><slot /></th>' },
  'b-td': { template: '<td><slot /></td>' },
  'nuxt-link': { template: '<a><slot /></a>' },
  ModSupportMailHeldTable: {
    name: 'ModSupportMailHeldTable',
    props: ['members', 'limit'],
    template:
      '<table class="held"><tr v-for="m in members" :key="m.userid"><td>{{ m.email }}</td><td>{{ m.skipped }}</td></tr></table>',
  },
}

async function render(response = {}) {
  mockFetchDeferrals.mockResolvedValue({
    suppressions: [],
    members: [],
    memberlimit: 1000,
    queues: [],
    ...response,
  })

  const wrapper = mount(ModSupportMailDeferrals, { global: { stubs } })
  await flushPromises()

  return wrapper
}

function words(wrapper) {
  return wrapper.text().replace(/\s+/g, ' ').trim()
}

const yahooBacklog = {
  domain: 'yahoo.com',
  waiting: 2499,
  deferred: 0,
  oldest: '2026-09-15 07:30:24',
  deliveredperhour: 1538,
  instance: '/etc/postfix-warm',
}

describe('ModSupportMailDeferrals', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockFetchDeferrals.mockReset()
  })

  // The bug this page had. Yahoo was accepting our mail perfectly - no
  // deferral, no suppression, nothing to report - while 2,499 messages sat
  // twelve hours deep behind our own rate limit. The page said everything was
  // fine, and it was right about the only thing it was looking at.
  it('does not claim all is well when mail is queued but unrefused', async () => {
    const text = words(await render({ queues: [yahooBacklog] }))

    expect(text).not.toContain('Every provider is accepting our mail')
    expect(text).toContain('yahoo.com')
    expect(text).toContain('2,499')
  })

  it('says all is well only when nothing is queued and nothing is refused', async () => {
    expect(words(await render())).toContain(
      'Every provider is accepting our mail'
    )
  })

  // Depth alone cannot be acted on: 2,499 is a normal evening at 1,538/hour
  // and a two-day outage at 60. The page has to do the division.
  it('turns depth and drain rate into a time to clear', async () => {
    expect(words(await render({ queues: [yahooBacklog] }))).toContain(
      '1.6 hours'
    )
  })

  it('says so plainly when a queue is not moving at all', async () => {
    const text = words(
      await render({ queues: [{ ...yahooBacklog, deliveredperhour: 0 }] })
    )

    expect(text).toContain('not draining')
  })

  it('reports a short queue in minutes rather than a fraction of an hour', async () => {
    const text = words(
      await render({
        queues: [{ ...yahooBacklog, waiting: 100, deliveredperhour: 1200 }],
      })
    )

    expect(text).toContain('5 min')
  })

  // A domain with everything refused has no waiting mail, so there is nothing
  // to divide - and inventing a clearance time for mail nobody will accept
  // would be worse than saying nothing.
  it('does not estimate a clearance time for mail that is only refused', async () => {
    const text = words(
      await render({
        queues: [
          { domain: 'talktalk.net', waiting: 0, deferred: 269, oldest: null },
        ],
      })
    )

    expect(text).toContain('talktalk.net')
    expect(text).not.toContain('not draining')
  })

  it('still lists providers that are refusing us', async () => {
    const text = words(
      await render({
        suppressions: [
          {
            id: 1,
            provider: 'TalkTalk',
            value: 'talktalk.net',
            deferredsince: '2026-09-10 12:44:03',
            messagecount: 269,
            reason: 'refused to talk to me: 421',
          },
        ],
      })
    )

    expect(text).toContain('talktalk.net')
    expect(text).toContain('TalkTalk')
  })

  // Mail queued behind our own rate limit has already been generated, so it is
  // not "held". Only a suppression holds mail back, and conflating the two
  // would have support hunting for members who are not there.
  it('explains that queued mail is not the same as held-back mail', async () => {
    expect(words(await render({ queues: [yahooBacklog] }))).toContain(
      "it doesn't appear here"
    )
  })

  // A member whose own inbox is full is not waiting on anything we can fix,
  // and the suppression list above deliberately leaves those reasons out. With
  // both in one table the page said "every provider is accepting our mail"
  // directly above 194 people it described as having mail held.
  it('separates members waiting on a provider from members whose mailbox is full', async () => {
    const wrapper = await render({
      members: [
        { userid: 1, email: 'full@gmail.com', skipped: 11694, permailbox: true },
        { userid: 2, email: 'waiting@talktalk.net', skipped: 4, permailbox: false },
      ],
    })

    const tables = wrapper.findAllComponents({ name: 'ModSupportMailHeldTable' })
    expect(tables).toHaveLength(2)
    expect(tables[0].props('members').map((m) => m.userid)).toEqual([2])
    expect(tables[1].props('members').map((m) => m.userid)).toEqual([1])
  })

  // The all-clear was computed from the suppression list alone, so a page with
  // no domain suppression but 194 members listed below claimed all was well.
  it('does not claim all is well while it is listing members', async () => {
    const text = words(
      await render({
        members: [
          { userid: 1, email: 'full@gmail.com', skipped: 12351, permailbox: true },
        ],
      })
    )

    expect(text).not.toContain('Every provider is accepting our mail')
  })

  it('says a full mailbox is not a problem with our mail', async () => {
    const text = words(
      await render({
        members: [
          { userid: 1, email: 'full@gmail.com', skipped: 12351, permailbox: true },
        ],
      })
    )

    expect(text).toContain("Members whose own mailbox is the problem")
    expect(text).toContain('Nothing here means anything is wrong with our mail')
  })
})
