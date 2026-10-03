import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PostRow from '~/components/chatshell/PostRow.vue'

const posts = {
  1: {
    id: 1,
    type: 'Offer',
    subject: 'OFFER: Grey sofa (EH3)',
    textbody: 'Three seater',
    attachments: [{ paththumb: 't.jpg', path: 'p.jpg' }],
    area: { name: 'Edinburgh' },
  },
  // A full record: location is an object, not a string.
  3: {
    id: 3,
    type: 'Wanted',
    subject: 'WANTED: Bike (EH3)',
    attachments: [],
    location: { id: 9, name: 'EH3 6SS', type: '' },
  },
}
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: (id) => posts[id] || null }),
}))
const stubs = { ProxyImage: true, 'v-icon': true }

// One line per post, opening in place: the list stays a list until someone wants more.
describe('PostRow', () => {
  it('shows the cleaned title, type, distance and area on one line, and expands on tap', async () => {
    const w = mount(PostRow, {
      props: { id: 1, miles: 2.4 },
      global: { stubs },
    })
    expect(w.find('.post-row-title').text()).toBe('Grey sofa')
    expect(w.find('.post-row-type').text()).toBe('Offer')
    expect(w.text()).toContain('2 miles')
    expect(w.text()).toContain('Edinburgh')
    expect(w.text()).not.toContain('Three seater')
    expect(w.find('.post-row-detail').exists()).toBe(false)
    await w.find('.post-row-main').trigger('click')
    expect(w.emitted('expand')[0]).toEqual([1])
  })

  it('expanded, it shows the photo, the words and Reply', async () => {
    const w = mount(PostRow, {
      props: { id: 1, expanded: true, miles: 0.4 },
      global: { stubs },
    })
    expect(w.text()).toContain('Three seater')
    expect(w.text()).toContain('Under a mile')
    expect(w.find('.post-row-detail .post-row-photo').exists()).toBe(true)
    await w.find('[data-testid="post-reply-1"]').trigger('click')
    expect(w.emitted('reply')[0]).toEqual([1])
  })

  it('says I have one for a wanted post, reads the area from a location object, and renders nothing for an unknown post', () => {
    const w = mount(PostRow, {
      props: { id: 3, expanded: true },
      global: { stubs },
    })
    expect(w.find('.post-row-type').text()).toBe('Wanted')
    expect(w.find('.post-row-meta').text()).toContain('EH3 6SS')
    expect(w.find('.post-row-meta').text()).not.toContain('{')
    expect(w.find('[data-testid="post-reply-3"]').text()).toBe('I have one')
    const none = mount(PostRow, { props: { id: 2 }, global: { stubs } })
    expect(none.find('.post-row').exists()).toBe(false)
  })
})
