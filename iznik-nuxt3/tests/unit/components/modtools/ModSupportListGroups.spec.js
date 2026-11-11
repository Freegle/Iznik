import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import ModSupportListGroups from '~/modtools/components/ModSupportListGroups.vue'

const mockModGroupStore = {
  allGroups: {},
  listMT: vi.fn().mockResolvedValue({}),
}

vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => mockModGroupStore,
}))

// Minimal b-table: renders the header labels and one row per item, applying each
// field's formatter and tdClass the way bootstrap-vue-next does. Sorting and paging
// are bootstrap-vue-next's job; what we own is the fields and items we hand it.
const BTableStub = defineComponent({
  name: 'BTableStub',
  props: ['items', 'fields', 'perPage', 'currentPage', 'sortBy'],
  setup(props) {
    return () =>
      h('table', { class: 'stub-table' }, [
        h(
          'thead',
          h(
            'tr',
            props.fields.map((f) => h('th', { 'data-key': f.key }, f.label))
          )
        ),
        h(
          'tbody',
          props.items.map((item) =>
            h(
              'tr',
              { class: 'stub-row' },
              props.fields.map((f) => {
                const raw = item[f.key]
                const text = f.formatter ? f.formatter(raw, f.key, item) : raw
                const cls =
                  typeof f.tdClass === 'function'
                    ? f.tdClass(raw, f.key, item)
                    : f.tdClass
                return h(
                  'td',
                  { 'data-key': f.key, class: cls },
                  String(text ?? '')
                )
              })
            )
          )
        ),
      ])
  },
})

