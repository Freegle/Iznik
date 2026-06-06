import { describe, it, expect } from 'vitest'
import {
  parseEditThis,
  parseOptional,
  stripDirectiveTags,
  removeOptionalAt,
  keepOptionalAt,
  pendingEditThis,
  hasUndecidedOptional,
  sendBlockers,
} from '~/modtools/utils/stdMessageDirectives'

describe('stdMessageDirectives', () => {
  describe('parseEditThis', () => {
    it('returns the inner text of each editthis block', () => {
      const t = 'Hi <editthis>a clearer title</editthis> and <editthis>why</editthis>.'
      expect(parseEditThis(t)).toEqual(['a clearer title', 'why'])
    })
    it('is case-insensitive and multiline', () => {
      const t = 'a <EditThis>line one\nline two</EDITTHIS> b'
      expect(parseEditThis(t)).toEqual(['line one\nline two'])
    })
    it('returns [] when there are none or text is empty', () => {
      expect(parseEditThis('no directives here')).toEqual([])
      expect(parseEditThis('')).toEqual([])
      expect(parseEditThis(undefined)).toEqual([])
    })
  })

  describe('parseOptional', () => {
    it('returns the inner text of each optional block', () => {
      const t = 'Keep. <optional>maybe browse instead</optional> End.'
      expect(parseOptional(t)).toEqual(['maybe browse instead'])
    })
  })

  describe('stripDirectiveTags', () => {
    it('removes editthis and optional tags but keeps their content', () => {
      const t = 'A <editthis>X</editthis> B <optional>Y</optional> C'
      expect(stripDirectiveTags(t)).toBe('A X B Y C')
    })
    it('leaves plain text untouched', () => {
      expect(stripDirectiveTags('plain')).toBe('plain')
    })
  })

  describe('removeOptionalAt / keepOptionalAt', () => {
    const t =
      'Intro.\n<optional>first opt</optional>\nMiddle.\n<optional>second opt</optional>\nEnd.'

    it('removeOptionalAt drops the chosen block and tidies blank lines', () => {
      const out = removeOptionalAt(t, 0)
      expect(out).not.toContain('first opt')
      expect(out).toContain('second opt') // the other one is untouched
      expect(out).not.toMatch(/\n{3,}/) // no triple blank lines left behind
    })

    it('keepOptionalAt strips just that block’s tags, leaving its text', () => {
      const out = keepOptionalAt(t, 1)
      expect(out).toContain('second opt')
      expect(out).not.toContain('<optional>second opt</optional>')
      // The first block is still undecided (tags remain).
      expect(out).toContain('<optional>first opt</optional>')
    })

    it('a remove then a keep clears all optional tags', () => {
      let out = removeOptionalAt(t, 0) // first removed; second now at index 0
      out = keepOptionalAt(out, 0)
      expect(hasUndecidedOptional(out)).toBe(false)
      expect(out).toContain('second opt')
      expect(out).not.toContain('first opt')
    })
  })

  describe('pendingEditThis', () => {
    const originals = ['a clearer title', 'a reason']

    it('lists placeholders still present verbatim', () => {
      const body = 'Please use a clearer title here, because reasons.'
      expect(pendingEditThis(body, originals)).toEqual(['a clearer title'])
    })
    it('is empty once every placeholder has been changed', () => {
      const body = 'Please use Blue sofa, free to collect, because too big.'
      expect(pendingEditThis(body, originals)).toEqual([])
    })
    it('matches even if the editthis tags are still around the placeholder', () => {
      const body = 'Use <editthis>a clearer title</editthis> please.'
      expect(pendingEditThis(body, originals)).toEqual(['a clearer title'])
    })
    it('ignores whitespace-only placeholders', () => {
      expect(pendingEditThis('anything', ['  ', ''])).toEqual([])
    })
  })

  describe('hasUndecidedOptional', () => {
    it('is true while an optional tag remains, false otherwise', () => {
      expect(hasUndecidedOptional('a <optional>x</optional> b')).toBe(true)
      expect(hasUndecidedOptional('a x b')).toBe(false)
      expect(hasUndecidedOptional('')).toBe(false)
    })
  })

  describe('sendBlockers', () => {
    it('blocks while an editthis is unedited', () => {
      const r = sendBlockers('Use a clearer title.', ['a clearer title'])
      expect(r.ok).toBe(false)
      expect(r.editThis).toEqual(['a clearer title'])
    })
    it('blocks while an optional is undecided', () => {
      const r = sendBlockers('text <optional>x</optional>', [])
      expect(r.ok).toBe(false)
      expect(r.optionalUndecided).toBe(true)
    })
    it('passes once everything is edited and decided', () => {
      const r = sendBlockers('all good, decided text', ['placeholder'])
      expect(r.ok).toBe(true)
      expect(r.editThis).toEqual([])
      expect(r.optionalUndecided).toBe(false)
    })
  })
})
