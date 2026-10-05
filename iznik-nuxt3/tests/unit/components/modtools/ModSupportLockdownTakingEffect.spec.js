import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownTakingEffect from '~/modtools/components/ModSupportLockdownTakingEffect.vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.6: the presser's
// "Taking effect" list. API is shown as informational (it applies within a
// few seconds, no ack needed); each batch loop shows a tick once its
// lockdown_acks row says it caught up, otherwise a spinner, or - once two
// minutes have passed since the change with no ack - an amber warning.
// Leakage (stats.leaked) is shown alongside, highlighted if non-zero.
describe('ModSupportLockdownTakingEffect', () => {
  function createWrapper(props = {}) {
    return mount(ModSupportLockdownTakingEffect, {
      props,
      global: {
        stubs: {
          'v-icon': { template: '<i />' },
          'b-spinner': { template: '<span class="spinner" />' },
        },
      },
    })
  }

  it('shows the visible "Taking effect" heading', () => {
    const wrapper = createWrapper({
      stats: { api: { delayseconds: 5 }, acks: [], leaked: {} },
    })
    expect(wrapper.find('h5').text()).toBe('Taking effect')
  })

  it('renders the API line using stats.api.delayseconds', () => {
    const wrapper = createWrapper({
      stats: { api: { delayseconds: 5 }, acks: [], leaked: {} },
    })
    expect(
      wrapper.find('[data-testid="lockdown-taking-effect"]').text()
    ).toContain('API: within 5 seconds')
  })

  it('defaults the API delay to 5 seconds when stats is missing', () => {
    const wrapper = createWrapper({ stats: null })
    expect(wrapper.text()).toContain('API: within 5 seconds')
  })

  it('shows a tick and the seconds taken for a caught-up loop', () => {
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        changedat: new Date().toISOString(),
        acks: [
          {
            loop: 'chat-process',
            lockdownrowid: 42,
            seenat: new Date().toISOString(),
            seconds: 3,
            caughtup: true,
          },
        ],
        leaked: {},
      },
    })
    const row = wrapper.find(
      '[data-testid="lockdown-taking-effect-chat-process"]'
    )
    expect(row.text()).toContain('took 3 seconds')
  })

  it('shrinks to one line once every loop has caught up', () => {
    const keys = [
      'chat-process',
      'content-check',
      'auto-approve',
      'mail-spool',
      'mail-loops',
      'background-tasks',
      'push',
      'tick',
    ]
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        acks: keys.map((loop) => ({ loop, caughtup: true, seconds: 2 })),
        leaked: { chat: 0 },
      },
    })
    expect(
      wrapper.find('[data-testid="lockdown-taking-effect-done"]').text()
    ).toBe('Every part of Freegle has picked up the latest change.')
    expect(wrapper.find('h5').exists()).toBe(false)
    expect(
      wrapper
        .find('[data-testid="lockdown-taking-effect-chat-process"]')
        .exists()
    ).toBe(false)
    expect(wrapper.find('[data-testid="lockdown-leaked"]').exists()).toBe(true)
  })

  it('shows a spinner and "waiting" for a loop with no ack yet', () => {
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        changedat: new Date().toISOString(),
        acks: [],
        leaked: {},
      },
    })
    const row = wrapper.find('[data-testid="lockdown-taking-effect-push"]')
    expect(row.text()).toContain('waiting')
    expect(row.find('.spinner').exists()).toBe(true)
  })

  it('turns a still-waiting loop amber after two minutes', () => {
    const changedat = new Date(Date.now() - 121000).toISOString()
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        changedat,
        acks: [],
        leaked: {},
      },
    })
    const row = wrapper.find('[data-testid="lockdown-taking-effect-tick"]')
    expect(row.text()).toContain(
      'Has not picked this up. The loop may be stopped or stuck. Check the batch host.'
    )
    expect(row.classes()).toContain('text-warning')
  })

  it('does not warn a loop still within two minutes of the change', () => {
    const changedat = new Date(Date.now() - 30000).toISOString()
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        changedat,
        acks: [],
        leaked: {},
      },
    })
    const row = wrapper.find('[data-testid="lockdown-taking-effect-tick"]')
    expect(row.text()).toContain('waiting')
    expect(row.text()).not.toContain('Has not picked this up')
  })

  it('lists all eight batch loops', () => {
    const wrapper = createWrapper({
      stats: { api: { delayseconds: 5 }, acks: [], leaked: {} },
    })
    for (const key of [
      'chat-process',
      'content-check',
      'auto-approve',
      'mail-spool',
      'mail-loops',
      'background-tasks',
      'push',
      'tick',
    ]) {
      expect(
        wrapper.find(`[data-testid="lockdown-taking-effect-${key}"]`).exists()
      ).toBe(true)
    }
  })

  it('shows a zero leaked count with no highlight', () => {
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        acks: [],
        leaked: { mail: 0, push: 0 },
      },
    })
    const leaked = wrapper.find('[data-testid="lockdown-leaked"]')
    expect(leaked.text()).toContain('Sent since the press: 0')
    expect(leaked.classes()).not.toContain('text-danger')
  })

  // plans/active/2026-09-27-lockdown-switch.md section 11.7: leaked relay
  // sends for email come per type as leaked:email:<type> - a compound key,
  // same as any other leaked entry - so the total just sums every value
  // regardless of how its key is composed.
  it('sums leaked totals across compound keys such as email:<type>', () => {
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        acks: [],
        leaked: { 'email:sign-in': 2, 'email:digest': 1, push: 0 },
      },
    })
    const leaked = wrapper.find('[data-testid="lockdown-leaked"]')
    expect(leaked.text()).toContain('Sent since the press: 3')
    expect(leaked.classes()).toContain('text-danger')
  })

  it('highlights a non-zero leaked count', () => {
    const wrapper = createWrapper({
      stats: {
        api: { delayseconds: 5 },
        acks: [],
        leaked: { mail: 4, push: 1 },
      },
    })
    const leaked = wrapper.find('[data-testid="lockdown-leaked"]')
    expect(leaked.text()).toContain('Sent since the press: 5')
    expect(leaked.classes()).toContain('text-danger')
  })

  it('handles missing stats gracefully', () => {
    const wrapper = createWrapper({ stats: null })
    expect(
      wrapper.find('[data-testid="lockdown-taking-effect"]').exists()
    ).toBe(true)
    expect(wrapper.find('[data-testid="lockdown-leaked"]').text()).toContain(
      'Sent since the press: 0'
    )
  })
})
