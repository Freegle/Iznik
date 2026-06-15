import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ModMessage from '~/modtools/components/ModMessage.vue'
// The real child, so tests can assert on what a moderator actually sees rather
// than on the stub that hid duplicate hold reasons (Discourse 9989).
import ModMessageWorry from '~/modtools/components/ModMessageWorry.vue'

// Hoisted mocks
const {
  mockAuthStore,
  mockLocationStore,
  mockMemberStore,
  mockMessageStore,
  mockMiscStore,
  mockModconfigStore,
  mockModGroupStore,
  mockGroupStore,
  mockUserStore,
  mockMe,
  mockMyModGroups,
} = vi.hoisted(() => {
  return {
    mockGroupStore: {
      get: vi.fn((id) => ({ id, namedisplay: `Group ${id}` })),
      fetch: vi.fn().mockResolvedValue(),
    },
    mockAuthStore: {
      groups: [{ groupid: 789, configid: 1 }],
    },
    mockLocationStore: {
      fetch: vi.fn().mockResolvedValue(null),
    },
    mockMemberStore: {
      update: vi.fn().mockResolvedValue(),
    },
    mockMessageStore: {
      patch: vi.fn().mockResolvedValue(),
      move: vi.fn().mockResolvedValue(),
      fetch: vi.fn().mockResolvedValue(),
      backToPending: vi.fn().mockResolvedValue(),
      byId: vi.fn().mockReturnValue(null),
    },
    mockMiscStore: {
      modtoolsediting: false,
    },
    mockModconfigStore: {
      configs: [
        {
          id: 1,
          default: true,
          coloursubj: true,
          subjreg: /^(OFFER|WANTED):/i,
        },
      ],
      fetchById: vi.fn().mockResolvedValue({
        id: 1,
        default: true,
        coloursubj: true,
        subjreg: /^(OFFER|WANTED):/i,
      }),
    },
    mockModGroupStore: {
      fetchIfNeedBeMT: vi.fn().mockResolvedValue({}),
    },
    mockUserStore: {
      fetch: vi.fn().mockResolvedValue(),
      fetchMT: vi.fn().mockResolvedValue(),
      byId: vi.fn().mockReturnValue({
        id: 456,
        displayname: 'Updated User',
        memberships: [{ id: 789, groupid: 789 }],
      }),
    },
    mockMe: { id: 999, displayname: 'Test Mod' },
    mockMyModGroups: [
      {
        id: 789,
        lat: 52.0,
        lng: -1.0,
        polygon: null,
        mysettings: { configid: 1 },
        settings: {
          duplicates: {
            check: true,
            offer: 14,
            wanted: 14,
          },
          keywords: {
            offer: 'OFFER',
            wanted: 'WANTED',
          },
        },
      },
    ],
  }
})

// Mock stores
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

vi.mock('~/stores/location', () => ({
  useLocationStore: () => mockLocationStore,
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => mockModconfigStore,
}))

vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => mockModGroupStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

// Mock composables
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myid: mockMe.id,
  }),
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    myModGroups: { value: mockMyModGroups },
    myModGroup: (id) => mockMyModGroups.find((g) => g.id === id),
    amAModOn: (id) => mockMyModGroups.some((g) => g.id === id),
  }),
}))

vi.mock('~/composables/useKeywords', () => ({
  setupKeywords: () => ({
    typeOptions: [
      { value: 'Offer', text: 'OFFER' },
      { value: 'Wanted', text: 'WANTED' },
      { value: 'Other', text: 'Other' },
    ],
  }),
}))

vi.mock('~/composables/useTwem', () => ({
  twem: (text) => text,
}))

