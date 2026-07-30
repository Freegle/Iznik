import { describe, it, expect } from 'vitest'
import { searchSettings } from '~/modtools/composables/useSettingsSearch'

describe('searchSettings', () => {
  it('returns nothing for an empty query', () => {
    expect(searchSettings('')).toEqual([])
    expect(searchSettings('   ')).toEqual([])
  })

  it('finds a setting by its label', () => {
    const results = searchSettings('tagline')

    expect(results[0].id).toBe('tagline')
    expect(results[0].label).toBe('Tagline')
  })

  it('ranks a label match above a description-only match', () => {
    const results = searchSettings('welcome')
    const welcomeMail = results.findIndex((r) => r.id === 'welcomemail')

    expect(welcomeMail).toBe(0)

    // Other settings mention the welcome mail in their description; they may
    // appear, but never above the setting actually called "Welcome email".
    const descriptionOnly = results.findIndex(
      (r) => !r.label.toLowerCase().includes('welcome')
    )

    if (descriptionOnly !== -1) {
      expect(descriptionOnly).toBeGreaterThan(welcomeMail)
    }
  })

  it('matches text in descriptions, not just labels', () => {
    // "autoreposts" appears in the expiry setting's description but not its
    // label - the case that makes description matching worth having.
    const results = searchSettings('autoreposts')

    expect(results.length).toBeGreaterThan(0)
  })

  it('narrows rather than widens as terms are added', () => {
    const broad = searchSettings('members')
    const narrow = searchSettings('members edit')

    expect(narrow.length).toBeLessThan(broad.length)
    expect(narrow.length).toBeGreaterThan(0)
  })

  it('requires every term to match something', () => {
    expect(searchSettings('tagline zzzznotathing')).toEqual([])
  })

  it('is case insensitive', () => {
    expect(searchSettings('TAGLINE')[0].id).toBe('tagline')
  })

  it('labels each result with the tab it lives on', () => {
    const results = searchSettings('tagline')

    expect(results[0].tabLabel).toBe('Community')
  })

  it('caps the number of results', () => {
    // A term this common would otherwise return most of the index.
    expect(searchSettings('the').length).toBeLessThanOrEqual(12)
  })
})
