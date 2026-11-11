import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, Suspense, h, reactive } from 'vue'

// pages/job/[id].vue is where every digest-email job link lands: it logs the click and sends
// the member on to the advert. WhatJobs does not pay for a repeat click on the same advert
// within a day, and counts it against the first one, so the page must not send this device to
// an advert it has already opened - whether the email link was tapped again or the page
// loaded twice.
//
// Mail-security scanners open email links in real browsers, and WhatJobs discards what they
// click. The API says per caller whether the page may open the advert at once: autoopen is false
// for an address abroad or on Microsoft's network, and the page then shows a card and waits for
// a tap. A missing autoopen, or true, means the page opens the advert at once, so the page can
// deploy before the API. The page reports the browser's time zone with its request, because a
// browser whose clock is in the UK is never asked for a tap, and it remembers the first tap on
// the device, so a person is asked once.

const JOB = {
  id: 42,
  url: 'https://www.whatjobs.com/redirect/42',
  job_reference: 'ref-42',
  category: 'Driving',
  cpc: 0.15,
}

const GATED_JOB = {
  ...JOB,
  title: 'Care Assistant',
  location: ', Newtown',
  autoopen: false,
  autoopen_reason: 'hosting',
}

const mockJobStore = {
  fetchOne: vi.fn(),
  log: vi.fn(),
  openedRecently: vi.fn(),
  recordOpened: vi.fn(),
  hasTappedGate: vi.fn(),
  recordGateTap: vi.fn(),
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
        // Root-element fallthrough passes the page's @click to the native button.
        'b-button': { template: '<button class="b-button"><slot /></button>' },
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        Spinner: { template: '<div class="spinner" />' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

// The client log call for a tap on the advert, or undefined if there was none.
function clickEvent() {
  return mockAction.mock.calls.find(([name]) => name === 'job_ad_click')
}

describe('pages/job/[id].vue', () => {
  let originalLocation

  beforeEach(() => {
    vi.clearAllMocks()
    mockJobStore.fetchOne.mockResolvedValue({ ...JOB })
    mockJobStore.openedRecently.mockReturnValue(false)
    mockJobStore.hasTappedGate.mockReturnValue(false)
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
    expect(mockAction).not.toHaveBeenCalledWith(
      'job_ad_click',
      expect.anything()
    )
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

  it('sends no gated flag when the page opens the advert itself', async () => {
    await mountPage()

    const click = clickEvent()
    expect(click[1]).toMatchObject({ job_id: 42, context: 'email_redirect' })
    expect(click[1]).not.toHaveProperty('gated')
  })

  describe('when the API says not to open the advert at once', () => {
    beforeEach(() => {
      mockJobStore.fetchOne.mockResolvedValue({ ...GATED_JOB })
    })

    const buttonNamed = (wrapper, text) =>
      wrapper.findAll('.b-button').find((b) => b.text().includes(text))

    it('shows the job and waits, without logging, recording or leaving', async () => {
      const wrapper = await mountPage()

      expect(wrapper.text()).toContain('Care Assistant')
      expect(wrapper.text()).toContain('Newtown')
      expect(wrapper.text()).toContain('Tap to open this job.')
      expect(buttonNamed(wrapper, 'Open this job')).toBeTruthy()
      expect(buttonNamed(wrapper, 'View more jobs')).toBeTruthy()

      expect(mockJobStore.log).not.toHaveBeenCalled()
      expect(mockJobStore.recordOpened).not.toHaveBeenCalled()
      expect(mockAction).not.toHaveBeenCalledWith(
        'job_ad_click',
        expect.anything()
      )
      expect(window.location).toBe('')
      expect(mockMiscStore.unloading).toBe(false)
    })

    it('says nothing about why', async () => {
      const wrapper = await mountPage()

      const text = wrapper.text().toLowerCase()
      expect(text).not.toMatch(/\bscanners?\b/)
      expect(text).not.toMatch(/\bbots?\b/)
      expect(text).not.toMatch(/\brobots?\b/)
      expect(text).not.toMatch(/\bhosting\b/)
      expect(text).not.toMatch(/\bsecurity\b/)
    })

    it('never gives the browser a link to the advert before the tap', async () => {
      const wrapper = await mountPage()

      expect(wrapper.html()).not.toContain(JOB.url)
      expect(wrapper.html()).not.toContain('whatjobs')

      const open = buttonNamed(wrapper, 'Open this job')
      expect(open.element.tagName).toBe('BUTTON')
      expect(open.attributes('href')).toBeUndefined()
      expect(open.attributes('to')).toBeUndefined()

      const more = buttonNamed(wrapper, 'View more jobs')
      expect(more.attributes('to')).toBe('/jobs')
    })

    it('logs that the card was shown, with the reason, source and campaign', async () => {
      await mountPage()

      expect(mockAction).toHaveBeenCalledWith('job_ad_gated', {
        job_id: 42,
        reason: 'hosting',
        source: 'email',
        campaign: 'unified_digest',
      })
      expect(
        mockAction.mock.calls.filter(([name]) => name === 'job_ad_gated')
      ).toHaveLength(1)
    })

    it.each(['abroad', 'hosting', 'forced'])(
      'passes the reason %s to the client log',
      async (reason) => {
        mockJobStore.fetchOne.mockResolvedValue({
          ...GATED_JOB,
          autoopen_reason: reason,
        })
        await mountPage()

        expect(mockAction).toHaveBeenCalledWith(
          'job_ad_gated',
          expect.objectContaining({ reason })
        )
      }
    )

    it('opens the advert when the member taps, and logs the click with its placement and source', async () => {
      const wrapper = await mountPage()

      await buttonNamed(wrapper, 'Open this job').trigger('click')

      expect(mockJobStore.log).toHaveBeenCalledTimes(1)
      expect(mockJobStore.log).toHaveBeenCalledWith({
        id: 42,
        link: JOB.url,
        placement: 'email_redirect',
        source: 'email',
      })
      expect(mockJobStore.recordOpened).toHaveBeenCalledWith(42, 0)
      expect(window.location).toBe(JOB.url)
      expect(mockMiscStore.unloading).toBe(true)
      expect(wrapper.text()).toContain('should have opened in your browser')
    })

    it('adds gated: true to the client click event, and only there', async () => {
      const wrapper = await mountPage()

      await buttonNamed(wrapper, 'Open this job').trigger('click')

      const click = clickEvent()
      expect(click[1]).toMatchObject({
        job_id: 42,
        source: 'email',
        campaign: 'unified_digest',
        context: 'email_redirect',
        gated: true,
      })

      // The click row that is compared with the partner carries no flag.
      expect(mockJobStore.log.mock.calls[0][0]).not.toHaveProperty('gated')
    })

    it('replaces the card at once, so a second tap has nothing to land on', async () => {
      const wrapper = await mountPage()

      await buttonNamed(wrapper, 'Open this job').trigger('click')
      expect(buttonNamed(wrapper, 'Open this job')).toBeUndefined()
      expect(wrapper.text()).not.toContain('Tap to open this job.')
      expect(mockJobStore.log).toHaveBeenCalledTimes(1)
    })

    describe('the words on the card', () => {
      it('drops the comma and space the feed puts in front of the place', async () => {
        const wrapper = await mountPage()

        expect(wrapper.findAll('p')[0].text()).toBe('Newtown')
        expect(wrapper.text()).not.toContain(', Newtown')
      })

      it('shows the title and place the way the job tiles do', async () => {
        mockJobStore.fetchOne.mockResolvedValue({
          ...GATED_JOB,
          title: 'Café Assistant<br>Â£50k\\nPart time',
          location: ', Newtown, Powys',
        })
        const wrapper = await mountPage()

        const title = wrapper.find('h1').element.textContent
        expect(title.replace(/\s+/g, ' ')).toBe('Cafe Assistant £50k Part time')
        expect(wrapper.text()).not.toContain('<br>')
        expect(wrapper.text()).not.toContain('\\n')
        expect(wrapper.text()).not.toContain('Â£')
        expect(wrapper.findAll('p')[0].text()).toBe('Newtown, Powys')
      })

      it('shows no heading or place when the job has none', async () => {
        mockJobStore.fetchOne.mockResolvedValue({
          ...JOB,
          autoopen: false,
          autoopen_reason: 'abroad',
        })
        const wrapper = await mountPage()

        expect(wrapper.find('h1').exists()).toBe(false)
        expect(wrapper.text()).toContain('Tap to open this job.')
        expect(wrapper.findAll('p')).toHaveLength(1)
      })
    })

    describe('the first tap is remembered on the device', () => {
      it('remembers the first tap on the card', async () => {
        const wrapper = await mountPage()
        expect(mockJobStore.recordGateTap).not.toHaveBeenCalled()

        await buttonNamed(wrapper, 'Open this job').trigger('click')

        expect(mockJobStore.recordGateTap).toHaveBeenCalledTimes(1)
      })

      it('opens at once, with no card, on a device that has tapped before', async () => {
        mockJobStore.hasTappedGate.mockReturnValue(true)
        const wrapper = await mountPage()

        expect(wrapper.text()).not.toContain('Tap to open this job.')
        expect(mockAction).not.toHaveBeenCalledWith(
          'job_ad_gated',
          expect.anything()
        )
        expect(mockJobStore.log).toHaveBeenCalledTimes(1)
        expect(mockJobStore.recordOpened).toHaveBeenCalledWith(42, 0)
        expect(window.location).toBe(JOB.url)
        expect(mockMiscStore.unloading).toBe(true)
      })

      it('sends no gated flag for an open the card did not ask for', async () => {
        mockJobStore.hasTappedGate.mockReturnValue(true)
        await mountPage()

        expect(clickEvent()[1]).not.toHaveProperty('gated')
        expect(mockJobStore.recordGateTap).not.toHaveBeenCalled()
      })

      it('still shows the card on a device with fresh storage', async () => {
        mockJobStore.hasTappedGate.mockReturnValue(false)
        const wrapper = await mountPage()

        expect(wrapper.text()).toContain('Tap to open this job.')
        expect(mockJobStore.log).not.toHaveBeenCalled()
      })

      it('puts an advert opened in the last day before the memory of a tap', async () => {
        mockJobStore.hasTappedGate.mockReturnValue(true)
        mockJobStore.openedRecently.mockReturnValue(true)
        const wrapper = await mountPage()

        expect(wrapper.text()).toContain("You've already looked at this job")
        expect(mockJobStore.log).not.toHaveBeenCalled()
        expect(window.location).toBe('')
      })

      it('does not remember "Open it again", which is not a tap on the card', async () => {
        mockJobStore.openedRecently.mockReturnValue(true)
        const wrapper = await mountPage()

        await buttonNamed(wrapper, 'Open it again').trigger('click')

        expect(mockJobStore.recordGateTap).not.toHaveBeenCalled()
      })
    })

    it('leaves an advert this device already opened to the usual card', async () => {
      mockJobStore.openedRecently.mockReturnValue(true)
      const wrapper = await mountPage()

      expect(wrapper.text()).toContain("You've already looked at this job")
      expect(wrapper.text()).not.toContain('Tap to open this job.')
      expect(mockAction).not.toHaveBeenCalledWith(
        'job_ad_gated',
        expect.anything()
      )
      expect(mockJobStore.log).not.toHaveBeenCalled()
      expect(window.location).toBe('')
    })

    it('still says the job is gone when the id does not match', async () => {
      mockJobStore.fetchOne.mockResolvedValue({ ...GATED_JOB, id: 43 })
      const wrapper = await mountPage()

      expect(wrapper.text()).toContain('no longer available')
      expect(mockAction).not.toHaveBeenCalledWith(
        'job_ad_gated',
        expect.anything()
      )
    })

    it('adds no gated flag when "Open it again" is used on an advert opened before', async () => {
      mockJobStore.openedRecently.mockReturnValue(true)
      const wrapper = await mountPage()

      await buttonNamed(wrapper, 'Open it again').trigger('click')

      const click = clickEvent()
      expect(click[1]).not.toHaveProperty('gated')
    })
  })

  describe('when the API does not ask for a button', () => {
    it('opens at once when autoopen is true', async () => {
      mockJobStore.fetchOne.mockResolvedValue({ ...JOB, autoopen: true })
      await mountPage()

      expect(mockJobStore.log).toHaveBeenCalledTimes(1)
      expect(mockJobStore.recordOpened).toHaveBeenCalledWith(42, 0)
      expect(window.location).toBe(JOB.url)
      expect(mockAction).not.toHaveBeenCalledWith(
        'job_ad_gated',
        expect.anything()
      )
    })

    it('opens at once in shadow mode, when a reason comes with autoopen true', async () => {
      mockJobStore.fetchOne.mockResolvedValue({
        ...JOB,
        autoopen: true,
        autoopen_reason: 'hosting',
      })
      const wrapper = await mountPage()

      expect(window.location).toBe(JOB.url)
      expect(mockJobStore.log).toHaveBeenCalledTimes(1)
      expect(mockAction).not.toHaveBeenCalledWith(
        'job_ad_gated',
        expect.anything()
      )
      expect(wrapper.text()).not.toContain('Tap to open this job.')
    })

    it('opens at once when the API sends no autoopen at all', async () => {
      mockJobStore.fetchOne.mockResolvedValue({ ...JOB })
      await mountPage()

      expect(window.location).toBe(JOB.url)
      expect(mockAction).not.toHaveBeenCalledWith(
        'job_ad_gated',
        expect.anything()
      )
    })
  })

  // A browser whose clock is in the UK is never asked for a tap. The server cannot see the
  // clock, so the page reports it with the request for the job.
  describe("the browser's time zone", () => {
    let intl

    afterEach(() => {
      if (intl) {
        intl.mockRestore()
        intl = null
      }
    })

    function browserSays(zone) {
      intl = vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(() => ({
        resolvedOptions: () => ({ timeZone: zone }),
      }))
    }

    it('is sent with the request for the job', async () => {
      browserSays('Europe/London')
      await mountPage()

      expect(mockJobStore.fetchOne).toHaveBeenCalledTimes(1)
      expect(mockJobStore.fetchOne).toHaveBeenCalledWith(42, 'Europe/London')
    })

    it('is sent whatever it is, for the server to judge', async () => {
      browserSays('America/Los_Angeles')
      await mountPage()

      expect(mockJobStore.fetchOne).toHaveBeenCalledWith(
        42,
        'America/Los_Angeles'
      )
    })

    it('is left out when the browser has none', async () => {
      browserSays(undefined)
      await mountPage()

      expect(mockJobStore.fetchOne).toHaveBeenCalledWith(42, undefined)
    })

    it('is left out when asking the browser fails', async () => {
      intl = vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(() => {
        throw new Error('no Intl here')
      })
      const wrapper = await mountPage()

      expect(mockJobStore.fetchOne).toHaveBeenCalledWith(42, undefined)
      expect(window.location).toBe(JOB.url)
      expect(wrapper.exists()).toBe(true)
    })

    it('does not change what the page does with the answer', async () => {
      browserSays('Europe/London')
      mockJobStore.fetchOne.mockResolvedValue({ ...GATED_JOB })
      const wrapper = await mountPage()

      // The server decides. A card is shown when it says so, whatever the page sent.
      expect(wrapper.text()).toContain('Tap to open this job.')
      expect(mockJobStore.log).not.toHaveBeenCalled()
    })
  })

  describe('the click event', () => {
    it('names the advert by the job_reference the API returns', async () => {
      // The API's field is job_reference. The page read a field the API never sends, so every
      // click event from this page was missing the reference the ad tiles' events carry.
      await mountPage()

      const [, payload] = clickEvent()
      expect(payload.job_reference).toBe('ref-42')
    })
  })
})
