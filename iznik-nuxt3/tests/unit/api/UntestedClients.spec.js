import { describe, it, expect, vi, beforeEach } from 'vitest'
import GroupAPI from '~/api/GroupAPI'
import SpammersAPI from '~/api/SpammersAPI'
import StoriesAPI from '~/api/StoriesAPI'
import VolunteeringAPI from '~/api/VolunteeringAPI'
import NotificationAPI from '~/api/NotificationAPI'
import CommunityEventAPI from '~/api/CommunityEventAPI'
import AuthorityAPI from '~/api/AuthorityAPI'
import DomainAPI from '~/api/DomainAPI'
import GiftAidAPI from '~/api/GiftAidAPI'
import PartnershipsAPI from '~/api/PartnershipsAPI'
import MicroVolunteeringAPI from '~/api/MicroVolunteeringAPI'
import DashboardAPI from '~/api/DashboardAPI'
import TeamAPI from '~/api/TeamAPI'
import TrystAPI from '~/api/TrystAPI'
import UserSearchAPI from '~/api/UserSearchAPI'
import ShortlinksAPI from '~/api/ShortlinksAPI'
import AddressAPI from '~/api/AddressAPI'
import AlertAPI from '~/api/AlertAPI'
import CommentAPI from '~/api/CommentAPI'
import DonationsAPI from '~/api/DonationsAPI'
import AdminsAPI from '~/api/AdminsAPI'

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    auth: { jwt: 'j', persistent: 'p' },
    user: { id: 1 },
  }),
}))
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: false,
    api: vi.fn(),
    waitForOnline: vi.fn().mockResolvedValue(),
  }),
}))
vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => ({ getHeaders: () => ({}) }),
}))
vi.mock('~/composables/useTrace', () => ({ getTraceHeaders: () => ({}) }))
vi.mock('@sentry/vue', () => ({ captureMessage: vi.fn() }))
vi.mock('~/composables/useFetchRetry', () => ({ fetchRetry: () => vi.fn() }))

const VERBS = ['$getv2', '$postv2', '$patchv2', '$putv2', '$delv2']
const F = expect.any(Function)

function make(Cls, returns = {}) {
  const api = new Cls({ public: { APIv2: 'https://api.test.com' } })
  for (const v of VERBS) {
    api[v] = vi.fn().mockResolvedValue(returns[v] ?? {})
  }
  return api
}

