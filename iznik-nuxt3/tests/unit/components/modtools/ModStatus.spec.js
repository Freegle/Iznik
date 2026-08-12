import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModStatus from '~/modtools/components/ModStatus.vue'

// This spec used to mount a hand-written COPY of ModStatus's template and setup()
// rather than the component itself. That is why the platform status dot could be
// broken for a month with the suite green: the copy was fixed, the component was
// not. Everything below mounts the real thing.

const mockSupportOrAdmin = ref(false)

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    supportOrAdmin: mockSupportOrAdmin,
  }),
}))

const mockStatusFetch = vi.fn()

vi.mock('#app', () => ({
  useNuxtApp: () => ({ $api: { status: { fetch: mockStatusFetch } } }),
}))

const OK_STATUS = { ret: 0, error: false, warning: false, info: {} }

describe('ModStatus', () => {
  let wrapper = null

  // The component polls on a 30s timer and fetches on mount. Fake timers keep
  // the poll from leaking between tests; mounting is always followed by
  // flushPromises so the initial fetch has settled before we assert.
  async function mountComponent() {
    wrapper = mount(ModStatus, {
      global: {
        stubs: {
          'b-modal': {
            props: ['title'],
            template:
              '<div class="modal"><div class="title">{{ title }}</div><slot /></div>',
          },
          'b-button': { template: '<button><slot /></button>' },
          NoticeMessage: {
            props: ['variant'],
            template: '<div class="notice" :class="variant"><slot /></div>',
          },
        },
      },
    })

    await flushPromises()

    return wrapper
  }

  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.clearAllMocks()
    mockSupportOrAdmin.value = false
    mockStatusFetch.mockResolvedValue(OK_STATUS)
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.useRealTimers()
  })

  describe('the dot', () => {
    it('shows the trying indicator until the first answer arrives', () => {
      // Deliberately never resolves, so we observe the pre-answer state.
      mockStatusFetch.mockReturnValue(new Promise(() => {}))
      wrapper = mount(ModStatus, {
        global: { stubs: { 'b-modal': true, NoticeMessage: true } },
      })

      expect(wrapper.find('.trying').exists()).toBe(true)
    })

    it('shows fine when the platform is healthy', async () => {
      await mountComponent()

      expect(wrapper.find('.fine').exists()).toBe(true)
    })

    it('shows error when the published status has an error', async () => {
      mockStatusFetch.mockResolvedValue({ ...OK_STATUS, error: true })
      await mountComponent()

      expect(wrapper.find('.error').exists()).toBe(true)
    })

    it('shows warning to support and admin when there is a warning', async () => {
      mockSupportOrAdmin.value = true
      mockStatusFetch.mockResolvedValue({ ...OK_STATUS, warning: true })
      await mountComponent()

      expect(wrapper.find('.warning').exists()).toBe(true)
    })

    it('does not show warning to an ordinary mod', async () => {
      // Deliberate: warnings are geek-facing detail. Errors are not gated.
      mockSupportOrAdmin.value = false
      mockStatusFetch.mockResolvedValue({ ...OK_STATUS, warning: true })
      await mountComponent()

      expect(wrapper.find('.warning').exists()).toBe(false)
      expect(wrapper.find('.fine').exists()).toBe(true)
    })
  })

  describe('freshness', () => {
    it('stamps the timestamp when the API answers ret 0', async () => {
      await mountComponent()

      expect(wrapper.vm.updated).not.toBeNull()
      expect(wrapper.vm.outOfDate).toBe(false)
      expect(wrapper.vm.headline).toBe('Fine')
    })

    it('stamps the timestamp when the API answers ret 1, because it answered', async () => {
      // The month-long bug. The API was replying promptly with ret 1 (its
      // writer was dead), but only ret 0 stamped `updated`, so outOfDate stayed
      // true forever: headline pinned to "Not sure", and a warning banner with
      // no detail under it. outOfDate must mean "cannot reach the API".
      mockStatusFetch.mockResolvedValue({ ret: 1, status: 'Nope' })
      await mountComponent()

      expect(wrapper.vm.updated).not.toBeNull()
      expect(wrapper.vm.outOfDate).toBe(false)
    })

    it('leaves the timestamp alone when the API cannot be reached', async () => {
      mockStatusFetch.mockRejectedValue(new Error('Network error'))
      await mountComponent()

      expect(wrapper.vm.updated).toBeNull()
      expect(wrapper.vm.outOfDate).toBe(true)
      expect(wrapper.vm.headline).toBe('Not sure')
    })

    it('goes out of date once an answer is more than ten minutes old', async () => {
      await mountComponent()
      expect(wrapper.vm.outOfDate).toBe(false)

      wrapper.vm.updated = Date.now() - 1000 * 601

      expect(wrapper.vm.outOfDate).toBe(true)
      expect(wrapper.vm.headline).toBe('Not sure')
    })
  })

  describe('headline', () => {
    it('says Error, not Warning, when both are set', async () => {
      // Error is the more severe of the two. The headline used to test warning
      // first, so a platform with a real error announced itself as a warning
      // while the dot next to it was red.
      mockStatusFetch.mockResolvedValue({
        ...OK_STATUS,
        error: true,
        warning: true,
      })
      await mountComponent()

      expect(wrapper.vm.headline).toBe('Error')
    })

    it('says Warning when only a warning is set', async () => {
      mockStatusFetch.mockResolvedValue({ ...OK_STATUS, warning: true })
      await mountComponent()

      expect(wrapper.vm.headline).toBe('Warning')
    })
  })

  describe('when the status cannot be obtained', () => {
    it('explains a ret 1 using the reason the API gave', async () => {
      mockStatusFetch.mockResolvedValue({
        ret: 1,
        status: 'Platform status has not been published yet',
      })
      await mountComponent()

      expect(wrapper.vm.headline).toBe('Warning')
      expect(wrapper.vm.status.info['Status feed'].warningtext).toBe(
        'Platform status has not been published yet'
      )
    })

    it('explains an unreachable API', async () => {
      mockStatusFetch.mockRejectedValue(new Error('Network error'))
      await mountComponent()

      expect(wrapper.vm.status.warning).toBe(true)
      expect(wrapper.vm.status.info['Status feed'].warningtext).toContain(
        'Cannot reach the status API'
      )
    })

    it('never leaves the modal with a problem and no explanation', async () => {
      // The original symptom: "There is a problem" over an empty info block.
      mockSupportOrAdmin.value = true
      mockStatusFetch.mockResolvedValue({ ret: 1, status: 'Nope' })
      await mountComponent()

      await wrapper.find('.clickme').trigger('click')

      expect(wrapper.text()).toContain('There is a problem')
      expect(wrapper.text()).toContain('Nope')
    })
  })

  describe('the modal', () => {
    it('opens on click and shows the headline', async () => {
      await mountComponent()

      await wrapper.find('.clickme').trigger('click')

      expect(wrapper.find('.modal').exists()).toBe(true)
      expect(wrapper.find('.title').text()).toContain('Platform Status: Fine')
    })

    it('lists each breached job with what is wrong', async () => {
      mockSupportOrAdmin.value = true
      mockStatusFetch.mockResolvedValue({
        ret: 0,
        error: true,
        warning: true,
        info: {
          'chats:process-incoming': {
            error: true,
            errortext: 'queue backing up',
            warning: false,
            warningtext: null,
          },
          'stats:generate-daily': {
            error: false,
            errortext: null,
            warning: true,
            warningtext: 'no rows for yesterday',
          },
        },
      })
      await mountComponent()

      await wrapper.find('.clickme').trigger('click')

      const text = wrapper.text()
      expect(text).toContain('chats:process-incoming')
      expect(text).toContain('queue backing up')
      expect(text).toContain('stats:generate-daily')
      expect(text).toContain('no rows for yesterday')
    })

    it('says everything is fine when nothing is wrong', async () => {
      await mountComponent()

      await wrapper.find('.clickme').trigger('click')

      expect(wrapper.text()).toContain('Everything seems fine')
    })
  })

  describe('polling', () => {
    it('re-checks on a timer', async () => {
      await mountComponent()
      expect(mockStatusFetch).toHaveBeenCalledTimes(1)

      await vi.advanceTimersByTimeAsync(30000)

      expect(mockStatusFetch).toHaveBeenCalledTimes(2)
    })

    it('stops polling once unmounted', async () => {
      await mountComponent()
      expect(mockStatusFetch).toHaveBeenCalledTimes(1)

      wrapper.unmount()
      wrapper = null

      await vi.advanceTimersByTimeAsync(60000)

      expect(mockStatusFetch).toHaveBeenCalledTimes(1)
    })
  })
})
