import { describe, it, expect } from 'vitest'
import { resolveUiMode } from '~/composables/useUiMode'

describe('resolveUiMode', () => {
  it('settings win over the browser and the default', () => {
    expect(resolveUiMode({ settingsMode: 'classic', storedMode: 'chat', defaultMode: 'chat', userId: 1 })).toBe('classic')
    expect(resolveUiMode({ settingsMode: 'chat', storedMode: 'classic', defaultMode: 'classic', userId: 1 })).toBe('chat')
  })
  it('the browser wins over the default for a visitor', () => {
    expect(resolveUiMode({ settingsMode: undefined, storedMode: 'chat', defaultMode: 'classic' })).toBe('chat')
  })
  it('a fixed default applies when nobody chose', () => {
    expect(resolveUiMode({ defaultMode: 'chat' })).toBe('chat')
    expect(resolveUiMode({ defaultMode: 'classic' })).toBe('classic')
    expect(resolveUiMode({})).toBe('classic')
    expect(resolveUiMode({ defaultMode: 'nonsense' })).toBe('classic')
  })
  it('a percentage default buckets members by id and leaves visitors on classic', () => {
    expect(resolveUiMode({ defaultMode: '30', userId: 105 })).toBe('chat')
    expect(resolveUiMode({ defaultMode: '30', userId: 150 })).toBe('classic')
    expect(resolveUiMode({ defaultMode: '30' })).toBe('classic')
    expect(resolveUiMode({ defaultMode: '100', userId: 7 })).toBe('chat')
  })
})
