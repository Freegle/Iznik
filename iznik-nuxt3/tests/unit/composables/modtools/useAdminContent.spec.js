import { describe, it, expect } from 'vitest'
import {
  textProblem,
  mjmlProblem,
  isSingleEmail,
  apiMessage,
  mjmlForLiveEditor,
  localVolunteers,
  volunteersSentence,
  FOOTER_CHARITY,
  FOOTER_ADDRESS,
} from '~/modtools/composables/useAdminContent'

const section =
  '<mj-section><mj-column><mj-text>x</mj-text></mj-column></mj-section>'

describe('useAdminContent', () => {
  it('spots HTML in the text part but allows placeholders and comparisons', () => {
    for (const html of [
      '<b>x</b>',
      '<P>x',
      '</div>',
      '<img src="x">',
      '<!-- x -->',
      '<mj-text>',
    ]) {
      expect(textProblem(html), html).toContain('must be plain text')
    }
    for (const plain of [
      '',
      null,
      'Add <your names here>',
      '3 < 5',
      'I <3 Freegle',
    ]) {
      expect(textProblem(plain), String(plain)).toBeNull()
    }
  })

  it('accepts body sections and refuses everything else', () => {
    expect(mjmlProblem('')).toBeNull()
    expect(mjmlProblem('   ')).toBeNull()
    expect(mjmlProblem(section)).toBeNull()
    expect(mjmlProblem('<mj-wrapper>' + section + '</mj-wrapper>')).toBeNull()
    expect(mjmlProblem('<mjml><mj-body>' + section)).toContain(
      'inside <mj-body>'
    )
    expect(mjmlProblem('<mj-include path="x" />' + section)).toContain(
      'mj-include'
    )
    expect(mjmlProblem('<p>Just HTML</p>')).toContain('at least one')
    expect(mjmlProblem(section + 'x'.repeat(300 * 1024))).toContain('too long')
  })

  it('accepts exactly one plain address', () => {
    expect(isSingleEmail('a@example.com')).toBe(true)
    expect(isSingleEmail(' a@example.com ')).toBe(true)
    for (const bad of [
      '',
      null,
      'a',
      'a@b',
      'a@example.com, b@example.com',
      'A <a@example.com>',
    ]) {
      expect(isSingleEmail(bad), String(bad)).toBe(false)
    }
  })

  it('uses the server message when there is one', () => {
    expect(apiMessage({ response: { data: { message: 'No' } } }, 'F')).toBe(
      'No'
    )
    expect(apiMessage({}, 'F')).toBe('F')
    expect(apiMessage({ response: { data: { message: ' ' } } }, 'F')).toBe('F')
  })

  it('makes a whole MJML document with the standard footer under the sections', () => {
    const doc = mjmlForLiveEditor('  ' + section + '\n')
    expect(doc.startsWith('<mjml>')).toBe(true)
    expect(doc.trim().endsWith('</mj-body>\n</mjml>')).toBe(true)
    expect(doc).toContain(FOOTER_CHARITY)
    expect(doc).toContain(FOOTER_ADDRESS)
    expect(doc.indexOf(section)).toBeLessThan(doc.indexOf(FOOTER_CHARITY))
    expect(FOOTER_CHARITY).toContain('HMRC (ref. XT32865)')
    expect(FOOTER_ADDRESS).toContain('Ormesby')
  })

  it('names volunteers seen in the last year, by first name', () => {
    const now = new Date('2026-10-12T00:00:00Z').getTime()
    expect(
      localVolunteers(
        [
          { displayname: 'Michael Smith', lastaccess: '2026-10-11T10:00:00Z' },
          { displayname: 'Old Mod', lastaccess: '2025-01-01T00:00:00Z' },
          { displayname: 'Never', lastaccess: null },
          { displayname: '  Sunny  ', lastaccess: '2026-09-01T00:00:00Z' },
          { displayname: '', lastaccess: '2026-09-01T00:00:00Z' },
        ],
        now
      )
    ).toEqual(['Michael', 'Sunny'])
    expect(localVolunteers(null, now)).toEqual([])
  })

  it('words the volunteers as the plain-text email does', () => {
    expect(volunteersSentence([])).toBeNull()
    expect(volunteersSentence(['A'])).toBe('Your local volunteer is A.')
    expect(volunteersSentence(['A', 'B'])).toBe(
      'Your local volunteers are A and B.'
    )
    expect(volunteersSentence(['A', 'B', 'C'])).toBe(
      'Your local volunteers are A, B, and C.'
    )
  })

  it('puts the volunteers above the footer for the live editor, escaped', () => {
    const doc = mjmlForLiveEditor(section, ['Jo', '<b>'])
    expect(doc).toContain('Your local volunteers are Jo and &lt;b&gt;.')
    expect(doc.indexOf('Jo and')).toBeGreaterThan(doc.indexOf(section))
    expect(doc.indexOf('Jo and')).toBeLessThan(doc.indexOf(FOOTER_CHARITY))
    expect(mjmlForLiveEditor(section)).not.toContain('local volunteer')
  })
})
