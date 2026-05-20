import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, effectScope } from 'vue'

// ---- mocks (vi.mock is hoisted before imports) ----

const mockTimeagoShort = vi.fn()
const mockTimeagoMedium = vi.fn()
const mockDateshortNoYear = vi.fn()
const mockTimeago = vi.fn()

vi.mock('~/composables/useTimeFormat', () => ({
  timeagoShort: (...a) => mockTimeagoShort(...a),
  timeagoMedium: (...a) => mockTimeagoMedium(...a),
  dateshortNoYear: (...a) => mockDateshortNoYear(...a),
  timeago: (...a) => mockTimeago(...a),
}))

const mockMilesAway = vi.fn()

vi.mock('~/composables/useDistance', () => ({
  milesAway: (...a) => mockMilesAway(...a),
}))

const mockByIdMessage = vi.fn()
const mockFetchUser = vi.fn()
const mockByIdUser = vi.fn()
const mockGetGroup = vi.fn()

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ byId: mockByIdMessage }),
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ fetch: mockFetchUser, byId: mockByIdUser }),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({ get: mockGetGroup }),
}))

// mockMe must be a ref so the composable's `me.value` lookup works
const mockMe = ref(null)

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

// ~/stores/auth is pre-aliased to tests/unit/mocks/auth-store.js;
// override via globalThis.__mockAuthStore.

import { useMessageDisplay } from '~/composables/useMessageDisplay'

// ---- helpers ----

const activeScopes = []

/**
 * Instantiate useMessageDisplay with controlled message data.
 *
 * @param {object|null} msgData  - the message object returned by messageStore.byId
 * @param {object}      opts     - { me, poster, group, authUser }
 */
function make(msgData, opts = {}) {
  mockByIdMessage.mockReturnValue(msgData)

  if (opts.me !== undefined) mockMe.value = opts.me
  if (opts.poster !== undefined) mockByIdUser.mockReturnValue(opts.poster)
  if (opts.group !== undefined) mockGetGroup.mockReturnValue(opts.group)
  if (opts.authUser !== undefined) {
    globalThis.__mockAuthStore = { user: opts.authUser }
  }

  const msgId = ref(msgData?.id ?? 1)
  let c
  const scope = effectScope()
  scope.run(() => {
    c = useMessageDisplay(msgId)
  })
  activeScopes.push(scope)
  return c
}

// ---- setup ----

beforeEach(() => {
  vi.clearAllMocks()
  mockMe.value = null
  mockByIdUser.mockReturnValue(null)
  mockGetGroup.mockReturnValue(null)
  mockFetchUser.mockResolvedValue(undefined)
  mockTimeagoShort.mockReturnValue('2h')
  mockTimeagoMedium.mockReturnValue('2 hours')
  mockDateshortNoYear.mockReturnValue('15 Apr')
  mockTimeago.mockReturnValue('2 hours ago')
  mockMilesAway.mockReturnValue(5)
  globalThis.__mockAuthStore = { user: { id: 42 } }
})

afterEach(() => {
  activeScopes.forEach((s) => s.stop())
  activeScopes.length = 0
})

// ---- tests ----

