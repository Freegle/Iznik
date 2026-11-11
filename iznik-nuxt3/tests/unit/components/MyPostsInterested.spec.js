import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import MyPostsInterested from '~/components/MyPostsInterested.vue'

const { mockList, mockRemove, mockFetch } = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockRemove: vi.fn(),
  mockFetch: vi.fn(),
}))

vi.mock('~/composables/useInterested', () => ({
  listInterested: (...a) => mockList(...a),
  removeInterested: (...a) => mockRemove(...a),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ fetch: (...a) => mockFetch(...a) }),
}))

const mountIt = () =>
  mount(MyPostsInterested, {
    global: {
      stubs: {
        MyPostsInterestedRow: {
          props: ['id'],
          emits: ['remove'],
          template:
            '<div class="summary" :data-id="id" :data-testid="\'interested-row-\' + id"><button :data-testid="\'interested-remove-\' + id" @click="$emit(\'remove\', id)" /></div>',
        },
        'v-icon': true,
        'b-button': {
          props: ['to'],
          template:
            '<button :data-to="to" @click="$emit(\'click\')"><slot /></button>',
          emits: ['click'],
        },
      },
    },
  })

describe('MyPostsInterested', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetch.mockResolvedValue(undefined)
  })

  it('lists the open marks in the order given', async () => {
    mockList.mockResolvedValue([
      { msgid: 7, open: true, replied: false },
      { msgid: 3, open: false, replied: false },
      { msgid: 5, open: true, replied: false },
    ])
    const w = mountIt()
    await flushPromises()
    expect(w.text()).toContain("Posts you're interested in")
    expect(w.findAll('.summary').map((s) => s.attributes('data-id'))).toEqual([
      '7',
      '5',
    ])
    expect(mockFetch).toHaveBeenCalledWith(7)
    expect(mockFetch).not.toHaveBeenCalledWith(3)
  })

  it('removes a mark and drops its row', async () => {
    mockList.mockResolvedValue([{ msgid: 7, open: true }])
    mockRemove.mockResolvedValue(true)
    const w = mountIt()
    await flushPromises()
    await w.find('[data-testid="interested-remove-7"]').trigger('click')
    await flushPromises()
    expect(mockRemove).toHaveBeenCalledWith(7)
    expect(w.find('[data-testid="myposts-interested"]').exists()).toBe(false)
  })

  it('keeps the row when the removal fails', async () => {
    mockList.mockResolvedValue([{ msgid: 7, open: true }])
    mockRemove.mockResolvedValue(false)
    const w = mountIt()
    await flushPromises()
    await w.find('[data-testid="interested-remove-7"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="interested-row-7"]').exists()).toBe(true)
  })

  it('is hidden when there are no open marks', async () => {
    mockList.mockResolvedValue([{ msgid: 3, open: false }])
    const w = mountIt()
    await flushPromises()
    expect(w.find('[data-testid="myposts-interested"]').exists()).toBe(false)
  })
})
