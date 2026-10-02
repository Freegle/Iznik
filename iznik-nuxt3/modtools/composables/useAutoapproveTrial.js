import { computed } from 'vue'
import { useAuthStore } from '~/stores/auth'

// Post-moderation (self-publishing posts, the countdown, the Check queue) runs only on the
// communities in the trial. The session marks each of those memberships autoapprovetrial;
// anything that only makes sense under post-moderation shows only when a moderator has one.
export function useAutoapproveTrial() {
  const authStore = useAuthStore()
  const inTrial = computed(() =>
    (authStore.groups || []).some((g) => g.autoapprovetrial)
  )
  return { inTrial }
}

// Automated review (the flowchart) runs on a wider set of communities than autoapprove
// itself: shadow-mode groups record a decision with no visible effect, and approve-trial
// groups also auto-publish. The session marks either kind of membership automod, so
// anything that only makes sense with a decision to show (the compact line, the modal)
// should show only when a moderator has one of those.
export function useAutomod() {
  const authStore = useAuthStore()
  const isAutomodGroup = (groupid) =>
    (authStore.groups || []).some((g) => g.groupid === groupid && g.automod)
  return { isAutomodGroup }
}
