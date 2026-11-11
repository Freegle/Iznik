import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const CoverageReport = require('monocart-coverage-reports')
const { coverageSourcePath } = require('../e2e/coverage-source-path.js')

const MAIN_DIST = 'freegle-prod-local.localhost/_nuxt/m.js'
const MODTOOLS_DIST = 'modtools-prod-local.localhost/_nuxt/t.js'

describe('coverageSourcePath', () => {
  it('reports sources ModTools owns at their real repo path', () => {
    expect(
      coverageSourcePath('components/ModAdmin.vue', {
        url: '../../../components/ModAdmin.vue',
        distFile: MODTOOLS_DIST,
      })
    ).toBe('modtools/components/ModAdmin.vue')
  })

  it('keeps main-tree layer files bundled into the ModTools build', () => {
    expect(
      coverageSourcePath('components/ChatMessage.vue', {
        url: '../../../../components/ChatMessage.vue',
        distFile: MODTOOLS_DIST,
      })
    ).toBe('components/ChatMessage.vue')
  })

  it('leaves the main app and istanbul-stage calls alone', () => {
    expect(
      coverageSourcePath('layouts/default.vue', {
        url: '../../../layouts/default.vue',
        distFile: MAIN_DIST,
      })
    ).toBe('layouts/default.vue')
    expect(coverageSourcePath('layouts/default.vue', { data: {} })).toBe(
      'layouts/default.vue'
    )
  })
})

// A same-named file in both apps used to be decided by whichever coverage
// monocart handled last, i.e. by test worker finishing order.
describe('same-named file in both apps', () => {
  const mainSrc = 'a1\na2\na3\n'
  const modSrc = 'b1\nb2\nb3\nb4\nb5\nb6\n'

  const entry = (url, srcText, hitLines) => {
    const lines = srcText.trim().split('\n')
    const code = lines.map((_, i) => `f${i}();`).join('\n')
    const map = {
      version: 3,
      file: 'x.js',
      sources: ['../../../layouts/default.vue'],
      sourcesContent: [srcText],
      names: [],
      mappings: lines.map((_, i) => (i ? 'AACA' : 'AAAA')).join(';'),
    }
    // Built in two pieces so vite does not take this line for a real map comment.
    const mapComment = '//# sourceMapping' + 'URL=data:application/json;base64,'
    const source = `${code}\n${mapComment}${Buffer.from(
      JSON.stringify(map)
    ).toString('base64')}`
    const ranges = [{ startOffset: 0, endOffset: source.length, count: 1 }]
    let offset = 0
    lines.forEach((_, i) => {
      const len = `f${i}();`.length
      if (!hitLines.includes(i)) {
        ranges.push({ startOffset: offset, endOffset: offset + len, count: 0 })
      }
      offset += len + 1
    })
    return {
      url,
      source,
      functions: [{ functionName: '', isBlockCoverage: true, ranges }],
    }
  }

  const lcovFor = async (modtoolsFirst) => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mcr-'))
    const mcr = new CoverageReport({
      name: 'test',
      outputDir: dir,
      reports: ['lcovonly'],
      lcov: true,
      sourcePath: coverageSourcePath,
      sourceFilter: (p) => p.includes('.vue'),
    })
    const main = entry(`http://${MAIN_DIST}`, mainSrc, [0, 1, 2])
    const mod = entry(`http://${MODTOOLS_DIST}`, modSrc, [0])
    for (const e of modtoolsFirst ? [mod, main] : [main, mod]) {
      await mcr.add([e])
    }
    await mcr.generate()
    const lcov = fs.readFileSync(path.join(dir, 'lcov.info'), 'utf8')
    fs.rmSync(dir, { recursive: true, force: true })
    return lcov
  }

  const perFile = (lcov) =>
    Object.fromEntries(
      lcov
        .split('end_of_record')
        .filter((r) => r.includes('SF:'))
        .map((r) => [
          /SF:(.*)/.exec(r)[1],
          `${/LF:(\d+)/.exec(r)[1]}/${/LH:(\d+)/.exec(r)[1]}`,
        ])
    )

  it('reports both files, the same whichever app finished first', async () => {
    const a = perFile(await lcovFor(false))
    const b = perFile(await lcovFor(true))

    expect(a).toEqual(b)
    expect(a).toEqual({
      'layouts/default.vue': '3/3',
      'modtools/layouts/default.vue': '6/1',
    })
  })
})
