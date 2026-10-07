import { describe, it, expect } from 'vitest'
import {
  textProblem,
  mjmlProblem,
  isSingleEmail,
  apiMessage,
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
})
