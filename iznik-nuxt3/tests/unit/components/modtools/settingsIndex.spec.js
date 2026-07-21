import fs from 'fs'
import path from 'path'
import { describe, it, expect } from 'vitest'
import { SETTINGS_INDEX } from '~/modtools/utils/settingsIndex'

/**
 * The settings search index is declared by hand, separately from the templates
 * that render the settings, because the Community settings don't exist in the
 * DOM until a group is selected and so can't be discovered at runtime.
 *
 * That separation is what these tests guard: add a setting to a tab and it must
 * be indexed, or it won't be findable by search and nobody will notice.
 */

const COMPONENTS = path.resolve(__dirname, '../../../../modtools/components')

function read(file) {
  return fs.readFileSync(path.join(COMPONENTS, file), 'utf8')
}

function attr(tag, name) {
  const m = tag.match(new RegExp(`(?:^|\\s):?${name}="([^"]*)"`, 's'))
  return m ? m[1].replace(/\s+/g, ' ').trim() : null
}

// Pull out each opening tag for a component, coping with multi-line props.
function openingTags(src, tag) {
  const out = []
  const re = new RegExp(`<${tag}\\b`, 'g')
  let m

  while ((m = re.exec(src))) {
    let depth = 0

    for (let j = m.index; j < src.length; j++) {
      if (src[j] === '"') {
        j = src.indexOf('"', j + 1)
        continue
      }
      if (src[j] === '<') depth++
      if (src[j] === '>') {
        depth--
        if (depth === 0) {
          out.push(src.slice(m.index, j + 1))
          break
        }
      }
    }
  }

  return out
}

const indexById = new Map(SETTINGS_INDEX.map((s) => [s.id, s]))

describe('settings search index', () => {
  it('has no duplicate ids', () => {
    expect(indexById.size).toBe(SETTINGS_INDEX.length)
  })

  it('gives every entry a label and a known tab', () => {
    for (const setting of SETTINGS_INDEX) {
      expect(setting.label, `${setting.id} needs a label`).toBeTruthy()
      expect(['personal', 'community', 'stdmsgs']).toContain(setting.tab)
    }
  })

  // ModGroupSetting and ModConfigSetting render data-setting-id from their
  // `name` prop, so the name at the call site is the id search jumps to.
  it.each([
    ['ModSettingsGroup.vue', 'ModGroupSetting', 'community'],
    ['ModSettingsModConfig.vue', 'ModConfigSetting', 'stdmsgs'],
  ])('indexes every setting in %s', (file, component, tab) => {
    const missing = []

    for (const tag of openingTags(read(file), component)) {
      const name = attr(tag, 'name')
      const label = attr(tag, 'label')

      if (!name || !label) continue

      const entry = indexById.get(name)

      if (!entry) {
        missing.push(`${name} ("${label}")`)
      } else {
        expect(entry.label, `${name} label out of step with template`).toBe(
          label
        )
        expect(entry.tab).toBe(tab)
      }
    }

    expect(missing, `Not in SETTINGS_INDEX: ${missing.join(', ')}`).toEqual([])
  })

  // Settings built from a plain b-form-group need an explicit data-setting-id,
  // since there's no `name` prop to derive one from.
  it.each([
    ['ModSettingsPersonal.vue', 'personal'],
    ['ModSettingsGroup.vue', 'community'],
  ])('indexes every plain b-form-group in %s', (file, tab) => {
    const unanchored = []
    const missing = []

    for (const tag of openingTags(read(file), 'b-form-group')) {
      const label = attr(tag, 'label')

      if (!label) continue

      const id = attr(tag, 'data-setting-id')

      if (!id) {
        unanchored.push(label)
        continue
      }

      const entry = indexById.get(id)

      if (!entry) {
        missing.push(`${id} ("${label}")`)
      } else {
        expect(entry.label).toBe(label)
        expect(entry.tab).toBe(tab)
      }
    }

    expect(
      unanchored,
      `Needs data-setting-id: ${unanchored.join(', ')}`
    ).toEqual([])
    expect(missing, `Not in SETTINGS_INDEX: ${missing.join(', ')}`).toEqual([])
  })

  it('points every community entry at a section that exists', () => {
    const src = read('ModSettingsGroup.vue')
    const sections = new Map(
      openingTags(src, 'ModSettingsSection').map((tag) => [
        attr(tag, 'id'),
        attr(tag, 'title'),
      ])
    )

    for (const setting of SETTINGS_INDEX.filter((s) => s.tab === 'community')) {
      expect(
        sections.has(setting.section),
        `${setting.id} points at unknown section ${setting.section}`
      ).toBe(true)

      // A result offering to take you to a section named something the page
      // doesn't call it is worse than no result at all.
      expect(setting.sectionLabel).toBe(sections.get(setting.section))
    }
  })
})
