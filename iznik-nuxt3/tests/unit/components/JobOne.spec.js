import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import JobOne from '~/components/JobOne.vue'

const mockJob = {
  id: 123,
  job_reference: 'test-ref-123',
  title: 'Software Developer',
  location: ', London',
  url: 'https://jobs.example.com/123',
  image: 'https://example.com/job-image.jpg',
  category: 'IT;Technology',
  dist: 5.5,
  body: 'This is a great job opportunity for developers.',
  cpc: 0.5,
}

const mockJobStore = {
  byId: vi.fn().mockReturnValue(mockJob),
  log: vi.fn(),
}

const mockRouter = {
  push: vi.fn(),
  currentRoute: { value: { path: '/browse', name: 'browse-term' } },
}

const mockAction = vi.fn()

vi.mock('~/stores/job', () => ({
  useJobStore: () => mockJobStore,
}))

vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRouter: () => mockRouter,
  }
})

globalThis.__testUseRouter = () => mockRouter

vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))

vi.mock('~/constants', () => ({
  JOB_ICON_COLOURS: {
    'dark green': '#2d5016',
    'soft sage green': '#7a9e7e',
  },
}))

describe('JobOne', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockJobStore.byId.mockReturnValue({ ...mockJob })
    mockRouter.currentRoute = {
      value: { path: '/browse', name: 'browse-term' },
    }
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(JobOne, {
      props: {
        id: 123,
        ...props,
      },
      global: {
        stubs: {
          ExternalLink: {
            template: '<a class="external-link" :href="href"><slot /></a>',
            props: ['href'],
          },
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders job-item container when job exists', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.job-item').exists()).toBe(true)
    })

    it('does not render when job is null', () => {
      mockJobStore.byId.mockReturnValue(null)
      const wrapper = createWrapper()
      expect(wrapper.find('.job-item').exists()).toBe(false)
    })

    it('calls byId with correct id', () => {
      createWrapper({ id: 456 })
      expect(mockJobStore.byId).toHaveBeenCalledWith(456)
    })
  })

  describe('summary mode', () => {
    it('renders job-summary when summary prop is true', () => {
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-summary').exists()).toBe(true)
      expect(wrapper.find('.job-card').exists()).toBe(false)
    })

    it('renders job title', () => {
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-title').text()).toBe('Software Developer')
    })

    it('renders job location without leading comma', () => {
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-location').text()).toContain('London')
    })

    it('renders briefcase icon when no image', () => {
      mockJobStore.byId.mockReturnValue({ ...mockJob, image: null })
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.v-icon[data-icon="briefcase"]').exists()).toBe(true)
    })

    it('renders chevron icon', () => {
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.v-icon[data-icon="chevron-right"]').exists()).toBe(
        true
      )
    })
  })

  describe('card mode', () => {
    it('renders job-card when summary prop is false', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('.job-card').exists()).toBe(true)
      expect(wrapper.find('.job-summary').exists()).toBe(false)
    })

    it('renders card title', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('.job-card-title').text()).toBe('Software Developer')
    })

    it('renders job category', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('.job-category').text()).toBe('IT')
    })

    it('renders distance in miles', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('.job-distance').text()).toContain('mi')
    })

    it('renders Nearby for very close jobs', () => {
      mockJobStore.byId.mockReturnValue({ ...mockJob, dist: 0.5 })
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('.job-distance').text()).toContain('Nearby')
    })

    it('renders job description', () => {
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('.job-card-description').text()).toContain(
        'great job opportunity'
      )
    })

    it('adds highlight class when highlight prop is true', () => {
      const wrapper = createWrapper({ summary: false, highlight: true })
      expect(wrapper.find('.job-card--highlight').exists()).toBe(true)
    })
  })

  describe('computed properties', () => {
    it('returns empty title when job has no title', () => {
      mockJobStore.byId.mockReturnValue({ ...mockJob, title: null })
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-title').text()).toBe('')
    })

    it('cleans up location with leading comma', () => {
      mockJobStore.byId.mockReturnValue({
        ...mockJob,
        location: ', Manchester',
      })
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-location').text()).toContain('Manchester')
      expect(wrapper.find('.job-location').text()).not.toContain(', Manchester')
    })

    it('returns first category when multiple are present', () => {
      mockJobStore.byId.mockReturnValue({
        ...mockJob,
        category: 'Healthcare;Medical;Nursing',
      })
      const wrapper = createWrapper({ summary: false })
      expect(wrapper.find('.job-category').text()).toBe('Healthcare')
    })

    it('truncates long descriptions', () => {
      const longBody = 'A'.repeat(200)
      mockJobStore.byId.mockReturnValue({ ...mockJob, body: longBody })
      const wrapper = createWrapper({ summary: false })
      const description = wrapper.find('.job-card-description').text()
      expect(description.length).toBeLessThan(200)
      expect(description).toContain('...')
    })
  })

  describe('click handling', () => {
    it('logs click to jobStore with the placement (from context), source and page', async () => {
      // placement = the slot the ad was in; page = the route we were on, so CTR is
      // measurable per-slot AND per-page (the same slot appears on every page).
      // Exact match (not objectContaining): this is the revenue signal, so any new
      // field must be added here deliberately.
      const wrapper = createWrapper({ context: 'sticky_footer_mobile' })
      await wrapper.find('.job-item').trigger('click')
      expect(mockJobStore.log).toHaveBeenCalledWith({
        id: 123,
        link: 'https://jobs.example.com/123',
        placement: 'sticky_footer_mobile',
        source: 'website',
        page: 'browse-term',
      })
    })

    it('logs click action for analytics', async () => {
      const wrapper = createWrapper({
        position: 2,
        listLength: 10,
        context: 'sidebar',
      })
      await wrapper.find('.job-item').trigger('click')
      expect(mockAction).toHaveBeenCalledWith(
        'job_ad_click',
        expect.objectContaining({
          job_id: 123,
          position: 2,
          list_length: 10,
          context: 'sidebar',
          page: 'browse-term',
        })
      )
    })

    it('captures the current route NAME as page (low cardinality), not the path', async () => {
      // On the jobs page the route name is 'jobs'; /explore/123 would be 'explore-id'
      // etc, so dynamic ids never explode the cardinality of the page dimension.
      mockRouter.currentRoute = { value: { path: '/jobs', name: 'jobs' } }
      const wrapper = createWrapper({ context: 'jobspage' })
      await wrapper.find('.job-item').trigger('click')
      expect(mockJobStore.log).toHaveBeenCalledWith(
        expect.objectContaining({ page: 'jobs' })
      )
      expect(mockAction).toHaveBeenCalledWith(
        'job_ad_click',
        expect.objectContaining({ page: 'jobs' })
      )
    })

    it("falls back to 'unknown' (never the high-cardinality path) when route name is absent", async () => {
      mockRouter.currentRoute = {
        value: { path: '/explore/12345', name: null },
      }
      const wrapper = createWrapper({ context: 'sidebar_left' })
      await wrapper.find('.job-item').trigger('click')
      expect(mockJobStore.log).toHaveBeenCalledWith(
        expect.objectContaining({ page: 'unknown' })
      )
    })

    it('emits clicked with the job id when a job is clicked', async () => {
      const wrapper = createWrapper({ id: 123 })
      await wrapper.find('.job-item').trigger('click')
      expect(wrapper.emitted('clicked')).toBeTruthy()
      expect(wrapper.emitted('clicked')[0]).toEqual([123])
    })

    it('does not call router.push to navigate to /jobs', async () => {
      // Navigation to /jobs was removed in Phase 3; the follow-up modal
      // takes over as the "more jobs" mechanism from the parent (JobsDaSlot).
      const wrapper = createWrapper()
      await wrapper.find('.job-item').trigger('click')
      expect(mockRouter.push).not.toHaveBeenCalled()
    })

    it('contains no link or navigation to /jobs in the template', () => {
      const wrapper = createWrapper()
      // No anchor href pointing to /jobs in the rendered output.
      const links = wrapper.findAll('a')
      links.forEach((link) => {
        expect(link.attributes('href')).not.toBe('/jobs')
      })
    })
  })

  describe('hover handling', () => {
    it('logs hover action once', async () => {
      const wrapper = createWrapper({ position: 1, listLength: 5 })
      await wrapper.find('.job-item').trigger('mouseenter')
      expect(mockAction).toHaveBeenCalledWith(
        'job_ad_hover',
        expect.objectContaining({
          job_id: 123,
          position: 1,
          list_length: 5,
        })
      )
    })

    it('does not log hover action twice', async () => {
      const wrapper = createWrapper()
      await wrapper.find('.job-item').trigger('mouseenter')
      await wrapper.find('.job-item').trigger('mouseenter')
      const hoverCalls = mockAction.mock.calls.filter(
        (call) => call[0] === 'job_ad_hover'
      )
      expect(hoverCalls.length).toBe(1)
    })
  })

  describe('icon styles', () => {
    it('applies dark green background by default', () => {
      const wrapper = createWrapper({ summary: true })
      const icon = wrapper.find('.job-icon')
      expect(icon.attributes('style')).toContain('#2d5016')
    })

    it('applies custom bgColour', () => {
      const wrapper = createWrapper({
        summary: true,
        bgColour: 'soft sage green',
      })
      const icon = wrapper.find('.job-icon')
      expect(icon.attributes('style')).toContain('#7a9e7e')
    })
  })

  describe('external link', () => {
    it('links to job URL', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.external-link').attributes('href')).toBe(
        'https://jobs.example.com/123'
      )
    })
  })

  describe('filterNonsense utility', () => {
    it('cleans up escaped newlines', () => {
      mockJobStore.byId.mockReturnValue({
        ...mockJob,
        title: 'Job\\nTitle',
      })
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-title').text()).toBe('Job\nTitle')
    })

    it('cleans up HTML br tags', () => {
      mockJobStore.byId.mockReturnValue({
        ...mockJob,
        title: 'Job<br>Title',
      })
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-title').text()).toBe('Job\nTitle')
    })

    it('cleans up currency encoding', () => {
      mockJobStore.byId.mockReturnValue({
        ...mockJob,
        title: 'Â£50k Salary',
      })
      const wrapper = createWrapper({ summary: true })
      expect(wrapper.find('.job-title').text()).toBe('£50k Salary')
    })
  })
})
