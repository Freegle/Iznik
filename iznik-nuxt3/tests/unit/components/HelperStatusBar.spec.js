import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HelperStatusBar from '~/components/HelperStatusBar.vue'

const mountOpts = {
  global: { stubs: { 'b-button': true, 'b-badge': true } },
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

  it('formats a last-run timestamp when present', () => {
    const w = mount(HelperStatusBar, {
      ...mountOpts,
      props: { batch: { status: 'active', lastrunat: '2026-06-20T10:00:00Z' } },
    })
    expect(w.vm.lastrun).not.toBe('')
  })
})
