import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RatingNudgeModal from '~/components/RatingNudgeModal.vue'
import {
  ratingNudgeOpen,
  ratingNudgePeople,
  skippedUsers,
  resetRatingNudge,
} from '~/composables/useRatingNudge'

const { mockModal, mockHide } = vi.hoisted(() => {
  const { ref } = require('vue')
  return { mockModal: ref(null), mockHide: vi.fn() }
})
const mockAction = vi.fn()
const mockRate = vi.fn()
const mockFetch = vi.fn()
const users = {
  11: { id: 11, displayname: 'Alice', profile: { path: '/alice.jpg' } },
  12: { id: 12, displayname: 'Bob', profile: {} },
}

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: mockModal, hide: mockHide }),
}))
vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))
vi.mock('~/composables/useExperiment', () => ({
  experimentVariant: () => 'rate-on-return:b',
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({
    rate: mockRate,
    fetch: mockFetch,
    byId: (id) => users[id] || null,
  }),
}))

const PEOPLE = [
  {
    userid: 11,
    msgid: 101,
    subject: 'OFFER: Sofa (Here)',
    item: 'Sofa',
    gave: true,
  },
  {
    userid: 12,
    msgid: 102,
    subject: 'OFFER: Lamp (Here)',
    item: '',
    gave: false,
  },
]

function mountModal() {
  ratingNudgePeople.value = PEOPLE.map((p) => ({ ...p }))
  ratingNudgeOpen.value = true
  return mount(RatingNudgeModal, {
    global: {
      stubs: {
        'b-modal': {
          name: 'BModal',
          template:
            '<div class="b-modal"><div class="modal-title">{{ title }}</div><slot /><slot name="footer" /></div>',
          props: ['scrollable', 'title', 'noCloseOnBackdrop'],
          emits: ['hidden'],
        },
        'b-button': {
          template:
            '<button :class="variant" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size'],
          emits: ['click'],
        },
        'v-icon': { template: '<i :class="icon" />', props: ['icon'] },
        ProfileImage: {
          template: '<img class="profile" :src="image" />',
          props: ['image', 'isThumbnail', 'size'],
        },
        UserRatingsDownModal: {
          template:
            '<div class="down-modal" :data-id="id" @click="$emit(\'rated\')" />',
          props: ['id'],
          emits: ['rated'],
        },
      },
    },
  })
}

describe('RatingNudgeModal', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    resetRatingNudge()
    mockAction.mockClear()
    mockRate.mockReset().mockResolvedValue()
    mockFetch.mockReset()
  })

  it('lists each person with what happened, fetches them, and logs the show', async () => {
    const wrapper = mountModal()
    await flushPromises()

    expect(wrapper.find('.modal-title').text()).toBe('How did it go?')
    const rows = wrapper.findAll('.person')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Alice')
    expect(rows[0].text()).toContain('You gave them Sofa')
    expect(rows[0].find('img.profile').attributes('src')).toBe('/alice.jpg')
    // No item name: the subject stands in.
    expect(rows[1].text()).toContain('They gave you OFFER: Lamp (Here)')
    expect(mockFetch).toHaveBeenCalledWith(11)
    expect(mockFetch).toHaveBeenCalledWith(12)
    expect(mockAction).toHaveBeenCalledWith('rating_nudge_shown', {
      count: 2,
      variant: 'rate-on-return:b',
    })
  })

  it('names a person with no record as a freegler', async () => {
    ratingNudgePeople.value = [
      { userid: 99, msgid: 1, subject: 'x', gave: true },
    ]
    ratingNudgeOpen.value = true
    const wrapper = mountModal()
    ratingNudgePeople.value = [
      { userid: 99, msgid: 1, subject: 'x', gave: true },
    ]
    await flushPromises()
    expect(wrapper.find('.person').text()).toContain('A freegler')
  })

  it('thumbs up rates from the nudge and removes the person', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await wrapper.findAll('.person')[0].find('.rate-up').trigger('click')
    await flushPromises()

    expect(mockRate).toHaveBeenCalledWith(11, 'Up', null, null, 'rating_nudge')
    expect(wrapper.findAll('.person')).toHaveLength(1)
    expect(wrapper.find('.person').text()).toContain('Bob')
    expect(mockHide).not.toHaveBeenCalled()
  })

  it('thumbs down opens the reason modal for that person, and a rating from it removes them', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await wrapper.findAll('.person')[1].find('.rate-down').trigger('click')
    await flushPromises()
    const down = wrapper.find('.down-modal')
    expect(down.attributes('data-id')).toBe('12')
    expect(mockRate).not.toHaveBeenCalled()

    await down.trigger('click')
    await flushPromises()
    expect(wrapper.find('.down-modal').exists()).toBe(false)
    expect(wrapper.findAll('.person').map((r) => r.text())).toEqual(
      expect.arrayContaining([expect.stringContaining('Alice')])
    )
    expect(wrapper.findAll('.person')).toHaveLength(1)
  })

  it('skip remembers the person, logs it and removes them', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await wrapper.findAll('.person')[0].find('.rate-skip').trigger('click')
    await flushPromises()

    expect(Object.keys(skippedUsers())).toEqual(['11'])
    expect(mockAction).toHaveBeenCalledWith('rating_nudge_skipped', {
      variant: 'rate-on-return:b',
    })
    expect(wrapper.findAll('.person')).toHaveLength(1)
  })

  it('closes with a thank you once everyone is dealt with', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await wrapper.findAll('.person')[0].find('.rate-up').trigger('click')
    await flushPromises()
    await wrapper.find('.person').find('.rate-skip').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Thanks!')
    expect(mockHide).toHaveBeenCalledTimes(1)
    expect(mockAction).toHaveBeenCalledWith('rating_nudge_dismissed', {
      how: 'done',
      shown: 2,
      rated: 1,
      variant: 'rate-on-return:b',
    })
  })

  it('Not now logs a dismissal with the counts and hides', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await wrapper.find('.not-now').trigger('click')
    expect(mockHide).toHaveBeenCalledTimes(1)
    expect(mockAction).toHaveBeenCalledWith('rating_nudge_dismissed', {
      how: 'not_now',
      shown: 2,
      rated: 0,
      variant: 'rate-on-return:b',
    })
  })

  it('a hide from outside (Escape, navigation) is one dismissal and closes the nudge', async () => {
    const wrapper = mountModal()
    await flushPromises()

    wrapper.findComponent({ name: 'BModal' }).vm.$emit('hidden')
    await flushPromises()

    expect(ratingNudgeOpen.value).toBe(false)
    expect(ratingNudgePeople.value).toEqual([])
    const dismissals = mockAction.mock.calls.filter(
      (c) => c[0] === 'rating_nudge_dismissed'
    )
    expect(dismissals).toHaveLength(1)
    expect(dismissals[0][1].how).toBe('hidden')

    // The hidden that follows Not now is not a second dismissal.
    await wrapper.find('.not-now').trigger('click')
    expect(
      mockAction.mock.calls.filter((c) => c[0] === 'rating_nudge_dismissed')
    ).toHaveLength(1)
  })
})
