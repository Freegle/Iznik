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

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref(null),
    myid: ref(null),
    myGroups: ref([]),
    myGroup: vi.fn(),
    loggedIn: ref(false),
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
        GroupSelect: { template: '<div />' },
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