// Each row: [method, args, verb, expected call args to the verb].
const TABLES = {
  GroupAPI: [
    GroupAPI,
    [
      ['list', [{ a: 1 }], '$getv2', ['/group', { a: 1 }]],
      ['listMT', [{ b: 2 }], '$getv2', ['/group', { b: 2 }]],
      [
        'fetchGroupMT',
        [5, true, false, true, 'k', false],
        '$getv2',
        [
          '/group/5',
          {
            polygon: true,
            showmods: false,
            sponsors: true,
            tnkey: 'k',
            modtools: true,
          },
          false,
        ],
      ],
      [
        'fetchGroupsMT',
        [[1, 2, 3], false, true, false, undefined, true],
        '$getv2',
        [
          '/group/1,2,3',
          {
            polygon: false,
            showmods: true,
            sponsors: false,
            tnkey: undefined,
            modtools: true,
          },
          true,
        ],
      ],
      ['fetch', [7, true], '$getv2', ['/group/7', {}, true]],
      ['fetchBatch', [[4, 9], false], '$getv2', ['/group/4,9', {}, false]],
      ['fetchBatch', [[4]], '$getv2', ['/group/4', {}, undefined]],
      ['fetchMessagesForGroup', [3], '$getv2', ['/group/3/message']],
      ['fetchWork', [], '$getv2', ['/group/work']],
      ['patch', [{ id: 1 }], '$patchv2', ['/group', { id: 1 }]],
    ],
  ],
  SpammersAPI: [
    SpammersAPI,
    [
      [
        'add',
        [{ userid: 1 }],
        '$postv2',
        ['/modtools/spammers', { userid: 1 }],
      ],
      ['patch', [{ id: 1 }], '$patchv2', ['/modtools/spammers', { id: 1 }, F]],
      ['del', [{ id: 2 }], '$delv2', ['/modtools/spammers', { id: 2 }]],
    ],
  ],
  StoriesAPI: [
    StoriesAPI,
    [
      ['fetchv2', [3], '$getv2', ['/story/3', {}, true]],
      ['fetchv2', [3, false], '$getv2', ['/story/3', {}, false]],
      ['listv2', [], '$getv2', ['/story', {}]],
      ['listv2', [{ limit: 2 }], '$getv2', ['/story', { limit: 2 }]],
      ['byGroupv2', [4], '$getv2', ['/story/group/4']],
      ['byGroupv2', [4, 10], '$getv2', ['/story/group/4?limit=10']],
      ['byGroupv2', [4, 0], '$getv2', ['/story/group/4']],
      ['fetch', [{ x: 1 }], '$getv2', ['/story', { x: 1 }]],
      ['love', [6], '$postv2', ['/story/like', { id: 6 }]],
      ['unlove', [6], '$postv2', ['/story/unlike', { id: 6 }]],
      [
        'dontUseForPublicity',
        [6],
        '$patchv2',
        ['/story', { id: 6, reviewed: 1, public: 0 }],
      ],
      [
        'useForPublicity',
        [6],
        '$patchv2',
        ['/story', { id: 6, reviewed: 1, public: 1 }],
      ],
      [
        'useForNewsletter',
        [6],
        '$patchv2',
        ['/story', { id: 6, newsletterreviewed: 1, newsletter: 1 }],
      ],
      [
        'dontUseForNewsletter',
        [6],
        '$patchv2',
        ['/story', { id: 6, newsletterreviewed: 1, newsletter: 0 }],
      ],
    ],
  ],
  NotificationAPI: [
    NotificationAPI,
    [
      ['count', [], '$getv2', ['/notification/count', {}, false]],
      ['list', [], '$getv2', ['/notification', {}, false]],
      ['seen', [8], '$postv2', ['/notification/seen', { id: 8 }]],
      ['allSeen', [], '$postv2', ['/notification/allseen', {}]],
    ],
  ],
  AuthorityAPI: [
    AuthorityAPI,
    [
      ['fetch', [2], '$getv2', ['/authority/2']],
      ['fetchMessages', [2], '$getv2', ['/authority/2/message']],
      [
        'search',
        ['bath'],
        '$getv2',
        ['/authority', { search: 'bath', limit: 20 }],
      ],
      [
        'search',
        ['bath', 5],
        '$getv2',
        ['/authority', { search: 'bath', limit: 5 }],
      ],
    ],
  ],
  DomainAPI: [
    DomainAPI,
    [['fetch', [{ q: 'x' }], '$getv2', ['/domains', { q: 'x' }]]],
  ],
  GiftAidAPI: [
    GiftAidAPI,
    [
      ['list', [], '$getv2', ['/giftaid', { all: true }]],
      ['search', ['bob'], '$getv2', ['/giftaid', { search: 'bob' }]],
      [
        'save',
        [
          {
            period: 'This',
            fullname: 'A B',
            firstname: 'A',
            lastname: 'B',
            homeaddress: 'x',
            extra: 'dropped',
          },
        ],
        '$postv2',
        [
          '/giftaid',
          {
            period: 'This',
            fullname: 'A B',
            firstname: 'A',
            lastname: 'B',
            homeaddress: 'x',
          },
        ],
      ],
      [
        'edit',
        [1, 'Past', 'A B', 'addr', 'AB1', '3', 1, 0, 'A', 'B'],
        '$patchv2',
        [
          '/giftaid',
          {
            id: 1,
            period: 'Past',
            fullname: 'A B',
            firstname: 'A',
            lastname: 'B',
            homeaddress: 'addr',
            postcode: 'AB1',
            housenameornumber: '3',
            reviewed: 1,
            deleted: 0,
          },
        ],
      ],
      ['remove', [], '$delv2', ['/giftaid']],
    ],
  ],
  MicroVolunteeringAPI: [
    MicroVolunteeringAPI,
    [
      [
        'challenge',
        [{ types: ['x'] }],
        '$getv2',
        ['/microvolunteering', { types: ['x'] }],
      ],
      ['response', [{ id: 1 }], '$postv2', ['/microvolunteering', { id: 1 }]],
      ['fetch', [{ id: 2 }], '$getv2', ['/microvolunteering', { id: 2 }]],
    ],
  ],
  TrystAPI: [
    TrystAPI,
    [
      ['edit', [{ id: 1 }], '$patchv2', ['/tryst', { id: 1 }]],
      ['fetch', [{ a: 1 }], '$getv2', ['/tryst', { a: 1 }]],
      ['confirm', [4], '$postv2', ['/tryst', { id: 4, confirm: true }]],
      ['decline', [4], '$postv2', ['/tryst', { id: 4, decline: true }]],
      ['delete', [{ id: 4 }], '$delv2', ['/tryst', { id: 4 }]],
    ],
  ],
  UserSearchAPI: [
    UserSearchAPI,
    [
      ['fetch', [12], '$getv2', ['/user/12/search']],
      ['del', [3], '$delv2', ['/usersearch', { id: 3 }]],
    ],
  ],
  ShortlinksAPI: [
    ShortlinksAPI,
    [['fetch', [{ id: 1 }], '$getv2', ['/shortlink', { id: 1 }]]],
  ],
  AddressAPI: [
    AddressAPI,
    [
      ['fetchv2', [], '$getv2', ['/address']],
      ['fetchByIdv2', [5], '$getv2', ['/address/5', [], true]],
      ['fetchByIdv2', [5, false], '$getv2', ['/address/5', [], false]],
      ['add', [{ a: 1 }], '$putv2', ['/address', { a: 1 }]],
      ['update', [{ id: 1 }], '$patchv2', ['/address', { id: 1 }]],
      ['del', [5], '$delv2', ['/address/5']],
    ],
  ],
  AlertAPI: [
    AlertAPI,
    [
      ['fetch', [{ id: 1 }], '$getv2', ['/modtools/alert', { id: 1 }]],
      ['add', [{ s: 'x' }], '$putv2', ['/modtools/alert', { s: 'x' }]],
      ['record', [{ id: 1 }], '$postv2', ['/modtools/alert', { id: 1 }]],
    ],
  ],
  CommentAPI: [
    CommentAPI,
    [
      ['fetch', [{ userid: 1 }], '$getv2', ['/comment', { userid: 1 }]],
      ['save', [{ id: 1 }], '$patchv2', ['/comment', { id: 1 }]],
      ['del', [9], '$delv2', ['/comment/9']],
    ],
  ],
  DonationsAPI: [
    DonationsAPI,
    [
      [
        'add',
        [3, 10, '2026-01-01'],
        '$putv2',
        ['/donations', { userid: 3, amount: 10, date: '2026-01-01' }],
      ],
      [
        'stripeIntent',
        [{ amount: 5 }],
        '$postv2',
        ['/stripecreateintent', { amount: 5 }],
      ],
      [
        'stripeSubscription',
        [7],
        '$postv2',
        ['/stripecreatesubscription', { amount: 7 }],
      ],
    ],
  ],
  AdminsAPI: [
    AdminsAPI,
    [
      ['fetch', [{ id: 4 }], '$getv2', ['/modtools/admin/4']],
      ['fetch', [{ limit: 2 }], '$getv2', ['/modtools/admin', { limit: 2 }]],
      ['fetch', [undefined], '$getv2', ['/modtools/admin', undefined]],
      ['patch', [{ id: 1 }], '$patchv2', ['/modtools/admin', { id: 1 }, F]],
      ['del', [{ id: 1 }], '$delv2', ['/modtools/admin', { id: 1 }]],
      [
        'hold',
        [3],
        '$postv2',
        ['/modtools/admin', { id: 3, action: 'Hold' }, F],
      ],
      [
        'release',
        [3],
        '$postv2',
        ['/modtools/admin', { id: 3, action: 'Release' }],
      ],
    ],
  ],
}

