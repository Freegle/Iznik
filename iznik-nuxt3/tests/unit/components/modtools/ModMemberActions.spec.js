import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import ModMemberActions from '~/modtools/components/ModMemberActions.vue'

// Mock stores
const mockUserStore = {
  fetch: vi.fn(),
  fetchMT: vi.fn(),
  byId: vi.fn(),
}

const mockMemberStore = {
  ban: vi.fn(),
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

const mockSpammerStore = {
  byId: vi.fn(),
}

vi.mock('~/modtools/stores/spammer', () => ({
  useSpammerStore: () => mockSpammerStore,
}))

// Mock useMe composable
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: { value: { id: 999, displayname: 'Mod User' } },
    supportOrAdmin: { value: true },
  }),
}))

vi.mock('~/modtools/composables/useModMe', () => ({
  useModMe: () => ({
    checkWork: vi.fn(),
  }),
}))

// Mock useNuxtApp
const mockApi = {
  comment: {
    add: vi.fn(),
  },
}

// Make useNuxtApp available globally (it's auto-imported by Nuxt)
globalThis.useNuxtApp = () => ({ $api: mockApi })

// Mock child components with deep dependencies
vi.mock('~/modtools/components/ModCommentAddModal', () => ({
  default: defineComponent({
    name: 'ModCommentAddModal',
    props: ['userid'],
    emits: ['added', 'hidden'],
    setup() {
      return () => h('div', { class: 'comment-add-modal' })
    },
  }),
}))

vi.mock('~/modtools/components/ModBanMemberConfirmModal', () => ({
  default: defineComponent({
    name: 'ModBanMemberConfirmModal',
    props: ['userid'],
    emits: ['confirm'],
    setup() {
      return () => h('div', { class: 'ban-confirm-modal' })
    },
  }),
}))

vi.mock('~/modtools/components/ModSpammerReport', () => ({
  default: defineComponent({
    name: 'ModSpammerReport',
    props: ['userid', 'safelist'],
    setup() {
      return () => h('div', { class: 'spam-report-modal' })
    },
  }),
}))

describe('ModMemberActions', () => {
  const defaultProps = {
    userid: 456,
  }

  function mountComponent(props = {}) {
    return mount(ModMemberActions, {
      props: { ...defaultProps, ...props },
      global: {
        stubs: {
          'b-button': {
            template:
              '<button :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          'v-icon': {
            template: '<i :class="icon" />',
            props: ['icon'],
          },
          ConfirmModal: {
            template: '<div class="confirm-modal" :title="title" />',
            props: ['title'],
            methods: { show: vi.fn() },
          },
          ModBanMemberConfirmModal: {
            template: '<div class="ban-confirm-modal" />',
            props: ['userid'],
            methods: { show: vi.fn() },
          },
          ModCommentAddModal: {
            template: '<div class="comment-add-modal" />',
            props: ['userid'],
          },
          ModSpammerReport: {
            template: '<div class="spam-report-modal" />',
            props: ['userid', 'safelist'],
            methods: { show: vi.fn() },
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockUserStore.fetch.mockResolvedValue()
    mockUserStore.fetchMT.mockResolvedValue()
    mockUserStore.byId.mockReturnValue({
      id: 456,
      displayname: 'Test User',
    })
    mockMemberStore.ban.mockResolvedValue()
    mockApi.comment.add.mockResolvedValue()
    mockSpammerStore.byId.mockReturnValue(null)
  })

  describe('rendering', () => {
    it('shows Ban button when not banned', () => {
      const wrapper = mountComponent({ banned: false })
      expect(wrapper.text()).toContain('Ban')
    })

    it('hides Ban button when banned', () => {
      const wrapper = mountComponent({ banned: true })
      expect(wrapper.text()).not.toContain('Ban')
    })

    it('shows Report Spammer button when not spam', () => {
      mockSpammerStore.byId.mockReturnValue(null)
      const wrapper = mountComponent({ spammerid: null })
      expect(wrapper.text()).toContain('Report Spammer')
    })

    it('hides Report Spammer button when spam', () => {
      mockSpammerStore.byId.mockReturnValue({ id: 1, collection: 'PendingAdd' })
      const wrapper = mountComponent({ spammerid: 1 })
      expect(wrapper.text()).not.toContain('Report Spammer')
    })

    it('shows Safelist button when supportOrAdmin', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Safelist')
    })

    it('always shows Add note button', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Add note')
    })
  })

  describe('fetchUser method', () => {
    it('fetches user from store', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.fetchUser()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456, true)
      expect(mockUserStore.byId).toHaveBeenCalledWith(456)
    })
  })

  describe('ban action', () => {
    it('fetches user if not already loaded', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.ban()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456, true)
    })

    it('sets banConfirm to true', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.ban()
      expect(wrapper.vm.banConfirm).toBe(true)
    })
  })

  describe('banConfirmed method', () => {
    it('calls memberStore.ban and adds comment', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.banConfirmed('Bad behavior')
      expect(mockMemberStore.ban).toHaveBeenCalledWith(456, 'Bad behavior')
      expect(mockApi.comment.add).toHaveBeenCalledWith({
        userid: 456,
        user1: expect.stringContaining('Banned by Mod User'),
        flag: true,
      })
    })

    it('includes reason in comment', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.banConfirmed('Spamming')
      expect(mockApi.comment.add).toHaveBeenCalledWith(
        expect.objectContaining({
          user1: expect.stringContaining('reason: Spamming'),
        })
      )
    })
  })

  describe('addAComment method', () => {
    it('fetches user if not already loaded', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.addAComment()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456, true)
    })

    it('sets showAddCommentModal to true', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.addAComment()
      expect(wrapper.vm.showAddCommentModal).toBe(true)
    })
  })

  describe('commentadded method', () => {
    it('fetches user and emits event', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.commentadded()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456)
      expect(wrapper.emitted('commentadded')).toBeTruthy()
    })
  })

  describe('spamReport method', () => {
    it('fetches user if not already loaded', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.spamReport()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456, true)
    })

    it('sets safelist to false', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.spamReport()
      expect(wrapper.vm.safelist).toBe(false)
    })

    it('sets showSpamModal to true', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.spamReport()
      expect(wrapper.vm.showSpamModal).toBe(true)
    })
  })

  describe('spamSafelist method', () => {
    it('fetches user if not already loaded', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.spamSafelist()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456, true)
    })

    it('sets safelist to true', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.spamSafelist()
      expect(wrapper.vm.safelist).toBe(true)
    })

    it('sets showSpamModal to true', async () => {
      const wrapper = mountComponent({ userid: 456 })
      await wrapper.vm.spamSafelist()
      expect(wrapper.vm.showSpamModal).toBe(true)
    })
  })
})
