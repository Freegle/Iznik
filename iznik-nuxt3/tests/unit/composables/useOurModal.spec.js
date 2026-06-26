import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

// Import the REAL composable via a relative path. ~/composables/useOurModal is
// aliased to a no-op mock in vitest.config.mts, so a normal import would test
// the mock instead of the production code.
import { useOurModal } from '../../../composables/useOurModal.js'

const mockBeforeEach = vi.fn(() => vi.fn())

// The shared #imports mock (tests/unit/mocks/nuxt-app.js) re-exports real Vue
// reactivity but NOT onUnmounted, and its default useRouter has no beforeEach.
// useOurModal needs both, so fill them in while keeping the real ref/onMounted.
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  const vue = await vi.importActual('vue')
  return {
    ...actual,
    ref: vue.ref,
    onMounted: vue.onMounted,
    onUnmounted: vue.onUnmounted,
    nextTick: vue.nextTick,
    useRouter: () => ({
      beforeEach: mockBeforeEach,
      push: () => {},
      replace: () => {},
      currentRoute: { value: { path: '/' } },
    }),
  }
})

const mountedWrappers = []

// Mount a host that wires a stand-in <b-modal> (exposing show()/hide()) to
// useOurModal's `modal` template ref — exactly as a real component does. This
// is what useOurModal.show()/.hide() and the onMounted auto-show call into.
function mountWith(options) {
  const showSpy = vi.fn()
  const hideSpy = vi.fn()
  const StubModal = defineComponent({
    setup(_, { expose }) {
      expose({ show: showSpy, hide: hideSpy })
      return () => h('div')
    },
  })
  let api
  const Harness = defineComponent({
    components: { StubModal },
    setup() {
      api = options === undefined ? useOurModal() : useOurModal(options)
      return { modal: api.modal }
    },
    template: '<StubModal ref="modal" />',
  })
  const wrapper = mount(Harness)
  mountedWrappers.push(wrapper)
  return { wrapper, showSpy, hideSpy, getApi: () => api }
}

describe('useOurModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    while (mountedWrappers.length) {
      mountedWrappers.pop().unmount()
    }
  })

  describe('auto-show on mount', () => {
    it('auto-shows the modal on mount by default (v-if-gated modals rely on this)', async () => {
      const { showSpy } = mountWith()
      await flushPromises()
      expect(showSpy).toHaveBeenCalledTimes(1)
    })

    it('still auto-shows when called with an empty options object', async () => {
      const { showSpy } = mountWith({})
      await flushPromises()
      expect(showSpy).toHaveBeenCalledTimes(1)
    })

    it('does NOT auto-show on mount when autoShow:false (opens only via .show())', async () => {
      // Regression: ContactSupportModal is always-mounted but must stay hidden
      // until the user clicks "Contact us". Auto-show-on-mount made it pop up on
      // page load (/unsubscribe).
      const { showSpy } = mountWith({ autoShow: false })
      await flushPromises()
      expect(showSpy).not.toHaveBeenCalled()
    })
  })

  describe('imperative show()/hide()', () => {
    it('show() opens the modal even when autoShow is false', async () => {
      const { showSpy, getApi } = mountWith({ autoShow: false })
      await flushPromises()
      expect(showSpy).not.toHaveBeenCalled()

      getApi().show()
      expect(showSpy).toHaveBeenCalledTimes(1)
    })

    it('hide() closes the modal', async () => {
      const { hideSpy, getApi } = mountWith({ autoShow: false })
      await flushPromises()

      getApi().hide()
      expect(hideSpy).toHaveBeenCalledTimes(1)
    })
  })
})
