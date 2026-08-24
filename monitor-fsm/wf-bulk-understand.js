export const meta = {
  name: 'bulk-offer-feature-understand',
  description: 'Map the PR 618 bulk-offer feature end to end (poster, replier, mod, lifecycle) for docs + PR text + video',
  phases: [{ title: 'Understand' }, { title: 'Synthesize' }],
}

const FIND = {
  type: 'object',
  additionalProperties: false,
  properties: {
    summary: { type: 'string' },
    steps: {
      type: 'array',
      description: 'ordered user-visible steps in plain English for this perspective',
      items: {
        type: 'object',
        additionalProperties: false,
        properties: {
          step: { type: 'string' },
          how: { type: 'string', description: 'what the user does / sees' },
          behindScenes: { type: 'string', description: 'what the code does, with file:line' },
        },
        required: ['step', 'how', 'behindScenes'],
      },
    },
    components: { type: 'array', items: { type: 'string' }, description: 'key files/components for this perspective' },
    gotchas: { type: 'array', items: { type: 'string' } },
  },
  required: ['summary', 'steps', 'components', 'gotchas'],
}

const BR = 'origin/feature/bulk-offer-clearance'
const NUXT = '/home/edward/FreegleDockerWSL/iznik-nuxt3'
const GO = '/home/edward/FreegleDockerWSL/iznik-server-go'
const note = 'The PR branch is ' + BR + '; to read a file as it exists on the branch use: git show ' + BR + ':<path>. The current working tree is on a DIFFERENT branch, so read via git show, not the working copy.'

phase('Understand')

const readers = [
  {
    label: 'poster-compose-flow',
    prompt: note + '\n\nMap how a POSTER creates a bulk/clearance offer in PR 618. Read (via git show ' + BR + ':PATH): ' + NUXT + '/composables/useBulkItems.js (the spreadsheet/paste parser), ' + NUXT + '/components/BulkItemEditor.vue, ' + NUXT + '/stores/compose.js (bulk handling, search photourl/bulk), and the Go create path ' + GO + '/message/message.go around lines 3300-3360 and 3880-3920 (where bulk items + ingestBulkItemPhotos are handled) and ' + GO + '/message/bulkItem.go upsertBulkItems. Explain in PLAIN ENGLISH: how does a poster enter many items at once (paste a spreadsheet? columns: name/quantity/condition/dimensions/photourl/description)? How are photos handled? What does posting do? Return ordered steps (what the poster does + behindScenes file:line), key components, gotchas.',
  },
  {
    label: 'replier-interest-flow',
    prompt: note + '\n\nMap how a REPLIER expresses interest in items of a bulk offer in PR 618. Read: ' + NUXT + '/components/BulkItemsInterest.vue, ' + NUXT + '/components/BulkInterestEditor.vue, and Go ' + GO + '/message/bulkItem.go handleBulkInterest + LoadBulkItems + the messages_bulk_items_interest model. Explain in PLAIN ENGLISH: how does a replier pick which items and quantities they want, say when they can collect (cancollect), and what happens (a single consolidated "Interested" chat message to the giver; per-item interest rows; re-express updates not spams). What can the replier see of others interest (privacy)? Return ordered steps (replier does + behindScenes file:line), key components, gotchas.',
  },
  {
    label: 'giver-handling-and-collection',
    prompt: note + '\n\nMap how the GIVER/offerer HANDLES replies and arranges COLLECTION in PR 618. Read Go ' + GO + '/message/bulkItem.go (interest states: Interested/Reserved/Collected/Rejected/Withdrawn; HandleBulkInterestStateBatch; routes /message/bulk/state) and frontend ' + NUXT + '/components/ClearanceManageItem.vue + any ClearanceManage*/BulkManage* component (find them: git grep -l Clearance ' + BR + '). Explain in PLAIN ENGLISH: how does the giver see who wants what, reserve items for people, mark items collected/rejected, handle quantities across multiple repliers, and set/agree collection times? How are repliers notified of state changes? Return ordered steps (giver does + behindScenes file:line), key components, gotchas.',
  },
  {
    label: 'mod-modtools-flow',
    prompt: note + '\n\nMap what MODS see and do with bulk/clearance offers in PR 618. Find and read the ModTools components: git grep -l -iE "bulk|clearance" ' + BR + ' -- ' + NUXT + '/modtools ; read ' + NUXT + '/modtools/components/ModBulkPreviewModal.vue and any mod bulk view, plus how a bulk offer appears in the mod message view/approval queue (does it show the item catalogue? interest counts?). Explain in PLAIN ENGLISH what a mod sees when moderating/approving a bulk offer and any mod-only actions. Return ordered steps (mod sees/does + behindScenes file:line), key components, gotchas.',
  },
  {
    label: 'pr-scope-inventory',
    prompt: note + '\n\nInventory WHAT IS IN PR 618 for a descriptive (non-historical) PR write-up. Run: git diff --stat origin/master..' + BR + ' and group the changed files by area (DB migrations for messages_bulk_items + messages_bulk_items_interest; Go message/bulkItem.go + routes; Nuxt compose + interest + manage components; ModTools; batch/Laravel commands e.g. ImportOrgsCommand, notification/digest changes; tests). Read the migration(s) that create the bulk tables (git grep -l bulk_items ' + BR + ' -- iznik-batch/database/migrations) and summarise the schema. Return: a structured inventory of the PR contents by area (so a reader knows WHAT the PR adds), key components, and gotchas. Use the steps array to list each area as one entry (step=area name, how=what it adds, behindScenes=key files).',
  },
]

const findings = (
  await parallel(readers.map((r) => () => agent(r.prompt, { label: r.label, phase: 'Understand', schema: FIND })))
).filter(Boolean)

phase('Synthesize')
const OUT = {
  type: 'object',
  additionalProperties: false,
  properties: {
    posterDocOutline: { type: 'array', items: { type: 'string' } },
    replierDocOutline: { type: 'array', items: { type: 'string' } },
    modDocOutline: { type: 'array', items: { type: 'string' } },
    prDescriptionOutline: { type: 'array', items: { type: 'string' } },
    videoShotList: { type: 'array', items: { type: 'string' } },
    openQuestions: { type: 'array', items: { type: 'string' } },
  },
  required: ['posterDocOutline', 'replierDocOutline', 'modDocOutline', 'prDescriptionOutline', 'videoShotList', 'openQuestions'],
}
const syn = await agent(
  'From these per-perspective findings on the PR 618 bulk-offer feature, produce outlines (bullet lists) for: (a) a plain-English poster doc, (b) a plain-English replier doc, (c) a plain-English mod doc, (d) a DESCRIPTIVE (not historical) PR description, and (e) a video shot list covering compose -> reply -> giver handling -> collection -> ModTools. Keep everything concrete and grounded in the findings. Findings JSON:\n\n' + JSON.stringify(findings, null, 2),
  { label: 'synthesize', phase: 'Synthesize', schema: OUT }
)

return { findings, syn }
