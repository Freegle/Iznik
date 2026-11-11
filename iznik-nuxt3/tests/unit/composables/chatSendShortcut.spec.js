import { describe, it, expect } from 'vitest'
import { isSendShortcut } from '~/composables/chatSendShortcut'

const ev = (props) => ({
  key: 'Enter',
  ctrlKey: false,
  metaKey: false,
  shiftKey: false,
  altKey: false,
  isComposing: false,
  ...props,
})

describe('isSendShortcut', () => {
  it('accepts Ctrl+Enter', () => {
    expect(isSendShortcut(ev({ ctrlKey: true }))).toBe(true)
  })

  it('accepts Cmd+Enter', () => {
    expect(isSendShortcut(ev({ metaKey: true }))).toBe(true)
  })

  it('rejects plain Enter', () => {
    expect(isSendShortcut(ev({}))).toBe(false)
  })

  it('rejects other keys with Ctrl', () => {
    expect(isSendShortcut(ev({ key: 'a', ctrlKey: true }))).toBe(false)
  })

  it('leaves Shift and Alt combinations to the newline handlers', () => {
    expect(isSendShortcut(ev({ ctrlKey: true, shiftKey: true }))).toBe(false)
    expect(isSendShortcut(ev({ ctrlKey: true, altKey: true }))).toBe(false)
  })

  it('ignores Enter that confirms an IME composition', () => {
    expect(isSendShortcut(ev({ ctrlKey: true, isComposing: true }))).toBe(false)
  })
})
