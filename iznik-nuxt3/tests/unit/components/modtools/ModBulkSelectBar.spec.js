import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModBulkSelectBar from '~/modtools/components/ModBulkSelectBar.vue'

const stubs = {
  'b-button': {
    template:
      '<button :disabled="disabled" :title="title" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'size', 'disabled', 'title'],
    emits: ['click'],
  },
  'v-icon': { template: '<i :data-icon="icon"></i>', props: ['icon'] },
}

const rows = [
  {
    id: 101,
    name: 'Eric Jones',
    email: 'eric1@example.com',
    reason: 'Same name,\n50 accounts',
    added: '2026-10-24',
    by: 'Sheila',
  },
  {
    id: 102,
    name: 'Eric Jones',
    email: 'eric2@example.com',
    reason: 'Same name, 50 accounts',
    added: '2026-10-24',
    by: 'Sheila',
  },
]

function mountBar(props = {}) {
  return mount(ModBulkSelectBar, {
    props: {
      count: 0,
      limit: 50,
      shownIds: [101, 102],
      copyRows: rows,
      ...props,
    },
    global: { stubs },
  })
}

describe('ModBulkSelectBar', () => {
  const writeText = vi.fn().mockResolvedValue(undefined)

  beforeEach(() => {
    vi.useFakeTimers()
    writeText.mockClear()
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText },
      configurable: true,
      writable: true,
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the count and the cap', () => {
    const w = mountBar({ count: 3 })
    expect(w.find('[data-test="bulk-count"]').text()).toContain('3 selected')
    expect(w.find('[data-test="bulk-count"]').text()).toContain('up to 50')
  })

  it('emits select-all with the shown ids', async () => {
    const w = mountBar()
    await w.find('[data-test="bulk-select-all"]').trigger('click')
    expect(w.emitted('select-all')[0]).toEqual([[101, 102]])
  })

  it('disables select-all once everything shown is selected', () => {
    const w = mountBar({ count: 2 })
    expect(
      w.find('[data-test="bulk-select-all"]').attributes('disabled')
    ).toBeDefined()
  })

  it('disables select-all at the cap even when more is shown', () => {
    const w = mountBar({ count: 50, shownIds: [1, 2, 3, 4, 5] })
    expect(
      w.find('[data-test="bulk-select-all"]').attributes('disabled')
    ).toBeDefined()
  })

  it('emits clear', async () => {
    const w = mountBar({ count: 1 })
    await w.find('[data-test="bulk-clear"]').trigger('click')
    expect(w.emitted('clear')).toHaveLength(1)
  })

  it('copies one tab-separated line per member with no other markup', async () => {
    const w = mountBar()
    await w.find('[data-test="bulk-copy"]').trigger('click')
    expect(writeText).toHaveBeenCalledTimes(1)
    const text = writeText.mock.calls[0][0]
    const lines = text.split('\n')
    expect(lines).toHaveLength(2)
    expect(lines[0]).toBe(
      '101\tEric Jones\teric1@example.com\tSame name, 50 accounts\t2026-10-24\tSheila'
    )
    expect(lines[1].split('\t')).toHaveLength(6)
    expect(text).not.toMatch(/<|>/)
  })

  it('always writes the same six columns, so copies line up when pasted together', () => {
    const w = mountBar()
    const text = w.vm.plainText([
      { id: 1, name: 'A', email: 'a@x.com' },
      { id: 2, name: 'B', email: null, reason: 'r', added: 'd', by: 'S' },
    ])
    expect(text).toBe('1\tA\ta@x.com\t\t\t\n2\tB\t\tr\td\tS')
  })

  it('stops a name from becoming a spreadsheet formula', () => {
    const w = mountBar()
    const text = w.vm.plainText([
      { id: 1, name: '=HYPERLINK("http://evil","click")', email: '+44 nope' },
      { id: 2, name: '-minus', email: '@at' },
    ])
    const [a, b] = text.split('\n')
    expect(a.split('\t')[1]).toBe('\'=HYPERLINK("http://evil","click")')
    expect(a.split('\t')[2]).toBe("'+44 nope")
    expect(b.split('\t')[1]).toBe("'-minus")
    expect(b.split('\t')[2]).toBe("'@at")
  })

  it('can be told not to offer Copy at all', () => {
    const w = mountBar({ copy: false })
    expect(w.find('[data-test="bulk-copy"]').exists()).toBe(false)
  })

  it('says so when the clipboard refuses', async () => {
    writeText.mockRejectedValueOnce(new Error('denied'))
    const w = mountBar()
    await w.find('[data-test="bulk-copy"]').trigger('click')
    await w.vm.$nextTick()
    await w.vm.$nextTick()
    expect(w.find('[data-test="bulk-copy"]').text()).toContain('Copy failed')
  })

  it('says Copied for a moment after copying', async () => {
    const w = mountBar({ count: 2 })
    expect(w.find('[data-test="bulk-copy"]').text()).toContain('Copy selected')
    await w.find('[data-test="bulk-copy"]').trigger('click')
    await w.vm.$nextTick()
    expect(w.find('[data-test="bulk-copy"]').text()).toContain('Copied')
    vi.advanceTimersByTime(1600)
    await w.vm.$nextTick()
    expect(w.find('[data-test="bulk-copy"]').text()).toContain('Copy selected')
  })

  it('offers to copy the whole list when nothing is selected', () => {
    const w = mountBar({ count: 0 })
    expect(w.find('[data-test="bulk-copy"]').text()).toContain('Copy list')
  })

  it('renders its slot for page-specific actions', () => {
    const w = mount(ModBulkSelectBar, {
      props: { count: 0, limit: 50, shownIds: [], copyRows: [] },
      slots: { default: '<button class="extra">Confirm</button>' },
      global: { stubs },
    })
    expect(w.find('.extra').exists()).toBe(true)
  })
})