// Volunteering and community events share an identical shape.
for (const [name, Cls, p] of [
  ['VolunteeringAPI', VolunteeringAPI, '/volunteering'],
  ['CommunityEventAPI', CommunityEventAPI, '/communityevent'],
]) {
  const rows = [
    ['fetch', [3], '$getv2', [p + '/3', {}, true]],
    ['fetch', [3, false], '$getv2', [p + '/3', {}, false]],
    ['list', [{ a: 1 }], '$getv2', [p, { a: 1 }]],
    ['listGroup', [8], '$getv2', [p + '/group/8']],
    ['save', [{ id: 1 }], '$patchv2', [p, { id: 1 }, F]],
    [
      'addGroup',
      [1, 2],
      '$patchv2',
      [p, { id: 1, groupid: 2, action: 'AddGroup' }],
    ],
    [
      'removeGroup',
      [1, 2],
      '$patchv2',
      [p, { id: 1, groupid: 2, action: 'RemoveGroup' }],
    ],
    [
      'setPhoto',
      [1, 9],
      '$patchv2',
      [p, { id: 1, photoid: 9, action: 'SetPhoto' }],
    ],
    [
      'addDate',
      [1, 's', 'e'],
      '$patchv2',
      [p, { id: 1, start: 's', end: 'e', action: 'AddDate' }],
    ],
    [
      'removeDate',
      [1, 6],
      '$patchv2',
      [p, { id: 1, dateid: 6, action: 'RemoveDate' }],
    ],
    ['del', [1], '$delv2', [p + '/1']],
  ]
  if (name === 'VolunteeringAPI') {
    rows.push(
      ['renew', [1], '$patchv2', [p, { id: 1, action: 'Renew' }]],
      ['expire', [1], '$patchv2', [p, { id: 1, action: 'Expire' }]],
      ['hold', [1], '$patchv2', [p, { id: 1, action: 'Hold' }, F]],
      ['release', [1], '$patchv2', [p, { id: 1, action: 'Release' }]]
    )
  }
  TABLES[name] = [Cls, rows]
}

