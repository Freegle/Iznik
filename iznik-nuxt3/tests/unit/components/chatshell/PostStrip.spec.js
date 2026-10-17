import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PostStrip from '~/components/chatshell/PostStrip.vue'

const posts = {
  1: { id: 1, type: 'Offer', attachments: [{ paththumb: 't1.jpg' }] },
  2: { id: 2, type: 'Wanted', attachments: [] },
  3: { id: 3, type: 'Offer', attachments: [] },
  4: { id: 4, type: 'Offer', attachments: [] },
}
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: (id) => posts[id] || null }),
}))
const stubs = { ProxyImage: true, 'v-icon': true }

function text(props) {
  return mount(PostStrip, { props, global: { stubs } })
    .find('[data-testid="post-strip-text"]')
    .text()
}

// The chat shows how many and a glimpse; the list lives in the sheet.
describe('PostStrip', () => {
  it('shows at most three thumbnails, the count, and Look opens the sheet', async () => {
    const w = mount(PostStrip, {
      props: { ids: [1, 2, 3, 4], count: 14 },
      global: { stubs },
    })
    expect(w.findAll('.post-strip-thumb')).toHaveLength(3)
    expect(w.find('[data-testid="post-strip-text"]').text()).toBe(
      '14 things nearby'
    )
    expect(w.find('[data-testid="see-all"]').text()).toBe('Look')
    await w.find('[data-testid="see-all"]').trigger('click')
    expect(w.emitted('look')).toHaveLength(1)
  })

  it('words the count for each kind of look', () => {
    expect(text({ ids: [1], count: 1, filter: 'offers' })).toBe(
      '1 offer nearby'
    )
    expect(text({ ids: [2], count: 2, filter: 'wanted' })).toBe(
      '2 wanted posts nearby'
    )
    expect(text({ ids: [1], count: 3, kind: 'search', term: 'bike' })).toBe(
      '3 matches for "bike"'
    )
    expect(text({ ids: [], count: 0, kind: 'search', term: 'bike' })).toBe(
      'Nothing matching "bike" just now'
    )
    expect(text({ ids: [1], count: 2, kind: 'matches' })).toBe(
      "2 offers nearby might be what you're after"
    )
    expect(text({ ids: [1, 2], count: 2, kind: 'samples' })).toBe(
      'Offered near you recently'
    )
  })

  it('with nothing found there are no thumbnails and the button offers a search', () => {
    const w = mount(PostStrip, {
      props: { ids: [], count: 0 },
      global: { stubs },
    })
    expect(w.find('.post-strip-thumbs').exists()).toBe(false)
    expect(w.find('[data-testid="post-strip-text"]').text()).toBe(
      'Nothing nearby just now'
    )
    expect(w.find('[data-testid="see-all"]').text()).toBe('Search')
  })
})
