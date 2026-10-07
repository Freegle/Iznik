import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, reactive } from 'vue'
import {
  createMockAdminsStore,
  createMockUserStore,
  createMockGroupStore,
} from '../../mocks/stores'
import ModAdmin from '~/modtools/components/ModAdmin.vue'

// Create mock store instances
const mockAdminsStore = createMockAdminsStore({
  test: vi.fn().mockResolvedValue('test-token'),
})
const mockUserStore = createMockUserStore()
const mockGroupStore = createMockGroupStore()
const mockCheckWork = vi.fn()

// Mock the store imports
vi.mock('~/stores/admins', () => ({
  useAdminsStore: () => mockAdminsStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

// Mock the composables
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    myid: 999,
    me: ref({ email: 'mod@example.com' }),
  }),
}))

vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    checkWork: mockCheckWork,
  }),
}))

// Mock timeago
vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    timeago: vi.fn().mockReturnValue('1 day ago'),
  }
})

describe('ModAdmin', () => {
  const defaultProps = {
    id: 1,
  }

  const defaultAdmin = {
    id: 1,
    subject: 'Test Admin',
    text: 'Test body',
    created: '2024-01-01',
    groupid: 1,
    pending: true,
  }

  function mountComponent(props = {}, adminOverrides = {}) {
    // Store objects are reactive, so edits to them re-render.
    mockAdminsStore.get.mockReturnValue(
      reactive({ ...defaultAdmin, ...adminOverrides })
    )

    return mount(ModAdmin, {
      props: { ...defaultProps, ...props },
      global: {
        stubs: {
          'b-card': {
            template:
              '<div class="card"><slot /><slot name="header" /><slot name="body" /><slot name="footer" /></div>',
          },
          'b-card-header': {
            template:
              '<div class="card-header" @click="$emit(\'click\', $event)"><slot /></div>',
          },
          'b-card-body': {
            template: '<div class="card-body"><slot /></div>',
          },
          'b-card-footer': {
            template: '<div class="card-footer"><slot /></div>',
          },
          'b-row': { template: '<div class="row"><slot /></div>' },
          'b-col': { template: '<div class="col"><slot /></div>' },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
          },
          'b-form-group': {
            template: '<div class="form-group"><slot /></div>',
          },
          'b-form-input': {
            template:
              '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          'b-form-textarea': {
            template:
              '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          NoticeMessage: { template: '<div class="notice"><slot /></div>' },
          'b-badge': { template: '<span class="badge"><slot /></span>' },
          OurToggle: {
            template:
              '<label class="our-toggle"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />{{ modelValue ? labels.checked : labels.unchecked }}</label>',
            props: ['modelValue', 'labels'],
          },
          'b-tabs': { template: '<div class="tabs"><slot /></div>' },
          'b-tab': {
            template: '<div class="tab"><h6>{{ title }}</h6><slot /></div>',
            props: ['title', 'active'],
          },
          'b-form-checkbox': {
            template:
              '<label class="mjml-toggle"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
            props: ['modelValue'],
          },
          ConfirmModal: {
            template: '<div class="confirm-modal"><slot /></div>',
          },
          ExternalLink: { template: '<a><slot /></a>' },
          ModAdminPreviewLittleFreeShop2026: {
            template: '<div class="preview-stub" />',
          },
          'v-icon': true,
        },
        mocks: {
          timeago: vi.fn().mockReturnValue('1 day ago'),
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockAdminsStore.get.mockReturnValue({ ...defaultAdmin })
  })

  describe('rendering', () => {
    it('renders the card', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.card').exists()).toBe(true)
    })

    it('displays admin subject', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Test Admin')
    })

    it('displays admin ID', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('1')
    })

    it('displays group name', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Test Group')
    })
  })

  describe('expand/collapse', () => {
    it('starts collapsed by default', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.expanded).toBe(false)
    })

    it('starts expanded when open prop is true', () => {
      const wrapper = mountComponent({ open: true })
      expect(wrapper.vm.expanded).toBe(true)
    })

    it('toggles expanded state', async () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.expanded).toBe(false)

      // Directly toggle the expanded state as the component does
      wrapper.vm.expanded = !wrapper.vm.expanded
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.expanded).toBe(true)

      wrapper.vm.expanded = !wrapper.vm.expanded
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.expanded).toBe(false)
    })
  })

  describe('computed properties', () => {
    it('gets admin from store using id prop', () => {
      mountComponent({ id: 123 })
      expect(mockAdminsStore.get).toHaveBeenCalledWith(123)
    })

    it('gets group name from group store', () => {
      mountComponent()
      expect(mockGroupStore.get).toHaveBeenCalledWith(1)
    })

    it('returns holder when admin has heldby', () => {
      const mockHolder = { displayname: 'Test User' }
      mockUserStore.byId.mockReturnValue(mockHolder)

      const wrapper = mountComponent({}, { heldby: 456 })
      expect(wrapper.vm.holder).toEqual(mockHolder)
    })

    it('returns null for holder when no heldby', () => {
      const wrapper = mountComponent({}, { heldby: null })
      expect(wrapper.vm.holder).toBeNull()
    })
  })

  describe('actions', () => {
    it('shows confirm modal on deleteIt', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.showConfirmModal).toBe(false)

      wrapper.vm.deleteIt()
      expect(wrapper.vm.showConfirmModal).toBe(true)
    })

    it('emits copy event with admin on copyIt', () => {
      const wrapper = mountComponent()
      wrapper.vm.copyIt()

      expect(wrapper.emitted('copy')).toBeTruthy()
      expect(wrapper.emitted('copy')[0][0]).toEqual(defaultAdmin)
    })

    it('calls adminsStore.delete on deleteConfirmed', () => {
      const wrapper = mountComponent({ id: 42 })
      wrapper.vm.deleteConfirmed()

      expect(mockAdminsStore.delete).toHaveBeenCalledWith({ id: 42 })
      expect(mockCheckWork).toHaveBeenCalledWith(true)
    })

    it('calls adminsStore.edit on save', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.save()

      expect(mockAdminsStore.edit).toHaveBeenCalledWith({
        id: 1,
        essential: false,
        subject: 'Test Admin',
        text: 'Test body',
        mjml: '',
        ctatext: '',
        ctalink: '',
        sendafter: null,
        pending: true,
      })
    })

    it('sets saving state during save', async () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.saving).toBe(false)

      const savePromise = wrapper.vm.save()
      // saving should be true during the operation
      expect(wrapper.vm.saving).toBe(true)

      await savePromise
      expect(wrapper.vm.saving).toBe(false)
    })

    it('calls adminsStore.hold on hold', () => {
      const wrapper = mountComponent()
      wrapper.vm.hold()

      expect(mockAdminsStore.hold).toHaveBeenCalledWith({ id: 1 })
      expect(mockCheckWork).toHaveBeenCalledWith(true)
    })

    it('calls adminsStore.release on release', () => {
      const wrapper = mountComponent()
      wrapper.vm.release()

      expect(mockAdminsStore.release).toHaveBeenCalledWith({ id: 1 })
      expect(mockCheckWork).toHaveBeenCalledWith(true)
    })

    it('calls save then approve on approve, with no test for a text-only ADMIN', async () => {
      const wrapper = mountComponent()
      await wrapper.vm.approve()

      expect(mockAdminsStore.edit).toHaveBeenCalled()
      expect(mockAdminsStore.approve).toHaveBeenCalledWith({
        id: 1,
        testtoken: null,
      })
      expect(mockCheckWork).toHaveBeenCalledWith(true)
    })
  })

  describe('mounted lifecycle', () => {
    it('fetches holder when admin has heldby', () => {
      mountComponent({}, { heldby: 789 })
      expect(mockUserStore.fetch).toHaveBeenCalledWith(789)
    })

    it('does not fetch holder when admin has no heldby', () => {
      mountComponent({}, { heldby: null })
      expect(mockUserStore.fetch).not.toHaveBeenCalled()
    })
  })

  describe('guidance for local moderators', () => {
    const guidance = 'Add your own names at the end and drop paragraph two.'

    it('shows guidance clearly marked as not sent to members', async () => {
      const wrapper = mountComponent({ open: true }, { modguidance: guidance })
      await wrapper.vm.$nextTick()
      const box = wrapper.find('.modguidance')
      expect(box.exists()).toBe(true)
      expect(box.text()).toContain(guidance)
      expect(box.text()).toContain('NOT')
      expect(box.text()).toContain('sent to members')
    })

    it('shows no guidance block when there is none', () => {
      const wrapper = mountComponent({ open: true }, { modguidance: null })
      expect(wrapper.find('.modguidance').exists()).toBe(false)
    })

    it('keeps guidance out of the body textarea', async () => {
      const wrapper = mountComponent({ open: true }, { modguidance: guidance })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('textarea').element.value).toBe('Test body')
    })

    it('saving does not fold guidance into the text or send it', async () => {
      const wrapper = mountComponent({}, { modguidance: guidance })
      await wrapper.vm.save()

      const params = mockAdminsStore.edit.mock.calls[0][0]
      expect(params.text).toBe('Test body')
      expect(JSON.stringify(params)).not.toContain(guidance)
    })
  })

  describe('copy of a suggested ADMIN', () => {
    it('shows the suggested-ADMIN notice and label when the admin has a parent', async () => {
      const wrapper = mountComponent({ open: true }, { parentid: 7 })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('This is a copy of a suggested ADMIN')
      expect(wrapper.text()).toContain('Suggested ADMIN')
    })

    it('shows the guidance instead of the standard notice when there is some', async () => {
      const wrapper = mountComponent(
        { open: true },
        { parentid: 7, modguidance: 'Add your local recycling centre' }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).not.toContain(
        'This is a copy of a suggested ADMIN'
      )
      expect(wrapper.text()).toContain('Add your local recycling centre')
      expect(wrapper.text()).toContain('This is advice on how you might adapt')
    })

    it('shows no suggested-ADMIN notice without a parent, and names the creator', async () => {
      const wrapper = mountComponent(
        { open: true },
        { parentid: null, createdby: { id: 5, displayname: 'Pat Mod' } }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).not.toContain('copy of a suggested ADMIN')
      expect(wrapper.text()).toContain('Pat Mod')
    })
  })

  describe('send after', () => {
    it('shows the stored time and saves an edited one as ISO', async () => {
      const wrapper = mountComponent(
        { open: true },
        { sendafter: '2030-05-06T07:08:00Z' }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.sendafter).toMatch(/^2030-05-0[56]T\d\d:\d\d$/)

      wrapper.vm.sendafter = '2031-02-03T04:05'
      await wrapper.vm.save()

      const params = mockAdminsStore.edit.mock.calls[0][0]
      expect(params.sendafter).toBe(new Date('2031-02-03T04:05').toISOString())
    })

    it('clears the send-after time when the field is emptied', async () => {
      const wrapper = mountComponent(
        { open: true },
        { sendafter: '2030-05-06T07:08:00Z' }
      )
      wrapper.vm.sendafter = ''
      await wrapper.vm.save()

      expect(mockAdminsStore.edit.mock.calls[0][0].sendafter).toBeNull()
    })
  })
  describe('MJML part', () => {
    const mjml =
      '<mj-section><mj-column><mj-text>Designed</mj-text></mj-column></mj-section>'

    it('shows no MJML box for a text-only ADMIN, and does not send one', async () => {
      const wrapper = mountComponent({ open: true })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('textarea#body').exists()).toBe(true)
      expect(wrapper.find('textarea#mjml').exists()).toBe(false)
      await wrapper.vm.save()
      expect(mockAdminsStore.edit.mock.calls[0][0].mjml).toBe('')
    })

    it('shows the MJML with a warning, and saves edits to it', async () => {
      const wrapper = mountComponent({ open: true }, { mjml })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.both-versions').text()).toContain(
        'any change must be made in both'
      )
      expect(wrapper.text()).toContain('Plain text version')
      expect(wrapper.text()).toContain('Designed (MJML) version')
      expect(wrapper.find('.mjml-help').html()).toContain(
        'https://mjml.io/try-it-live'
      )
      expect(wrapper.find('textarea#mjml').element.value).toBe(mjml)

      // Changing only the MJML asks first.
      await wrapper
        .find('textarea#mjml')
        .setValue(mjml.replace('Designed', 'Edited'))
      expect(await wrapper.vm.save()).toBe(false)
      expect(mockAdminsStore.edit).not.toHaveBeenCalled()
      expect(wrapper.find('.one-sided').text()).toContain(
        'changed the designed (MJML) version but not the other'
      )

      wrapper.vm.saveAnyway()
      await wrapper.vm.$nextTick()
      await new Promise((resolve) => setTimeout(resolve, 0))
      expect(mockAdminsStore.edit.mock.calls[0][0].mjml).toContain('Edited')
    })

    it('refuses HTML in the text, and does not approve', async () => {
      const wrapper = mountComponent({ open: true }, { text: '<b>Bold</b>' })
      await wrapper.vm.approve()
      expect(mockAdminsStore.edit).not.toHaveBeenCalled()
      expect(mockAdminsStore.approve).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('must be plain text')
    })

    it('refuses unusable MJML', async () => {
      const wrapper = mountComponent(
        { open: true },
        { mjml: '<p>Just HTML</p>' }
      )
      expect(await wrapper.vm.save()).toBe(false)
      expect(mockAdminsStore.edit).not.toHaveBeenCalled()
    })

    it('shows why the server refused, and does not approve', async () => {
      mockAdminsStore.edit.mockRejectedValueOnce({
        response: { data: { error: 400, message: 'Server says no' } },
      })
      const wrapper = mountComponent({ open: true }, { mjml })
      await wrapper.vm.approve()
      expect(mockAdminsStore.approve).not.toHaveBeenCalled()
      expect(wrapper.vm.saveError).toBe('Server says no')
      expect(wrapper.vm.saving).toBe(false)
    })
  })
  describe('call to action', () => {
    it('saves edits to the button text and link', async () => {
      const wrapper = mountComponent(
        { open: true },
        { ctatext: 'Donate', ctalink: 'https://example.com/a' }
      )
      wrapper.vm.admin.ctalink = 'https://example.com/b'
      await wrapper.vm.save()
      const params = mockAdminsStore.edit.mock.calls[0][0]
      expect(params.ctatext).toBe('Donate')
      expect(params.ctalink).toBe('https://example.com/b')
    })

    it('hides the button fields when there is an MJML version', async () => {
      const wrapper = mountComponent(
        { open: true },
        { mjml: '<mj-section></mj-section>', ctatext: 'Donate' }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.find('#ctatext').exists()).toBe(false)
      expect(wrapper.find('#ctalink').exists()).toBe(false)
      expect(await wrapper.vm.save()).toBe(true)
    })

    it('refuses a button with text but no link', async () => {
      const wrapper = mountComponent({ open: true }, { ctatext: 'Donate' })
      expect(await wrapper.vm.save()).toBe(false)
      expect(mockAdminsStore.edit).not.toHaveBeenCalled()
    })
  })
  describe('pending ADMIN review', () => {
    const mjml =
      '<mj-section><mj-column><mj-text>Designed</mj-text></mj-column></mj-section>'

    it('marks an ADMIN with an MJML version in its header', () => {
      expect(mountComponent({}, { mjml }).text()).toContain('Designed (MJML)')
      expect(mountComponent().text()).not.toContain('Designed (MJML)')
    })

    it('can add an MJML version to a text-only ADMIN, which hides the button fields', async () => {
      const wrapper = mountComponent({ open: true })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('#ctatext').exists()).toBe(true)

      await wrapper.find('.mjml-toggle input').setValue(true)
      expect(wrapper.find('#ctatext').exists()).toBe(false)
      await wrapper.find('textarea#mjml').setValue(mjml)
      await wrapper.vm.save()
      expect(mockAdminsStore.edit.mock.calls[0][0].mjml).toBe(mjml)
    })

    it('asks for the MJML when the box is ticked but empty', async () => {
      const wrapper = mountComponent({ open: true })
      await wrapper.vm.$nextTick()
      await wrapper.find('.mjml-toggle input').setValue(true)
      expect(await wrapper.vm.save()).toBe(false)
      expect(wrapper.vm.saveError).toContain('add the MJML')
    })

    it('saving or approving without touching the MJML keeps it', async () => {
      const wrapper = mountComponent(
        { open: true },
        { mjml, parentid: 7, unedited: true }
      )
      await wrapper.vm.$nextTick()
      await wrapper.vm.save()
      expect(mockAdminsStore.edit.mock.calls[0][0].mjml).toBe(mjml)

      await wrapper.vm.approve()
      expect(mockAdminsStore.edit.mock.calls[1][0].mjml).toBe(mjml)
      expect(mockAdminsStore.approve).toHaveBeenCalled()
    })

    it('can remove the MJML version', async () => {
      const wrapper = mountComponent({ open: true }, { mjml })
      await wrapper.vm.$nextTick()
      await wrapper.find('.remove-mjml').trigger('click')
      expect(wrapper.find('textarea#mjml').exists()).toBe(false)
      await wrapper.vm.save()
      expect(mockAdminsStore.edit.mock.calls[0][0].mjml).toBe('')
    })

    it('needs a test before approving, and says so', async () => {
      const wrapper = mountComponent({ open: true }, { mjml })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.test-first').text()).toContain(
        'need to send a test of it before you can approve'
      )

      await wrapper.vm.approve()
      expect(mockAdminsStore.approve).not.toHaveBeenCalled()

      await wrapper.vm.sendTest()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.test-first').exists()).toBe(false)
      expect(mockAdminsStore.test).toHaveBeenCalledWith(
        expect.objectContaining({
          groupid: 1,
          mjml,
          email: 'mod@example.com',
        })
      )
      await wrapper.vm.approve()
      expect(mockAdminsStore.approve).toHaveBeenCalledWith({
        id: 1,
        testtoken: 'test-token',
      })
    })

    it('shows no test for a text-only ADMIN', async () => {
      const wrapper = mountComponent({ open: true })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.test-send').exists()).toBe(false)
      expect(wrapper.find('.test-first').exists()).toBe(false)
    })

    it('needs a new test after an edit', async () => {
      const wrapper = mountComponent({ open: true }, { mjml })
      await wrapper.vm.$nextTick()
      await wrapper.vm.sendTest()
      wrapper.vm.admin.text = 'Edited after the test'
      wrapper.vm.admin.mjml = mjml.replace('Designed', 'Edited')
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain(
        "You've changed the ADMIN since the test"
      )
      await wrapper.vm.approve()
      expect(mockAdminsStore.approve).not.toHaveBeenCalled()
    })

    it('lets an unedited suggested ADMIN be approved without a test', async () => {
      const wrapper = mountComponent(
        { open: true },
        { parentid: 7, unedited: true, mjml }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.test-first').exists()).toBe(false)
      expect(wrapper.text()).toContain('a test is optional')
      await wrapper.vm.approve()
      expect(mockAdminsStore.approve).toHaveBeenCalledWith({
        id: 1,
        testtoken: null,
      })
    })

    it('needs a test once a suggested ADMIN is edited', async () => {
      const wrapper = mountComponent(
        { open: true },
        { parentid: 7, unedited: true, mjml }
      )
      await wrapper.vm.$nextTick()
      wrapper.vm.admin.text = 'Our own sign-off'
      wrapper.vm.admin.mjml = mjml.replace('Designed', 'Our own sign-off')
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.test-first').exists()).toBe(true)
      await wrapper.vm.approve()
      expect(mockAdminsStore.approve).not.toHaveBeenCalled()
    })

    it('says approving sends to all members', async () => {
      const essential = mountComponent({ open: true }, { essential: 1 })
      await essential.vm.$nextTick()
      expect(essential.text()).toContain('Approve and send to all members')

      const newsletter = mountComponent({ open: true }, { essential: 0 })
      await newsletter.vm.$nextTick()
      expect(newsletter.text()).toContain(
        "send to all members who haven't opted out"
      )
    })
  })
  describe('essential or newsletter', () => {
    it('can switch a pending ADMIN between Essential and Newsletter', async () => {
      const wrapper = mountComponent({ open: true }, { essential: 1 })
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.type-toggle').text()).toContain('Essential')
      expect(wrapper.text()).toContain('Approve and send to all members')

      await wrapper.find('.type-toggle input').setValue(false)
      expect(wrapper.text()).toContain(
        'Newsletter - will be sent to all members'
      )
      expect(wrapper.text()).toContain("who haven't opted out")

      await wrapper.vm.save()
      expect(mockAdminsStore.edit.mock.calls[0][0].essential).toBe(false)
    })

    it('does not offer the switch for a pre-designed template', async () => {
      const wrapper = mountComponent(
        { open: true },
        { template: 'fundraising' }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.type-toggle').exists()).toBe(false)
    })
  })
  describe('who it goes to', () => {
    it('a copy of a central ADMIN goes only to recently active members', async () => {
      const wrapper = mountComponent(
        { open: true },
        { essential: 1, parentid: 7, activeonly: 1 }
      )
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain(
        'Essential - will be sent to members active in the last six months'
      )
      expect(wrapper.text()).toContain(
        'Approve and send to recently active members'
      )
    })

    it("a community's own ADMIN goes to all members", async () => {
      const wrapper = mountComponent({ open: true }, { essential: 1 })
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain(
        'Essential - will be sent to all members'
      )
    })
  })
})
