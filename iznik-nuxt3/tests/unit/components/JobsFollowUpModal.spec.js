import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense } from 'vue'

/* ---------------------------------------------------- component under test */

import JobsFollowUpModal from '~/components/JobsFollowUpModal.vue'

/* ------------------------------------------------------------------ mocks */

const mockJobList = [
  {
    id: 1,
    job_reference: 'ref-1',
    title: 'Software Developer',
    location: 'London',
    category: 'IT',
  },
  {
    id: 2,
    job_reference: 'ref-2',
    title: 'Nurse',
    location: 'Manchester',
    category: 'Healthcare',
  },
  {
    id: 3,
    job_reference: 'ref-3',
    title: 'Teacher',
    location: 'Birmingham',
    category: 'Education',
  },
  {
    id: 4,
    job_reference: 'ref-4',
    title: 'Driver',
    location: 'Leeds',
    category: 'Transport',
  },
  {
    id: 5,
    job_reference: 'ref-5',
    title: 'Engineer',
    location: 'Bristol',
    category: 'Engineering',
  },
  {
    id: 6,
    job_reference: 'ref-6',
    title: 'Chef',
    location: 'Glasgow',
    category: 'Hospitality',
  },
  {
    id: 7,
    job_reference: 'ref-7',
    title: 'Accountant',
    location: 'Edinburgh',
    category: 'Finance',
  },
  {
    id: 8,
    job_reference: 'ref-8',
    title: 'Marketing Manager',
    location: 'Cardiff',
    category: 'Marketing',
  },
  {
    id: 9,
    job_reference: 'ref-9',
    title: 'Designer',
    location: 'Oxford',
    category: 'Creative',
  },
]

const mockJobStore = {
  get list() {
    return mockJobList
  },
}

const mockAction = vi.fn()

vi.mock('~/stores/job', () => ({
  useJobStore: () => mockJobStore,
}))

vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))

/* useOurModal stub. modal must be a real ref: the component binds it as a template ref
 * (<b-modal ref="modal">), and a plain object triggers a Vue warning (which the test setup
 * treats as a failure). */
vi.mock('~/composables/useOurModal', async () => {
  const { ref } = await vi.importActual('vue')
  return {
    useOurModal: () => {
      const modal = ref({ show: () => {}, hide: () => {} })
      return {
        modal,
        show: () => modal.value?.show?.(),
        hide: () => modal.value?.hide?.(),
      }
    },
  }
})

/* Neutralise the async import of JobOne so the real (heavy) component isn't loaded. JobOne is
 * the only async component in the modal, so the stub renders as a lightweight .job-one row. */
vi.mock('vue', async () => {
  const actual = await vi.importActual('vue')
  return {
    ...actual,
    defineAsyncComponent: () => ({
      props: ['id', 'summary', 'context', 'position', 'listLength'],
      template:
        '<div class="job-one" :data-id="id" :data-context="context" :data-position="position" />',
    }),
  }
})

/* ----------------------------------------------------------------- helpers */

/* The modal imports JobOne via defineAsyncComponent, so it must be resolved inside a
 * <Suspense> boundary (mounting it bare leaves the root instance unresolved). */
function createWrapper(props = {}) {
  const TestWrapper = defineComponent({
    setup() {
      return () =>
        h(Suspense, null, {
          default: () => h(JobsFollowUpModal, props),
          fallback: () => h('div', 'Loading...'),
        })
    },
  })

  return mount(TestWrapper, {
    attachTo: document.body,
    global: {
      stubs: {
        'b-modal': {
          template: '<div class="b-modal"><slot name="default" /></div>',
          props: [
            'size',
            'hideHeader',
            'hideFooter',
            'noStacking',
            'modalClass',
          ],
          emits: ['show'],
        },
        'v-icon': {
          template: '<span class="v-icon" :data-icon="icon" />',
          props: ['icon'],
        },
      },
    },
  })
}

/* ------------------------------------------------------------------- tests */

describe('JobsFollowUpModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('job filtering', () => {
    it('shows jobs from the store when no excludeIds are given', async () => {
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()
      const jobs = wrapper.findAll('.job-one')
      expect(jobs.length).toBeGreaterThan(0)
    })

    it('excludes jobs whose ids are in excludeIds', async () => {
      const wrapper = createWrapper({ excludeIds: [1, 2, 3] })
      await flushPromises()
      const jobs = wrapper.findAll('.job-one')
      const ids = jobs.map((j) => Number(j.attributes('data-id')))
      expect(ids).not.toContain(1)
      expect(ids).not.toContain(2)
      expect(ids).not.toContain(3)
    })

    it('caps the default view at 8 jobs', async () => {
      /* Store has 9 jobs; the modal should show at most 8 without a query. */
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()
      const jobs = wrapper.findAll('.job-one')
      expect(jobs.length).toBeLessThanOrEqual(8)
    })
  })

  describe('search box', () => {
    it('renders the search input', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('input[type="text"]').exists()).toBe(true)
    })

    it('filters jobs to matches when a query is entered', async () => {
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()

      const input = wrapper.find('input[type="text"]')
      await input.setValue('software')

      const jobs = wrapper.findAll('.job-one')
      const ids = jobs.map((j) => Number(j.attributes('data-id')))
      /* "Software Developer" (id 1) matches; others should not be included. */
      expect(ids).toContain(1)
      expect(ids.length).toBe(1)
    })

    it('restores the default list when the query is cleared', async () => {
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()

      const input = wrapper.find('input[type="text"]')
      await input.setValue('software')
      await input.setValue('')

      const jobs = wrapper.findAll('.job-one')
      expect(jobs.length).toBeGreaterThan(1)
    })

    it('shows a no-results message when nothing matches', async () => {
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()

      const input = wrapper.find('input[type="text"]')
      await input.setValue('zzznomatch')

      expect(wrapper.find('.no-results').exists()).toBe(true)
      expect(wrapper.find('.job-one').exists()).toBe(false)
    })

    it('matches against location as well as title', async () => {
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()

      const input = wrapper.find('input[type="text"]')
      await input.setValue('bristol')

      const jobs = wrapper.findAll('.job-one')
      const ids = jobs.map((j) => Number(j.attributes('data-id')))
      /* Job 5 "Engineer" is in Bristol. */
      expect(ids).toContain(5)
    })

    it('matches against category', async () => {
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()

      const input = wrapper.find('input[type="text"]')
      await input.setValue('healthcare')

      const jobs = wrapper.findAll('.job-one')
      const ids = jobs.map((j) => Number(j.attributes('data-id')))
      /* Job 2 "Nurse" has category Healthcare. */
      expect(ids).toContain(2)
    })
  })

  describe('no /jobs navigation', () => {
    it('contains no link pointing to /jobs', async () => {
      const wrapper = createWrapper({ excludeIds: [] })
      await flushPromises()
      const links = wrapper.findAll('a')
      links.forEach((link) => {
        expect(link.attributes('href')).not.toBe('/jobs')
      })
    })
  })

  describe('close button', () => {
    it('renders a close button', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.close-btn').exists()).toBe(true)
    })
  })
})
