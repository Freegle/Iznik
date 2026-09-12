import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ChitChatBubble from '~/components/chatshell/ChitChatBubble.vue'

const stubs = {
  ProfileImage: true,
  ProxyImage: true,
  OurUploadedImage: true,
  'b-dropdown': {
    template: '<div><slot name="button-content" /><slot /></div>',
  },
  'b-dropdown-item': {
    template: '<button @click="$emit(\'click\')"><slot /></button>',
  },
}
const item = {
  id: 3,
  userid: 7,
  displayname: 'Sam',
  message: 'Anyone know a good plumber?',
  timestamp: '2026-09-08T09:00:00Z',
  loves: 2,
  loved: false,
}

describe('ChitChatBubble', () => {
  it('shows the name, text and love count, and emits love and reply', async () => {
    const w = mount(ChitChatBubble, { props: { item }, global: { stubs } })
    expect(w.find('.cc-name').text()).toBe('Sam')
    expect(w.find('.cc-text').text()).toBe('Anyone know a good plumber?')
    expect(w.find('[data-testid="cc-love-3"]').text()).toContain('2')
    await w.find('[data-testid="cc-love-3"]').trigger('click')
    expect(w.emitted('love')[0][0]).toMatchObject({ id: 3 })
    await w.find('[data-testid="cc-reply-3"]').trigger('click')
    expect(w.emitted('reply')[0][0]).toMatchObject({ id: 3 })
  })
  it('a reply quotes what it answers and tapping the quote jumps; my own bubbles sit right without a name', async () => {
    const quote = { id: 1, displayname: 'Jo', message: 'x'.repeat(100) }
    const w = mount(ChitChatBubble, {
      props: { item: { ...item, id: 4 }, quote, mine: true },
      global: { stubs },
    })
    expect(w.classes()).toContain('mine')
    expect(w.find('.cc-name').exists()).toBe(false)
    expect(w.find('.cc-quote-text').text().length).toBeLessThan(80)
    await w.find('[data-testid="cc-quote-4"]').trigger('click')
    expect(w.emitted('jump')[0][0]).toBe(1)
  })
})
