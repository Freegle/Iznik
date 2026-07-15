import { useMiscStore } from '~/stores/misc'
import { useAuthStore } from '~/stores/auth'
import { useMobileStore } from '~/stores/mobile'
import { useConfigStore } from '~/stores/config'
import { useNuxtApp, useRoute } from '#imports'

// Experiment: on mobile WEB, offer a voice-vs-keyboard choice for composing an
// OFFER instead of going straight to the typed form, and measure whether it helps.
// Web only - the native app is a separate release channel and is excluded (see the
// mobileStore.isApp guards below).
//
// Assignment is a fixed per-user % bucket (a clean A/B holdout, not the adaptive
// bandit) so a stable slice of mobile users sees the voice option. Exposure and
// completion are still recorded through the existing bandit endpoints
// (uid = COMPOSE_CHOICE_UID) so the abtest table accumulates shown/action rates
// per variant that we can compare.
// Exposure experiment: did we show the choice variant, and did they complete a post?
export const COMPOSE_CHOICE_UID = 'mobile-compose-variant'
// Method measurement: of those offered the choice, did they pick voice or keyboard?
export const COMPOSE_METHOD_UID = 'mobile-compose-method'

// The compose flow spans several route changes and BOTH arms converge on the final
// "Freegle it!" step (give/mobile/whereami), which is what actually creates the post.
// The assigned variant is stashed here at entry so that shared step records the
// conversion for both arms at the SAME funnel point. Without it, control never gets a
// conversion (its typed flow doesn't know about the experiment) and voice was recorded
// too early — at review-finish, before the post exists.
const CONVERSION_PENDING_KEY = 'compose-experiment-variant'

// Percentage of eligible (mobile) users shown the voice option. Read from SERVER
// config at runtime (key `voicepost_rollout_pct`) so the rollout can be raised or
// lowered WITHOUT a new frontend build/deploy: loadRollout() fetches it (cached in
// the config store) and the synchronous assign()/experimentActive() read the
// cached value. Falls back to DEFAULT_ROLLOUT_PCT when the key isn't set or the
// fetch fails. Per-visit override: ?voice=1 forces voice, ?voice=0 forces control.
const ROLLOUT_CONFIG_KEY = 'voicepost_rollout_pct'
const DEFAULT_ROLLOUT_PCT = 10
let rolloutPct = DEFAULT_ROLLOUT_PCT

export function useComposeChoice() {
  const miscStore = useMiscStore()
  const authStore = useAuthStore()
  const mobileStore = useMobileStore()
  const route = useRoute()
  const { $api } = useNuxtApp()
  const configStore = useConfigStore()

  // Fetch the runtime rollout % from server config (cached in the config store).
  // Call before experimentActive()/assign() so they read the live value; keeps
  // the last-known / default rollout if the fetch fails so it never breaks the
  // compose entry.
  async function loadRollout() {
    try {
      const rows = await configStore.fetch(ROLLOUT_CONFIG_KEY)
      if (rows?.length) {
        const n = parseInt(rows[0].value, 10)
        if (!Number.isNaN(n)) rolloutPct = Math.max(0, Math.min(100, n))
      }
    } catch (e) {
      // Non-fatal: fall back to the last-known / default rollout.
    }
  }

  const isMobile = () => ['xs', 'sm', 'md'].includes(miscStore.breakpoint)

  // Deterministic 0..99 bucket per user (FNV-1a hash) so a given user always
  // gets the same experience across visits.
  function bucket() {
    const id = authStore.user?.id || 0
    let h = 2166136261
    const s = 'voice:' + id
    for (let i = 0; i < s.length; i++) {
      h ^= s.charCodeAt(i)
      h = Math.imul(h, 16777619)
    }
    return Math.abs(h) % 100
  }

  // Whether the experiment is live at all. When it's off (the default), the
  // compose entry keeps its original behaviour with zero side effects - so
  // merging this changes nothing for existing users until ROLLOUT_PCT is raised.
  function experimentActive() {
    // Web-only: never run the experiment inside the native app, even with ?voice.
    if (mobileStore.isApp) return false
    const q = route?.query?.voice
    if (q === '1' || q === '0') return true
    return rolloutPct > 0
  }

  // Returns 'voice' or 'control'.
  function assign() {
    // Web-only: the native app never sees the voice variant.
    if (mobileStore.isApp) return 'control'
    const q = route?.query?.voice
    if (q === '1') return 'voice'
    if (q === '0') return 'control'
    if (!isMobile()) return 'control'
    return bucket() < rolloutPct ? 'voice' : 'control'
  }

  // Record that the user was put in a variant (exposure).
  function recordShown(variant) {
    try {
      $api.bandit.shown({ uid: COMPOSE_CHOICE_UID, variant })
    } catch (e) {
      // Tracking must never block the compose flow.
    }
  }

  // Record a completed post for a variant (conversion).
  function recordConversion(variant, score) {
    try {
      $api.bandit.chosen({ uid: COMPOSE_CHOICE_UID, variant, score })
    } catch (e) {
      // Tracking must never block the compose flow.
    }
  }

  // Stash the assigned variant at compose entry so the shared final step can record
  // the conversion for whichever arm the user is in. Session-scoped and per-tab.
  function markConversionPending(variant) {
    try {
      sessionStorage.setItem(CONVERSION_PENDING_KEY, variant)
    } catch (e) {
      // No sessionStorage (SSR/private mode) just means no conversion row — non-fatal.
    }
  }

  // Record a completed post for whichever variant is pending, then clear it. A no-op
  // when the user didn't enter through the experiment (no marker), so it is safe to
  // call unconditionally from the shared final "Freegle it!" step.
  function recordConversionIfPending(score) {
    try {
      const variant = sessionStorage.getItem(CONVERSION_PENDING_KEY)
      if (variant) {
        sessionStorage.removeItem(CONVERSION_PENDING_KEY)
        recordConversion(variant, score)
      }
    } catch (e) {
      // Non-fatal: never block the compose flow on tracking.
    }
  }

  // Record that the voice/keyboard choice was presented (both methods) so the
  // pick-rate per method is comparable.
  function recordMethodShown() {
    try {
      $api.bandit.shown({ uid: COMPOSE_METHOD_UID, variant: 'voice' })
      $api.bandit.shown({ uid: COMPOSE_METHOD_UID, variant: 'keyboard' })
    } catch (e) {
      // Tracking must never block the compose flow.
    }
  }

  // Record which method they picked ('voice' or 'keyboard').
  function recordMethodChosen(method) {
    try {
      $api.bandit.chosen({ uid: COMPOSE_METHOD_UID, variant: method })
    } catch (e) {
      // Tracking must never block the compose flow.
    }
  }

  return {
    COMPOSE_CHOICE_UID,
    COMPOSE_METHOD_UID,
    isMobile,
    loadRollout,
    experimentActive,
    assign,
    recordShown,
    recordConversion,
    markConversionPending,
    recordConversionIfPending,
    recordMethodShown,
    recordMethodChosen,
  }
}
