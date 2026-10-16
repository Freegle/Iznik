/**
 * The Ideas section stands alone (docs/developers/reference/ux-ideas.md). It must work when the
 * experiment, survey and reaction parts of the UX tab are absent, so nothing it is made of may import
 * one of their files. This reads the imports of each file below, from an explicit list (never a glob
 * that could match another part's files), and fails on any import of another part.
 *
 * The only contacts it has are the tab's own: the registry (useUxSections), which says which sections
 * there are, and the hosted slot a member problem's detail draws. It does not read useUxSections.js,
 * which is the tab's own file and holds an entry for every part.
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

// Everything the Ideas section is made of.
const FILES = [
  'modtools/components/ModSysAdminUxIdeas.vue',
  'modtools/components/ModSysAdminUxIdeaRow.vue',
  'modtools/components/ModSysAdminUxProblemIdeas.vue',
  'modtools/composables/useUxIdeaLabels.js',
  'modtools/stores/uxIdeas.js',
  'api/UxIdeasAPI.js',
]

// The modules of the experiment, survey and reaction parts, by the name of the file an import ends
// in. Names are whole file names, so the hosted piece ModSysAdminUxProblemIdeas, which is this
// part's, is not taken for the survey part's ModSysAdminUxProblem.
const FORBIDDEN = [
  // Experiments.
  'ModSysAdminUxExperiments',
  'ModSysAdminUxExperimentRow',
  'ModSysAdminUxExperimentDetail',
  'ModSysAdminUxExperimentReadout',
  'ModSysAdminUxExperimentForm',
  'ModSysAdminUxExperimentsBadge',
  'useUxExperimentActions',
  'useUxExperimentRead',
  'uxExperiments',
  'UxExperimentsAPI',
  // Surveys.
  'ModSysAdminUxSurveys',
  'ModSysAdminUxProblem',
  'ModSysAdminUxProblemGroups',
  'ModSysAdminUxValues',
  'ModSysAdminUxQuestions',
  'ModSysAdminUxQuestionResult',
  'ModSysAdminUxSaid',
  'useUxAnswers',
  'uxSurveys',
  'UxSurveysAPI',
  // Reactions.
  'ModSysAdminUxExperimentReaction',
  'uxReactions',
  'UxReactionsAPI',
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

// The file an import ends in, without the folder and without .vue or .js.
const nameOf = (spec) =>
  spec
    .split('/')
    .pop()
    .replace(/\.(vue|js|mjs|ts)$/, '')

const offending = (source) =>
  importsOf(source).filter((spec) => FORBIDDEN.includes(nameOf(spec)))

describe('the Ideas section stands alone', () => {
  const read = FILES.map((f) => ({
    file: f,
    source: fs.readFileSync(path.join(root, f), 'utf8'),
  }))

  it('read every file on its list, and none was empty', () => {
    // A wrong path would make readFileSync throw, and an empty file would check nothing.
    expect(read).toHaveLength(FILES.length)
    expect(read).toHaveLength(6)
    for (const { file, source } of read) {
      expect(source.trim().length, file).toBeGreaterThan(0)
    }
  })

  it('finds the imports that are there, so a pattern that finds nothing is noticed', () => {
    const imports = Object.fromEntries(
      read.map(({ file, source }) => [file, importsOf(source)])
    )
    expect(imports['modtools/components/ModSysAdminUxIdeas.vue']).toContain(
      '~/modtools/stores/uxIdeas'
    )
    expect(imports['modtools/components/ModSysAdminUxIdeas.vue']).toContain(
      '~/modtools/components/ModSysAdminUxIdeaRow.vue'
    )
    expect(imports['modtools/components/ModSysAdminUxIdeaRow.vue']).toContain(
      '~/modtools/composables/useUxSections'
    )
    expect(imports['modtools/stores/uxIdeas.js']).toContain(
      '~/modtools/composables/useUxSlot'
    )
    expect(imports['api/UxIdeasAPI.js']).toContain('@/api/BaseAPI')
    // The words are worked out by pure functions that import nothing at all.
    expect(imports['modtools/composables/useUxIdeaLabels.js']).toEqual([])
  })

  it('imports no file of the experiment, survey or reaction parts', () => {
    for (const { file, source } of read) {
      expect(offending(source), file).toEqual([])
    }
  })

  it('reaches the other parts of the tab only through the registry', () => {
    // The row asks which sections there are, to decide which links to draw, and the section file is
    // never read. The registry is the one contact the tab itself provides.
    for (const { file, source } of read) {
      for (const spec of importsOf(source)) {
        if (spec.includes('useUxSections')) {
          expect(spec, file).toBe('~/modtools/composables/useUxSections')
        }
      }
    }
    const importers = read
      .filter(({ source }) =>
        importsOf(source).includes('~/modtools/composables/useUxSections')
      )
      .map(({ file }) => file)
    expect(importers).toEqual(['modtools/components/ModSysAdminUxIdeaRow.vue'])
  })

  describe('the check itself', () => {
    it('finds every way of importing another part', () => {
      const src = `
        import a from '~/modtools/stores/uxSurveys'
        import { b } from "~/api/UxExperimentsAPI"
        import '~/modtools/components/ModSysAdminUxProblemGroups.vue'
        import ModSysAdminUxProblem from '~/modtools/components/ModSysAdminUxProblem.vue'
        const c = await import('~/modtools/composables/useUxExperimentRead')
        const d = require('~/modtools/stores/uxReactions')
        import ModSysAdminUxExperimentReaction from '~/modtools/components/ModSysAdminUxExperimentReaction.vue'
      `
      expect(offending(src)).toHaveLength(7)
    })

    it('lets the Ideas files import each other, F, and the libraries', () => {
      const src = `
        import { computed } from 'vue'
        import { useUxIdeasStore } from '~/modtools/stores/uxIdeas'
        import ModSysAdminUxIdeaRow from '~/modtools/components/ModSysAdminUxIdeaRow.vue'
        import ModSysAdminUxProblemIdeas from '~/modtools/components/ModSysAdminUxProblemIdeas.vue'
        import { uxSections } from '~/modtools/composables/useUxSections'
        import { FLOW_ORDER } from '~/modtools/composables/useUxFlows'
      `
      expect(offending(src)).toEqual([])
    })
  })
})
