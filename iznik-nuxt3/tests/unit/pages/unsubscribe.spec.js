import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

import UnsubscribePage from '~/pages/unsubscribe/[[id]].vue'

// Smoke test for the logged-out unsubscribe page: it should mount and render the
// email-entry form (with an EmailValidator). The detailed behaviour is covered
// elsewhere without a full page mount, which is unreliable here for template-ref
// wiring:
//   - the validate-before-proceed gate -> allValidatorsValid.spec.js
//   - the inline error + red invalid border -> EmailValidator.spec.js
//   - the Contact-us modal not auto-opening on load -> useOurModal.spec.js

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    unsubscribe: vi.fn(),
    forget: vi.fn(),
    leaveGroup: vi.fn(),
    loggedInEver: false,
    forceLogin: false,
  }),
}))

// Logged-out by default; a test can set these before mounting.
const meState = vi.hoisted(() => ({
  me: null,
  myid: null,
  myGroups: [],
  loggedIn: false,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref(meState.me),
    myid: ref(meState.myid),
    myGroups: ref(meState.myGroups),
    myGroup: vi.fn(),
    loggedIn: ref(meState.loggedIn),
  }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: vi.fn().mockReturnValue({}),
}))

vi.mock('~/components/EmailValidator.vue', () => ({
  default: {
    name: 'EmailValidator',
    template: '<input class="email-validator" />',
    emits: ['update:email', 'update:valid'],
  },
}))

function mountPage() {
  return mount(UnsubscribePage, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        SpinButton: { template: '<button class="spin-btn" />' },
        SupportLink: { template: '<span />' },
        ConfirmModal: { template: '<div />' },
        ContactSupportModal: { template: '<div />' },
        ForgetFailModal: { template: '<div />' },
        GroupSelect: {
          // Boolean so the bare `memberonly` attribute casts to true, as it does
          // on the real component.
          props: { memberonly: Boolean },
          template:
            '<div class="group-select" :data-memberonly="String(memberonly)" />',
        },
        NoticeMessage: { template: '<div><slot /></div>' },
        ExternalLink: { template: '<a><slot /></a>' },
        DeletedRestore: { template: '<div />' },
        NuxtLink: { template: '<a><slot /></a>' },
        'nuxt-link': { template: '<a><slot /></a>' },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-button': { template: '<button><slot /></button>' },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

describe('pages/unsubscribe/[[id]].vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // nuxt-app.js (aliased as #imports) reads these globals; logged-out + no
    // confirm query so the email-entry branch renders.
    globalThis.__testUseRoute = () => ({ params: { id: '0' }, query: {} })
    globalThis.__testUseRouter = () => ({
      push: vi.fn(),
      replace: () => {},
      currentRoute: { value: { path: '/' } },
    })
  })

  afterEach(() => {
    delete globalThis.__testUseRoute
    delete globalThis.__testUseRouter
    meState.me = null
    meState.myid = null
    meState.myGroups = []
    meState.loggedIn = false
  })

  it('only offers plain memberships to leave, never a moderator role', async () => {
    // Discourse 10148: an owner picked her own groups from this list and left them,
    // and came back a plain member. The picker must not list groups she moderates.
    meState.me = { id: 42 }
    meState.myid = 42
    meState.loggedIn = true
    meState.myGroups = [
      { id: 1, namedisplay: 'Plain', role: 'Member' },
      { id: 2, namedisplay: 'Mine', role: 'Owner' },
    ]

    const wrapper = mountPage()
    await flushPromises()

    const pickers = wrapper.findAll('.group-select')
    expect(pickers.length).toBeGreaterThan(0)
    for (const picker of pickers) {
      expect(picker.attributes('data-memberonly')).toBe('true')
    }
  })

  it('mounts without error when logged out', async () => {
    let err = null
    try {
      mountPage()
      await flushPromises()
    } catch (e) {
      err = e
    }
    expect(err).toBeNull()
  })

  it('renders the email-entry form (with a validator) when logged out', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.findAll('.email-validator').length).toBeGreaterThan(0)
  })
})
