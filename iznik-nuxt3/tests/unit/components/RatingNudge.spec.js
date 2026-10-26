import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RatingNudge from '~/components/RatingNudge.vue'
import { ratingNudgeOpen, resetRatingNudge } from '~/composables/useRatingNudge'

const mockRoute = { path: '/browse', query: {} }
const mockMisc = { modtools: false }
const mockMobile = { isApp: false }
const mockFetchUnrated = vi.fn()
const me = vi.hoisted(() => ({ loggedIn: true }))
const experiment = vi.hoisted(() => ({ arm: 'b' }))

vi.mock('~/composables/useExperiment', () => ({
  useExperiment: () => experiment.arm,
  experimentVariant: () => 'rate-on-return:b',
}))
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ loggedIn: { value: me.loggedIn } }),
}))
vi.mock('~/stores/misc', () => ({ useMiscStore: () => mockMisc }))
vi.mock('~/stores/mobile', () => ({ useMobileStore: () => mockMobile }))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetchUnrated: mockFetchUnrated }),
}))
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return { ...actual, useRoute: () => mockRoute }
})

describe('RatingNudge', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    resetRatingNudge()
    experiment.arm = 'b'
    me.loggedIn = true
    mockRoute.path = '/browse'
    mockMisc.modtools = false
    mockMobile.isApp = false
    mockFetchUnrated.mockReset()
  })

  function stubs() {
    return {
      RatingNudgeModal: { template: '<div class="rating-nudge-modal" />' },
    }
  }

  it('asks the server on arrival and mounts the modal for arm b', async () => {
    mockFetchUnrated.mockResolvedValue([{ userid: 5, msgid: 6, gave: true }])
    const wrapper = mount(RatingNudge, { global: { stubs: stubs() } })
    await flushPromises()
    expect(mockFetchUnrated).toHaveBeenCalledTimes(1)
    expect(ratingNudgeOpen.value).toBe(true)
    expect(wrapper.find('.rating-nudge-modal').exists()).toBe(true)
  })

  it('mounts nothing in control', async () => {
    experiment.arm = 'control'
    mockFetchUnrated.mockResolvedValue([{ userid: 5, msgid: 6, gave: true }])
    const wrapper = mount(RatingNudge, { global: { stubs: stubs() } })
    await flushPromises()
    expect(wrapper.find('.rating-nudge-modal').exists()).toBe(false)
  })

  it('does not ask the server when signed out', async () => {
    me.loggedIn = false
    mount(RatingNudge, { global: { stubs: stubs() } })
    await flushPromises()
    expect(mockFetchUnrated).not.toHaveBeenCalled()
  })

  it('does not ask the server in the app', async () => {
    mockMobile.isApp = true
    mount(RatingNudge, { global: { stubs: stubs() } })
    await flushPromises()
    expect(mockFetchUnrated).not.toHaveBeenCalled()
  })
})
