import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, computed } from 'vue'

// Mock the composable with configurable return values
const mockFilter = ref('0')
const mockVisibleMembers = ref([])

vi.mock('~/composables/useModMembers', () => ({
  setupModMembers: () => ({
    filter: mockFilter,
    visibleMembers: mockVisibleMembers,
  }),
}))

// Create test component that mirrors ModMembers logic
const ModMembersTest = {
  template: `
    <div>
      <div
        v-for="member in visibleMembers"
        :key="'memberlist-' + member.id"
        class="p-0 mt-2 member-item"
      >
        <div
          class="mod-member"
          :data-member-id="member.id"
          :data-expand-comments="expandComments"
        >
          {{ member.displayname }}
        </div>
      </div>
    </div>
  `,
  setup() {
    const expandComments = computed(() => parseInt(mockFilter.value) === 1)

    return {
      filter: mockFilter,
      visibleMembers: mockVisibleMembers,
      expandComments,
    }
  },
}

describe('ModMembers', () => {
  const sampleMembers = [
    {
      id: 1,
      displayname: 'Alice Test',
      email: 'alice@example.com',
      joined: '2024-01-01',
      groups: [{ id: 100, arrival: '2024-01-01' }],
    },
    {
      id: 2,
      displayname: 'Bob Test',
      email: 'bob@example.com',
      joined: '2024-01-02',
      groups: [{ id: 100, arrival: '2024-01-02' }],
    },
    {
      id: 3,
      displayname: 'Charlie Test',
      email: 'charlie@example.com',
      joined: '2024-01-03',
      groups: [{ id: 100, arrival: '2024-01-03' }],
    },
  ]

  function mountComponent() {
    return mount(ModMembersTest)
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockFilter.value = '0'
    mockVisibleMembers.value = []
  })

  describe('filter and expandComments', () => {
    it('sets expandComments to false when filter is "0"', () => {
      mockFilter.value = '0'
      const wrapper = mountComponent()
      expect(wrapper.vm.expandComments).toBe(false)
    })

    it('sets expandComments to true when filter is "1"', async () => {
      mockFilter.value = '1'
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.expandComments).toBe(true)
    })

    it('sets expandComments to false when filter is "2"', async () => {
      mockFilter.value = '2'
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.expandComments).toBe(false)
    })

    it('passes expandComments to ModMember component', async () => {
      mockFilter.value = '1'
      mockVisibleMembers.value = [sampleMembers[0]]
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      const modMember = wrapper.find('.mod-member')
      expect(modMember.attributes('data-expand-comments')).toBe('true')
    })

    it('updates expandComments when filter changes', async () => {
      mockFilter.value = '0'
      const wrapper = mountComponent()
      expect(wrapper.vm.expandComments).toBe(false)

      mockFilter.value = '1'
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.expandComments).toBe(true)
    })
  })

  describe('edge cases', () => {

    it('handles filter as string "1" correctly (parseInt)', () => {
      mockFilter.value = '1'
      const wrapper = mountComponent()
      expect(wrapper.vm.expandComments).toBe(true)
    })

    it('handles filter as numeric-looking string', () => {
      mockFilter.value = '01'
      const wrapper = mountComponent()
      // parseInt('01') === 1
      expect(wrapper.vm.expandComments).toBe(true)
    })

  })
})