describe.each(Object.entries(TABLES))('%s', (name, [Cls, rows]) => {
  it.each(rows)('%s(%j) calls %s', async (method, args, verb, expected) => {
    const api = make(Cls)
    await api[method](...args)
    expect(api[verb]).toHaveBeenCalledTimes(1)
    expect(api[verb]).toHaveBeenCalledWith(...expected)
    for (const other of VERBS.filter((v) => v !== verb)) {
      expect(api[other]).not.toHaveBeenCalled()
    }
  })
})

describe('held-conflict callbacks', () => {
  it.each([
    [SpammersAPI, 'patch', '$patchv2', 2, [{}]],
    [VolunteeringAPI, 'save', '$patchv2', 2, [{}]],
    [VolunteeringAPI, 'hold', '$patchv2', 2, [1]],
    [CommunityEventAPI, 'save', '$patchv2', 2, [{}]],
    [AdminsAPI, 'patch', '$patchv2', 2, [{}]],
    [AdminsAPI, 'hold', '$postv2', 2, [1]],
  ])(
    '%o.%s suppresses logging only for a heldby conflict',
    async (Cls, m, verb, idx, args) => {
      const api = make(Cls)
      await api[m](...args)
      const cb = api[verb].mock.calls[0][idx]
      expect(cb({ heldby: 5 })).toBe(false)
      expect(cb({})).toBe(true)
      expect(cb(undefined)).toBe(true)
    }
  )
})

