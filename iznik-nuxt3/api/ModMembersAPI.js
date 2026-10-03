import BaseAPI from '@/api/BaseAPI'
import { notAHeldConflict } from '~/api/heldConflict'

// Self-moderating rework: moderators are national (users.systemrole
// Moderator/Support/Admin), so every method here is unscoped by community.
// Replaces the groupid-scoped surface of api/MembershipsAPI.js (deleted) for
// the endpoints modtools-rework.md's "Member tools contract" keeps. Backs
// modtools/stores/member.js and the home page's New/Flagged
// members and Completed freegles sections.
export default class ModMembersAPI extends BaseAPI {
  // params.filter: 'new' | 'flagged' | 'banned' | 'search'; params.q for
  // search; params.since (hours) for new/flagged.
  fetch(params) {
    return this.$getv2('/modtools/members', params)
  }

  fetchOne(id, logError = true) {
    return this.$getv2('/modtools/members/' + id, {}, logError)
  }

  ban(id, reason) {
    return this.$postv2(
      '/modtools/members/' + id + '/ban',
      { reason },
      notAHeldConflict
    )
  }

  unban(id) {
    return this.$delv2('/modtools/members/' + id + '/ban')
  }

  setPostingStatus(id, postingstatus) {
    return this.$patchv2('/modtools/members/' + id, { postingstatus })
  }

  clearFlag(id) {
    return this.$postv2('/modtools/members/' + id + '/flag/clear')
  }

  // Completed freegles: Taken/Received outcomes, two members per row.
  fetchOutcomes(params) {
    return this.$getv2('/modtools/outcomes', params)
  }

  reviewOutcome(id) {
    return this.$patchv2('/modtools/outcomes/' + id, { reviewed: true })
  }
}
