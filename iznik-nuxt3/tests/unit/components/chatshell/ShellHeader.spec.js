import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'

const stubs = { 'v-icon': true, 'nuxt-link': { template: '<a :href="to"><slot /></a>', props: ['to'] } }

describe('ShellHeader', () => {
  it('shows title, subtitle, initial avatar and a back link with an unread badge', () => {
    const w = mount(ShellHeader, { props: { title: 'Freegle', subtitle: 'Give and get', back: '/chats', badge: 3 }, global: { stubs } })
    expect(w.find('.shell-name').text()).toBe('Freegle')
    expect(w.find('.shell-subtitle').text()).toBe('Give and get')
    expect(w.find('.shell-avatar-fallback').text()).toBe('F')
    expect(w.find('[data-testid="shell-back"]').attributes('href')).toBe('/chats')
    expect(w.find('[data-testid="shell-badge"]').text()).toBe('3')
  })
  it('has no back link without a target and caps the badge', () => {
    const w = mount(ShellHeader, { props: { title: 'X', back: '/chats', badge: 250 }, global: { stubs } })
    expect(w.find('[data-testid="shell-badge"]').text()).toBe('99+')
    const none = mount(ShellHeader, { props: { title: 'X' }, global: { stubs } })
    expect(none.find('[data-testid="shell-back"]').exists()).toBe(false)
  })
})
