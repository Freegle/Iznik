import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import AdminsPage from '~/modtools/pages/admins.vue'

// Create mock stores
const mockAdminsStore = {
  list: [],
  clear: vi.fn().mockResolvedValue({}),
  fetch: vi.fn().mockResolvedValue({}),
  add: vi.fn().mockResolvedValue({}),
  test: vi.fn().mockResolvedValue('test-token'),
}

const mockModGroupStore = {
  get: vi.fn().mockReturnValue({
    id: 1,
    type: 'Freegle',
    role: 'Moderator',
    work: { pendingadmins: 3 },
  }),
}

// Mock vue-router
vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      params: {},
      query: {},
      path: '/admins',
      name: 'admins',
      fullPath: '/admins',
      matched: [],
      redirectedFrom: undefined,
      meta: {},
    }),
  }
})

globalThis.__testUseRoute = () => ({
  params: {},
  query: {},
  path: '/admins',
  name: 'admins',
  fullPath: '/admins',
  matched: [],
  redirectedFrom: undefined,
  meta: {},
})

// Mock stores
vi.mock('~/stores/admins', () => ({
  useAdminsStore: () => mockAdminsStore,
}))

vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => mockModGroupStore,
}))

// Mock composables
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ email: 'mod@example.com' }),
    myGroups: ref([
      { id: 1, role: 'Moderator' },
      { id: 2, role: 'Moderator' },
    ]),
    supportOrAdmin: ref(false),
  }),
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    checkWork: vi.fn(),
  }),
}))

