import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportLockdownSurfaces from '~/modtools/components/ModSupportLockdownSurfaces.vue'

// plans/active/2026-09-27-lockdown-switch.md section 10.9: lifting order is
// mods, chat (hard/soft), posts, chitchat, events, push, email, export -
// people before content, content before mail, downloads last.
describe('ModSupportLockdownSurfaces', () => {
  function createWrapper(props = {}) {
    return mount(ModSupportLockdownSurfaces, {
      props: {
        surfaces: {
          mods: true,
          chat: true,
          chat_mode: 'hard',
          posts: true,
          chitchat: true,
          events: true,
          push: true,
          email: true,
          export: true,
        },
        heldCounts: {
          chat: { count: 42 },
          post: { count: 7 },
          chitchat: { count: 1 },
        },
        ...props,
      },
      global: {
        stubs: {
          'b-form-checkbox': {
            template:
              '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
            props: ['modelValue', 'switch'],
          },
          'b-form-select': {
            template:
              '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.text }}</option></select>',
            props: ['modelValue', 'options'],
          },
          'b-badge': { template: '<span><slot /></span>' },
        },
      },
    })
  }

  it('renders one row per surface in lift order', () => {
    const wrapper = createWrapper()
    const order = [
      'mods',
      'chat',
      'posts',
      'chitchat',
      'events',
      'push',
      'email',
      'export',
    ]
    const rendered = order.map((k) =>
      wrapper.find(`[data-testid="lockdown-surface-${k}"]`).exists()
    )
    expect(rendered.every(Boolean)).toBe(true)

    // And in that order in the DOM.
    const html = wrapper.html()
    const positions = order.map((k) => html.indexOf(`lockdown-surface-${k}"`))
    for (let i = 1; i < positions.length; i++) {
      expect(positions[i]).toBeGreaterThan(positions[i - 1])
    }
  })

  it('shows held counts beside chat, posts and chitchat', () => {
    const wrapper = createWrapper()
    expect(
      wrapper.find('[data-testid="lockdown-surface-chat"]').text()
    ).toContain('42')
    expect(
      wrapper.find('[data-testid="lockdown-surface-posts"]').text()
    ).toContain('7')
    expect(
      wrapper.find('[data-testid="lockdown-surface-chitchat"]').text()
    ).toContain('1')
  })

  it('shows no held count for surfaces with no triage kind', () => {
    const wrapper = createWrapper()
    expect(
      wrapper.find('[data-testid="lockdown-surface-mods"]').text()
    ).not.toMatch(/\d+ held/)
  })

  it('emits toggle-surface with the surface key and new value', async () => {
    const wrapper = createWrapper()
    await wrapper
      .find('[data-testid="lockdown-surface-toggle-posts"]')
      .setValue(false)
    expect(wrapper.emitted('toggle-surface')).toEqual([['posts', false]])
  })

  it('shows the chat mode selector only while chat is held', () => {
    const wrapper = createWrapper({
      surfaces: {
        mods: false,
        chat: false,
        chat_mode: 'hard',
        posts: false,
        chitchat: false,
        events: false,
        push: false,
        email: false,
        export: false,
      },
    })
    expect(wrapper.find('[data-testid="lockdown-chat-mode"]').exists()).toBe(
      false
    )
  })

  it('emits set-chat-mode when the chat mode selector changes', async () => {
    const wrapper = createWrapper()
    await wrapper.find('[data-testid="lockdown-chat-mode"]').setValue('soft')
    expect(wrapper.emitted('set-chat-mode')).toEqual([['soft']])
  })
})
