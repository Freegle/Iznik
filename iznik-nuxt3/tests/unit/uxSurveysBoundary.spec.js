/**
 * The What members say section stands alone (docs/developers/reference/ux-member-surveys.md). It must
 * work when the experiment, idea and reaction parts of the UX tab are absent, so nothing it is made of
 * may import one of their files. This reads each file below, from an explicit list (never a glob that
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

// Everything the What members say section is made of.
const COMPONENTS = [
  'modtools/components/ModSysAdminUxSurveys.vue',
  'modtools/components/ModSysAdminUxProblemGroups.vue',
  'modtools/components/ModSysAdminUxProblem.vue',
  'modtools/components/ModSysAdminUxValues.vue',
  'modtools/components/ModSysAdminUxQuestions.vue',
  'modtools/components/ModSysAdminUxQuestionResult.vue',
  'modtools/components/ModSysAdminUxSaid.vue',
]
const FILES = [
  ...COMPONENTS,
  'modtools/composables/useUxAnswers.js',
  'modtools/stores/uxSurveys.js',
  'api/UxSurveysAPI.js',
]

// The files of the experiment, idea and reaction parts, by the names their imports carry.
const FORBIDDEN = [
  'uxExperiments',
  'uxIdeas',
  'uxReactions',
  'UxExperimentsAPI',
  'UxIdeasAPI',
  'UxReactionsAPI',
  'ModSysAdminUxExperiment',
  'ModSysAdminUxIdea',
  'ModSysAdminUxProblemIdeas',
  'useUxExperiment',
  'useUxIdeaLabels',
  'useUxReaction',
]

// A way of drawing text as markup: the attribute, or the property or call that does the same. A
// comment that says "never v-html" names it without using it, and is not one.
const MARKUP =
  /\bv-html\s*=|\.innerHTML\b|\binnerHTML\s*=|insertAdjacentHTML\s*\(/

// Their tables, which this section must not name either.
const TABLES = [/ux_experiments\b/, /ux_experiment_reads\b/, /ux_ideas\b/]

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

describe('the What members say section stands alone', () => {
  const read = FILES.map((f) => ({
    file: f,
    source: fs.readFileSync(path.join(root, f), 'utf8'),
  }))

  it('read every file on its list, and none was empty', () => {
    // A wrong path would make readFileSync throw, and an empty file would check nothing.
    expect(read).toHaveLength(FILES.length)
    expect(read).toHaveLength(10)
    expect(COMPONENTS).toHaveLength(7)
    for (const { file, source } of read) {
      expect(source.trim().length, file).toBeGreaterThan(0)
    }
  })

  it('finds the imports that are there, so a pattern that finds nothing is noticed', () => {
    const imports = Object.fromEntries(
      read.map(({ file, source }) => [file, importsOf(source)])
    )
    expect(imports['modtools/components/ModSysAdminUxSurveys.vue']).toContain(
      '~/modtools/stores/uxSurveys'
    )
    expect(
      imports['modtools/components/ModSysAdminUxProblemGroups.vue']
    ).toContain('~/modtools/components/ModSysAdminUxProblem.vue')
    expect(imports['modtools/components/ModSysAdminUxProblem.vue']).toContain(
      '~/modtools/composables/useUxSections'
    )
    expect(imports['modtools/stores/uxSurveys.js']).toContain(
      '~/modtools/composables/useUxSlot'
    )
    expect(imports['api/UxSurveysAPI.js']).toContain('@/api/BaseAPI')
    // The figures and sentences are worked out by pure functions that import nothing at all.
    expect(imports['modtools/composables/useUxAnswers.js']).toEqual([])
  })

  it('imports no file of the experiment, idea or reaction parts', () => {
    for (const { file, source } of read) {
      expect(offending(source), file).toEqual([])
    }
  })

  it('names none of their tables', () => {
    for (const { file, source } of read) {
      for (const re of TABLES)
        expect(re.test(source), `${file} ${re}`).toBe(false)
    }
  })

  it('reaches the registry from one place only, the problem detail that draws what other parts add', () => {
    const importers = read
      .filter(({ source }) =>
        importsOf(source).some((s) => s.includes('useUxSections'))
      )
      .map(({ file }) => file)
    expect(importers).toEqual(['modtools/components/ModSysAdminUxProblem.vue'])
  })

  it('draws member text by interpolation only: no v-html and no innerHTML in any component', () => {
    for (const file of COMPONENTS) {
      const source = read.find((r) => r.file === file).source
      expect(source, file).not.toMatch(MARKUP)
    }
  })

  describe('the check itself', () => {
    it('finds every way of importing another part', () => {
      const src = `
        import a from '~/modtools/stores/uxExperiments'
        import { b } from "~/api/UxIdeasAPI"
        import '~/modtools/components/ModSysAdminUxProblemIdeas.vue'
        const c = await import('~/modtools/composables/useUxIdeaLabels')
        const d = require('~/modtools/stores/uxReactions')
        import ModSysAdminUxExperimentRow from '~/modtools/components/ModSysAdminUxExperimentRow.vue'
        import { e } from '~/modtools/composables/useUxExperimentRead'
      `
      expect(offending(src)).toHaveLength(7)
    })

    it('finds text drawn as markup, and not a comment that says not to', () => {
      expect('<p v-html="text" />').toMatch(MARKUP)
      expect('el.innerHTML = text').toMatch(MARKUP)
      expect('const h = el.innerHTML').toMatch(MARKUP)
      expect('el.insertAdjacentHTML("beforeend", text)').toMatch(MARKUP)
      expect(
        '<!-- Member text: interpolated, so it is escaped. Never v-html. -->'
      ).not.toMatch(MARKUP)
      expect('<li>{{ c }}</li>').not.toMatch(MARKUP)
    })

    it('lets the section import its own files, F, and the libraries', () => {
      const src = `
        import { computed } from 'vue'
        import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
        import ModSysAdminUxProblem from '~/modtools/components/ModSysAdminUxProblem.vue'
        import { hostedFor } from '~/modtools/composables/useUxSections'
        import { FLOW_ORDER } from '~/modtools/composables/useUxFlows'
      `
      expect(offending(src)).toEqual([])
    })

    it("does not mistake the section's own problem component for the idea part's piece", () => {
      // ModSysAdminUxProblem, ModSysAdminUxProblemGroups and ModSysAdminUxProblemIdeas share a start.
      // Only the last belongs to another part.
      expect(
        offending(
          `import a from '~/modtools/components/ModSysAdminUxProblem.vue'`
        )
      ).toEqual([])
      expect(
        offending(
          `import a from '~/modtools/components/ModSysAdminUxProblemGroups.vue'`
        )
      ).toEqual([])
      expect(
        offending(
          `import a from '~/modtools/components/ModSysAdminUxProblemIdeas.vue'`
        )
      ).toHaveLength(1)
    })
  })
})
