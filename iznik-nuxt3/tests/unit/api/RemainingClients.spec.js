import { describe, it, expect, vi, afterEach } from 'vitest'
import RipplingAPI from '~/api/RipplingAPI'
import MergeAPI from '~/api/MergeAPI'
import PafAPI from '~/api/PafAPI'
import SupportAIAPI from '~/api/SupportAIAPI'
import HousekeeperAPI from '~/api/HousekeeperAPI'
import ImageAPI from '~/api/ImageAPI'
import AIImagesAPI from '~/api/AIImagesAPI'
import BanditAPI from '~/api/BanditAPI'
import BrowseAPI from '~/api/BrowseAPI'
import CharityAPI from '~/api/CharityAPI'
import DrivingAPI from '~/api/DrivingAPI'
import ExportAPI from '~/api/ExportAPI'
import IsochroneAPI from '~/api/IsochroneAPI'
import LogsAPI from '~/api/LogsAPI'
import StatusAPI from '~/api/StatusAPI'
import TownAPI from '~/api/TownAPI'
import VisualiseAPI from '~/api/VisualiseAPI'
import ModConfigsAPI from '~/api/ModConfigsAPI'
import SystemLogsAPI from '~/api/SystemLogsAPI'
import EmailTrackingAPI from '~/api/EmailTrackingAPI'

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

function make(Cls, returns = {}) {
  const api = new Cls({ public: { APIv2: 'https://api.test.com' } })
  for (const v of VERBS) {
    api[v] = vi.fn().mockResolvedValue(returns[v] ?? {})
  }
  return api
}

