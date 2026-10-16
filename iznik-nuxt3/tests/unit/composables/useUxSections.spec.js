import { describe, it, expect } from 'vitest'
import {
  SECTIONS,
  HOSTED,
  uxSections,
  hostedFor,
} from '~/modtools/composables/useUxSections'

describe('useUxSections', () => {
  it('lists the sections in the order of their `order`', () => {
    const orders = uxSections().map((s) => s.order)
    expect(orders).toEqual([...orders].sort((a, b) => a - b))
    // Other parts of the tab add sections between Funnels and Pages, so this names only these three.
    const own = ['overview', 'funnels', 'pages']
    expect(
      uxSections()
        .map((s) => s.key)
        .filter((k) => own.includes(k))
    ).toEqual(own)
    expect(uxSections()[0].key).toBe('overview')
  })

  it('gives a copy, so a caller cannot reorder the registry', () => {
    const copy = uxSections()
    copy.reverse()
    expect(uxSections()[0].key).toBe('overview')
    expect(copy).not.toBe(SECTIONS)
  })

  it('gives every section a component, an order, a label and lists for filters, slots and params', () => {
    for (const s of SECTIONS) {
      expect(s.component, s.key).toBeTruthy()
      expect(typeof s.order, s.key).toBe('number')
      expect(s.label, s.key).toBeTruthy()
      expect(Array.isArray(s.filters), s.key).toBe(true)
      expect(Array.isArray(s.slots), s.key).toBe(true)
      expect(Array.isArray(s.params), s.key).toBe(true)
    }
  })

  it('uses a key once and a filter only from the three it knows', () => {
    expect(new Set(SECTIONS.map((s) => s.key)).size).toBe(SECTIONS.length)
    for (const s of SECTIONS) {
      for (const f of s.filters) {
        expect(['period', 'device', 'signed'], s.key).toContain(f)
      }
    }
  })

  it('asks the server only for the reads the store has', () => {
    const reads = [
      'health',
      'funnels',
      'outcomes',
      'friction',
      'adoption',
      'regret',
      'search',
    ]
    for (const s of SECTIONS) {
      for (const slot of s.slots) expect(reads, s.key).toContain(slot)
    }
  })

  it('leaves the field figures to the Funnels section, which knows the funnel', () => {
    for (const s of SECTIONS) expect(s.slots, s.key).not.toContain('fields')
  })

  it('puts Pages and features last, so other sections sit between Funnels and it', () => {
    const last = uxSections().at(-1)
    expect(last.key).toBe('pages')
    expect(last.order).toBe(90)
  })

  it('has two places other parts add components to, and gives an empty list for a place it does not know', () => {
    // Which components are in them depends on which other parts of the tab are there, so this names none.
    expect(Object.keys(HOSTED).sort()).toEqual(['experiment', 'problem'])
    expect(Array.isArray(HOSTED.problem)).toBe(true)
    expect(Array.isArray(HOSTED.experiment)).toBe(true)
    expect(hostedFor('problem')).toBe(HOSTED.problem)
    expect(hostedFor('experiment')).toBe(HOSTED.experiment)
    expect(hostedFor('nothing')).toEqual([])
  })
})
