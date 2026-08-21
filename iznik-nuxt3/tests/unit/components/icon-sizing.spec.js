import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it, expect } from 'vitest'

/**
 * FontAwesome 7 sets an explicit width on every icon:
 *
 *   .svg-inline--fa { height: 1em; width: var(--fa-width, 1.25em); ... }
 *
 * FontAwesome 6 set no width at all, so the SVG took its width from the
 * viewBox and sizing an icon with `height` alone scaled the whole glyph. Under
 * 7 that no longer holds: the height applies, the width stays at the default,
 * and the glyph shrinks to fit the width instead. The navbar notification bell
 * regressed exactly this way when the Nuxt 4 upgrade brought FontAwesome 7 in,
 * rendering at roughly 20px beside its 32px neighbours.
 *
 * These guards are source greps rather than rendered assertions because scoped
 * SCSS is not applied under happy-dom, so a mounted component cannot show it.
 */

const ROOT = process.cwd()
const DIRS = ['components', 'pages', 'layouts', 'modtools', 'assets']

function sourceFiles() {
  const out = []
  const walk = (dir) => {
    let entries
    try {
      entries = readdirSync(dir, { withFileTypes: true })
    } catch {
      return
    }
    for (const e of entries) {
      const p = join(dir, e.name)
      if (e.isDirectory()) {
        if (e.name !== 'node_modules') walk(p)
      } else if (/\.(vue|scss|css)$/.test(e.name)) {
        out.push(p)
      }
    }
  }
  for (const d of DIRS) walk(join(ROOT, d))
  return out
}

const FILES = sourceFiles()
const rel = (p) => p.slice(ROOT.length + 1)

// Classes put on a <v-icon>, ignoring utility/size classes that carry no
// sizing rule of their own.
const IGNORED =
  /^(fa-|text-|m[etbsxy]?-|p[etbsxy]?-|d-|align-|float-|position-|w-|h-|border|bg-)/

function iconClasses() {
  const found = new Map()
  for (const f of FILES) {
    if (!f.endsWith('.vue')) continue
    const src = readFileSync(f, 'utf8')
    for (const m of src.matchAll(/<v-icon\b[^>]*?class="([^"]+)"/g)) {
      for (const c of m[1].split(/\s+/)) {
        if (c && !IGNORED.test(c)) found.set(c, rel(f))
      }
    }
  }
  return found
}

describe('FontAwesome icon sizing', () => {
  it('uses no invalid fa- size class', () => {
    // fa-2x is a size. fa-2 is not, and silently does nothing.
    const VALID = /^fa-(2xs|xs|sm|lg|xl|2xl|[1-9]|10)x$/
    const offenders = []

    for (const f of FILES) {
      if (!f.endsWith('.vue')) continue
      const lines = readFileSync(f, 'utf8').split('\n')
      lines.forEach((line, i) => {
        if (!line.includes('<v-icon')) return
        const m = line.match(/class="([^"]+)"/)
        if (!m) return
        for (const c of m[1].split(/\s+/)) {
          // A bare numeric suffix with no unit is never a FontAwesome class.
          if (/^fa-\d+$/.test(c) && !VALID.test(c)) {
            offenders.push(`${rel(f)}:${i + 1}  ${c}`)
          }
        }
      })
    }

    expect(
      offenders,
      'Not a FontAwesome size class, so it has no effect. Did you mean the "x" ' +
        'suffix, as in fa-2x?\n' +
        offenders.join('\n')
    ).toEqual([])
  })

  it('never sizes an icon by height alone', () => {
    const classes = iconClasses()
    const offenders = []

    for (const f of FILES) {
      const src = readFileSync(f, 'utf8')
      for (const [c] of classes) {
        const re = new RegExp(
          '\\.' +
            c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') +
            '\\s*\\{([^}]*)\\}',
          'g'
        )
        for (const m of src.matchAll(re)) {
          // Strip comments first: they routinely mention the very properties
          // being looked for, which would mask a real offender.
          const body = m[1]
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/\/\/.*/g, '')
          const hasHeight = /(^|[\s;])height\s*:/.test(body)
          const hasWidth = /(^|[\s;])width\s*:/.test(body)
          if (hasHeight && !hasWidth) {
            const line = src.slice(0, m.index).split('\n').length
            offenders.push(`${rel(f)}:${line}  .${c}`)
          }
        }
      }
    }

    expect(
      offenders,
      'FontAwesome 7 pins the icon width, so height on its own shrinks the ' +
        'glyph rather than scaling it. Set both dimensions, or size with ' +
        'font-size / fa-2x instead.\n' +
        offenders.join('\n')
    ).toEqual([])
  })
})
