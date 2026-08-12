import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSysAdminRipplingDensity from '~/modtools/components/ModSysAdminRipplingDensity.vue'

// This panel exists to answer one question - is a shorter city cap and a longer
// rural one better than the flat 30 minutes? - and every way of getting that
// wrong is a way of reading a row as more than it says. So the tests are about
// what the page tells a sysadmin, not about which numbers land in which cell.

const fetchDensity = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({ rippling: { fetchDensity } }),
}))

const BANDS = {
  start: '2026-07-11 00:00:00',
  end: '2026-08-10 00:00:00',
  bands: [
    {
      band: 'dense',
      posts: 900,
      capminutes: 20,
      avgdrivemin: 19.4,
      avgradiusmiles: 1.1,
      avgaudience: 5200,
      replied: 540,
      taken: 300,
      held: 120,
      released: 100,
    },
    {
      band: 'sparse',
      posts: 400,
      capminutes: 45,
      avgdrivemin: 44.1,
      avgradiusmiles: 6.3,
      avgaudience: 1800,
      replied: 200,
      taken: 120,
      held: 60,
      released: 55,
    },
  ],
}

function createWrapper() {
  return mount(ModSysAdminRipplingDensity, {
    global: {
      stubs: {
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        'b-spinner': { template: '<div class="spinner" />' },
        'b-button': { template: '<button><slot /></button>' },
        // Declares its props, so the option objects and v-model do not fall
        // through to a bare <select> and raise a Vue warning.
        'b-form-select': {
          props: ['modelValue', 'options', 'size'],
          emits: ['update:modelValue', 'change'],
          template: '<select />',
        },
        'b-table-simple': { template: '<table><slot /></table>' },
        'b-thead': { template: '<thead><slot /></thead>' },
        'b-tbody': { template: '<tbody><slot /></tbody>' },
        'b-tr': { template: '<tr><slot /></tr>' },
        'b-th': { template: '<th><slot /></th>' },
        'b-td': { template: '<td><slot /></td>' },
      },
    },
  })
}

// Prose wraps wherever the formatter puts it, so compare on collapsed
// whitespace - otherwise these assertions break on a reflow that changed
// nothing a reader would notice.
async function renderedText(payload = BANDS) {
  fetchDensity.mockResolvedValue(payload)

  const wrapper = createWrapper()
  await flushPromises()

  return wrapper.text().replace(/\s+/g, ' ')
}

describe('ModSysAdminRipplingDensity', () => {
  beforeEach(() => {
    fetchDensity.mockReset()
  })

  it('names each band in words a reader can place, not the stored code', async () => {
    const text = await renderedText()

    expect(text).toContain('Dense (city)')
    expect(text).toContain('Sparse (rural)')
  })

  it('shows the budget asked for beside the drive time reached', async () => {
    // The gap between them is the whole diagnosis: a band well under its cap was
    // never constrained by it, so nothing else in that row is about the cap.
    const text = await renderedText()

    expect(text).toContain('Cap asked')
    expect(text).toContain('Drive time reached')
    expect(text).toContain('20 min')
    expect(text).toContain('45 min')
  })

  it('reports rehoming as a rate, because the bands are different sizes', async () => {
    // 300 of 900 and 120 of 400 are the same 33%. Counts alone would read as the
    // city doing two and a half times better.
    const text = await renderedText()

    expect(text).toContain('33%')
  })

  it('warns that unmeasured posts are a broken measurement, not a fourth band', async () => {
    const text = await renderedText({
      ...BANDS,
      bands: [
        ...BANDS.bands,
        {
          band: 'unknown',
          posts: 50,
          capminutes: 0,
          avgdrivemin: 30,
          avgradiusmiles: 0,
          avgaudience: 900,
          replied: 20,
          taken: 10,
          held: 5,
          released: 5,
        },
      ],
    })

    expect(text).toContain('could not be measured')
    expect(text).toContain('not a fourth kind of place')
  })

  it('says nothing about unmeasured posts when every post was measured', async () => {
    expect(await renderedText()).not.toContain('could not be measured')
  })

  it('states the withdrawn-post bias rather than letting rehomed read as the true rate', async () => {
    expect(await renderedText()).toContain('overestimate')
  })

  it('says the window is empty rather than showing a blank table', async () => {
    expect(await renderedText({ ...BANDS, bands: [] })).toContain(
      'No posts started rippling'
    )
  })

  it('surfaces a failed fetch instead of an empty panel that looks like no data', async () => {
    fetchDensity.mockRejectedValue(new Error('gateway timeout'))

    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.text()).toContain('gateway timeout')
  })
})