describe('ModSupportListGroups', () => {
  const createGroup = (overrides = {}) => ({
    id: 1,
    nameshort: 'TestGroup',
    namedisplay: 'Test Group Display',
    lastautoapprove: '2024-01-15T10:00:00Z',
    recentautoapprovespercent: 25,
    recentautoapproves: 10,
    recentmoderated: 4,
    recentmoderatedpercent: 40,
    activeownercount: 2,
    activemodcount: 5,
    lastmoderated: '2024-01-20T10:00:00Z',
    publish: 1,
    onhere: 1,
    ontn: 0,
    onlovejunk: 1,
    region: 'Scotland',
    lat: 55.9533,
    lng: -3.1883,
    founded: '2010-01-01',
    affiliationconfirmed: '2024-01-01T00:00:00Z',
    backupownersactive: 1,
    backupmodsactive: 2,
    mentored: false,
    ...overrides,
  })

  const BPaginationStub = {
    template: '<div class="stub-pagination" />',
    props: ['modelValue', 'totalRows', 'perPage'],
  }

  async function mountLoaded(groups) {
    mockModGroupStore.allGroups = Object.fromEntries(
      groups.map((g) => [g.id, g])
    )
    const wrapper = mount(ModSupportListGroups, {
      global: {
        stubs: { 'b-table': BTableStub, 'b-pagination': BPaginationStub },
      },
    })
    await wrapper.vm.fetchCommunities()
    await flushPromises()
    return wrapper
  }

  const rowNames = (wrapper) =>
    wrapper
      .findAll('.stub-row')
      .map((r) => r.find('[data-key="nameshort"]').text())

  beforeEach(() => {
    vi.clearAllMocks()
    mockModGroupStore.allGroups = {}
    mockModGroupStore.listMT = vi.fn().mockResolvedValue({})
  })

  it('shows a loading message while fetching', async () => {
    const wrapper = mount(ModSupportListGroups, {
      global: {
        stubs: { 'b-table': BTableStub, 'b-pagination': BPaginationStub },
      },
    })
    let resolve
    mockModGroupStore.listMT = vi.fn(() => new Promise((r) => (resolve = r)))
    const p = wrapper.vm.fetchCommunities()
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('Loading communities...')
    resolve({})
    await p
  })

  it('fetches support communities once', async () => {
    const wrapper = await mountLoaded([createGroup()])
    await wrapper.vm.fetchCommunities()
    expect(mockModGroupStore.listMT).toHaveBeenCalledTimes(1)
    expect(mockModGroupStore.listMT).toHaveBeenCalledWith({
      grouptype: 'Freegle',
      support: true,
    })
  })

  it('does not use Handsontable', async () => {
    const wrapper = await mountLoaded([createGroup()])
    expect(wrapper.find('.handsontable').exists()).toBe(false)
    expect(wrapper.text()).toContain('caretaker groups')
  })

  describe('columns', () => {
    it('renders every column the volunteers had', async () => {
      const wrapper = await mountLoaded([createGroup()])
      const titles = wrapper.findAll('th').map((t) => t.text())
      expect(titles).toEqual([
        'ID',
        'Short Name',
        'Display Name',
        'Last Auto-Approve',
        'Auto-Approve %',
        'Auto-Approves',
        'Moderated (30d)',
        '% Moderated (30d)',
        'Active-owner',
        'Active Mods',
        'Last Moderated',
        'Publish?',
        'FD?',
        'TN?',
        'LJ?',
        'Region',
        'Lat',
        'Lng',
        'Founded',
        'Affiliation Confirmed',
        'Backup Owners Active',
        'Backup Mods Active',
        'Caretaker?',
      ])
    })

    it('makes every column sortable and freezes the name', async () => {
      const wrapper = await mountLoaded([createGroup()])
      const table = wrapper.findComponent(BTableStub)
      const fields = table.props('fields')
      expect(fields.every((f) => f.sortable)).toBe(true)
      expect(fields.find((f) => f.key === 'nameshort').stickyColumn).toBe(true)
    })

    it('starts sorted by short name', async () => {
      const wrapper = await mountLoaded([createGroup()])
      expect(wrapper.findComponent(BTableStub).props('sortBy')).toEqual([
        { key: 'nameshort', order: 'asc' },
      ])
    })
  })

  describe('cell content', () => {
    it('formats dates, flags, coordinates and percentages', async () => {
      const wrapper = await mountLoaded([
        createGroup({ recentautoapprovespercent: -30, mentored: true }),
      ])
      const cell = (k) => wrapper.find(`td[data-key="${k}"]`).text()
      expect(cell('lastautoapprove')).toBe('2024-01-15')
      expect(cell('recentautoapprovespercent')).toBe('30%')
      expect(cell('publish')).toBe('Y')
      expect(cell('ontn')).toBe('N')
      expect(cell('lat')).toBe('55.95')
      expect(cell('lng')).toBe('-3.19')
      expect(cell('mentored')).toBe('Y')
      expect(cell('recentmoderatedpercent')).toBe('40%')
    })

    it('shows a dash for a missing date', async () => {
      const wrapper = await mountLoaded([createGroup({ lastmoderated: null })])
      expect(wrapper.find('td[data-key="lastmoderated"]').text()).toBe('-')
    })

    it('highlights caretaker group IDs', async () => {
      const wrapper = await mountLoaded([
        createGroup({ id: 1, nameshort: 'A', mentored: true }),
        createGroup({ id: 2, nameshort: 'B', mentored: false }),
      ])
      const ids = wrapper.findAll('td[data-key="id"]')
      expect(ids[0].classes()).toContain('bg-info-subtle')
      expect(ids[1].classes()).not.toContain('bg-info-subtle')
    })

    it('highlights high auto-approve only for published groups', async () => {
      const wrapper = await mountLoaded([
        createGroup({ id: 1, nameshort: 'A', recentautoapprovespercent: 50 }),
        createGroup({ id: 2, nameshort: 'B', recentautoapprovespercent: 49 }),
        createGroup({
          id: 3,
          nameshort: 'C',
          recentautoapprovespercent: 90,
          publish: 0,
        }),
      ])
      const cells = wrapper.findAll('td[data-key="recentautoapprovespercent"]')
      expect(cells[0].classes()).toContain('bg-warning')
      expect(cells[1].classes()).not.toContain('bg-warning')
      expect(cells[2].classes()).not.toContain('bg-warning')
    })

    it('colours the moderated percentage by band', async () => {
      const wrapper = await mountLoaded([
        createGroup({ id: 1, nameshort: 'A', recentmoderatedpercent: 5 }),
        createGroup({ id: 2, nameshort: 'B', recentmoderatedpercent: 50 }),
        createGroup({ id: 3, nameshort: 'C', recentmoderatedpercent: 85 }),
      ])
      const cells = wrapper.findAll('td[data-key="recentmoderatedpercent"]')
      expect(cells[0].classes()).toContain('bg-success-subtle')
      expect(cells[1].classes()).not.toContain('bg-success-subtle')
      expect(cells[1].classes()).not.toContain('bg-warning-subtle')
      expect(cells[2].classes()).toContain('bg-warning-subtle')
    })
  })

  describe('ordering and filtering', () => {
    const groups = () => [
      createGroup({ id: 1, nameshort: 'Zebra', region: 'Wales' }),
      createGroup({ id: 2, nameshort: 'alpha', region: 'Scotland', ontn: 1 }),
      createGroup({
        id: 3,
        nameshort: 'Beta',
        region: 'Wales',
        mentored: true,
        publish: 0,
      }),
    ]

    it('lists groups alphabetically ignoring case', async () => {
      const wrapper = await mountLoaded(groups())
      expect(rowNames(wrapper)).toEqual(['alpha', 'Beta', 'Zebra'])
    })

    it('filters by text over id, names and region', async () => {
      const wrapper = await mountLoaded(groups())
      await wrapper.find('input').setValue('wales')
      expect(rowNames(wrapper)).toEqual(['Beta', 'Zebra'])
      await wrapper.find('input').setValue('3')
      expect(rowNames(wrapper)).toEqual(['Beta'])
    })

    it('filters by a Y/N flag', async () => {
      const wrapper = await mountLoaded(groups())
      wrapper.vm.flags.ontn = 'Y'
      await wrapper.vm.$nextTick()
      expect(rowNames(wrapper)).toEqual(['alpha'])

      wrapper.vm.flags.ontn = 'N'
      await wrapper.vm.$nextTick()
      expect(rowNames(wrapper)).toEqual(['Beta', 'Zebra'])
    })

    it('filters by caretaker and publish together', async () => {
      const wrapper = await mountLoaded(groups())
      wrapper.vm.flags.mentored = 'Y'
      wrapper.vm.flags.publish = 'N'
      await wrapper.vm.$nextTick()
      expect(rowNames(wrapper)).toEqual(['Beta'])
    })

    it('returns to page one when a filter changes', async () => {
      const wrapper = await mountLoaded(groups())
      wrapper.vm.currentPage = 3
      await wrapper.find('input').setValue('a')
      expect(wrapper.vm.currentPage).toBe(1)
    })
  })

  describe('large lists', () => {
    it('pages the table so hundreds of groups stay responsive', async () => {
      const many = Array.from({ length: 450 }, (_, i) =>
        createGroup({ id: i + 1, nameshort: `Group${i}` })
      )
      const wrapper = await mountLoaded(many)
      const table = wrapper.findComponent(BTableStub)
      expect(table.props('perPage')).toBe(100)
      expect(wrapper.find('.stub-pagination').exists()).toBe(true)
    })

    it('hides the pager when everything fits on one page', async () => {
      const wrapper = await mountLoaded([createGroup()])
      expect(wrapper.find('.stub-pagination').exists()).toBe(false)
    })
  })

  it('copes with groups missing most properties', async () => {
    const wrapper = await mountLoaded([{ id: 9, nameshort: 'Partial' }])
    expect(rowNames(wrapper)).toEqual(['Partial'])
  })
})
