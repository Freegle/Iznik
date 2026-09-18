import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModMemberExportButton from '~/modtools/components/ModMemberExportButton.vue'

// Mock save-file so we can assert whether an export was actually written.
const mockSaveAs = vi.fn().mockResolvedValue(undefined)
vi.mock('save-file', () => ({
  default: (blob, filename) => mockSaveAs(blob, filename),
}))

// Mock member store so we can assert whether a fetch was actually triggered.
const mockMemberStore = {
  clear: vi.fn(),
  fetchMembers: vi.fn().mockResolvedValue(undefined),
  getByGroup: vi.fn(() => []),
  context: null,
}
vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

// Mock group store; tests vary myrole/membercount via mockGroup.
let mockGroup = null
vi.mock('~/stores/modgroup', () => ({
  useModGroupStore: () => ({
    get: () => mockGroup,
  }),
}))

// Mock useOurModal (avoids needing a real router for its nav guard); the
// component calls modal.value.show() directly, so give it a stub with one.
const mockModalShow = vi.fn()
const mockModalRef = ref({ show: mockModalShow, hide: vi.fn() })
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: mockModalRef,
    show: vi.fn(),
    hide: vi.fn(),
  }),
}))

const modalStubs = {
  'b-modal': {
    template: '<div class="modal"><slot /><slot name="footer" /></div>',
    methods: { show: vi.fn(), hide: vi.fn() },
  },
  'b-progress': { template: '<div class="progress"><slot /></div>' },
  'b-progress-bar': {
    template: '<div class="progress-bar"></div>',
    props: ['value'],
  },
}

describe('ModMemberExportButton', () => {
  function mountComponent({
    groupid = 789,
    hasGroup = true,
    myrole = 'Owner',
    membercount = 100,
  } = {}) {
    mockGroup = hasGroup
      ? {
          id: groupid,
          nameshort: 'Test Group',
          myrole,
          membercount,
        }
      : null

    return mount(ModMemberExportButton, {
      props: { groupid },
      global: { stubs: modalStubs },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockMemberStore.context = null
    mockMemberStore.getByGroup.mockReturnValue([])
  })

  describe('rendering', () => {
    it('shows Export button when group exists', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Export')
    })

    it('hides Export button when no group', () => {
      const wrapper = mountComponent({ hasGroup: false })
      expect(wrapper.find('button').exists()).toBe(false)
    })
  })

  describe('button disabled state', () => {
    it('button stays disabled for Owner too (GDPR export restriction, topic 10085/7)', () => {
      const wrapper = mountComponent({ myrole: 'Owner' })
      expect(wrapper.find('button').attributes('disabled')).toBe('')
    })

    it('button is disabled when not admin (Moderator)', () => {
      const wrapper = mountComponent({ myrole: 'Moderator' })
      expect(wrapper.find('button').attributes('disabled')).toBe('')
    })
  })

  describe('computed properties', () => {
    it('admin is always false, even when myrole is Owner (GDPR export restriction, topic 10085/7)', () => {
      const wrapper = mountComponent({ myrole: 'Owner' })
      expect(wrapper.vm.admin).toBe(false)
    })

    it('admin returns false when myrole is not Owner', () => {
      const wrapper = mountComponent({ myrole: 'Moderator' })
      expect(wrapper.vm.admin).toBe(false)
    })

    it('progressValue returns correct percentage', () => {
      const wrapper = mountComponent({ membercount: 100 })
      wrapper.vm.fetched = 50
      expect(wrapper.vm.progressValue).toBe(50)
    })

    it('progressValue returns 0 when no membercount', () => {
      const wrapper = mountComponent({ membercount: 0 })
      wrapper.vm.fetched = 50
      expect(wrapper.vm.progressValue).toBe(0)
    })

    it('progressValue returns 0 when no group', () => {
      const wrapper = mountComponent({ hasGroup: false })
      expect(wrapper.vm.progressValue).toBe(0)
    })
  })

  describe('cancelit method', () => {
    it('sets cancelled to true', () => {
      const wrapper = mountComponent()
      wrapper.vm.cancelit()
      expect(wrapper.vm.cancelled).toBe(true)
    })

    it('clears exportList', () => {
      const wrapper = mountComponent()
      wrapper.vm.exportList = [{ id: 1 }]
      wrapper.vm.cancelit()
      expect(wrapper.vm.exportList).toEqual([])
    })

    it('hides modal', () => {
      const wrapper = mountComponent()
      wrapper.vm.showExportModal = true
      wrapper.vm.cancelit()
      expect(wrapper.vm.showExportModal).toBe(false)
    })
  })

  describe('download method', () => {
    // Regression test: the review on PR #1561 found that disabling the
    // button only flipped the :disabled attribute, while download() ->
    // exportChunk() -> memberStore.fetchMembers() + saveAs() stayed fully
    // wired underneath. Calling download() directly (e.g. from devtools,
    // bypassing the disabled button) must be a no-op, not just an unclickable
    // button. This fails against the pre-fix download() and passes now that
    // download() itself is gated on admin.
    it('does nothing when called directly, even though myrole is Owner', async () => {
      const wrapper = mountComponent({ myrole: 'Owner' })

      wrapper.vm.download()
      await wrapper.vm.$nextTick()
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.showExportModal).toBe(false)
      expect(mockModalShow).not.toHaveBeenCalled()
      expect(mockMemberStore.fetchMembers).not.toHaveBeenCalled()
      expect(mockSaveAs).not.toHaveBeenCalled()
    })

    it('does not reset state when called directly', () => {
      const wrapper = mountComponent()
      wrapper.vm.context = 'old'
      wrapper.vm.cancelled = true
      wrapper.vm.exportList = [{ id: 1 }]
      wrapper.vm.fetched = 50

      wrapper.vm.download()

      expect(wrapper.vm.context).toBe('old')
      expect(wrapper.vm.cancelled).toBe(true)
      expect(wrapper.vm.exportList).toEqual([{ id: 1 }])
      expect(wrapper.vm.fetched).toBe(50)
    })
  })

  describe('modal content', () => {
    it('shows progress in modal', async () => {
      const wrapper = mountComponent()
      wrapper.vm.showExportModal = true
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.progress').exists()).toBe(true)
    })

    it('clicking cancel button closes modal', async () => {
      const wrapper = mountComponent()
      wrapper.vm.showExportModal = true
      await wrapper.vm.$nextTick()
      await wrapper.findAll('button').at(-1).trigger('click')
      expect(wrapper.vm.showExportModal).toBe(false)
    })
  })

  describe('button click', () => {
    it('clicking the disabled Export button does not start an export', async () => {
      const wrapper = mountComponent()
      expect(wrapper.find('button').attributes('disabled')).toBe('')

      await wrapper.find('button').trigger('click')

      expect(wrapper.vm.showExportModal).toBe(false)
      expect(mockMemberStore.fetchMembers).not.toHaveBeenCalled()
      expect(mockSaveAs).not.toHaveBeenCalled()
    })
  })
})
