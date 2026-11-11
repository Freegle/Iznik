import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownSurfaces from '~/modtools/components/ModSupportLockdownSurfaces.vue'
import { LOCKDOWN_AREAS } from '~/modtools/utils/lockdownAreas'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: one row per area
// with what is held and what still works, its status in words, and one
// button - Lift while held, Hold again while running. No toggles.
describe('ModSupportLockdownSurfaces', () => {
  function createWrapper(props = {}) {
    return mount(ModSupportLockdownSurfaces, {
      props,
      global: {
        stubs: {
          'b-badge': { template: '<span><slot /></span>' },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['disabled', 'variant', 'size'],
            emits: ['click'],
          },
        },
      },
    })
  }

  it('lists every area in lifting order', () => {
    const wrapper = createWrapper()
    const keys = wrapper
      .findAll('[data-testid^="lockdown-surface-"]')
      .map((r) => r.attributes('data-testid'))
      .filter((id) => /^lockdown-surface-[a-z]+$/.test(id))
      .map((id) => id.replace('lockdown-surface-', ''))
    expect(keys).toEqual([
      'mods',
      'chat',
      'posts',
      'chitchat',
      'events',
      'push',
      'email',
      'export',
    ])
  })

  it('says exactly what each area holds and what still works', () => {
    const wrapper = createWrapper()
    const chat = wrapper.find('[data-testid="lockdown-surface-chat"]').text()
    expect(chat).toContain('Chat between members')
    expect(chat).toContain(
      'Messages one member sends another wait. Chat with the volunteers keeps working both ways.'
    )
    const mods = wrapper.find('[data-testid="lockdown-surface-mods"]').text()
    expect(mods).toContain(
      'Replies to members who wrote to the volunteers still work.'
    )
    const posts = wrapper.find('[data-testid="lockdown-surface-posts"]').text()
    expect(posts).toContain('the member sees it as live. Nobody else sees it.')
    for (const area of LOCKDOWN_AREAS) {
      expect(
        wrapper.find(`[data-testid="lockdown-surface-${area.key}"]`).text()
      ).toContain(area.description)
    }
  })

  it('shows Held and a Lift button for a held area', () => {
    const wrapper = createWrapper({ surfaces: { chat: true } })
    expect(
      wrapper.find('[data-testid="lockdown-surface-status-chat"]').text()
    ).toBe('Held')
    expect(
      wrapper.find('[data-testid="lockdown-surface-button-chat"]').text()
    ).toBe('Lift')
  })

  it('shows Running and a Hold again button for a lifted area', () => {
    const wrapper = createWrapper({ surfaces: { chat: false } })
    expect(
      wrapper.find('[data-testid="lockdown-surface-status-chat"]').text()
    ).toBe('Running')
    expect(
      wrapper.find('[data-testid="lockdown-surface-button-chat"]').text()
    ).toBe('Hold again')
  })

  it('uses Lift for email, the same as every other area', () => {
    const wrapper = createWrapper({ surfaces: { email: true } })
    expect(
      wrapper.find('[data-testid="lockdown-surface-button-email"]').text()
    ).toBe('Lift')
  })

  it('emits set-surface false to lift, and true to hold again', async () => {
    const wrapper = createWrapper({ surfaces: { chat: true, posts: false } })
    await wrapper
      .find('[data-testid="lockdown-surface-button-chat"]')
      .trigger('click')
    await wrapper
      .find('[data-testid="lockdown-surface-button-posts"]')
      .trigger('click')
    expect(wrapper.emitted('set-surface')).toEqual([
      ['chat', false],
      ['posts', true],
    ])
  })

  it('disables the buttons while busy', () => {
    const wrapper = createWrapper({ surfaces: { chat: true }, busy: true })
    expect(
      wrapper
        .find('[data-testid="lockdown-surface-button-chat"]')
        .attributes('disabled')
    ).toBeDefined()
  })

  it('has no toggle switches', () => {
    const wrapper = createWrapper({ surfaces: { chat: true } })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
  })
})
