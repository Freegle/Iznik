import BaseAPI from '@/api/BaseAPI'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'

import { notAHeldConflict } from '~/api/heldConflict'

// A ChitChat moderator posting on a member's behalf names the member here. The
// server checks the caller is a ChitChat moderator and derives that member's
// location and community itself, so this only says WHO the post is for.
function onBehalfOfQuery(userid) {
  return userid ? '?onbehalfof=' + encodeURIComponent(userid) : ''
}

export default class MessageAPI extends BaseAPI {
  fetch(id, logError = true) {
    return this.$getv2('/message/' + id, {}, logError)
  }

  async fetchMT(params, logError = true) {
    const { id, ...rest } = params
    return await this.$getv2('/message/' + id, rest, logError)
  }

  // Actual rippling-out progress of a post, for the moderation reach map (mod-of-group only).
  reach(id, logError = true) {
    return this.$getv2('/message/' + id + '/reach', {}, logError)
  }

  fetchByUser(id, active, logError = true) {
    return this.$getv2(
      '/user/' + id + '/message',
      {
        active: active ? 'true' : 'false',
      },
      logError
    )
  }

  inbounds(swlat, swlng, nelat, nelng, groupid, limit) {
    return this.$getv2('/message/inbounds', {
      swlat,
      swlng,
      nelat,
      nelng,
      groupid,
      limit,
    })
  }

  search(params) {
    return this.$getv2(
      '/message/search/' + encodeURIComponent(params.search),
      params
    )
  }

  // Posts semantically similar to a given post, for the "more like this nearby"
  // recommendation strip. Returns [{id, groupid, score, lat, lng}].
  similar(id, limit) {
    return this.$getv2('/message/' + id + '/similar', limit ? { limit } : {})
  }

  // Existing OFFERs near a location matching a free-text query, for the
  // "people are offering these near you" panel shown while composing a WANTED.
  // Returns [{id, groupid, score, lat, lng}].
  matches(query, lat, lng, limit) {
    return this.$getv2('/message/matches', {
      query,
      lat,
      lng,
      ...(limit ? { limit } : {}),
    })
  }

  mygroups(gid) {
    return this.$getv2('/message/mygroups' + (gid ? '/' + gid : ''))
  }

  fetchMessages(params) {
    return this.$getv2('/modtools/messages', params)
  }

  // Mark auto-published posts (Checked/Trusted oversight queues) as reviewed by a
  // moderator. Pass {groupid, filter} to clear a whole bucket, or {groupid, ids}.
  markChecked(params) {
    return this.$postv2('/modtools/messages/markchecked', params)
  }

  // SysAdmin moderation analytics for a date range ({start, end}).
  moderationStats(params) {
    return this.$getv2('/modtools/moderationstats', params)
  }

  update(event) {
    return this.$postv2('/message', event)
  }

  save(event) {
    return this.$patchv2('/message', event)
  }

  joinAndPost(id, email, options = {}, logError = true) {
    const params = { id, email, action: 'JoinAndPost' }

    // Add optional deadline and deliverypossible params from options
    if (options.deadline) {
      params.deadline = options.deadline
    }
    if (options.deliverypossible !== undefined) {
      params.deliverypossible = options.deliverypossible
    }
    if (options.ai_declined) {
      params.ai_declined = true
    }

    // If options.logError is provided, use it; otherwise use the logError param
    const logErrorFn =
      options.logError !== undefined ? options.logError : logError

    return this.$postv2(
      '/message' + onBehalfOfQuery(options.onbehalfof),
      params,
      logErrorFn
    )
  }

  del(id) {
    return this.$delv2('/message/' + id)
  }

  put(data) {
    // onbehalfof travels in the query string, where the server reads it and
    // checks the caller is a ChitChat moderator. Keep it out of the body so it
    // can't be mistaken for a message field.
    const { onbehalfof, ...body } = data
    return this.$putv2('/message' + onBehalfOfQuery(onbehalfof), body)
  }

  intend(id, outcome) {
    return this.$postv2('/message', {
      action: 'OutcomeIntended',
      id,
      outcome,
    })
  }

  view(id, source) {
    const body = {
      action: 'View',
      id,
    }
    // Optional context tag (e.g. 'browse' vs 'message_page', or a notification
    // ?src= value) so the server can tell a browse-feed view from a detail view.
    // handleView persists it via COALESCE; omit when absent so we never clear it.
    if (source) {
      body.source = source
    }
    return this.$postv2('/message', body)
  }

  // Register interest in one or more items of a bulk offer. `items` is an array
  // of { bulkitemid, quantity, cancollect }. A quantity of 0 withdraws interest
  // in that item.
  // interestuserid lets the offerer record a replier's interest on their behalf;
  // omit it (or pass falsy) to express your own.
  bulkInterest(id, items, interestuserid, comment) {
    return this.$postv2('/message', {
      action: 'BulkInterest',
      id,
      bulkinterest: items,
      ...(interestuserid ? { interestuserid } : {}),
      ...(comment ? { comment } : {}),
    })
  }

