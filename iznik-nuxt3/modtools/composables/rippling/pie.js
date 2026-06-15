// Render a small SVG pie chart into the given <svg> element.
//
// `slices` is an array of `{ count, color }`.  The SVG's outer transform
// is expected to rotate by -90° so the first slice starts at the top.
//
// Side effect: replaces svgEl.innerHTML.
export function renderPie(svgEl, slices) {
  if (!svgEl) return
  const total = slices.reduce((s, x) => s + (x.count || 0), 0)
  if (total === 0) {
    svgEl.innerHTML = '<circle r="1" fill="#eee" />'
    return
  }
  let acc = 0
  const parts = []
  for (const s of slices) {
    if (!s.count) continue
    const frac = s.count / total
    if (frac >= 0.999) {
      parts.push(`<circle r="1" fill="${s.color}" />`)
      break
    }
    const a0 = acc * 2 * Math.PI
    const a1 = (acc + frac) * 2 * Math.PI
    const x0 = Math.cos(a0).toFixed(4)
    const y0 = Math.sin(a0).toFixed(4)
    const x1 = Math.cos(a1).toFixed(4)
    const y1 = Math.sin(a1).toFixed(4)
    const largeArc = frac > 0.5 ? 1 : 0
    parts.push(
      `<path d="M ${x0} ${y0} A 1 1 0 ${largeArc} 1 ${x1} ${y1} L 0 0 Z" fill="${s.color}" />`
    )
    acc += frac
  }
  svgEl.innerHTML = parts.join('')
}
