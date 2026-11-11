import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

import SettingsPage from '~/pages/settings/index.vue'

const startSettingsFlow = vi.hoisted(() => vi.fn())
vi.mock('~/composables/useSettingsTelemetry', () => ({ startSettingsFlow }))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: ref({ id: 1, settings: {} }) }),
  fetchMe: vi.fn().mockResolvedValue(undefined),
}))

globalThis.useHead = () => {}

describe('pages/settings', () => {
  beforeEach(() => {
    startSettingsFlow.mockClear()
  })

  it('starts the settings flow telemetry for the visit', async () => {
    mount(SettingsPage, {
      global: {
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ProfileSection: true,
          AccountSection: true,
          AddressBookSection: true,
          FeedSettingsSection: true,
          EmailSettingsSection: true,
          AppNotificationsSection: true,
          OtherSettingsSection: true,
        },
      },
    })
    await flushPromises()
    expect(startSettingsFlow).toHaveBeenCalledTimes(1)
  })
})
