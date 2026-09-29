import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownRelease from '~/modtools/components/ModSupportLockdownRelease.vue'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: watching what was
// held drain, during and after a lockdown.
describe('ModSupportLockdownRelease', () => {
  function createWrapper(stats) {
    return mount(ModSupportLockdownRelease, { props: { stats } })
  }

  it('shows still held and what became of the rest, per kind', () => {
    const wrapper = createWrapper({
      release: {
        chat: { held: 40, released: 900, rejected: 12, review: 0, gone: 3 },
        post: { held: 0, released: 20, rejected: 0, review: 4, gone: 1 },
        chitchat: { held: 2, released: 5, rejected: 1, review: 0, gone: 0 },
      },
      queue: { email: 1500 },
    })
    const cells = (key) =>
      wrapper
        .find(`[data-testid="lockdown-release-${key}"]`)
        .findAll('td')
        .map((td) => td.text())
    expect(cells('chat')).toEqual([
      'Chat messages',
      '40',
      '900',
      '12',
      '0',
      '3',
    ])
    expect(cells('post')).toEqual(['Posts', '0', '20', '0', '4', '1'])
    expect(cells('chitchat')).toEqual([
      'ChitChat posts',
      '2',
      '5',
      '1',
      '0',
      '0',
    ])
    expect(
      wrapper.find('[data-testid="lockdown-release-email"]').text()
    ).toContain('Emails waiting to send: 1500')
    expect(wrapper.find('[data-testid="lockdown-release-done"]').exists()).toBe(
      false
    )
  })

  it('says so once nothing is held and the queue is empty', () => {
    const wrapper = createWrapper({
      release: {
        chat: { held: 0, released: 3 },
        post: { held: 0 },
        chitchat: { held: 0 },
      },
      queue: { email: 0 },
    })
    expect(wrapper.find('[data-testid="lockdown-release-done"]').text()).toBe(
      'Nothing is still held, and the send queue is empty.'
    )
  })

  it('is not done while email is still queued', () => {
    const wrapper = createWrapper({
      release: { chat: { held: 0 }, post: { held: 0 }, chitchat: { held: 0 } },
      queue: { email: 7 },
    })
    expect(wrapper.find('[data-testid="lockdown-release-done"]').exists()).toBe(
      false
    )
  })

  it('shows zeros before any stats have arrived', () => {
    const wrapper = createWrapper(null)
    expect(
      wrapper.find('[data-testid="lockdown-release-held-chat"]').text()
    ).toBe('0')
  })
})
