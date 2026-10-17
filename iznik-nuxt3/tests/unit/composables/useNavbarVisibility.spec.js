import { describe, it, expect, vi } from 'vitest'
import { ref } from 'vue'

import { useNavbarVisibility } from '~/composables/useNavbarVisibility'

const isChat = ref(false)
vi.mock('~/composables/useUiMode', () => ({ useUiMode: () => ({ isChat }) }))

// Pins the app.vue navbar/layout contract across the Nuxt 4 upgrade: the
// navbar hides for the 'no-navbar' layout, hides on chat-shell routes while the
// member is on the chat version, and shows for everything else, including routes
// where meta is missing entirely.
describe('useNavbarVisibility', () => {
  it('shows the navbar for the default layout', () => {
    isChat.value = false
    expect(useNavbarVisibility({ meta: { layout: 'default' } }).value).toBe(
      true
    )
  })
  it('shows the navbar when no layout is set', () => {
    expect(useNavbarVisibility({ meta: {} }).value).toBe(true)
  })
  it('shows the navbar when route.meta is missing', () => {
    expect(useNavbarVisibility({}).value).toBe(true)
  })
  it('hides the navbar for the no-navbar layout', () => {
    expect(useNavbarVisibility({ meta: { layout: 'no-navbar' } }).value).toBe(
      false
    )
  })
  it('hides the navbar on a chat-shell route only while the member is on chat', () => {
    isChat.value = true
    expect(
      useNavbarVisibility({ meta: { layout: false, chatShell: true } }).value
    ).toBe(false)
    expect(useNavbarVisibility({ meta: { layout: 'default' } }).value).toBe(
      true
    )
    isChat.value = false
    expect(
      useNavbarVisibility({ meta: { layout: false, chatShell: true } }).value
    ).toBe(true)
  })
})
