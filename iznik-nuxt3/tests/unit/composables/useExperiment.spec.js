import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const auth = vi.hoisted(() => ({ user: null }))
const log = vi.hoisted(() => ({ action: vi.fn() }))
vi.mock('~/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('~/composables/useTrace', () => ({
  getSessionId: () => 'sess-abc',
}))
vi.mock('~/composables/useClientLog', () => ({ action: log.action }))

let mod

const NOW = new Date('2026-10-14T12:00:00Z')
const NOW_S = Math.floor(NOW.getTime() / 1000)

function config(experiments) {
  globalThis.__testRuntimeConfig = () => ({
    public: { UX_EXPERIMENTS: experiments },
  })
}

// A running entry that ends in `hours` hours (the batch writes ends_at + 1 hour).
function running(arms, hours = 12) {
  return { arms, until: NOW_S + hours * 3600 }
}

describe('useExperiment', () => {
  beforeEach(async () => {
    vi.resetModules()
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    localStorage.clear()
    log.action.mockClear()
    auth.user = null
    config('{}')
    mod = await import('~/composables/useExperiment')
  })

  afterEach(() => {
    vi.useRealTimers()
    delete globalThis.__testRuntimeConfig
  })

  describe('crc32 matches PHP crc32()', () => {
    // Generated with: php -r 'echo crc32($s);' in the batch container.
    it.each([
      ['123|exp', 3831333189],
      ['sess-abc|ab', 4117368972],
      ['', 0],
      ['0|x', 108983158],
      ['42|give-hint', 4236068640],
      ['f47ac10b-58cc-4372-a567-0e02b2c3d479|checkout', 3452454329],
      ['7|ünï', 2444113647],
      ['99999999|longer-experiment-name', 2670517058],
    ])('%j', (input, expected) => {
      expect(mod.crc32(input)).toBe(expected)
    })
  })

  describe('bucket', () => {
    it('is crc32(id|name) % 100, like ExperimentBucket::bucket', () => {
      expect(mod.bucket('123', 'exp')).toBe(89)
      expect(mod.bucket('sess-abc', 'ab')).toBe(72)
      expect(mod.bucket('42', 'give-hint')).toBe(40)
    })

    // The same vectors are pinned in iznik-batch (ExperimentBucketTest) and iznik-server-go
    // (ux_serverarm_test.go). A change to one runtime that moves a bucket fails all three.
    const IDS = [1, 2, 3, 5, 12345, 999999]
    it.each([
      ['confirm-reply', '', [44, 73, 77, 55, 1, 38]],
      ['digest-subject', '', [87, 77, 72, 88, 29, 68]],
      ['digest-subject', 'r2', [88, 97, 5, 2, 11, 58]],
    ])('matches the shared vectors for %s with salt %j', (name, salt, want) => {
      expect(IDS.map((id) => mod.bucket(id, name, salt))).toEqual(want)
    })

    it('reads an empty salt as no salt', () => {
      expect(mod.bucket(5, 'digest-subject', '')).toBe(
        mod.bucket(5, 'digest-subject')
      )
      expect(mod.bucket(5, 'digest-subject', 'r2')).not.toBe(
        mod.bucket(5, 'digest-subject')
      )
    })
  })

  describe('a salted entry', () => {
    const arms = { control: [0, 49], items: [50, 99] }

    it('places a member by the salted bucket', () => {
      // Member 3 is in items without a salt (bucket 72) and in control with the salt r2 (bucket 5).
      auth.user = { id: 3 }
      mod.setLiveExperiments({ 'digest-subject': running(arms) })
      expect(mod.useExperiment('digest-subject')).toBe('items')

      localStorage.clear()
      mod.resetLiveExperiments()
      mod.setLiveExperiments({
        'digest-subject': { ...running(arms), salt: 'r2' },
      })
      expect(mod.useExperiment('digest-subject')).toBe('control')
    })

    it('keeps its salt once adopted, or adoption would move everyone', () => {
      // Member 2 has bucket 77 without the salt, so in the adopted arm, and bucket 97 with the
      // salt r2, so in the holdout.
      auth.user = { id: 2 }
      const adopted = { items: [0, 89], control: [90, 99] }
      mod.setLiveExperiments({
        'digest-subject': { arms: adopted, adopted: true, salt: 'r2' },
      })
      expect(mod.useExperiment('digest-subject')).toBe('control')

      mod.setLiveExperiments({
        'digest-subject': { arms: adopted, adopted: true },
      })
      expect(mod.useExperiment('digest-subject')).toBe('items')
    })

    it('stamps the salt on the entry it publishes', () => {
      const entries = mod.normaliseConfig({
        a: { ...running(arms), salt: 'r2' },
        b: running(arms),
        c: { ...running(arms), salt: null },
      })
      expect(entries.a.salt).toBe('r2')
      expect(entries.b.salt).toBe('')
      expect(entries.c.salt).toBe('')
    })

    it.each([
      ['a space', 'bad salt'],
      ['17 characters', 'abcdefghijklmnopq'],
      ['an empty string', ''],
      ['a number', 5],
      ['true', true],
      ['a list', ['r2']],
      ['an object', { r: 2 }],
      ['a symbol', 'r2!'],
      ['a newline at the end', 'r2\n'],
    ])('drops the whole entry when the salt is %s', (_label, salt) => {
      auth.user = { id: 3 }
      mod.setLiveExperiments({
        bad: { ...running(arms), salt },
        good: { ...running(arms), salt: 'ok_-1' },
        adoptedbad: { arms: { items: [0, 99] }, adopted: true, salt },
      })
      expect(mod.useExperiment('bad')).toBeNull()
      expect(mod.useExperiment('adoptedbad')).toBeNull()
      expect(mod.useExperiment('good')).not.toBeNull()
    })

    it('accepts a salt of 16 characters', () => {
      auth.user = { id: 3 }
      mod.setLiveExperiments({
        exp: { ...running(arms), salt: 'abcdefghijklmnop' },
      })
      expect(mod.useExperiment('exp')).not.toBeNull()
    })
  })

  describe('the old flat shape, from runtime config only', () => {
    const exps = JSON.stringify({
      exp: { control: [0, 49], b: [50, 99] },
      'give-hint': { control: [0, 39], b: [40, 99] },
    })

    it('returns null for an experiment that is not configured', () => {
      config(exps)
      expect(mod.useExperiment('nope')).toBeNull()
    })

    it('returns null when nothing is configured at all', () => {
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('assigns by user id when logged in', () => {
      config(exps)
      auth.user = { id: 123 }
      // bucket 89 falls in b.
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('assigns by session id when logged out', () => {
      config(exps)
      const expected = mod.bucket('sess-abc', 'exp') <= 49 ? 'control' : 'b'
      expect(mod.useExperiment('exp')).toBe(expected)
    })

    it('treats the arm ranges as inclusive at both ends', () => {
      config(exps)
      auth.user = { id: 42 }
      expect(mod.useExperiment('give-hint')).toBe('b')
      // PHP: crc32("18|exp") % 100 === 49, the top of control.
      auth.user = { id: 18 }
      expect(mod.bucket('18', 'exp')).toBe(49)
      expect(mod.useExperiment('exp')).toBe('control')
    })

    it('returns null when the bucket is outside every range', () => {
      config(JSON.stringify({ exp: { control: [0, 10] } }))
      auth.user = { id: 123 }
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('accepts the config already parsed as an object', () => {
      config({ exp: { control: [0, 49], b: [50, 99] } })
      auth.user = { id: 123 }
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('survives invalid JSON config', () => {
      config('{not json')
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('is never honoured from the live key', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments('{"exp":{"control":[0,49],"b":[50,99]}}')
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('has no expiry and is not sticky', () => {
      config(exps)
      auth.user = { id: 123 }
      expect(mod.useExperiment('exp')).toBe('b')
      expect(localStorage.getItem(mod.ARM_STORAGE_PREFIX + 'exp')).toBeNull()
    })
  })

  describe('live running entries and their expiry', () => {
    it('serves a running entry whose until is ahead', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: running({ control: [0, 34], b: [35, 99] }),
      })
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('accepts the config as a JSON string', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments(
        JSON.stringify({ exp: running({ control: [0, 34], b: [35, 99] }) })
      )
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('treats a missing until as control', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: { arms: { control: [0, 34], b: [35, 99] } },
      })
      expect(mod.useExperiment('exp')).toBeNull()
      expect(mod.experimentVariant()).toBe('')
    })

    it('treats a past until as control', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: { arms: { control: [0, 34], b: [35, 99] }, until: NOW_S - 1 },
      })
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('treats an until more than 25 hours away as control', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: running({ control: [0, 34], b: [35, 99] }, 26),
      })
      expect(mod.useExperiment('exp')).toBeNull()
      mod.setLiveExperiments({
        exp: running({ control: [0, 34], b: [35, 99] }, 24.9),
      })
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('stops serving the variant by itself once until passes in an open tab', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: running({ control: [0, 34], b: [35, 99] }, 1),
      })
      expect(mod.useExperiment('exp')).toBe('b')
      vi.setSystemTime(new Date(NOW.getTime() + 2 * 3600 * 1000))
      expect(mod.useExperiment('exp')).toBeNull()
      expect(mod.experimentVariant()).toBe('')
    })

    it('honours at most five running entries, the first five by name', () => {
      auth.user = { id: 123 }
      const cfg = {}
      for (const n of ['g', 'a', 'f', 'b', 'e', 'c', 'd']) {
        cfg[n] = running({ b: [0, 99] })
      }
      mod.setLiveExperiments(cfg)
      const got = ['a', 'b', 'c', 'd', 'e', 'f', 'g'].map((n) =>
        mod.useExperiment(n)
      )
      expect(got).toEqual(['b', 'b', 'b', 'b', 'b', null, null])
    })

    it('does not count adopted entries towards the five', () => {
      auth.user = { id: 123 }
      const cfg = { z: { arms: { b: [0, 99] }, adopted: true } }
      for (const n of ['a', 'b', 'c', 'd', 'e'])
        cfg[n] = running({ b: [0, 99] })
      mod.setLiveExperiments(cfg)
      expect(mod.useExperiment('e')).toBe('b')
      expect(mod.useExperiment('z')).toBe('b')
    })

    it('skips an arm with an empty range rather than dropping the whole entry', () => {
      auth.user = { id: 123 }
      // id 123 buckets into b here; the empty arm [99, 98] holds nobody.
      mod.setLiveExperiments({
        exp: running({ control: [0, 34], b: [35, 99], empty: [99, 98] }),
      })
      expect(mod.useExperiment('exp')).toBe('b')
      expect(mod.experimentVariant()).toBe('exp:b')
      // An entry whose only arms are empty serves nobody, as control.
      mod.setLiveExperiments({ exp2: running({ b: [99, 98] }) })
      expect(mod.useExperiment('exp2')).toBeNull()
    })

    it('ignores malformed entries and unsafe names', () => {
      auth.user = { id: 123 }
      // A real "__proto__" key, which an object literal would not make.
      mod.setLiveExperiments(
        '{"bad1":{"arms":{"b":[0,100]},"until":' +
          running({}).until +
          '},' +
          '"bad2":{"arms":{"b":[60,50]},"until":' +
          running({}).until +
          '},' +
          '"bad3":{"arms":{"b":[0]},"until":' +
          running({}).until +
          '},' +
          '"bad4":{"arms":{"bad arm!":[0,99]},"until":' +
          running({}).until +
          '},' +
          '"bad5":"x",' +
          '"not a name":{"arms":{"b":[0,99]},"until":' +
          running({}).until +
          '},' +
          '"__proto__":{"arms":{"b":[0,99]},"until":' +
          running({}).until +
          '},' +
          '"constructor":{"arms":{"b":[0,99]},"until":' +
          running({}).until +
          '},' +
          '"ok":{"arms":{"b":[0,99]},"until":' +
          running({}).until +
          '}}'
      )
      for (const n of ['bad1', 'bad2', 'bad3', 'bad4', 'bad5', 'not a name']) {
        expect(mod.useExperiment(n)).toBeNull()
      }
      expect(mod.useExperiment('constructor')).toBeNull()
      expect(mod.useExperiment('toString')).toBeNull()
      expect(mod.useExperiment('ok')).toBe('b')
    })
  })

  describe('adopted entries', () => {
    it('are served from the config and never expire', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: { arms: { b: [0, 89], control: [90, 99] }, adopted: true },
      })
      expect(mod.useExperiment('exp')).toBe('b')
      vi.setSystemTime(new Date(NOW.getTime() + 60 * 24 * 3600 * 1000))
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('are not sticky, so the holdout moves with the config', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: { arms: { b: [0, 89], control: [90, 99] }, adopted: true },
      })
      expect(mod.useExperiment('exp')).toBe('b')
      expect(localStorage.getItem(mod.ARM_STORAGE_PREFIX + 'exp')).toBeNull()
      mod.setLiveExperiments({
        exp: { arms: { control: [0, 99] }, adopted: true },
      })
      expect(mod.useExperiment('exp')).toBe('control')
    })

    it('still honour an old stored copy, which only running entries lose', async () => {
      const old = NOW.getTime() - 5 * 3600 * 1000
      localStorage.setItem(
        mod.LIVE_STORAGE_KEY,
        JSON.stringify({
          fetchedAt: old,
          config: {
            a: { arms: { b: [0, 99] }, adopted: true },
            r: running({ b: [0, 99] }),
          },
        })
      )
      vi.resetModules()
      config('{}')
      const fresh = await import('~/composables/useExperiment')
      auth.user = { id: 123 }
      expect(fresh.useExperiment('a')).toBe('b')
      expect(fresh.useExperiment('r')).toBeNull()
    })
  })

  describe('sticky arm', () => {
    const cfg = () => ({
      exp: running({ control: [0, 49], b: [50, 99] }),
    })

    it('keeps the arm worked out at first exposure in localStorage with its until', () => {
      auth.user = { id: 123 }
      const entry = cfg()
      mod.setLiveExperiments(entry)
      expect(mod.useExperiment('exp')).toBe('b')
      expect(
        JSON.parse(localStorage.getItem(mod.ARM_STORAGE_PREFIX + 'exp'))
      ).toEqual({ arm: 'b', until: entry.exp.until })
    })

    it('keeps the arm after signing in mid-flow', () => {
      mod.setLiveExperiments(cfg())
      const first = mod.useExperiment('exp')
      // User 18 would be in control (bucket 49) if assigned afresh; user 123 in b.
      const other = first === 'b' ? 18 : 123
      expect(mod.bucket(String(other), 'exp') <= 49 ? 'control' : 'b').not.toBe(
        first
      )
      auth.user = { id: other }
      expect(mod.useExperiment('exp')).toBe(first)
      expect(mod.experimentVariant().endsWith(`exp:${first}`)).toBe(true)
    })

    it('keeps the arm when the bandit moves the boundaries', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: running({ control: [0, 49], b: [50, 99] }),
      })
      const first = mod.useExperiment('exp')
      expect(first).toBe('b')
      // Bucket 89 would now be control, but the member keeps what they were shown.
      const moved = { exp: running({ b: [0, 34], control: [35, 99] }) }
      moved.exp.until = JSON.parse(
        localStorage.getItem(mod.ARM_STORAGE_PREFIX + 'exp')
      ).until
      mod.setLiveExperiments(moved)
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('uses the stored arm on the next visit', async () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments(cfg())
      const first = mod.useExperiment('exp')
      vi.resetModules()
      config('{}')
      const fresh = await import('~/composables/useExperiment')
      // The stored config copy is fresh, so it still serves, and the arm is the stored one.
      auth.user = { id: 18 }
      expect(fresh.useExperiment('exp')).toBe(first)
    })

    it('forgets the arm when the experiment leaves the config', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments(cfg())
      mod.useExperiment('exp')
      mod.setLiveExperiments({})
      expect(mod.useExperiment('exp')).toBeNull()
      // Back in the config with a new run (new until): worked out afresh.
      auth.user = { id: 18 }
      mod.setLiveExperiments({
        exp: running({ control: [0, 49], b: [50, 99] }, 20),
      })
      expect(mod.useExperiment('exp')).toBe('control')
    })

    it('forgets the arm once its until has passed', () => {
      auth.user = { id: 123 }
      const first = cfg()
      mod.setLiveExperiments(first)
      mod.useExperiment('exp')
      vi.setSystemTime(new Date((first.exp.until + 10) * 1000))
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('forgets an arm the config no longer has', () => {
      auth.user = { id: 123 }
      const e = cfg()
      mod.setLiveExperiments(e)
      expect(mod.useExperiment('exp')).toBe('b')
      mod.setLiveExperiments({
        exp: { arms: { control: [0, 99] }, until: e.exp.until },
      })
      expect(mod.useExperiment('exp')).toBe('control')
    })

    it('works with localStorage blocked, for the page view', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments(cfg())
      const spy = vi
        .spyOn(Storage.prototype, 'getItem')
        .mockImplementation(() => {
          throw new Error('blocked')
        })
      const set = vi
        .spyOn(Storage.prototype, 'setItem')
        .mockImplementation(() => {
          throw new Error('blocked')
        })
      expect(mod.useExperiment('exp')).toBe('b')
      auth.user = { id: 18 }
      expect(mod.useExperiment('exp')).toBe('b')
      spy.mockRestore()
      set.mockRestore()
    })
  })

  describe('the live copy in localStorage', () => {
    it('is stored with its fetch time', () => {
      mod.setLiveExperiments({ later: running({ b: [0, 99] }) })
      const stored = JSON.parse(localStorage.getItem(mod.LIVE_STORAGE_KEY))
      expect(stored.fetchedAt).toBe(NOW.getTime())
      expect(stored.config.later.arms).toEqual({ b: [0, 99] })
    })

    it('serves running entries from a fresh stored copy before any fetch', async () => {
      mod.setLiveExperiments({ later: running({ b: [0, 99] }) })
      vi.resetModules()
      config('{}')
      const fresh = await import('~/composables/useExperiment')
      auth.user = { id: 123 }
      expect(fresh.useExperiment('later')).toBe('b')
    })

    it('ignores running entries in a stored copy older than 2 hours', async () => {
      mod.setLiveExperiments({ later: running({ b: [0, 99] }) })
      vi.setSystemTime(new Date(NOW.getTime() + 2 * 3600 * 1000 + 1000))
      vi.resetModules()
      config('{}')
      const fresh = await import('~/composables/useExperiment')
      auth.user = { id: 123 }
      expect(fresh.useExperiment('later')).toBeNull()
    })

    it('ignores a stored copy in the old format, which has no fetch time', async () => {
      localStorage.setItem(
        mod.LIVE_STORAGE_KEY,
        JSON.stringify({ exp: { control: [0, 49], b: [50, 99] } })
      )
      vi.resetModules()
      config('{}')
      const fresh = await import('~/composables/useExperiment')
      auth.user = { id: 123 }
      expect(fresh.useExperiment('exp')).toBeNull()
    })

    it('ignores a corrupt stored copy', async () => {
      localStorage.setItem(mod.LIVE_STORAGE_KEY, '{nope')
      vi.resetModules()
      config('{}')
      const fresh = await import('~/composables/useExperiment')
      auth.user = { id: 123 }
      expect(fresh.useExperiment('exp')).toBeNull()
    })

    it('prefers the live arms over the environment, and empty means none', () => {
      config(JSON.stringify({ exp: { control: [0, 49], b: [50, 99] } }))
      auth.user = { id: 123 }
      expect(mod.useExperiment('exp')).toBe('b')
      mod.setLiveExperiments('{}')
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('treats anything but an object as no experiments', () => {
      mod.setLiveExperiments('[1,2]')
      expect(mod.useExperiment('exp')).toBeNull()
      mod.setLiveExperiments(null)
      expect(mod.useExperiment('exp')).toBeNull()
    })
  })

  describe('exposure event', () => {
    beforeEach(() => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: running({ control: [0, 49], b: [50, 99] }),
      })
    })

    it('is sent once per page view per experiment, with name and arm', () => {
      mod.useExperiment('exp')
      mod.useExperiment('exp')
      mod.useExperiment('exp')
      expect(log.action).toHaveBeenCalledTimes(1)
      expect(log.action).toHaveBeenCalledWith('experiment_exposure', {
        experiment: 'exp',
        arm: 'b',
      })
    })

    it('is sent per experiment', () => {
      mod.setLiveExperiments({
        exp: running({ b: [0, 99] }),
        other: running({ control: [0, 99] }),
      })
      mod.useExperiment('exp')
      mod.useExperiment('other')
      expect(log.action).toHaveBeenCalledTimes(2)
    })

    it('is not sent when the member is in no arm or it is not configured', () => {
      mod.useExperiment('unknown')
      mod.setLiveExperiments({ exp: running({ control: [0, 10] }) })
      mod.useExperiment('exp')
      expect(log.action).not.toHaveBeenCalled()
    })

    it('is not sent by stamping the variant alone', () => {
      mod.useExperiment('exp')
      log.action.mockClear()
      mod.experimentVariant()
      expect(log.action).not.toHaveBeenCalled()
    })

    it('survives a logging failure', () => {
      log.action.mockImplementationOnce(() => {
        throw new Error('log down')
      })
      expect(mod.useExperiment('exp')).toBe('b')
    })
  })

  describe('experimentVariant', () => {
    it('is empty when there are no experiments', () => {
      expect(mod.experimentVariant()).toBe('')
    })

    it('lists name:arm sorted and comma joined', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        'give-hint': running({ control: [0, 39], b: [40, 99] }),
        exp: running({ control: [0, 49], b: [50, 99] }),
      })
      mod.useExperiment('give-hint')
      mod.useExperiment('exp')
      const gh = mod.bucket('123', 'give-hint') <= 39 ? 'control' : 'b'
      expect(mod.experimentVariant()).toBe(`exp:b,give-hint:${gh}`)
    })

    it('leaves out experiments the member is in no arm of', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({ exp: running({ control: [0, 10] }) })
      mod.useExperiment('exp')
      expect(mod.experimentVariant()).toBe('')
    })

    it('includes an adopted experiment, so the holdout can be read', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: { arms: { b: [0, 89], control: [90, 99] }, adopted: true },
      })
      mod.useExperiment('exp')
      expect(mod.experimentVariant()).toBe('exp:b')
    })

    it('stamps only experiments this bundle declares', () => {
      auth.user = { id: 123 }
      mod.setLiveExperiments({
        exp: running({ b: [0, 99] }),
        elsewhere: running({ b: [0, 99] }),
      })
      // This bundle has code for 'exp' only (a call in this page view), not 'elsewhere'.
      mod.useExperiment('exp')
      expect(mod.experimentVariant()).toBe('exp:b')
    })

    it('stamps a switch listed in the build manifest before any call is made', async () => {
      vi.resetModules()
      vi.doMock('~/ux-experiments.manifest.json', () => ({
        default: { experiments: [{ name: 'built-in' }] },
      }))
      config('{}')
      const fresh = await import('~/composables/useExperiment')
      auth.user = { id: 123 }
      fresh.setLiveExperiments({
        'built-in': running({ b: [0, 99] }),
        'not-built': running({ b: [0, 99] }),
      })
      expect(fresh.experimentVariant()).toBe('built-in:b')
      vi.doUnmock('~/ux-experiments.manifest.json')
    })

    it('uses the sticky arm, so a flow started after signing in matches the first exposure', () => {
      mod.setLiveExperiments({
        exp: running({ control: [0, 49], b: [50, 99] }),
      })
      const first = mod.useExperiment('exp')
      auth.user = { id: first === 'b' ? 18 : 123 }
      expect(mod.experimentVariant()).toBe(`exp:${first}`)
    })
  })

  describe('armInVariant', () => {
    it('reads one name out of a stamped variant', () => {
      expect(mod.armInVariant('a:b,c:control', 'c')).toBe('control')
      expect(mod.armInVariant('a:b,c:control', 'a')).toBe('b')
    })

    it('does not match a name that is only a suffix', () => {
      expect(mod.armInVariant('login_cta:b', 'cta')).toBeNull()
      expect(mod.armInVariant('', 'cta')).toBeNull()
      expect(mod.armInVariant(null, 'cta')).toBeNull()
    })
  })
})
