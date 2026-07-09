import { useMiscStore } from '~/stores/misc'
import { useAuthStore } from '~/stores/auth'
import { useNuxtApp, useRoute } from '#imports'

// Experiment: on mobile, offer a voice-vs-keyboard choice for composing an OFFER
// instead of going straight to the typed form, and measure whether it helps.
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

// Percentage of eligible (mobile) users shown the voice option. Start at 0 and
// raise to roll the experiment out to a slice of traffic. Per-visit override:
// ?voice=1 forces the voice variant, ?voice=0 forces control (handy for demos).
const ROLLOUT_PCT = 10

export function useComposeChoice() {
  const miscStore = useMiscStore()
  const authStore = useAuthStore()
  const route = useRoute()
  const { $api } = useNuxtApp()

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
    const q = route?.query?.voice
    if (q === '1' || q === '0') return true
    return ROLLOUT_PCT > 0
  }

  // Returns 'voice' or 'control'.
  function assign() {
    const q = route?.query?.voice
    if (q === '1') return 'voice'
    if (q === '0') return 'control'
    if (!isMobile()) return 'control'
    return bucket() < ROLLOUT_PCT ? 'voice' : 'control'
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
    experimentActive,
    assign,
    recordShown,
    recordConversion,
    recordMethodShown,
    recordMethodChosen,
  }
}
