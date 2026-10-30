import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModMembers from '~/modtools/components/ModMembers.vue'

// The real component, with its list and stores mocked, to check the bulk
// report action that sits above the member cards.

const mockFilter = ref('0')
const mockVisibleMembers = ref([])
const mockReportMany = vi.fn()
let mockMemberList = {}

vi.mock('~/composables/useModMembers', () => ({
  setupModMembers: () => ({
    filter: mockFilter,
    visibleMembers: mockVisibleMembers,
  }),
}))

vi.mock('~/stores/spammer', () => ({
  useSpammerStore: () => ({ reportMany: mockReportMany }),
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => ({
    get list() {
      return mockMemberList
    },
  }),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: ref({ id: 66 }) }),
}))

const stubs = {
  ModMember: {
    template: '<div class="member-stub"><slot name="select" /></div>',
    props: ['membershipid'],
  },
  ModBulkSelectBar: {
    template:
      '<div class="bulk-bar-stub" :data-count="count" :data-copy="String(copy)"><slot /></div>',
    props: ['count', 'limit', 'shownIds', 'copyRows', 'busy', 'copy'],
    emits: ['select-all', 'clear'],
  },
  ModBulkSpammerReport: {
    template: '<div class="bulk-modal-stub" :data-count="count" />',
    props: ['count'],
    emits: ['confirm', 'hidden'],
  },
  NoticeMessage: {
    template: '<div class="notice-stub"><slot /></div>',
    props: ['variant'],
  },
  'b-form-checkbox': {
    template:
      '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', !modelValue)" />',
    props: ['modelValue', 'disabled', 'title'],
    emits: ['update:modelValue'],
  },
  'b-button': {
    template:
      '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'size', 'disabled'],
    emits: ['click'],
  },
  'v-icon': { template: '<i />', props: ['icon'] },
}

function members() {
  return [
    { id: 11, userid: 1, groupid: 100, displayname: 'Eric Jones' },
    // The same person on a second community: one checkbox state.
    { id: 12, userid: 1, groupid: 101, displayname: 'Eric Jones' },
    { id: 13, userid: 2, groupid: 100, displayname: 'Eric Jones' },
  ]
}

function mountIt() {
  return mount(ModMembers, { global: { stubs } })
}

function okResult(done, skipped = []) {
  return {
    done,
    skipped,
    failed: [],
    values: new Map(done.map((u, i) => [u, 500 + i])),
  }
}

