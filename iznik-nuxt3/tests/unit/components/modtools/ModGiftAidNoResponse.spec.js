import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModGiftAidNoResponse from '~/modtools/components/ModGiftAidNoResponse.vue'

const mockGiftaidApi = {
  noResponse: vi.fn(),
  record: vi.fn(),
}

vi.mock('#app', () => ({
  useNuxtApp: () => ({
    $api: {
      giftaid: mockGiftaidApi,
    },
  }),
}))

const louise = {
  userid: 11,
  fullname: 'Louise Donor',
  email: 'louise@example.com',
  donations: 30,
  asks: 3,
  lastasked: '2026-10-20T10:00:00Z',
  askedby: 42,
}

const chasedOnce = {
  userid: 12,
  fullname: 'Once Asked',
  email: null,
  donations: 0,
  asks: 1,
  lastasked: '2026-09-01T10:00:00Z',
  askedby: null,
}

function mountComponent() {
  return mount(ModGiftAidNoResponse, {
    global: {
      stubs: {
        'b-card': { template: '<div class="card"><slot /></div>' },
        'b-card-body': { template: '<div class="card-body"><slot /></div>' },
        'b-form-input': {
          template:
            '<input class="form-input" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue'],
        },
        SpinButton: {
          template:
            '<button class="spin-button" :data-label="label" :disabled="disabled" @click="$emit(\'handle\', done)">{{ label }}</button>',
          props: ['label', 'disabled'],
          setup() {
            return { done: vi.fn() }
          },
        },
        NoticeMessage: {
          template: '<div class="notice" :class="variant"><slot /></div>',
          props: ['variant'],
        },
        ExternalLink: {
          template: '<a class="external-link" :href="href"><slot /></a>',
          props: ['href'],
        },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

function rows(wrapper) {
  return wrapper.findAll('.noresponse')
}

describe('ModGiftAidNoResponse', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGiftaidApi.noResponse.mockResolvedValue([louise, chasedOnce])
    mockGiftaidApi.record.mockResolvedValue({})
  })

  it('lists donors asked who have not answered', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    expect(mockGiftaidApi.noResponse).toHaveBeenCalled()
    expect(rows(wrapper)).toHaveLength(2)
    expect(rows(wrapper)[0].text()).toContain('Louise Donor')
    expect(rows(wrapper)[0].text()).toContain('No response')
  })

  it('counts repeat asks and says who asked', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    const first = rows(wrapper)[0].text()
    expect(first).toContain('Asked 3 times, last on')
    expect(first).toContain('by volunteer #42')

    const second = rows(wrapper)[1].text()
    expect(second).toContain('Asked on')
    expect(second).not.toContain('times')
    expect(second).toContain('by the reminder email')
  })

  it('says so when nobody is waiting', async () => {
    mockGiftaidApi.noResponse.mockResolvedValue([])
    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.text()).toContain('Nobody is waiting for a reply')
  })

  it('copes with an empty answer', async () => {
    mockGiftaidApi.noResponse.mockResolvedValue(undefined)
    const wrapper = mountComponent()
    await flushPromises()

    expect(rows(wrapper)).toHaveLength(0)
  })

  it('records another ask from a row and refreshes the list', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    await rows(wrapper)[0].find('[data-label="Asked again"]').trigger('click')
    await flushPromises()

    expect(mockGiftaidApi.record).toHaveBeenCalledWith(11, 'NoResponse')
    expect(mockGiftaidApi.noResponse).toHaveBeenCalledTimes(2)
    expect(wrapper.find('.notice.success').text()).toContain(
      'user #11 has not replied'
    )
  })

  it('records a decline from a row', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    await rows(wrapper)[1].find('[data-label="They declined"]').trigger('click')
    await flushPromises()

    expect(mockGiftaidApi.record).toHaveBeenCalledWith(12, 'Declined')
    expect(wrapper.find('.notice.success').text()).toContain(
      'user #12 declined Gift Aid'
    )
  })

  it('records by user ID, and needs one first', async () => {
    const wrapper = mountComponent()
    await flushPromises()

    const top = wrapper.findAll('.spin-button').slice(0, 2)
    expect(top[0].attributes('disabled')).toBeDefined()

    await wrapper.find('.form-input').setValue('77')
    await wrapper.findAll('.spin-button')[0].trigger('click')
    await flushPromises()

    expect(mockGiftaidApi.record).toHaveBeenCalledWith(77, 'Declined')
  })

  it('shows the reason the server refused', async () => {
    mockGiftaidApi.record.mockRejectedValue({
      response: {
        data: { message: 'This donor has already answered about Gift Aid' },
      },
    })
    const wrapper = mountComponent()
    await flushPromises()

    await wrapper.find('.form-input').setValue('77')
    await wrapper.findAll('.spin-button')[1].trigger('click')
    await flushPromises()

    expect(mockGiftaidApi.record).toHaveBeenCalledWith(77, 'NoResponse')
    expect(wrapper.find('.notice.danger').text()).toContain('already answered')
  })

  it('falls back to a general message', async () => {
    mockGiftaidApi.record.mockRejectedValue(new Error('network'))
    const wrapper = mountComponent()
    await flushPromises()

    await rows(wrapper)[0].find('[data-label="Asked again"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('.notice.danger').text()).toContain(
      'could not be recorded'
    )
  })
})
