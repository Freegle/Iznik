import { describe, it, expect } from 'vitest'
import { useNavbarVisibility } from '~/composables/useNavbarVisibility'

// Pins the app.vue navbar/layout contract across the Nuxt 4 upgrade: the
// navbar hides only for the 'no-navbar' layout and shows for everything else,
// including routes where meta is missing entirely.
describe('useNavbarVisibility', () => {
  it('shows the navbar for the default layout', () => {
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
})
