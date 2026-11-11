import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import HostingAdModal from '~/components/HostingAdModal.vue'
import { hostingAdOpen, resetHostingAd } from '~/composables/useHostingAd'

const { mockModal, mockHide } = vi.hoisted(() => {
  const { ref } = require('vue')
  return { mockModal: ref(null), mockHide: vi.fn() }
})
const mockAction = vi.fn()
const mockEvent = vi.fn()

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: mockModal, hide: mockHide }),
}))
vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ hostingAdEvent: mockEvent }),
}))

function mountModal() {
  hostingAdOpen.value = true
  return mount(HostingAdModal, {
    global: {
      stubs: {
        'b-modal': {
          name: 'BModal',
          template:
            '<div class="b-modal"><div class="modal-title">{{ title }}</div><slot /><slot name="footer" /></div>',
          props: ['scrollable', 'title', 'noCloseOnBackdrop'],
          emits: ['hidden'],
        },
        'b-button': {
          template:
            '<a :class="variant" :href="href" :target="target" :rel="rel" @click="$emit(\'click\')"><slot /></a>',
          props: ['variant', 'href', 'target', 'rel'],
          emits: ['click'],
        },
      },
    },
  })
}

describe('HostingAdModal', () => {
  beforeEach(() => {
    resetHostingAd()
    mockAction.mockReset()
    mockEvent.mockReset()
    mockEvent.mockResolvedValue(undefined)
    mockHide.mockReset()
  })

  it('says what it is, names the affiliate payment and logs the show', () => {
    const wrapper = mountModal()
    expect(wrapper.find('.modal-title').text()).toBe(
      'A greener home for your website'
    )
    expect(wrapper.text()).toContain('WordPress website')
    expect(wrapper.text()).toContain('Freegle gets a small payment')
    expect(mockAction).toHaveBeenCalledWith('hosting_ad_shown')
    const link = wrapper.find('.take-a-look')
    expect(link.attributes('href')).toBe('/shortlink/krystalhosting')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toContain('sponsored')
  })

  it('reports a click, logs it and closes', async () => {
    const wrapper = mountModal()
    await wrapper.find('.take-a-look').trigger('click')
    await flushPromises()
    expect(mockEvent).toHaveBeenCalledWith('click')
    expect(mockAction).toHaveBeenCalledWith('hosting_ad_click')
    expect(mockHide).toHaveBeenCalled()

    // The hide that follows is not a dismissal.
    wrapper.findComponent({ name: 'BModal' }).vm.$emit('hidden')
    await flushPromises()
    expect(mockEvent).not.toHaveBeenCalledWith('dismiss')
    expect(hostingAdOpen.value).toBe(false)
  })

  it('reports No thanks as a dismissal and closes', async () => {
    const wrapper = mountModal()
    await wrapper.find('.no-thanks').trigger('click')
    await flushPromises()
    expect(mockEvent).toHaveBeenCalledWith('dismiss')
    expect(mockAction).toHaveBeenCalledWith('hosting_ad_dismissed', {
      how: 'not_now',
    })
    expect(mockHide).toHaveBeenCalled()

    wrapper.findComponent({ name: 'BModal' }).vm.$emit('hidden')
    await flushPromises()
    expect(mockEvent).toHaveBeenCalledTimes(1)
    expect(hostingAdOpen.value).toBe(false)
  })

  it('treats Escape or a navigation as a dismissal', async () => {
    const wrapper = mountModal()
    wrapper.findComponent({ name: 'BModal' }).vm.$emit('hidden')
    await flushPromises()
    expect(mockEvent).toHaveBeenCalledWith('dismiss')
    expect(mockAction).toHaveBeenCalledWith('hosting_ad_dismissed', {
      how: 'hidden',
    })
    expect(hostingAdOpen.value).toBe(false)
  })

  it('still opens the link and closes when the report fails', async () => {
    mockEvent.mockRejectedValue(new Error('down'))
    const wrapper = mountModal()
    await wrapper.find('.take-a-look').trigger('click')
    await flushPromises()
    expect(mockHide).toHaveBeenCalled()
  })
})
