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
    imageUrls: ['/test.jpg'],
    taken: false,
    takenBy: null,
  }

  it('renders offer card with title and user', () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    expect(wrapper.text()).toContain('Sofa')
    expect(wrapper.text()).toContain('Alice')
  })

  it('shows type overlay badge on photo for offer', () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    expect(wrapper.text()).toContain('Offer')
  })

  it('shows type overlay badge for wanted', () => {
    const wanted = { ...offerPost, id: 2, type: 'Wanted' }
    const wrapper = mount(FeedCard, { props: { post: wanted } })
    expect(wrapper.text()).toContain('Wanted')
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
    expect(wrapper.find('.feed-card__type-overlay').exists()).toBe(false)
  })

  it('emits open-detail event on card click', async () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    await wrapper.find('.feed-card').trigger('click')
    expect(wrapper.emitted('open-detail')).toBeTruthy()
  })

  it('shows photo count badge for multiple images', () => {
    const multiPhoto = { ...offerPost, imageUrls: ['/a.jpg', '/b.jpg', '/c.jpg'] }
    const wrapper = mount(FeedCard, { props: { post: multiPhoto } })
    expect(wrapper.text()).toContain('3')
  })
})
