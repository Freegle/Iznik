import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ModMessageWorry from '~/modtools/components/ModMessageWorry.vue'

const { mockMessageStore } = vi.hoisted(() => {
  const mockMessageStore = {
    byId: vi.fn(),
    fetch: vi.fn().mockResolvedValue(),
  }
  return { mockMessageStore }
})

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

const STUBS = {
  NoticeMessage: {
    template: '<div class="notice-message" :class="variant"><slot /></div>',
    props: ['variant'],
  },
  ExternalLink: {
    template: '<a :href="href" target="_blank"><slot /></a>',
    props: ['href'],
  },
}

function makeMessage(reasons = [], worry = []) {
  return {
    id: 123,
    worry,
    groups: [{ contentcheck_reasons: reasons }],
  }
}

function mountWithReasons(reasons) {
  const message = makeMessage(reasons)
  mockMessageStore.byId.mockImplementation((id) =>
    id === message.id ? message : null
  )
  return mount(ModMessageWorry, {
    props: { messageid: message.id },
    global: { stubs: STUBS },
  })
}

describe('ModMessageWorry', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('rendering', () => {
    it('renders nothing when there are no contentcheck reasons', () => {
      const wrapper = mountWithReasons([])
      expect(wrapper.findAll('.notice-message').length).toBe(0)
    })

    it('renders one NoticeMessage per reason', () => {
      const wrapper = mountWithReasons([
        { check: 'Vague', category: null, detail: "Item name 'stuff' is too generic" },
        { check: 'PhoneNumber', category: null, detail: 'Post contains what looks like a phone number' },
      ])
      expect(wrapper.findAll('.notice-message').length).toBe(2)
    })

    it('applies warning variant to each NoticeMessage', () => {
      const wrapper = mountWithReasons([
        { check: 'Vague', category: null, detail: 'too generic' },
      ])
      expect(wrapper.find('.notice-message.warning').exists()).toBe(true)
    })

    it('handles message with no groups', () => {
      const message = { id: 123 }
      mockMessageStore.byId.mockImplementation(() => message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.findAll('.notice-message').length).toBe(0)
    })

    it('handles group with null contentcheck_reasons', () => {
      const message = { id: 123, groups: [{ contentcheck_reasons: null }] }
      mockMessageStore.byId.mockImplementation(() => message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.findAll('.notice-message').length).toBe(0)
    })
  })

  describe('worry words (Go API message.worry)', () => {
    it('shows nothing when worry array is empty', () => {
      const wrapper = mountWithReasons([])
      expect(wrapper.findAll('.notice-message').length).toBe(0)
    })

    it('shows one NoticeMessage per worry match', () => {
      const message = makeMessage([], [
        { word: 'gun', worryword: { keyword: 'gun', type: 'Review' } },
        { word: 'knife', worryword: { keyword: 'knife', type: 'Regulated' } },
      ])
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.findAll('.notice-message').length).toBe(2)
    })

    it('shows the matched keyword and review text for Review type', () => {
      const message = makeMessage([], [
        { word: 'gun', worryword: { keyword: 'gun', type: 'Review' } },
      ])
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.text()).toContain('gun')
      expect(wrapper.text()).toContain('flagged up for review')
    })

    it('shows Regulated substance text for Regulated type', () => {
      const message = makeMessage([], [
        { word: 'gun', worryword: { keyword: 'gun', type: 'Regulated' } },
      ])
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.text()).toContain('regulated substance')
    })

    it('shows Reportable substance text for Reportable type', () => {
      const message = makeMessage([], [
        { word: 'acid', worryword: { keyword: 'acid', type: 'Reportable' } },
      ])
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.text()).toContain('reportable substance')
    })

    it('shows Medicine text for Medicine type', () => {
      const message = makeMessage([], [
        { word: 'codeine', worryword: { keyword: 'codeine', type: 'Medicine' } },
      ])
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.text()).toContain('drug, medicine or supplement')
    })

    it('shows both worry matches and contentcheck reasons', () => {
      const message = makeMessage(
        [{ check: 'Vague', category: null, detail: 'too generic' }],
        [{ word: 'gun', worryword: { keyword: 'gun', type: 'Review' } }]
      )
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.findAll('.notice-message').length).toBe(2)
    })
  })

  describe('Vague check', () => {
    it('shows Vague post heading', () => {
      const wrapper = mountWithReasons([
        { check: 'Vague', category: null, detail: "Item name 'stuff' is too generic" },
      ])
      expect(wrapper.text()).toContain('Vague post')
    })

    it('shows the detail text', () => {
      const wrapper = mountWithReasons([
        { check: 'Vague', category: null, detail: "Item name 'stuff' is too generic" },
      ])
      expect(wrapper.text()).toContain("Item name 'stuff' is too generic")
    })

    it('asks mod to request a more specific description', () => {
      const wrapper = mountWithReasons([
        { check: 'Vague', category: null, detail: "Item name 'junk' is too generic" },
      ])
      expect(wrapper.text()).toContain('describe the item more specifically')
    })
  })

  describe('PhoneNumber check', () => {
    it('shows Phone number heading', () => {
      const wrapper = mountWithReasons([
        { check: 'PhoneNumber', category: null, detail: 'Post contains what looks like a phone number' },
      ])
      expect(wrapper.text()).toContain('Phone number')
    })

    it('asks mod to request phone number removal', () => {
      const wrapper = mountWithReasons([
        { check: 'PhoneNumber', category: null, detail: 'Post contains what looks like a phone number' },
      ])
      expect(wrapper.text()).toContain('remove their phone number')
    })
  })

  describe('EmailAddress check', () => {
    it('shows Email address heading', () => {
      const wrapper = mountWithReasons([
        { check: 'EmailAddress', category: null, detail: 'Post contains an external email address' },
      ])
      expect(wrapper.text()).toContain('Email address')
    })

    it('asks mod to request email removal', () => {
      const wrapper = mountWithReasons([
        { check: 'EmailAddress', category: null, detail: 'Post contains an external email address' },
      ])
      expect(wrapper.text()).toContain('remove their email address')
    })
  })

  describe('MessagingLink check', () => {
    it('shows Messaging app link heading', () => {
      const wrapper = mountWithReasons([
        { check: 'MessagingLink', category: null, detail: 'Post contains a messaging app link (wa.me)' },
      ])
      expect(wrapper.text()).toContain('Messaging app link')
    })

    it('shows the detail text', () => {
      const wrapper = mountWithReasons([
        { check: 'MessagingLink', category: null, detail: 'Post contains a messaging app link (wa.me)' },
      ])
      expect(wrapper.text()).toContain('Post contains a messaging app link (wa.me)')
    })

    it('asks mod to request link removal', () => {
      const wrapper = mountWithReasons([
        { check: 'MessagingLink', category: null, detail: 'Post contains a messaging app link (wa.me)' },
      ])
      expect(wrapper.text()).toContain('remove the link')
    })
  })

  describe('ConcernKeyword check — categories', () => {
    it('substance_regulated: shows Regulated substance heading', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'substance_regulated', detail: "Matched concern keyword 'cocaine'" },
      ])
      expect(wrapper.text()).toContain('Regulated substance')
    })

    it('substance_regulated: mentions not legal on Freegle', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'substance_regulated', detail: "Matched concern keyword 'cocaine'" },
      ])
      expect(wrapper.text()).toContain('not legal on Freegle')
    })

    it('substance_regulated: links to Central', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'substance_regulated', detail: "Matched concern keyword 'cocaine'" },
      ])
      const links = wrapper.findAll('a')
      const centralLink = links.find((l) =>
        l.attributes('href')?.includes('discourse.ilovefreegle.org')
      )
      expect(centralLink).toBeDefined()
    })

    it('substance_reportable: shows Reportable substance heading', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'substance_reportable', detail: "Matched concern keyword 'asbestos'" },
      ])
      expect(wrapper.text()).toContain('Reportable substance')
    })

    it('substance_reportable: mentions reporting to police', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'substance_reportable', detail: "Matched concern keyword 'asbestos'" },
      ])
      expect(wrapper.text()).toContain('reported to the police')
    })

    it('substance_medicine: shows Medicine or drug heading', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'substance_medicine', detail: "Matched concern keyword 'aspirin'" },
      ])
      expect(wrapper.text()).toContain('Medicine or drug')
    })

    it('substance_medicine: mentions not legal on Freegle', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'substance_medicine', detail: "Matched concern keyword 'aspirin'" },
      ])
      expect(wrapper.text()).toContain('not legal on Freegle')
    })

    it('scam: shows Possible scam heading', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'scam', detail: "Matched concern keyword 'lottery'" },
      ])
      expect(wrapper.text()).toContain('Possible scam')
    })

    it('scam: says fine to approve if nothing wrong', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'scam', detail: "Matched concern keyword 'lottery'" },
      ])
      expect(wrapper.text()).toContain("fine to approve")
    })

    it('review (generic category): shows Flagged for review heading', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'review', detail: "Matched concern keyword 'test'" },
      ])
      expect(wrapper.text()).toContain('Flagged for review')
    })

    it('review: shows detail text', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'review', detail: "Matched concern keyword 'suspicious'" },
      ])
      expect(wrapper.text()).toContain("Matched concern keyword 'suspicious'")
    })

    it('unknown category: falls through to Flagged for review', () => {
      const wrapper = mountWithReasons([
        { check: 'ConcernKeyword', category: 'unknown_future_category', detail: 'some detail' },
      ])
      expect(wrapper.text()).toContain('Flagged for review')
    })
  })

  describe('NotAnItem check', () => {
    it('shows Possibly not an item heading', () => {
      const wrapper = mountWithReasons([
        { check: 'NotAnItem', category: 'service', detail: 'Post may be a non-physical request (service) rather than an item — matched "cleaner wanted"' },
      ])
      expect(wrapper.text()).toContain('Possibly not an item')
    })

    it('shows the detail text (matched phrase)', () => {
      const wrapper = mountWithReasons([
        { check: 'NotAnItem', category: 'accommodation', detail: 'Post may be a non-physical request (accommodation) rather than an item — matched "to rent"' },
      ])
      expect(wrapper.text()).toContain('matched "to rent"')
    })

    it('explains it may not be a physical item', () => {
      const wrapper = mountWithReasons([
        { check: 'NotAnItem', category: 'work', detail: 'Post may be a non-physical request (work) rather than an item — matched "job vacancy"' },
      ])
      expect(wrapper.text()).toContain('rather than a physical item')
    })
  })

  describe('unknown check type', () => {
    it('shows generic Flagged heading', () => {
      const wrapper = mountWithReasons([
        { check: 'SomeFutureCheck', category: null, detail: 'something flagged' },
      ])
      expect(wrapper.text()).toContain('Flagged')
    })

    it('shows the detail text', () => {
      const wrapper = mountWithReasons([
        { check: 'SomeFutureCheck', category: null, detail: 'something flagged' },
      ])
      expect(wrapper.text()).toContain('something flagged')
    })
  })

  describe('multiple reasons', () => {
    it('renders a separate NoticeMessage for each reason', () => {
      const wrapper = mountWithReasons([
        { check: 'Vague', category: null, detail: "Item name 'stuff' is too generic" },
        { check: 'ConcernKeyword', category: 'scam', detail: "Matched concern keyword 'lottery'" },
        { check: 'PhoneNumber', category: null, detail: 'Post contains what looks like a phone number' },
      ])
      expect(wrapper.findAll('.notice-message').length).toBe(3)
    })
  })

  describe('props', () => {
    it('messageid prop is required and used', () => {
      const wrapper = mountWithReasons([])
      expect(wrapper.props('messageid')).toBe(123)
    })
  })
})
