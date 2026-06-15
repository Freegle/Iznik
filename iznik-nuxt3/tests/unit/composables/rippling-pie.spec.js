import { describe, it, expect, beforeEach } from 'vitest'
import { renderPie } from '~/modtools/composables/rippling/pie.js'

let svg
beforeEach(() => {
  svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
})

describe('rippling/pie', () => {
  it('does nothing when given a null element', () => {
    expect(() => renderPie(null, [{ count: 1, color: '#000' }])).not.toThrow()
  })

  it('renders an empty grey circle when all slices are zero', () => {
    renderPie(svg, [{ count: 0, color: '#000' }, { count: 0, color: '#fff' }])
    expect(svg.innerHTML).toContain('<circle')
    expect(svg.innerHTML).toContain('#eee')
  })

  it('renders a single full-circle when one slice has 100% share', () => {
    renderPie(svg, [{ count: 10, color: '#27ae60' }])
    expect(svg.innerHTML).toContain('<circle')
    expect(svg.innerHTML).toContain('#27ae60')
    expect(svg.innerHTML).not.toContain('<path')
  })

  it('renders two slice <path>s for a two-slice pie', () => {
    renderPie(svg, [
      { count: 60, color: '#27ae60' },
      { count: 40, color: '#1f77b4' },
    ])
    const paths = svg.querySelectorAll('path')
    expect(paths.length).toBe(2)
    expect(paths[0].getAttribute('fill')).toBe('#27ae60')
    expect(paths[1].getAttribute('fill')).toBe('#1f77b4')
  })

  it('skips zero-count slices when others have data', () => {
    renderPie(svg, [
      { count: 50, color: '#27ae60' },
      { count: 0, color: '#1f77b4' },
      { count: 50, color: '#f39c12' },
    ])
    const paths = svg.querySelectorAll('path')
    expect(paths.length).toBe(2)
    expect(paths[0].getAttribute('fill')).toBe('#27ae60')
    expect(paths[1].getAttribute('fill')).toBe('#f39c12')
  })

  it('emits the large-arc flag for slices over half the pie', () => {
    renderPie(svg, [
      { count: 70, color: '#27ae60' },
      { count: 30, color: '#1f77b4' },
    ])
    const first = svg.querySelector('path').getAttribute('d')
    // The third number in the A directive is the large-arc-flag.  For a
    // 70% slice it should be 1.
    const match = first.match(/A 1 1 0 (\d) 1/)
    expect(match).not.toBeNull()
    expect(match[1]).toBe('1')
  })

  it('does not emit the large-arc flag for small slices', () => {
    renderPie(svg, [
      { count: 30, color: '#27ae60' },
      { count: 70, color: '#1f77b4' },
    ])
    const first = svg.querySelector('path').getAttribute('d')
    const match = first.match(/A 1 1 0 (\d) 1/)
    expect(match).not.toBeNull()
    expect(match[1]).toBe('0')
  })
})