describe('admins.vue page', () => {
  function mountComponent(options = {}) {
    return mount(AdminsPage, {
      global: {
        stubs: {
          ModHelpAdmins: { template: '<div class="help-stub" />' },
          ModGroupSelect: {
            template: '<div class="group-select-stub" />',
            props: ['modelValue', 'all', 'modonly', 'work', 'systemwide'],
          },
          ModAdmin: {
            template: '<div class="admin-stub" />',
            props: ['id', 'open'],
            emits: ['copy'],
          },
          NoticeMessage: {
            template: '<div class="notice-stub"><slot /></div>',
          },
          OurToggle: {
            template: '<div class="toggle-stub" />',
            props: [
              'modelValue',
              'height',
              'width',
              'fontSize',
              'sync',
              'labels',
              'variant',
            ],
          },
          VeeForm: {
            template: '<form class="vee-form-stub" ref="form"><slot /></form>',
            methods: {
              validate: () => Promise.resolve({ valid: true }),
            },
          },
          Field: {
            template:
              '<input class="field-stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: [
              'modelValue',
              'name',
              'type',
              'rules',
              'as',
              'placeholder',
              'id',
              'rows',
              'maxRows',
              'spellcheck',
            ],
          },
          ErrorMessage: { template: '<span class="error-stub" />' },
          'b-tabs': {
            template: '<div class="tabs"><slot /></div>',
            props: ['modelValue'],
            emits: ['update:modelValue'],
          },
          'b-tab': {
            template: '<div class="tab"><slot /><slot name="title" /></div>',
            props: ['active'],
          },
          'b-badge': { template: '<span class="badge"><slot /></span>' },
          'b-button': {
            template:
              '<button class="btn" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size', 'disabled'],
          },
          'b-form-group': {
            template: '<div class="form-group"><slot /></div>',
          },
          'b-form-input': {
            template:
              '<input class="form-input" :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'id'],
          },
          'b-form-textarea': {
            template:
              '<textarea class="form-textarea" :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'id'],
          },
          'b-form-select': {
            template:
              '<select class="form-select" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
            props: ['modelValue'],
          },
          'v-icon': { template: '<span class="icon-stub" />' },
          ExternalLink: {
            template: '<a class="external-link" :href="href"><slot /></a>',
            props: ['href'],
          },
          'b-form-checkbox': {
            template:
              '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
            props: ['modelValue'],
          },
        },
        ...options.global,
      },
      ...options,
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockAdminsStore.list = []
  })

  describe('initial state', () => {
    it('fetches admins on mount', async () => {
      vi.clearAllMocks()
      mountComponent()
      // Wait for async operations
      await flushPromises()
      expect(mockAdminsStore.clear).toHaveBeenCalled()
      expect(mockAdminsStore.fetch).toHaveBeenCalledWith({ groupid: null })
    })
  })

  describe('computed properties', () => {
    it('calculates pendingcount from groups with pending admins', () => {
      vi.clearAllMocks()
      const wrapper = mountComponent()
      // 2 groups, each with 3 pending admins from mock = 6
      expect(wrapper.vm.pendingcount).toBe(6)
    })

    it('filters pending admins from list', () => {
      mockAdminsStore.list = [
        { id: 1, pending: true, created: '2024-01-01' },
        { id: 2, pending: false, created: '2024-01-02' },
        { id: 3, pending: true, created: '2024-01-03' },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.pending).toHaveLength(2)
      expect(wrapper.vm.pending[0].id).toBe(3) // Sorted by date desc
    })

    it('filters previous (non-pending) admins from list', () => {
      mockAdminsStore.list = [
        { id: 1, pending: true, created: '2024-01-01' },
        { id: 2, pending: false, created: '2024-01-02' },
        { id: 3, pending: false, created: '2024-01-03' },
      ]
      const wrapper = mountComponent()
      expect(wrapper.vm.previous).toHaveLength(2)
      expect(wrapper.vm.previous[0].id).toBe(3) // Sorted by date desc
    })
  })

  describe('methods', () => {
    it('validateSubject returns error for empty value', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.validateSubject('')).toBe('Please enter a subject.')
      expect(wrapper.vm.validateSubject(null)).toBe('Please enter a subject.')
    })

    it('validateSubject returns error if includes ADMIN', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.validateSubject('ADMIN notice')).toBe(
        'Do not include ADMIN in your subject.'
      )
      expect(wrapper.vm.validateSubject('admin notice')).toBe(
        'Do not include ADMIN in your subject.'
      )
    })

    it('validateSubject returns true for valid subject', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.validateSubject('Notice about group')).toBe(true)
    })

    it('validateBody returns error for empty value', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.validateBody('')).toBe('Please add the message.')
      expect(wrapper.vm.validateBody(null)).toBe('Please add the message.')
    })

    it('validateBody refuses HTML in the plain-text part', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.validateBody('Hello <b>there</b>')).toContain(
        'must be plain text'
      )
      expect(wrapper.vm.validateBody('Line<br>break')).toContain(
        'must be plain text'
      )
      expect(wrapper.vm.validateBody('Please add <your names here>')).toBe(true)
    })

    it('validateBody returns true for valid body', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.validateBody('Message body content')).toBe(true)
    })

    it('copyAdmin copies admin data to form fields', () => {
      const wrapper = mountComponent()
      const admin = {
        essential: 1,
        groupid: 5,
        subject: 'Test Subject',
        text: 'Test Body',
        ctatext: 'Click me',
        ctalink: 'https://example.com',
      }

      wrapper.vm.copyAdmin(admin)

      expect(wrapper.vm.essential).toBe(true)
      expect(wrapper.vm.groupidcreate).toBe(5)
      expect(wrapper.vm.subject).toBe('Test Subject')
      expect(wrapper.vm.body).toBe('Test Body')
      expect(wrapper.vm.ctatext).toBe('Click me')
      expect(wrapper.vm.ctalink).toBe('https://example.com')
      expect(wrapper.vm.tabIndex).toBe(1)
    })

    it('copyAdmin handles essential=0', () => {
      const wrapper = mountComponent()
      const admin = {
        essential: 0,
        groupid: 5,
        subject: 'Test',
        text: 'Body',
      }

      wrapper.vm.copyAdmin(admin)

      expect(wrapper.vm.essential).toBe(false)
    })

    it('fetchAdmins clears and fetches admins', async () => {
      const wrapper = mountComponent()
      vi.clearAllMocks()

      await wrapper.vm.fetchAdmins(123)

      expect(mockAdminsStore.clear).toHaveBeenCalled()
      expect(mockAdminsStore.fetch).toHaveBeenCalledWith({ groupid: 123 })
    })

    it('fetchPending calls fetch with groupidshow', async () => {
      const wrapper = mountComponent()
      wrapper.vm.groupidshow = 456
      vi.clearAllMocks()

      await wrapper.vm.fetchPending()

      expect(mockAdminsStore.fetch).toHaveBeenCalledWith({ groupid: 456 })
    })

    it('fetchPrevious calls fetch with groupidprevious', async () => {
      const wrapper = mountComponent()
      wrapper.vm.groupidprevious = 789
      vi.clearAllMocks()

      await wrapper.vm.fetchPrevious()

      expect(mockAdminsStore.fetch).toHaveBeenCalledWith({ groupid: 789 })
    })
  })

  describe('watchers', () => {
    it('fetches when groupidshow changes', async () => {
      const wrapper = mountComponent()
      vi.clearAllMocks()

      wrapper.vm.groupidshow = 100
      await wrapper.vm.$nextTick()

      expect(mockAdminsStore.fetch).toHaveBeenCalledWith({ groupid: 100 })
    })

    it('fetches when groupidprevious changes', async () => {
      const wrapper = mountComponent()
      vi.clearAllMocks()

      wrapper.vm.groupidprevious = 200
      await wrapper.vm.$nextTick()

      expect(mockAdminsStore.fetch).toHaveBeenCalledWith({ groupid: 200 })
    })
  })

  describe('tab rendering', () => {
    it('renders tabs', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.tabs').exists()).toBe(true)
    })

    it('renders group select in Pending tab', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.group-select-stub').exists()).toBe(true)
    })
  })

  describe('guidance for local moderators', () => {
    it('shows the separate guidance field for a system-wide ADMIN only', async () => {
      const wrapper = mountComponent()
      expect(wrapper.find('#modguidance').exists()).toBe(false)

      wrapper.vm.groupidcreate = 5
      await wrapper.vm.$nextTick()
      expect(wrapper.find('#modguidance').exists()).toBe(false)

      wrapper.vm.groupidcreate = -2
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.modguidance').exists()).toBe(true)
      expect(wrapper.find('textarea#modguidance').exists()).toBe(true)
      expect(
        wrapper.find('.modguidance .form-group').attributes('label')
      ).toContain('NOT sent to members')
    })

    it('sends guidance as its own field and keeps it out of the body', async () => {
      const wrapper = mountComponent()
      wrapper.vm.groupidcreate = -2
      wrapper.vm.subject = 'Subject'
      wrapper.vm.body = 'The message to members'
      wrapper.vm.modguidance = 'Tell your mods to add local details'
      await wrapper.vm.sendTest()
      await wrapper.vm.create()

      expect(mockAdminsStore.add).toHaveBeenCalledTimes(1)
      const params = mockAdminsStore.add.mock.calls[0][0]
      expect(params.modguidance).toBe('Tell your mods to add local details')
      expect(params.text).toBe('The message to members')
      expect(params.subject).toBe('Subject')
      expect(params.text).not.toContain('local details')
    })

    it('does not send guidance for a single-group ADMIN', async () => {
      const wrapper = mountComponent()
      wrapper.vm.groupidcreate = 5
      wrapper.vm.subject = 'Subject'
      wrapper.vm.body = 'Body'
      wrapper.vm.modguidance = 'Should be dropped'
      await wrapper.vm.sendTest()
      await wrapper.vm.create()

      const params = mockAdminsStore.add.mock.calls[0][0]
      expect(params).not.toHaveProperty('modguidance')
    })
  })

  describe('send after', () => {
    it('sends the chosen time as ISO, and nothing when left empty', async () => {
      const wrapper = mountComponent()
      wrapper.vm.groupidcreate = 5
      wrapper.vm.subject = 'Subject'
      wrapper.vm.body = 'Body'
      await wrapper.vm.sendTest()
      await wrapper.vm.create()
      expect(mockAdminsStore.add.mock.calls[0][0]).not.toHaveProperty(
        'sendafter'
      )

      wrapper.vm.sendafter = '2031-02-03T04:05'
      await wrapper.vm.sendTest()
      await wrapper.vm.create()
      expect(mockAdminsStore.add.mock.calls[1][0].sendafter).toBe(
        new Date('2031-02-03T04:05').toISOString()
      )
    })
  })
  describe('MJML part and test send', () => {
    const mjml =
      '<mj-section><mj-column><mj-text>Designed</mj-text></mj-column></mj-section>'

    function filled() {
      const wrapper = mountComponent()
      wrapper.vm.groupidcreate = 5
      wrapper.vm.subject = 'Subject'
      wrapper.vm.body = 'Plain body'
      return wrapper
    }

    it('defaults the test address to my own', () => {
      expect(mountComponent().vm.testEmail).toBe('mod@example.com')
    })

    it('links to the MJML website and warns before showing the MJML box', async () => {
      const wrapper = filled()
      expect(wrapper.find('textarea#mjml').exists()).toBe(false)

      wrapper.vm.useMjml = true
      await wrapper.vm.$nextTick()
      expect(wrapper.find('textarea#mjml').exists()).toBe(true)
      expect(wrapper.find('.mjml-part').text()).toContain(
        'Only use this if you know what you are doing'
      )
      expect(wrapper.find('.mjml-part').html()).toContain('https://mjml.io')
    })

    it('will not create without a test', async () => {
      const wrapper = filled()
      expect(wrapper.vm.tested).toBe(false)
      await wrapper.vm.create()
      expect(mockAdminsStore.add).not.toHaveBeenCalled()
      expect(wrapper.vm.createError).toContain('test')
    })

    it('sends a test of the content to one address, then creates with its token', async () => {
      const wrapper = filled()
      wrapper.vm.useMjml = true
      wrapper.vm.mjml = mjml
      wrapper.vm.testEmail = ' other@example.com '
      await wrapper.vm.sendTest()

      expect(mockAdminsStore.test).toHaveBeenCalledWith(
        expect.objectContaining({
          groupid: 5,
          subject: 'Subject',
          text: 'Plain body',
          mjml,
          email: 'other@example.com',
        })
      )
      expect(wrapper.vm.tested).toBe(true)
      expect(wrapper.vm.testedTo).toBe('other@example.com')

      await wrapper.vm.create()
      const params = mockAdminsStore.add.mock.calls[0][0]
      expect(params.testtoken).toBe('test-token')
      expect(params.mjml).toBe(mjml)
      expect(params.text).toBe('Plain body')

      // One test, one ADMIN.
      expect(wrapper.vm.tested).toBe(false)
    })

    it('needs a new test after any change', async () => {
      const wrapper = filled()
      await wrapper.vm.sendTest()
      expect(wrapper.vm.tested).toBe(true)

      wrapper.vm.body = 'Edited after the test'
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.tested).toBe(false)
      expect(wrapper.text()).toContain(
        "You've changed the ADMIN since the test"
      )

      await wrapper.vm.create()
      expect(mockAdminsStore.add).not.toHaveBeenCalled()
    })

    it('does not send the MJML box once it is switched off', async () => {
      const wrapper = filled()
      wrapper.vm.useMjml = true
      wrapper.vm.mjml = mjml
      wrapper.vm.useMjml = false
      await wrapper.vm.sendTest()
      expect(mockAdminsStore.test.mock.calls[0][0].mjml).toBe('')
    })

    it('refuses a test to anything but one address', async () => {
      const wrapper = filled()
      for (const email of ['', 'nope', 'a@example.com, b@example.com']) {
        wrapper.vm.testEmail = email
        await wrapper.vm.sendTest()
        expect(wrapper.vm.testError).toContain('one email address')
      }
      expect(mockAdminsStore.test).not.toHaveBeenCalled()
    })

    it('refuses unusable MJML before sending a test', async () => {
      const wrapper = filled()
      wrapper.vm.useMjml = true
      wrapper.vm.mjml = '<mjml><mj-body>' + mjml + '</mj-body></mjml>'
      await wrapper.vm.sendTest()
      expect(wrapper.vm.testError).toContain('inside <mj-body>')
      expect(mockAdminsStore.test).not.toHaveBeenCalled()
    })

    it('shows why the server refused a test', async () => {
      mockAdminsStore.test.mockRejectedValueOnce({
        response: { data: { error: 400, message: 'Server says no' } },
      })
      const wrapper = filled()
      await wrapper.vm.sendTest()
      expect(wrapper.vm.testError).toBe('Server says no')
      expect(wrapper.vm.tested).toBe(false)
    })

    it('shows why the server refused to create', async () => {
      mockAdminsStore.add.mockRejectedValueOnce({
        response: { data: { error: 400, message: 'Send a test first' } },
      })
      const wrapper = filled()
      await wrapper.vm.sendTest()
      await wrapper.vm.create()
      expect(wrapper.vm.createError).toBe('Send a test first')
      expect(wrapper.vm.creating).toBe(false)
      expect(wrapper.vm.created).toBe(false)
    })

    it('needs both parts of a big button', async () => {
      const wrapper = filled()
      wrapper.vm.ctatext = 'Click'
      await wrapper.vm.sendTest()
      expect(wrapper.vm.testError).toContain('both its text and its link')
    })

    it('drops the big button when there is an MJML version', async () => {
      const wrapper = filled()
      wrapper.vm.ctatext = 'Click'
      wrapper.vm.ctalink = 'https://example.com'
      await wrapper.vm.$nextTick()
      expect(wrapper.find('#ctatext').exists()).toBe(true)

      wrapper.vm.useMjml = true
      wrapper.vm.mjml = mjml
      await wrapper.vm.$nextTick()
      expect(wrapper.find('#ctatext').exists()).toBe(false)
      expect(wrapper.find('#ctalink').exists()).toBe(false)

      await wrapper.vm.sendTest()
      const params = mockAdminsStore.test.mock.calls[0][0]
      expect(params.ctatext).toBeNull()
      expect(params.ctalink).toBeNull()
    })

    it('does not need both parts of a button when using MJML', async () => {
      const wrapper = filled()
      wrapper.vm.ctatext = 'Click'
      wrapper.vm.useMjml = true
      wrapper.vm.mjml = mjml
      await wrapper.vm.sendTest()
      expect(wrapper.vm.testError).toBeNull()
    })

    it('copyAdmin brings the MJML part with it', () => {
      const wrapper = mountComponent()
      wrapper.vm.copyAdmin({
        essential: 1,
        groupid: 5,
        subject: 'S',
        text: 'T',
        mjml,
      })
      expect(wrapper.vm.mjml).toBe(mjml)
      expect(wrapper.vm.useMjml).toBe(true)
    })
  })
})
