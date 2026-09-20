import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import PromotePage from '~/pages/promote.vue'

globalThis.useHead = () => {}

function mountPage() {
  return mount(PromotePage, {
    global: {
      stubs: {
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-img': { template: '<img :src="src" />', props: ['src', 'lazy'] },
        InviteSomeone: { template: '<div class="invite-someone" />' },
        PosterModal: {
          template: '<div class="poster-modal" />',
          emits: ['hidden'],
        },
      },
    },
  })
}

describe('pages/promote.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('defaults to showing the English posters', () => {
    const wrapper = mountPage()

    const posterLinks = wrapper.findAll('a.poster-item')
    expect(posterLinks.length).toBe(3)
    expect(posterLinks[0].attributes('href')).toBe(
      'https://freegle.in/A4Poster'
    )
  })

  it('switches to the Welsh posters when the Welsh button is clicked', async () => {
    const wrapper = mountPage()

    const buttons = wrapper.findAll('button')
    const welshButton = buttons.find((b) => b.text() === 'Welsh')
    await welshButton.trigger('click')

    const posterLinks = wrapper.findAll('a.poster-item')
    expect(posterLinks[0].attributes('href')).toBe(
      'https://freegle.in/A4WelshPoster'
    )
  })

  it('opens the poster modal when "I put up a poster!" is clicked', async () => {
    const wrapper = mountPage()

    expect(wrapper.find('.poster-modal').exists()).toBe(false)

    const buttons = wrapper.findAll('button')
    const posterButton = buttons.find((b) =>
      b.text().includes('I put up a poster!')
    )
    await posterButton.trigger('click')

    expect(wrapper.find('.poster-modal').exists()).toBe(true)
  })
})
