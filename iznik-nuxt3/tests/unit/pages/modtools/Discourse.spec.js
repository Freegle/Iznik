import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, nextTick } from 'vue'
import Discourse from '~/modtools/pages/discourse.vue'

// Mock useMe composable with reactive myid
const mockMyid = ref(null)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    myid: mockMyid,
  }),
}))

// Mock auth store
const mockAuthStore = {
  user: null,
  auth: { persistent: null, jwt: null },
}
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

const PERSISTENT = { id: 26273648, series: 3406655488790931, token: 'tok' }
const CHALLENGE = { sso: 'bm9uY2U9YWJj', sig: 'deadbeef' }
const RETRY_URL =
  'https://modtools.org/discourse_sso?sso=bm9uY2U9YWJj&sig=deadbeef'

let currentQuery

describe('Discourse Page', () => {
  let originalLocation
  let cookieWrites

  beforeEach(() => {
    vi.clearAllMocks()
    mockMyid.value = null
    mockAuthStore.user = null
    mockAuthStore.auth.persistent = null
    currentQuery = {}
    globalThis.__testUseRoute = () => ({
      params: {},
      query: currentQuery,
      path: '/discourse',
      name: 'discourse',
      fullPath: '/discourse',
    })
    window.sessionStorage.clear()

    cookieWrites = []
    vi.spyOn(document, 'cookie', 'set').mockImplementation((value) => {
      cookieWrites.push(value)
    })

    // Mock window.location
    originalLocation = window.location
    delete window.location
    window.location = { href: '', origin: 'https://modtools.org' }
  })

  afterEach(() => {
    mounted.splice(0).forEach((w) => w.unmount())
    window.location = originalLocation
    delete globalThis.__testUseRoute
    vi.restoreAllMocks()
  })

  // Every mount is torn down after its test. The page watches the shared
  // myid ref, so a component left mounted by an earlier test would wake up
  // when a later test logs in, write a cookie of its own and redirect.
  const mounted = []

  function mountComponent() {
    const wrapper = mount(Discourse, {
      global: {
        stubs: {
          'b-img': { template: '<img />' },
        },
      },
    })
    mounted.push(wrapper)
    return wrapper
  }

  async function mountLoggedInWithChallenge() {
    mockMyid.value = 123
    mockAuthStore.auth.persistent = PERSISTENT
    currentQuery = { ...CHALLENGE }
    const wrapper = mountComponent()
    await flushPromises()
    return wrapper
  }

  describe('rendering', () => {
    it('renders the redirect message', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain(
        'This should redirect you back to Discourse'
      )
    })

    it('renders the loading spinner', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.spinner-border').exists()).toBe(true)
    })
  })

  describe('redirect behavior', () => {
    it('sets the cookie and goes to Discourse when myid is already set', async () => {
      mockMyid.value = 123
      mockAuthStore.auth.persistent = PERSISTENT
      mountComponent()
      await flushPromises()

      expect(cookieWrites).toHaveLength(1)
      expect(cookieWrites[0]).toContain('Iznik-Discourse-SSO=')
      expect(window.location).toBe('https://discourse.ilovefreegle.org')
    })

    it('redirects when user is in auth store on mount', async () => {
      mockAuthStore.user = { id: 456 }
      mockAuthStore.auth.persistent = PERSISTENT
      mountComponent()
      await flushPromises()

      expect(window.location).toBe('https://discourse.ilovefreegle.org')
    })

    it('does not redirect when user is not logged in', async () => {
      mockMyid.value = null
      mockAuthStore.user = null
      mountComponent()
      await flushPromises()

      expect(window.location.href).toBe('')
    })

    it('redirects when myid changes from null to a value', async () => {
      mockMyid.value = null
      mockAuthStore.auth.persistent = PERSISTENT
      mountComponent()
      await flushPromises()

      // User not logged in - no redirect
      expect(window.location.href).toBe('')

      // User logs in
      mockMyid.value = 789
      await nextTick()
      await flushPromises()

      expect(window.location).toBe('https://discourse.ilovefreegle.org')
    })

    it('retries the SSO endpoint with the same nonce when sent back without a cookie', async () => {
      await mountLoggedInWithChallenge()

      expect(cookieWrites).toHaveLength(1)
      expect(window.location).toBe(RETRY_URL)
    })

    it('shows the session message instead of retrying when there is no persistent token', async () => {
      mockMyid.value = 123
      mockAuthStore.auth.persistent = null
      currentQuery = { ...CHALLENGE }
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.text()).toContain("We couldn't verify your ModTools session")
      expect(wrapper.find('a[href="/login"]').exists()).toBe(true)
      expect(wrapper.find('.spinner-border').exists()).toBe(false)
      expect(cookieWrites).toHaveLength(0)
      expect(window.location.href).toBe('')
    })
  })

  describe('when the SSO endpoint refused us', () => {
    // The endpoint answers a refusal with ?ssoerror=<class>. Retrying with the
    // same cookie would get the same answer, so the page must stop here.

    it('tells a non-moderator that Discourse is for moderators, and does nothing else', async () => {
      mockMyid.value = 123
      mockAuthStore.auth.persistent = PERSISTENT
      currentQuery = { ...CHALLENGE, ssoerror: 'notmod' }
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.text()).toContain(
        'Discourse is only available to Freegle moderators'
      )
      expect(wrapper.text()).toContain('ask one of your community')
      expect(wrapper.find('a[href="mailto:geeks@ilovefreegle.org"]').exists()).toBe(true)
      expect(wrapper.find('a[href="/"]').exists()).toBe(true)
      expect(wrapper.find('.spinner-border').exists()).toBe(false)
      expect(cookieWrites).toHaveLength(0)
      expect(window.location.href).toBe('')
    })

    it('tells a moderator with a dead session to log in again', async () => {
      mockMyid.value = 123
      mockAuthStore.auth.persistent = PERSISTENT
      currentQuery = { ...CHALLENGE, ssoerror: 'session' }
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.text()).toContain("We couldn't verify your ModTools session")
      expect(wrapper.find('a[href="/login"]').exists()).toBe(true)
      expect(cookieWrites).toHaveLength(0)
      expect(window.location.href).toBe('')
    })

    it('treats an unknown error class as a session problem', async () => {
      mockMyid.value = 123
      mockAuthStore.auth.persistent = PERSISTENT
      currentQuery = { ssoerror: 'something-new' }
      const wrapper = mountComponent()
      await flushPromises()

      expect(wrapper.text()).toContain("We couldn't verify your ModTools session")
      expect(window.location.href).toBe('')
    })

    it('still does nothing when the user logs in after the error page is up', async () => {
      mockMyid.value = null
      mockAuthStore.auth.persistent = PERSISTENT
      currentQuery = { ...CHALLENGE, ssoerror: 'notmod' }
      mountComponent()
      await flushPromises()

      mockMyid.value = 789
      await nextTick()
      await flushPromises()

      expect(cookieWrites).toHaveLength(0)
      expect(window.location.href).toBe('')
    })
  })

  describe('retry guard', () => {
    // Belt to the ?ssoerror brace: whatever sends the page round again, it
    // must not reload itself indefinitely.

    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-09-25T13:40:00Z'))
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('allows two retries, then stops and shows the session message', async () => {
      await mountLoggedInWithChallenge()
      expect(window.location).toBe(RETRY_URL)

      window.location = { href: '', origin: 'https://modtools.org' }
      await mountLoggedInWithChallenge()
      expect(window.location).toBe(RETRY_URL)

      window.location = { href: '', origin: 'https://modtools.org' }
      cookieWrites.length = 0
      const wrapper = await mountLoggedInWithChallenge()
      expect(window.location.href).toBe('')
      expect(cookieWrites).toHaveLength(0)
      expect(wrapper.text()).toContain("We couldn't verify your ModTools session")
    })

    it('forgets retries older than a minute', async () => {
      await mountLoggedInWithChallenge()
      window.location = { href: '', origin: 'https://modtools.org' }
      await mountLoggedInWithChallenge()

      vi.setSystemTime(new Date('2026-09-25T13:41:01Z'))
      window.location = { href: '', origin: 'https://modtools.org' }
      await mountLoggedInWithChallenge()

      expect(window.location).toBe(RETRY_URL)
    })

    it('starts counting afresh after a visit from the Us link', async () => {
      await mountLoggedInWithChallenge()
      window.location = { href: '', origin: 'https://modtools.org' }
      await mountLoggedInWithChallenge()

      // Entry point: no challenge in the URL.
      window.location = { href: '', origin: 'https://modtools.org' }
      currentQuery = {}
      mountComponent()
      await flushPromises()
      expect(window.location).toBe('https://discourse.ilovefreegle.org')

      window.location = { href: '', origin: 'https://modtools.org' }
      await mountLoggedInWithChallenge()
      expect(window.location).toBe(RETRY_URL)
    })

    it('clears the counter when the endpoint has refused us', async () => {
      await mountLoggedInWithChallenge()
      window.location = { href: '', origin: 'https://modtools.org' }
      await mountLoggedInWithChallenge()

      currentQuery = { ...CHALLENGE, ssoerror: 'notmod' }
      window.location = { href: '', origin: 'https://modtools.org' }
      mountComponent()
      await flushPromises()

      expect(window.sessionStorage.getItem('discourse-sso-retries')).toBeNull()
    })
  })
})
