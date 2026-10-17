import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// The funnel views read the rollup tables through the UX read API and nothing else. This reads their
// files as text and fails on a name that ties them to another part of the UX work: its tables, its
// components, its stores or its API classes. It forbids names, never plain words: the labels "By
// experiment version" and "Not in an experiment" are the funnel views' own.
//
// The files are listed by hand, never found by a glob that could also match another part's files.
// useUxSections.js is left out on purpose: it is the registry, and holds every section's entry.
const ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../modtools'
)

const FILES = [
  'components/ModSysAdminUx.vue',
  'components/ModSysAdminUxNav.vue',
  'components/ModSysAdminUxFilters.vue',
  'components/ModSysAdminUxOverview.vue',
  'components/ModSysAdminUxFunnelTable.vue',
  'components/ModSysAdminUxFunnelPicker.vue',
  'components/ModSysAdminUxFunnels.vue',
  'components/ModSysAdminUxFunnelChart.vue',
  'components/ModSysAdminUxPages.vue',
  'components/ModSysAdminUxPanel.vue',
  'components/ModSysAdminUxHealth.vue',
  'components/ModSysAdminUxFriction.vue',
  'components/ModSysAdminUxAdoption.vue',
  'components/ModSysAdminUxRegret.vue',
  'components/ModSysAdminUxSearch.vue',
  'components/ModSysAdminUxSecondThoughts.vue',
  'components/ModSysAdminUxHelp.vue',
  'components/ModSysAdminUxSamples.vue',
  'composables/useUxView.js',
  'composables/useUxSlot.js',
  'composables/useUxFlows.js',
  'composables/useUxFunnel.js',
  'composables/useUxFormat.js',
  'stores/ux.js',
]

const FORBIDDEN = [
  // Tables that belong to other parts of the UX work.
  /ux_experiments\b/,
  /ux_experiment_reads\b/,
  /ux_prompts\b/,
  /ux_prompt_responses\b/,
  /ux_problems\b/,
  /ux_ideas\b/,
  // Their components.
  /ModSysAdminUx(Experiment|Surveys|ProblemGroups|Problem|Values|Question|Said|Idea)/,
  // Their stores.
  /\buxExperiments\b|\buxSurveys\b|\buxIdeas\b|\buxReactions\b/,
  // Their API classes.
  /Ux(Experiments|Surveys|Ideas|Reactions)API/,
]

describe('the funnel views stand alone', () => {
  const read = FILES.map((f) => ({
    f,
    text: fs.readFileSync(path.join(ROOT, f), 'utf8'),
  }))

  it('read every file it lists, and none was empty', () => {
    // A wrong path would throw above, and an empty file would make the checks below mean nothing.
    expect(read).toHaveLength(FILES.length)
    expect(FILES).toHaveLength(24)
    for (const { f, text } of read) expect(text.length, f).toBeGreaterThan(0)
  })

  it.each(FORBIDDEN.map((re) => [String(re), re]))(
    'names nothing that matches %s',
    (_, re) => {
      for (const { f, text } of read) expect(re.test(text), f).toBe(false)
    }
  )

  it('is allowed its own words about experiments', () => {
    const funnels = read.find(
      (r) => r.f === 'components/ModSysAdminUxFunnels.vue'
    ).text
    expect(funnels).toContain('By experiment version')
    expect(funnels).toContain('Not in an experiment')
  })

  it('has no component that imports the registry or the address helper, which would be a circular import', () => {
    for (const { f, text } of read.filter((r) =>
      r.f.startsWith('components/')
    )) {
      if (f === 'components/ModSysAdminUx.vue') continue
      expect(text, f).not.toMatch(
        /composables\/useUxSections|composables\/useUxView/
      )
    }
  })
})
