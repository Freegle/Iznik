import BaseAPI from '@/api/BaseAPI'
import { notAHeldConflict } from '~/api/heldConflict'
import { notABannedFailure } from '~/api/bannedFailure'

export default class MembershipsAPI extends BaseAPI {
  update(data) {
    return this.$patchv2('/memberships', data)
  }

  joinGroup(data) {
    // A banned member's join is refused with 403 "Failed - banned" - expected, so
    // don't log it to Sentry (the caller handles it).
    return this.$putv2('/memberships', data, notABannedFailure)
  }

  leaveGroup(data) {
    return this.$delv2('/memberships', data)
  }

  async fetch(params, logError = true) {
    const members = await this.$getv2('/memberships', params, logError)
    const member = Array.isArray(members) ? members[0] : members
    return { member }
  }

  async fetchMembers(params) {
    const ret = await this.$getv2('/memberships', params)
    // Related collection returns a raw array; all other collections return
    // { members, context?, ratings?, filtercount? }.
    if (Array.isArray(ret)) {
      return { members: ret, context: null, ratings: [], filtercount: null }
    }
    return {
      members: ret?.members ?? [],
      context: ret?.context ?? null,
      ratings: ret?.ratings ?? [],
      filtercount: ret?.filtercount ?? null,
    }
  }

  save(event) {
    return this.$patchv2('/memberships', event)
  }

  del(id) {
    return this.$delv2('/memberships', { id })
  }

  put(data) {
    // Used by the moderator "Add member" flow; a banned target returns 403
    // "Failed - banned" - expected, so keep it out of Sentry (the modal surfaces it).
    return this.$putv2('/memberships', data, notABannedFailure)
  }

  approveMember(userid, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2(
      '/memberships',
      {
        action: 'Approve',
        userid,
        groupid,
        subject,
        stdmsgid,
        body,
      },
      notAHeldConflict
    )
  }

  rejectMember(userid, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2(
      '/memberships',
      {
        action: 'Reject',
        userid,
        groupid,
        subject,
        stdmsgid,
        body,
      },
      notAHeldConflict
    )
  }

  reply(userid, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2('/memberships', {
      action: 'Leave Approved Member',
      userid,
      groupid,
      subject,
      stdmsgid,
      body,
    })
  }

  delete(userid, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2(
      '/memberships',
      {
        action: 'Delete Approved Member',
        userid,
        groupid,
        subject,
        stdmsgid,
        body,
      },
      notAHeldConflict
    )
  }

  remove(userid, groupid) {
    return this.$delv2('/memberships', {
      userid,
      groupid,
    })
  }

  ban(userid, groupid) {
    return this.$delv2('/memberships', {
      userid,
      groupid,
      ban: true,
    })
  }

  unban(userid, groupid) {
    return this.$postv2('/memberships', {
      userid,
      groupid,
      action: 'Unban',
    })
  }

  hold(userid, groupid) {
    return this.$postv2('/memberships', {
      action: 'Hold',
      userid,
      groupid,
    })
  }

  release(userid, groupid) {
    return this.$postv2('/memberships', {
      action: 'Release',
      userid,
      groupid,
    })
  }

  reviewIgnore(userid, groupid) {
    return this.$postv2('/memberships', {
      action: 'ReviewIgnore',
      userid,
      groupid,
    })
  }

  reviewHold(userid, groupid) {
    return this.$postv2('/memberships', {
      action: 'ReviewHold',
      userid,
      groupid,
    })
  }

  reviewRelease(userid, groupid) {
    return this.$postv2('/memberships', {
      action: 'ReviewRelease',
      userid,
      groupid,
    })
  }

  happinessReviewed(params) {
    return this.$postv2('/memberships', params)
  }
}
