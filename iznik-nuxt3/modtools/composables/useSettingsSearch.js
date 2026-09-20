import { computed, unref } from 'vue'
import { SETTINGS_INDEX, SETTINGS_TABS } from '~/modtools/utils/settingsIndex'

const MAX_RESULTS = 12

/**
 * Score a single setting against one search term.
 *
 * Label matches beat description matches, so that searching "spam" surfaces
 * the setting actually called "Spam Keywords" above the dozen others that
 * merely mention spam in their explanatory text.
 */
function scoreTerm(setting, term) {
  const label = setting.label.toLowerCase()

  if (label === term) return 100
  if (label.startsWith(term)) return 80

  // Match at a word boundary within the label - "posts" should rank higher in
  // "Expire posts" than in a description that happens to say "posts".
  if (new RegExp(`\\b${escapeRegExp(term)}`).test(label)) return 70
  if (label.includes(term)) return 55

  if (setting.keywords?.some((k) => k.toLowerCase().includes(term))) return 50
  if (setting.sectionLabel?.toLowerCase().includes(term)) return 30
  if (setting.description?.toLowerCase().includes(term)) return 20

  return 0
}

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Search the settings index.
 *
 * Every term must match something, so "spam member" narrows rather than
 * widens. A setting's score is the sum of its per-term scores, which favours
 * settings matching several terms in their label over ones scraping a match
 * from a long description.
 */
export function searchSettings(query) {
  const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean)

  if (!terms.length) return []

  return SETTINGS_INDEX.map((setting) => {
    let total = 0

    for (const term of terms) {
      const score = scoreTerm(setting, term)

      // A term that matches nothing disqualifies the setting entirely.
      if (!score) return null

      total += score
    }

    return { setting, score: total }
  })
    .filter(Boolean)
    .sort(
      (a, b) =>
        b.score - a.score || a.setting.label.localeCompare(b.setting.label)
    )
    .slice(0, MAX_RESULTS)
    .map((r) => ({
      ...r.setting,
      tabLabel: SETTINGS_TABS[r.setting.tab]?.label ?? r.setting.tab,
    }))
}

export function useSettingsSearch(query) {
  const results = computed(() => searchSettings(unref(query) || ''))

  return { results }
}

const HIGHLIGHT_CLASS = 'setting-found'
const HIGHLIGHT_MS = 3000
const REVEAL_TIMEOUT_MS = 2000
const SETTLED_FRAMES = 3

/**
 * Wait for a setting to appear and stop moving.
 *
 * The accordion section containing it animates open, which shifts everything
 * below it for a few hundred milliseconds. Scrolling to the setting before
 * that finishes lands somewhere else entirely - usually the top of the
 * section - so wait until its position is unchanged for a few frames.
 */
function waitForSetting(id) {
  const selector = `[data-setting-id="${CSS.escape(id)}"]`

  return new Promise((resolve) => {
    const deadline = Date.now() + REVEAL_TIMEOUT_MS
    let lastTop = null
    let stableFor = 0

    const poll = () => {
      const el = document.querySelector(selector)
      const rect = el?.getBoundingClientRect()

      if (rect?.height) {
        stableFor = rect.top === lastTop ? stableFor + 1 : 0
        lastTop = rect.top

        if (stableFor >= SETTLED_FRAMES) {
          resolve(el)
          return
        }
      }

      if (Date.now() > deadline) {
        // Out of time. Better to scroll to a still-moving target than to
        // report the setting as missing and tell the user to pick a group.
        resolve(el?.getBoundingClientRect().height ? el : null)
        return
      }

      requestAnimationFrame(poll)
    }

    poll()
  })
}

/**
 * Scroll a setting into view and flash it, so it's obvious which of the
 * controls on a crowded panel was the one searched for.
 *
 * Returns false if the setting isn't in the DOM - which happens on the
 * Community tab when no group has been selected yet, so the caller can say
 * something useful rather than appearing to do nothing.
 */
export async function revealSetting(id) {
  const el = await waitForSetting(id)

  if (!el) return false

  el.scrollIntoView({ behavior: 'smooth', block: 'center' })

  // Restart the animation if the same setting is picked twice in a row -
  // reading offsetWidth forces the reflow that makes the browser notice.
  el.classList.remove(HIGHLIGHT_CLASS)
  el.offsetWidth
  el.classList.add(HIGHLIGHT_CLASS)

  setTimeout(() => el.classList.remove(HIGHLIGHT_CLASS), HIGHLIGHT_MS)

  return true
}
