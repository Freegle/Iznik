import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownRelease from '~/modtools/components/ModSupportLockdownRelease.vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: once an area is
// lifted, what matters is when its backlog has caught up.
describe('ModSupportLockdownRelease', () => {
  function createWrapper(stats, surfaces = null) {
    return mount(ModSupportLockdownRelease, {
      props: { stats, surfaces },
      global: {
        stubs: {
          'b-progress': {
            template:
              '<div class="progress" :data-value="value" :data-max="max" />',
            props: ['value', 'max', 'variant'],
          },
        },
      },
    })
  }

  const text = (w, key) =>
    w.find(`[data-testid="lockdown-release-progress-${key}"]`).text()

  it('shows how far each lifted area has got', () => {
    const wrapper = createWrapper(
      {
        release: {
          chat: { held: 40, released: 400, rejected: 10, review: 0, gone: 2 },
          post: { held: 3, released: 1 },
          chitchat: { held: 0 },
        },
      },
      { chat: false, posts: false, chitchat: true, email: true }
    )
    expect(text(wrapper, 'chat')).toBe('412 of 452 gone through')
    expect(text(wrapper, 'post')).toBe('1 of 4 gone through')
    expect(
      wrapper.find('[data-testid="lockdown-release-chitchat"]').exists()
    ).toBe(false)
    expect(wrapper.find('[data-testid="lockdown-release-done"]').exists()).toBe(
      false
    )
    expect(
      wrapper.find('[data-testid="lockdown-release-email"]').exists()
    ).toBe(false)
  })

  it('says caught up once every lifted area has gone through', () => {
    const wrapper = createWrapper(
      {
        release: {
          chat: { held: 0, released: 450, rejected: 2 },
          post: { held: 5 },
        },
      },
      { chat: false, posts: true }
    )
    expect(wrapper.find('[data-testid="lockdown-release-done"]').text()).toBe(
      'Caught up: everything held has gone through the usual checks.'
    )
  })

  it('shows the send queue once email is lifted, without it counting towards caught up', () => {
    const wrapper = createWrapper(
      { release: { chat: { held: 0, released: 3 } }, queue: { email: 1500 } },
      { chat: false, email: false }
    )
    expect(
      wrapper.find('[data-testid="lockdown-release-email"]').text()
    ).toContain('Emails waiting to send: 1500')
    expect(wrapper.find('[data-testid="lockdown-release-done"]').exists()).toBe(
      true
    )
  })

  it('treats every area as lifted after a close', () => {
    const wrapper = createWrapper({
      release: { chat: { held: 2, released: 1 }, post: {}, chitchat: {} },
    })
    expect(text(wrapper, 'chat')).toBe('1 of 3 gone through')
    expect(
      wrapper.find('[data-testid="lockdown-release-post"]').exists(),
      'an area that held nothing is not listed'
    ).toBe(false)
  })
})
