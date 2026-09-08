import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ChooserSheet from '~/components/chatshell/ChooserSheet.vue'

vi.mock('~/stores/user', () => ({ useUserStore: () => ({ byId: () => null }) }))

const post = { id: 5, type: 'Offer', subject: 'OFFER: Four chairs (EH3)', arrival: '2026-09-01T10:00:00Z' }
const replies = [
  { userid: 1, name: 'Ali', displayname: 'Ali', date: '2026-09-01T10:30:00Z', chatid: 11, snippet: 'Still going?', miles: 8 },
  { userid: 2, name: 'Jane', displayname: 'Jane', date: '2026-09-01T12:00:00Z', chatid: 12, snippet: 'Could I have two please', miles: 1, wanted: 2 },
]
const stubs = { ProfileImage: true }

describe('ChooserSheet', () => {
  it('single item: tapping a row promises to that person', async () => {
    const w = mount(ChooserSheet, { props: { post, replies, available: 1 }, global: { stubs } })
    expect(w.text()).toContain('Who should have the Four chairs?')
    await w.find('[data-testid="choose-2"]').trigger('click')
    expect(w.emitted('promise')[0][0]).toEqual([{ userid: 2, count: 1 }])
  })
  it('several: steppers prefilled from what people asked for, capped at the pool, confirm emits the split', async () => {
    const w = mount(ChooserSheet, { props: { post, replies, available: 3 }, global: { stubs } })
    expect(w.find('[data-testid="sheet-pool"]').text()).toContain('3 available')
    // Jane asked for two and scores higher, so she is prefilled 2; Ali gets the remaining 1.
    expect(w.find('[data-testid="count-2"]').text()).toBe('2')
    expect(w.find('[data-testid="count-1"]').text()).toBe('1')
    const plus = w.findAll('.step').filter((b) => b.text() === '+')
    expect(plus[0].attributes('disabled')).toBeDefined()
    await w.find('[data-testid="chooser-confirm"]').trigger('click')
    const split = w.emitted('promise')[0][0]
    expect(split.reduce((n, a) => n + a.count, 0)).toBe(3)
  })
  it('chat and close emit', async () => {
    const w = mount(ChooserSheet, { props: { post, replies, available: 1 }, global: { stubs } })
    await w.find('[data-testid="person-card-2"] .chat-btn').trigger('click')
    expect(w.emitted('chat')[0][0]).toBe(12)
    await w.find('.sheet-close').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
  })
})
