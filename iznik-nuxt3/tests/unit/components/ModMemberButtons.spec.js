import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ModMemberButtons from '~/modtools/components/ModMemberButtons.vue'

const baseMember = {
  id: 5,
  userid: 99,
  groupid: 10,
  collection: 'Approved',
}

const mockMemberStore = {
  get: vi.fn().mockReturnValue(baseMember),
}

const mockModConfig = {
  id: 7,
  stdmsgs: [
    {
      id: 1,
      title: 'Welcome note',
      action: 'Leave Approved Member',
      rarelyused: 0,
    },
    {
      id: 2,
      title: 'Removing you',
      action: 'Delete Approved Member',
      rarelyused: 0,
    },
  ],
}

const mockModConfigStore = {
  configsById: { 7: mockModConfig },
}

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => mockModConfigStore,
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({ hasPermissionSpamAdmin: true }),
}))

function mountButtons(member = {}) {
  mockMemberStore.get.mockReturnValue({ ...baseMember, ...member })

  return mount(ModMemberButtons, {
    props: { membershipid: 5, modconfigid: 7, actions: true },
    global: {
      stubs: {
        ModMemberButton: {
          template: '<button class="mod-member-button" :data-label="label" />',
          props: [
            'userid',
            'groupid',
            'membershipid',
            'spammerid',
            'variant',
            'label',
            'icon',
            'leave',
            'release',
            'spamignore',
            'spamconfirm',
            'spamremove',
            'spamsafelist',
            'spamrequestremove',
            'spamhold',
            'stdmsgid',
            'autosend',
          ],
        },
        ModMemberActions: { template: '<div class="mod-member-actions" />' },
        ModCommentAddModal: { template: '<div />' },
        OurToggle: { template: '<div class="our-toggle" />' },
        'b-button': { template: '<button><slot /></button>' },
        'client-only': { template: '<div><slot /></div>' },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

function labels(wrapper) {
  return wrapper
    .findAll('.mod-member-button')
    .map((b) => b.attributes('data-label'))
}

describe('ModMemberButtons', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('offers Mail and the standard messages for an ordinary member', () => {
    const seen = labels(mountButtons({ mod_messaging_allowed: true }))

    expect(seen).toContain('Mail')
    expect(seen).toContain('Welcome note')
    expect(seen).toContain('Removing you')
  })

  // Everything here writes to the member, and a TN member who never opted in to Freegle
  // has agreed to none of it. ModMember carries the notice explaining why they are gone.
  describe('a member who has not opted in to Freegle', () => {
    it('offers no Mail button', () => {
      expect(
        labels(mountButtons({ mod_messaging_allowed: false }))
      ).not.toContain('Mail')
    })

    it('offers no standard messages', () => {
      const seen = labels(mountButtons({ mod_messaging_allowed: false }))

      expect(seen).not.toContain('Welcome note')
      expect(seen).not.toContain('Removing you')
    })

    it('hides the autosend toggle, which now controls nothing', () => {
      expect(
        mountButtons({ mod_messaging_allowed: false })
          .find('.our-toggle')
          .exists()
      ).toBe(false)
    })

    it('keeps the membership actions, so they can still be removed', () => {
      expect(
        mountButtons({ mod_messaging_allowed: false })
          .find('.mod-member-actions')
          .exists()
      ).toBe(true)
    })
  })
})
