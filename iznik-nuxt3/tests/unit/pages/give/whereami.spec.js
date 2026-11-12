/**
 * The desktop Give flow's postcode step. When the community near the postcode is closed
 * the Next button is hidden, so the page has to say why.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import WhereamiPage from '~/pages/give/whereami.vue'

globalThis.__testUseRouter = () => ({ push: vi.fn() })
globalThis.__testUseRoute = () => ({
  params: {},
  query: {},
  path: '/give/whereami',
})
globalThis.useHead = () => {}

const state = vi.hoisted(() => ({}))

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({ all: [{ id: 7, type: 'Offer' }] }),
}))

vi.mock('~/composables/useBuildHead', () => ({ buildHead: () => ({}) }))

vi.mock('~/composables/useCompose', async () => {
  const { ref } = await import('vue')
  return {
    setup: async () => ({
      initialPostcode: ref(''),
      postcodeValid: ref(true),
      noGroups: ref(false),
      closed: ref(state.closed),
      group: ref(null),
    }),
    postcodeSelect: vi.fn(),
    postcodeClear: vi.fn(),
  }
})

const stubs = {
  'client-only': { template: '<div><slot /></div>' },
  'b-button': { template: '<button class="b-btn"><slot /></button>' },
  'v-icon': { template: '<i />' },
  WizardProgressCompact: { template: '<div />' },
  GlobalMessage: { template: '<div />' },
  PostCode: { template: '<div />' },
  ComposeGroup: { template: '<div />' },
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
  ExternalLink: { template: '<a><slot /></a>' },
  PostPersonalInfoWarning: { template: '<div />' },
}

async function mountPage() {
  const Host = {
    components: { WhereamiPage },
    template: '<Suspense><WhereamiPage /></Suspense>',
  }
  const wrapper = mount(Host, { global: { stubs } })
  await flushPromises()
  return wrapper
}

describe('give/whereami: a closed community', () => {
  beforeEach(() => {
    state.closed = false
  })

  it('offers the next step when the community is open', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('.b-btn').exists()).toBe(true)
    expect(wrapper.find('.notice').exists()).toBe(false)
  })

  it('says the community is closed when the next step is hidden', async () => {
    state.closed = true

    const wrapper = await mountPage()

    expect(wrapper.find('.b-btn').exists()).toBe(false)
    expect(wrapper.find('.notice').text().toLowerCase()).toContain('closed')
  })
})
