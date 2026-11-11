import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import TeamsPage from '~/modtools/pages/teams.vue'

const mockTeamStore = {
  all: [],
  list: {},
  fetch: vi.fn().mockResolvedValue({
    id: 7,
    name: 'Board',
    description: 'The board',
    active: true,
    members: [],
  }),
  add: vi.fn().mockResolvedValue({}),
}

vi.mock('@/stores/team', () => ({
  useTeamStore: () => mockTeamStore,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ email: 'mod@example.com' }),
    supportOrAdmin: ref(true),
  }),
}))

describe('teams.vue addMember', () => {
  let wrapper
  let consoleError

  function mountPage() {
    return mount(TeamsPage, {
      global: {
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          'b-button': {
            template:
              '<button class="btn" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          'b-input-group': {
            template: '<div class="input-group"><slot /></div>',
          },
          'b-form-input': {
            template:
              '<input class="form-input" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue'],
          },
          NoticeMessage: {
            template: '<div class="notice-stub"><slot /></div>',
          },
          ExternalLink: {
            template: '<a class="external-link" :href="href"><slot /></a>',
            props: ['href'],
          },
          ModTeamMember: { template: '<div class="member-stub" />' },
          SpinButton: {
            template: '<button class="spin-button"><slot /></button>',
            props: ['variant', 'iconName', 'label', 'spinclass', 'disabled'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    wrapper = mountPage()
  })

  afterEach(() => {
    consoleError.mockRestore()
  })

  // Script-setup exposes addMember on vm, same as the other page specs
  // call their handlers directly (admins.spec.js vm.create()).

  it('adds the member, refetches the team and stops the SpinButton', async () => {
    const finish = vi.fn()
    wrapper.vm.memberToAdd = 42
    wrapper.vm.selected = 7

    await wrapper.vm.addMember(finish, 'Board')

    expect(mockTeamStore.add).toHaveBeenCalledWith({ id: 7, userid: 42 })
    expect(mockTeamStore.fetch).toHaveBeenCalledWith('Board')
    expect(finish).toHaveBeenCalledTimes(1)
  })

  it('stops the SpinButton when the server refuses the add', async () => {
    mockTeamStore.add.mockRejectedValueOnce({
      response: { status: 404, data: { error: 404, message: 'No such user' } },
    })
    const finish = vi.fn()
    wrapper.vm.memberToAdd = 99
    wrapper.vm.selected = 7

    await wrapper.vm.addMember(finish, 'Board')

    // Without the catch this rejects, the button spins for 20 seconds and
    // SpinButton reports a forgotten callback to Sentry.
    expect(finish).toHaveBeenCalledTimes(1)
    expect(consoleError).toHaveBeenCalled()
  })

  it('stops the SpinButton when the guard declines to add anything', async () => {
    const finish = vi.fn()
    wrapper.vm.memberToAdd = null
    wrapper.vm.selected = 7

    await wrapper.vm.addMember(finish, 'Board')

    expect(mockTeamStore.add).not.toHaveBeenCalled()
    expect(finish).toHaveBeenCalledTimes(1)
  })
})
