import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import NewsConvertModal from '~/components/NewsConvertModal.vue'

const { mockModal, mockHide } = vi.hoisted(() => {
  const { ref } = require('vue')
  return { mockModal: ref(null), mockHide: vi.fn() }
})

const mockConvertInfo = vi.fn()
const mockConvertToPost = vi.fn()

const mockApi = { news: { convertInfo: (...a) => mockConvertInfo(...a) } }

vi.mock('~/api', () => ({ default: () => mockApi }))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: {} }),
}))

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: mockModal, hide: mockHide }),
}))

vi.mock('~/stores/newsfeed', () => ({
  useNewsfeedStore: () => ({
    convertToPost: (...a) => mockConvertToPost(...a),
  }),
}))

describe('NewsConvertModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockConvertInfo.mockResolvedValue({
      canpost: true,
      locationname: 'BA1 5BG',
      groupid: 12,
      groupname: 'Freegle Bath',
    })
  })

  function createWrapper(newsfeed = {}) {
    return mount(NewsConvertModal, {
      props: {
        newsfeed: {
          id: 42,
          userid: 99,
          message: 'Set of four dining chairs going spare if anyone wants them',
          ...newsfeed,
        },
        posterName: 'Sam',
      },
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="b-modal"><slot /><slot name="footer" /></div>',
          },
          'b-form-group': {
            template: '<div class="b-form-group"><slot /></div>',
          },
          'b-form-radio-group': { template: '<div class="radios" />' },
          'b-form-input': { template: '<input class="b-form-input" />' },
          'b-form-textarea': {
            template: '<textarea class="b-form-textarea" />',
          },
          'b-card': { template: '<div class="b-card"><slot /></div>' },
          'b-button': {
            template: '<button class="b-button"><slot /></button>',
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
          },
          NewsConvertedNotice: {
            template:
              '<div class="converted-notice" :data-msgtype="msgtype || \'\'">note preview</div>',
            props: ['preview', 'msgtype'],
          },
          SpinButton: {
            template: '<button class="spin-button" :disabled="disabled" />',
            props: ['disabled', 'variant', 'iconName', 'label'],
          },
        },
      },
    })
  }

  // A moderator is posting to somebody else's area. Showing a placeholder meant
  // they could not see where it would land.
  describe('where it will post', () => {
    it('asks the server where this would go', async () => {
      createWrapper()
      await flushPromises()
      expect(mockConvertInfo).toHaveBeenCalledWith(42)
    })

    it('names the postcode and community', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('BA1 5BG')
      expect(wrapper.text()).toContain('Freegle Bath')
    })

    it("makes clear it is the member's area, not the moderator's", async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain("Sam's own area, not yours")
    })

    it('puts the real postcode in the preview subject', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('(BA1 5BG)')
      expect(wrapper.text()).not.toContain('(their area)')
    })

    it('falls back to a placeholder if the lookup fails, rather than blocking', async () => {
      mockConvertInfo.mockRejectedValue(new Error('nope'))
      vi.spyOn(console, 'error').mockImplementation(() => {})
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('their area')
      expect(
        wrapper.find('.spin-button').attributes('disabled')
      ).toBeUndefined()
    })
  })

  // The server refuses to post for a member it cannot place. Say so before the
  // moderator fills the form in, not after.
  describe('when the member cannot be posted for', () => {
    beforeEach(() => {
      mockConvertInfo.mockResolvedValue({
        canpost: false,
        reason:
          "That member hasn't set their location, so we can't post for them",
      })
    })

    it('shows the reason', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain("hasn't set their location")
    })

    it('disables posting', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.spin-button').attributes('disabled')).toBeDefined()
    })
  })

  it('previews the note that will be left on the thread', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('.converted-notice').exists()).toBe(true)
    expect(wrapper.text()).toContain('What goes on this chat')
  })

  it('previews the note with the type about to be posted', async () => {
    // "Does anyone have" reads as a WANTED, so the note previews as a WANTED.
    // The member reads this exact wording, so the preview must track the
    // moderator's choice rather than showing a generic note.
    const wrapper = createWrapper({
      message: 'Does anyone have any bunny ears',
    })
    await flushPromises()
    expect(wrapper.find('.converted-notice').attributes('data-msgtype')).toBe(
      'Wanted'
    )
  })

  describe('photo carry-over', () => {
    it('tells the moderator the photo comes too', async () => {
      const wrapper = createWrapper({
        image: { id: 7, paththumb: 'https://example.com/t.jpg' },
      })
      await flushPromises()
      expect(wrapper.text()).toContain("We'll include their photo")
    })

    it('says nothing about photos when there is none', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).not.toContain('photo')
    })
  })

  // Assert on the SUBJECT line specifically. The description below it is the
  // member's post verbatim, so the whole modal legitimately still contains the
  // words the subject strips out.
  describe('guessing from the post', () => {
    const subjectOf = (wrapper) => wrapper.find('.b-card .fw-bold').text()

    it('reads "looking for" as a WANTED', async () => {
      const wrapper = createWrapper({
        message: 'Looking for a bookcase please',
      })
      await flushPromises()
      expect(subjectOf(wrapper)).toContain('WANTED:')
    })

    it('treats anything else as an OFFER', async () => {
      const wrapper = createWrapper({ message: 'Dining chairs going spare' })
      await flushPromises()
      expect(subjectOf(wrapper)).toContain('OFFER:')
    })

    it('strips the offer-speak tail so the subject is just the item', async () => {
      const wrapper = createWrapper({
        message: 'Set of four dining chairs going spare if anyone wants them',
      })
      await flushPromises()
      expect(subjectOf(wrapper)).toContain('Set of four dining chairs')
      expect(subjectOf(wrapper)).not.toContain('going spare')
    })

    it('drops a greeting', async () => {
      const wrapper = createWrapper({ message: 'Hi all, garden bench' })
      await flushPromises()
      expect(subjectOf(wrapper)).toContain('garden bench')
      expect(subjectOf(wrapper)).not.toContain('Hi all')
    })
  })
})
