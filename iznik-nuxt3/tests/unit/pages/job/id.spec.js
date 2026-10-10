import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, Suspense, h, reactive } from 'vue'

// pages/job/[id].vue is where every digest-email job link lands: it logs the click and sends
// the member on to the advert. WhatJobs does not pay for a repeat click on the same advert
// within a day, and counts it against the first one, so the page must not send this device to
// an advert it has already opened - whether the email link was tapped again or the page
// loaded twice.

const JOB = {
  id: 42,
  url: 'https://www.whatjobs.com/redirect/42',
  reference: 'ref-42',
  category: 'Driving',
  cpc: 0.15,
}

const mockJobStore = {
  fetchOne: vi.fn(),
  log: vi.fn(),
  openedRecently: vi.fn(),
  recordOpened: vi.fn(),
}
const mockMiscStore = reactive({ unloading: false })
const mockAction = vi.fn()

vi.mock('~/stores/job', () => ({ useJobStore: () => mockJobStore }))
vi.mock('~/stores/misc', () => ({ useMiscStore: () => mockMiscStore }))
vi.mock('~/composables/useClientLog', () => ({
  useClientLog: () => ({ action: mockAction }),
}))

const mockRoute = reactive({
  params: { id: '42' },
  query: { source: 'email', campaign: 'unified_digest' },
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => mockRoute,
    definePageMeta: vi.fn(),
  }
})

globalThis.__testUseRoute = () => mockRoute
globalThis.definePageMeta = vi.fn()

async function mountPage() {
  const Page = (await import('~/pages/job/[id].vue')).default
  const Wrapper = defineComponent({
    setup() {
      return () => h(Suspense, null, { default: () => h(Page) })
    },
  })
  const wrapper = mount(Wrapper, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-button': {
          template:
            '<button class="b-button" @click="$emit(\'click\')"><slot /></button>',
        },
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        Spinner: { template: '<div class="spinner" />' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('pages/job/[id].vue', () => {
  let originalLocation

  beforeEach(() => {
    vi.clearAllMocks()
    mockJobStore.fetchOne.mockResolvedValue({ ...JOB })
    mockJobStore.openedRecently.mockReturnValue(false)
    mockMiscStore.unloading = false
    originalLocation = window.location
    delete window.location
    window.location = ''
  })

  afterEach(() => {
    window.location = originalLocation
  })

  it('logs the click, records the advert as opened and sends the member to it', async () => {
    await mountPage()

    expect(mockJobStore.log).toHaveBeenCalledWith({
      id: 42,
      link: JOB.url,
      placement: 'email_redirect',
      source: 'email',
    })
    expect(mockJobStore.recordOpened).toHaveBeenCalledWith(42, 0)
    expect(window.location).toBe(JOB.url)
  })

  it('marks the page as unloading before it leaves, so a cancelled load cannot reload it', async () => {
    await mountPage()
    expect(mockMiscStore.unloading).toBe(true)
  })

  it('does not send the member to an advert this device opened recently', async () => {
    mockJobStore.openedRecently.mockReturnValue(true)
    const wrapper = await mountPage()

    expect(mockJobStore.openedRecently).toHaveBeenCalledWith(42)
    expect(mockJobStore.log).not.toHaveBeenCalled()
    expect(mockAction).not.toHaveBeenCalled()
    expect(window.location).toBe('')
    expect(wrapper.text()).toContain("You've already looked at this job")
  })

  it('opens the advert again only when the member asks', async () => {
    mockJobStore.openedRecently.mockReturnValue(true)
    const wrapper = await mountPage()

    const again = wrapper
      .findAll('.b-button')
      .find((b) => b.text().includes('Open it again'))
    await again.trigger('click')

    expect(mockJobStore.log).toHaveBeenCalledTimes(1)
    expect(window.location).toBe(JOB.url)
  })

  it('says the job is gone when it cannot be fetched', async () => {
    mockJobStore.fetchOne.mockResolvedValue(null)
    const wrapper = await mountPage()

    expect(mockJobStore.log).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('no longer available')
  })
})
