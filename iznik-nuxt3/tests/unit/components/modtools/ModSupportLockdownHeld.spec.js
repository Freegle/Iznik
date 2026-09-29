import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSupportLockdownHeld from '~/modtools/components/ModSupportLockdownHeld.vue'

const mockFetchHeld = vi.fn()

vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: () => ({ fetchHeld: mockFetchHeld }),
}))

vi.mock('~/composables/useTimeFormat', () => ({
  timeago: (d) => 'ago:' + d,
}))

function item(id, extra = {}) {
  return {
    id,
    kind: 'chat',
    refid: 100 + id,
    created: 'c' + id,
    userid: 500 + id,
    name: 'Sender ' + id,
    email: 's' + id + '@example.com',
    text: 'Claim your voucher ' + id,
    recipientid: 900,
    recipientname: 'Recipient',
    ...extra,
  }
}

// plans/active/2026-09-27-lockdown-switch.md section 11.11: browse and search
// what is held, one kind at a time, 50 a page, each sender linked to Support
// tools. No actions on held items.
describe('ModSupportLockdownHeld', () => {
  function createWrapper() {
    return mount(ModSupportLockdownHeld, {
      global: {
        stubs: {
          'b-form-select': {
            template:
              '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.text }}</option></select>',
            props: ['modelValue', 'options'],
          },
          'b-form-input': {
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @keyup.enter="$emit(\'keyup\', $event)" />',
            props: ['modelValue'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['disabled', 'variant'],
            emits: ['click'],
          },
          'b-spinner': { template: '<span class="spinner" />' },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchHeld.mockResolvedValue({ items: [], next: null })
  })

  it('fetches held chat messages when opened', async () => {
    createWrapper()
    await flushPromises()
    expect(mockFetchHeld).toHaveBeenCalledWith({ kind: 'chat' })
  })

  it('says nothing is held when the list is empty', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('[data-testid="lockdown-held-empty"]').text()).toBe(
      'Nothing held.'
    )
  })

  it('lists each held item with its sender linked to Support tools', async () => {
    mockFetchHeld.mockResolvedValue({ items: [item(1)], next: null })
    const wrapper = createWrapper()
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="lockdown-held-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Claim your voucher 1')
    expect(rows[0].text()).toContain('s1@example.com')
    expect(rows[0].text()).toContain('Recipient')
    expect(
      wrapper
        .find('[data-testid="lockdown-held-sender-501"]')
        .attributes('href')
    ).toBe('/support/501')
    expect(wrapper.find('[data-testid="lockdown-held-more"]').exists()).toBe(
      false
    )
  })

  it('has no actions on held items', async () => {
    mockFetchHeld.mockResolvedValue({ items: [item(1)], next: null })
    const wrapper = createWrapper()
    await flushPromises()
    const row = wrapper.find('[data-testid="lockdown-held-row"]')
    expect(row.find('button').exists()).toBe(false)
  })

  it('searches with the trimmed term', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper
      .find('[data-testid="lockdown-held-search"]')
      .setValue('  voucher  ')
    await wrapper
      .find('[data-testid="lockdown-held-search-button"]')
      .trigger('click')
    await flushPromises()
    expect(mockFetchHeld).toHaveBeenLastCalledWith({
      kind: 'chat',
      q: 'voucher',
    })
    expect(wrapper.find('[data-testid="lockdown-held-empty"]').text()).toBe(
      'Nothing held matches that search.'
    )
  })

  it('switches kind and fetches again', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.find('[data-testid="lockdown-held-kind"]').setValue('post')
    await flushPromises()
    expect(mockFetchHeld).toHaveBeenLastCalledWith({ kind: 'post' })
  })

  it('only shows the To column for chat', async () => {
    mockFetchHeld.mockResolvedValue({
      items: [item(1, { kind: 'post' })],
      next: null,
    })
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.findAll('th').map((th) => th.text())).toEqual([
      'When',
      'From',
      'To',
      'Text',
    ])
    await wrapper.find('[data-testid="lockdown-held-kind"]').setValue('post')
    await flushPromises()
    expect(wrapper.findAll('th').map((th) => th.text())).toEqual([
      'When',
      'From',
      'Text',
    ])
  })

  it('pages with before, appending to the list', async () => {
    mockFetchHeld.mockResolvedValueOnce({ items: [item(2)], next: 2 })
    mockFetchHeld.mockResolvedValueOnce({ items: [item(1)], next: null })
    const wrapper = createWrapper()
    await flushPromises()

    await wrapper.find('[data-testid="lockdown-held-more"]').trigger('click')
    await flushPromises()

    expect(mockFetchHeld).toHaveBeenLastCalledWith({ kind: 'chat', before: 2 })
    expect(wrapper.findAll('[data-testid="lockdown-held-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-testid="lockdown-held-more"]').exists()).toBe(
      false
    )
  })

  it('replaces the list on a new search rather than appending', async () => {
    mockFetchHeld.mockResolvedValueOnce({ items: [item(2)], next: 2 })
    mockFetchHeld.mockResolvedValueOnce({ items: [item(3)], next: null })
    const wrapper = createWrapper()
    await flushPromises()
    await wrapper.vm.search()
    await flushPromises()
    const rows = wrapper.findAll('[data-testid="lockdown-held-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('voucher 3')
  })
})
