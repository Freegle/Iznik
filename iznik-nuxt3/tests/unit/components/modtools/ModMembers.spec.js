import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

// Mock the composable with a configurable return value. The component no
// longer reads `filter` at all (the old numeric "With notes" filter value it
// used to expand comments for doesn't exist under the new contract's
// new/flagged/banned/search values), so visibleMembers is the only thing
// this component depends on.
const mockVisibleMembers = ref([])

vi.mock('~/composables/useModMembers', () => ({
  setupModMembers: () => ({
    visibleMembers: mockVisibleMembers,
  }),
}))

// Test component that mirrors ModMembers.vue's actual (post-rework) template.
const ModMembersTest = {
  template: `
    <div>
      <div
        v-for="member in visibleMembers"
        :key="'memberlist-' + member.id"
        class="p-0 mt-2 member-item"
      >
        <div class="mod-member" :data-member-id="member.id">
          {{ member.displayname }}
        </div>
      </div>
    </div>
  `,
  setup() {
    return {
      visibleMembers: mockVisibleMembers,
    }
  },
}

describe('ModMembers', () => {
  const sampleMembers = [
    { id: 1, displayname: 'Alice Test', email: 'alice@example.com' },
    { id: 2, displayname: 'Bob Test', email: 'bob@example.com' },
    { id: 3, displayname: 'Charlie Test', email: 'charlie@example.com' },
  ]

  function mountComponent() {
    return mount(ModMembersTest)
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockVisibleMembers.value = []
  })

  describe('rendering', () => {
    it('renders a container div', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('div').exists()).toBe(true)
    })

    it('renders nothing when visibleMembers is empty', () => {
      mockVisibleMembers.value = []
      const wrapper = mountComponent()
      expect(wrapper.findAll('.member-item')).toHaveLength(0)
    })

    it('renders member items for each visible member', async () => {
      mockVisibleMembers.value = sampleMembers
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.findAll('.member-item')).toHaveLength(3)
    })

    it('displays member displayname', async () => {
      mockVisibleMembers.value = [sampleMembers[0]]
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('Alice Test')
    })

    it('uses member id as part of the key', async () => {
      mockVisibleMembers.value = sampleMembers
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      const items = wrapper.findAll('.mod-member')
      expect(items[0].attributes('data-member-id')).toBe('1')
      expect(items[1].attributes('data-member-id')).toBe('2')
      expect(items[2].attributes('data-member-id')).toBe('3')
    })
  })

  describe('member list updates', () => {
    it('adds new members when visibleMembers changes', async () => {
      mockVisibleMembers.value = [sampleMembers[0]]
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.findAll('.member-item')).toHaveLength(1)

      mockVisibleMembers.value = [...sampleMembers]
      await wrapper.vm.$nextTick()

      expect(wrapper.findAll('.member-item')).toHaveLength(3)
    })

    it('removes members when visibleMembers decreases', async () => {
      mockVisibleMembers.value = [...sampleMembers]
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.findAll('.member-item')).toHaveLength(3)

      mockVisibleMembers.value = [sampleMembers[0]]
      await wrapper.vm.$nextTick()

      expect(wrapper.findAll('.member-item')).toHaveLength(1)
    })

    it('handles empty to populated transition', async () => {
      mockVisibleMembers.value = []
      const wrapper = mountComponent()
      expect(wrapper.findAll('.member-item')).toHaveLength(0)

      mockVisibleMembers.value = sampleMembers
      await wrapper.vm.$nextTick()

      expect(wrapper.findAll('.member-item')).toHaveLength(3)
    })
  })

  describe('edge cases', () => {
    it('handles members with null displayname', async () => {
      const memberWithNullName = {
        id: 5,
        displayname: null,
        email: 'nullname@example.com',
      }
      mockVisibleMembers.value = [memberWithNullName]
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.findAll('.member-item')).toHaveLength(1)
    })

    it('handles undefined visibleMembers', () => {
      mockVisibleMembers.value = undefined
      const wrapper = mountComponent()
      // v-for on undefined/null renders nothing in Vue 3.
      expect(wrapper.find('.member-item').exists()).toBe(false)
    })
  })
})
