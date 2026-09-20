import { describe, it, expect } from 'vitest'
import {
  attachmentMods,
  isAIAttachment,
  removePhotoPatch,
} from '~/composables/usePhotoRemoval'

describe('usePhotoRemoval', () => {
  describe('attachmentMods', () => {
    it('parses a JSON string in externalmods', () => {
      expect(
        attachmentMods({
          externalmods: JSON.stringify({ ai: true, rotate: 90 }),
        })
      ).toEqual({ ai: true, rotate: 90 })
    })

    it('accepts an object, and falls back to the legacy mods field', () => {
      expect(attachmentMods({ externalmods: { rotate: 180 } })).toEqual({
        rotate: 180,
      })
      expect(attachmentMods({ mods: JSON.stringify({ ai: true }) })).toEqual({
        ai: true,
      })
    })

    it('returns an empty object for nothing, null or unparseable input', () => {
      expect(attachmentMods(null)).toEqual({})
      expect(attachmentMods({})).toEqual({})
      expect(attachmentMods({ externalmods: 'not json' })).toEqual({})
      expect(attachmentMods({ externalmods: 'null' })).toEqual({})
    })
  })

  describe('isAIAttachment', () => {
    it('is true only when the mods carry the ai flag', () => {
      expect(
        isAIAttachment({ externalmods: JSON.stringify({ ai: true }) })
      ).toBe(true)
      expect(
        isAIAttachment({ externalmods: JSON.stringify({ rotate: 90 }) })
      ).toBe(false)
      expect(isAIAttachment({ id: 1 })).toBe(false)
    })

    it('also trusts the ai flag the V2 API computes on each attachment', () => {
      expect(isAIAttachment({ id: 1, ai: true, externalmods: null })).toBe(true)
      expect(isAIAttachment({ id: 1, ai: false, externalmods: null })).toBe(
        false
      )
    })
  })

  describe('removePhotoPatch', () => {
    const message = {
      id: 456,
      attachments: [{ id: 123 }, { id: 124 }, { id: 125 }],
    }

    it('keeps every other attachment, in order', () => {
      expect(removePhotoPatch(message, 124, false)).toEqual({
        id: 456,
        attachments: [123, 125],
      })
    })

    it('flags the removed image as bad for any post of the item when asked', () => {
      expect(removePhotoPatch(message, 123, true)).toEqual({
        id: 456,
        attachments: [124, 125],
        badAIImages: [123],
      })
    })

    it('sends an empty list when the last attachment goes', () => {
      expect(
        removePhotoPatch({ id: 7, attachments: [{ id: 1 }] }, 1, false)
      ).toEqual({ id: 7, attachments: [] })
    })
  })
})
