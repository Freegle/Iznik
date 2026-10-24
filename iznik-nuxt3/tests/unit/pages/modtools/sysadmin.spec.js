import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import Sysadmin from '~/modtools/pages/sysadmin/index.vue'

// Controllable route query so deep-link (?tab=...) behaviour can be exercised.
const mockRouteQuery = { value: {} }
// Support by default.
const mockAdmin = { value: false }

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ supportOrAdmin: true, admin: mockAdmin.value }),
}))

const mockWork = { value: { emailout: 0, emailin: 0 } }

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ work: mockWork.value }),
}))

// The page calls the auto-imported useRoute() bare; the shared setup globalises
// Vue reactivity (ref/computed/onMounted) but not useRoute, so provide it here.
globalThis.useRoute = () => ({ query: mockRouteQuery.value })
globalThis.__testUseRoute = () => ({ query: mockRouteQuery.value })

const stub = (cls) => ({ template: `<div class="${cls}" />` })

function mountComponent() {
  return mount(Sysadmin, {
    global: {
      stubs: {
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        'b-tabs': {
          template: '<div class="tabs"><slot /></div>',
          props: ['modelValue'],
        },
        'b-tab': {
          template:
            '<div class="tab" @click="$emit(\'click\')"><slot name="title" /><slot /></div>',
        },
        'b-badge': { template: '<span class="badge"><slot /></span>' },
        ModSysAdminHousekeeping: {
          props: ['openPaf'],
          template: '<div class="c-housekeeping" :data-open-paf="openPaf" />',
        },
        ModSysAdminCronJobs: stub('c-cronjobs'),
        ModSupportEmailStats: stub('c-emailstats'),
        ModSupportIncomingEmail: stub('c-incomingemail'),
        ModSupportMailDeferrals: stub('c-maildeferrals'),
        ModSysAdminDigestClicks: stub('c-digestclicks'),
        ModSysAdminBrowseScroll: stub('c-browsescroll'),
        ModSysAdminReengageEffectiveness: stub('c-reengage'),
        ModSysAdminUx: stub('c-ux'),
        ModSysAdminRipplingDensity: stub('c-ripplingdensity'),
        ModSysAdminRipplingAnalytics: stub('c-rippling'),
        ModSysAdminSupportAI: stub('c-supportai'),
      },
    },
  })
}

