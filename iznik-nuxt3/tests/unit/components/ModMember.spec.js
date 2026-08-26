import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

import ModMember from '~/modtools/components/ModMember.vue'

const baseMember = {
  id: 5,
  userid: 99,
  groupid: 10,
  fullname: 'A Freegler',
  collection: 'Approved',
  added: '2026-08-01T10:00:00Z',
}

const mockMemberStore = {
  get: vi.fn().mockReturnValue(baseMember),
}

const mockUserStore = {
  list: {},
  byId: vi.fn(),
  fetchMT: vi.fn().mockResolvedValue({}),
  fetch: vi.fn().mockResolvedValue({}),
}

const mockModConfigStore = {
  fetchById: vi.fn().mockResolvedValue({}),
  configsById: {},
}

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => mockModConfigStore,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ id: 1, systemrole: 'Moderator' }),
    myGroups: ref([{ id: 10, configid: 7 }]),
  }),
}))

vi.mock('~/modtools/composables/usePreferredEmail', () => ({
  usePreferredEmail: () => ref('freegler@example.com'),
}))

function mountMember(member = {}) {
  mockMemberStore.get.mockReturnValue({ ...baseMember, ...member })

  return mount(ModMember, {
    props: { membershipid: 5 },
    shallow: true,
    global: {
      // shallow only stubs components Vue can resolve; the auto-imported ones have to be
      // named here or they warn (and the shared setup turns a Vue warning into a failure).
      stubs: {
        ...Object.fromEntries(
          [
            'ConfirmModal',
            'ExternalLink',
            'ModBouncing',
            'ModClipboard',
            'ModComments',
            'ModDeletedOrForgotten',
            'ModLogsModal',
            'ModMailDelayed',
            'ModMemberActions',
            'ModMemberButton',
            'ModMemberButtons',
            'ModMemberEngagement',
            'ModMemberLogins',
            'ModMemberSummary',
            'ModMemberships',
            'ModModeration',
            'ModPostingHistoryModal',
            'ModRole',
            'ModSpammer',
            'OurToggle',
            'ProfileImage',
            'SettingsGroup',
          ].map((name) => [name, { template: '<div />' }])
        ),
        // Real templates for the two things under test: the notice must render its text,
        // and the chat button must be findable when it is there.
        NoticeMessage: {
          template: '<div class="notice-message"><slot /></div>',
          props: ['variant'],
        },
        ChatButton: { template: '<button class="chat-button" />' },
        // Layout wrappers have to render their slots, or nothing inside the card exists
        // to assert on.
        'b-card': { template: '<div><slot /></div>' },
        'b-card-header': { template: '<div><slot /></div>' },
        'b-card-body': { template: '<div><slot /></div>' },
        'b-card-footer': { template: '<div><slot /></div>' },
        'b-button': { template: '<button><slot /></button>' },
        'v-icon': { template: '<i />' },
      },
      mocks: {
        datetimeshort: (v) => String(v),
      },
    },
  })
}

describe('ModMember', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUserStore.list = {}
  })

  it('offers a chat and shows no warning for an ordinary member', () => {
    const wrapper = mountMember({ mod_messaging_allowed: true })

    expect(wrapper.find('.chat-button').exists()).toBe(true)
    expect(
      wrapper.find('[data-test="tn-unaddressed-member-warning"]').exists()
    ).toBe(false)
  })

  it('treats a member with no flag at all as ordinary', () => {
    const wrapper = mountMember()

    expect(wrapper.find('.chat-button').exists()).toBe(true)
  })

  describe('a Trash Nothing member who has not opted in to Freegle', () => {
    it('warns the moderator, saying who they are and that they cannot be contacted', () => {
      const wrapper = mountMember({ mod_messaging_allowed: false })
      const warning = wrapper.find(
        '[data-test="tn-unaddressed-member-warning"]'
      )

      expect(warning.exists()).toBe(true)
      expect(warning.text()).toContain('Trash Nothing')
      expect(warning.text()).toContain("hasn't opted in")
      expect(warning.text()).toContain("can't be contacted")
    })

    it('offers no chat button', () => {
      const wrapper = mountMember({ mod_messaging_allowed: false })

      expect(wrapper.find('.chat-button').exists()).toBe(false)
    })
  })
})
