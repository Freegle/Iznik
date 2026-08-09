/**
 * Contract tests for the spammers wrapper. These pin two things that are easy
 * to break silently and expensive to notice: the exact v2 endpoint each verb
 * targets, and the fact that patch() suppresses Sentry reporting for a
 * held-by-another-moderator 409.
 *
 * The held-conflict argument matters. A 409 carrying `heldby` is the hold
 * feature working as designed, not a fault. Drop that argument and every
 * moderator who bumps into a colleague's hold generates Sentry noise, which is
 * invisible in the UI and so would go unnoticed indefinitely.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

import SpammersAPI from '~/api/SpammersAPI.js'
import { notAHeldConflict } from '~/api/heldConflict'

describe('SpammersAPI', () => {
  let api

  beforeEach(() => {
    vi.clearAllMocks()
    api = new SpammersAPI({ public: { APIv2: 'https://api.test.com' } })
  })

  describe('fetch', () => {
    it('returns only spammers and context from the response envelope', async () => {
      const spammers = [{ id: 1, userid: 10 }]
      vi.spyOn(api, '$getv2').mockResolvedValue({
        spammers,
        context: { id: 99 },
        // The API also returns other keys; the wrapper deliberately drops them.
        ret: 0,
        status: 'Success',
      })

      const result = await api.fetch({ collection: 'PendingAdd' })

      expect(api.$getv2).toHaveBeenCalledWith('/modtools/spammers', {
        collection: 'PendingAdd',
      })
      expect(result).toEqual({ spammers, context: { id: 99 } })
    })

    it('yields undefined members rather than throwing when the envelope is empty', async () => {
      vi.spyOn(api, '$getv2').mockResolvedValue({})

      const result = await api.fetch({})

      expect(result).toEqual({ spammers: undefined, context: undefined })
    })
  })

  describe('write verbs', () => {
    it('adds via POST to the spammers endpoint', async () => {
      vi.spyOn(api, '$postv2').mockResolvedValue({ id: 5 })

      await api.add({ userid: 10, reason: 'spam' })

      expect(api.$postv2).toHaveBeenCalledWith('/modtools/spammers', {
        userid: 10,
        reason: 'spam',
      })
    })

    it('deletes via DELETE to the spammers endpoint', async () => {
      vi.spyOn(api, '$delv2').mockResolvedValue({})

      await api.del({ id: 5 })

      expect(api.$delv2).toHaveBeenCalledWith('/modtools/spammers', { id: 5 })
    })

    it('patches with the held-conflict guard so a colleague hold is not logged as a fault', async () => {
      vi.spyOn(api, '$patchv2').mockResolvedValue({})

      await api.patch({ id: 5, collection: 'Spammer' })

      expect(api.$patchv2).toHaveBeenCalledWith(
        '/modtools/spammers',
        { id: 5, collection: 'Spammer' },
        notAHeldConflict
      )
    })
  })

  // Pin what the guard actually decides, not just that it was passed along:
  // identity alone would still hold if the predicate were inverted.
  describe('notAHeldConflict guard', () => {
    it('suppresses logging for a held conflict and logs everything else', () => {
      expect(notAHeldConflict({ heldby: 42 })).toBe(false)
      expect(notAHeldConflict({ error: 'something else' })).toBe(true)
      expect(notAHeldConflict(null)).toBe(true)
      expect(notAHeldConflict(undefined)).toBe(true)
    })
  })
})
