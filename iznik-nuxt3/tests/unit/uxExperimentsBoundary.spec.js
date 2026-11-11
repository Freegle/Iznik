/**
 * The Experiments section stands alone (docs/developers/reference/ux-experiments.md). It must work when
 * the survey, idea and reaction parts of the UX tab are absent, so nothing it is made of may import one
 * of their files. This reads the imports of each file below, from an explicit list (never a glob that
 * could match another part's files), and fails on any import of another part.
 *
 * It does not read useUxSections.js, which is the tab's own file and holds an entry for every part.
 */
import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '..'
)

// Everything the Experiments section is made of.
const FILES = [
  'modtools/components/ModSysAdminUxExperiments.vue',
  'modtools/components/ModSysAdminUxExperimentRow.vue',
  'modtools/components/ModSysAdminUxExperimentDetail.vue',
  'modtools/components/ModSysAdminUxExperimentReadout.vue',
  'modtools/components/ModSysAdminUxExperimentChannel.vue',
  'modtools/components/ModSysAdminUxExperimentForm.vue',
  'modtools/components/ModSysAdminUxExperimentsBadge.vue',
  'modtools/composables/useUxExperimentActions.js',
  'modtools/composables/useUxExperimentRead.js',
  'modtools/stores/uxExperiments.js',
  'api/UxExperimentsAPI.js',
]

// The files of the survey, idea and reaction parts, by the names their imports carry.
const FORBIDDEN = [
  'uxSurveys',
  'uxIdeas',
  'uxReactions',
  'UxSurveysAPI',
  'UxIdeasAPI',
  'UxReactionsAPI',
  'ModSysAdminUxSurveys',
  'ModSysAdminUxProblem',
  'ModSysAdminUxIdea',
  'ModSysAdminUxValues',
  'ModSysAdminUxQuestion',
  'ModSysAdminUxSaid',
  'ModSysAdminUxExperimentReaction',
  'useUxAnswers',
  'useUxIdeaLabels',
]

// Every module named by an import, a dynamic import or a require.
function importsOf(source) {
  const found = []
  const patterns = [
    /\bfrom\s+['"]([^'"]+)['"]/g,
    /\bimport\s*\(\s*['"]([^'"]+)['"]\s*\)/g,
    /\brequire\s*\(\s*['"]([^'"]+)['"]\s*\)/g,
    /^\s*import\s+['"]([^'"]+)['"]/gm,
  ]
  for (const re of patterns) {
    for (const m of source.matchAll(re)) found.push(m[1])
  }
  return found
}

const offending = (source) =>
  importsOf(source).filter((spec) =>
    FORBIDDEN.some((name) => spec.includes(name))
  )

describe('the Experiments section stands alone', () => {
  const read = FILES.map((f) => ({
    file: f,
    source: fs.readFileSync(path.join(root, f), 'utf8'),
  }))

  it('read every file on its list, and none was empty', () => {
    // A wrong path would make readFileSync throw, and an empty file would check nothing.
    expect(read).toHaveLength(FILES.length)
    expect(read).toHaveLength(11)
    for (const { file, source } of read) {
      expect(source.trim().length, file).toBeGreaterThan(0)
    }
  })

  it('finds the imports that are there, so a pattern that finds nothing is noticed', () => {
    const imports = Object.fromEntries(
      read.map(({ file, source }) => [file, importsOf(source)])
    )
    expect(
      imports['modtools/components/ModSysAdminUxExperiments.vue']
    ).toContain('~/modtools/stores/uxExperiments')
    expect(
      imports['modtools/components/ModSysAdminUxExperimentDetail.vue']
    ).toContain('~/modtools/composables/useUxSections')
    expect(imports['modtools/stores/uxExperiments.js']).toContain(
      '~/modtools/composables/useUxSlot'
    )
    expect(imports['api/UxExperimentsAPI.js']).toContain('@/api/BaseAPI')
    // The words are worked out by pure functions that import nothing at all.
    expect(imports['modtools/composables/useUxExperimentRead.js']).toEqual([])
  })

  it('imports no file of the survey, idea or reaction parts', () => {
    for (const { file, source } of read) {
      expect(offending(source), file).toEqual([])
    }
  })

  describe('the check itself', () => {
    it('finds every way of importing another part', () => {
      const src = `
        import a from '~/modtools/stores/uxSurveys'
        import { b } from "~/api/UxIdeasAPI"
        import '~/modtools/components/ModSysAdminUxProblemGroups.vue'
        const c = await import('~/modtools/composables/useUxIdeaLabels')
        const d = require('~/modtools/stores/uxReactions')
        import ModSysAdminUxExperimentReaction from '~/modtools/components/ModSysAdminUxExperimentReaction.vue'
      `
      expect(offending(src)).toHaveLength(6)
    })

    it('lets the Experiments files import each other, F, and the libraries', () => {
      const src = `
        import { computed } from 'vue'
        import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'
        import ModSysAdminUxExperimentRow from '~/modtools/components/ModSysAdminUxExperimentRow.vue'
        import { hostedFor } from '~/modtools/composables/useUxSections'
        import { FLOW_ORDER } from '~/modtools/composables/useUxFlows'
      `
      expect(offending(src)).toEqual([])
    })
  })
})
