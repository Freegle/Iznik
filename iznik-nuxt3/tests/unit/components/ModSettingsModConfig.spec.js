import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

import ModSettingsModConfig from '~/modtools/components/ModSettingsModConfig.vue'

const config = {
  id: 42,
  name: 'South West Mods',
  createdby: null,
  protected: 0,
  using: [],
  stdmsgs: [],
  messageorder: null,
}

const mockModConfigStore = {
  configs: [{ id: 42, name: 'South West Mods' }],
  current: config,
  fetch: vi.fn().mockResolvedValue({}),
  fetchConfig: vi.fn().mockResolvedValue({}),
  add: vi.fn().mockResolvedValue(43),
  delete: vi.fn().mockResolvedValue({}),
}

const mockMiscStore = {
  get: vi.fn().mockReturnValue(42),
  set: vi.fn().mockResolvedValue({}),
}

const mockUserStore = {
  byId: vi.fn(),
  fetch: vi.fn().mockResolvedValue({}),
  fetchMultiple: vi.fn().mockResolvedValue({}),
}

const mockModGroupStore = {
  list: {},
  fetchIfNeedBeMT: vi.fn().mockResolvedValue({}),
}

const mockExport = vi.hoisted(() => vi.fn().mockResolvedValue({}))

vi.mock('~/stores/modconfig', () => ({
  useModConfigStore: () => mockModConfigStore,
}))

vi.mock('~/stores/modgroup', () => ({
  useModGroupStore: () => mockModGroupStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('@/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ id: 7, displayname: 'Jane Moderator' }),
    myid: ref(7),
  }),
}))

vi.mock('~/composables/useModConfigPdf', () => ({
  exportModConfigPdf: mockExport,
}))

function mountConfig() {
  return mount(ModSettingsModConfig, {
    global: {
      stubs: {
        SpinButton: {
          // The real one hands the handler a callback to stop the spinner.
          template:
            '<button class="spin-button" :data-label="label" @click="$emit(\'handle\', done)"><slot />{{ label }}</button>',
          props: ['variant', 'iconName', 'label', 'disabled', 'title'],
          emits: ['handle'],
          setup() {
            return { done: () => {} }
          },
        },
        ModConfigSetting: { template: '<div class="mod-config-setting" />' },
        ModSettingsStandardMessageSet: {
          template: '<div class="stdmsg-set" />',
        },
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        Spinner: { template: '<div class="spinner" />' },
        ConfirmModal: { template: '<div class="confirm-modal" />' },
        ExternalLink: { template: '<a><slot /></a>' },
        'v-icon': { template: '<span />' },
        'b-form-select': {
          template: '<select class="config-select" />',
          props: ['modelValue', 'options'],
          emits: ['update:modelValue'],
        },
        'b-form-input': {
          template: '<input />',
          props: ['modelValue', 'placeholder'],
          emits: ['update:modelValue'],
        },
        'b-input-group': {
          template: '<div><slot /><slot name="append" /></div>',
        },
        'b-button': {
          template:
            '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
          emits: ['click'],
        },
        'b-card': { template: '<div><slot /></div>' },
        'b-card-header': { template: '<div><slot /></div>' },
        'b-card-body': { template: '<div><slot /></div>' },
        'b-collapse': { template: '<div><slot /></div>' },
      },
      directives: {
        'b-toggle': {},
      },
    },
  })
}

describe('ModSettingsModConfig export button', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockExport.mockResolvedValue({})
    mockMiscStore.get.mockReturnValue(42)
    mockModConfigStore.current = config
  })

  function exportButton(wrapper) {
    return wrapper
      .findAll('.spin-button')
      .find((b) => b.attributes('data-label') === 'Export PDF')
  }

  it('offers an export button once a config is chosen', async () => {
    const wrapper = mountConfig()
    await flushPromises()

    expect(exportButton(wrapper)).toBeTruthy()
  })

  it('exports the config that is being looked at, and says who by', async () => {
    const wrapper = mountConfig()
    await flushPromises()

    await exportButton(wrapper).trigger('click')
    await flushPromises()

    expect(mockExport).toHaveBeenCalledWith(config, {
      exportedBy: 'Jane Moderator',
    })
  })

  it('tells the mod when the export fails rather than spinning forever', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    mockExport.mockRejectedValue(new Error('no can do'))

    const wrapper = mountConfig()
    await flushPromises()

    await exportButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain("couldn't make the PDF")
    consoleError.mockRestore()
  })

  it('offers the export even when the config is locked against changes', async () => {
    // Locked configs can be used, viewed and copied, so they can be read on
    // paper too - it is only editing that is barred.
    mockModConfigStore.current = { ...config, protected: 1, createdby: 999 }

    const wrapper = mountConfig()
    await flushPromises()

    expect(exportButton(wrapper)).toBeTruthy()
  })
})
