import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { monotonic } from './monotonicClock'

// A machine whose wall clock is stepped back (by time sync) loses clicks in
// component tests: Vue ignores an event stamped before its handler was
// attached. tests/unit/setup.ts installs monotonic() over Date.now so a step
// cannot do that.

describe('monotonic clock', () => {
  const realNow = Date.now

  afterEach(() => {
    Date.now = realNow
  })

  const Button = {
    emits: ['pressed'],
    template: '<button @click="$emit(\'pressed\')">Go</button>',
  }

  // A clock the test can step, starting from a fixed point.
  function steppable() {
    const clock = { t: 1_800_000_000_000 }
    return { clock, now: () => clock.t }
  }

  it('holds its last reading when the clock under it steps back', () => {
    const { clock, now } = steppable()
    const read = monotonic(now)

    expect(read()).toBe(1_800_000_000_000)
    clock.t -= 5000
    expect(read()).toBe(1_800_000_000_000)
    clock.t += 5001
    expect(read()).toBe(1_800_000_000_001)
  })

  it('a click survives the clock stepping back after the mount', async () => {
    const { clock, now } = steppable()
    Date.now = monotonic(now)

    const wrapper = mount(Button)
    clock.t -= 5000
    await wrapper.find('button').trigger('click')

    expect(wrapper.emitted('pressed')).toHaveLength(1)
  })

  it('without it, the same step loses the click', async () => {
    // This is the failure the setup guards against, kept as a test so the
    // reason for the guard stays checked against the installed Vue.
    const { clock, now } = steppable()
    Date.now = now

    const wrapper = mount(Button)
    clock.t -= 5000
    await wrapper.find('button').trigger('click')

    expect(wrapper.emitted('pressed')).toBeUndefined()
  })
})
