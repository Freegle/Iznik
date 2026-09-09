import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PostCard from '~/components/chatshell/PostCard.vue'

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byId: (id) =>
      id === 1
        ? {
            id: 1,
            type: 'Offer',
            subject: 'OFFER: Grey sofa (EH3)',
            textbody: 'Three seater',
            attachments: [],
            area: { name: 'Edinburgh' },
          }
        : null,
  }),
}))

const stubs = { ProxyImage: true, 'v-icon': true }

describe('PostCard', () => {
  it('shows the cleaned title, type, area and distance, and emits reply and expand', async () => {
    const w = mount(PostCard, {
      props: { id: 1, miles: 2.4 },
      global: { stubs },
    })
    expect(w.find('.post-card-title').text()).toBe('Grey sofa')
    expect(w.find('.post-card-type').text()).toBe('Offer')
    expect(w.text()).toContain('About 2 miles away')
    expect(w.text()).toContain('Edinburgh')
    expect(w.text()).not.toContain('Three seater')
    await w.find('[data-testid="post-reply-1"]').trigger('click')
    expect(w.emitted('reply')[0]).toEqual([1])
    await w.find('.post-card-main').trigger('click')
    expect(w.emitted('expand')[0]).toEqual([1])
  })
  it('shows the body when expanded and renders nothing for an unknown post', () => {
    const w = mount(PostCard, {
      props: { id: 1, expanded: true },
      global: { stubs },
    })
    expect(w.text()).toContain('Three seater')
    const none = mount(PostCard, { props: { id: 2 }, global: { stubs } })
    expect(none.find('.post-card').exists()).toBe(false)
  })
})
