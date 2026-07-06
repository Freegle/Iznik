export const meta = {
  name: 'jobs-phase1b-impressions-understand',
  description: 'Map exact integration points for Phase 1b job/playwire impression logging, then spec + critique',
  phases: [
    { title: 'Understand' },
    { title: 'Design' },
    { title: 'Critique' },
  ],
}

const FIND = {
  type: 'object',
  additionalProperties: false,
  properties: {
    summary: { type: 'string' },
    facts: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        properties: { claim: { type: 'string' }, evidence: { type: 'string' } },
        required: ['claim', 'evidence'],
      },
    },
    recommendation: { type: 'string' },
  },
  required: ['summary', 'facts', 'recommendation'],
}

const ROOT = '/home/edward/FreegleDockerWSL'
phase('Understand')

const readers = [
  {
    label: 'jobone-impression-hooks',
    prompt:
      'Read ' + ROOT + '/iznik-nuxt3/components/JobOne.vue FULLY. I am adding a job IMPRESSION beacon (fired when the ad has dwelt in view) for Phase 1b. Find with exact line numbers: (1) Any existing IntersectionObserver / visibility detection and the job_ad_visible (or similar) action call - quote it. (2) Is there a dwell timer already, or must I add one? (3) Search the WHOLE iznik-nuxt3 repo for getSessionId, freegle_session_id, sessionStorage, useSession and report exactly how a stable session id is obtained. (4) Is there an abVariant prop or A/B variant available? (5) How does JobOne talk to the server today (jobStore.log -> JobAPI -> $postv2)? Could the impression reuse that path or use $fetch to /apiv2/job/impression? Return facts with file:line evidence and a recommendation for exactly where/how to fire a per-job impression with type=job, placement (props.context), page (router route name), session, position, list_length.',
  },
  {
    label: 'externalda-fill-detection',
    prompt:
      'Read ' + ROOT + '/iznik-nuxt3/components/ExternalDa.vue FULLY (recently changed to thread a placement prop). For Phase 1b I must fire a NON-job impression (type = playwire|adsense|prebid|fallback) when a non-job fill renders, so job vs Playwire is comparable. Determine: (1) JobsDaSlot/OurPlaywireDa/OurGoogleDa/OurPrebidDa all emit rendered routed to rippleRendered(). At the moment rippleRendered fires, how can I tell WHICH fill rendered? Quote the template v-if/v-else-if chain (playWire/adSense refs) and rippleRendered(). (2) Does ExternalDa have placement (yes) and can it get the page/route name (does it import useRouter)? (3) Is a session id available here? (4) Recommend the cleanest way to fire ONE non-job impression when a non-job fill becomes viewable (checkStillVisible already enforces a 100ms delay) WITHOUT double-firing for the job fill (JobOne logs its own). Return facts with file:line evidence + recommendation.',
  },
  {
    label: 'go-impression-reference',
    prompt:
      'In ' + ROOT + '/iznik-server-go: (1) Read browse/scroll.go END TO END as the reference for a fire-and-forget POST logging endpoint - how it reads the JSON body, validates/bot-filters, obtains userid (auth), runs INSERT ... ON DUPLICATE KEY UPDATE, returns. Quote the handler. (2) Read job/job.go RecordJobClick to see how it gets userid (c.Locals session) - quote that block. (3) Find where /job and /scrolldepth routes are registered (router/routes.go) and quote the lines. (4) Note the package + imports a new RecordJobImpression in job/job.go would need. Return facts with file:line evidence and the exact skeleton to mirror for RecordJobImpression.',
  },
  {
    label: 'session-and-test-patterns',
    prompt:
      'In ' + ROOT + ': (1) iznik-nuxt3 - how is a stable per-session/per-browser id generated and stored? Search for sessionStorage, localStorage, freegle_session_id, getSessionId, uniqueId, useSession; report the exact helper to call from JobOne/ExternalDa. (2) Any existing frontend code that POSTs a fire-and-forget analytics beacon to /apiv2 (search /apiv2/ with POST, $fetch, scrolldepth)? Quote it. (3) In iznik-server-go/test read jobs_test.go and any scroll/browse test to learn the harness for a POST logging endpoint (getApp().Test, CreateTestJob, DBConn.Raw asserts). Return patterns + a recommendation for how the beacon obtains and sends the session id, and how to test RecordJobImpression.',
  },
]

