import { defineStore } from 'pinia'
import { nextTick } from 'vue'
import api from '~/api'
import { APIError } from '~/api/APIErrors'
import { useAuthStore } from '~/stores/auth'
import { useUserStore } from '~/stores/user'
import { useNearbyStore } from '~/stores/nearby'
import { useGroupStore } from '~/stores/group'
import { useMiscStore } from '~/stores/misc'
import {
  prewarmRoadDistances,
  roadAnswersVersion,
} from '~/composables/useDriveDistance'

// Debounce delay for batching message fetches (ms)
const BATCH_DELAY = 50

// Refetch a cached message after this long, in case its state has changed.
const CACHE_TTL_SECONDS = 600

export const useMessageStore = defineStore('message', {
  state: () => ({
    list: {},
    byUserList: {},

    // Count of unseen items
    count: 0,

    // In bounds
    bounds: {},
    activePostsCounter: 0,

    // The most recent "all my communities" (mygroups) feed, kept so the distance slider can
    // scale its range to these posts' distances - the nearby store is empty on this view, so
    // without this the slider max collapsed to its floor and mis-scaled on mygroups.
    myGroupsList: [],

    // The context from the last fetch, used for fetchMore (ModTools)
    context: null,

    // Freegle Helper (AI concierge) state per bulk-offer msgid:
    // { batch, repliers, proposals, sent }. Loaded by the clearance management page.
    helper: {},
  }),
  actions: {
    init(config) {
      this.config = config
      // Messages we're in the process of fetching
      this.fetching = {}
      this.fetchingCount = 0
      this.fetchingMyGroups = null
      // Debounced batching state
      this.pendingFetches = []
      this.batchTimer = null
      // ModTools context
      this.context = null
    },
    async fetchCount(browseView, maxDistance, log = true) {
      const ret = await api(this.config).message.count(
        browseView,
        maxDistance,
        log
      )
      this.count = ret?.count || 0
      return this.count
    },
    // Whether a cached entry is old enough to be refetched. Every "do we need to
    // go to the API" decision must use this - fetch(), processMessageBatch() and
    // fetchMultiple() each make that call independently, and if they disagree an
    // id can be routed into a batch by one and silently dropped by the next.
    expired(id, now = Math.round(Date.now() / 1000)) {
      const addedToCache = this.list[id]?.addedToCache
      return addedToCache ? now - addedToCache > CACHE_TTL_SECONDS : false
    },
    async fetch(id, force) {
      id = parseInt(id)

      const expired = this.expired(id)

      // If already cached and not forcing/expired, return immediately
      if (!force && this.list[id] && !expired) {
        return this.list[id]
      }

      // If already fetching this ID, wait for the existing fetch
      if (this.fetching[id]) {
        try {
          await this.fetching[id]
          await nextTick()
        } catch (e) {
          this.handleFetchError(id, e)
        }
        return this.list[id]
      }

      // Add to pending batch and wait for the batch to complete
      return new Promise((resolve, reject) => {
        this.pendingFetches.push({ id, resolve, reject, force })

        // Clear existing timer and set a new one
        if (this.batchTimer) {
          clearTimeout(this.batchTimer)
        }

        this.batchTimer = setTimeout(() => {
          this.processMessageBatch()
        }, BATCH_DELAY)
      })
    },
    handleFetchError(id, e) {
      console.log('Failed to fetch message', e)
      if (e instanceof APIError && e.response.status === 404) {
        console.log('Message deleted, removing from store')
        delete this.list[id]

        const authStore = useAuthStore()
        const userUid = authStore.user?.id
        if (userUid && this.byUserList[userUid]) {
          this.byUserList[userUid] = this.byUserList[userUid].filter(
            (message) => message.id !== id
          )
        }
      } else {
        throw e
      }
    },
    async processMessageBatch() {
      if (!this.pendingFetches.length) {
        return
      }

      // Collect all pending fetches
      const pending = [...this.pendingFetches]
      this.pendingFetches = []
      this.batchTimer = null

      // Filter out IDs that are already cached (unless force/expired)
      const idsToFetch = []
      const forcedIds = new Set()
      const now = Math.round(Date.now() / 1000)

      for (const { id, force } of pending) {
        if (
          (force || !this.list[id] || this.expired(id, now)) &&
          !this.fetching[id]
        ) {
          if (!idsToFetch.includes(id)) {
            idsToFetch.push(id)
          }
          if (force) {
            forcedIds.add(id)
          }
        }
      }

      if (idsToFetch.length > 0) {
        try {
          // Pass force=true if any of the IDs were force-requested
          await this.fetchMultiple(idsToFetch, forcedIds.size > 0)
        } catch (e) {
          // Errors are handled per-message in fetchMultiple
          console.log('Batch fetch error', e)
        }
      }

      // Resolve all promises with the cached data
      for (const { id, resolve } of pending) {
        resolve(this.list[id])
      }
    },
    async fetchMultiple(ids, force) {
      const left = []
      const now = Math.round(Date.now() / 1000)

      ids.forEach((id) => {
        id = parseInt(id)

        if (
          (force || !this.list[id] || this.expired(id, now)) &&
          !this.fetching[id]
        ) {
          left.push(id)
        }
      })

      if (left.length) {
        // Split into chunks of 19 (API limit is < 20)
        const BATCH_SIZE = 19
        const chunks = []
        for (let i = 0; i < left.length; i += BATCH_SIZE) {
          chunks.push(left.slice(i, i + BATCH_SIZE))
        }

        // Process each chunk
        const fetched = []
        for (const chunk of chunks) {
          this.fetchingCount++

          // Create a shared promise for the batch fetch. Individual fetch() calls
          // will await this promise instead of making duplicate API requests.
          const batchPromise = api(this.config).message.fetch(
            chunk.join(','),
            false
          )

          // Set the fetching flag for each ID to the shared batch promise.
          chunk.forEach((id) => {
            this.fetching[id] = batchPromise
          })

          try {
            const msgs = await batchPromise

            if (msgs && msgs.forEach) {
              msgs.forEach((msg) => {
                this.list[msg.id] = msg
                if (this.list[msg.id]) {
                  this.list[msg.id].addedToCache = Math.round(Date.now() / 1000)
                }
              })
              fetched.push(...msgs)
            } else if (typeof msgs === 'object') {
              this.list[msgs.id] = msgs
              if (this.list[msgs.id]) {
                this.list[msgs.id].addedToCache = Math.round(Date.now() / 1000)
              }
            } else {
              console.error('Failed to fetch', msgs)
            }
          } catch (e) {
            console.log('Failed to fetch messages', e)
            if (e instanceof APIError && e.response.status === 404) {
              console.log('Ignore 404 error')
            } else {
              throw e
            }
          } finally {
            this.fetchingCount--
            // Clear the fetching flags for all IDs in this batch.
            chunk.forEach((id) => {
              this.fetching[id] = null
            })
          }
        }

        // Road distances: the server ships roadmins/roadmiles with each
        // message (computed in the same batched call that blurred the
        // coords). Signal consumers that snapshot an order (the browse
        // feed's locked sort) that new road answers exist, and only
        // client-fetch for records an older server left bare - normally
        // none, so a page load makes NO /drivedistance calls at all.
        const anyRoad = fetched.some((m) => m.roadmins != null)
        if (anyRoad) {
          roadAnswersVersion.value++
        }
        // All-or-nothing fallback: if ANY record carries road metrics the
        // server-side routing ran, and the bare ones are posts the engine
        // genuinely cannot answer - asking again from the client just
        // repeats the null. Only when NO record has metrics (an older
        // server) is the client-side batched lookup worth making.
        if (!anyRoad) {
          prewarmRoadDistances(fetched)
        }

        // Batch-fetch the groups these messages belong to in one request, so the per-post
        // MessageTag components find their group cached instead of each firing its own
        // /group/{id} call. Done here (rather than only in the list component) so it covers
        // every fetch path uniformly - initial render AND lazy pagination. It matters most
        // for heavy-membership users on the nearby/reach and "all my communities" feeds,
        // where a post can be in a group the viewer isn't a member of (so it isn't in the
        // membership cache loaded at login). fetchBatch de-dupes against the cache and
        // no-ops when everything is already present.
        const groupIds = [
          ...new Set(
            left
              .flatMap((id) => this.list[id]?.groups ?? [])
              .map((g) => g.groupid)
              .filter(Boolean)
          ),
        ]
        if (groupIds.length) {
          useGroupStore().fetchBatch(groupIds)
        }
      }
    },
    async fetchInBounds(swlat, swlng, nelat, nelng, groupid, limit, cache) {
      let ret
      const key =
        swlat + ':' + swlng + ':' + nelat + ':' + nelng + ':' + groupid

      if (cache && this.bounds[key]) {
        ret = this.bounds[key]
      } else {
        // Don't cache this, as it might change.
        ret = await api(this.config).message.inbounds(
          swlat,
          swlng,
          nelat,
          nelng,
          groupid,
          limit
        )

        this.bounds[key] = ret
      }

      return ret
    },
    async search(params) {
      await this.clear()
      const ret = await api(this.config).message.search(params)
      return ret
    },
    // Semantically similar posts for the "more like this" recommendation strip.
    // Returns [{id, groupid, score, lat, lng}] (or [] when the feature is off or
    // there's nothing to compare).
    async similar(id, limit) {
      return await api(this.config).message.similar(id, limit)
    },
    // Existing offers near a location matching a free-text query, for the
    // wanted→offer "people are offering these near you" panel.
    async matches(query, lat, lng, limit) {
      return await api(this.config).message.matches(query, lat, lng, limit)
    },
    async fetchMyGroups(gid) {
      let ret

      if (this.fetchingMyGroups) {
        ret = await this.fetchingMyGroups
        await nextTick()
      } else {
        this.fetchingMyGroups = api(this.config).message.mygroups(gid)
        ret = await this.fetchingMyGroups
        this.fetchingMyGroups = null
      }
      // Keep the combined ("all my communities", gid falsy) feed so the distance slider can
      // scale to it. Skip single-group fetches, which aren't the slider's universe.
      if (!gid && Array.isArray(ret)) {
        this.myGroupsList = ret
      }
      return ret
    },
    async fetchByUser(userid, active, force) {
      let messages = []

      const promise = api(this.config).message.fetchByUser(userid, active)

      const authStore = useAuthStore()
      const isOwnMessages = authStore.user?.id === userid

      // If we're getting non-active messages make sure we hit the server as the cache might be of active only.
      if (!active || force || !this.byUserList[userid]) {
        messages = await promise

        // Guard against null/undefined API response (e.g. in test mocks).
        if (!Array.isArray(messages)) {
          messages = []
        }

        if (isOwnMessages) {
          for (const message of messages) {
            message.unseen = false
          }
        }

        this.byUserList[userid] = messages
      } else if (this.byUserList[userid]) {
        // Fetch but don't wait
        promise.then((msgs) => {
          if (isOwnMessages) {
            for (const message of msgs) {
              message.unseen = false
            }
          }

          this.byUserList[userid] = msgs
        })

        messages = this.byUserList[userid]
      }

      return messages || []
    },
    async view(id, source) {
      await api(this.config).message.view(id, source)
    },
    // Register the current user's interest in bulk-offer items, then refetch so
    // the per-item interest summary and yourinterest are up to date.
    async bulkInterest(id, items, interestuserid, comment) {
      const data = await api(this.config).message.bulkInterest(
        id,
        items,
        interestuserid,
        comment
      )
      const message = await this.fetch(id, true)
      this.list[id] = message
      return data
    },
    // Offerer/mod: change the state of one interest row, then refetch.
    async bulkInterestState(id, bulkitemid, userid, state) {
      const data = await api(this.config).message.bulkInterestState(
        id,
        bulkitemid,
        userid,
        state
      )
      const message = await this.fetch(id, true)
      this.list[id] = message
      return data
    },
    // Load Freegle Helper state for a bulk offer (offerer/mod only). Stores it
    // keyed by msgid; returns it. Never throws for "no batch yet" — the API
    // returns batch:null, which the page renders as "Helper not started".
    async fetchHelper(msgid) {
      const ret = await api(this.config).message.getHelper(msgid, false)
      this.helper[msgid] = ret
      return ret
    },
    // Offerer: pause/resume/stop the Helper and (optionally) set the send mode
    // (automatic/approve), then refresh helper state.
    async helperSetStatus(msgid, status, automode = null) {
      const params = { action: 'SetStatus', msgid, status }
      if (automode) {
        params.automode = automode
      }
      await api(this.config).message.helper(params)
      return await this.fetchHelper(msgid)
    },
    // Offerer: confirm/edit/send or dismiss a proposed decision, then refresh both
    // the helper state and the message (an allocation changes interest states too).
    async helperResolveProposal(msgid, proposalid, decision, text) {
      await api(this.config).message.helper({
        action: 'ResolveProposal',
        proposalid,
        decision,
        ...(text != null ? { text } : {}),
      })
      const message = await this.fetch(msgid, true)
      this.list[msgid] = message
      return await this.fetchHelper(msgid)
    },
    async update(params) {
      const authStore = useAuthStore()
      const userUid = authStore.user?.id
      const data = await api(this.config).message.update(params)

      if (data.deleted) {
        // This can happen if we withdraw a post while it is pending.
        delete this.list[params.id]
        if (userUid && this.byUserList[userUid]) {
          this.byUserList[userUid] = this.byUserList[userUid].filter(
            (message) => message.id !== params.id
          )
        }
      } else {
        // Fetch back the updated version.
        const message = await this.fetch(params.id, true)
        this.list[params.id] = message
        if (userUid && this.byUserList[userUid]) {
          const index = this.byUserList[userUid].findIndex(
            (curMessage) => curMessage.id === params.id
          )
          if (index !== -1) {
            this.byUserList[userUid][index] = message

            // If this was an Outcome action, set hasoutcome flag so the post
            // moves from active to old posts list immediately
            if (params.action === 'Outcome') {
              this.byUserList[userUid][index].hasoutcome = true
            }
          }
        }
      }

      return data
    },
    async patch(params) {
      const data = await api(this.config).message.save(params)

      // Re-fetch the message to get the updated server state (e.g. after
      // deleting a photo).  Don't remove-then-readd — that destroys and
      // recreates any mounted component, losing local state such as
      // expanded/collapsed in summary view (#299).
      const miscStore = useMiscStore()
      if (miscStore.modtools) {
        const message = await this.fetchMT({
          id: params.id,
        })
        if (message) {
          this.list[message.id] = message
        }
      } else {
        await this.fetch(params.id, true) // Gets message.fromuser as int not object

        // Sync updated message into byUserList so that myposts reflects the
        // latest server state (e.g. hasoutcome cleared when a future deadline
        // is set — without this the post stays in "Old Posts" after extending).
        const updated = this.list[params.id]
        if (updated) {
          for (const userId in this.byUserList) {
            const idx = this.byUserList[userId].findIndex(
              (m) => m.id === params.id
            )
            if (idx !== -1) {
              this.byUserList[userId][idx] = {
                ...this.byUserList[userId][idx],
                ...updated,
                // Explicitly propagate hasoutcome from server state.
                // Without this, a cleared outcome (server omits hasoutcome with
                // omitempty) won't override the synthetic hasoutcome:true that
                // fetchByUser sets, so the post stays in "Old Posts".
                hasoutcome: updated.hasoutcome,
              }
              break
            }
          }
        }
      }

      return data
    },
    remove(item) {
      delete this.list[parseInt(item.id)]
    },
    clear() {
      this.$reset()
      this.clearContext()
    },
    clearContext() {
      // ModTools
      this.context = null
    },
    async promise(id, userid) {
      await api(this.config).message.update({
        id,
        userid,
        action: 'Promise',
      })

      await this.fetch(id, true)
    },
    async renege(id, userid) {
      await api(this.config).message.update({
        id,
        userid,
        action: 'Renege',
      })

      await this.fetch(id, true)
    },
    async addBy(id, userid, count) {
      await api(this.config).message.addBy(id, userid, count)
      await this.fetch(id, true)
    },
    async removeBy(id, userid) {
      await api(this.config).message.removeBy(id, userid)
      await this.fetch(id, true)
    },
    async intend(id, outcome) {
      await api(this.config).message.intend(id, outcome)
    },
    async fetchActivePostCount() {
      const authStore = useAuthStore()
      const userUid = authStore.user?.id

      if (!userUid) {
        this.activePostsCounter = 0
        return
      }

      const activeMessages = await api(this.config).message.fetchByUser(
        userUid,
        true
      )
      this.activePostsCounter = Array.isArray(activeMessages)
        ? activeMessages.length
        : 0
    },
    // Clears the whole browse count server-side. No ids: see MessageAPI.clearCount.
    async clearCount() {
      await api(this.config).message.clearCount()
      this.count = 0
    },

    async markSeen(ids, source) {
      try {
        await api(this.config).message.markSeen(ids, source)
      } catch (e) {
        if (e?.response?.status === 401) {
          // Session expired while scrolling — not critical, silently ignore.
          return
        }

        throw e
      }

      const nearbyStore = useNearbyStore()
      nearbyStore.markSeen(ids)

      // Also update local cache to prevent watcher loop in MessageList.vue. Index the
      // ids directly rather than scanning the whole message cache, which grows for the
      // lifetime of the session (a long infinite-scroll made this an O(cache) scan per
      // mark-seen, i.e. trending to O(total^2) over a session).
      ids.forEach((id) => {
        const cached = this.list[id]
        if (cached) {
          cached.unseen = false
        }
      })

      // Refresh the badge for the member's ACTUAL browse view and distance limit. Calling
      // fetchCount() with no arguments recomputed the count for the default view (nearby,
      // unlimited), so a 'mygroups' member - or anyone with the distance slider set - saw
      // the badge repaint with a different view's number right after marking seen, i.e. it
      // didn't drop to zero. Mirror nearbyStore.fetchMessages and read the settings here.
      const settings = useAuthStore().user?.settings
      await this.fetchCount(settings?.browseView, settings?.browseMaxDistance)
    },
    // Mark the hidden crosspost/repost copies of an already-shown post as seen. The browse
    // feed collapses a poster's duplicate copies to one card (useMessageDedup), but the server
    // counts each copy as its own unseen post, so viewing the shown card leaves the hidden
    // copies unseen and the unread badge can never drain to zero through normal browsing.
    // Marking them here keeps the count in step with what the member has actually seen.
    //
    // Deliberately lighter than markSeen(): only writes the copies that are still unseen (so a
    // re-view is a no-op, not a repeated API call) and does NOT refetch the count - like a
    // normal per-card view, the badge refreshes on the next scroll/poll.
    async markSeenSiblings(ids) {
      if (!ids?.length) {
        return
      }

      const nearbyStore = useNearbyStore()
      const unseen = new Set(
        (nearbyStore.messageList || []).filter((m) => m.unseen).map((m) => m.id)
      )
      const toMark = ids.filter((id) => unseen.has(id))

      if (!toMark.length) {
        return
      }

      try {
        await api(this.config).message.markSeen(toMark)
      } catch (e) {
        if (e?.response?.status === 401) {
          return
        }

        throw e
      }

      nearbyStore.markSeen(toMark)
      toMark.forEach((id) => {
        const cached = this.list[id]
        if (cached) {
          cached.unseen = false
        }
      })
    },
    // ModTools-specific methods below
    async searchMT(params) {
      // Message search is always semantic now (the keyword toggle has been
      // retired). We call the V2 vector search endpoint directly and pass
      // searchmode explicitly so this does not depend on the server default.
      const results = await api(this.config).message.search({
        search: params.term,
        messagetype: 'All',
        groupids: params.groupid ? String(params.groupid) : undefined,
        searchmode: 'vector',
      })

      if (!results || results.length === 0) return []

      // Fetch in parallel but preserve API score order via Promise.all index stability
      const fetched = await Promise.all(
        results.map(async (r) => {
          try {
            const message = await this.fetchMT({ id: r.id || r.msgid })
            if (message) {
              // Carry matchedon from search result onto the fetched message
              if (r.matchedon) {
                message.matchedon = r.matchedon
              }
              this.list[message.id] = message
              return message.id
            }
          } catch (e) {
            console.log('Failed to fetch message', r.id, e?.message)
          }
          return null
        })
      )
      return fetched.filter((id) => id !== null)
    },
    async fetchMessagesMT(params) {
      if (params.context) {
        // Server expects context as a JSON-encoded string; URLSearchParams
        // would otherwise coerce an object to "[object Object]", the server
        // silently drops it, and infinite scroll caps at one page (~100).
        params.context = JSON.stringify(params.context)
      }
      if (!params.context) params.context = null

      const data = await api(this.config).message.fetchMessages(params)
      if (!data.messages || data.messages.length === 0) return
      const messageIDs = data.messages // Now returns IDs only (uint64 array)
      const context = data.context // Can be undefined if search complete

      if (params.collection !== 'Draft') {
        // We don't use context for drafts - there aren't many.
        this.context = context
      }

      // Fetch full message details in parallel for each ID.
      // Individual fetches may 404 if a message was deleted between listing and fetching.
      await Promise.all(
        messageIDs.map(async (id) => {
          try {
            const message = await this.fetchMT({
              id,
            })
            if (message) {
              if (!message.subject) message.subject = ''
              this.list[message.id] = message
            }
          } catch (e) {
            console.log('Failed to fetch message', id, e?.message)
          }
        })
      )

      return messageIDs
    },
    async fetchMT(params, logError = true) {
      const message = await api(this.config).message.fetchMT(params, logError)
      if (message && !message.subject) message.subject = ''
      return message
    },
    // Actual rippling-out progress of a post, for the moderation reach map.
    async fetchReach(id, logError = true) {
      return await api(this.config).message.reach(id, logError)
    },
    async updateMT(params) {
      // Rely on refresh elsewhere
      return await api(this.config).message.update(params)
    },
    async delete(params) {
      await this.runHoldAware(params.id, () =>
        api(this.config).message.delete(
          params.id,
          params.groupid,
          params.subject,
          params.stdmsgid,
          params.body
        )
      )

      delete this.list[params.id]
    },
    async approveedits(params) {
      await api(this.config).message.approveEdits(params.id)

      this.remove({ id: params.id })
    },
    async revertedits(params) {
      await api(this.config).message.revertEdits(params.id)

      this.remove({ id: params.id })
    },
    async backToPending(id, groupid) {
      await api(this.config).message.update({
        id,
        groupid,
        action: 'BackToPending',
      })
      this.remove({ id })
    },
    // After a PER-GROUP mod action (approve/reject) on a post that may be pending on several of
    // the mod's groups, re-fetch it and KEEP it in the review list if any group copy is still in
    // the review queue - so the next group's copy is immediately actionable without reloading the
    // pending page (Discourse 9862). Only drop it once nothing's left. The review-queue states
    // match ModMessage's own predicate; mirrors hold()/release()'s re-fetch, but conditional.
    async refreshOrRemoveFromMTList(id) {
      let message
      try {
        message = await this.fetchMT({ id }, false)
      } catch (e) {
        message = null
      }
      const stillInReviewQueue = !!message?.groups?.some((g) =>
        ['Pending', 'PendingOther', 'Spam'].includes(g.collection)
      )
      if (stillInReviewQueue) {
        this.list[message.id] = message
      } else {
        this.remove({ id })
      }
    },
    // The server refuses a moderation action with 409 when a DIFFERENT moderator
    // holds the post (see dispatchPostMessageAction). That normally means our copy
    // of the message is stale - the hold happened after we last fetched it - so
    // re-fetch, which makes the "Held by X" banner appear and hides the action
    // buttons, and hand the caller a message naming the holder (Discourse #9946).
    async runHoldAware(id, fn) {
      try {
        return await fn()
      } catch (e) {
        if (e?.response?.status !== 409 || !e?.response?.data?.heldby) throw e

        try {
          const message = await api(this.config).message.fetchMT({ id }, false)
          if (message) this.list[message.id] = message
        } catch (fetchError) {
          // Leave the stale copy in place; the thrown error still explains why.
        }

        const who = e.response.data.heldbyname || 'Another moderator'
        const held = new Error(
          `${who} is holding this post. Check with them, or release it first.`
        )
        held.heldByOtherMod = true
        throw held
      }
    },
    async approve(id, groupid, subject, stdmsgid, body) {
      const msg = this.byId(id)
      const fromuser = msg?.fromuser

      await this.runHoldAware(id, () =>
        api(this.config).message.approve(id, groupid, subject, stdmsgid, body)
      )
      await this.refreshOrRemoveFromMTList(id)

      // Re-fetch the sender so posting status changes from stdmsg take effect.
      if (fromuser) {
        const uid = typeof fromuser === 'number' ? fromuser : fromuser.id
        if (uid) {
          useUserStore().fetch(uid, true)
        }
      }
    },
    async reject(id, groupid, subject, stdmsgid, body) {
      const msg = this.byId(id)
      const fromuser = msg?.fromuser

      await this.runHoldAware(id, () =>
        api(this.config).message.reject(id, groupid, subject, stdmsgid, body)
      )
      await this.refreshOrRemoveFromMTList(id)

      if (fromuser) {
        const uid = typeof fromuser === 'number' ? fromuser : fromuser.id
        if (uid) {
          useUserStore().fetch(uid, true)
        }
      }
    },
    async reply(params) {
      await api(this.config).message.reply(
        params.id,
        params.groupid,
        params.subject,
        params.stdmsgid,
        params.body
      )
      // Do not remove from list
    },
    async hold(params) {
      await this.runHoldAware(params.id, () =>
        api(this.config).message.hold(params.id, params.groupid)
      )
      const message = await api(this.config).message.fetchMT({
        id: params.id,
      })
      this.list[message.id] = message
    },
    async release(params) {
      await api(this.config).message.release(params.id, params.groupid)
      const message = await api(this.config).message.fetchMT({
        id: params.id,
      })
      this.list[message.id] = message
    },
    async spam(params) {
      await this.runHoldAware(params.id, () =>
        api(this.config).message.spam(params.id, params.groupid)
      )

      this.remove({ id: params.id })
    },
    async move(params) {
      await api(this.config).message.update({
        id: params.id,
        groupid: params.groupid,
        action: 'Move',
      })

      const message = await api(this.config).message.fetchMT({
        id: params.id,
      })
      this.list[message.id] = message
    },
    async searchMember(term, groupid) {
      const data = await api(this.config).message.fetchMessages({
        subaction: 'searchmemb',
        search: term,
        groupid,
      })
      await this.clear()
      if (!data.messages || data.messages.length === 0) return
      // Response is IDs only — fetch full details for each.
      await Promise.all(
        data.messages.map(async (id) => {
          try {
            const message = await this.fetchMT({ id })
            if (message) {
              this.list[message.id] = message
            }
          } catch (e) {
            console.log('Failed to fetch message', id, e?.message)
          }
        })
      )
    },
  },
  getters: {
    byId: (state) => {
      return (id) => state.list[id]
    },
    helperById: (state) => {
      return (id) => state.helper[id]
    },
    inBounds: (state) => (swlat, swlng, nelat, nelng, groupid) => {
      const key =
        swlat + ':' + swlng + ':' + nelat + ':' + nelng + ':' + groupid

      return key in state.bounds ? state.bounds[key] : []
    },
    all: (state) => Object.values(state.list),
    byUser: (state) => (userid) => {
      return state.byUserList[userid] || []
    },
    getByGroup: (state) => (groupid) => {
      // ModTools — match any group in the message's groups array (multi-group support).
      const gid = parseInt(groupid)
      const ret = Object.values(state.list).filter((message) => {
        return message.groups.some((g) => parseInt(g.groupid) === gid)
      })
      return ret
    },
  },
})
