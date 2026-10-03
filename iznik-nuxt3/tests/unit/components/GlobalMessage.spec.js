import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import GlobalMessage from '~/components/GlobalMessage.vue'

const mockGet = vi.fn()
const mockSet = vi.fn()

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get: mockGet,
    set: mockSet,
  }),
}))

describe('GlobalMessage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGet.mockReturnValue(false)
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2025-05-01'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function createWrapper() {
    return mount(GlobalMessage, {
      global: {
        stubs: {
          PrivacyUpdate: { template: '<div class="privacy-update" />' },
          'b-card': { template: '<div class="b-card"><slot /></div>' },
          'b-button': {
            template:
              '<button :class="$attrs.class" @click="$emit(\'click\', $event)"><slot /></button>',
            props: ['variant', 'size', 'href', 'target', 'title'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders container div', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('div').exists()).toBe(true)
    })

    it('always renders PrivacyUpdate component', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.privacy-update').exists()).toBe(true)
    })
  })

  describe('relevantGroup computed', () => {
    // This banner was a time-boxed survey for one community's members, gated on group
    // membership and a cutoff date. Communities no longer exist and the campaign's end date
    // (2025-06-22) has passed, so relevantGroup is now a permanent constant rather than
    // something derived from a date or a membership - see components/GlobalMessage.vue.
    it('is always false', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.relevantGroup).toBe(false)
    })

    it('never renders the card or the "Show notice" link, since relevantGroup is always false', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-card').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Show notice')
    })
  })

  describe('hideIt method', () => {
    it('sets the misc store key to hide the banner', () => {
      const wrapper = createWrapper()
      const event = { preventDefault: vi.fn() }
      wrapper.vm.hideIt(event)
      expect(event.preventDefault).toHaveBeenCalled()
      expect(mockSet).toHaveBeenCalledWith({
        key: 'hideglobalwarning20250530',
        value: true,
      })
    })
  })

  describe('showit method', () => {
    it('clears the misc store key to show the banner again', () => {
      const wrapper = createWrapper()
      wrapper.vm.showit()
      expect(mockSet).toHaveBeenCalledWith({
        key: 'hideglobalwarning20250530',
        value: false,
      })
    })
  })

  describe('show computed', () => {
    it('is true when the misc store has no hidden flag', () => {
      mockGet.mockReturnValue(false)
      const wrapper = createWrapper()
      expect(wrapper.vm.show).toBe(true)
      expect(mockGet).toHaveBeenCalledWith('hideglobalwarning20250530')
    })

    it('is false when the misc store has the hidden flag set', () => {
      mockGet.mockReturnValue(true)
      const wrapper = createWrapper()
      expect(wrapper.vm.show).toBe(false)
    })
  })
})
