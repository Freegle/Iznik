import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { useModalHistory } from '~/composables/useModalHistory'

const mockAction = vi.fn()

vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))

// Track wrappers so we can unmount them in afterEach to avoid listener leaks.
const mountedWrappers = []

function mountWithModalHistory(modalId, onClose, enabled) {
  const Harness = defineComponent({
    setup() {
      if (enabled !== undefined) {
        useModalHistory(modalId, onClose, enabled)
      } else {
        useModalHistory(modalId, onClose)
      }
      return () => h('div')
    },
  })
  const wrapper = mount(Harness)
  mountedWrappers.push(wrapper)
  return wrapper
}

describe('useModalHistory', () => {
  let pushStateSpy
  let addEventListenerSpy
  let removeEventListenerSpy

  beforeEach(() => {
    vi.clearAllMocks()
    pushStateSpy = vi.spyOn(history, 'pushState')
    addEventListenerSpy = vi.spyOn(window, 'addEventListener')
    removeEventListenerSpy = vi.spyOn(window, 'removeEventListener')
  })

  afterEach(() => {
    // Unmount all wrappers created in this test to remove popstate listeners.
    while (mountedWrappers.length) {
      mountedWrappers.pop().unmount()
    }
    vi.restoreAllMocks()
  })

  describe('onMounted — enabled behavior', () => {
    it('pushes modal history state when enabled is the default (true)', () => {
      mountWithModalHistory('my-modal', vi.fn())

      expect(pushStateSpy).toHaveBeenCalledWith({ modal: 'my-modal' }, '')
    })

    it('registers a popstate listener when enabled (default)', () => {
      mountWithModalHistory('my-modal', vi.fn())

      const popstateCalls = addEventListenerSpy.mock.calls.filter(
        ([event]) => event === 'popstate'
      )
      expect(popstateCalls).toHaveLength(1)
    })

    it('logs modal_history_push action with the correct modal ID', () => {
      mountWithModalHistory('my-modal', vi.fn())

      expect(mockAction).toHaveBeenCalledWith('modal_history_push', {
        modal_id: 'my-modal',
      })
    })

    it('does NOT push history state when enabled = false', () => {
      mountWithModalHistory('my-modal', vi.fn(), false)

      expect(pushStateSpy).not.toHaveBeenCalled()
    })

    it('does NOT add a popstate listener when enabled = false', () => {
      mountWithModalHistory('my-modal', vi.fn(), false)

      const popstateCalls = addEventListenerSpy.mock.calls.filter(
        ([event]) => event === 'popstate'
      )
      expect(popstateCalls).toHaveLength(0)
    })

    it('does NOT log any action when enabled = false', () => {
      mountWithModalHistory('my-modal', vi.fn(), false)

      expect(mockAction).not.toHaveBeenCalled()
    })

    it('activates when enabled is a ref(true)', () => {
      mountWithModalHistory('my-modal', vi.fn(), ref(true))

      expect(pushStateSpy).toHaveBeenCalledWith({ modal: 'my-modal' }, '')
    })

    it('skips activation when enabled is a ref(false)', () => {
      mountWithModalHistory('my-modal', vi.fn(), ref(false))

      expect(pushStateSpy).not.toHaveBeenCalled()
      expect(mockAction).not.toHaveBeenCalled()
    })

    it('uses the provided modal ID in history state, not a default', () => {
      mountWithModalHistory('photo-gallery-modal', vi.fn())

      expect(pushStateSpy).toHaveBeenCalledWith(
        { modal: 'photo-gallery-modal' },
        ''
      )
    })
  })

  describe('popstate handling', () => {
    it('calls onClose when popstate state.modal differs from the registered ID', () => {
      const onClose = vi.fn()
      mountWithModalHistory('my-modal', onClose)

      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'other-modal' } })
      )

      expect(onClose).toHaveBeenCalledTimes(1)
    })

    it('calls onClose when popstate event has null state (back to pre-push entry)', () => {
      const onClose = vi.fn()
      mountWithModalHistory('my-modal', onClose)

      window.dispatchEvent(new PopStateEvent('popstate', { state: null }))

      expect(onClose).toHaveBeenCalledTimes(1)
    })

    it('does NOT call onClose when state.modal matches the registered modal ID', () => {
      const onClose = vi.fn()
      mountWithModalHistory('my-modal', onClose)

      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'my-modal' } })
      )

      expect(onClose).not.toHaveBeenCalled()
    })

    it('logs modal_history_popstate with will_close=true when different modal', () => {
      const onClose = vi.fn()
      mountWithModalHistory('my-modal', onClose)
      mockAction.mockClear()

      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'other-modal' } })
      )

      expect(mockAction).toHaveBeenCalledWith('modal_history_popstate', {
        modal_id: 'my-modal',
        is_active: true,
        event_state: JSON.stringify({ modal: 'other-modal' }),
        will_close: true,
      })
    })

    it('logs modal_history_popstate with will_close=false when state matches', () => {
      const onClose = vi.fn()
      mountWithModalHistory('my-modal', onClose)
      mockAction.mockClear()

      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'my-modal' } })
      )

      expect(mockAction).toHaveBeenCalledWith('modal_history_popstate', {
        modal_id: 'my-modal',
        is_active: true,
        event_state: JSON.stringify({ modal: 'my-modal' }),
        will_close: false,
      })
    })

    it('logs event_state as null when e.state is null', () => {
      const onClose = vi.fn()
      mountWithModalHistory('my-modal', onClose)
      mockAction.mockClear()

      window.dispatchEvent(new PopStateEvent('popstate', { state: null }))

      expect(mockAction).toHaveBeenCalledWith(
        'modal_history_popstate',
        expect.objectContaining({ event_state: null })
      )
    })

    it('reports is_active=true in the action log while the modal is mounted', () => {
      const onClose = vi.fn()
      mountWithModalHistory('my-modal', onClose)
      mockAction.mockClear()

      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'other' } })
      )

      expect(mockAction).toHaveBeenCalledWith(
        'modal_history_popstate',
        expect.objectContaining({ is_active: true })
      )
    })

    it('two mounted modals each handle popstate independently', () => {
      const onCloseA = vi.fn()
      const onCloseB = vi.fn()
      mountWithModalHistory('modal-a', onCloseA)
      mountWithModalHistory('modal-b', onCloseB)

      // state matches modal-a — modal-a will NOT close, modal-b WILL
      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'modal-a' } })
      )

      expect(onCloseA).not.toHaveBeenCalled()
      expect(onCloseB).toHaveBeenCalledTimes(1)
    })
  })

  describe('onUnmounted cleanup', () => {
    it('removes the popstate listener when the component unmounts', () => {
      const wrapper = mountWithModalHistory('my-modal', vi.fn())
      removeEventListenerSpy.mockClear()

      wrapper.unmount()

      const popstateCalls = removeEventListenerSpy.mock.calls.filter(
        ([event]) => event === 'popstate'
      )
      expect(popstateCalls).toHaveLength(1)
    })

    it('does NOT call onClose after the component has unmounted', () => {
      const onClose = vi.fn()
      const wrapper = mountWithModalHistory('my-modal', onClose)
      wrapper.unmount()

      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'other-modal' } })
      )

      expect(onClose).not.toHaveBeenCalled()
    })

    it('does NOT remove a listener when enabled=false (none was added)', () => {
      const wrapper = mountWithModalHistory('my-modal', vi.fn(), false)
      removeEventListenerSpy.mockClear()

      wrapper.unmount()

      const popstateCalls = removeEventListenerSpy.mock.calls.filter(
        ([event]) => event === 'popstate'
      )
      expect(popstateCalls).toHaveLength(0)
    })

    it('removes exactly the handler that was added — not every popstate listener', () => {
      const externalHandler = vi.fn()
      window.addEventListener('popstate', externalHandler)

      const wrapper = mountWithModalHistory('my-modal', vi.fn())
      removeEventListenerSpy.mockClear()

      wrapper.unmount()

      // Only one popstate remove call expected (for the composable's own handler)
      const popstateCalls = removeEventListenerSpy.mock.calls.filter(
        ([event]) => event === 'popstate'
      )
      expect(popstateCalls).toHaveLength(1)

      // The external handler should still receive events
      window.dispatchEvent(
        new PopStateEvent('popstate', { state: { modal: 'x' } })
      )
      expect(externalHandler).toHaveBeenCalledTimes(1)

      // Cleanup
      window.removeEventListener('popstate', externalHandler)
    })
  })
})
