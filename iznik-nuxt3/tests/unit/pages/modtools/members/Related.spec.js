import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import Related from '~/modtools/pages/members/related.vue'

const mockMemberStore = {
  list: reactive({}),
  fetchMembers: vi.fn(),
}

vi.mock('~/modtools/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

describe('Related Page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    Object.keys(mockMemberStore.list).forEach(
      (k) => delete mockMemberStore.list[k]
    )
    mockMemberStore.fetchMembers.mockResolvedValue(0)
  })

  function mountComponent() {
    return mount(Related, {
      global: {
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ScrollToTop: { template: '<div class="scroll-to-top" />' },
          ModHelpRelated: { template: '<div class="mod-help-related" />' },
          ModRelatedMember: {
            template:
              '<div class="mod-related-member" :data-member-id="memberid" />',
            props: ['memberid'],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
          },
        },
      },
    })
  }

  it('renders ModHelpRelated component', () => {
    const wrapper = mountComponent()
    expect(wrapper.find('.mod-help-related').exists()).toBe(true)
  })

  it('has no per-community group selector any more', () => {
    const wrapper = mountComponent()
    expect(wrapper.find('.mod-group-select').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'ModGroupSelect' }).exists()).toBe(
      false
    )
  })

  it('fetches the related filter on mount', () => {
    mountComponent()
    expect(mockMemberStore.fetchMembers).toHaveBeenCalledWith({
      filter: 'related',
    })
  })

  it('shows the honest empty notice when there are no pairs', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.find('.notice-message').exists()).toBe(true)
    expect(wrapper.findAll('.mod-related-member')).toHaveLength(0)
  })

  it('renders a card for a real pair once the store has one', async () => {
    mockMemberStore.list[99] = { id: 99, user1: 1, user2: 2, reason: null }
    const wrapper = mountComponent()
    await flushPromises()

    const card = wrapper.find('.mod-related-member')
    expect(card.exists()).toBe(true)
    expect(card.attributes('data-member-id')).toBe('99')
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })

  it('never renders a row missing user1/user2 as a pair', async () => {
    // member/member.go's ListMembers silently falls back an unrecognised
    // filter (e.g. today's not-yet-live filter=related) to "new" rather
    // than erroring - those rows carry displayname/added, not user1/user2,
    // and must never be mistaken for a real pair.
    mockMemberStore.list[5] = {
      id: 5,
      displayname: 'Some New Member',
      added: '2026-09-26',
    }
    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.findAll('.mod-related-member')).toHaveLength(0)
    expect(wrapper.find('.notice-message').exists()).toBe(true)
  })

  it('renders every real pair and ignores non-pair rows in the same fetch', async () => {
    mockMemberStore.list[1] = { id: 1, user1: 10, user2: 20 }
    mockMemberStore.list[2] = { id: 2, user1: 30, user2: 40 }
    mockMemberStore.list[3] = { id: 3, displayname: 'Not a pair' }
    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.findAll('.mod-related-member')).toHaveLength(2)
  })
})
