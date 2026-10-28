// The sections of the SysAdmin UX tab, in one explicit list. A section is a row in the nav, and
// the tab mounts only the open one. Another part of the UX work adds a section by importing its
// component here and adding one entry; nothing scans for files.
import ModSysAdminUxOverview from '~/modtools/components/ModSysAdminUxOverview.vue'
import ModSysAdminUxFunnels from '~/modtools/components/ModSysAdminUxFunnels.vue'
import ModSysAdminUxExperiments from '~/modtools/components/ModSysAdminUxExperiments.vue'
import ModSysAdminUxExperimentsBadge from '~/modtools/components/ModSysAdminUxExperimentsBadge.vue'
import ModSysAdminUxSurveys from '~/modtools/components/ModSysAdminUxSurveys.vue'
import ModSysAdminUxPages from '~/modtools/components/ModSysAdminUxPages.vue'
import ModSysAdminUxReplyConfirmDetail from '~/modtools/components/ModSysAdminUxReplyConfirmDetail.vue'
import ModSysAdminUxInterestedDetail from '~/modtools/components/ModSysAdminUxInterestedDetail.vue'

// A section has:
//   key        the value of ux_section
//   label      the words in the nav
//   order      where it sits in the nav: overview 10, funnels 20, experiments 30, members 40,
//              ideas 50, pages 90. Pages is last, so other sections sit between Funnels and it.
//   component  drawn with the prop `view` (see useUxView) and the event `navigate(patch)`
//   filters    which of period, device and signed the section uses. The filter bar shows only those,
//              and the tab sends only those to the server. With none the bar is hidden.
//   slots      the reads in the store that the tab loads for the section (see stores/ux.js). A
//              section that loads its own data from its own store lists none.
//   params     the short names of the address keys the section owns, written with ux_ in front.
//              They are cleared when the person leaves the section.
// A section may also have:
//   badge      a component drawn after its label in the nav. It loads its own data and may draw
//              nothing.
//   funnelLink { label }: adds a link with this label to "Also for this funnel" in the Funnels
//              section, which opens the section for that funnel.
export const SECTIONS = [
  {
    key: 'overview',
    label: 'Overview',
    order: 10,
    component: ModSysAdminUxOverview,
    filters: ['period', 'device'],
    slots: ['health', 'funnels', 'outcomes'],
    params: ['open'],
  },
  {
    // The Funnels section loads the field figures itself once it knows which funnel is open.
    key: 'funnels',
    label: 'Funnels',
    order: 20,
    component: ModSysAdminUxFunnels,
    filters: ['period', 'device', 'signed'],
    slots: ['health', 'funnels', 'outcomes'],
    params: ['step', 'open'],
  },
  {
    // The experiments, and a form to make one by hand. It loads its own data from its own store.
    key: 'experiments',
    label: 'Experiments',
    order: 30,
    component: ModSysAdminUxExperiments,
    badge: ModSysAdminUxExperimentsBadge,
    funnelLink: { label: 'Experiments' },
    filters: [],
    slots: [],
    params: ['exp', 'estate'],
  },
  {
    // The problems members raised, what works for them, and the questions asked. It loads its own data
    // from its own store.
    key: 'members',
    label: 'What members say',
    order: 40,
    component: ModSysAdminUxSurveys,
    funnelLink: { label: 'What members say' },
    filters: [],
    slots: [],
    params: ['view', 'group', 'problem', 'q', 'question'],
  },
  {
    key: 'pages',
    label: 'Pages and features',
    order: 90,
    component: ModSysAdminUxPages,
    filters: ['period', 'device'],
    slots: ['health', 'friction', 'adoption', 'regret', 'search'],
    params: ['open'],
  },
]

// Components that other parts of the tab add to a place that draws them without knowing them. The details of
// every experiment draw the ones under `experiment`, so each draws nothing unless the experiment is its own.
export const HOSTED = {
  problem: [],
  experiment: [ModSysAdminUxReplyConfirmDetail, ModSysAdminUxInterestedDetail],
}

export const uxSections = () => [...SECTIONS].sort((a, b) => a.order - b.order)
export const hostedFor = (slot) => HOSTED[slot] || []
