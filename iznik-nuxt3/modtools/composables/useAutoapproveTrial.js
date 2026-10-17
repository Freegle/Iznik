import { computed, toValue } from 'vue'
import { useAuthStore } from '~/stores/auth'

function sameGroup(a, b) {
  return parseInt(a) === parseInt(b)
}

// Post-moderation (self-publishing posts, the countdown, the Check queue) runs only on the
// communities in the trial. The session marks each of those memberships autoapprovetrial;
// anything that only makes sense under post-moderation shows only where a moderator has one.
//
// `groupid` (a number, ref or getter; 0 or absent means "any of my communities") narrows
// inTrial to one community: a help box on a community outside the trial must not describe
// a countdown that community will never show, even to a moderator who also runs a trial
// community.
export function useAutoapproveTrial(groupid = null) {
  const authStore = useAuthStore()
  const groups = () => authStore.groups || []
  const isTrialGroup = (gid) =>
    groups().some((g) => sameGroup(g.groupid, gid) && g.autoapprovetrial)
  const inTrial = computed(() => {
    const gid = parseInt(toValue(groupid)) || 0
    return gid ? isTrialGroup(gid) : groups().some((g) => g.autoapprovetrial)
  })
  // Every community of mine in the trial, whatever community is asked about: the Check page
  // offers these and no others, because no other community has a Check queue.
  const trialGroupIds = computed(() =>
    groups()
      .filter((g) => g.autoapprovetrial)
      .map((g) => parseInt(g.groupid))
  )
  return { inTrial, isTrialGroup, trialGroupIds }
}

// Automated review (the flowchart) runs on a wider set of communities than autoapprove
// itself: shadow-mode groups record a decision with no visible effect, and approve-trial
// groups also auto-publish. The session marks either kind of membership automod, so
// anything that only makes sense with a decision to show (the compact line, the modal)
// should show only when a moderator has one of those.
export function useAutomod() {
  const authStore = useAuthStore()
  const isAutomodGroup = (groupid) =>
    (authStore.groups || []).some(
      (g) => sameGroup(g.groupid, groupid) && g.automod
    )
  return { isAutomodGroup }
}
