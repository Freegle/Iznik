import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { useTypewriter } from '~/composables/useTypewriter'

// Render a throwaway component that calls the composable so that
// Vue lifecycle hooks (onBeforeUnmount) have a proper setup context.
// Returns { api, wrapper } so tests can inspect the reactive refs and
// trigger unmount to exercise cleanup.
function mountWithTypewriter(text, options) {
  let api
  const Harness = defineComponent({
    setup() {
      api = useTypewriter(text, options)
      return () => h('div')
    },
  })
  const wrapper = mount(Harness)
  return { api, wrapper }
}

describe('useTypewriter', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('initial state', () => {
    it('exposes refs in their starting state before startAnimation', () => {
      const { api } = mountWithTypewriter('hi')
      expect(api.displayedText.value).toBe('')
      expect(api.showDots.value).toBe(false)
      expect(api.animationComplete.value).toBe(false)
    })

    it('exposes the expected control functions', () => {
      const { api } = mountWithTypewriter('hi')
      expect(typeof api.startAnimation).toBe('function')
      expect(typeof api.stopAnimation).toBe('function')
      expect(typeof api.resetAnimation).toBe('function')
    })
  })

  describe('typing progression with defaults', () => {
    it('types one character per typingSpeed tick', () => {
      const { api } = mountWithTypewriter('abc')
      api.startAnimation()

      // No characters shown until the first timer fires.
      expect(api.displayedText.value).toBe('')

      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('a')

      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('ab')

      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('abc')
    })

    it('shows dots once the whole string has been typed', () => {
      const { api } = mountWithTypewriter('hi')
      api.startAnimation()

      vi.advanceTimersByTime(80 * 2) // type 'h', 'i'
      expect(api.displayedText.value).toBe('hi')

      // The tick that finishes typing also transitions to dots (no extra delay).
      vi.advanceTimersByTime(80)
      expect(api.showDots.value).toBe(true)
    })
  })

  describe('cycling behaviour', () => {
    it('clears and retypes after each dots-display period for maxCycles cycles', () => {
      const { api } = mountWithTypewriter('ab', { maxCycles: 2 })
      api.startAnimation()

      // Cycle 1: type 'a', 'b', transition to dots.
      vi.advanceTimersByTime(80 * 3)
      expect(api.displayedText.value).toBe('ab')
      expect(api.showDots.value).toBe(true)

      // Dots-display elapses → dots off, text resets to '', schedule next type.
      vi.advanceTimersByTime(1500)
      expect(api.showDots.value).toBe(false)
      expect(api.displayedText.value).toBe('')
      expect(api.animationComplete.value).toBe(false)

      // Cycle 2 types out again.
      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('a')
      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('ab')
      // Transition-to-dots tick.
      vi.advanceTimersByTime(80)
      expect(api.showDots.value).toBe(true)

      // Final dots-display completes the animation.
      vi.advanceTimersByTime(1500)
      expect(api.animationComplete.value).toBe(true)
      expect(api.showDots.value).toBe(false)
      expect(api.displayedText.value).toBe('ab')
    })

    it('runs maxCycles=3 by default', () => {
      const text = 'x'
      const { api } = mountWithTypewriter(text)
      api.startAnimation()

      // Per cycle: 80ms (type 'x') + 80ms (dots transition) + 1500ms (dots visible).
      // The final cycle short-circuits the post-dots reset and marks complete
      // when dotsDisplayTime elapses.
      //
      // Total to finish 3 cycles:
      //   cycle1: 80 + 80 + 1500
      //   cycle2: 80 + 80 + 1500
      //   cycle3: 80 + 80 + 1500  (animationComplete fires on the final dots tick)
      vi.advanceTimersByTime((80 + 80 + 1500) * 3)
      expect(api.animationComplete.value).toBe(true)
    })
  })

  describe('finalText option', () => {
    it('displays finalText after completion when provided', () => {
      const { api } = mountWithTypewriter('Hi', {
        maxCycles: 1,
        finalText: 'Welcome!',
      })
      api.startAnimation()

      // Type + dots-transition + dots-display to finish the single cycle.
      vi.advanceTimersByTime(80 * 3 + 1500)
      expect(api.animationComplete.value).toBe(true)
      expect(api.displayedText.value).toBe('Welcome!')
    })

    it('falls back to original text when finalText is null', () => {
      const { api } = mountWithTypewriter('Hello', { maxCycles: 1 })
      api.startAnimation()
      vi.advanceTimersByTime(80 * 6 + 1500) // type 5 chars + dots tick + dots display
      expect(api.animationComplete.value).toBe(true)
      expect(api.displayedText.value).toBe('Hello')
    })

    it('treats finalText of empty string as an explicit override (not a fallback)', () => {
      const { api } = mountWithTypewriter('Hello', {
        maxCycles: 1,
        finalText: '',
      })
      api.startAnimation()
      vi.advanceTimersByTime(80 * 6 + 1500)
      expect(api.animationComplete.value).toBe(true)
      expect(api.displayedText.value).toBe('')
    })
  })

  describe('custom timing options', () => {
    it('honours a custom typingSpeed', () => {
      const { api } = mountWithTypewriter('ab', { typingSpeed: 200 })
      api.startAnimation()

      // Must not advance on the default 80ms.
      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('')

      vi.advanceTimersByTime(120) // total 200ms
      expect(api.displayedText.value).toBe('a')

      vi.advanceTimersByTime(200)
      expect(api.displayedText.value).toBe('ab')
    })

    it('honours a custom dotsDisplayTime', () => {
      const { api } = mountWithTypewriter('a', {
        maxCycles: 1,
        dotsDisplayTime: 50,
      })
      api.startAnimation()

      // Type 'a', then dots-transition tick.
      vi.advanceTimersByTime(80 * 2)
      expect(api.showDots.value).toBe(true)

      // Dots display for only 50ms, not 1500.
      vi.advanceTimersByTime(50)
      expect(api.animationComplete.value).toBe(true)
    })
  })

  describe('stopAnimation', () => {
    it('prevents further typing after being called mid-cycle', () => {
      const { api } = mountWithTypewriter('abcde')
      api.startAnimation()

      vi.advanceTimersByTime(80 * 2)
      expect(api.displayedText.value).toBe('ab')

      api.stopAnimation()

      // Further time passes — displayed text should not advance.
      vi.advanceTimersByTime(1000)
      expect(api.displayedText.value).toBe('ab')
      expect(api.animationComplete.value).toBe(false)
    })

    it('cancels the pending dots timer so the cycle never completes', () => {
      const { api } = mountWithTypewriter('a', { maxCycles: 1 })
      api.startAnimation()

      // Type 'a' + dots-transition tick → dots on.
      vi.advanceTimersByTime(80 * 2)
      expect(api.showDots.value).toBe(true)

      api.stopAnimation()

      // dotsDisplayTime elapses but the timer was cleared.
      vi.advanceTimersByTime(5000)
      expect(api.animationComplete.value).toBe(false)
      expect(api.showDots.value).toBe(true) // Was on; stop does not reset.
    })

    it('is safe to call when no animation is in flight', () => {
      const { api } = mountWithTypewriter('x')
      expect(() => api.stopAnimation()).not.toThrow()
      expect(() => api.stopAnimation()).not.toThrow()
    })
  })

  describe('resetAnimation', () => {
    it('stops the animation and resets all reactive state', () => {
      const { api } = mountWithTypewriter('abc')
      api.startAnimation()

      vi.advanceTimersByTime(80 * 4) // partial typing + dots transition
      expect(api.displayedText.value.length).toBeGreaterThan(0)

      api.resetAnimation()

      expect(api.displayedText.value).toBe('')
      expect(api.showDots.value).toBe(false)
      expect(api.animationComplete.value).toBe(false)

      // And no further work happens — reset calls stop internally.
      vi.advanceTimersByTime(5000)
      expect(api.displayedText.value).toBe('')
    })

    it('allows the animation to be restarted cleanly after reset', () => {
      const { api } = mountWithTypewriter('hi', { maxCycles: 1 })
      api.startAnimation()
      vi.advanceTimersByTime(80)
      api.resetAnimation()

      api.startAnimation()
      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('h')
    })
  })

  describe('component unmount cleanup', () => {
    it('clears timers on unmount so typing does not continue', () => {
      const { api, wrapper } = mountWithTypewriter('hello')
      api.startAnimation()
      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('h')

      wrapper.unmount()

      // After unmount, typing timers must not fire.
      vi.advanceTimersByTime(5000)
      expect(api.displayedText.value).toBe('h')
      expect(api.animationComplete.value).toBe(false)
    })
  })

  describe('edge cases', () => {
    it('handles empty text by going straight to dots then completing', () => {
      const { api } = mountWithTypewriter('', { maxCycles: 1 })
      api.startAnimation()

      // First tick: charIndex (0) is not < text.length (0) → dots on.
      vi.advanceTimersByTime(80)
      expect(api.showDots.value).toBe(true)
      expect(api.displayedText.value).toBe('')

      // Dots elapse → complete.
      vi.advanceTimersByTime(1500)
      expect(api.animationComplete.value).toBe(true)
    })

    it('resets reactive state synchronously when startAnimation is called again', () => {
      // Synchronous reset is documented behaviour: the three refs are cleared
      // the moment startAnimation() runs, even mid-cycle. (The caller is
      // expected to stopAnimation() first to guarantee a clean restart, as
      // exercised by resetAnimation → restart above.)
      const { api } = mountWithTypewriter('abc', { maxCycles: 1 })
      api.startAnimation()
      vi.advanceTimersByTime(80)
      expect(api.displayedText.value).toBe('a')

      api.startAnimation()
      expect(api.displayedText.value).toBe('')
      expect(api.showDots.value).toBe(false)
      expect(api.animationComplete.value).toBe(false)
    })
  })
})
