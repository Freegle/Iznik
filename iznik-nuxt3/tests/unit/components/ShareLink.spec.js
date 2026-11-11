import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ShareLink from '~/components/ShareLink.vue'

function mountLink(props = {}) {
  return mount(ShareLink, {
    props: {
      network: 'facebook',
      url: 'https://www.ilovefreegle.org/message/1',
      title: 'OFFER: Sofa',
      ...props,
    },
    slots: { default: '<button>Share</button>' },
  })
}

describe('ShareLink', () => {
  let popup

  beforeEach(() => {
    vi.useFakeTimers()
    popup = { closed: false }
    vi.spyOn(window, 'open').mockImplementation(() => popup)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('renders its slot', () => {
    expect(mountLink().text()).toBe('Share')
  })

  it('opens a sharing window and says it opened', async () => {
    const wrapper = mountLink()

    await wrapper.trigger('click')

    expect(window.open).toHaveBeenCalledWith(
      expect.stringContaining('facebook.com/sharer'),
      'sharer',
      expect.stringContaining('width=626')
    )
    expect(wrapper.emitted('open')).toHaveLength(1)
  })

  it('says when the sharing window is closed', async () => {
    const wrapper = mountLink()
    await wrapper.trigger('click')

    vi.advanceTimersByTime(1000)
    expect(wrapper.emitted('close')).toBeUndefined()

    popup.closed = true
    vi.advanceTimersByTime(500)
    expect(wrapper.emitted('close')).toHaveLength(1)

    vi.advanceTimersByTime(2000)
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('treats a window that refuses to answer as still open', async () => {
    const wrapper = mountLink()
    popup = {
      get closed() {
        throw new DOMException('blocked', 'SecurityError')
      },
    }
    window.open.mockImplementation(() => popup)
    await wrapper.trigger('click')

    expect(() => vi.advanceTimersByTime(2000)).not.toThrow()
    expect(wrapper.emitted('close')).toBeUndefined()
  })

  it('stops watching the window when the component goes', async () => {
    const wrapper = mountLink()
    await wrapper.trigger('click')

    wrapper.unmount()
    popup.closed = true
    vi.advanceTimersByTime(2000)

    expect(wrapper.emitted('close')).toBeUndefined()
  })
})