  // Offerer/mod transition of one interest row (Interested → Reserved →
  // Collected, or Rejected/Withdrawn).
  bulkInterestState(id, bulkitemid, userid, state) {
    return this.$postv2('/message', {
      action: 'BulkInterestState',
      id,
      bulkitemid,
      userid,
      state,
    })
  }

  // Freegle Helper (AI concierge) state for a bulk offer: batch, per-replier FSM
  // knowledge records with per-item state/score, queued proposals, and the ids of
  // Helper-sent chat messages. Offerer/mod only.
  getHelper(msgid, logError = true) {
    return this.$getv2('/helper/' + msgid, {}, logError)
  }

  // Helper actions. The page uses SetStatus (pause/resume/stop) and
  // ResolveProposal (confirm/edit/send or dismiss); the driver uses the rest.
  helper(payload) {
    return this.$postv2('/helper', payload)
  }

  async getIllustration(item) {
    try {
      const result = await this.$getv2(
        '/illustration',
        { item },
        false // Don't log errors - ret=3 is expected for cache miss
      )

      if (result.illustration) {
        return result.illustration
      }
    } catch (e) {
      // Cache miss or error - no illustration available
    }

    return null
  }

  approve(id, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2(
      '/message',
      {
        action: 'Approve',
        id,
        groupid,
        subject,
        stdmsgid,
        body,
      },
      notAHeldConflict
    )
  }

  reply(id, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2('/message', {
      action: 'Reply',
      id,
      groupid,
      subject,
      stdmsgid,
      body,
    })
  }

  reject(id, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2(
      '/message',
      {
        action: 'Reject',
        id,
        groupid,
        subject,
        stdmsgid,
        body,
      },
      notAHeldConflict
    )
  }

  delete(id, groupid, subject = null, stdmsgid = null, body = null) {
    return this.$postv2(
      '/message',
      {
        action: 'Delete',
        id,
        groupid,
        subject,
        stdmsgid,
        body,
      },
      notAHeldConflict
    )
  }

  spam(id, groupid) {
    return this.$postv2(
      '/message',
      {
        action: 'Spam',
        id,
        groupid,
      },
      notAHeldConflict
    )
  }

  hold(id, groupid) {
    return this.$postv2(
      '/message',
      {
        action: 'Hold',
        id,
        groupid,
      },
      notAHeldConflict
    )
  }

  release(id, groupid) {
    return this.$postv2('/message', {
      action: 'Release',
      id,
      groupid,
    })
  }

  approveEdits(id) {
    return this.$postv2('/message', {
      action: 'ApproveEdits',
      id,
    })
  }

  revertEdits(id) {
    return this.$postv2('/message', {
      action: 'RevertEdits',
      id,
    })
  }

  partnerConsent(id, partner) {
    return this.$postv2('/message', {
      action: 'PartnerConsent',
      id,
      partner,
    })
  }

  addBy(id, userid, count) {
    return this.$postv2('/message', {
      action: 'AddBy',
      id,
      userid,
      count,
    })
  }

  removeBy(id, userid) {
    return this.$postv2('/message', {
      action: 'RemoveBy',
      id,
      userid,
    })
  }

  async count(browseView, maxDistance, log) {
    const params = {
      browseView,
    }

    // Only send a distance limit when it's a real limit - the sentinel (or absent)
    // means "no limit", and omitting it lets the server fall back to its own fast,
    // unfiltered count rather than doing extra work for a limit that doesn't apply.
    if (maxDistance != null && maxDistance < BROWSE_DISTANCE_UNLIMITED) {
      params.maxDistance = maxDistance
    }

    return await this.$getv2('/message/count', params, log)
  }

  async markSeen(ids, source) {
    const body = { ids }
    // Optional impression source tag (e.g. 'similar_posts') so a recommendation
    // widget's impressions can be measured. Omitted for ordinary browse views.
    if (source) {
      body.source = source
    }
    return await this.$postv2('/messages/markseen', body, false)
  }

  // --- Bulk-offer ("clearance") logged-out update link ---------------------

  // Mint (or fetch the existing) secret link that lets an external owner update
  // this bulk offer's item availability/counts without logging in. Owner/mod only.
  async bulkEditLink(id) {
    return await this.$postv2('/message', { id, action: 'BulkEditLink' })
  }

  // Load a bulk offer's catalogue from a secret update-link token (logged out).
  // logError defaults to false so a stale/typo'd link doesn't spam Sentry.
  async fetchBulkEditOffer(token, logError = false) {
    return await this.$getv2(
      '/bulkoffer/update/' + encodeURIComponent(token),
      {},
      logError
    )
  }

  // Update one item's availability and/or count from the logged-out page.
  async updateBulkEditItem(token, itemid, changes) {
    return await this.$postv2(
      '/bulkoffer/update/' + encodeURIComponent(token),
      {
        itemid,
        ...changes,
      }
    )
  }
}
