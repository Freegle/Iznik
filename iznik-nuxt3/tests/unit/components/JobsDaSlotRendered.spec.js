import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive, defineComponent, h, nextTick } from 'vue'
import JobsDaSlot from '~/components/JobsDaSlot.vue'

// Regression suite for the empty grey band at the bottom of the page (2026-08-13).
//
// Measured on dev-live before the fix: .sticky was 123px of $gray-200 with .jobs-slot
// absent entirely, zero job cards, the "Hate ads?" CTA showing, and .aboveSticky padded
// 125px to make room for an ad that never came.
//
// Two causes, both in this component:
//   1. onMounted emitted an unconditional rendered:true, so ExternalDa believed an ad had
//      rendered and LayoutCommon reserved the height and tinted it. The collapse path
//      (.adNotShown, padding-bottom 0) needs stickyAdRendered === 0 and could never fire.
//   2. The user was read non-reactively (`const me = authStore.user`, lat/lng as plain
//      consts) and the fetch ran once at setup, so a session that hydrated later - the
//      normal case in the app, which restores a token rather than a cookie - never
//      triggered a fetch and nothing retried.

// Reactive stand-ins for the stores, so a test can hydrate the session or land the jobs
// AFTER mount and the component has to notice. A plain object would pass the old code.
const authState = reactive({ user: null })
const jobState = reactive({ list: [], blocked: false })
const mockFetch = vi.fn().mockResolvedValue({})

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => authState,
}))

vi.mock('~/stores/job', () => ({
  useJobStore: () => ({
    get list() {
      return jobState.list
    },
    get blocked() {
      return jobState.blocked
    },
    fetch: mockFetch,
  }),
}))

vi.mock('~/composables/useJobsFollowUpModal', () => ({
  useJobsFollowUpModal: () => ({
    shouldShowModal: () => false,
    recordShown: vi.fn(),
  }),
}))

const JOB = {
  id: 1,
  job_reference: 'ref-1',
  title: 'Warehouse operative',
  location: 'Preston',
  cpc: 0.5,
  clickability: 1,
}

const stubs = {
  JobOne: defineComponent({ render: () => h('div', { class: 'job-one' }) }),
  JobsFollowUpModal: defineComponent({ render: () => h('div') }),
  NoticeMessage: defineComponent({ render: () => h('div') }),
  DonationButton: defineComponent({ render: () => h('div') }),
  'v-icon': defineComponent({ render: () => h('i') }),
  'nuxt-link': defineComponent({ render: () => h('a') }),
}

const AT = { id: 1, lat: 53.7, lng: -2.7, settings: { mylocation: { name: 'Preston' } } }

async function mountSlot() {
  const wrapper = mount(JobsDaSlot, { global: { stubs } })
  await flushPromises()
  await nextTick()
  return wrapper
}

function lastRendered(wrapper) {
  const emitted = wrapper.emitted('rendered') ?? []
  expect(emitted.length).toBeGreaterThan(0)
  return emitted[emitted.length - 1][0]
}

beforeEach(() => {
  vi.clearAllMocks()
  authState.user = null
  jobState.list = []
  jobState.blocked = false
})

describe('JobsDaSlot — reports what it actually rendered', () => {
  it('emits rendered=false with no location, so the reserved band can collapse', async () => {
    authState.user = { id: 1, lat: 53.7, lng: -2.7 } // no settings.mylocation
    jobState.list = [JOB]
    const wrapper = await mountSlot()

    expect(wrapper.find('.jobs-slot').exists()).toBe(false)
    expect(lastRendered(wrapper)).toBe(false)
  })

  it('emits rendered=false when the jobs list is empty', async () => {
    authState.user = AT
    const wrapper = await mountSlot()

    expect(wrapper.find('.jobs-slot').exists()).toBe(false)
    expect(lastRendered(wrapper)).toBe(false)
  })

  it('emits rendered=true only when a job is actually on screen', async () => {
    authState.user = AT
    jobState.list = [JOB]
    const wrapper = await mountSlot()

    expect(wrapper.find('.jobs-slot').exists()).toBe(true)
    expect(wrapper.findAll('.job-one').length).toBe(1)
    expect(lastRendered(wrapper)).toBe(true)
  })

  it('re-emits when jobs arrive after mount, since the first answer is "nothing yet"', async () => {
    authState.user = AT
    const wrapper = await mountSlot()
    expect(lastRendered(wrapper)).toBe(false)

    jobState.list = [JOB]
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.jobs-slot').exists()).toBe(true)
    expect(lastRendered(wrapper)).toBe(true)
  })
})

describe('JobsDaSlot — fetches when the session arrives late', () => {
  it('does not fetch while there is no user', async () => {
    await mountSlot()

    expect(mockFetch).not.toHaveBeenCalled()
  })

  it('fetches once the session hydrates after mount', async () => {
    // The app case exactly: the sticky slot mounts with the layout, before the token
    // restore has produced a user. The old code captured lat/lng as undefined at setup
    // and never looked again.
    await mountSlot()
    expect(mockFetch).not.toHaveBeenCalled()

    authState.user = {
      id: 1,
      lat: 53.86469,
      lng: -2.624747,
      settings: { mylocation: { name: 'PR3 2NX' } },
    }
    await flushPromises()

    expect(mockFetch).toHaveBeenCalledWith(53.86469, -2.624747)
  })

  it('re-fetches when the member changes location', async () => {
    authState.user = AT
    await mountSlot()
    expect(mockFetch).toHaveBeenCalledWith(53.7, -2.7)

    authState.user = {
      id: 1,
      lat: 51.5074,
      lng: -0.1278,
      settings: { mylocation: { name: 'London' } },
    }
    await flushPromises()

    expect(mockFetch).toHaveBeenCalledWith(51.5074, -0.1278)
  })
})
