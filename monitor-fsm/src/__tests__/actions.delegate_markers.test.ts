import { describe, it, expect } from 'vitest'
import { extractJsonArrayMarker } from '../actions/index.js'

// Regression coverage for the silent bug-drop of 2026-07-22 (topic 9808 post
// 633): a verbose topic delegate emitted CLASSIFICATIONS=[...] near the middle
// of a long stream, then kept printing a prose summary. The driver only fed the
// last 1500 chars (stdoutTail) to the collate brain, so the marker scrolled out
// of view and the bugs were never persisted — while the cursor still advanced,
// making the loss unrecoverable. The fix parses the marker from the FULL stream
// in the driver, exactly as ANALYSIS_COMPLETE already is.
describe('extractJsonArrayMarker', () => {
  it('extracts a single-line CLASSIFICATIONS array', () => {
    const out = 'some log\nCLASSIFICATIONS=[{"type":"bug","topic":9808}]\nANALYSIS_COMPLETE=done'
    expect(extractJsonArrayMarker(out, 'CLASSIFICATIONS')).toEqual([{ type: 'bug', topic: 9808 }])
  })

  it('finds the marker even when buried under a long trailing prose summary', () => {
    const marker = 'CLASSIFICATIONS=[{"type":"bug","summary":"feedback tab leaks rippled items"}]'
    const trailingProse = '\n' + 'x'.repeat(5000) // far exceeds the old 1500-char tail window
    const out = 'grounding...\n' + marker + '\nANALYSIS_COMPLETE=2 bugs' + trailingProse
    expect(extractJsonArrayMarker(out, 'CLASSIFICATIONS')).toEqual([
      { type: 'bug', summary: 'feedback tab leaks rippled items' },
    ])
  })

  it('parses an empty array', () => {
    expect(extractJsonArrayMarker('CLASSIFICATIONS=[]\nANALYSIS_COMPLETE=no new posts', 'CLASSIFICATIONS')).toEqual([])
  })

  it('returns the last valid occurrence when the marker appears more than once', () => {
    const out = 'CLASSIFICATIONS=[{"a":1}]\nretry\nCLASSIFICATIONS=[{"a":2}]'
    expect(extractJsonArrayMarker(out, 'CLASSIFICATIONS')).toEqual([{ a: 2 }])
  })

  it('tolerates brackets inside string values', () => {
    const out = 'CLASSIFICATIONS=[{"summary":"array [0] index and ] bracket"}]'
    expect(extractJsonArrayMarker(out, 'CLASSIFICATIONS')).toEqual([
      { summary: 'array [0] index and ] bracket' },
    ])
  })

  it('returns null when the marker is absent', () => {
    expect(extractJsonArrayMarker('nothing here\nANALYSIS_COMPLETE=done', 'CLASSIFICATIONS')).toBeNull()
  })

  it('does not grab a bracket from later prose when the value is not an array', () => {
    // A malformed emission where the "=" is not immediately followed by "["
    const out = 'CLASSIFICATIONS= see the array [below]'
    expect(extractJsonArrayMarker(out, 'CLASSIFICATIONS')).toBeNull()
  })

  it('returns null (not a throw) on malformed JSON', () => {
    expect(extractJsonArrayMarker('CLASSIFICATIONS=[{"type":"bug",]', 'CLASSIFICATIONS')).toBeNull()
  })

  it('extracts the SENTRY_ISSUES marker with the same parser', () => {
    const out = 'SENTRY_ISSUES=[{"project":"nuxt3","id":"1"}]\nANALYSIS_COMPLETE=sentry scan done'
    expect(extractJsonArrayMarker(out, 'SENTRY_ISSUES')).toEqual([{ project: 'nuxt3', id: '1' }])
  })
})
