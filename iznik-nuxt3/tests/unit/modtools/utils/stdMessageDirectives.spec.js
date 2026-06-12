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
  parseSegments,
  hasDirectives,
  assembleSegments,
  segmentsSendBlockers,
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

  describe('parseSegments', () => {
    it('splits a message into ordered text/editthis/optional segments', () => {
      const t = 'Hi <editthis>name</editthis>, see <optional>browse first</optional> ok'
      const segs = parseSegments(t)
      expect(segs.map((s) => s.type)).toEqual([
        'text', 'editthis', 'text', 'optional', 'text',
      ])
      expect(segs[0].content).toBe('Hi ')
      expect(segs[1]).toMatchObject({ type: 'editthis', content: 'name', value: 'name', edited: false })
      expect(segs[3]).toMatchObject({ type: 'optional', content: 'browse first', removed: undefined })
      expect(segs[4].content).toBe(' ok')
    })
    it('preserves order with multiple editthis blocks', () => {
      const segs = parseSegments('a<editthis>1</editthis>b<editthis>2</editthis>c')
      expect(segs.filter((s) => s.type === 'editthis').map((s) => s.content)).toEqual(['1', '2'])
      expect(segs.map((s) => s.type)).toEqual(['text', 'editthis', 'text', 'editthis', 'text'])
    })
    it('handles a plain message (single text segment) and empty input', () => {
      expect(parseSegments('just text').map((s) => s.type)).toEqual(['text'])
      expect(parseSegments('')).toEqual([])
    })
  })

  describe('hasDirectives', () => {
    it('detects editthis/optional, ignores plain text', () => {
      expect(hasDirectives('a <editthis>x</editthis>')).toBe(true)
      expect(hasDirectives('a <optional>x</optional>')).toBe(true)
      expect(hasDirectives('plain message')).toBe(false)
      expect(hasDirectives('')).toBe(false)
    })
  })

  describe('assembleSegments', () => {
    it('builds the final message from edited segments in order', () => {
      const segs = parseSegments('Hi <editthis>NAME</editthis>.\n\n<optional>browse first</optional>\n\nThanks')
      segs.find((s) => s.type === 'editthis').value = 'Jo'
      segs.find((s) => s.type === 'optional').removed = false // kept
      expect(assembleSegments(segs)).toBe('Hi Jo.\n\nbrowse first\n\nThanks')
    })
    it('drops a removed optional and tidies the blank lines', () => {
      const segs = parseSegments('Hi <editthis>NAME</editthis>.\n\n<optional>browse first</optional>\n\nThanks')
      segs.find((s) => s.type === 'editthis').value = 'Jo'
      segs.find((s) => s.type === 'optional').removed = true
      expect(assembleSegments(segs)).toBe('Hi Jo.\n\nThanks')
    })
  })

  describe('segmentsSendBlockers', () => {
    it('blocks while an editthis is unchanged or an optional undecided', () => {
      const segs = parseSegments('Hi <editthis>NAME</editthis> <optional>x</optional>')
      let r = segmentsSendBlockers(segs)
      expect(r.ok).toBe(false)
      expect(r.unedited.length).toBe(1)
      expect(r.undecided.length).toBe(1)
      // edit the editthis and decide the optional
      segs.find((s) => s.type === 'editthis').value = 'Jo'
      segs.find((s) => s.type === 'optional').removed = true
      r = segmentsSendBlockers(segs)
      expect(r.ok).toBe(true)
      expect(r.unedited).toEqual([])
      expect(r.undecided).toEqual([])
    })
    it('treats a blanked editthis as still unedited', () => {
      const segs = parseSegments('Hi <editthis>NAME</editthis>')
      segs.find((s) => s.type === 'editthis').value = '   '
      expect(segmentsSendBlockers(segs).ok).toBe(false)
    })
  })
})
