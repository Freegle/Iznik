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
        {
          check: 'Vague',
          category: null,
          detail: "Item name 'stuff' is too generic",
        },
        {
          check: 'PhoneNumber',
          category: null,
          detail: 'Post contains what looks like a phone number',
        },
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
      const message = makeMessage(
        [],
        [
          { word: 'gun', worryword: { keyword: 'gun', type: 'Review' } },
          { word: 'knife', worryword: { keyword: 'knife', type: 'Regulated' } },
        ]
      )
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.findAll('.notice-message').length).toBe(2)
    })

    it('shows the matched keyword and review text for Review type', () => {
      const message = makeMessage(
        [],
        [{ word: 'gun', worryword: { keyword: 'gun', type: 'Review' } }]
      )
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.text()).toContain('gun')
      expect(wrapper.text()).toContain('flagged up for review')
    })

    it('shows Regulated substance text for Regulated type', () => {
      const message = makeMessage(
        [],
        [{ word: 'gun', worryword: { keyword: 'gun', type: 'Regulated' } }]
      )
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.text()).toContain('regulated substance')
    })

    it('shows Reportable substance text for Reportable type', () => {
      const message = makeMessage(
        [],
        [{ word: 'acid', worryword: { keyword: 'acid', type: 'Reportable' } }]
      )
      mockMessageStore.byId.mockReturnValue(message)
      const wrapper = mount(ModMessageWorry, {
        props: { messageid: 123 },
        global: { stubs: STUBS },
      })
      expect(wrapper.text()).toContain('reportable substance')
    })

    it('shows Medicine text for Medicine type', () => {
      const message = makeMessage(
        [],
        [
          {
            word: 'codeine',
            worryword: { keyword: 'codeine', type: 'Medicine' },
          },
        ]
      )
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
        {
          check: 'Vague',
          category: null,
          detail: "Item name 'stuff' is too generic",
        },
      ])
      expect(wrapper.text()).toContain('Vague post')
    })

    it('shows the detail text', () => {
      const wrapper = mountWithReasons([
        {
          check: 'Vague',
          category: null,
          detail: "Item name 'stuff' is too generic",
        },
      ])
      expect(wrapper.text()).toContain("Item name 'stuff' is too generic")
    })

    it('asks mod to request a more specific description', () => {
      const wrapper = mountWithReasons([
        {
          check: 'Vague',
          category: null,
          detail: "Item name 'junk' is too generic",
        },
      ])
      expect(wrapper.text()).toContain('describe the item more specifically')
    })
  })

  describe('PhoneNumber check', () => {
    it('shows Phone number heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'PhoneNumber',
          category: null,
          detail: 'Post contains what looks like a phone number',
        },
      ])
      expect(wrapper.text()).toContain('Phone number')
    })

    it('asks mod to request phone number removal', () => {
      const wrapper = mountWithReasons([
        {
          check: 'PhoneNumber',
          category: null,
          detail: 'Post contains what looks like a phone number',
        },
      ])
      expect(wrapper.text()).toContain('remove their phone number')
    })
  })

  describe('EmailAddress check', () => {
    it('shows Email address heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'EmailAddress',
          category: null,
          detail: 'Post contains an external email address',
        },
      ])
      expect(wrapper.text()).toContain('Email address')
    })

    it('asks mod to request email removal', () => {
      const wrapper = mountWithReasons([
        {
          check: 'EmailAddress',
          category: null,
          detail: 'Post contains an external email address',
        },
      ])
      expect(wrapper.text()).toContain('remove their email address')
    })
  })

  describe('MessagingLink check', () => {
    it('shows Messaging app link heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'MessagingLink',
          category: null,
          detail: 'Post contains a messaging app link (wa.me)',
        },
      ])
      expect(wrapper.text()).toContain('Messaging app link')
    })

    it('shows the detail text', () => {
      const wrapper = mountWithReasons([
        {
          check: 'MessagingLink',
          category: null,
          detail: 'Post contains a messaging app link (wa.me)',
        },
      ])
      expect(wrapper.text()).toContain(
        'Post contains a messaging app link (wa.me)'
      )
    })

    it('asks mod to request link removal', () => {
      const wrapper = mountWithReasons([
        {
          check: 'MessagingLink',
          category: null,
          detail: 'Post contains a messaging app link (wa.me)',
        },
      ])
      expect(wrapper.text()).toContain('remove the link')
    })
  })

  describe('ConcernKeyword check — categories', () => {
    it('substance_regulated: shows Regulated substance heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'substance_regulated',
          detail: "Matched concern keyword 'cocaine'",
        },
      ])
      expect(wrapper.text()).toContain('Regulated substance')
    })

    it('substance_regulated: says it is not allowed on Freegle', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'substance_regulated',
          detail: "Matched concern keyword 'cocaine'",
        },
      ])
      expect(wrapper.text()).toContain("isn't allowed on Freegle")
    })

    it('substance_regulated: links to Central', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'substance_regulated',
          detail: "Matched concern keyword 'cocaine'",
        },
      ])
      const links = wrapper.findAll('a')
      const centralLink = links.find((l) =>
        l.attributes('href')?.includes('discourse.ilovefreegle.org')
      )
      expect(centralLink).toBeDefined()
    })

    it('substance_reportable: shows Reportable substance heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'substance_reportable',
          detail: "Matched concern keyword 'asbestos'",
        },
      ])
      expect(wrapper.text()).toContain('Reportable substance')
    })

    it('substance_reportable: mentions reporting to police', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'substance_reportable',
          detail: "Matched concern keyword 'asbestos'",
        },
      ])
      expect(wrapper.text()).toContain('reported to the police')
    })

    it('substance_medicine: shows Medicine or drug heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'substance_medicine',
          detail: "Matched concern keyword 'aspirin'",
        },
      ])
      expect(wrapper.text()).toContain('Medicine or drug')
    })

    it('substance_medicine: says it is not allowed on Freegle', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'substance_medicine',
          detail: "Matched concern keyword 'aspirin'",
        },
      ])
      expect(wrapper.text()).toContain("isn't allowed on Freegle")
    })

    it('scam: shows Possible scam heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'scam',
          detail: "Matched concern keyword 'lottery'",
        },
      ])
      expect(wrapper.text()).toContain('Possible scam')
    })

    it('scam: says fine to approve if nothing wrong', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'scam',
          detail: "Matched concern keyword 'lottery'",
        },
      ])
      expect(wrapper.text()).toContain('fine to approve')
    })

    it('review (generic category): shows Flagged for review heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'review',
          detail: "Matched concern keyword 'test'",
        },
      ])
      expect(wrapper.text()).toContain('Flagged for review')
    })

    it('review: shows detail text', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'review',
          detail: "Matched concern keyword 'suspicious'",
        },
      ])
      expect(wrapper.text()).toContain("Matched concern keyword 'suspicious'")
    })

    it('unknown category: falls through to Flagged for review', () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: 'unknown_future_category',
          detail: 'some detail',
        },
      ])
      expect(wrapper.text()).toContain('Flagged for review')
    })
  })

  describe('NotAnItem check', () => {
    it('shows Possibly not an item heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'NotAnItem',
          category: 'service',
          detail:
            'Post may be a non-physical request (service) rather than an item — matched "cleaner wanted"',
        },
      ])
      expect(wrapper.text()).toContain('Possibly not an item')
    })

    it('shows the detail text (matched phrase)', () => {
      const wrapper = mountWithReasons([
        {
          check: 'NotAnItem',
          category: 'accommodation',
          detail:
            'Post may be a non-physical request (accommodation) rather than an item — matched "to rent"',
        },
      ])
      expect(wrapper.text()).toContain('matched "to rent"')
    })

    it('explains it may not be a physical item', () => {
      const wrapper = mountWithReasons([
        {
          check: 'NotAnItem',
          category: 'work',
          detail:
            'Post may be a non-physical request (work) rather than an item — matched "job vacancy"',
        },
      ])
      expect(wrapper.text()).toContain('rather than a physical item')
    })
  })

  describe('unknown check type', () => {
    it('shows generic Flagged heading', () => {
      const wrapper = mountWithReasons([
        {
          check: 'SomeFutureCheck',
          category: null,
          detail: 'something flagged',
        },
      ])
      expect(wrapper.text()).toContain('Flagged')
    })

    it('shows the detail text', () => {
      const wrapper = mountWithReasons([
        {
          check: 'SomeFutureCheck',
          category: null,
          detail: 'something flagged',
        },
      ])
      expect(wrapper.text()).toContain('something flagged')
    })
  })

  describe('multiple reasons', () => {
    it('renders a separate NoticeMessage for each reason', () => {
      const wrapper = mountWithReasons([
        {
          check: 'Vague',
          category: null,
          detail: "Item name 'stuff' is too generic",
        },
        {
          check: 'ConcernKeyword',
          category: 'scam',
          detail: "Matched concern keyword 'lottery'",
        },
        {
          check: 'PhoneNumber',
          category: null,
          detail: 'Post contains what looks like a phone number',
        },
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

  describe('keyword de-duplication and per-group scoping', () => {
    function mountMessage(message, props = {}) {
      mockMessageStore.byId.mockImplementation((id) =>
        id === message.id ? message : null
      )
      return mount(ModMessageWorry, {
        props: { messageid: message.id, ...props },
        global: { stubs: STUBS },
      })
    }

    it('shows one box when a live worry word and a stored reason name the same keyword', () => {
      const message = {
        id: 1,
        worry: [
          {
            word: 'cot mattress',
            worryword: { keyword: 'cot mattress', type: 'Review' },
          },
        ],
        groups: [
          {
            groupid: 10,
            contentcheck_reasons: [
              {
                check: 'ConcernKeyword',
                category: 'review',
                keyword: 'cot mattress',
                detail: "Matched concern keyword 'cot mattress'",
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 10 })
      expect(wrapper.findAll('.notice-message').length).toBe(1)
    })

    it('collapses ConcernKeyword and PerGroupWorryWord reasons for the same keyword', () => {
      const message = {
        id: 2,
        worry: [],
        groups: [
          {
            groupid: 10,
            contentcheck_reasons: [
              {
                check: 'ConcernKeyword',
                category: 'review',
                keyword: 'cot mattress',
                detail: "Matched concern keyword 'cot mattress'",
              },
              {
                check: 'PerGroupWorryWord',
                category: null,
                keyword: 'cot mattress',
                detail: "Matched per-group worry word 'cot mattress'",
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 10 })
      expect(wrapper.findAll('.notice-message').length).toBe(1)
      expect(wrapper.text()).toContain('Flagged for review')
    })

    it("does not show another group's worry word when moderating a clean group", () => {
      const message = {
        id: 3,
        worry: [
          {
            word: 'cot mattress',
            worryword: { keyword: 'cot mattress', type: 'Review' },
          },
        ],
        groups: [
          { groupid: 10, contentcheck_reasons: null },
          {
            groupid: 20,
            contentcheck_reasons: [
              {
                check: 'ConcernKeyword',
                category: 'review',
                keyword: 'cot mattress',
                detail: "Matched concern keyword 'cot mattress'",
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 10 })
      expect(wrapper.findAll('.notice-message').length).toBe(0)
    })

    it('shows the flag for the group that configured it', () => {
      const message = {
        id: 4,
        worry: [
          {
            word: 'cot mattress',
            worryword: { keyword: 'cot mattress', type: 'Review' },
          },
        ],
        groups: [
          { groupid: 10, contentcheck_reasons: null },
          {
            groupid: 20,
            contentcheck_reasons: [
              {
                check: 'ConcernKeyword',
                category: 'review',
                keyword: 'cot mattress',
                detail: "Matched concern keyword 'cot mattress'",
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 20 })
      expect(wrapper.findAll('.notice-message').length).toBe(1)
      expect(wrapper.text()).toContain("Matched concern keyword 'cot mattress'")
    })

    it('keeps distinct keywords as separate boxes (no false de-dup)', () => {
      const message = {
        id: 5,
        worry: [{ word: 'gun', worryword: { keyword: 'gun', type: 'Review' } }],
        groups: [
          {
            groupid: 10,
            contentcheck_reasons: [
              {
                check: 'ConcernKeyword',
                category: 'scam',
                keyword: 'lottery',
                detail: "Matched concern keyword 'lottery'",
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 10 })
      expect(wrapper.findAll('.notice-message').length).toBe(2)
    })

    it('never de-duplicates non-keyword checks', () => {
      const message = {
        id: 6,
        worry: [],
        groups: [
          {
            groupid: 10,
            contentcheck_reasons: [
              {
                check: 'Vague',
                category: null,
                detail: "Item name 'stuff' is too generic",
              },
              {
                check: 'PhoneNumber',
                category: null,
                detail: 'Post contains a phone number',
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 10 })
      expect(wrapper.findAll('.notice-message').length).toBe(2)
    })

    it('matches the group row when groupid is a string in the API data', () => {
      const message = {
        id: 8,
        worry: [],
        groups: [
          { groupid: '10', contentcheck_reasons: null },
          {
            groupid: '20',
            contentcheck_reasons: [
              {
                check: 'ConcernKeyword',
                category: 'review',
                keyword: 'cot mattress',
                detail: "Matched concern keyword 'cot mattress'",
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 20 })
      expect(wrapper.findAll('.notice-message').length).toBe(1)
    })

    it('de-dupes against legacy stored reasons that have no keyword field', () => {
      const message = {
        id: 7,
        worry: [
          {
            word: 'cot mattress',
            worryword: { keyword: 'cot mattress', type: 'Review' },
          },
        ],
        groups: [
          {
            groupid: 10,
            contentcheck_reasons: [
              {
                check: 'ConcernKeyword',
                category: 'review',
                detail: "Matched concern keyword 'cot mattress'",
              },
            ],
          },
        ],
      }
      const wrapper = mountMessage(message, { groupid: 10 })
      expect(wrapper.findAll('.notice-message').length).toBe(1)
    })
  })

  // This component is the only place stored hold reasons are rendered. A second
  // renderer in ModMessage meant a post with two flags showed four notices
  // (Discourse 9989); ModMessage now says which causes its own notices cover.
  describe('single owner of hold reasons (Discourse 9989)', () => {
    function mountMessage(message, props = {}) {
      mockMessageStore.byId.mockImplementation((id) =>
        id === message.id ? message : null
      )
      return mount(ModMessageWorry, {
        props: { messageid: message.id, ...props },
        global: { stubs: STUBS },
      })
    }

    it('drops a reason the parent says it is already explaining', () => {
      const wrapper = mountMessage(
        {
          id: 1,
          worry: [],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: [
                { check: 'NoLocation', detail: 'We could not work out where' },
                { check: 'Vague', detail: 'too generic' },
              ],
            },
          ],
        },
        { groupid: 10, covered: ['NoLocation'] }
      )
      const boxes = wrapper.findAll('.notice-message')
      expect(boxes.length).toBe(1)
      expect(wrapper.text()).toContain('Vague post')
      expect(wrapper.text()).not.toContain('We could not work out where')
    })

    it('keeps a reason when the parent is not covering it', () => {
      const wrapper = mountMessage(
        {
          id: 2,
          worry: [],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: [
                { check: 'MemberModerated', detail: 'moderated member' },
              ],
            },
          ],
        },
        { groupid: 10, covered: [] }
      )
      expect(wrapper.text()).toContain("This member's posts are moderated")
    })

    it('shows a moderation setting as information, not as a warning', () => {
      const wrapper = mountMessage(
        {
          id: 3,
          worry: [],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: [
                { check: 'GroupModerated', detail: 'group moderates' },
              ],
            },
          ],
        },
        { groupid: 10 }
      )
      expect(wrapper.find('.notice-message.info').exists()).toBe(true)
      expect(wrapper.find('.notice-message.warning').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Flagged')
    })

    it('hides a money worry word when the stored Money check says the same thing', () => {
      const wrapper = mountMessage(
        {
          id: 4,
          worry: [{ worryword: { keyword: '£', type: 'Other' } }],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: [
                { check: 'Money', detail: 'Post contains a money symbol' },
              ],
            },
          ],
        },
        { groupid: 10 }
      )
      expect(wrapper.findAll('.notice-message').length).toBe(1)
      expect(wrapper.text()).toContain('Post contains a money symbol')
    })

    it('still shows a money worry word when no stored Money check is displayed', () => {
      const wrapper = mountMessage(
        {
          id: 5,
          worry: [{ worryword: { keyword: '£', type: 'Other' } }],
          groups: [{ groupid: 10, contentcheck_reasons: [] }],
        },
        { groupid: 10 }
      )
      expect(wrapper.text()).toContain('Flagged for review:')
      expect(wrapper.text()).toContain('£')
    })

    it('names the word behind a categorised keyword flag (Discourse 9988)', () => {
      const wrapper = mountMessage(
        {
          id: 6,
          worry: [],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: [
                {
                  check: 'ConcernKeyword',
                  category: 'substance_medicine',
                  keyword: 'mineral',
                  detail: "Matched concern keyword 'mineral'",
                },
              ],
            },
          ],
        },
        { groupid: 10 }
      )
      const text = wrapper.text()
      expect(text).toContain('Medicine or drug')
      expect(text).toContain('Triggered by the word')
      expect(text.split('mineral').length - 1).toBe(1)
    })

    it('reads reasons that arrive as a JSON string', () => {
      const wrapper = mountMessage(
        {
          id: 7,
          worry: [],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: JSON.stringify([
                { check: 'Vague', detail: 'too generic' },
              ]),
            },
          ],
        },
        { groupid: 10 }
      )
      expect(wrapper.text()).toContain('Vague post')
    })

    it('uses one lead-in for every flag, never "Flagged" and "Flagged for review" together', () => {
      const wrapper = mountMessage(
        {
          id: 9,
          worry: [],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: [
                {
                  check: 'ConcernKeyword',
                  category: null,
                  keyword: 'jewellery',
                  detail: "Matched concern keyword 'jewellery'",
                },
                {
                  check: 'ConcernKeyword',
                  category: 'substance_medicine',
                  keyword: 'codeine',
                  detail: "Matched concern keyword 'codeine'",
                },
                { check: 'Money', detail: 'Post contains a money symbol' },
                { check: 'SomeFutureCheck', detail: 'something else' },
              ],
            },
          ],
        },
        { groupid: 10 }
      )
      const text = wrapper.text()
      expect(text).toContain('Flagged for review')
      // Every "Flagged" must be part of "Flagged for review".
      expect(text.split('Flagged').length - 1).toBe(
        text.split('Flagged for review').length - 1
      )
    })

    it('words the substance guidance the same whichever pass found it', () => {
      // The stored check and the real-time worry word describe the same three
      // substance cases and must not read differently.
      const stored = mountMessage(
        {
          id: 10,
          worry: [],
          groups: [
            {
              groupid: 10,
              contentcheck_reasons: [
                {
                  check: 'ConcernKeyword',
                  category: 'substance_medicine',
                  keyword: 'codeine',
                  detail: "Matched concern keyword 'codeine'",
                },
              ],
            },
          ],
        },
        { groupid: 10 }
      )
      const live = mountMessage(
        {
          id: 11,
          worry: [{ worryword: { keyword: 'codeine', type: 'Medicine' } }],
          groups: [{ groupid: 10, contentcheck_reasons: [] }],
        },
        { groupid: 10 }
      )
      // Both routes must carry the identical guidance sentence. They differ only
      // in how they name the word, which each does in its own lead-in.
      const GUIDANCE =
        "Medicine or drug: This post might contain a drug, medicine or supplement, which isn't allowed on Freegle. If you have questions, ask on Central."
      const flat = (w) => w.text().replace(/\s+/g, ' ')
      expect(flat(stored)).toContain(GUIDANCE)
      expect(flat(live)).toContain(GUIDANCE)
    })

    it('survives a malformed reasons value rather than breaking the card', () => {
      const consoleError = vi
        .spyOn(console, 'error')
        .mockImplementation(() => {})
      const wrapper = mountMessage(
        {
          id: 8,
          worry: [],
          groups: [{ groupid: 10, contentcheck_reasons: 'not json at all' }],
        },
        { groupid: 10 }
      )
      expect(wrapper.findAll('.notice-message').length).toBe(0)
      consoleError.mockRestore()
    })
  })
})