describe('useMessageDisplay', () => {
  describe('messageId can be a plain value (non-ref)', () => {
    it('resolves message when messageId is a plain number', () => {
      mockByIdMessage.mockReturnValue({ id: 42, subject: 'OFFER: Plain ID test' })
      let c
      const scope = effectScope()
      scope.run(() => {
        c = useMessageDisplay(42) // plain number, not a ref
      })
      activeScopes.push(scope)
      expect(c.strippedSubject.value).toBe('Plain ID test')
      expect(mockByIdMessage).toHaveBeenCalledWith(42)
    })
  })

  describe('message is null/missing', () => {
    it('strippedSubject returns empty string when message is null', () => {
      const c = make(null)
      expect(c.strippedSubject.value).toBe('')
    })

    it('gotAttachments returns false when message is null', () => {
      const c = make(null)
      expect(c.gotAttachments.value).toBe(false)
    })

    it('attachmentCount returns 0 when message is null', () => {
      const c = make(null)
      expect(c.attachmentCount.value).toBe(0)
    })

    it('replyCount returns 0 when message is null', () => {
      const c = make(null)
      expect(c.replyCount.value).toBe(0)
    })

    it('timeAgo returns empty string when message is null', () => {
      const c = make(null)
      expect(c.timeAgo.value).toBe('')
    })

    it('distanceText returns null when message is null', () => {
      const c = make(null)
      expect(c.distanceText.value).toBeNull()
    })

    it('poster returns null when message is null', () => {
      const c = make(null)
      expect(c.poster.value).toBeNull()
    })
  })

  describe('strippedSubject', () => {
    it.each([
      ['OFFER: Nice sofa', 'Nice sofa'],
      ['WANTED: Bicycle', 'Bicycle'],
      ['TAKEN: Garden tools', 'Garden tools'],
      ['RECEIVED: Books', 'Books'],
      ['OFFERED: Kettle', 'Kettle'],
      ['REQUESTED: Help', 'Help'],
      ['offered: Lowercase test', 'Lowercase test'],
      ['OFFER:No space', 'No space'],
      ['offer:  Extra spaces', 'Extra spaces'],
    ])('strips "%s" → "%s"', (subject, expected) => {
      const c = make({ id: 1, subject })
      expect(c.strippedSubject.value).toBe(expected)
    })

    it('leaves subjects without a recognised prefix unchanged', () => {
      const c = make({ id: 1, subject: 'No prefix here' })
      expect(c.strippedSubject.value).toBe('No prefix here')
    })

    it('leaves empty subject as empty string', () => {
      const c = make({ id: 1, subject: '' })
      expect(c.strippedSubject.value).toBe('')
    })

    it('uses custom group keyword for offer when provided', () => {
      const c = make(
        { id: 1, subject: 'GIVE: Custom keyword item', groups: [{ groupid: 10 }] },
        { group: { settings: { keywords: { offer: 'GIVE' } } } }
      )
      expect(c.strippedSubject.value).toBe('Custom keyword item')
    })

    it('uses custom group keyword for wanted when provided', () => {
      const c = make(
        { id: 1, subject: 'SEEKING: Garden chair', groups: [{ groupid: 10 }] },
        { group: { settings: { keywords: { wanted: 'SEEKING' } } } }
      )
      expect(c.strippedSubject.value).toBe('Garden chair')
    })

    it('falls back to default keywords when group has no settings', () => {
      const c = make(
        { id: 1, subject: 'OFFER: Fallback test', groups: [{ groupid: 10 }] },
        { group: {} }  // group without settings
      )
      expect(c.strippedSubject.value).toBe('Fallback test')
    })
  })

  describe('subjectItemName', () => {
    it('extracts item name from "Item (Location)" format', () => {
      const c = make({ id: 1, subject: 'OFFER: Nice sofa (Gracemount EH17)' })
      expect(c.subjectItemName.value).toBe('Nice sofa')
    })

    it('returns the full stripped subject when there is no location', () => {
      const c = make({ id: 1, subject: 'OFFER: Bicycle' })
      expect(c.subjectItemName.value).toBe('Bicycle')
    })

    it('handles multiple words in location parentheses', () => {
      const c = make({ id: 1, subject: 'WANTED: Books (North Edinburgh EH3)' })
      expect(c.subjectItemName.value).toBe('Books')
    })

    it('returns stripped subject unchanged when no parentheses at end', () => {
      const c = make({ id: 1, subject: 'OFFER: Item name with (parens) in middle' })
      // Parens not at end — no trailing location
      expect(c.subjectItemName.value).toBe('Item name with (parens) in middle')
    })
  })

  describe('subjectLocation', () => {
    it('extracts location from trailing parentheses', () => {
      const c = make({ id: 1, subject: 'OFFER: Sofa (Gracemount EH17)' })
      expect(c.subjectLocation.value).toBe('Gracemount EH17')
    })

    it('returns null when there is no location in parentheses', () => {
      const c = make({ id: 1, subject: 'OFFER: Bicycle' })
      expect(c.subjectLocation.value).toBeNull()
    })

    it('returns null when parentheses are not at end', () => {
      const c = make({ id: 1, subject: 'OFFER: Item (note) extra' })
      expect(c.subjectLocation.value).toBeNull()
    })
  })

  describe('fromme', () => {
    it('returns true when message.fromuser matches auth user id', () => {
      const c = make({ id: 1, fromuser: 42 }, { authUser: { id: 42 } })
      expect(c.fromme.value).toBe(true)
    })

    it('returns false when message.fromuser differs from auth user id', () => {
      const c = make({ id: 1, fromuser: 99 }, { authUser: { id: 42 } })
      expect(c.fromme.value).toBe(false)
    })

    it('returns false when there is no auth user', () => {
      const c = make({ id: 1, fromuser: 42 }, { authUser: null })
      expect(c.fromme.value).toBe(false)
    })
  })

  describe('gotAttachments / attachmentCount', () => {
    it.each([
      [[], false, 0],
      [[{ id: 1 }], true, 1],
      [[{ id: 1 }, { id: 2 }, { id: 3 }], true, 3],
    ])('attachments %j → gotAttachments=%s, count=%d', (attachments, gotAtt, count) => {
      const c = make({ id: 1, attachments })
      expect(c.gotAttachments.value).toBe(gotAtt)
      expect(c.attachmentCount.value).toBe(count)
    })

    it('returns false/0 when attachments property is absent', () => {
      const c = make({ id: 1 })
      expect(c.gotAttachments.value).toBe(false)
      expect(c.attachmentCount.value).toBe(0)
    })
  })

  describe('sampleImage', () => {
    it('returns sampleimage when present', () => {
      const c = make({ id: 1, sampleimage: { path: '/img/sample.jpg' } })
      expect(c.sampleImage.value).toEqual({ path: '/img/sample.jpg' })
    })

    it('returns null when sampleimage is absent', () => {
      const c = make({ id: 1 })
      expect(c.sampleImage.value).toBeNull()
    })
  })

  describe('timeAgo', () => {
    it('uses groups[0].arrival as primary timestamp', () => {
      const c = make({
        id: 1,
        groups: [{ arrival: '2026-05-16T10:00:00Z' }],
        arrival: '2026-05-15T10:00:00Z',
        date: '2026-05-14T10:00:00Z',
      })
      // Access value first to trigger lazy computed evaluation
      expect(c.timeAgo.value).toBe('2h')
      expect(mockTimeagoShort).toHaveBeenCalledWith('2026-05-16T10:00:00Z')
    })

    it('falls back to arrival when groups arrival is absent', () => {
      const c = make({
        id: 1,
        groups: [{}],
        arrival: '2026-05-15T10:00:00Z',
      })
      c.timeAgo.value // trigger
      expect(mockTimeagoShort).toHaveBeenCalledWith('2026-05-15T10:00:00Z')
    })

    it('falls back to date when arrival is also absent', () => {
      const c = make({
        id: 1,
        date: '2026-05-14T10:00:00Z',
      })
      c.timeAgo.value // trigger
      expect(mockTimeagoShort).toHaveBeenCalledWith('2026-05-14T10:00:00Z')
    })

    it('returns empty string when all timestamps are absent', () => {
      const c = make({ id: 1 })
      expect(c.timeAgo.value).toBe('')
    })
  })

  describe('timeAgoExpanded / timeAgoExpandedDisplay', () => {
    it('returns timeagoMedium result for timeAgoExpanded', () => {
      mockTimeagoMedium.mockReturnValue('3 days')
      const c = make({ id: 1, date: '2026-05-14T10:00:00Z' })
      expect(c.timeAgoExpanded.value).toBe('3 days')
    })

    it('uses groups[0].arrival as primary timestamp', () => {
      const c = make({
        id: 1,
        groups: [{ arrival: '2026-05-16T10:00:00Z' }],
        arrival: '2026-05-15T10:00:00Z',
      })
      c.timeAgoExpanded.value
      expect(mockTimeagoMedium).toHaveBeenCalledWith('2026-05-16T10:00:00Z')
    })

    it('falls back to arrival when groups arrival is absent', () => {
      const c = make({ id: 1, groups: [{}], arrival: '2026-05-15T10:00:00Z' })
      c.timeAgoExpanded.value
      expect(mockTimeagoMedium).toHaveBeenCalledWith('2026-05-15T10:00:00Z')
    })

    it('returns empty string when no timestamp', () => {
      const c = make({ id: 1 })
      expect(c.timeAgoExpanded.value).toBe('')
    })

    it('timeAgoExpandedDisplay appends "ago" when not "just now"', () => {
      mockTimeagoMedium.mockReturnValue('2 hours')
      const c = make({ id: 1, date: '2026-05-14T10:00:00Z' })
      expect(c.timeAgoExpandedDisplay.value).toBe('2 hours ago')
    })

    it('timeAgoExpandedDisplay returns "just now" unchanged (no "ago")', () => {
      mockTimeagoMedium.mockReturnValue('just now')
      const c = make({ id: 1, date: '2026-05-18T10:00:00Z' })
      expect(c.timeAgoExpandedDisplay.value).toBe('just now')
    })

    it('timeAgoExpandedDisplay returns empty string when timeAgoExpanded is empty', () => {
      mockTimeagoMedium.mockReturnValue('')
      const c = make({ id: 1, date: '2026-05-14T10:00:00Z' })
      expect(c.timeAgoExpandedDisplay.value).toBe('')
    })
  })

  describe('fullTimeAgo', () => {
    it('returns "Posted X ago" format', () => {
      mockTimeago.mockReturnValue('2 hours ago')
      const c = make({ id: 1, date: '2026-05-18T10:00:00Z' })
      expect(c.fullTimeAgo.value).toBe('Posted 2 hours ago')
    })

    it('returns empty string when no timestamp', () => {
      const c = make({ id: 1 })
      expect(c.fullTimeAgo.value).toBe('')
    })

    it('uses groups[0].arrival as primary timestamp', () => {
      const c = make({
        id: 1,
        groups: [{ arrival: '2026-05-16T10:00:00Z' }],
        arrival: '2026-05-15T10:00:00Z',
      })
      c.fullTimeAgo.value // trigger
      expect(mockTimeago).toHaveBeenCalledWith('2026-05-16T10:00:00Z')
    })

    it('falls back to arrival when groups arrival is absent', () => {
      const c = make({ id: 1, groups: [{}], arrival: '2026-05-15T10:00:00Z' })
      c.fullTimeAgo.value
      expect(mockTimeago).toHaveBeenCalledWith('2026-05-15T10:00:00Z')
    })
  })

  describe('replyCount / replyTooltip', () => {
    it.each([
      [undefined, 0, 'No replies yet'],
      [[], 0, 'No replies yet'],
      [[{ id: 1 }], 1, '1 person has replied'],
      [[{ id: 1 }, { id: 2 }], 2, '2 people have replied'],
      [
        [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }],
        5,
        '5 people have replied',
      ],
    ])('replies %j → count=%d, tooltip="%s"', (replies, count, tooltip) => {
      const c = make({ id: 1, replies })
      expect(c.replyCount.value).toBe(count)
      expect(c.replyTooltip.value).toBe(tooltip)
    })
  })

  describe('isOffer / isWanted / successfulText / placeholderClass / categoryIcon', () => {
    it.each([
      ['Offer', true, false, 'Taken', 'offer-gradient', 'gift'],
      ['Wanted', false, true, 'Received', 'wanted-gradient', 'search'],
      ['Other', false, false, 'Received', 'wanted-gradient', 'gift'],
    ])(
      'type=%s → isOffer=%s isWanted=%s successfulText=%s placeholder=%s icon=%s',
      (type, isOffer, isWanted, successful, placeholder, icon) => {
        const c = make({ id: 1, type })
        expect(c.isOffer.value).toBe(isOffer)
        expect(c.isWanted.value).toBe(isWanted)
        expect(c.successfulText.value).toBe(successful)
        expect(c.placeholderClass.value).toBe(placeholder)
        expect(c.categoryIcon.value).toBe(icon)
      }
    )
  })

  describe('formattedDeadline / deadlineTooltip', () => {
    it('formattedDeadline calls dateshortNoYear when deadline is present', () => {
      mockDateshortNoYear.mockReturnValue('30 Jun')
      const c = make({ id: 1, deadline: '2026-06-30', type: 'Offer' })
      expect(c.formattedDeadline.value).toBe('30 Jun')
      expect(mockDateshortNoYear).toHaveBeenCalledWith('2026-06-30')
    })

    it('formattedDeadline returns empty string when deadline is absent', () => {
      const c = make({ id: 1, type: 'Offer' })
      expect(c.formattedDeadline.value).toBe('')
    })

    it('deadlineTooltip is offer-specific text for Offer type', () => {
      const c = make({ id: 1, type: 'Offer' })
      expect(c.deadlineTooltip.value).toBe('Only available until this date')
    })

    it('deadlineTooltip is wanted-specific text for Wanted type', () => {
      const c = make({ id: 1, type: 'Wanted' })
      expect(c.deadlineTooltip.value).toBe('Only needed before this date')
    })
  })

  describe('distanceText', () => {
    it('returns message.area when me.lat is absent', () => {
      mockMe.value = null
      const c = make({ id: 1, lat: 51.5, lng: -0.1, area: 'Hackney' })
      expect(c.distanceText.value).toBe('Hackney')
    })

    it('returns message.area when message.lat is absent', () => {
      mockMe.value = { lat: 51.5, lng: -0.1 }
      const c = make({ id: 1, area: 'Hackney' })
      expect(c.distanceText.value).toBe('Hackney')
    })

    it('returns null when neither me.lat nor message.area are set', () => {
      mockMe.value = null
      const c = make({ id: 1 })
      expect(c.distanceText.value).toBeNull()
    })

    it('returns "<1mi" when distance is under 1 mile', () => {
      mockMilesAway.mockReturnValue(0.4)
      mockMe.value = { lat: 51.5, lng: -0.1 }
      const c = make({ id: 1, lat: 51.51, lng: -0.1 })
      expect(c.distanceText.value).toBe('<1mi')
    })

    it('returns rounded distance in miles when >= 1', () => {
      mockMilesAway.mockReturnValue(5.7)
      mockMe.value = { lat: 51.5, lng: -0.1 }
      const c = make({ id: 1, lat: 51.6, lng: -0.1 })
      expect(c.distanceText.value).toBe('6mi')
    })
  })

  describe('distanceTextExpanded', () => {
    it('returns message.area when me.lat is absent', () => {
      mockMe.value = null
      const c = make({ id: 1, lat: 51.5, area: 'Camden' })
      expect(c.distanceTextExpanded.value).toBe('Camden')
    })

    it('returns null when me.lat is absent and message has no area', () => {
      mockMe.value = null
      const c = make({ id: 1, lat: 51.5 })
      expect(c.distanceTextExpanded.value).toBeNull()
    })

    it('returns "less than 1 mile" for distances under 1 mile', () => {
      mockMilesAway.mockReturnValue(0.3)
      mockMe.value = { lat: 51.5, lng: -0.1 }
      const c = make({ id: 1, lat: 51.51, lng: -0.1 })
      expect(c.distanceTextExpanded.value).toBe('less than 1 mile')
    })

    it('returns "1 mile" (singular) for exactly 1 mile', () => {
      mockMilesAway.mockReturnValue(1.0)
      mockMe.value = { lat: 51.5, lng: -0.1 }
      const c = make({ id: 1, lat: 51.52, lng: -0.1 })
      expect(c.distanceTextExpanded.value).toBe('1 mile')
    })

    it('returns "N miles" (plural) for distances > 1', () => {
      mockMilesAway.mockReturnValue(7.4)
      mockMe.value = { lat: 51.5, lng: -0.1 }
      const c = make({ id: 1, lat: 51.6, lng: -0.1 })
      expect(c.distanceTextExpanded.value).toBe('7 miles')
    })
  })

  describe('poster / posterName / posterProfileUrl', () => {
    it('returns null poster when message has no fromuser', () => {
      const c = make({ id: 1 })
      expect(c.poster.value).toBeNull()
      expect(c.posterName.value).toBeNull()
      expect(c.posterProfileUrl.value).toBeNull()
    })

    it('returns poster from userStore.byId when fromuser is set', () => {
      const posterObj = { id: 99, displayname: 'Jane Smith' }
      const c = make({ id: 1, fromuser: 99 }, { poster: posterObj })
      expect(c.poster.value).toEqual(posterObj)
    })

    it('posterName returns first word of displayname', () => {
      const c = make(
        { id: 1, fromuser: 99 },
        { poster: { id: 99, displayname: 'Jane Smith' } }
      )
      expect(c.posterName.value).toBe('Jane')
    })

    it('posterName returns single-word displayname unchanged', () => {
      const c = make(
        { id: 1, fromuser: 99 },
        { poster: { id: 99, displayname: 'Mononym' } }
      )
      expect(c.posterName.value).toBe('Mononym')
    })

    it('posterName returns empty string for blank displayname', () => {
      const c = make(
        { id: 1, fromuser: 99 },
        { poster: { id: 99, displayname: '' } }
      )
      expect(c.posterName.value).toBe('')
    })

    it('posterName returns null when poster is null', () => {
      mockByIdUser.mockReturnValue(null)
      const c = make({ id: 1, fromuser: 99 })
      expect(c.posterName.value).toBeNull()
    })

    it('posterProfileUrl returns /profile/{id} for known poster', () => {
      const c = make(
        { id: 1, fromuser: 99 },
        { poster: { id: 99, displayname: 'Test' } }
      )
      expect(c.posterProfileUrl.value).toBe('/profile/99')
    })

    it('posterProfileUrl returns null when poster is null', () => {
      mockByIdUser.mockReturnValue(null)
      const c = make({ id: 1, fromuser: 99 })
      expect(c.posterProfileUrl.value).toBeNull()
    })
  })

  describe('userStore.fetch is called for message poster', () => {
    it('calls userStore.fetch when message.fromuser is present', () => {
      make({ id: 1, fromuser: 77 })
      expect(mockFetchUser).toHaveBeenCalledWith(77)
    })

    it('does not call userStore.fetch when message has no fromuser', () => {
      make({ id: 1 })
      expect(mockFetchUser).not.toHaveBeenCalled()
    })
  })
})
