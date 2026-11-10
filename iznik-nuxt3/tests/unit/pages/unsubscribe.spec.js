import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

import UnsubscribePage from '~/pages/unsubscribe/[[id]].vue'
import { ROLE_MEMBER, ROLE_OWNER } from '~/constants'

// Smoke test for the logged-out unsubscribe page: it should mount and render the
// email-entry form (with an EmailValidator). The detailed behaviour is covered
// elsewhere without a full page mount, which is unreliable here for template-ref
// wiring:
//   - the validate-before-proceed gate -> allValidatorsValid.spec.js
//   - the inline error + red invalid border -> EmailValidator.spec.js
//   - the Contact-us modal not auto-opening on load -> useOurModal.spec.js

const leaveGroup = vi.hoisted(() => vi.fn())
const pickState = vi.hoisted(() => ({ id: null }))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    unsubscribe: vi.fn(),
    forget: vi.fn(),
    leaveGroup,
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
    myGroup: (id) =>
      meState.myGroups.find((g) => parseInt(g.id) === id) || null,
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
        SpinButton: {
          props: ['label'],
          emits: ['handle'],
          template:
            '<button class="spin-btn" :data-label="label" @click="$emit(\'handle\', () => {})" />',
        },
        SupportLink: { template: '<span />' },
        ConfirmModal: { template: '<div />' },
        ContactSupportModal: { template: '<div />' },
        ForgetFailModal: { template: '<div />' },
        GroupSelect: {
          // Boolean so the bare `memberonly` attribute casts to true, as it does
          // on the real component.
          props: { memberonly: Boolean, modelValue: { default: null } },
          emits: ['update:modelValue'],
          template:
            '<div class="group-select" :data-memberonly="String(memberonly)" @click="$emit(\'update:modelValue\', pickState.id)" />',
          setup: () => ({ pickState }),
        },
        NoticeMessage: {
          props: ['variant'],
          template: '<div class="notice" :class="variant"><slot /></div>',
        },
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
      { id: 1, namedisplay: 'Plain', role: ROLE_MEMBER },
      { id: 2, namedisplay: 'Mine', role: ROLE_OWNER },
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

  // Discourse 10242: an owner picked a community she moderates, pressed Leave, got a 403
  // from the server and landed on the "Oh dear" crash page.
  describe('leaving a community you moderate', () => {
    const MSG =
      'You are a moderator of this community, so leaving would drop that role. To leave, change your own role to Member first, or ask another owner to.'

    function loggedInWith(groups) {
      meState.me = { id: 42 }
      meState.myid = 42
      meState.loggedIn = true
      meState.myGroups = groups
    }

    async function pick(wrapper, id) {
      pickState.id = id
      for (const picker of wrapper.findAll('.group-select')) {
        await picker.trigger('click')
      }
      await flushPromises()
    }

    it('shows an explanation instead of a Leave button', async () => {
      loggedInWith([{ id: 2, namedisplay: 'Mine', role: ROLE_OWNER }])
      const wrapper = mountPage()
      await flushPromises()
      await pick(wrapper, 2)

      expect(
        wrapper.findAll('.spin-btn[data-label="Leave this community"]')
      ).toHaveLength(0)
      expect(wrapper.text()).toContain('change your own role to Member')
    })

    it('still offers Leave for a plain membership', async () => {
      loggedInWith([{ id: 1, namedisplay: 'Plain', role: ROLE_MEMBER }])
      const wrapper = mountPage()
      await flushPromises()
      await pick(wrapper, 1)

      expect(
        wrapper.findAll('.spin-btn[data-label="Leave this community"]').length
      ).toBeGreaterThan(0)
      expect(wrapper.text()).not.toContain('change your own role to Member')
    })

    it('shows the server refusal instead of crashing if the server says no', async () => {
      loggedInWith([{ id: 1, namedisplay: 'Plain', role: ROLE_MEMBER }])
      const e = new Error('API Error DELETE /memberships -> status: 403')
      e.response = { status: 403, data: { error: 403, message: MSG } }
      leaveGroup.mockRejectedValue(e)

      const wrapper = mountPage()
      await flushPromises()
      await pick(wrapper, 1)
      await wrapper
        .find('.spin-btn[data-label="Leave this community"]')
        .trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain(MSG)
    })
  })
})
