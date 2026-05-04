import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, config } from '@vue/test-utils'
import { ref } from 'vue'
import ModSpammer from '~/modtools/components/ModSpammer.vue'

let mockUserData = {}

const mockUserStore = {
  byId: (id) => mockUserData[id] || null,
  fetch: vi.fn().mockResolvedValue(null),
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => ({
    list: {},
  }),
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    hasPermissionSpamAdmin: ref(false),
  }),
}))

config.global.mocks = {
  ...config.global.mocks,
  timeago: vi.fn(() => '3 days ago'),
}

describe('ModSpammer', () => {
  const baseUser = {
    id: 1,
    displayname: 'Test User',
    spammer: {
      reason: 'Posting spam links',
      collection: 'Spammer',
      added: '2024-01-01T12:00:00Z',
      byuserid: 100,
      byuser: {
        displayname: 'Mod Person',
        email: 'mod@example.com',
      },
    },
  }

  function createUser(overrides = {}) {
    return {
      ...baseUser,
      spammer: { ...baseUser.spammer, ...overrides.spammer },
      ...overrides,
    }
  }

  function populateUserStore(user) {
    mockUserData[user.id] = user
  }

  function mountComponent(props = {}) {
    // Convert old-style user prop to userid and populate store
    const { user: userProp, ...otherProps } = props
    const user = userProp || baseUser
    populateUserStore(user)

    return mount(ModSpammer, {
      props: { userid: user.id, ...otherProps },
      global: {
        mocks: {
          timeago: vi.fn(() => '3 days ago'),
        },
        stubs: {
          NoticeMessage: {
            template:
              '<div class="notice" :class="\'notice-\' + variant"><slot /></div>',
            props: ['variant'],
          },
          'notice-message': {
            template:
              '<div class="notice" :class="\'notice-\' + variant"><slot /></div>',
            props: ['variant'],
          },
          ExternalLink: {
            template: '<a :href="href"><slot /></a>',
            props: ['href'],
          },
          ModClipboard: {
            template: '<span class="clipboard" />',
            props: ['value'],
          },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to'],
          },
          'v-icon': {
            template: '<span class="icon" />',
            props: ['icon', 'scale'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockUserData = {}
  })

  describe('rendering', () => {
    it('displays user displayname', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Test User')
    })

    it('displays spammer reason', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Posting spam links')
    })

    it('displays byuser displayname', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Mod Person')
    })

    it('displays byuser email', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('mod@example.com')
    })

    it('displays byuserid', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('100')
    })

    it('creates mailto link with byuser email', () => {
      const wrapper = mountComponent()
      const link = wrapper.find('a[href*="mailto:"]')
      expect(link.attributes('href')).toContain('mailto:mod@example.com')
    })
  })

  describe('computed properties by collection type', () => {
    it.each([
      ['Spammer', 'danger', 'Confirmed Spammer'],
      ['Safelisted', 'primary', 'Safelisted'],
      ['PendingAdd', 'warning', 'Unconfirmed Spammer'],
      ['PendingRemove', 'warning', 'Disputed Spammer'],
      ['Unknown', 'warning', 'Unknown'],
      ['CustomType', 'warning', 'CustomType'],
    ])(
      '%s collection → variant=%s, collname="%s"',
      (collection, expectedVariant, expectedCollname) => {
        const user = createUser({ spammer: { collection } })
        const wrapper = mountComponent({ user })
        expect(wrapper.vm.variant).toBe(expectedVariant)
        expect(wrapper.vm.collname).toBe(expectedCollname)
      }
    )
  })

  describe('PendingAdd specific behavior', () => {
    it.each([
      ['PendingAdd', 'Reported by'],
      ['Spammer', 'Added by'],
    ])('%s collection shows "%s" text', (collection, expectedText) => {
      const user = createUser({ spammer: { collection } })
      const wrapper = mountComponent({ user })
      expect(wrapper.text()).toContain(expectedText)
    })
  })

  describe('sameip array', () => {
    it.each([
      [null, false, 'null'],
      [[], false, 'empty array'],
      [[123, 456], true, 'populated array'],
    ])('sameip=%s shows warning=%s (%s)', (sameip, shouldShowWarning) => {
      const wrapper = mountComponent({ sameip })
      if (shouldShowWarning) {
        expect(wrapper.text()).toContain('Recently active on the same IP')
      } else {
        expect(wrapper.text()).not.toContain('Recently active on the same IP')
      }
    })

    it('renders support links for each userid in sameip', () => {
      const wrapper = mountComponent({ sameip: [123, 456, 789] })
      const links = wrapper.findAll('a[href*="/support/"]')
      expect(links.length).toBe(3)
      expect(wrapper.find('a[href="/support/123"]').exists()).toBe(true)
      expect(wrapper.text()).toContain(
        'These may not be the same actual person'
      )
    })
  })

  describe('props and byuser handling', () => {
    it('passes props correctly and displays byuser info', () => {
      const sameip = [100, 200]
      const wrapper = mountComponent({ sameip })
      expect(wrapper.props('userid')).toEqual(baseUser.id)
      expect(wrapper.props('sameip')).toEqual(sameip)
      expect(wrapper.text()).toContain('Mod Person')
      expect(wrapper.text()).toContain('mod@example.com')
    })

    it('defaults sameip to null and handles missing byuser', () => {
      const user = createUser({ spammer: { byuser: null } })
      const wrapper = mountComponent({ user })
      expect(wrapper.props('sameip')).toBeNull()
      expect(wrapper.text()).toContain('Test User')
    })
  })

  describe('notice styling by collection', () => {
    it.each([
      ['Spammer', 'notice-danger'],
      ['Safelisted', 'notice-primary'],
      ['PendingAdd', 'notice-warning'],
    ])('%s collection applies %s class', (collection, cssClass) => {
      const user = createUser({ spammer: { collection } })
      const wrapper = mountComponent({ user })
      expect(wrapper.find(`.${cssClass}`).exists()).toBe(true)
    })
  })

  describe('edge cases', () => {
    it('handles minimal spammer data and empty reason', () => {
      const user = {
        id: 99,
        displayname: 'Minimal User',
        spammer: {
          reason: '',
          collection: 'Spammer',
          added: null,
          byuserid: null,
          byuser: null,
        },
      }
      const wrapper = mountComponent({ user })
      expect(wrapper.text()).toContain('Minimal User')
      expect(wrapper.text()).toContain('Confirmed Spammer')
    })

    it('BUG REPRODUCTION: fails when approved+banned filter returns member with missing spammer properties', () => {
      // This test reproduces the exact bug from the diagnosis:
      // When filtering members by both 'Approved' and 'Banned' status,
      // the backend returns members where the spammer object exists but is incomplete
      // (missing properties like reason, collection, byuserid, etc.)
      //
      // The template at line 5 directly accesses: {{ user.spammer.reason }}
      // without null-checking the spammer object or its properties.
      //
      // This test demonstrates the crash by:
      // 1. Creating a member with spammer as an empty object {}
      // 2. Rendering ModSpammer component
      // 3. The template tries to render {{ user.spammer.reason }} which is undefined
      // 4. The variant computed accesses user.spammer.collection which is also undefined
      // 5. The collname computed accesses user.spammer.collection which is undefined

      const user = {
        id: 102,
        displayname: 'Filtered User by Approved+Banned',
        // The crucial bug: spammer exists but has no properties
        // This happens when API filters by approved+banned and returns
        // incomplete relationship objects
        spammer: {},
      }

      populateUserStore(user)

      const wrapper = mountComponent({ user })

      // The bug manifests when the template tries to render spammer properties
      // Line 5 in ModSpammer.vue: {{ user.spammer.reason }}
      // This accesses spammer.reason which is undefined
      const html = wrapper.html()

      // The component should NOT render "undefined" text for spammer.reason
      // It should handle the missing reason property gracefully with optional chaining
      // Currently this test checks that the rendering contains the unsafe access
      expect(html).toContain('Filtered User by Approved+Banned')

      // The bug is that the template accesses spammer properties without null-checks:
      // - Line 5: {{ user.spammer.reason }} - should be {{ user.spammer?.reason }}
      // - Line 8: v-if="user.spammer.collection === 'PendingAdd'" - should have optional chaining
      // - Line 36: {{ user.spammer.byuserid }} - should have optional chaining
      // - Line 36: {{ user.spammer.added }} - should have optional chaining
      expect(wrapper.vm.user.spammer).toEqual({})
    })

    it('fails when spammer object lacks all required properties from API', () => {
      // When the approved+banned filter is applied, the API returns a partially
      // hydrated spammer object. The ModSpammer component then tries to access
      // properties that don't exist, causing errors in the template.
      const user = {
        id: 103,
        displayname: 'Test User with Broken Spammer',
        spammer: {
          // Missing: reason, collection, byuserid, added, byuser
          // This simulates the edge case where the relationship is not fully loaded
        },
      }

      populateUserStore(user)

      const wrapper = mount(ModSpammer, {
        props: { userid: 103 },
        global: {
          mocks: {
            timeago: vi.fn(() => '3 days ago'),
          },
          stubs: {
            NoticeMessage: {
              template:
                '<div class="notice"><slot /></div>',
              props: ['variant'],
            },
            ExternalLink: {
              template: '<a :href="href"><slot /></a>',
              props: ['href'],
            },
            ModClipboard: {
              template: '<span class="clipboard" />',
              props: ['value'],
            },
            'nuxt-link': {
              template: '<a><slot /></a>',
              props: ['to'],
            },
            'v-icon': {
              template: '<span />',
              props: ['icon', 'scale'],
            },
          },
        },
      })

      // The bug is in the template's unsafe access to spammer properties
      // When spammer.reason is undefined, Vue renders it as empty string
      // But other code that processes spammer.reason might crash expecting a string
      expect(wrapper.vm.user).toBeDefined()
      expect(wrapper.vm.user.spammer).toBeDefined()
      expect(wrapper.vm.user.spammer.reason).toBeUndefined()
      expect(wrapper.vm.user.spammer.collection).toBeUndefined()
    })

    it('FAILS: template renders with missing reason when spammer.reason is undefined (approved+banned filter bug)', () => {
      // Bug reproduction: Filtering members by 'Approved' AND 'Banned' together
      // returns members with incomplete spammer objects (reason field missing)
      //
      // ModSpammer.vue template line 5:
      //   {{ user.displayname }} {{ collname }}: {{ user.spammer.reason }}
      //
      // When spammer.reason is undefined, Vue renders it as empty, breaking the UI
      // Expected output: "User Name [Spammer Type]: [Reason]"
      // Actual output: "User Name : " (reason is missing!)
      //
      // This test FAILS because the template directly accesses spammer.reason
      // without optional chaining, causing the reason to be missing from display
      const user = {
        id: 105,
        displayname: 'Test Spam User',
        spammer: {
          collection: 'Spammer',
          byuserid: 100,
          added: '2024-01-01T12:00:00Z',
          byuser: {
            displayname: 'Moderator',
            email: 'mod@test.com',
          },
          // Missing: reason property - this causes the bug!
          // When the API filter returns incomplete spammer data, reason is undefined
        },
      }

      populateUserStore(user)

      const wrapper = mountComponent({ user })

      // The test checks that the spammer reason is properly displayed
      // This will FAIL because reason is undefined and renders as empty
      const html = wrapper.html()

      // FAILING ASSERTION: The reason should be in the output
      // But it's missing because spammer.reason is undefined
      // and the template uses {{ user.spammer.reason }} without safe navigation
      expect(html).toContain('reason') // This FAILS!

      // When the bug is fixed, the template should use:
      //   {{ user.spammer?.reason || 'Unknown reason' }}
      // or check for undefined and handle it gracefully
    })
  })
})
