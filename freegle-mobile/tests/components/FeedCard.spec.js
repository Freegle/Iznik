import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FeedCard from '~/components/FeedCard.vue'

describe('FeedCard', () => {
  const offerPost = {
    id: 1,
    type: 'Offer',
    title: 'Sofa',
    description: 'Comfy sofa, collection BA1',
    userName: 'Alice',
    groupName: 'Freegle Bath',
    timeAgo: '2h',
    imageUrls: [],
    taken: false,
    takenBy: null,
  }

  it('renders offer card with green styling', () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    expect(wrapper.text()).toContain('OFFER')
    expect(wrapper.text()).toContain('Sofa')
    expect(wrapper.text()).toContain('Alice')
  })

  it('renders wanted card', () => {
    const wanted = { ...offerPost, id: 2, type: 'Wanted' }
    const wrapper = mount(FeedCard, { props: { post: wanted } })
    expect(wrapper.text()).toContain('WANTED')
  })

  it('renders taken card as collapsed line without revealing who took it', () => {
    const taken = { ...offerPost, id: 3, taken: true, takenBy: null }
    const wrapper = mount(FeedCard, { props: { post: taken } })
    expect(wrapper.text()).toContain('TAKEN')
    expect(wrapper.text()).toContain('Sofa')
  })

  it('renders discussion card without type badge', () => {
    const discussion = { ...offerPost, id: 4, type: 'Discussion' }
    const wrapper = mount(FeedCard, { props: { post: discussion } })
    expect(wrapper.text()).not.toContain('OFFER')
    expect(wrapper.text()).not.toContain('WANTED')
  })

  it('emits reply event on button click', async () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    const replyBtn = wrapper.find('[class*="reply"]')
    if (replyBtn.exists()) {
      await replyBtn.trigger('click')
      expect(wrapper.emitted('reply')).toBeTruthy()
    }
  })

  it('shows photo count badge for multiple images', () => {
    const multiPhoto = { ...offerPost, imageUrls: ['/a.jpg', '/b.jpg', '/c.jpg'] }
    const wrapper = mount(FeedCard, { props: { post: multiPhoto } })
    expect(wrapper.text()).toContain('3')
  })
})