describe('ModMembers bulk report', () => {
  beforeEach(() => {
    mockFilter.value = '0'
    mockVisibleMembers.value = members()
    mockMemberList = Object.fromEntries(members().map((m) => [m.id, m]))
    mockReportMany.mockReset()
    mockReportMany.mockResolvedValue(okResult([]))
  })

  it('shows no bar with nothing listed', () => {
    mockVisibleMembers.value = []
    const w = mountIt()
    expect(w.find('.bulk-bar-stub').exists()).toBe(false)
  })

  it('offers no Copy: moderators do not take member lists away', () => {
    const w = mountIt()
    expect(w.find('.bulk-bar-stub').attributes('data-copy')).toBe('false')
  })

  it('keys selection by person, so one tick covers both memberships', async () => {
    const w = mountIt()
    expect(w.vm.shownUserIds).toEqual([1, 2])
    await w.find('[data-test="bulk-check-1"]').trigger('change')
    expect(w.vm.bulk.count.value).toBe(1)
    const boxes = w.findAll('[data-test="bulk-check-1"]')
    expect(boxes).toHaveLength(2)
    boxes.forEach((b) => expect(b.element.checked).toBe(true))
  })

  it('drops a selection when the list is replaced under it', async () => {
    const w = mountIt()
    w.vm.bulk.selectAll([1, 2])
    expect(w.vm.bulk.count.value).toBe(2)
    // A new search: a different list, person 2 still in it.
    mockVisibleMembers.value = [
      { id: 21, userid: 2, groupid: 100, displayname: 'Eric Jones' },
      { id: 22, userid: 3, groupid: 100, displayname: 'Eric Jones' },
    ]
    await w.vm.$nextTick()
    expect(w.vm.bulk.isSelected(1)).toBe(false)
    expect(w.vm.bulk.isSelected(2)).toBe(true)
    // An empty list clears it entirely.
    mockVisibleMembers.value = []
    await w.vm.$nextTick()
    expect(w.vm.bulk.count.value).toBe(0)
  })

  it('shows Report only once something is selected', async () => {
    const w = mountIt()
    await w.vm.$nextTick()
    expect(w.find('[data-test="bulk-spam"]').exists()).toBe(false)
    w.vm.bulk.selectAll([1])
    await w.vm.$nextTick()
    expect(w.find('[data-test="bulk-spam"]').text()).toContain(
      'Report 1 as spammers'
    )
  })

  it('puts each selection checkbox inside its member card', async () => {
    const w = mountIt()
    await w.vm.$nextTick()
    const card = w.find('.member-stub')
    expect(card.find('[data-test^="bulk-check-"]').exists()).toBe(true)
  })

  it('always reports to the Spam team, never adds directly', async () => {
    const w = mountIt()
    mockReportMany.mockResolvedValue(okResult([1, 2]))
    w.vm.bulk.selectAll([1, 2])
    await w.vm.$nextTick()
    expect(w.find('[data-test="bulk-spam"]').text()).toContain(
      'Report 2 as spammers'
    )
    await w.find('[data-test="bulk-spam"]').trigger('click')
    await w.vm.$nextTick()
    expect(w.find('.bulk-modal-stub').attributes('data-count')).toBe('2')

    w.findComponent('.bulk-modal-stub').vm.$emit(
      'confirm',
      'Same name, many accounts'
    )
    await flushPromises()

    expect(mockReportMany).toHaveBeenCalledWith({
      userids: [1, 2],
      reason: 'Same name, many accounts',
    })
    expect(w.find('[data-test="bulk-result"]').text()).toContain(
      'Reported 2 to the Spam team'
    )
    expect(w.vm.bulk.count.value).toBe(0)
  })

  it('marks the reported people on their cards straight away', async () => {
    const w = mountIt()
    mockReportMany.mockResolvedValue(okResult([1]))
    w.vm.bulk.toggle(1)
    await w.vm.bulkReport('Bot')
    // Both memberships of person 1 now carry the Pending Add state the card reads.
    expect(mockMemberList[11].spammer).toMatchObject({
      id: 500,
      collection: 'PendingAdd',
      reason: 'Bot',
      byuserid: 66,
    })
    expect(mockMemberList[12].spammer.collection).toBe('PendingAdd')
    expect(mockMemberList[13].spammer).toBeUndefined()
  })

  it('says who was already reported or listed rather than counting them as reported', async () => {
    const w = mountIt()
    mockReportMany.mockResolvedValue(okResult([1], [2]))
    w.vm.bulk.selectAll([1, 2])
    await w.vm.bulkReport('r')
    const text = w.find('[data-test="bulk-result"]').text()
    expect(text).toContain('Reported 1 to the Spam team')
    expect(text).toContain('1 already reported or on the spammer list')
    expect(mockMemberList[13].spammer).toBeUndefined()
  })

  it('names the people that could not be reported', async () => {
    const w = mountIt()
    mockReportMany.mockResolvedValue({
      ...okResult([1]),
      failed: [{ item: 2, error: new Error('Server error') }],
    })
    w.vm.bulk.selectAll([1, 2])
    await w.vm.bulkReport('r')
    expect(w.vm.bulkResult.failed).toEqual([
      { userid: 2, name: 'Eric Jones', message: 'Server error' },
    ])
    expect(w.find('[data-test="bulk-result"]').text()).toContain('#2')
  })

  it('reports every selected person as failed when the whole call throws', async () => {
    const w = mountIt()
    mockReportMany.mockRejectedValue(new Error('Network down'))
    w.vm.bulk.selectAll([1, 2])
    await w.vm.bulkReport('r')
    expect(w.vm.bulkResult.failed.map((f) => f.userid)).toEqual([1, 2])
    expect(w.vm.bulkResult.failed[0].message).toBe('Network down')
    expect(w.vm.bulk.count.value).toBe(0)
  })

  it('does nothing when confirmed with nothing selected', async () => {
    const w = mountIt()
    await w.vm.bulkReport('r')
    expect(mockReportMany).not.toHaveBeenCalled()
  })

  it('will not tick a 51st person', async () => {
    mockVisibleMembers.value = Array.from({ length: 52 }, (_, i) => ({
      id: 100 + i,
      userid: 1000 + i,
      groupid: 100,
      displayname: 'Eric Jones',
    }))
    const w = mountIt()
    w.vm.bulk.selectAll(w.vm.shownUserIds)
    await w.vm.$nextTick()
    expect(w.vm.bulk.count.value).toBe(50)
    expect(
      w.find('[data-test="bulk-check-1051"]').attributes('disabled')
    ).toBeDefined()
    expect(
      w.find('[data-test="bulk-check-1000"]').attributes('disabled')
    ).toBeUndefined()
  })
})
