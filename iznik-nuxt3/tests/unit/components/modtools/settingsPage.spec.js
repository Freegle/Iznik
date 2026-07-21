import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SettingsPage from '~/modtools/pages/settings/[[id]].vue'

const revealSetting = vi.fn()

vi.mock('~/composables/useSettingsSearch', () => ({
  revealSetting: (...args) => revealSetting(...args),
  useSettingsSearch: () => ({ results: { value: [] } }),
}))

let routeParams = {}
vi.mock('#imports', () => ({
  useRoute: () => ({ params: routeParams }),
}))

// Stands in for ModSettingsGroup: exposes the openSection the page writes to,
// and lets a test fire the event the real one emits when a group loads.
const groupStub = {
  template: '<div class="group-settings" />',
  props: ['initialGroup'],
  emits: ['settings-shown'],
  data: () => ({ openSection: null }),
}

const searchStub = {
  template: '<div class="search" />',
  emits: ['select'],
}

const tabsStub = {
  template: '<div class="tabs"><slot /></div>',
  props: ['modelValue', 'contentClass', 'card'],
}

const stubs = {
  ModSettingsSearch: searchStub,
  ModSettingsGroup: groupStub,
  ModSettingsPersonal: { template: '<div />' },
  ModSettingsModConfig: { template: '<div />' },
  NoticeMessage: {
    template: '<div class="notice"><slot /></div>',
    props: ['variant'],
  },
  'b-tabs': tabsStub,
  'b-tab': { template: '<div class="tab"><slot /></div>' },
}

function mountPage() {
  return mount(SettingsPage, { global: { stubs } })
}

function pick(wrapper, setting) {
  return wrapper.findComponent(searchStub).vm.$emit('select', setting)
}

const SPAM_SETTING = {
  id: 'settings.spammers.worrywords',
  tab: 'community',
  section: 'accordion-spam',
  label: 'Worry words?',
}

describe('settings page jump-to-setting', () => {
  beforeEach(() => {
    routeParams = {}
    revealSetting.mockReset()
    revealSetting.mockResolvedValue(true)
  })

  it('switches to the tab the setting lives on', async () => {
    const wrapper = mountPage()

    await pick(wrapper, {
      id: 'personal-play-beep',
      tab: 'personal',
      label: 'Play Beep',
    })
    await flushPromises()

    expect(wrapper.findComponent(tabsStub).props('modelValue')).toBe(0)

    await pick(wrapper, SPAM_SETTING)
    await flushPromises()

    expect(wrapper.findComponent(tabsStub).props('modelValue')).toBe(1)
  })

  it('opens the accordion section containing the setting', async () => {
    const wrapper = mountPage()

    await pick(wrapper, SPAM_SETTING)
    await flushPromises()

    expect(wrapper.findComponent(groupStub).vm.openSection).toBe(
      'accordion-spam'
    )
  })

  it('scrolls to and flashes the setting', async () => {
    const wrapper = mountPage()

    await pick(wrapper, SPAM_SETTING)
    await flushPromises()

    expect(revealSetting).toHaveBeenCalledWith('settings.spammers.worrywords')
  })

  it('asks for a community when the setting is not on the page yet', async () => {
    revealSetting.mockResolvedValue(false)
    const wrapper = mountPage()

    await pick(wrapper, SPAM_SETTING)
    await flushPromises()

    // Silently doing nothing would read as the search being broken.
    expect(wrapper.find('.notice').text()).toContain('Pick a community')
    expect(wrapper.find('.notice').text()).toContain('Worry words?')
  })

  it('finishes the jump once a community is chosen', async () => {
    revealSetting.mockResolvedValue(false)
    const wrapper = mountPage()

    await pick(wrapper, SPAM_SETTING)
    await flushPromises()
    expect(wrapper.find('.notice').exists()).toBe(true)

    // The group's settings have now rendered.
    revealSetting.mockResolvedValue(true)
    wrapper.findComponent(groupStub).vm.$emit('settings-shown')
    await flushPromises()

    expect(revealSetting).toHaveBeenCalledTimes(2)
    expect(wrapper.find('.notice').exists()).toBe(false)
  })

  it('keeps asking if the setting still is not there', async () => {
    revealSetting.mockResolvedValue(false)
    const wrapper = mountPage()

    await pick(wrapper, SPAM_SETTING)
    await flushPromises()

    wrapper.findComponent(groupStub).vm.$emit('settings-shown')
    await flushPromises()

    expect(wrapper.find('.notice').exists()).toBe(true)
  })

  it('does nothing on settings-shown when no jump is waiting', async () => {
    const wrapper = mountPage()

    wrapper.findComponent(groupStub).vm.$emit('settings-shown')
    await flushPromises()

    expect(revealSetting).not.toHaveBeenCalled()
  })

  it('clears a previous prompt when a new setting is picked', async () => {
    revealSetting.mockResolvedValue(false)
    const wrapper = mountPage()

    await pick(wrapper, SPAM_SETTING)
    await flushPromises()
    expect(wrapper.find('.notice').exists()).toBe(true)

    revealSetting.mockResolvedValue(true)
    await pick(wrapper, {
      id: 'personal-play-beep',
      tab: 'personal',
      label: 'Play Beep',
    })
    await flushPromises()

    expect(wrapper.find('.notice').exists()).toBe(false)
  })

  it('opens on the Community tab when a group id is in the route', async () => {
    routeParams = { id: '123' }
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.findComponent(tabsStub).props('modelValue')).toBe(1)
  })
})