const findings = (
  await parallel(
    readers.map((r) => () => agent(r.prompt, { label: r.label, phase: 'Understand', schema: FIND }))
  )
).filter(Boolean)

phase('Design')
const SPEC = {
  type: 'object',
  additionalProperties: false,
  properties: {
    migration: { type: 'string' },
    goHandler: { type: 'string' },
    joboneChange: { type: 'string' },
    externaldaChange: { type: 'string' },
    sessionMechanism: { type: 'string' },
    tests: { type: 'string' },
    openQuestions: { type: 'array', items: { type: 'string' } },
  },
  required: ['migration', 'goHandler', 'joboneChange', 'externaldaChange', 'sessionMechanism', 'tests', 'openQuestions'],
}
const designInput = JSON.stringify(findings, null, 2)
const designPrompt =
  'Design Phase 1b: job + non-job (Playwire/AdSense/Prebid) impression logging into logs_job_impressions, so revenue-per-impression and job-vs-Playwire are measurable per (placement, page). Findings JSON:\n\n' +
  designInput +
  '\n\nThe plan file ' + ROOT + '/plans/2026-06-29-jobs-optimization-plan.md Phase 1b section already sketches the table (type, placement, page, jobid nullable, variant, position, session, unique key) and the approach - read it and align. Produce a concrete, minimal, correct, idempotent spec: migration (hasTable/hasColumn guards), Go RecordJobImpression mirroring the scroll-depth handler exactly (fire-and-forget, bot filter gated on type=job, type+placement allowlist normalisation, INSERT ON DUPLICATE KEY), route registration, JobOne dwell-confirmed type=job beacon, ExternalDa per-fill non-job beacon (no double count), session mechanism, and tests. Be explicit about every file and line touched. NOTE: MySQL treats NULL as distinct in UNIQUE keys, so a UNIQUE key including a nullable jobid will NOT dedupe non-job rows; account for this.'
const spec = await agent(designPrompt, { label: 'design-spec', phase: 'Design', schema: SPEC })

phase('Critique')
const CRIT = {
  type: 'object',
  additionalProperties: false,
  properties: {
    verdict: { type: 'string', enum: ['sound', 'needs-changes'] },
    problems: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        properties: {
          issue: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
          fix: { type: 'string' },
        },
        required: ['issue', 'severity', 'fix'],
      },
    },
    finalRecommendation: { type: 'string' },
  },
  required: ['verdict', 'problems', 'finalRecommendation'],
}
const lenses = ['double-counting-and-dedup', 'bot-filter-and-nullable-jobid', 'session-and-frontend-hotpath', 'test-completeness']
const critiques = (
  await parallel(
    lenses.map((lens) => () =>
      agent(
        'Adversarially critique this Phase 1b impression-logging spec through the lens of ' + lens + '. Spec:\n\n' + JSON.stringify(spec) + '\n\nFindings:\n\n' + designInput + '\n\nHunt for: double-counting (job impression fired by BOTH JobOne and ExternalDa; the same fill firing twice on re-intersection/re-render; the UNIQUE key not preventing dupes because jobid is NULL for non-job fills and MySQL treats NULL as distinct); bot/empty filter dropping legitimate non-job impressions that have no jobid; nullable jobid handling in the Go INSERT; firing on the ad hot path causing perf issues; session id absent on SSR; tests not proving dedup or the playwire path. Default severity major if unsure. Return verdict, problems, finalRecommendation.',
        { label: 'critique:' + lens, phase: 'Critique', schema: CRIT }
      )
    )
  )
).filter(Boolean)

return { findings, spec, critiques }
