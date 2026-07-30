import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HelperStatusBar from '~/components/HelperStatusBar.vue'

const mountOpts = {
  global: {
    stubs: {
      'b-button': true,
      // Render the badge slot so the spinner inside it is testable.
      'b-badge': { template: '<span><slot /></span>' },
      'b-spinner': true,
    },
  },
}

describe('HelperStatusBar', () => {
  it('shows a pause button when active and emits pause', async () => {
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: { batch: { status: 'active' } },
    })
    expect(w.vm.status).toBe('active')
    expect(w.find('[data-testid="helper-pause"]').exists()).toBe(true)
    expect(w.find('[data-testid="helper-resume"]').exists()).toBe(false)
    await w.find('[data-testid="helper-pause"]').trigger('click')
    expect(w.emitted().pause).toBeTruthy()
  })

  it('shows a resume button when paused and emits resume', async () => {
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: { batch: { status: 'paused' } },
    })
    expect(w.vm.status).toBe('paused')
    expect(w.find('[data-testid="helper-resume"]').exists()).toBe(true)
    await w.find('[data-testid="helper-resume"]').trigger('click')
    expect(w.emitted().resume).toBeTruthy()
  })

  it('treats a missing batch as not started', () => {
    const w = mount(HelperStatusBar, { ...mountOpts, props: { batch: null } })
    expect(w.vm.status).toBe('inactive')
    expect(w.vm.statusLabel).toBe('Not started')
  })

  const iso = (msAgo) => new Date(Date.now() - msAgo).toISOString()

  it('shows "Pausing…" only while a LIVE driver has not yet acknowledged', () => {
    // Driver pinged 3s ago (alive), but BEFORE we paused 1s ago → not yet confirmed.
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: {
        batch: {
          status: 'paused',
          pausedat: iso(1000),
          lastpolledat: iso(3000),
        },
      },
    })
    expect(w.vm.driverAlive).toBe(true)
    expect(w.vm.pausing).toBe(true)
    expect(w.vm.pauseConfirmed).toBe(false)
    expect(w.vm.statusLabel).toContain('Pausing')
    expect(w.find('[data-testid="pausing-spinner"]').exists()).toBe(true)
  })

  it('confirms the pause once the live heartbeat advances past it', () => {
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: {
        batch: {
          status: 'paused',
          pausedat: iso(5000),
          lastpolledat: iso(1000),
        },
      },
    })
    expect(w.vm.pausing).toBe(false)
    expect(w.vm.pauseConfirmed).toBe(true)
    expect(w.vm.statusLabel).toContain('stopped')
  })

  it('treats a pause as effective when there is no driver heartbeat at all', () => {
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: { batch: { status: 'paused', pausedat: iso(1000) } },
    })
    expect(w.vm.pauseConfirmed).toBe(true)
    expect(w.vm.pausing).toBe(false)
  })

  it('confirms the pause when the loop is NOT running (stale heartbeat)', () => {
    // Heartbeat is 10 min old → loop dead/stopped → nothing to wait for.
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: {
        batch: {
          status: 'paused',
          pausedat: iso(1000),
          lastpolledat: iso(600000),
        },
      },
    })
    expect(w.vm.driverAlive).toBe(false)
    expect(w.vm.pauseConfirmed).toBe(true)
    expect(w.vm.pausing).toBe(false)
  })

  it('formats a last-run timestamp when present', () => {
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: { batch: { status: 'active', lastrunat: '2026-06-20T10:00:00Z' } },
    })
    expect(w.vm.lastrun).not.toBe('')
  })
})