describe('return value handling', () => {
  let api
  beforeEach(() => {
    api = null
  })

  it.each([
    [GroupAPI, 'add', '$postv2', [{ name: 'g' }]],
    [StoriesAPI, 'add', '$putv2', [{ headline: 'h' }]],
    [VolunteeringAPI, 'add', '$postv2', [{ title: 't' }]],
    [CommunityEventAPI, 'add', '$postv2', [{ title: 't' }]],
    [TrystAPI, 'add', '$putv2', [{ user1: 1 }]],
    [ShortlinksAPI, 'add', '$postv2', [{ url: 'u' }]],
    [CommentAPI, 'add', '$postv2', [{ userid: 1 }]],
    [AdminsAPI, 'add', '$postv2', [{ subject: 's' }]],
    [PartnershipsAPI, 'add', '$postv2', [{ name: 'p' }]],
    [PartnershipsAPI, 'addStatsJob', '$postv2', [{ authorityid: 1 }]],
  ])('%o.%s unwraps the created id', async (Cls, m, verb, args) => {
    api = make(Cls, { [verb]: { id: 99, other: 1 } })
    expect(await api[m](...args)).toBe(99)
    expect(api[verb]).toHaveBeenCalledTimes(1)
  })

  it('GroupAPI.add posts to /group', async () => {
    api = make(GroupAPI, { $postv2: { id: 4 } })
    await api.add({ name: 'g' })
    expect(api.$postv2).toHaveBeenCalledWith('/group', { name: 'g' })
  })

  it('SpammersAPI.fetch returns only spammers and context', async () => {
    api = make(SpammersAPI, {
      $getv2: { spammers: [1], context: { c: 1 }, junk: 1 },
    })
    expect(await api.fetch({ collection: 'Spammer' })).toEqual({
      spammers: [1],
      context: { c: 1 },
    })
    expect(api.$getv2).toHaveBeenCalledWith('/modtools/spammers', {
      collection: 'Spammer',
    })
  })

  it('AdminsAPI.test sends action Test and returns the token', async () => {
    api = make(AdminsAPI, { $postv2: { testtoken: 'tok' } })
    expect(await api.test({ subject: 's' })).toBe('tok')
    const call = api.$postv2.mock.calls[0]
    expect(call[0]).toBe('/modtools/admin')
    expect(call[1]).toEqual({ subject: 's', action: 'Test' })
    expect(call[2]({ error: 400 })).toBe(false)
    expect(call[2]({ error: 500 })).toBe(true)
    expect(call[2](undefined)).toBe(true)
  })

  it('AdminsAPI.add logs-suppresses only a 400', async () => {
    api = make(AdminsAPI, { $postv2: { id: 1 } })
    await api.add({})
    const cb = api.$postv2.mock.calls[0][2]
    expect(cb({ error: 400 })).toBe(false)
    expect(cb({ error: 500 })).toBe(true)
    expect(cb(null)).toBe(true)
  })

  it('GiftAidAPI.get returns the declaration without logging 404s', async () => {
    api = make(GiftAidAPI, { $getv2: { period: 'This' } })
    expect(await api.get()).toEqual({ period: 'This' })
    expect(api.$getv2).toHaveBeenCalledWith('/giftaid', {}, false)
  })

  it('GiftAidAPI.get returns null on error', async () => {
    api = make(GiftAidAPI)
    api.$getv2.mockRejectedValue(new Error('404'))
    expect(await api.get()).toBeNull()
  })

  it.each(['list', 'search'])(
    'GiftAidAPI.%s returns the giftaids array',
    async (m) => {
      api = make(GiftAidAPI, { $getv2: { giftaids: [{ id: 1 }], other: 1 } })
      expect(await api[m]('x')).toEqual([{ id: 1 }])
    }
  )

  it('MicroVolunteeringAPI.challenge returns the raw response', async () => {
    api = make(MicroVolunteeringAPI, { $getv2: { type: 'CheckMessage' } })
    expect(await api.challenge({})).toEqual({ type: 'CheckMessage' })
  })

  describe('DashboardAPI', () => {
    it('joins an array of components', async () => {
      api = make(DashboardAPI, { $getv2: { dashboard: { d: 1 } } })
      const params = { components: ['a', 'b'], group: 1 }
      expect(await api.fetch(params)).toEqual({ d: 1 })
      expect(api.$getv2).toHaveBeenCalledWith('/dashboard', {
        components: 'a,b',
        group: 1,
      })
    })

    it('leaves a string components value alone', async () => {
      api = make(DashboardAPI, { $getv2: { components: { c: 1 } } })
      expect(await api.fetch({ components: 'a,b' })).toEqual({ c: 1 })
      expect(api.$getv2).toHaveBeenCalledWith('/dashboard', {
        components: 'a,b',
      })
    })

    it('prefers dashboard over components', async () => {
      api = make(DashboardAPI, { $getv2: { dashboard: 'D', components: 'C' } })
      expect(await api.fetch({})).toBe('D')
    })

    it('fetchHeatmap requests heatmap only and unwraps it', async () => {
      api = make(DashboardAPI, { $getv2: { heatmap: [[1, 2]] } })
      expect(await api.fetchHeatmap({ ignored: 1 })).toEqual([[1, 2]])
      expect(api.$getv2).toHaveBeenCalledWith('/dashboard', { heatmap: true })
    })
  })

  describe('TeamAPI', () => {
    it('fetch prefers teams over team', async () => {
      api = make(TeamAPI, { $getv2: { team: 't', teams: ['a'] } })
      expect(await api.fetch({ id: 1 })).toEqual(['a'])
      expect(api.$getv2).toHaveBeenCalledWith('/team', { id: 1 })
    })

    it('fetch falls back to team', async () => {
      api = make(TeamAPI, { $getv2: { team: 't' } })
      expect(await api.fetch({})).toBe('t')
    })

    it.each([
      ['add', 'Add'],
      ['remove', 'Remove'],
    ])('%s patches with action %s', async (m, action) => {
      api = make(TeamAPI)
      await api[m]({ teamid: 1, userid: 2 })
      expect(api.$patchv2).toHaveBeenCalledWith('/team', {
        action,
        teamid: 1,
        userid: 2,
      })
    })
  })

  describe('DonationsAPI.fetch', () => {
    it('defaults groupid to null and rounds to integers', async () => {
      api = make(DonationsAPI, { $getv2: { target: '2000.7', raised: '1500' } })
      expect(await api.fetch()).toEqual({ target: 2000, raised: 1500 })
      expect(api.$getv2).toHaveBeenCalledWith('/donations', { groupid: null })
    })

    it('passes a groupid', async () => {
      api = make(DonationsAPI, { $getv2: { target: 1, raised: 2 } })
      await api.fetch(7)
      expect(api.$getv2).toHaveBeenCalledWith('/donations', { groupid: 7 })
    })

    it('yields NaN for missing figures', async () => {
      api = make(DonationsAPI, { $getv2: {} })
      const r = await api.fetch()
      expect(r.target).toBeNaN()
      expect(r.raised).toBeNaN()
    })
  })

  describe('PartnershipsAPI', () => {
    it.each([
      ['list', [], '$getv2', { partnerships: [1] }, [1], ['/partnership']],
      [
        'summary',
        [],
        '$getv2',
        { summary: { s: 1 } },
        { s: 1 },
        ['/partnership/summary'],
      ],
      [
        'listStatsJobs',
        [],
        '$getv2',
        { jobs: [2] },
        [2],
        ['/partnership/statsjob'],
      ],
      ['fetch', [4], '$getv2', { id: 4 }, { id: 4 }, ['/partnership/4']],
      [
        'patchGroups',
        [4, { g: 1 }],
        '$patchv2',
        { ok: 1 },
        { ok: 1 },
        ['/partnership/4/group', { g: 1 }],
      ],
      [
        'addPayment',
        [4, { amount: 5 }],
        '$postv2',
        { id: 11 },
        11,
        ['/partnership/4/payment', { amount: 5 }],
      ],
      [
        'edit',
        [4, { n: 1 }],
        '$patchv2',
        {},
        undefined,
        ['/partnership/4', { n: 1 }],
      ],
      ['remove', [4], '$delv2', {}, undefined, ['/partnership/4']],
      [
        'setYears',
        [4, [2025, 2026]],
        '$putv2',
        {},
        undefined,
        ['/partnership/4/year', { years: [2025, 2026] }],
      ],
      [
        'editPayment',
        [4, 9, { a: 1 }],
        '$patchv2',
        {},
        undefined,
        ['/partnership/4/payment/9', { a: 1 }],
      ],
      [
        'removePayment',
        [4, 9],
        '$delv2',
        {},
        undefined,
        ['/partnership/4/payment/9'],
      ],
      [
        'removeStatsJob',
        [6],
        '$delv2',
        {},
        undefined,
        ['/partnership/statsjob/6'],
      ],
    ])('%s', async (m, args, verb, resp, result, expected) => {
      api = make(PartnershipsAPI, { [verb]: resp })
      expect(await api[m](...args)).toEqual(result)
      expect(api[verb]).toHaveBeenCalledWith(...expected)
    })
  })
})