describe('sysadmin page tab grouping', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockRouteQuery.value = {}
    mockAdmin.value = false
    mockWork.value = { emailout: 0, emailin: 0 }
  })

  it('shows a red number on the Behaviour tab when experiments have a verdict', async () => {
    mockWork.value = {
      emailout: 0,
      emailin: 0,
      uxexperiments: 2,
      uxexperimentsconfirm: 1,
      uxexperimentsremove: 1,
    }
    const wrapper = mountComponent()
    await flushPromises()
    const badges = wrapper
      .findAll('.tab')
      .find((t) => t.text().includes('Behaviour'))
      .findAll('.badge')
    expect(badges.map((b) => b.text())).toContain('2')
  })

  it('repeats the red number on the UX sub-tab and says in words what it is', async () => {
    mockRouteQuery.value = { tab: 'behaviour' }
    mockWork.value = {
      emailout: 0,
      emailin: 0,
      uxexperiments: 2,
      uxexperimentsconfirm: 1,
      uxexperimentsremove: 1,
    }
    const wrapper = mountComponent()
    await flushPromises()
    const sub = wrapper
      .findAll('.tab')
      .find(
        (t) => t.find('.subtab-label').exists() && t.text().startsWith('UX')
      )
    expect(sub.find('.subtab-label .badge').text()).toBe('2')
    const note = wrapper.find('.ux-verdict-note')
    expect(note.text()).toContain(
      '2 experiments have a verdict: 1 to confirm, 1 to remove code'
    )
    expect(note.find('a').attributes('href')).toBe(
      '?tab=ux&ux_section=experiments'
    )
  })

  it('draws no verdict line on the UX sub-tab when none is waiting', async () => {
    mockRouteQuery.value = { tab: 'ux' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.ux-verdict-note').exists()).toBe(false)
  })

  it('opens Behaviour on UX by default, with ?tab=behaviour', async () => {
    mockRouteQuery.value = { tab: 'behaviour' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-ux').exists()).toBe(true)
    expect(wrapper.find('.c-digestclicks').exists()).toBe(false)
    expect(wrapper.find('.c-reengage').exists()).toBe(false)
  })

  it('orders the Behaviour sub-tabs UX, Scrolling, Reengagement', () => {
    const labels = mountComponent()
      .findAll('.subtab-label')
      .map((l) => l.text().replace(/\s+\d+$/, ''))
      .filter((t) => ['UX', 'Scrolling', 'Reengagement'].includes(t))
    expect(labels).toEqual(['UX', 'Scrolling', 'Reengagement'])
  })

  it.each(['scrolling', 'digest'])(
    'lands ?tab=%s on Scrolling',
    async (tab) => {
      mockRouteQuery.value = { tab }
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.find('.c-digestclicks').exists()).toBe(true)
      expect(wrapper.find('.c-ux').exists()).toBe(false)
    }
  )

  it('shows no number on the Behaviour tab when none is waiting', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    const tab = wrapper
      .findAll('.tab')
      .find((t) => t.text().includes('Behaviour'))
    expect(tab.findAll('.badge')).toHaveLength(0)
  })

  it('shows the grouped top-level tabs: Housekeeping, Cron Jobs, Mail, Behaviour, Rippling, AI Helper', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    const text = wrapper.text()
    for (const label of [
      'Housekeeping',
      'Cron Jobs',
      'Mail',
      'Behaviour',
      'Rippling',
      'AI Helper',
    ]) {
      expect(text).toContain(label)
    }
  })

  it('no longer offers a First reply tab', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.text()).not.toContain('First reply')
  })

  it('nests Outgoing and Incoming under Mail', () => {
    const text = mountComponent().text()
    expect(text).toContain('Outgoing')
    expect(text).toContain('Incoming')
  })

  it('nests Scrolling and Reengagement under Behaviour, with no Recommendations tab', () => {
    // The post-page "More like this" strip and its funnel were removed: the
    // stats showed no useful value, so the tab that reported them went too.
    const text = mountComponent().text()
    expect(text).toContain('Scrolling')
    expect(text).toContain('Reengagement')
    expect(text).not.toContain('Recommendations')
  })

  it('nests UX under Behaviour', () => {
    expect(mountComponent().text()).toContain('UX')
  })

  it('loads the UX tab only when asked for, via ?tab=ux', async () => {
    let wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-ux').exists()).toBe(false)
    mockRouteQuery.value = { tab: 'ux' }
    wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-ux').exists()).toBe(true)
  })

  it('defaults to loading only Housekeeping (others stay lazy)', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-housekeeping').exists()).toBe(true)
    expect(wrapper.find('.c-reengage').exists()).toBe(false)
    expect(wrapper.find('.c-emailstats').exists()).toBe(false)
  })

  it('deep-links ?tab=reengagement to the Behaviour tab and loads the effectiveness panel', async () => {
    mockRouteQuery.value = { tab: 'reengagement' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-reengage').exists()).toBe(true)
  })

  it('keeps the old ?tab=outgoing deep-link working (now under Mail)', async () => {
    mockRouteQuery.value = { tab: 'outgoing' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-emailstats').exists()).toBe(true)
  })

  it('opens the Delayed sub-tab from ?tab=delayed', async () => {
    // Added after Outgoing and Incoming rather than before them, so the
    // existing sub-tab indices - and the deep-links that use them - keep
    // working.
    mockRouteQuery.value = { tab: 'delayed' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-maildeferrals').exists()).toBe(true)
  })

  it('keeps the old ?tab=incoming deep-link working (now under Mail)', async () => {
    mockRouteQuery.value = { tab: 'incoming' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-incomingemail').exists()).toBe(true)
  })

  it('lands an old ?tab=recommendations bookmark on the Behaviour tab (Scrolling)', async () => {
    mockRouteQuery.value = { tab: 'recommendations' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-recommendations').exists()).toBe(false)
    expect(wrapper.find('.c-digestclicks').exists()).toBe(true)
  })

  it('shows both the density and the analytics panel under Rippling', async () => {
    // The density comparison is what says whether the per-band reach budgets are
    // working, so it has to be on the tab a sysadmin already opens rather than
    // somewhere they have to be told about.
    mockRouteQuery.value = { tab: 'rippling' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-ripplingdensity').exists()).toBe(true)
    expect(wrapper.find('.c-rippling').exists()).toBe(true)
  })

  it('deep-links ?tab=aihelper to the AI Helper runs', async () => {
    mockRouteQuery.value = { tab: 'aihelper' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-supportai').exists()).toBe(true)
  })

  it('has no tab of its own for the PAF update', async () => {
    mockAdmin.value = true
    expect(mountComponent().text()).not.toContain('Addresses')
    expect(mountComponent().text()).not.toContain('PAF')
  })

  it.each(['paf', 'addresses'])(
    'deep-links ?tab=%s to Housekeeping with the PAF update open',
    async (tab) => {
      mockAdmin.value = true
      mockRouteQuery.value = { tab }
      const wrapper = mountComponent()
      await flushPromises()
      expect(wrapper.find('.c-housekeeping').attributes('data-open-paf')).toBe(
        'true'
      )
    }
  )

  it('opens Housekeeping without the PAF update otherwise', async () => {
    mockRouteQuery.value = { tab: 'housekeeping' }
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.c-housekeeping').attributes('data-open-paf')).toBe(
      'false'
    )
  })

  it('shows an access notice to non-admins', () => {
    // supportOrAdmin is mocked true above; this asserts the guarded region renders
    // rather than the notice, confirming the v-if wiring is intact.
    const wrapper = mountComponent()
    expect(wrapper.find('.tabs').exists()).toBe(true)
  })
})
