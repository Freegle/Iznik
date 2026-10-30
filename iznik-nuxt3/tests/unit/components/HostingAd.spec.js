import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import HostingAd from '~/components/HostingAd.vue'
import { hostingAdOpen, resetHostingAd } from '~/composables/useHostingAd'

const mockRoute = { path: '/browse', query: {} }
const mockMisc = { modtools: false }
const mockMobile = { isApp: false }
const mockFetchHostingAd = vi.fn()
const me = vi.hoisted(() => {
  const { ref } = require('vue')
  return { loggedIn: ref(true) }
})

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ loggedIn: me.loggedIn }),
}))
vi.mock('~/stores/misc', () => ({ useMiscStore: () => mockMisc }))
vi.mock('~/stores/mobile', () => ({ useMobileStore: () => mockMobile }))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetchHostingAd: mockFetchHostingAd }),
}))
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return { ...actual, useRoute: () => mockRoute }
})

describe('HostingAd', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    document.body.classList.remove('modal-open')
    resetHostingAd()
    me.loggedIn.value = true
    mockRoute.path = '/browse'
    mockMisc.modtools = false
    mockMobile.isApp = false
    mockFetchHostingAd.mockReset()
  })

  function stubs() {
    return {
      HostingAdModal: { template: '<div class="hosting-ad-modal" />' },
    }
  }

  it('asks the server on arrival and mounts the modal when it says so', async () => {
    mockFetchHostingAd.mockResolvedValue(true)
    const wrapper = mount(HostingAd, { global: { stubs: stubs() } })
    await flushPromises()
    expect(mockFetchHostingAd).toHaveBeenCalledTimes(1)
    expect(hostingAdOpen.value).toBe(true)
    expect(wrapper.find('.hosting-ad-modal').exists()).toBe(true)
  })

  it('mounts nothing when the server says no', async () => {
    mockFetchHostingAd.mockResolvedValue(false)
    const wrapper = mount(HostingAd, { global: { stubs: stubs() } })
    await flushPromises()
    expect(hostingAdOpen.value).toBe(false)
    expect(wrapper.find('.hosting-ad-modal').exists()).toBe(false)
  })

  it('asks nothing in the app, in ModTools or signed out', async () => {
    for (const set of [
      () => (mockMobile.isApp = true),
      () => (mockMisc.modtools = true),
      () => (me.loggedIn.value = false),
    ]) {
      resetHostingAd()
      mockMobile.isApp = false
      mockMisc.modtools = false
      me.loggedIn.value = true
      set()
      mount(HostingAd, { global: { stubs: stubs() } })
      await flushPromises()
      expect(mockFetchHostingAd).not.toHaveBeenCalled()
    }
  })

  it('asks once the member signs in on this page view', async () => {
    me.loggedIn.value = false
    mockFetchHostingAd.mockResolvedValue(true)
    const wrapper = mount(HostingAd, { global: { stubs: stubs() } })
    await flushPromises()
    expect(mockFetchHostingAd).not.toHaveBeenCalled()

    me.loggedIn.value = true
    await flushPromises()
    expect(mockFetchHostingAd).toHaveBeenCalledTimes(1)
    expect(wrapper.find('.hosting-ad-modal').exists()).toBe(true)
  })
})
