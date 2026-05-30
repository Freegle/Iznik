/**
 * AssertFlip test: ModSupport Unsubscribe button does not remove member from group
 *
 * Bug #9738: Clicking the Unsubscribe button in ModTools Support tools does not
 * remove the member from their group, no log entries are written, and the member's
 * account remains active.
 *
 * Root cause: unsubscribeConfirmed() calls userStore.unsubscribe(userid) which POSTs
 * to /user with action:'Unsubscribe' — it never calls memberStore.remove(userid, groupid)
 * which would DELETE /memberships to actually remove the member from the group.
 *
 * STEP 1 (BUGGY): Assert the wrong behaviour IS happening — memberStore.remove is
 *   NOT called; only userStore.unsubscribe is called without a groupid.
 *
 * STEP 2 (FLIPPED): Invert — assert memberStore.remove IS called with the correct
 *   userid and groupid. Fails on current code; will pass after the fix.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModSupportUser from '~/modtools/components/ModSupportUser.vue'

// ────────────────────────────────────────────────────────────────────────────
// API mock — capture calls to both user and memberships namespaces
// ────────────────────────────────────────────────────────────────────────────
const mockUserUnsubscribeApi = vi.fn().mockResolvedValue({})
const mockMembershipsRemoveApi = vi.fn().mockResolvedValue({})

vi.mock('~/api', () => ({
  default: () => ({
    user: {
      unsubscribe: mockUserUnsubscribeApi,
      fetchChatrooms: vi.fn().mockResolvedValue([]),
      fetchEmailHistory: vi.fn().mockResolvedValue([]),
      fetchBans: vi.fn().mockResolvedValue([]),
      fetchNewsfeed: vi.fn().mockResolvedValue([]),
      fetchApplied: vi.fn().mockResolvedValue([]),
      fetchMembershipHistory: vi.fn().mockResolvedValue([]),
      fetchLogins: vi.fn().mockResolvedValue([]),
    },
    memberships: {
      remove: mockMembershipsRemoveApi,
    },
  }),
}))

// ────────────────────────────────────────────────────────────────────────────
// Store mocks
// ────────────────────────────────────────────────────────────────────────────
const mockUnsubscribe = vi.fn().mockResolvedValue({})
const mockFetchMT = vi.fn().mockResolvedValue({})
const mockById = vi.fn()

vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    byId: mockById,
    fetchMT: mockFetchMT,
    fetch: vi.fn().mockResolvedValue({}),
    edit: vi.fn().mockResolvedValue({}),
    purge: vi.fn().mockResolvedValue({}),
    unsubscribe: mockUnsubscribe,
    addEmail: vi.fn().mockResolvedValue({}),
    config: {},
  }),
}))

// memberStore is NOT imported by the current (buggy) ModSupportUser.vue.
// The mock is set up here so the flipped assertion can detect that remove()
// is never called. The fix will import this store and call remove().
const mockMemberRemove = vi.fn().mockResolvedValue({})

vi.mock('~/stores/member', () => ({
  useMemberStore: () => ({
    remove: mockMemberRemove,
    update: vi.fn().mockResolvedValue({}),
    config: {},
  }),
}))

// ────────────────────────────────────────────────────────────────────────────
// Test helpers
// ────────────────────────────────────────────────────────────────────────────
const USER_ID = 123
const GROUP_ID = 789

const createUser = (overrides = {}) => ({
  id: USER_ID,
  email: 'test@example.com',
  displayname: 'Test User',
  lastaccess: new Date().toISOString(),
  systemrole: 'User',
  bouncing: false,
  spammer: null,
  emails: [{ id: 1, email: 'test@example.com', preferred: true }],
  applied: [],
  membershiphistory: [],
  messagehistory: [],
  emailhistory: [],
  chatrooms: [],
  newsfeed: [],
  newsfeedmodstatus: 'Unmoderated',
  // User belongs to one Freegle group — the unsubscribe action should
  // remove them from this group via DELETE /memberships.
  memberships: [
    {
      id: GROUP_ID,
      groupid: GROUP_ID,
      membershipid: 200,
      nameshort: 'FreegleTestGroup',
      type: 'Freegle',
      role: 'Member',
      emailfrequency: -1,
      ourpostingstatus: 'DEFAULT',
      eventsallowed: 1,
      volunteeringallowed: 1,
      added: '2024-01-15T10:00:00Z',
    },
  ],
  ...overrides,
})

async function mountComponent(userOverrides = {}) {
  const user = createUser(userOverrides)
  mockById.mockReturnValue(user)

  const wrapper = mount(ModSupportUser, {
    props: { id: USER_ID, expand: true },
    global: {
      plugins: [createPinia()],
      directives: {
        'b-tooltip': { mounted() {}, updated() {}, unmounted() {} },
      },
      stubs: {
        'b-card': {
          template: '<div class="card"><slot /><slot name="header" /></div>',
          props: ['noBody'],
        },
        'b-card-header': {
          template:
            '<div class="card-header" @click="$emit(\'click\')"><slot /></div>',
        },
        'b-card-body': {
          template: '<div class="card-body"><slot /></div>',
        },
        'b-row': { template: '<div class="row"><slot /></div>' },
        'b-col': {
          template: '<div class="col"><slot /></div>',
          props: ['cols', 'lg', 'md'],
        },
        'b-button': {
          template:
            '<button :variant="variant" :disabled="disabled" :href="href" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size', 'disabled', 'href'],
        },
        'b-input-group': {
          template:
            '<div class="input-group"><slot /><slot name="append" /></div>',
        },
        'b-form-input': {
          template:
            '<input :value="modelValue" :type="type" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'type', 'placeholder', 'autocomplete'],
        },
        'b-form-select': {
          template:
            '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
          props: ['modelValue'],
        },
        'b-form-select-option': {
          template: '<option :value="value"><slot /></option>',
          props: ['value'],
        },
        'v-icon': {
          template: '<span class="icon" :class="icon" />',
          props: ['icon', 'scale'],
        },
        NoticeMessage: {
          template:
            '<div class="notice-message" :class="variant"><slot /></div>',
          props: ['variant'],
        },
        ModClipboard: {
          template: '<span class="clipboard" />',
          props: ['value'],
        },
        ModBouncing: {
          template: '<div class="mod-bouncing" />',
          props: ['userid'],
        },
        ModSpammer: {
          template: '<div class="mod-spammer" />',
          props: ['userid'],
        },
        ModComments: {
          template: '<div class="mod-comments" />',
          props: ['userid'],
        },
        ModMergeButton: {
          template: '<button class="merge-button" />',
        },
        ModDeletedOrForgotten: {
          template: '<div class="deleted-or-forgotten" />',
          props: ['userid'],
        },
        ModMemberLogins: {
          template: '<div class="member-logins" />',
          props: ['userid'],
        },
        ModMemberSummary: {
          template: '<div class="member-summary" />',
          props: ['userid'],
        },
        ModSupportMembership: {
          template: '<div class="support-membership" />',
          props: ['membershipid', 'userid'],
        },
        ModSupportChatList: {
          template: '<div class="chat-list" />',
          props: ['chats', 'pov'],
        },
        ModLogsModal: {
          template: '<div class="logs-modal" />',
          props: ['userid'],
          methods: { show: vi.fn() },
        },
        ConfirmModal: {
          template: '<div class="confirm-modal" />',
          props: ['title', 'message'],
          methods: { show: vi.fn() },
        },
        ProfileModal: {
          template: '<div class="profile-modal" />',
          props: ['id'],
          methods: { show: vi.fn() },
        },
        ModSpammerReport: {
          template: '<div class="spammer-report" />',
          props: ['userid'],
          methods: { show: vi.fn() },
        },
        ModCommentAddModal: {
          template: '<div class="comment-add-modal" />',
          props: ['userid'],
        },
        SpinButton: {
          template:
            '<button @click="$emit(\'handle\', () => {})"><slot /></button>',
          props: ['variant', 'iconName', 'label'],
        },
        ExternalLink: {
          template: '<a :href="href" class="external-link"><slot /></a>',
          props: ['href'],
        },
      },
    },
  })

  await flushPromises()
  return wrapper
}

// ────────────────────────────────────────────────────────────────────────────
// Tests
// ────────────────────────────────────────────────────────────────────────────
describe('ModSupportUser — Unsubscribe button bug #9738 (AssertFlip)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockFetchMT.mockResolvedValue({})
    mockUnsubscribe.mockResolvedValue({})
    mockMemberRemove.mockResolvedValue({})
    mockUserUnsubscribeApi.mockResolvedValue({})
    mockMembershipsRemoveApi.mockResolvedValue({})
  })

  it('[FIX] unsubscribeConfirmed should call memberStore.remove with userid and groupid', async () => {
    const wrapper = await mountComponent()

    wrapper.vm.unsubscribeConfirmed()
    await flushPromises()

    // After the fix: memberStore.remove(userid, groupid) must be called so that
    // a DELETE /memberships request is sent with both the user id and the group id.
    expect(mockMemberRemove).toHaveBeenCalledWith(USER_ID, GROUP_ID)

    // After the fix: userStore.unsubscribe should NOT be called for a group-level
    // removal — it puts the account into full 14-day limbo, which is not the
    // intended action when removing someone from a group.
    expect(mockUnsubscribe).not.toHaveBeenCalled()
  })
})