describe('ModMessage', () => {
  // Helper to create test message data
  const createTestMessage = (overrides = {}) => ({
    id: 123,
    subject: 'OFFER: Test Item (Location)',
    textbody: 'This is the message body',
    type: 'Offer',
    attachments: [{ id: 1, path: '/image1.jpg' }],
    groups: [
      {
        groupid: 789,
        namedisplay: 'Test Group',
        collection: 'Pending',
      },
    ],
    fromuser: 456,
    location: { name: 'SW1A 1AA', lat: 51.5, lng: -0.1 },
    lat: 51.5,
    lng: -0.1,
    source: 'Platform',
    heldby: null,
    outcomes: [],
    successful: false,
    promised: false,
    deadline: null,
    deliverypossible: false,
    availableinitially: null,
    availablenow: null,
    matchedon: null,
    related: [],
    microvolunteering: [],
    worry: null,
    spamreason: null,
    myrole: 'Moderator',
    edits: null,
    item: { name: 'Test Item' },
    ...overrides,
  })

  // Mount helper with common stubs. Pass stubOverrides to change or disable one
  // of them - `{ ModMessageWorry: false }` mounts the real child, which is how
  // duplicate hold-reason rendering becomes visible (Discourse 9989).
  function mountComponent(
    props = {},
    messageOverrides = {},
    stubOverrides = {}
  ) {
    const testMessage = createTestMessage(messageOverrides)
    mockMessageStore.byId.mockReturnValue(testMessage)
    return mount(ModMessage, {
      props: {
        messageid: testMessage.id,
        ...props,
      },
      global: {
        stubs: {
          'b-card': {
            template:
              '<div class="b-card"><slot /><slot name="header" /><slot name="footer" /></div>',
          },
          'b-card-header': {
            template: '<div class="b-card-header"><slot /></div>',
          },
          'b-card-body': {
            template: '<div class="b-card-body"><slot /></div>',
          },
          'b-card-footer': {
            template: '<div class="b-card-footer"><slot /></div>',
          },
          'b-row': { template: '<div class="b-row"><slot /></div>' },
          'b-col': { template: '<div class="b-col"><slot /></div>' },
          'b-alert': {
            template: '<div class="b-alert" :class="variant"><slot /></div>',
            props: ['variant', 'show'],
          },
          'b-badge': {
            template: '<span class="b-badge" :class="variant"><slot /></span>',
            props: ['variant'],
          },
          'b-input-group': {
            template: '<div class="b-input-group"><slot /></div>',
          },
          'b-form-input': {
            template: '<input class="b-form-input" :value="modelValue" />',
            props: ['modelValue', 'size'],
          },
          'b-form-select': {
            template: '<select class="b-form-select"><slot /></select>',
            props: ['modelValue', 'options', 'size'],
          },
          'b-form-textarea': {
            template: '<textarea class="b-form-textarea"></textarea>',
            props: ['modelValue', 'rows'],
          },
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant'],
          },
          PostCode: {
            template: '<div class="postcode"><slot /></div>',
            props: ['value', 'find'],
          },
          ModGroupSelect: {
            template: '<select class="mod-group-select"></select>',
            props: [
              'modelValue',
              'modonly',
              'size',
              'disabledExceptFor',
              'disabled',
            ],
          },
          MessageHistory: {
            template: '<div class="message-history"><slot /></div>',
            props: ['id', 'message', 'modinfo', 'displayMessageLink'],
          },
          ModDiff: {
            template: '<div class="mod-diff"><slot /></div>',
            props: ['old', 'new'],
          },
          ModMessageDuplicate: {
            template: '<div class="mod-message-duplicate"><slot /></div>',
            props: ['messageid'],
          },
          ModMessageCrosspost: {
            template: '<div class="mod-message-crosspost"><slot /></div>',
            props: ['messageid'],
          },
          ModMessageRelated: {
            template: '<div class="mod-message-related"><slot /></div>',
            props: ['messageid'],
          },
          ModComments: {
            template: '<div class="mod-comments"><slot /></div>',
            props: ['userid'],
          },
          ModSpammer: {
            template: '<div class="mod-spammer"><slot /></div>',
            props: ['userid'],
          },
          ModMessageMicroVolunteering: {
            template:
              '<div class="mod-message-microvolunteering"><slot /></div>',
            props: ['messageid', 'microvolunteering'],
          },
          ModMessageWorry: {
            template: '<div class="mod-message-worry"><slot /></div>',
            props: ['messageid'],
          },
          ModPhoto: {
            template: '<div class="mod-photo"><slot /></div>',
            props: ['messageid', 'attachmentid'],
          },
          MessageReplyInfo: {
            template: '<div class="message-reply-info"><slot /></div>',
            props: ['message'],
          },
          MessageMap: {
            template: '<div class="message-map"><slot /></div>',
            props: ['centerat', 'position', 'locked', 'boundary', 'height'],
          },
          ModMessageUserInfo: {
            template: '<div class="mod-message-user-info"><slot /></div>',
            props: ['message', 'userid', 'modinfo', 'groupid', 'milesaway'],
          },
          SettingsGroup: {
            template: '<div class="settings-group"><slot /></div>',
            props: ['emailfrequency', 'membershipMT', 'userid'],
          },
          ModMemberActions: {
            template: '<div class="mod-member-actions"><slot /></div>',
            props: ['userid', 'groupid'],
          },
          OurUploader: {
            template: '<div class="our-uploader"><slot /></div>',
            props: ['modelValue', 'type', 'multiple'],
          },
          ModMessageButtons: {
            template: '<div class="mod-message-buttons"><slot /></div>',
            props: ['messageid', 'modconfigid', 'editreview', 'cantpost'],
          },
          ModMessageButton: {
            template: '<button class="mod-message-button"><slot /></button>',
            props: ['messageid', 'variant', 'icon', 'release', 'label'],
          },
          ModMessageEmailModal: {
            template: '<div class="mod-message-email-modal"><slot /></div>',
            props: ['id'],
          },
          ModBulkPreviewModal: {
            template: '<div class="mod-bulk-preview-modal"><slot /></div>',
            props: ['messageid'],
          },
          ModSpammerReport: {
            template: '<div class="mod-spammer-report"><slot /></div>',
            props: ['userid', 'safelist'],
          },
          RipplingExplanationModal: {
            template: '<div class="rippling-explanation-modal" />',
          },
          ModMessageReachMap: {
            template: '<div class="mod-message-reach-map" />',
          },
          SpinButton: {
            template:
              '<button class="spin-button" @click="$emit(\'handle\', () => {})"><slot />{{ label }}</button>',
            props: ['variant', 'iconName', 'label', 'confirm'],
          },
          Highlighter: {
            template: '<span class="highlighter">{{ textToHighlight }}</span>',
            props: [
              'searchWords',
              'textToHighlight',
              'highlightClassName',
              'autoEscape',
            ],
          },
          'client-only': { template: '<div><slot /></div>' },
          ExternalLink: {
            template: '<a :href="href"><slot /></a>',
            props: ['href'],
          },
          ...stubOverrides,
        },
        mocks: {
          datetimeshort: (val) => `formatted:${val}`,
          dateonly: (val) => `dateonly:${val}`,
          pluralise: (word, count, showCount) =>
            showCount ? `${count} ${word}${count !== 1 ? 's' : ''}` : word,
        },
        directives: {
          'b-tooltip': { mounted() {} },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockMiscStore.modtoolsediting = false
    mockUserStore.byId.mockReturnValue({
      id: 456,
      displayname: 'Updated User',
      memberships: [{ id: 789, groupid: 789 }],
    })
  })

  afterEach(async () => {
    await flushPromises()
  })

  describe('Rendering', () => {
    it('renders card header, subject, and MessageHistory', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.b-card-header').exists()).toBe(true)
      expect(wrapper.text()).toContain('OFFER: Test Item (Location)')
      expect(wrapper.find('.message-history').exists()).toBe(true)
    })
  })

  describe('Computed: groupid', () => {
    it('returns groupid from message groups, 0 when no groups', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.groupid).toBe(789)
    })
  })

  describe('Back to Pending explanation', () => {
    // A "Back to Pending" on any community pulls every copy of a rippled post back to
    // Pending for per-group review, and stores the reason on THAT group's copy
    // (contextGroup.spamreason). Without surfacing it, a mod who already approved the post
    // sees it reappear in Pending with no explanation and clicks Approve repeatedly
    // (Discourse 9909). We show the per-group reason so they understand what happened.
    it('surfaces the per-group reason when a copy is back in Pending', async () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            {
              groupid: 789,
              namedisplay: 'Test Group',
              collection: 'Pending',
              spamreason:
                'A moderator moved this post back to pending for review.',
            },
          ],
        }
      )
      await flushPromises()
      expect(wrapper.vm.contextGroup.spamreason).toBe(
        'A moderator moved this post back to pending for review.'
      )
      expect(wrapper.text()).toContain(
        'A moderator moved this post back to pending for review.'
      )
    })

    it('does not duplicate the message-level spamreason', async () => {
      const wrapper = mountComponent(
        {},
        {
          spamreason: 'Flagged as spam',
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              spamreason: 'Flagged as spam',
            },
          ],
        }
      )
      await flushPromises()
      const count = wrapper.text().split('Flagged as spam').length - 1
      expect(count).toBe(1)
    })
  })

  // The automated content check records WHY it left a post in the queue, but nothing
  // ever showed it. A paint post flagged on the word "mineral" looked to the moderator
  // like it had been called a medicine for no reason (Discourse 9988), and a post held
  // purely by a moderation setting said nothing at all (9987). ModMessageWorry renders
  // these, so mount the real one rather than the stub used elsewhere in this file.
  describe('Content check hold reasons', () => {
    const REAL_WORRY = { ModMessageWorry }

    it('shows the word that flagged the post', async () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              contentcheck_reasons: JSON.stringify([
                {
                  check: 'ConcernKeyword',
                  category: 'substance_medicine',
                  action: 'flag',
                  keyword: 'mineral',
                  detail: "Matched concern keyword 'mineral'",
                },
              ]),
            },
          ],
        },
        REAL_WORRY
      )
      await flushPromises()
      const text = wrapper.text()
      expect(text).toContain('Medicine or drug')
      // Named once - the category alone told Emma nothing (9988), but saying it
      // twice is what 9989 was about.
      expect(text.split('mineral').length - 1).toBe(1)
    })

    it('explains a hold caused by a moderation setting', async () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              contentcheck_reasons: JSON.stringify([
                {
                  check: 'GroupModerated',
                  category: null,
                  action: 'flag',
                  detail:
                    'This group moderates all posts, whatever the member’s setting',
                },
              ]),
            },
          ],
        },
        REAL_WORRY
      )
      await flushPromises()
      expect(wrapper.text()).toContain('This group moderates all posts')
    })

    it('accepts reasons already parsed into an array', async () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              contentcheck_reasons: [
                {
                  check: 'PhoneNumber',
                  detail: 'Post contains a phone number',
                },
              ],
            },
          ],
        },
        REAL_WORRY
      )
      await flushPromises()
      expect(wrapper.text()).toContain('Phone number')
    })

    it('shows nothing when there are no reasons', async () => {
      const wrapper = mountComponent(
        {},
        { groups: [{ groupid: 789, collection: 'Pending' }] },
        REAL_WORRY
      )
      await flushPromises()
      expect(wrapper.find('.mod-message-worry').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Flagged')
    })

    it('survives malformed reasons rather than breaking the card', async () => {
      const consoleError = vi
        .spyOn(console, 'error')
        .mockImplementation(() => {})
      const wrapper = mountComponent(
        {},
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              contentcheck_reasons: 'not json at all',
            },
          ],
        },
        REAL_WORRY
      )
      await flushPromises()
      expect(wrapper.text()).toContain('OFFER: Test Item')
      expect(wrapper.text()).not.toContain('Flagged')
      consoleError.mockRestore()
    })
  })

  // Discourse 9989 ("All flags waving"): a post with two flags showed four notices.
  // ModMessageWorry has rendered contentcheck_reasons since June; a second renderer
  // was added here for the same array, plus hold-reason kinds that repeat notices
  // this component already shows. Every test below mounts the REAL ModMessageWorry -
  // the stub used elsewhere in this file is what hid the duplication.
  describe('Hold reasons are explained exactly once (Discourse 9989)', () => {
    const REAL_WORRY = { ModMessageWorry }

    function occurrences(text, needle) {
      return text.split(needle).length - 1
    }

    function mountWithReasons(reasons, extraMessage = {}, props = {}) {
      return mountComponent(
        props,
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              contentcheck_reasons: reasons,
            },
          ],
          ...extraMessage,
        },
        REAL_WORRY
      )
    }

    it('states a flagged keyword once, not twice', async () => {
      const wrapper = mountWithReasons([
        {
          check: 'ConcernKeyword',
          category: null,
          action: 'flag',
          keyword: 'jewellery',
          detail: "Matched concern keyword 'jewellery'",
        },
      ])
      await flushPromises()
      expect(occurrences(wrapper.text(), 'jewellery')).toBe(1)
    })

    it('states a money-symbol flag once across both of its sources', async () => {
      // The stored Money check and the real-time worry word are the same "£".
      const wrapper = mountWithReasons(
        [{ check: 'Money', detail: 'Post contains a money symbol' }],
        { worry: [{ worryword: { keyword: '£', type: 'Other' } }] }
      )
      await flushPromises()
      const text = wrapper.text()
      expect(occurrences(text, 'Post contains a money symbol')).toBe(1)
      expect(text).not.toContain('Flagged for review: "£"')
    })

    it('explains a group moderation setting once, and not as a flag', async () => {
      const wrapper = mountWithReasons([
        {
          check: 'GroupModerated',
          detail:
            "This group moderates all posts, whatever the member's setting",
        },
      ])
      await flushPromises()
      const text = wrapper.text()
      expect(occurrences(text, 'This group moderates all posts')).toBe(1)
      expect(text).not.toContain('Flagged: This group moderates all posts')
    })

    it('leaves the missing-location advice to the notice that already gives it', async () => {
      const wrapper = mountWithReasons(
        [
          {
            check: 'NoLocation',
            detail:
              'We could not work out where this post is - add a postcode before approving',
          },
        ],
        { location: null, lat: 0, lng: 0 },
        { summary: false }
      )
      await flushPromises()
      const text = wrapper.text()
      expect(text).toContain("We couldn't work out where this post is")
      expect(text).not.toContain('add a postcode before approving')
    })

    it('drops a stale no-location reason once the post has a location', async () => {
      // A moderator added a postcode after the check ran. Saying we don't know
      // where a post with a visible postcode is would be worse than saying nothing.
      const wrapper = mountWithReasons(
        [
          {
            check: 'NoLocation',
            detail:
              'We could not work out where this post is - add a postcode before approving',
          },
        ],
        { location: { lat: 54.97, lng: -1.61 } },
        { summary: false }
      )
      await flushPromises()
      const text = wrapper.text()
      expect(text).not.toContain('add a postcode before approving')
      expect(text).not.toContain("We couldn't work out where this post is")
    })

    it('still explains a member moderation setting when no membership is loaded', async () => {
      // The "This member is Moderated" notice needs fromUser.memberships. Without
      // it the stored reason is the only explanation, so it must survive (9987).
      const wrapper = mountWithReasons([
        {
          check: 'MemberModerated',
          detail: "This member's posts are moderated",
        },
      ])
      await flushPromises()
      expect(
        occurrences(wrapper.text(), "This member's posts are moderated")
      ).toBe(1)
    })
  })

  describe('Computed: alreadyOnHomeGroup', () => {
    // Regression: a post must not be told it "Possibly should be on" a group it is ALREADY
    // on (its origin, or a group it has rippled onto). The hint previously fired whenever the
    // post was viewed under a different group's context than its nearest home group, so a
    // multi-group/rippled post on its home group wrongly showed "Possibly should be on <home>".
    it('is true and suppresses the hint when the post is already on its nearest home group', async () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            {
              groupid: 789,
              namedisplay: 'Home Group',
              collection: 'Approved',
              arrival: '2024-01-01T00:00:00Z',
            },
            {
              groupid: 790,
              namedisplay: 'Other Group',
              collection: 'Approved',
              arrival: '2024-01-02T00:00:00Z',
            },
          ],
          location: {
            name: 'SW1A 1AA',
            lat: 51.5,
            lng: -0.1,
            groupsnear: [{ id: 789, namedisplay: 'Home Group', ontn: true }],
          },
        }
      )
      await flushPromises()
      expect(wrapper.vm.alreadyOnHomeGroup).toBe(true)
      expect(wrapper.text()).not.toContain('Possibly should be on')
    })

    it('is false when the post is NOT on its nearest home group', async () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            {
              groupid: 790,
              namedisplay: 'Other Group',
              collection: 'Approved',
              arrival: '2024-01-02T00:00:00Z',
            },
          ],
          location: {
            name: 'SW1A 1AA',
            lat: 51.5,
            lng: -0.1,
            groupsnear: [{ id: 789, namedisplay: 'Home Group', ontn: true }],
          },
        }
      )
      await flushPromises()
      expect(wrapper.vm.alreadyOnHomeGroup).toBe(false)
    })
  })

  describe('Computed: pending', () => {
    it.each([
      ['Pending', true],
      ['Approved', false],
    ])('returns %s for %s collection', (collection, expected) => {
      const wrapper = mountComponent(
        {},
        {
          groups: [{ groupid: 789, collection }],
        }
      )
      expect(wrapper.vm.pending).toBe(expected)
    })
  })

  describe('Computed: position', () => {
    it.each([
      [
        'returns location when present',
        { location: { name: 'SW1A 1AA', lat: 51.5, lng: -0.1 } },
        { name: 'SW1A 1AA', lat: 51.5, lng: -0.1 },
      ],
      [
        'returns lat/lng when no location but has coordinates',
        { location: null, lat: 52.0, lng: -1.5 },
        { lat: 52.0, lng: -1.5 },
      ],
      [
        'returns null when no position data',
        { location: null, lat: null, lng: null },
        null,
      ],
    ])('%s', (_desc, overrides, expected) => {
      const wrapper = mountComponent({}, overrides)
      expect(wrapper.vm.position).toEqual(expected)
    })
  })

  describe('Computed: outsideUK', () => {
    it.each([
      ['UK position', { lat: 52.0, lng: -1.0 }, false],
      ['outside UK (west)', { lat: 52.0, lng: -20.0 }, true],
      ['outside UK (south)', { lat: 40.0, lng: -1.0 }, true],
      // (0,0) and its blurred form are unresolved locations, not foreign ones -
      // they must NOT trip the scam warning (Discourse #9865).
      ['null island (0,0) is not outside UK', { lat: 0, lng: 0 }, false],
      [
        'blurred null island (0.004,0) is not outside UK',
        { lat: 0.004, lng: 0 },
        false,
      ],
    ])('%s returns %s', (_desc, location, expected) => {
      const wrapper = mountComponent({}, { location })
      expect(wrapper.vm.outsideUK).toBe(expected)
    })
  })

  describe('Computed: noLocation', () => {
    it.each([
      ['real UK location', { lat: 51.5, lng: -0.1 }, false],
      [
        'genuinely foreign location (New York)',
        { lat: 40.7, lng: -74.0 },
        false,
      ],
      ['null island (0,0)', { lat: 0, lng: 0 }, true],
      ['blurred null island (0.004,0)', { lat: 0.004, lng: 0 }, true],
    ])('%s -> %s', (_desc, location, expected) => {
      const wrapper = mountComponent({}, { location })
      expect(wrapper.vm.noLocation).toBe(expected)
    })

    it('is true when there is no position at all', () => {
      const wrapper = mountComponent(
        {},
        { location: null, lat: null, lng: null }
      )
      expect(wrapper.vm.noLocation).toBe(true)
    })
  })

  describe('Computed: eSubject and eBody', () => {
    it('returns message subject and body', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.eSubject).toBe('OFFER: Test Item (Location)')
      expect(wrapper.vm.eBody).toBe('This is the message body')
    })
  })

  describe('Computed: membership', () => {
    it('returns membership for the group from store user', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.membership).toEqual({ id: 789, groupid: 789 })
    })

    it('returns undefined when no matching group in store user', () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Test User',
        memberships: [{ id: 999, groupid: 999 }],
      })
      const wrapper = mountComponent()
      expect(wrapper.vm.membership).toBe(undefined)
    })
  })

  describe('Computed: subjectClass', () => {
    it('returns text-success for valid subjects', () => {
      const wrapper = mountComponent({}, { subject: 'OFFER: Test Item' })
      expect(wrapper.vm.subjectClass).toBe('text-success')
    })

    // Discourse 9481/594: with subject-colouring ON and the default subjreg
    // (/^(OFFER|WANTED):/i, which does NOT match "REQUESTED"), a Wanted post shown
    // with the custom/variant keyword "REQUESTED" must still be GREEN — it's a
    // recognised Wanted keyword. Previously it was wrongly red.
    it('keeps a REQUESTED (Wanted variant) subject green even when subjreg only knows OFFER/WANTED', async () => {
      const wrapper = mountComponent(
        {},
        { subject: 'REQUESTED: Green House', type: 'Wanted' }
      )
      wrapper.vm.modconfig = { coloursubj: true, subjreg: /^(OFFER|WANTED):/i }
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.subjectClass).toBe('text-success')
    })

    it('still flags a subject with no recognised keyword (red) when colouring is on', async () => {
      const wrapper = mountComponent(
        {},
        { subject: 'random junk with no keyword' }
      )
      wrapper.vm.modconfig = { coloursubj: true, subjreg: /^(OFFER|WANTED):/i }
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.subjectClass).toBe('text-danger')
    })
  })

  describe('Expand/collapse', () => {
    it('starts collapsed/expanded based on summary prop', () => {
      expect(mountComponent({ summary: true }).vm.expanded).toBe(false)
      expect(mountComponent({ summary: false }).vm.expanded).toBe(true)
    })

    it('shows card body and footer when expanded, hides footer with noactions', async () => {
      const wrapper = mountComponent({ summary: false, noactions: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.b-card-body').exists()).toBe(true)
      expect(wrapper.find('.b-card-footer').exists()).toBe(true)

      const wrapper2 = mountComponent({ summary: false, noactions: true })
      await wrapper2.vm.$nextTick()
      expect(wrapper2.find('.b-card-footer').exists()).toBe(false)
    })
  })

  describe('Editing', () => {
    it('startEdit sets editing true and miscStore.modtoolsediting', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.editing).toBe(false)
      wrapper.vm.startEdit()
      expect(wrapper.vm.editing).toBe(true)
      expect(mockMiscStore.modtoolsediting).toBe(true)
    })

    it('cancelEdit sets editing false and fetches message', () => {
      const wrapper = mountComponent()
      wrapper.vm.startEdit()
      wrapper.vm.cancelEdit()
      expect(wrapper.vm.editing).toBe(false)
      expect(mockMessageStore.fetch).toHaveBeenCalledWith(123)
    })
  })

  describe('Save', () => {
    it('calls messageStore.patch with item/location or subject, sets editing false', async () => {
      const wrapper = mountComponent(
        {},
        {
          item: { name: 'Test Item' },
          location: { name: 'SW1A 1AA' },
        }
      )
      wrapper.vm.startEdit()
      await wrapper.vm.save()

      expect(mockMessageStore.patch).toHaveBeenCalledWith({
        id: 123,
        groupid: 789,
        msgtype: 'Offer',
        item: 'Test Item',
        location: 'SW1A 1AA',
        attachments: [1],
        textbody: 'This is the message body',
      })
      expect(wrapper.vm.editing).toBe(false)
    })

    it('calls messageStore.patch with subject when no item', async () => {
      const wrapper = mountComponent(
        {},
        {
          item: null,
          location: null,
          subject: 'Custom Subject',
        }
      )
      wrapper.vm.startEdit()
      await wrapper.vm.save()

      expect(mockMessageStore.patch).toHaveBeenCalledWith({
        id: 123,
        msgtype: 'Offer',
        subject: 'Custom Subject',
        attachments: [1],
        textbody: 'This is the message body',
      })
    })
  })

  describe('backToPending', () => {
    it('calls messageStore.backToPending and callback', async () => {
      const wrapper = mountComponent()
      const callback = vi.fn()
      await wrapper.vm.backToPending(callback)
      // Passes the contextual groupid (789) so back-to-pending is per-group.
      expect(mockMessageStore.backToPending).toHaveBeenCalledWith(123, 789)
      expect(callback).toHaveBeenCalled()
    })
  })

  describe('toggleMail', () => {
    it('toggles showMailSettings and fetches user', async () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.showMailSettings).toBe(false)
      await wrapper.vm.toggleMail()
      expect(wrapper.vm.showMailSettings).toBe(true)
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456)
    })
  })

  describe('settingsChange', () => {
    it('calls memberStore.update with correct params', () => {
      const wrapper = mountComponent()
      wrapper.vm.settingsChange('emailfrequency', 7)
      expect(mockMemberStore.update).toHaveBeenCalledWith({
        userid: 456,
        groupid: 789,
        emailfrequency: 7,
      })
    })
  })

  describe('spamReport and photoAdd', () => {
    it('spamReport sets showSpamModal, photoAdd sets uploading', () => {
      const wrapper = mountComponent()
      wrapper.vm.spamReport()
      expect(wrapper.vm.showSpamModal).toBe(true)

      const wrapper2 = mountComponent()
      wrapper2.vm.photoAdd()
      expect(wrapper2.vm.uploading).toBe(true)
    })
  })

  describe('imageAdded and imageRemoved', () => {
    it.each([
      ['imageAdded', '[1, 2]', '[2]', 1, true],
      ['imageAdded', '[2]', '[1, 2]', 1, false],
      ['imageRemoved', '[2]', '[1, 2]', 1, true],
      ['imageRemoved', '[1, 2]', '[2]', 1, false],
    ])(
      '%s returns %s when newimages=%s, oldimages=%s, id=%s',
      (method, newimages, oldimages, id, expected) => {
        const wrapper = mountComponent(
          { editreview: true },
          {
            edits: [{ reviewrequired: true, newimages, oldimages }],
          }
        )
        expect(wrapper.vm[method](id)).toBe(expected)
      }
    )

    it('returns false when no editreview', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.imageAdded(1)).toBe(false)
      expect(wrapper.vm.imageRemoved(1)).toBe(false)
    })
  })

  describe('postcodeSelect', () => {
    it('updates editmessage location when editing', () => {
      const wrapper = mountComponent(
        {},
        {
          item: { name: 'Test Item' },
          location: { name: 'SW1A 1AA' },
        }
      )
      wrapper.vm.startEdit()
      const pc = { name: 'SW1A 2AA', lat: 51.6, lng: -0.2 }
      wrapper.vm.postcodeSelect(pc)
      expect(wrapper.vm.editmessage.location).toEqual(pc)
    })

    it('save sends updated postcode after postcodeSelect during edit', async () => {
      const wrapper = mountComponent(
        {},
        {
          item: { name: 'Test Item' },
          location: { name: 'LA23 2JH' },
        }
      )
      wrapper.vm.startEdit()
      wrapper.vm.postcodeSelect({ name: 'LA3 3QJ', lat: 54.1, lng: -2.9 })
      await wrapper.vm.save()

      expect(mockMessageStore.patch).toHaveBeenCalledWith(
        expect.objectContaining({
          location: 'LA3 3QJ',
        })
      )
    })
  })

  describe('updateComments', () => {
    it('re-fetches user from store', () => {
      const wrapper = mountComponent()
      wrapper.vm.updateComments()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(456)
    })
  })

  describe('Held message', () => {
    it('shows release button when held, warning when held by someone else', async () => {
      const wrapper1 = mountComponent(
        {
          summary: false,
        },
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              heldby: { id: 999, displayname: 'Test Mod' },
            },
          ],
        }
      )
      await wrapper1.vm.$nextTick()
      expect(wrapper1.find('.mod-message-button').exists()).toBe(true)

      const wrapper2 = mountComponent(
        {
          summary: false,
        },
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              heldby: { id: 888, displayname: 'Other Mod' },
            },
          ],
        }
      )
      await wrapper2.vm.$nextTick()
      expect(wrapper2.text()).toContain('Held by')
    })

    // Discourse 9970/2: a mod who moderates several nearby groups had a post that
    // rippled to more than one of them show as "held by another moderator" -
    // hiding every action button - even though the hold was only on a DIFFERENT
    // one of her groups, not the copy she was actually trying to act on. heldby is
    // per-group (messages_groups.heldby), so the copy on the group being
    // administered (contextGroupid) must only be treated as held when THAT
    // group's row carries a hold, not because some other group of hers happens to
    // be held.
    it('does not hide actions for a copy on an unheld group, even when another of the mod-owned groups on the same rippled post is held by someone else', async () => {
      const wrapper = mountComponent(
        {
          summary: false,
          contextGroupid: 789,
        },
        {
          // The server's message-level heldby mirrors "held on ANY group I moderate",
          // so it is truthy here purely because group 790 is held - exactly what a
          // real API response looks like for a multi-group mod. The fix must ignore
          // this and look at the per-group row for the context group (789) instead.
          heldby: 888,
          groups: [
            { groupid: 789, collection: 'Pending', heldby: null },
            { groupid: 790, collection: 'Pending', heldby: 888 },
          ],
        }
      )
      await wrapper.vm.$nextTick()

      expect(wrapper.find('.mod-message-buttons').exists()).toBe(true)
      expect(wrapper.text()).not.toContain('held by someone else')
    })

    // The API no longer sends a message-level heldby at all - a hold belongs to a
    // (message, group) pair, so there was never a correct message-wide value. These pin
    // the component to the per-group rows alone, so it cannot regress to reading a
    // message-wide field if one ever reappears in a payload or a cached response.
    it('shows the hold from the context group when the payload has no message-level heldby', async () => {
      const wrapper = mountComponent(
        { summary: false, contextGroupid: 789 },
        {
          groups: [
            { groupid: 789, collection: 'Pending', heldby: 888 },
            { groupid: 790, collection: 'Pending', heldby: null },
          ],
        }
      )
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('held by someone else')
      expect(wrapper.find('.mod-message-buttons').exists()).toBe(false)
    })

    it('leaves actions available when no group on the post is held', async () => {
      const wrapper = mountComponent(
        { summary: false, contextGroupid: 789 },
        {
          groups: [
            { groupid: 789, collection: 'Pending', heldby: null },
            { groupid: 790, collection: 'Pending', heldby: null },
          ],
        }
      )
      await wrapper.vm.$nextTick()

      expect(wrapper.find('.mod-message-buttons').exists()).toBe(true)
      expect(wrapper.text()).not.toContain('held by someone else')
    })
  })

  describe('Spammer indicator', () => {
    it('shows ModSpammer when user is a spammer', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Spam User',
        spammer: { collection: 'Spammer' },
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-spammer').exists()).toBe(true)
    })
  })

  describe('Message type notices', () => {
    it('shows notice for Other type messages', async () => {
      const wrapper = mountComponent({ summary: false }, { type: 'Other' })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain(
        'needs editing so that we know what kind of post'
      )
    })
  })

  describe('Outcomes', () => {
    it('shows outcome notice when outcomes exist', async () => {
      const wrapper = mountComponent(
        { summary: false },
        {
          outcomes: [{ outcome: 'TAKEN', timestamp: '2024-01-15' }],
        }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('TAKEN')
    })
  })

  describe('Deadline and delivery possible', () => {
    it('shows deadline and delivery possible when set', () => {
      const wrapper = mountComponent(
        {},
        {
          deadline: '2024-02-01',
          deliverypossible: true,
        }
      )
      expect(wrapper.text()).toContain('Deadline')
      expect(wrapper.text()).toContain('Delivery possible')
    })
  })

  describe('Active distance warning', () => {
    it('shows warning for large active distance', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Test User',
        activedistance: 100,
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('100 miles apart')
    })
  })

  describe('Outside UK warning', () => {
    it('shows warning for positions outside UK', async () => {
      const wrapper = mountComponent(
        { summary: false },
        {
          location: { lat: 40.0, lng: -1.0 },
        }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('outside the UK')
    })
  })

  describe('Spam reason', () => {
    it('shows spam reason when present', async () => {
      const wrapper = mountComponent(
        { summary: false },
        { spamreason: 'Suspicious content' }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Suspicious content')
    })
  })

  describe('Moderated member notice', () => {
    it('shows moderated notice for pending messages from MODERATED member', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Test User',
        memberships: [{ id: 789, groupid: 789, ourpostingstatus: 'MODERATED' }],
      })
      const wrapper = mountComponent({ summary: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Moderated')
      expect(wrapper.text()).toContain('posts need approval')
    })

    it('does not show moderated notice for DEFAULT member', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Test User',
        memberships: [{ id: 789, groupid: 789, ourpostingstatus: 'DEFAULT' }],
      })
      const wrapper = mountComponent({ summary: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).not.toContain('posts need approval')
    })

    it('does not show moderated notice for approved messages', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Test User',
        memberships: [{ id: 789, groupid: 789, ourpostingstatus: 'MODERATED' }],
      })
      const wrapper = mountComponent(
        { summary: false },
        { groups: [{ groupid: 789, collection: 'Approved' }] }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).not.toContain('posts need approval')
    })
  })

  describe('ModMessageWorry', () => {
    it('shows worry component when worry is set', async () => {
      const wrapper = mountComponent({ summary: false }, { worry: 'medium' })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-message-worry').exists()).toBe(true)
    })
  })

  describe('Availability badges', () => {
    it('shows availability badges with correct values', () => {
      const wrapper1 = mountComponent(
        {},
        {
          availableinitially: 5,
          availablenow: 5,
        }
      )
      expect(wrapper1.text()).toContain('5 available')

      const wrapper2 = mountComponent(
        {},
        {
          availableinitially: 5,
          availablenow: 3,
        }
      )
      expect(wrapper2.text()).toContain('5 available initially')
      expect(wrapper2.text()).toContain('3 now')
    })
  })

  describe('Email source button', () => {
    it('shows for Email source, hides for Platform', async () => {
      const wrapper1 = mountComponent({ summary: false }, { source: 'Email' })
      await wrapper1.vm.$nextTick()
      expect(wrapper1.text()).toContain('View Email Source')

      const wrapper2 = mountComponent(
        { summary: false },
        { source: 'Platform' }
      )
      await wrapper2.vm.$nextTick()
      expect(wrapper2.text()).not.toContain('View Email Source')
    })
  })

  describe('Back to pending button', () => {
    it('shows for Approved messages, hides for Pending', async () => {
      const wrapper1 = mountComponent(
        {
          summary: false,
        },
        {
          groups: [{ groupid: 789, collection: 'Approved' }],
        }
      )
      await wrapper1.vm.$nextTick()
      expect(wrapper1.text()).toContain('Back to Pending')

      const wrapper2 = mountComponent(
        {
          summary: false,
        },
        {
          groups: [{ groupid: 789, collection: 'Pending' }],
        }
      )
      await wrapper2.vm.$nextTick()
      const buttons = wrapper2.findAll('.spin-button')
      const backToPendingButton = buttons.filter((b) =>
        b.text().includes('Back to Pending')
      )
      expect(backToPendingButton.length).toBe(0)
    })
  })

  describe('ModMessageButtons', () => {
    it('shows when not editing, hides when editing', async () => {
      const wrapper = mountComponent({ summary: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-message-buttons').exists()).toBe(true)

      wrapper.vm.startEdit()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-message-buttons').exists()).toBe(false)
    })
  })

  describe('Review mode', () => {
    it.each([
      ['Pending', 'Pending'],
      ['Approved', 'Approved'],
    ])('shows %s alert in review mode', async (collection, expected) => {
      const wrapper = mountComponent(
        { review: true, summary: false },
        {
          groups: [{ groupid: 789, collection }],
        }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Post now in')
      expect(wrapper.text()).toContain(expected)
    })
  })

  describe('Edit review mode', () => {
    it('shows ModDiff for subject when editreview with changes', () => {
      const wrapper = mountComponent(
        { editreview: true },
        {
          edits: [
            {
              reviewrequired: true,
              oldsubject: 'Old Subject',
              newsubject: 'New Subject',
            },
          ],
        }
      )
      expect(wrapper.find('.mod-diff').exists()).toBe(true)
    })
  })

  describe('Location editing notice', () => {
    it('shows notice when editing and no location', async () => {
      const wrapper = mountComponent(
        { summary: false },
        {
          lat: null,
          lng: null,
          location: null,
        }
      )
      await wrapper.vm.$nextTick()
      wrapper.vm.startEdit()
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain(
        'needs editing so that we know where it is'
      )
    })
  })

  describe('User summary display', () => {
    it('shows user displayname in summary mode', () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Updated User',
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: true })
      expect(wrapper.text()).toContain('Updated User')
    })
  })

  describe('Deleted user indicator', () => {
    it('shows "Account deleted" badge in summary view when user is deleted', () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Deleted User',
        deleted: '2024-01-15',
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: true })
      expect(wrapper.text()).toContain('Account deleted')
    })

    it('shows "Account deleted" badge in expanded view header when user is deleted', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Deleted User',
        deleted: '2024-01-15',
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Account deleted')
    })

    it('shows recovery notice in expanded view when user is deleted', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Deleted User',
        deleted: '2024-01-15',
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: false })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('This user has deleted their account')
    })

    it('does not show deleted indicator for active user', async () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'Active User',
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: true })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).not.toContain('Account deleted')
      expect(wrapper.text()).not.toContain(
        'This user has deleted their account'
      )
    })
  })

  describe('Pending badge and approved-by display', () => {
    it('shows Pending badge for pending messages on editreview page', async () => {
      const message = createTestMessage({
        groups: [{ groupid: 789, namedisplay: 'Test', collection: 'Pending' }],
      })
      const wrapper = mountComponent({
        message,
        summary: false,
        editreview: true,
      })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Pending')
    })
  })

  describe('Microvolunteering and related messages', () => {
    it('shows both sections when data present', async () => {
      const wrapper = mountComponent(
        { summary: false },
        {
          microvolunteering: [{ id: 1, vote: 'approve' }],
          related: [{ id: 999, subject: 'Related Message' }],
        }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-message-microvolunteering').exists()).toBe(true)
      expect(wrapper.find('.mod-message-related').exists()).toBe(true)
    })
  })

  describe('Highlighter', () => {
    it('uses matchedon.word for highlighting when set', () => {
      const wrapper = mountComponent({}, { matchedon: { word: 'test' } })
      expect(wrapper.text()).toContain('OFFER: Test Item (Location)')
      expect(wrapper.vm.message.matchedon.word).toBe('test')
    })
  })

  describe('Blank message body', () => {
    it('shows blank message notice when body is empty', async () => {
      const wrapper = mountComponent({ summary: false }, { textbody: '' })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('This message is blank')
    })
  })

  describe('Report Spammer button', () => {
    it('shows for pending messages, hidden when held by someone else', async () => {
      const wrapper1 = mountComponent({ summary: false })
      await wrapper1.vm.$nextTick()
      expect(wrapper1.text()).toContain('Report Spammer')

      const wrapper2 = mountComponent(
        { summary: false },
        {
          groups: [
            {
              groupid: 789,
              collection: 'Pending',
              heldby: { id: 888, displayname: 'Other Mod' },
            },
          ],
        }
      )
      await wrapper2.vm.$nextTick()
      expect(wrapper2.text()).toContain('held by someone else')
    })
  })

  describe('Wrong group warning', () => {
    it('shows warning when message group is nearby but not the nearest group', async () => {
      // Message is on group 789. groupsnear[0] is a closer group (100).
      // Warning should show because the message is not on the nearest group.
      const wrapper = mountComponent(
        {},
        {
          location: {
            name: 'SW1A 1AA',
            lat: 51.5,
            lng: -0.1,
            groupsnear: [
              { id: 100, namedisplay: 'Closer Group', ontn: true },
              { id: 789, namedisplay: 'Test Group', ontn: true },
            ],
          },
        }
      )
      await flushPromises()
      expect(wrapper.text()).toContain('Possibly should be on Closer Group')
    })

    it('shows warning when message group is not in groupsnear at all', async () => {
      // Message is on group 789, but groupsnear returns completely different groups.
      const wrapper = mountComponent(
        {},
        {
          location: {
            name: 'SW1A 1AA',
            lat: 51.5,
            lng: -0.1,
            groupsnear: [
              { id: 100, namedisplay: 'Distant Group', ontn: true },
              { id: 200, namedisplay: 'Another Group', ontn: false },
            ],
          },
        }
      )
      await flushPromises()
      expect(wrapper.text()).toContain('Possibly should be on Distant Group')
    })

    it('shows warning via location API fallback when message.location has no groupsnear', async () => {
      // location has no groupsnear — triggers fallback to locationStore.fetch.
      // The fetched groupsnear does not include group 789, so warning should fire.
      mockLocationStore.fetch.mockResolvedValue({
        groupsnear: [{ id: 100, namedisplay: 'Other Group', ontn: true }],
      })
      const wrapper = mountComponent(
        {},
        {
          location: { name: 'SW1A 1AA', lat: 51.5, lng: -0.1 },
          lat: 51.5,
          lng: -0.1,
        }
      )
      await flushPromises()
      expect(wrapper.text()).toContain('Possibly should be on Other Group')
    })

    it('shows warning when message group is nearby but not the nearest group', async () => {
      // groupsnear[0] is a closer group; message is on group 789 (further away).
      // Warning should fire because the message is not on the nearest group.
      mockLocationStore.fetch.mockResolvedValue({
        groupsnear: [
          { id: 100, namedisplay: 'Closer Group', ontn: true },
          { id: 789, namedisplay: 'Test Group', ontn: true },
        ],
      })
      const wrapper = mountComponent(
        {},
        {
          location: { name: 'SW1A 1AA', lat: 51.5, lng: -0.1 },
          lat: 51.5,
          lng: -0.1,
        }
      )
      await flushPromises()
      expect(wrapper.text()).toContain('Possibly should be on Closer Group')
    })

    it('does not show warning when message group is the nearest group', async () => {
      // groupsnear[0] is group 789 — the message IS on the nearest group, so no warning.
      mockLocationStore.fetch.mockResolvedValue({
        groupsnear: [
          { id: 789, namedisplay: 'Test Group', ontn: true },
          { id: 100, namedisplay: 'Further Group', ontn: true },
        ],
      })
      const wrapper = mountComponent(
        {},
        {
          location: { name: 'SW1A 1AA', lat: 51.5, lng: -0.1 },
          lat: 51.5,
          lng: -0.1,
        }
      )
      await flushPromises()
      expect(wrapper.text()).not.toContain('Possibly should be on')
    })
  })

  describe('multi-group support', () => {
    it('uses contextGroupid prop when provided', () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            { groupid: 789, namedisplay: 'Group A', collection: 'Pending' },
            { groupid: 999, namedisplay: 'Group B', collection: 'Approved' },
          ],
        }
      )
      expect(wrapper.vm.groupid).toBe(789)
    })

    // Rippling-out: a post can ripple into a neighbouring group's pending queue
    // from elsewhere, so mods are warned not to reject it just for being "out of
    // area". The warning shows only on a rippled-in PENDING post.
    const rippleEarlier = '2026-06-18T10:00:00Z'
    const rippleLater = '2026-06-18T10:20:00Z' // 20 min later, beyond the 10-min origin window

    it('warns mods not to reject a rippled-in pending post for being out of area', () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            {
              groupid: 999,
              namedisplay: 'Origin',
              collection: 'Approved',
              arrival: rippleEarlier,
            },
            {
              groupid: 789,
              namedisplay: 'Context',
              collection: 'Pending',
              arrival: rippleLater,
            },
          ],
        }
      )
      expect(wrapper.vm.isRippledInToContextGroup).toBe(true)
      expect(wrapper.vm.pending).toBe(true)
      const warning = wrapper.find(
        '[data-test="ripple-out-of-area-reject-warning"]'
      )
      expect(warning.exists()).toBe(true)
      expect(warning.text()).toContain('out of area')
    })

    // Task #23: the P/Q "quicker to get to" note. Wording (fixed by product spec) is
    // "...in {P} than {P} is to {Q}" — P (nearest in-group point to the offer) appears twice,
    // Q (furthest in-group point from P) once. This test locks that exact structure so the
    // repeated {P} can't be "corrected" to {Q} and the note can't silently regress.
    it('renders the ripple proximity note with P and Q in the specified positions', () => {
      const p = 'DN14 8HH (Whitgift)'
      const q = 'HU12 0UH (Kilnsea)'
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            {
              groupid: 999,
              namedisplay: 'Origin',
              collection: 'Approved',
              arrival: rippleEarlier,
            },
            {
              groupid: 789,
              namedisplay: 'Context',
              collection: 'Pending',
              arrival: rippleLater,
              ripple_proximity_p: p,
              ripple_proximity_q: q,
            },
          ],
        }
      )
      const note = wrapper.find('[data-test="ripple-proximity-note"]')
      expect(note.exists()).toBe(true)
      const text = note.text().replace(/\s+/g, ' ').trim()
      expect(text).toBe(
        `This post is quicker to get to for Freeglers in ${p} than ${p} is to ${q}.`
      )
    })

    it('omits the ripple proximity note when only one endpoint is present', () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            {
              groupid: 999,
              namedisplay: 'Origin',
              collection: 'Approved',
              arrival: rippleEarlier,
            },
            {
              groupid: 789,
              namedisplay: 'Context',
              collection: 'Pending',
              arrival: rippleLater,
              ripple_proximity_p: 'DN14 8HH (Whitgift)',
              // ripple_proximity_q intentionally absent → note must not render
            },
          ],
        }
      )
      expect(wrapper.vm.isRippledInToContextGroup).toBe(true)
      expect(wrapper.find('[data-test="ripple-proximity-note"]').exists()).toBe(
        false
      )
    })

    it('does not warn when the post has not rippled in', () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            {
              groupid: 999,
              namedisplay: 'Origin',
              collection: 'Approved',
              arrival: rippleEarlier,
            },
            {
              groupid: 789,
              namedisplay: 'Context',
              collection: 'Pending',
              arrival: rippleEarlier,
            },
          ],
        }
      )
      expect(wrapper.vm.isRippledInToContextGroup).toBe(false)
      expect(
        wrapper.find('[data-test="ripple-out-of-area-reject-warning"]').exists()
      ).toBe(false)
    })

    it('does not warn once a rippled-in post is approved (not pending)', () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            {
              groupid: 999,
              namedisplay: 'Origin',
              collection: 'Approved',
              arrival: rippleEarlier,
            },
            {
              groupid: 789,
              namedisplay: 'Context',
              collection: 'Approved',
              arrival: rippleLater,
            },
          ],
        }
      )
      expect(wrapper.vm.isRippledInToContextGroup).toBe(true)
      expect(wrapper.vm.pending).toBe(false)
      expect(
        wrapper.find('[data-test="ripple-out-of-area-reject-warning"]').exists()
      ).toBe(false)
    })

    it('falls back to first group when no contextGroupid', () => {
      const wrapper = mountComponent(
        {},
        {
          groups: [
            { groupid: 789, namedisplay: 'Group A', collection: 'Pending' },
            { groupid: 999, namedisplay: 'Group B', collection: 'Approved' },
          ],
        }
      )
      expect(wrapper.vm.groupid).toBe(789)
    })

    // Discourse 9808/565: in the all-communities view a mod who is active on BOTH the
    // origin group and a group the post rippled into must anchor to the ORIGIN, so a
    // Blank reply appends to the member's existing chat rather than starting a new one
    // from the rippled-in group. The rippled-in copy was approved most recently, so its
    // arrival is newest - anchoring must use rippled_in, not most-recent arrival.
    it('anchors to the origin group, not a newer rippled-in copy, with no contextGroupid', () => {
      // The mod is active on BOTH the rippled-in group (789, in the default mock) and the
      // origin group (111) - add the latter to the moderated set for this test.
      mockMyModGroups.push({ id: 111 })
      try {
        const wrapper = mountComponent(
          {},
          {
            groups: [
              // Rippled-in copy: approved most recently, so its arrival is the NEWEST.
              {
                groupid: 789,
                namedisplay: 'Rippled-in',
                collection: 'Approved',
                arrival: rippleLater,
                rippled_in: 1,
              },
              // Origin: where the member's chat with volunteers actually lives.
              {
                groupid: 111,
                namedisplay: 'Origin',
                collection: 'Approved',
                arrival: rippleEarlier,
                rippled_in: 0,
              },
            ],
          }
        )
        expect(wrapper.vm.currentGroupid).toBe(111)
      } finally {
        mockMyModGroups.pop()
      }
    })

    // Discourse 9862/15: a mod found a standard message configured only for other
    // groups (Newham/Hackney) available on a rippled post, auto-signed as Tower
    // Hamlets (the group they were actually moderating it under). configid drives
    // which group's stdmsg config is offered/substituted, so it must anchor to
    // currentGroupid (the group being moderated) - not groups[0], which has no
    // guaranteed order and can be the rippled-in copy.
    it('resolves configid from the group being moderated (currentGroupid), not groups[0], for a rippled post with no contextGroupid', () => {
      mockMyModGroups.push({ id: 111 })
      mockAuthStore.groups.push({ groupid: 111, configid: 2 })
      try {
        const wrapper = mountComponent(
          {},
          {
            groups: [
              // Rippled-in copy (e.g. Newham/Hackney): approved most recently, so
              // its arrival is the NEWEST, and it happens to be groups[0].
              {
                groupid: 789,
                namedisplay: 'Rippled-in',
                collection: 'Approved',
                arrival: rippleLater,
                rippled_in: 1,
              },
              // Origin (e.g. Tower Hamlets): where the mod is actually administering
              // this post from - currentGroupid anchors here.
              {
                groupid: 111,
                namedisplay: 'Origin',
                collection: 'Approved',
                arrival: rippleEarlier,
                rippled_in: 0,
              },
            ],
          }
        )
        expect(wrapper.vm.currentGroupid).toBe(111)
        expect(wrapper.vm.configid).toBe(2)
      } finally {
        mockMyModGroups.pop()
        mockAuthStore.groups.pop()
      }
    })

    it('computes contextGroup from the correct group', () => {
      const wrapper = mountComponent(
        { contextGroupid: 999 },
        {
          groups: [
            { groupid: 789, namedisplay: 'Group A', collection: 'Pending' },
            { groupid: 999, namedisplay: 'Group B', collection: 'Approved' },
          ],
        }
      )
      expect(wrapper.vm.contextGroup.groupid).toBe(999)
      expect(wrapper.vm.contextGroup.collection).toBe('Approved')
    })

    it('computes otherGroups excluding the context group', () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            { groupid: 789, namedisplay: 'Group A', collection: 'Pending' },
            { groupid: 999, namedisplay: 'Group B', collection: 'Approved' },
          ],
        }
      )
      expect(wrapper.vm.otherGroups).toHaveLength(1)
      expect(wrapper.vm.otherGroups[0].groupid).toBe(999)
    })

    it('shows the multi-group ("may also be shown") indicator for multi-group messages', async () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            { groupid: 789, namedisplay: 'Group A', collection: 'Pending' },
            { groupid: 999, namedisplay: 'Group B', collection: 'Approved' },
          ],
        }
      )
      await flushPromises()
      expect(wrapper.text()).toContain('May also be shown to some members in')
    })

    it('does not show the multi-group indicator for single-group messages', async () => {
      const wrapper = mountComponent(
        { contextGroupid: 789 },
        {
          groups: [
            { groupid: 789, namedisplay: 'Group A', collection: 'Pending' },
          ],
        }
      )
      await flushPromises()
      expect(wrapper.text()).not.toContain(
        'May also be shown to some members in'
      )
    })
  })

  describe('Summary view responsive layout (Discourse 9481)', () => {
    it('username in summary header has text-truncate and max-width to prevent squeezing edit fields', () => {
      mockUserStore.byId.mockReturnValue({
        id: 456,
        displayname: 'AVeryLongUsernameThatWouldSqueezeEditFields',
        memberships: [{ id: 789, groupid: 789 }],
      })
      const wrapper = mountComponent({ summary: true })
      const usernameEl = wrapper.find('.text-truncate.d-inline-block')
      expect(usernameEl.exists()).toBe(true)
      expect(usernameEl.text()).toContain(
        'AVeryLongUsernameThatWouldSqueezeEditFields'
      )
      expect(usernameEl.attributes('style')).toContain('max-width: 8rem')
    })

    it('Back to Pending button uses slot for label so text can be hidden on xs', async () => {
      const wrapper = mountComponent(
        { summary: false, contextGroupid: 789 },
        {
          groups: [{ groupid: 789, collection: 'Approved' }],
        }
      )
      await wrapper.vm.$nextTick()
      const spinButton = wrapper.find('.spin-button')
      expect(spinButton.exists()).toBe(true)
      const labelSpan = spinButton.find('span.d-none.d-sm-inline')
      expect(labelSpan.exists()).toBe(true)
      expect(labelSpan.text()).toBe('Back to Pending')
    })
  })

  describe('auto-approve countdown badge (A5)', () => {
    const pendingGroups = (autoapproveat) => [
      { groupid: 789, namedisplay: 'Test Group', collection: 'Pending', autoapproveat },
    ]

    it('shows no badge when there is no autoapproveat', () => {
      const wrapper = mountComponent({}, { groups: pendingGroups(null) })
      expect(
        wrapper.find('[data-testid="autoapprove-countdown"]').exists()
      ).toBe(false)
    })

    it('shows a prominent m/s countdown when <=30m away', () => {
      const soon = new Date(Date.now() + 15 * 60 * 1000).toISOString()
      const wrapper = mountComponent({}, { groups: pendingGroups(soon) })
      const badge = wrapper.find('[data-testid="autoapprove-countdown"]')
      expect(badge.exists()).toBe(true)
      expect(badge.text()).toMatch(/Auto-approves in \d+m \d{2}s/)
    })

    it('shows a muted ~Nh badge when >1h away', () => {
      const later = new Date(Date.now() + 3 * 60 * 60 * 1000).toISOString()
      const wrapper = mountComponent({}, { groups: pendingGroups(later) })
      const badge = wrapper.find('[data-testid="autoapprove-countdown"]')
      expect(badge.exists()).toBe(true)
      expect(badge.text()).toMatch(/Auto-approves in ~3h/)
    })

    it('shows Auto-approving… when the time has passed', () => {
      const past = new Date(Date.now() - 5000).toISOString()
      const wrapper = mountComponent({}, { groups: pendingGroups(past) })
      expect(
        wrapper.find('[data-testid="autoapprove-countdown"]').text()
      ).toBe('Auto-approving…')
    })

    it('picks the soonest autoapproveat across multiple Pending groups', () => {
      const sooner = new Date(Date.now() + 10 * 60 * 1000).toISOString()
      const later = new Date(Date.now() + 40 * 60 * 1000).toISOString()
      const wrapper = mountComponent(
        {},
        {
          groups: [
            { groupid: 789, collection: 'Pending', autoapproveat: later },
            { groupid: 790, collection: 'Pending', autoapproveat: sooner },
          ],
        }
      )
      const badge = wrapper.find('[data-testid="autoapprove-countdown"]')
      expect(badge.exists()).toBe(true)
      // 10 min is <=30m, so prominent m/s formatting (the sooner one wins).
      expect(badge.text()).toMatch(/Auto-approves in \d+m \d{2}s/)
    })
  })
})
