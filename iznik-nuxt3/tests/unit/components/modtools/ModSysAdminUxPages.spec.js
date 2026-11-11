import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxPages from '~/modtools/components/ModSysAdminUxPages.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const view = (over = {}) => ({
  section: 'pages',
  period: '7days',
  from: '',
  to: '',
  device: '',
  signed: '',
  flow: '',
  step: '',
  open: '',
  uses: ['period', 'device'],
  filtersActive: false,
  filterParams: {},
  extra: {},
  ...over,
})

describe('ModSysAdminUxPages', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    const store = useUxStore()
    store.friction.data = { components: [] }
    store.adoption.data = { features: [] }
    store.regret.data = { signals: [] }
    store.search.data = { days: [] }
  })

  const make = (over = {}) =>
    mount(ModSysAdminUxPages, {
      props: { view: view(over) },
      global: { stubs: uxStubs },
    })

  const buttons = (w) => w.findAll('h4 > button')

  it('says what the section is for', () => {
    expect(make().text()).toContain(
      'Figures that are not about one funnel. Open a heading to see them.'
    )
  })

  it('has four panels, in this order, all closed', () => {
    const w = make()
    expect(buttons(w).map((b) => b.text())).toEqual([
      'Friction',
      'Adoption',
      'Regret',
      'Search and browse',
    ])
    expect(buttons(w).map((b) => b.attributes('aria-expanded'))).toEqual([
      'false',
      'false',
      'false',
      'false',
    ])
  })

  it.each([
    ['friction', 0],
    ['adoption', 1],
    ['regret', 2],
    ['search', 3],
  ])('opens only the panel the address names (%s)', (name, index) => {
    const open = buttons(make({ open: name })).map((b) =>
      b.attributes('aria-expanded')
    )
    expect(open.filter((x) => x === 'true')).toHaveLength(1)
    expect(open[index]).toBe('true')
  })

  it('opens none for a name it does not know', () => {
    const open = buttons(make({ open: 'something' })).map((b) =>
      b.attributes('aria-expanded')
    )
    expect(open).not.toContain('true')
  })

  it.each([
    ['friction', 0],
    ['adoption', 1],
    ['regret', 2],
    ['search', 3],
  ])(
    'asks the tab to open %s, and to close it again when it is the open one',
    async (name, index) => {
      const w = make()
      await buttons(w)[index].trigger('click')
      expect(w.emitted('navigate')).toEqual([[{ open: name }]])

      const open = make({ open: name })
      await buttons(open)[index].trigger('click')
      expect(open.emitted('navigate')).toEqual([[{ open: undefined }]])
    }
  )

  it('shows a summary line under every closed heading', () => {
    expect(make().findAll('.ux-summary')).toHaveLength(4)
  })
})