// Each row: [method, args, verb, expected args passed to the verb].
const TABLES = {
  RipplingAPI: [
    RipplingAPI,
    [
      ['fetchMetrics', [], '$getv2', ['/rippling/metrics', {}]],
      ['fetchMetrics', [5], '$getv2', ['/rippling/metrics', { groupid: 5 }]],
      [
        'fetchMetrics',
        [0, 'a'],
        '$getv2',
        ['/rippling/metrics', { start: 'a' }],
      ],
      [
        'fetchMetrics',
        [0, '', 'b'],
        '$getv2',
        ['/rippling/metrics', { end: 'b' }],
      ],
      [
        'fetchMetrics',
        [3, 'a', 'b'],
        '$getv2',
        ['/rippling/metrics', { groupid: 3, start: 'a', end: 'b' }],
      ],
      ['fetchDensity', [], '$getv2', ['/rippling/density', { days: 30 }]],
      ['fetchDensity', [90], '$getv2', ['/rippling/density', { days: 90 }]],
      [
        'fetchAnalytics',
        [],
        '$getv2',
        ['/rippling/analytics', { stratum: 'all' }],
      ],
      [
        'fetchAnalytics',
        ['rural', 's', 'e'],
        '$getv2',
        ['/rippling/analytics', { stratum: 'rural', start: 's', end: 'e' }],
      ],
      [
        'fetchAnalytics',
        ['dense', 's'],
        '$getv2',
        ['/rippling/analytics', { stratum: 'dense', start: 's' }],
      ],
      [
        'fetchAnalytics',
        ['dense', '', 'e'],
        '$getv2',
        ['/rippling/analytics', { stratum: 'dense', end: 'e' }],
      ],
      [
        'fetchAnalyticsDriveTimes',
        [],
        '$getv2',
        ['/rippling/analytics/drivetime', { stratum: 'all' }],
      ],
      [
        'fetchAnalyticsDriveTimes',
        ['suburban', 's', 'e'],
        '$getv2',
        [
          '/rippling/analytics/drivetime',
          { stratum: 'suburban', start: 's', end: 'e' },
        ],
      ],
      [
        'fetchAnalyticsDriveTimes',
        ['all', 's'],
        '$getv2',
        ['/rippling/analytics/drivetime', { stratum: 'all', start: 's' }],
      ],
      [
        'fetchAnalyticsDriveTimes',
        ['all', '', 'e'],
        '$getv2',
        ['/rippling/analytics/drivetime', { stratum: 'all', end: 'e' }],
      ],
      [
        'scoreAnalyticsDriveTimes',
        [[1, 2]],
        '$postv2',
        ['/rippling/analytics/drivetime/score', { posts: [1, 2] }],
      ],
      [
        'aggregateAnalyticsDriveTimes',
        [[{ a: 1 }]],
        '$postv2',
        ['/rippling/analytics/drivetime/aggregate', { obs: [{ a: 1 }] }],
      ],
    ],
  ],
  MergeAPI: [
    MergeAPI,
    [
      ['ask', [{ id: 1 }], '$putv2', ['/merge', { id: 1 }]],
      ['ignore', [{ id: 1 }], '$delv2', ['/merge', { id: 1 }]],
      [
        'accept',
        [{ id: 1, uid: 'u', user1: 2, user2: 3, extra: 'dropped' }],
        '$postv2',
        ['/merge', { id: 1, uid: 'u', user1: 2, user2: 3, action: 'Accept' }],
      ],
      [
        'reject',
        [{ id: 1, uid: 'u', user1: 2, user2: 3, extra: 'dropped' }],
        '$postv2',
        ['/merge', { id: 1, uid: 'u', user1: 2, user2: 3, action: 'Reject' }],
      ],
    ],
  ],
  PafAPI: [
    PafAPI,
    [
      ['fetchImports', [], '$getv2', ['/sysadmin/paf']],
      ['fetchLatest', [], '$getv2', ['/sysadmin/paf/latest']],
      // The third argument false keeps expected refusals out of Sentry.
      ['startImport', ['k'], '$postv2', ['/sysadmin/paf', { key: 'k' }, false]],
    ],
  ],
  SupportAIAPI: [
    SupportAIAPI,
    [
      ['fetchRuns', [{ a: 1 }], '$getv2', ['/supportai/runs', { a: 1 }]],
      ['fetchRun', [7], '$getv2', ['/supportai/runs/7']],
      ['rate', [7, 5], '$patchv2', ['/supportai/runs', { id: 7, rating: 5 }]],
      [
        'rate',
        [7, 1, 'meh'],
        '$patchv2',
        ['/supportai/runs', { id: 7, rating: 1, comment: 'meh' }],
      ],
      [
        'rate',
        [7, 1, ''],
        '$patchv2',
        ['/supportai/runs', { id: 7, rating: 1, comment: '' }],
      ],
    ],
  ],
  HousekeeperAPI: [
    HousekeeperAPI,
    [
      ['fetchTasks', [], '$getv2', ['/housekeeper/tasks']],
      ['fetchCronJobs', [], '$getv2', ['/housekeeper/cronjobs']],
      ['completeTask', ['t1'], '$postv2', ['/housekeeper/tasks/t1/complete']],
    ],
  ],
  ImageAPI: [ImageAPI, [['post', [{ a: 1 }], '$postv2', ['/image', { a: 1 }]]]],
  AIImagesAPI: [
    AIImagesAPI,
    [
      ['review', [], '$getv2', ['/admin/ai-images/review']],
      ['count', [], '$getv2', ['/admin/ai-images/count']],
      [
        'regenerate',
        [4, 'n'],
        '$postv2',
        ['/admin/ai-images/4/regenerate', { notes: 'n' }],
      ],
      [
        'accept',
        [4, 'ext'],
        '$postv2',
        ['/admin/ai-images/4/accept', { pending_externaluid: 'ext' }],
      ],
      ['keep', [4], '$postv2', ['/admin/ai-images/4/keep', {}]],
      ['suppress', [4], '$postv2', ['/admin/ai-images/4/suppress', {}]],
    ],
  ],
  BanditAPI: [
    BanditAPI,
    [
      [
        'shown',
        [{ uid: 'u', variant: 'v' }],
        '$postv2',
        ['/abtest', { uid: 'u', variant: 'v', shown: true }],
      ],
      [
        'chosen',
        [{ uid: 'u', variant: 'v', score: 2, info: 'i' }],
        '$postv2',
        [
          '/abtest',
          { uid: 'u', variant: 'v', action: true, score: 2, info: 'i' },
        ],
      ],
    ],
  ],
  BrowseAPI: [
    BrowseAPI,
    [
      ['fetchScrollDepth', [], '$getv2', ['/modtools/scroll/depth', {}]],
      [
        'fetchScrollDepth',
        [{ start: 'a', end: 'b' }],
        '$getv2',
        ['/modtools/scroll/depth', { start: 'a', end: 'b' }],
      ],
    ],
  ],
  CharityAPI: [
    CharityAPI,
    [['signup', [{ n: 1 }], '$postv2', ['/charities', { n: 1 }]]],
  ],
  DrivingAPI: [
    DrivingAPI,
    [
      [
        'distances',
        [[{ lat: 1, lng: 2 }]],
        '$postv2',
        ['/drivedistance', { targets: [{ lat: 1, lng: 2 }] }],
      ],
    ],
  ],
  IsochroneAPI: [
    IsochroneAPI,
    [['fetchMessages', [{ a: 1 }], '$getv2', ['/isochrone/message', { a: 1 }]]],
  ],
  LogsAPI: [
    LogsAPI,
    [
      ['fetch', [{ a: 1 }], '$getv2', ['/modtools/logs', { a: 1 }]],
      ['src', ['fb'], '$postv2', ['/src', { src: 'fb' }]],
    ],
  ],
  StatusAPI: [
    StatusAPI,
    [['fetch', [{ a: 1 }], '$getv2', ['/status', { a: 1 }]]],
  ],
  TownAPI: [
    TownAPI,
    [
      [
        'fetchNear',
        [1, 2, 30],
        '$getv2',
        ['/town/near', { lat: 1, lng: 2, minutes: 30 }],
      ],
      [
        'fetchNear',
        [1, 2, 30, false],
        '$getv2',
        ['/town/near', { lat: 1, lng: 2, minutes: 30 }],
      ],
      [
        'fetchNear',
        [1, 2, 30, true],
        '$getv2',
        ['/town/near', { lat: 1, lng: 2, minutes: 30, polygon: 1 }],
      ],
    ],
  ],
  VisualiseAPI: [
    VisualiseAPI,
    [['fetch', [{ a: 1 }], '$getv2', ['/visualise', { a: 1 }]]],
  ],
  ModConfigsAPI: [
    ModConfigsAPI,
    [
      ['fetchStdMsg', [9], '$getv2', ['/modtools/stdmsg', { id: 9 }]],
      ['listConfigs', [{ a: 1 }], '$getv2', ['/modtools/modconfig', { a: 1 }]],
      [
        'patchConfig',
        [{ id: 1 }],
        '$patchv2',
        ['/modtools/modconfig', { id: 1 }],
      ],
      [
        'deleteConfig',
        [{ id: 1 }],
        '$delv2',
        ['/modtools/modconfig', { id: 1 }],
      ],
      ['patchStdMsg', [{ id: 1 }], '$patchv2', ['/modtools/stdmsg', { id: 1 }]],
      ['deleteStdMsg', [{ id: 1 }], '$delv2', ['/modtools/stdmsg', { id: 1 }]],
    ],
  ],
  SystemLogsAPI: [
    SystemLogsAPI,
    [
      ['fetch', [{ a: 1 }], '$getv2', ['/modtools/systemlogs', { a: 1 }]],
      [
        'fetchCounts',
        [{ a: 1 }],
        '$getv2',
        ['/modtools/systemlogs/counts', { a: 1 }],
      ],
      [
        'fetchHeadersByRequestId',
        ['req1'],
        '$getv2',
        [
          '/modtools/systemlogs',
          { sources: 'api_headers', search: 'req1', start: '24h', limit: 1 },
        ],
      ],
    ],
  ],
  EmailTrackingAPI: [
    EmailTrackingAPI,
    [
      ['fetchStats', [], '$getv2', ['/modtools/email/stats', {}]],
      [
        'fetchStats',
        [{ type: 'x' }],
        '$getv2',
        ['/modtools/email/stats', { type: 'x' }],
      ],
      ['fetchDeferrals', [], '$getv2', ['/modtools/email/deferrals']],
      ['fetchUserEmails', [12], '$getv2', ['/modtools/email/user/12', {}]],
      [
        'fetchUserEmails',
        ['12', { limit: 5 }],
        '$getv2',
        ['/modtools/email/user/12', { limit: 5 }],
      ],
      [
        'fetchUserEmails',
        ['a@b.com', { limit: 5 }],
        '$getv2',
        ['/modtools/email/user/0', { limit: 5, email: 'a@b.com' }],
      ],
      [
        'fetchTimeSeries',
        [],
        '$getv2',
        ['/modtools/email/stats/timeseries', {}],
      ],
      [
        'fetchTimeSeries',
        [{ a: 1 }],
        '$getv2',
        ['/modtools/email/stats/timeseries', { a: 1 }],
      ],
      ['fetchStatsByType', [], '$getv2', ['/modtools/email/stats/bytype', {}]],
      [
        'fetchStatsByType',
        [{ a: 1 }],
        '$getv2',
        ['/modtools/email/stats/bytype', { a: 1 }],
      ],
      [
        'fetchTopClickedLinks',
        [],
        '$getv2',
        ['/modtools/email/stats/clicks', {}],
      ],
      [
        'fetchTopClickedLinks',
        [{ limit: 0 }],
        '$getv2',
        ['/modtools/email/stats/clicks', { limit: 0 }],
      ],
      [
        'fetchDigestPositions',
        [],
        '$getv2',
        ['/modtools/email/stats/digestpositions', {}],
      ],
      [
        'fetchDigestPositions',
        [{ type: 't' }],
        '$getv2',
        ['/modtools/email/stats/digestpositions', { type: 't' }],
      ],
      [
        'fetchReengageEffectiveness',
        [],
        '$getv2',
        ['/modtools/email/stats/reengage', {}],
      ],
      [
        'fetchReengageEffectiveness',
        [{ start: 's' }],
        '$getv2',
        ['/modtools/email/stats/reengage', { start: 's' }],
      ],
    ],
  ],
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

describe('return values', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('MergeAPI.fetch returns the merge property', async () => {
    const api = make(MergeAPI, { $getv2: { merge: { id: 3 } } })
    expect(await api.fetch({ a: 1 })).toEqual({ id: 3 })
    expect(api.$getv2).toHaveBeenCalledWith('/merge', { a: 1 })
  })

  it('BanditAPI.choose returns the variant', async () => {
    const api = make(BanditAPI, { $getv2: { variant: 'B' } })
    expect(await api.choose({ uid: 'u' })).toBe('B')
    expect(api.$getv2).toHaveBeenCalledWith('/abtest', { uid: 'u' })
  })

  it('ModConfigsAPI.fetchConfig returns the config property', async () => {
    const api = make(ModConfigsAPI, { $getv2: { config: { id: 2 } } })
    expect(await api.fetchConfig({ id: 2 })).toEqual({ id: 2 })
    expect(api.$getv2).toHaveBeenCalledWith('/modtools/modconfig', { id: 2 })
  })

  it.each([
    ['addModConfig', '/modtools/modconfig'],
    ['addStdMsg', '/modtools/stdmsg'],
  ])('ModConfigsAPI.%s returns the new id', async (method, path) => {
    const api = make(ModConfigsAPI, { $postv2: { id: 77 } })
    expect(await api[method]({ n: 1 })).toBe(77)
    expect(api.$postv2).toHaveBeenCalledWith(path, { n: 1 })
  })

  it.each(['patchConfig', 'deleteConfig', 'patchStdMsg', 'deleteStdMsg'])(
    'ModConfigsAPI.%s resolves to undefined',
    async (method) => {
      const api = make(ModConfigsAPI, { $patchv2: { x: 1 }, $delv2: { x: 1 } })
      expect(await api[method]({ id: 1 })).toBeUndefined()
    }
  )

  it('passes the verb result through for plain delegates', async () => {
    const api = make(PafAPI, { $postv2: { ok: true } })
    expect(await api.startImport('k')).toEqual({ ok: true })
  })

  it('ExportAPI.create posts a time-based dup value', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-01-02T03:04:05Z'))
    const api = make(ExportAPI)
    await api.create()
    expect(api.$postv2).toHaveBeenCalledWith('/export', {
      dup: new Date('2026-01-02T03:04:05Z').getTime(),
    })
  })

  it('ExportAPI.status passes id and tag', async () => {
    const api = make(ExportAPI)
    await api.status(4, 'tg')
    expect(api.$getv2).toHaveBeenCalledWith('/export', { id: 4, tag: 'tg' })
  })

  describe('SystemLogsAPI.fetchHeadersByTimestamp', () => {
    const ts = '2026-01-02T03:04:05.000Z'
    const base = {
      sources: 'api_headers',
      start: '2026-01-02T03:04:04.000Z',
      end: '2026-01-02T03:04:06.000Z',
      limit: 10,
    }

    it('uses a plus or minus one second window with no optional filters', async () => {
      const api = make(SystemLogsAPI)
      await api.fetchHeadersByTimestamp(ts)
      expect(api.$getv2).toHaveBeenCalledWith('/modtools/systemlogs', base)
    })

    it('adds userid and search when given', async () => {
      const api = make(SystemLogsAPI)
      await api.fetchHeadersByTimestamp(ts, '/api/x', 42)
      expect(api.$getv2).toHaveBeenCalledWith('/modtools/systemlogs', {
        ...base,
        userid: 42,
        search: '/api/x',
      })
    })

    it('adds only search when no user', async () => {
      const api = make(SystemLogsAPI)
      await api.fetchHeadersByTimestamp(ts, '/api/x')
      expect(api.$getv2).toHaveBeenCalledWith('/modtools/systemlogs', {
        ...base,
        search: '/api/x',
      })
    })

    it('adds only userid when no endpoint', async () => {
      const api = make(SystemLogsAPI)
      await api.fetchHeadersByTimestamp(ts, null, 42)
      expect(api.$getv2).toHaveBeenCalledWith('/modtools/systemlogs', {
        ...base,
        userid: 42,
      })
    })
  })
})
