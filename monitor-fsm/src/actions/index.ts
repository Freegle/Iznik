import type { ActionDefinition } from 'ai-flower'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { readFile, writeFile } from 'node:fs/promises'
import { existsSync, readdirSync, readlinkSync } from 'node:fs'
import { out, outWarn, dbg, startGroup, endGroup, truncate } from '../log.js'
import { DISCOURSE_BASE } from '../discourse.js'
import {
  getDb,
  getTopicCursor,
  setTopicCursor,
  listTopicCursors,
  kvGet,
  kvSet,
  queueDiscourseDraft,
  recordPostedReply,
  cancelDraftsForBug,
  insertReviewerFeedback,
  listUnprocessedFeedback,
  upsertDiscourseBug,
  reopenBugAfterRejection,
  upsertPr,
  findTagDuplicate,
  tagJaccard,
} from '../db/index.js'
import { renderAllViews } from '../db/views.js'
import { getPhaseInfo } from '../phase.js'
import { modelForAdversarialReview } from '../policy.js'

const exec = promisify(execFile)

const STATE_PATH = '/tmp/freegle-monitor/state.json'
const SUMMARY_PATH = '/tmp/freegle-monitor/summary.md'
const DRAFTS_PATH = '/tmp/freegle-monitor/retest-drafts.md'
const USER_FEEDBACK_PATH = '/tmp/freegle-monitor/user_feedback.md'

// Resolve the `claude` CLI binary once. The Node parent's PATH isn't always
// inherited the way an interactive shell expects (systemd, cron, npm script
// launchers strip it), so relying on `spawn('claude', …)` to find it via PATH
// produced ENOENT crashes mid-iteration. Prefer $CLAUDE_BIN, then the user's
// ~/.local/bin install (which is where `which claude` resolves to in practice),
// and only fall back to the bare name if neither is present.
function resolveClaudeBin(): string {
  if (process.env.CLAUDE_BIN && existsSync(process.env.CLAUDE_BIN)) {
    return process.env.CLAUDE_BIN
  }
  const home = process.env.HOME
  if (home) {
    const localBin = `${home}/.local/bin/claude`
    if (existsSync(localBin)) return localBin
  }
  return 'claude'
}
const CLAUDE_BIN = resolveClaudeBin()

// Redact obvious credentials from strings we display on screen or stash in
// context (the stdoutTail fed back to the FSM). The delegate is explicitly
// allowed to read ~/.circleci/cli.yml and put the token into a Bash env var
// — that's legitimate work. But the tool-use event includes the full command
// as `input.command`, so the raw token flows onto the terminal and into the
// FSM's context unless we scrub here. Debug.log keeps the raw values so
// post-hoc investigation still works.
function redactSecrets(s: string): string {
  if (!s) return s
  return s
    .replace(/\b(CIRCLECI_TOKEN|GITHUB_TOKEN|SENTRY_AUTH_TOKEN|SMTP_PASS|OPENAI_API_KEY|ANTHROPIC_API_KEY)=\S+/g, '$1=<redacted>')
    .replace(/(Authorization:\s*(?:bearer|token)\s+)\S+/gi, '$1<redacted>')
    .replace(/(-u\s+[^:\s]+:)\S+/g, '$1<redacted>')
    .replace(/\bCCIPAT_[A-Za-z0-9_-]+/g, 'CCIPAT_<redacted>')
    .replace(/\bsk-ant-[A-Za-z0-9_-]+/g, 'sk-ant-<redacted>')
    .replace(/\bghp_[A-Za-z0-9]+/g, 'ghp_<redacted>')
    .replace(/\bghs_[A-Za-z0-9]+/g, 'ghs_<redacted>')
}

async function sh(cmd: string, args: string[], cwd?: string): Promise<{ stdout: string; stderr: string; code: number }> {
  try {
    const { stdout, stderr } = await exec(cmd, args, { cwd, maxBuffer: 20 * 1024 * 1024 })
    return { stdout, stderr, code: 0 }
  } catch (err: any) {
    return {
      stdout: err.stdout ?? '',
      stderr: err.stderr ?? String(err),
      code: err.code ?? 1,
    }
  }
}

const PROD_REPO = 'Freegle/Iznik'
// Netlify site for the Freegle app (frontend). Site ID and slug both work with the public API.
const NETLIFY_SITE = 'golden-caramel-d2c3a7.netlify.app'

/**
 * Post a reply to a Discourse topic, threaded under a specific post.
 *
 * Used to auto-post the verified-live "fix applied, please retest" reply once a
 * merged PR's fix is confirmed live in production. reply_to_post_number threads
 * the reply under the SPECIFIC reporting post (only meaningful for post > 1; a
 * reply to the OP is a normal topic reply and Discourse normalises it).
 */
/**
 * How long to wait before retrying a Discourse 429. Prefers the Retry-After
 * header, falls back to the rate-limit body's `extras.wait_seconds`, then a
 * sensible default. Returns seconds.
 */
export function parseRetryAfter(header: string | null, body: string): number {
  const fromHeader = header ? Number(header) : NaN
  if (Number.isFinite(fromHeader) && fromHeader > 0) return fromHeader
  try {
    const j = JSON.parse(body) as { extras?: { wait_seconds?: unknown } }
    const w = Number(j?.extras?.wait_seconds)
    if (Number.isFinite(w) && w > 0) return w
  } catch { /* body not JSON */ }
  return 5
}

// Bot accounts whose PR comments are never a human review signal.
const BOT_COMMENT_LOGINS = new Set([
  'netlify', 'github-actions', 'codecov', 'codecov-commenter', 'coderabbitai',
  'sonarcloud', 'dependabot', 'vercel',
])

// Phrases a human uses when closing an FSM PR to mean "this is NOT an actionable
// code bug — do not try again", as opposed to "the fix is wrong, redo it" (for
// which they leave the PR open with changes requested). On a CLOSED PR these park
// the bug instead of triggering the retry-once-then-escalate path.
const WONTFIX_CLOSE_PATTERNS = [
  'no fix required', 'no fix needed', 'no fix is required', 'no fix is needed',
  'not a bug', 'not a code bug', "isn't a bug", 'not a real bug',
  'no actionable', 'not actionable', 'no action required', 'no action needed',
  'wontfix', "won't fix", 'wont fix', 'will not fix',
  'working as designed', 'working as intended', 'by design', 'as designed',
  'perception complaint', 'bad fix',
]

// Marker the FSM puts in its OWN auto-close comments (failed adversarial review) so
// detectWontfixClose can tell them apart from a human "not a bug" close: the FSM's
// auto-close means "retry then escalate", a human wontfix means "park, never retry".
export const FSM_AUTOCLOSE_MARKER = '<!-- fsm-auto-close: failed-review -->'

/**
 * Build the comment for auto-closing an FSM fix PR that failed adversarial review.
 * Lists the blockers and carries FSM_AUTOCLOSE_MARKER so it is not mistaken for a
 * human wontfix close (which would wrongly park the bug instead of retrying it).
 */
export function buildFailedReviewCloseComment(
  prNumber: number,
  issues: Array<{ category?: string; description?: string; severity?: string }>,
): string {
  const blockers = (issues ?? []).filter((i) => i?.severity === 'error')
  const body = blockers.length
    ? blockers.map((b) => `- **${b.category ?? 'issue'}**: ${(b.description ?? '').slice(0, 300)}`).join('\n')
    : '- (no specific blockers recorded)'
  return [
    `Auto-closed by the monitor's adversarial review (PR #${prNumber}) — this fix did not pass and is not ready to merge.`,
    '',
    body,
    '',
    'The underlying bug will be re-attempted, then escalated for human triage if it keeps failing review. Reopen if this was closed in error.',
    FSM_AUTOCLOSE_MARKER,
  ].join('\n')
}

/**
 * Did a human leave a "this is not an actionable bug" signal on a (closed) PR?
 * Scans non-bot comments for a WONTFIX_CLOSE_PATTERN. Returns the matched comment
 * excerpt so the bug's reason can quote the human's decision.
 */
export function detectWontfixClose(
  comments: Array<{ author?: { login?: string } | null; body?: string | null }>,
): { wontfix: boolean; reason?: string } {
  for (const c of comments ?? []) {
    const login = (c?.author?.login ?? '').toLowerCase()
    if (!login || BOT_COMMENT_LOGINS.has(login) || login.endsWith('[bot]')) continue
    const body = c?.body ?? ''
    // The FSM's own auto-close (failed review) means retry, not park — never treat
    // it as a human wontfix even though it quotes blocker text that may match.
    if (body.includes(FSM_AUTOCLOSE_MARKER)) continue
    const hay = body.toLowerCase()
    if (WONTFIX_CLOSE_PATTERNS.some((p) => hay.includes(p))) {
      return { wontfix: true, reason: body.replace(/\s+/g, ' ').trim().slice(0, 200) }
    }
  }
  return { wontfix: false }
}

export async function postDiscourseReply(
  topicId: number,
  raw: string,
  replyToPostNumber?: number,
  opts: { maxRetries?: number; sleepFn?: (ms: number) => Promise<void> } = {},
): Promise<{ ok: boolean; error?: string }> {
  const maxRetries = opts.maxRetries ?? 4
  const sleepFn = opts.sleepFn ?? ((ms: number) => new Promise<void>((r) => setTimeout(r, ms)))

  let apiKey: string | null = null
  try {
    const profile = JSON.parse(await readFile('/home/edward/profile.json', 'utf8')) as {
      auth_pairs?: Array<{ user_api_key?: string }>
    }
    apiKey = profile.auth_pairs?.[0]?.user_api_key ?? null
  } catch { /* no profile / unreadable */ }
  if (!apiKey) return { ok: false, error: 'no Discourse API key available' }

  const payload: Record<string, unknown> = { topic_id: topicId, raw }
  if (replyToPostNumber && replyToPostNumber > 1) payload.reply_to_post_number = replyToPostNumber

  let lastError = ''
  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      const resp = await fetch(`${DISCOURSE_BASE}/posts.json`, {
        method: 'POST',
        headers: {
          'User-Api-Key': apiKey,
          'Api-Username': 'Edward_Hibbert',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      })
      if (resp.status === 200 || resp.status === 201) return { ok: true }

      const text = await resp.text().catch(() => '')
      lastError = `HTTP ${resp.status}: ${text.slice(0, 300)}`

      // Discourse rate-limits writes hard. Rather than dropping the reply (and
      // hammering on the next iteration), respect Retry-After and back off so the
      // burst self-throttles. Cap the wait so one pathological value can't stall
      // the whole iteration.
      if (resp.status === 429 && attempt < maxRetries - 1) {
        const waitSecs = Math.min(parseRetryAfter(resp.headers.get('Retry-After'), text), 60)
        await sleepFn(waitSecs * 1000)
        continue
      }
      return { ok: false, error: lastError }
    } catch (err) {
      return { ok: false, error: String(err) }
    }
  }
  return { ok: false, error: lastError }
}

/**
 * Compare a commit SHA against a reference SHA using the GitHub compare API.
 * Returns behind_by: how many commits in baseSha are NOT in headSha.
 * behind_by == 0 means headSha contains all of baseSha's history → headSha is "at or past" baseSha.
 */
async function githubBehindBy(baseSha: string, headSha: string): Promise<number> {
  const res = await sh('gh', ['api', `repos/${PROD_REPO}/compare/${baseSha}...${headSha}`, '--jq', '.behind_by'])
  if (res.code !== 0) return -1
  const n = parseInt(res.stdout.trim(), 10)
  return isNaN(n) ? -1 : n
}

async function checkPrDeployed(prNumber: number): Promise<{
  deployed: boolean
  frontendDeployed: boolean | null   // null if PR doesn't touch the frontend
  backendDeployed: boolean
  mergeCommitSha: string | null
  productionSha: string | null
  netlifyCommitSha: string | null
  touched: { frontend: boolean; go: boolean; php: boolean }
  reason: string
}> {
  const notDeployed = (reason: string) => ({
    deployed: false, frontendDeployed: null, backendDeployed: false,
    mergeCommitSha: null, productionSha: null, netlifyCommitSha: null,
    touched: { frontend: false, go: false, php: false }, reason,
  })

  // Get PR merge commit SHA.
  const prRes = await sh('gh', ['api', `repos/${PROD_REPO}/pulls/${prNumber}`, '--jq', '{merge_commit_sha, merged_at}'])
  if (prRes.code !== 0) return notDeployed('Could not fetch PR info')
  let prInfo: { merge_commit_sha: string | null; merged_at: string | null }
  try { prInfo = JSON.parse(prRes.stdout) } catch { return notDeployed('Failed to parse PR info') }
  const mergeCommitSha = prInfo.merge_commit_sha
  if (!mergeCommitSha || mergeCommitSha === 'null') return notDeployed('PR not merged yet')

  // --- Which production areas does this PR touch? ---
  // Go, Laravel (PHP), and the frontend deploy SEPARATELY and via different
  // mechanisms, so we gate each touched area on its OWN live signal. We must NOT
  // use one area's commit as a proxy for another's (Laravel and Go don't deploy
  // together, so laravel_commit says nothing about the live Go build).
  let paths: string[] = []
  const filesRes = await sh('gh', ['pr', 'view', String(prNumber), '--repo', PROD_REPO, '--json', 'files', '--jq', '[.files[].path]'])
  if (filesRes.code === 0) { try { paths = JSON.parse(filesRes.stdout) as string[] } catch { /* leave empty */ } }
  const touchesGo = paths.some(p => p.startsWith('iznik-server-go/'))
  const touchesPhp = paths.some(p => p.startsWith('iznik-batch/') || p.startsWith('iznik-server/'))
  const touchesFrontend = paths.some(p => p.startsWith('iznik-nuxt3/'))

  // --- Live backend commits from /api/version ---
  // commit         = the Go build commit (baked in at build via ldflags)
  // laravel_commit = the deployed Laravel checkout commit (config, refreshed
  //                  every 15 min by deploy:record-commit)
  let goSha: string | null = null
  let laravelSha: string | null = null
  const verRes = await sh('curl', ['-s', '--max-time', '12', 'https://api.ilovefreegle.org/api/version'])
  if (verRes.code === 0) {
    try {
      const v = JSON.parse(verRes.stdout) as { commit?: string; laravel_commit?: string }
      goSha = v.commit && v.commit !== 'unknown' ? v.commit : null
      laravelSha = v.laravel_commit && v.laravel_commit !== 'unknown' ? v.laravel_commit : null
    } catch { /* /api/version returned non-JSON */ }
  }

  const parts: string[] = []

  // Each touched backend area must report a live commit that includes the merge.
  let backendDeployed = true
  if (touchesGo) {
    if (goSha) {
      const behind = await githubBehindBy(mergeCommitSha, goSha)
      const ok = behind === 0
      backendDeployed = backendDeployed && ok
      parts.push(ok
        ? `Go: live ${goSha.slice(0, 8)} includes merge`
        : `Go: live ${goSha.slice(0, 8)} missing ${behind} commits from merge`)
    } else {
      backendDeployed = false
      parts.push('Go: /api/version commit unknown — cannot confirm live deploy')
    }
  }
  if (touchesPhp) {
    if (laravelSha) {
      const behind = await githubBehindBy(mergeCommitSha, laravelSha)
      const ok = behind === 0
      backendDeployed = backendDeployed && ok
      parts.push(ok
        ? `Laravel: live ${laravelSha.slice(0, 8)} includes merge`
        : `Laravel: live ${laravelSha.slice(0, 8)} missing ${behind} commits from merge`)
    } else {
      backendDeployed = false
      parts.push('Laravel: laravel_commit unknown — cannot confirm live deploy')
    }
  }

  // --- Frontend check: Netlify published deploy ---
  // Netlify has its own build queue; published_deploy.commit_ref is what's live.
  let netlifyCommitSha: string | null = null
  let frontendDeployed: boolean | null = null
  if (touchesFrontend) {
    frontendDeployed = false
    try {
      const netlifyRes = await sh('curl', ['-s', `https://api.netlify.com/api/v1/sites/${NETLIFY_SITE}`])
      if (netlifyRes.code === 0) {
        const netlify = JSON.parse(netlifyRes.stdout)
        netlifyCommitSha = netlify?.published_deploy?.commit_ref ?? null
        if (netlifyCommitSha) {
          const netlifyBehindBy = await githubBehindBy(mergeCommitSha, netlifyCommitSha)
          frontendDeployed = netlifyBehindBy === 0
          parts.push(frontendDeployed
            ? 'Netlify: published deploy includes PR'
            : `Netlify: published deploy (${netlifyCommitSha.slice(0, 8)}) does not include PR yet`)
        } else {
          parts.push('Netlify: no published deploy commit — cannot confirm')
        }
      } else {
        parts.push('Netlify: API unavailable — cannot confirm')
      }
    } catch { parts.push('Netlify: API error — cannot confirm') }
  }

  // Overall: every touched, deployable area must be live. If the PR touches none
  // of the three (e.g. monitor-fsm / docs / CI only), there's nothing to verify
  // in production, so treat the merge itself as "deployed".
  const checks: boolean[] = []
  if (touchesGo || touchesPhp) checks.push(backendDeployed)
  if (touchesFrontend) checks.push(frontendDeployed === true)
  if (checks.length === 0) parts.push('no deployable (frontend/Go/PHP) files touched — nothing to verify')
  const deployed = checks.length === 0 ? true : checks.every(Boolean)

  return {
    deployed,
    frontendDeployed,
    backendDeployed,
    mergeCommitSha,
    productionSha: null,
    netlifyCommitSha,
    touched: { frontend: touchesFrontend, go: touchesGo, php: touchesPhp },
    reason: parts.join('; '),
  }
}

const STOP_WORDS = new Set([
  'that', 'this', 'when', 'with', 'from', 'after', 'before', 'have', 'does',
  'should', 'would', 'could', 'member', 'user', 'page', 'click', 'show', 'list',
  'item', 'button', 'modal', 'error', 'issue', 'problem', 'missing', 'wrong',
  'freegle', 'modtools', 'group', 'message', 'chat', 'email', 'post',
])

// DISABLED — this heuristic produced a ~75% false-positive rate (3 of 4 newly
// tracked bug topics auto-marked "fixed" by commits that didn't actually fix
// them, e.g. #9715/#9716/#9719). Generic tokens like "chat"/"group"/"name"/
// "android"/"modtools" routinely match unrelated recent fix commits. The cost
// of running the full diagnose→reproduce→fix path on an occasional already-
// fixed bug is far smaller than silently ignoring real reports. If we want to
// re-enable an auto-fixed shortcut later it needs (at minimum) exact-string
// matching of a distinctive symptom phrase, not bag-of-words keyword overlap.
async function checkGitAlreadyFixed(
  _codeArea: string | null,
  _featureArea: string | null,
  _summary: string,
): Promise<string | null> {
  return null
}

/**
 * Decide which open PRs `close_extra_prs` should sweep.
 *
 * Goal: close OTHER PRs the fix delegate opened for the SAME bug as the
 * expected PR, while preserving every PR for a DIFFERENT bug.
 *
 * Identification of "same bug" uses the topic-post suffix (e.g.
 * `9481-531`) at the end of an FSM-managed branch name. If we can't
 * extract a suffix from the expected branch, we have no reliable way
 * to tell which other PRs are duplicates — be conservative and don't
 * sweep ANY of them. (Previously, an empty suffix caused the filter to
 * fall through and close every recent edwh-authored PR, including
 * unrelated manual PRs. See PR #577 incident on 2026-05-30.)
 *
 * Pure function so it can be unit-tested without spawning gh.
 */
export function selectExtraPrsToClose(
  prs: Array<{ number: number; createdAt: string; headRefName: string; author: { login: string } | null }>,
  expectedPrNumber: number,
  iterationStartTs: string,
  expectedBugBranch: string,
): Array<{ number: number; createdAt: string; headRefName: string; author: { login: string } | null }> {
  const bugSuffix = expectedBugBranch.match(/(\d+-\d+)$/)?.[1] ?? ''
  if (!bugSuffix) return []

  const cutoff = new Date(iterationStartTs)
  return prs.filter(pr => {
    if (pr.number === expectedPrNumber) return false
    if (new Date(pr.createdAt) < cutoff) return false
    if (pr.author?.login !== 'edwh') return false
    if (!pr.headRefName.includes(bugSuffix)) return false
    return true
  })
}

export const actions: ActionDefinition[] = [
  {
    name: 'load_state',
    description: 'Load monitor state from the SQLite DB. Returns {state: {topics, last_email_sent, sentry_last_check}, iterationStartTs}. On first run after the SQLite migration, bootstraps from the legacy /tmp/freegle-monitor/state.json so no cursor is lost.',
    handler: async () => {
      const db = getDb()

      // One-time bootstrap: if DB has no cursors but state.json exists, import.
      const cursorCount = (db.prepare('SELECT COUNT(*) AS c FROM topic_cursor').get() as { c: number }).c
      if (cursorCount === 0) {
        try {
          const raw = await readFile(STATE_PATH, 'utf8')
          const legacy = JSON.parse(raw) as { topics?: Record<string, { last_post?: number }>; last_email_sent?: string; sentry_last_check?: string }
          for (const [tid, entry] of Object.entries(legacy.topics ?? {})) {
            setTopicCursor(db, Number(tid), entry?.last_post ?? 0)
          }
          if (legacy.last_email_sent) kvSet(db, 'last_email_sent', legacy.last_email_sent)
          if (legacy.sentry_last_check) kvSet(db, 'sentry_last_check', legacy.sentry_last_check)
        } catch {
          // No legacy file — fresh start.
        }
      }

      const topics: Record<string, { last_post: number; title: string | null }> = {}
      for (const c of listTopicCursors(db)) {
        topics[String(c.topic_id)] = { last_post: c.last_post_number, title: c.title }
      }
      return {
        state: {
          topics,
          sentry_last_check: kvGet(db, 'sentry_last_check'),
        },
        iterationStartTs: new Date().toISOString(),
      }
    },
  },

  {
    name: 'check_bug_feedback',
    description: 'For each open/investigating/deferred bug in discourse_bug, fetch posts after the original report on that Discourse topic. (A) Detects reporter confirmation of a fix — marks confirmed bugs as fixed. (B) Detects Edward_Hibbert posts indicating fix-in-progress, off-topic/expected-behaviour, or applied fix — updates state accordingly. Returns {checked, markedFixed, markedInvestigating, markedOffTopic}.',
    handler: async () => {
      const db = getDb()
      // Reporter-confirmation scan: open/investigating only
      const bugs = db.prepare(
        "SELECT topic, post, reporter FROM discourse_bug WHERE state IN ('open','investigating')"
      ).all() as Array<{ topic: number; post: number; reporter: string | null }>

      // Edward-post scan: also include deferred (Edward may post "fix on the way" on a deferred bug)
      const allActiveBugs = db.prepare(
        "SELECT topic, post, reporter FROM discourse_bug WHERE state IN ('open','investigating','deferred')"
      ).all() as Array<{ topic: number; post: number; reporter: string | null }>

      if (allActiveBugs.length === 0) return { checked: 0, markedFixed: [], markedInvestigating: [], markedOffTopic: [] }

      const bugsJson = JSON.stringify(bugs)
      const allBugsJson = JSON.stringify(allActiveBugs)

      const script = `
import json, urllib.request, re, sys, time

p = json.load(open('/home/edward/profile.json'))
api_key = p['auth_pairs'][0]['user_api_key']
headers = {'User-Api-Key': api_key, 'Api-Username': 'Edward_Hibbert'}

CONFIRM_RE = re.compile(
    r'\\b(fixed|works? now|working now|confirmed?|thanks?|thankyou|all good|resolved?'
    r'|seems? (?:to be )?(?:fixed|working|ok|good)|unlimited now|no (?:longer|more)'
    r'|great[,!.]?\\s*(?:thanks?)?|perfect|sorted|much better|no issues?'
    r'|fix worked|that worked|worked (?:a treat|fine|now|perfectly)|no problem)\\b',
    re.IGNORECASE
)

# Negation guard (sweep 2026-05-31 rule #1): a reporter can say "thanks but it's
# still broken" / "spoke too soon" / "back again" — CONFIRM_RE matches "thanks"
# but the bug is NOT fixed. If a post matches STILL_BROKEN_RE, it must NOT be
# treated as a fix confirmation, even if CONFIRM_RE also matches.
STILL_BROKEN_RE = re.compile(
    r'(?:still (?:broken|not working|happening|there|occurring|stuck|the same|an issue|a problem|doing)'
    r'|spoke too soon|came back|back again|is back|happening again|not fixed|doesn.?t work'
    r'|did(?:n.?t| not) (?:work|fix)|same (?:problem|issue|thing|error)|no (?:change|difference)'
    r'|(?:never|hasn.?t|has not|not) worked'
    r'|worse|reappear|reoccur|again today|once more)',
    re.IGNORECASE
)

# Edward's posts indicating he is actively working on a fix
EDWARD_IN_PROGRESS_RE = re.compile(
    r'(?:fix|looking|working|will).*(?:on the way|in progress|coming|soon|catch up|next week|look.*into|look.*at this|looking into|working on)'
    r'|(?:possible fix|fix is on the way|fix on the way|fix coming|on the way)'
    r'|(?:i can see the (?:problem|bug|issue))'
    r'|(?:will (?:fix|sort|look at|investigate))',
    re.IGNORECASE
)

# Edward's posts indicating he has applied a fix
EDWARD_FIXED_RE = re.compile(
    r'(?:should be fixed|please retest|let me know how it is|i.ve fixed|fixed this|fixed now'
    r'|this should now work|fix applied|should now work|have fixed|has been fixed)',
    re.IGNORECASE
)

# Edward's posts indicating this is expected/by-design (not a bug)
EDWARD_EXPECTED_RE = re.compile(
    r'(?:this is expected|to be expected|that.s expected|by design|working as (?:intended|designed|expected)'
    r'|not a bug|expected (?:behavior|behaviour)|this is correct|working correctly)',
    re.IGNORECASE
)

def fetch(url, retries=3):
    delay = 2.0
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers=headers)
            return json.load(urllib.request.urlopen(req, timeout=20))
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < retries - 1:
                time.sleep(float(e.headers.get('Retry-After', delay)))
                delay *= 2
                continue
            if e.code == 404:
                return None
            raise
        except Exception:
            return None

bugs = json.loads('''${bugsJson.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}''')
all_bugs = json.loads('''${allBugsJson.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}''')

# Cache topic JSON by topic_id to avoid double-fetching
topic_cache = {}
def get_topic(tid):
    if tid not in topic_cache:
        topic_cache[tid] = fetch(f'${DISCOURSE_BASE}/t/{tid}.json')
    return topic_cache[tid]

def get_posts_after(topic_id, orig_post):
    d = get_topic(topic_id)
    if not d:
        return []
    stream = d.get('post_stream', {}).get('stream', [])
    new_ids = stream[orig_post:]
    if not new_ids:
        return []
    new_posts = []
    for i in range(0, len(new_ids), 50):
        batch = new_ids[i:i+50]
        qs = '&'.join(f'post_ids[]={pid}' for pid in batch)
        pd = fetch(f'${DISCOURSE_BASE}/t/{topic_id}/posts.json?{qs}')
        if pd:
            new_posts.extend(pd.get('post_stream', {}).get('posts', []))
    return new_posts

results = []
edward_updates = []

# Pass A: reporter confirmations (open/investigating only)
for bug in bugs:
    topic_id = bug['topic']
    orig_post = bug['post']
    reporter = bug.get('reporter') or ''

    new_posts = get_posts_after(topic_id, orig_post)
    # Sweep rule #1 (2026-05-31): if ANY non-Edward post in the thread reports the
    # bug is still broken, do NOT confirm a fix — even if an earlier post said
    # "thanks, fixed". (9655/4: post 4 "fix worked", post 5 "still omits pending".)
    any_still_broken = False
    for post in new_posts:
        if post.get('username', '') == 'Edward_Hibbert':
            continue
        t = re.sub(r'<[^>]+>', ' ', post.get('cooked', ''))
        t = re.sub(r'\\s+', ' ', t).strip()
        if STILL_BROKEN_RE.search(t):
            any_still_broken = True
            break

    if not any_still_broken:
        for post in new_posts:
            username = post.get('username', '')
            if username == 'Edward_Hibbert':
                continue
            text = re.sub(r'<[^>]+>', ' ', post.get('cooked', ''))
            text = re.sub(r'\\s+', ' ', text).strip()
            if CONFIRM_RE.search(text) and not STILL_BROKEN_RE.search(text):
                results.append({
                    'topic': topic_id,
                    'post': orig_post,
                    'reporter': reporter,
                    'confirmedBy': username,
                    'confirmPostNumber': post.get('post_number'),
                    'confirmText': text[:200],
                })
                break

# Pass B: Edward's posts (open/investigating/deferred)
for bug in all_bugs:
    topic_id = bug['topic']
    orig_post = bug['post']

    new_posts = get_posts_after(topic_id, orig_post)
    for post in new_posts:
        if post.get('username') != 'Edward_Hibbert':
            continue
        text = re.sub(r'<[^>]+>', ' ', post.get('cooked', ''))
        text = re.sub(r'\\s+', ' ', text).strip()
        post_num = post.get('post_number')
        if EDWARD_EXPECTED_RE.search(text):
            edward_updates.append({'topic': topic_id, 'post': orig_post, 'action': 'off_topic', 'postNumber': post_num, 'text': text[:200]})
            break
        elif EDWARD_FIXED_RE.search(text):
            edward_updates.append({'topic': topic_id, 'post': orig_post, 'action': 'investigating', 'postNumber': post_num, 'text': text[:200]})
            break
        elif EDWARD_IN_PROGRESS_RE.search(text):
            edward_updates.append({'topic': topic_id, 'post': orig_post, 'action': 'investigating', 'postNumber': post_num, 'text': text[:200]})
            break

print(json.dumps({'confirmations': results, 'edwardUpdates': edward_updates}))
`

      const { stdout } = await exec('python3', ['-c', script])
      let parsed: { confirmations: Array<any>; edwardUpdates: Array<any> }
      try { parsed = JSON.parse(stdout.trim() || '{}') } catch { parsed = { confirmations: [], edwardUpdates: [] } }
      const confirmations = parsed.confirmations ?? []
      const edwardUpdates = parsed.edwardUpdates ?? []

      for (const c of confirmations) {
        db.prepare(
          "UPDATE discourse_bug SET state='fixed', fixed_at=datetime('now'), reason=? WHERE topic=? AND post=?"
        ).run(
          `Confirmed by ${c.confirmedBy} (post ${c.confirmPostNumber}): "${c.confirmText.slice(0, 120)}"`,
          c.topic, c.post
        )
        out(`check_bug_feedback: marked ${c.topic}/${c.post} fixed — confirmed by ${c.confirmedBy}`)
      }

      const markedInvestigating: Array<{ topic: number; post: number; reason: string }> = []
      const markedOffTopic: Array<{ topic: number; post: number; reason: string }> = []

      for (const u of edwardUpdates) {
        const current = db.prepare('SELECT state FROM discourse_bug WHERE topic=? AND post=?').get(u.topic, u.post) as { state: string } | undefined
        if (!current) continue
        // Don't downgrade fixed/confirmed/fix-queued bugs
        if (['fixed', 'confirmed', 'fix-queued'].includes(current.state)) continue

        if (u.action === 'off_topic') {
          db.prepare(
            "UPDATE discourse_bug SET state='off-topic', reason=? WHERE topic=? AND post=?"
          ).run(`Edward (post ${u.postNumber}): "${u.text.slice(0, 120)}"`, u.topic, u.post)
          const cancelled = cancelDraftsForBug(db, u.topic, u.post, 'Bug dismissed as off-topic')
          if (cancelled > 0) out(`check_bug_feedback: cancelled ${cancelled} pending draft(s) for off-topic bug ${u.topic}/${u.post}`)
          markedOffTopic.push({ topic: u.topic, post: u.post, reason: u.text.slice(0, 80) })
          out(`check_bug_feedback: marked ${u.topic}/${u.post} off-topic — Edward explained expected behaviour`)
        } else if (u.action === 'investigating') {
          if (current.state !== 'investigating') {
            db.prepare(
              "UPDATE discourse_bug SET state='investigating', reason=? WHERE topic=? AND post=?"
            ).run(`Edward working on it (post ${u.postNumber}): "${u.text.slice(0, 120)}"`, u.topic, u.post)
            markedInvestigating.push({ topic: u.topic, post: u.post, reason: u.text.slice(0, 80) })
            out(`check_bug_feedback: marked ${u.topic}/${u.post} investigating — Edward posted fix-in-progress`)
          }
        }
      }

      return { checked: allActiveBugs.length, markedFixed: confirmations, markedInvestigating, markedOffTopic }
    },
  },

  {
    name: 'sync_pr_states',
    description: 'Sync PR states from GitHub for all PRs referenced in discourse_bug.pr_number. Updates the pr table (sets deploy_state=pending_deploy on MERGE), and reopens bugs whose PRs were closed (rejected) by the reviewer — tracking rejection count so escalation logic can fire. Bug → fixed promotion happens in reconcileBugStates (discourse-status.ts) only once deploy_state=deployed is confirmed. Returns {synced, updated, reopened: [{topic, post, prNumber, rejections}]}.',
    handler: async () => {
      const db = getDb()
      const bugPRs = db.prepare(
        `SELECT DISTINCT pr_number FROM discourse_bug WHERE pr_number IS NOT NULL AND state IN ('open','investigating','fix-queued')`
      ).all() as Array<{ pr_number: number }>
      const tablePRs = db.prepare(
        `SELECT DISTINCT number FROM pr WHERE state IS NULL OR state NOT IN ('MERGED','CLOSED')`
      ).all() as Array<{ number: number }>

      const toSync = new Set([...bugPRs.map(r => r.pr_number), ...tablePRs.map(r => r.number)])
      if (toSync.size === 0) return { synced: [], updated: 0, reopened: [] }

      const synced: Array<{ number: number; state: string; mergedAt: string | null }> = []
      const reopened: Array<{ topic: number; post: number; prNumber: number; rejections: number }> = []
      let updated = 0

      for (const num of toSync) {
        const res = await sh('gh', ['pr', 'view', String(num), '--repo', 'Freegle/Iznik', '--json', 'number,state,mergedAt,title,headRefName'])
        if (res.code !== 0) continue
        try {
          const pr = JSON.parse(res.stdout) as { number: number; state: string; mergedAt: string | null; title: string; headRefName: string }
          const ghState = pr.state === 'MERGED' ? 'MERGED' : pr.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
          // Don't assume MERGED = deployed. Production branch is only updated after CI passes on master.
          // Preserve any existing 'deployed' state; set 'pending_deploy' only if transitioning to MERGED
          // with no existing deploy_state.
          const existingPr = db.prepare('SELECT deploy_state FROM pr WHERE number = ?').get(pr.number) as { deploy_state: string | null } | undefined
          const shouldSetPendingDeploy = ghState === 'MERGED' && (!existingPr?.deploy_state || existingPr.deploy_state === 'pending_deploy')
          upsertPr(db, {
            number: pr.number,
            title: pr.title,
            branch: pr.headRefName,
            state: ghState,
            deployState: shouldSetPendingDeploy ? 'pending_deploy' : undefined,
          })
          synced.push({ number: pr.number, state: ghState, mergedAt: pr.mergedAt })
          updated++

          // A CLOSED (not merged) PR means the reviewer rejected the fix.
          // Reopen the linked bug so it can be re-dispatched or escalated.
          // Check all non-terminal states (not just fix-queued) in case the bug was
          // manually reopened or is in another active state.
          if (ghState === 'CLOSED') {
            const bugs = db.prepare(
              `SELECT topic, post, pr_rejections FROM discourse_bug
               WHERE pr_number = ? AND state NOT IN ('fixed','confirmed','deferred','off-topic','duplicate')`
            ).all(pr.number) as Array<{ topic: number; post: number; pr_rejections: number }>

            // Distinguish "the human closed this because it is NOT a code bug" (park it,
            // never retry) from "the fix was wrong, try again" (retry-once-then-escalate).
            // Re-attempting after a wontfix close just churns out duplicate bad PRs —
            // exactly what happened with #9753 (#631 closed "no actionable code bug" → the
            // FSM produced #660). A wontfix signal in a human close comment means STOP.
            let wontfix: { wontfix: boolean; reason?: string } = { wontfix: false }
            if (bugs.length > 0) {
              const cres = await sh('gh', ['pr', 'view', String(pr.number), '--repo', 'Freegle/Iznik', '--json', 'comments'])
              if (cres.code === 0) {
                try {
                  const parsed = JSON.parse(cres.stdout) as { comments?: Array<{ author?: { login?: string } | null; body?: string | null }> }
                  wontfix = detectWontfixClose(parsed.comments ?? [])
                } catch { /* malformed comments json — fall through to reopen */ }
              }
            }

            for (const bug of bugs) {
              if (wontfix.wontfix) {
                db.prepare(
                  `UPDATE discourse_bug SET state='deferred', pr_number=NULL,
                     reason=?, last_seen_at=datetime('now')
                   WHERE topic=? AND post=?`
                ).run(`Human closed PR #${pr.number} as not-a-bug: ${wontfix.reason ?? 'no fix required'}`, bug.topic, bug.post)
                out(`sync_pr_states: PR #${pr.number} CLOSED as wontfix — parked bug ${bug.topic}/${bug.post} (no retry)`)
                continue
              }
              reopenBugAfterRejection(db, bug.topic, bug.post, pr.number)
              insertReviewerFeedback(db, {
                kind: 'pr_rejected',
                prNumber: pr.number,
                bugTopic: bug.topic,
                bugPost: bug.post,
                raw: `PR #${pr.number} (${pr.title}) closed by reviewer`,
              })
              const newRejections = bug.pr_rejections + 1
              out(`sync_pr_states: PR #${pr.number} CLOSED — reopened bug ${bug.topic}/${bug.post} (rejections now ${newRejections})`)
              reopened.push({ topic: bug.topic, post: bug.post, prNumber: pr.number, rejections: newRejections })
            }
          }
        } catch { /* malformed JSON from gh — skip */ }
      }

      return { synced, updated, reopened }
    },
  },

  {
    name: 'check_pr_deployed',
    description: 'Check whether a merged PR\'s fix is LIVE in production. Determines which areas the PR touches (Go / Laravel-PHP / frontend) from its file list and gates each touched area on its OWN live signal — they deploy separately: Go on /api/version.commit (build-time ldflags), Laravel on /api/version.laravel_commit (config, refreshed by deploy:record-commit), frontend on the Netlify published-deploy commit. A PR is deployed only when every touched, deployable area includes the merge commit (or it touches none). Returns {deployed, frontendDeployed, backendDeployed, mergeCommitSha, productionSha, netlifyCommitSha, touched:{frontend,go,php}, reason}.',
    paramsSchema: {
      type: 'object',
      properties: { prNumber: { type: 'number' } },
      required: ['prNumber'],
    },
    handler: async (params) => {
      return checkPrDeployed(params.prNumber as number)
    },
  },

  {
    name: 'queue_deployed_reply_drafts',
    description: 'Called automatically during LOAD_STATE. For every bug in fix-queued or fixed state whose PR is MERGED and deploy_state is pending_deploy, checks via check_pr_deployed whether the fix is LIVE (per-area: Go build commit, Laravel checked-out commit, and Netlify published deploy). Once confirmed live, AUTO-POSTS the verbatim "AI Edward: possible fix applied, please retest and report back" reply to the specific reporting Discourse post (no human review), appending the app-release caveat when the fix touches iznik-nuxt3/. Records each posted reply (dedup: one per reporting post). Updates pr.deploy_state to "deployed" or "pending_deploy". Returns {posted, pendingDeploy, alreadyPosted, postFailed}.',
    handler: async () => {
      const db = getDb()

      // Bugs with MERGED PRs still awaiting confirmed deployment.
      // Include fix-queued (PR merged, bug not yet advanced) AND fixed (bug
      // advanced before the live build caught up — deploy_state stays
      // pending_deploy until we confirm the commit is live).
      const bugs = db.prepare(`
        SELECT b.topic, b.post, b.reporter, b.excerpt, b.pr_number,
               p.title AS pr_title, p.frontend_only, p.preview_url, p.deploy_state
        FROM discourse_bug b
        JOIN pr p ON p.number = b.pr_number
        WHERE b.state IN ('fix-queued', 'fixed')
          AND b.pr_number IS NOT NULL
          AND p.state = 'MERGED'
          AND (p.deploy_state IS NULL OR p.deploy_state = 'pending_deploy')
        ORDER BY b.topic, b.post
      `).all() as Array<{
        topic: number; post: number; reporter: string | null; excerpt: string | null
        pr_number: number; pr_title: string
        frontend_only: number | null; preview_url: string | null; deploy_state: string | null
      }>

      const posted: number[] = []
      const pendingDeploy: number[] = []
      const alreadyPosted: number[] = []
      const postFailed: number[] = []

      for (const bug of bugs) {
        // Check deployment (per-area: Go / Laravel / frontend each gated on its
        // own live signal — they deploy separately).
        const deployCheck = await checkPrDeployed(bug.pr_number)

        // Always update deploy_state accurately
        if (deployCheck.deployed && bug.deploy_state !== 'deployed') {
          upsertPr(db, { number: bug.pr_number, deployState: 'deployed' })
          out(`queue_deployed_reply_drafts: PR #${bug.pr_number} is now live (${deployCheck.reason})`)
        } else if (!deployCheck.deployed && bug.deploy_state !== 'pending_deploy') {
          upsertPr(db, { number: bug.pr_number, deployState: 'pending_deploy' })
        }

        if (!deployCheck.deployed) {
          pendingDeploy.push(bug.pr_number)
          continue
        }

        // A reply for this reporting post already exists (posted or queued) —
        // never double-post.
        const existingDraft = db.prepare(
          `SELECT id FROM discourse_draft WHERE topic = ? AND post = ?`
        ).get(bug.topic, bug.post)
        if (existingDraft) {
          alreadyPosted.push(bug.pr_number)
          continue
        }

        const prUrl = `https://github.com/${PROD_REPO}/pull/${bug.pr_number}`
        // Verbatim reply (fixed operator wording). Append the app-release caveat
        // when the fix touches iznik-nuxt3/ — the Capacitor apps are built from
        // it, so app users only get the fix via a store release (web users get
        // it immediately). checkPrDeployed already determined the touched areas
        // from the PR file list, so reuse that rather than re-querying GitHub.
        const affectsApp = deployCheck.touched.frontend || (bug.frontend_only ?? 0) === 1
        const APP_CAVEAT = ' (but app releases may take up to one week)'
        const body = 'AI Edward: possible fix applied, please retest and report back'
          + (affectsApp ? APP_CAVEAT : '')
        const quote = bug.excerpt ?? ''
        const username = bug.reporter ?? 'there'

        // Auto-post (explicitly approved): post the verbatim reply threaded under
        // the specific reporting post, then record it so it dedups + audits.
        const postRes = await postDiscourseReply(bug.topic, body, bug.post)
        if (!postRes.ok) {
          postFailed.push(bug.pr_number)
          outWarn(`queue_deployed_reply_drafts: FAILED to post reply for bug ${bug.topic}.${bug.post} (PR #${bug.pr_number}): ${postRes.error}`)
          // Leave deploy_state=deployed; we'll retry the post next iteration.
          continue
        }

        recordPostedReply(db, {
          topic: bug.topic,
          post: bug.post,
          username,
          quote,
          body,
          prNumber: bug.pr_number,
          prUrl,
        })

        out(`queue_deployed_reply_drafts: auto-posted verified-live reply for bug ${bug.topic}.${bug.post} (PR #${bug.pr_number})`)
        posted.push(bug.pr_number)
      }

      if (posted.length > 0) await renderAllViews(db)

      return { posted, pendingDeploy, alreadyPosted, postFailed }
    },
  },

  {
    name: 'fetch_discourse',
    description: 'Fetch all NEW POSTS since the state.json cursor, across (a) topics listed in state.topics and (b) the N most recently active topics. Returns {posts: [{topic, topicTitle, postNumber, username, text, createdAt, isOP}], topicsSeen: {id: {title, latestPostNumber}}}. Every new post is returned with full text so TRIAGE can classify per-post (bug/retest/confirmed/question/off-topic).',
    paramsSchema: {
      type: 'object',
      properties: {
        recentLimit: { type: 'number', description: 'How many "latest" topics to fetch in addition to tracked ones. Default 20.' },
      },
    },
    handler: async (params, context) => {
      const recentLimit = (params.recentLimit as number) ?? 20
      const loaded = (context as any)?._action_load_state ?? {}
      const state = loaded.state ?? {}
      const trackedCursors: Record<string, number> = {}
      const topicsObj = (state.topics ?? {}) as Record<string, { last_post?: number }>
      for (const [tid, entry] of Object.entries(topicsObj)) {
        trackedCursors[tid] = entry?.last_post ?? 0
      }

      const cursorsJson = JSON.stringify(trackedCursors)
      const script = `
import json, urllib.request, re, html, sys, time
p = json.load(open('/home/edward/profile.json'))
api_key = p['auth_pairs'][0]['user_api_key']
headers = {'User-Api-Key': api_key}

tracked_cursors = json.loads('''${cursorsJson.replace(/'/g, "\\'")}''')

def fetch(url, retries=4):
    """GET a Discourse URL with rate-limit backoff for 429."""
    delay = 2.0
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers=headers)
            return json.load(urllib.request.urlopen(req, timeout=20))
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < retries - 1:
                retry_after = e.headers.get('Retry-After')
                sleep_s = float(retry_after) if retry_after else delay
                sys.stderr.write(f'429 on {url} — backing off {sleep_s}s (attempt {attempt+1}/{retries})\\n')
                time.sleep(sleep_s)
                delay *= 2
                continue
            raise

# 1. Get latest-${recentLimit} to find recently-active topics (may include new untracked ones)
d = fetch('${DISCOURSE_BASE}/latest.json?order=activity&per_page=${recentLimit}')
latest_topics = {str(t['id']): t for t in d['topic_list']['topics'][:${recentLimit}]}

# 2. Filter tracked topics to those with activity since cursor (latest_posted_at isn't
# enough — we need post_number > cursor). For tracked topics NOT in latest-N, trust the
# cursor — only fetch if topic is in latest-N (recent activity) OR cursor == 0 is allowed
# but rare. In practice: only fetch topic endpoints for topics in latest-N OR those the
# latest_topics list says have posts_count > cursor. This cuts request volume dramatically.
topic_ids = set()
for tid, t in latest_topics.items():
    topic_ids.add(tid)
# Also include tracked topics that appear in latest-N with posts_count > cursor
# (we already have them above — the union with tracked adds nothing if a tracked topic
# hasn't been touched, which is the point: no need to fetch it).
# But for tracked topics whose last_posted_at is newer than cursor's "implied" timestamp
# we can't know from latest-N alone if the topic fell off the top-N. Heuristic: include
# tracked topics where latest_topics has them with posts_count > cursor.
for tid, t in latest_topics.items():
    if tid in tracked_cursors and t.get('posts_count', 0) > tracked_cursors[tid]:
        topic_ids.add(tid)

posts_out = []
topics_seen = {}
for i, tid in enumerate(sorted(topic_ids)):
    if i > 0:
        time.sleep(0.4)  # gentle rate limit — stay well under Discourse's budget
    cursor = tracked_cursors.get(tid, 0)  # 0 for untracked → we'll only take OP (post 1) below to avoid flooding
    try:
        td = fetch(f'${DISCOURSE_BASE}/t/{tid}.json')
    except Exception as e:
        sys.stderr.write(f'topic {tid} failed: {e}\\n')
        continue

    title = td.get('title', '')
    posts = td.get('post_stream', {}).get('posts', [])
    if not posts:
        continue
    highest = max(p.get('post_number', 0) for p in posts)
    topics_seen[tid] = {'title': title, 'latestPostNumber': highest}

    # For untracked topics (cursor=0) only return the OP so we don't flood with old threads
    effective_cursor = cursor if tid in tracked_cursors else max(0, highest - 1)
    for post in posts:
        pn = post.get('post_number', 0)
        if pn <= effective_cursor:
            continue
        cooked = post.get('cooked', '')
        text = re.sub(r'<[^>]+>', '', cooked)
        text = html.unescape(text)
        posts_out.append({
            'topic': int(tid),
            'topicTitle': title,
            'postNumber': pn,
            'username': post.get('username', ''),
            'text': text[:1500],
            'createdAt': post.get('created_at', ''),
            'isOP': pn == 1,
        })

# Sort by topic then post number so TRIAGE sees them in reading order
posts_out.sort(key=lambda p: (p['topic'], p['postNumber']))
print(json.dumps({'posts': posts_out, 'topicsSeen': topics_seen}))
`
      const { stdout, stderr, code } = await sh('python3', ['-c', script])
      if (code !== 0) throw new Error(`fetch_discourse failed: ${stderr}`)
      const result = JSON.parse(stdout.trim()) as { posts: Array<{ topic: number; topicTitle: string; postNumber: number; username: string; text: string; createdAt: string; isOP: boolean }>; topicsSeen: Record<string, { title: string; latestPostNumber: number }> }

      // Advance topic cursors in the DB. We do this in fetch rather than TRIAGE
      // so that even if TRIAGE crashes or is skipped, we don't re-pull the same
      // posts on the next iteration. TRIAGE is still responsible for deciding
      // what's a bug — the cursor is just "up to which post did we see?".
      try {
        const db = getDb()
        for (const [tid, t] of Object.entries(result.topicsSeen ?? {})) {
          setTopicCursor(db, Number(tid), t.latestPostNumber, t.title)
        }
      } catch (err) {
        // DB failure must not abort the fetch — cursor will be re-advanced next time.
        console.error('[db] setTopicCursor failed in fetch_discourse:', err)
      }

      return result
    },
  },

  {
    name: 'read_user_feedback',
    description: 'Return unprocessed reviewer feedback from the SQLite reviewer_feedback table. On first run after migration, imports any parseable lines from the legacy /tmp/freegle-monitor/user_feedback.md so nothing is lost. Returns {entries: [{id, raw, prRejected?, bugTopic?, bugPost?, reason?}], present}. TRIAGE consults these so a rejection surfaces the bug again as actionable with the reviewer reason as extra context.',
    handler: async () => {
      const db = getDb()

      // Parse a line into a reviewer-feedback row. Shared between bootstrap and
      // any future import pass.
      const parseLine = (trimmed: string): { kind: 'pr_rejected' | 'bug_reopen'; prNumber?: number; bugTopic?: number; bugPost?: number; reason?: string } | null => {
        if (!trimmed || trimmed.startsWith('#')) return null
        const reopenMatch = trimmed.match(/^REOPEN\s+bug\s+(\d+)[\/\.](\d+)\s*[:\-]?\s*(.*)$/i)
        const prMatch = trimmed.match(/^(?:PR\s*)?#(\d+)\s+(rejected|rework|wrong|bad|close)\b\s*[:\-]?\s*(.*)$/i)
        const prColonMatch = trimmed.match(/^PR\s*#(\d+)\s*[:\-]\s*(.+)$/i)
        if (reopenMatch) {
          return {
            kind: 'bug_reopen',
            bugTopic: Number(reopenMatch[1]),
            bugPost: Number(reopenMatch[2]),
            reason: reopenMatch[3]?.trim() || undefined,
          }
        }
        if (prMatch) {
          return {
            kind: 'pr_rejected',
            prNumber: Number(prMatch[1]),
            reason: prMatch[3]?.trim() || prMatch[2],
          }
        }
        if (prColonMatch) {
          return {
            kind: 'pr_rejected',
            prNumber: Number(prColonMatch[1]),
            reason: prColonMatch[2]?.trim() || undefined,
          }
        }
        return null
      }

      // One-time bootstrap from legacy MD if DB is empty and file exists.
      const fbCount = (db.prepare('SELECT COUNT(*) AS c FROM reviewer_feedback').get() as { c: number }).c
      let legacyPresent = false
      if (fbCount === 0) {
        try {
          const raw = await readFile(USER_FEEDBACK_PATH, 'utf8')
          legacyPresent = true
          for (const line of raw.split('\n')) {
            const trimmed = line.trim()
            const parsed = parseLine(trimmed)
            if (parsed) {
              insertReviewerFeedback(db, { ...parsed, raw: trimmed })
            }
          }
        } catch {
          // no legacy file — fine
        }
      }

      const rows = listUnprocessedFeedback(db)
      const entries = rows.map(r => ({
        id: r.id,
        raw: r.raw,
        prRejected: r.kind === 'pr_rejected' ? (r.pr_number ?? undefined) : undefined,
        bugTopic: r.kind === 'bug_reopen' ? (r.bug_topic ?? undefined) : undefined,
        bugPost: r.kind === 'bug_reopen' ? (r.bug_post ?? undefined) : undefined,
        reason: r.reason ?? undefined,
      }))
      return { entries, path: USER_FEEDBACK_PATH, present: entries.length > 0 || legacyPresent }
    },
  },

  {
    name: 'git_log_today',
    description: 'Return last 3 days of commits across FreegleDockerWSL, iznik-server-go, iznik-nuxt3.',
    handler: async () => {
      const repos = [
        '/home/edward/FreegleDockerWSL',
        '/home/edward/FreegleDockerWSL/iznik-server-go',
        '/home/edward/FreegleDockerWSL/iznik-nuxt3',
      ]
      const out: Record<string, string> = {}
      for (const repo of repos) {
        const { stdout } = await sh('git', ['log', '--oneline', '--since=3 days ago', '--all'], repo)
        out[repo] = stdout.trim()
      }
      return out
    },
  },

  {
    name: 'discover_active_topics',
    description: 'Light pre-check: fetches Discourse /latest.json (one API call) and compares post counts against DB cursors to find topics with new posts. Used to decide which topics need a triage delegate. Returns {topics: [{id, title, cursor, postsCount, hasNew}]}.',
    paramsSchema: { type: 'object', properties: { recentLimit: { type: 'number' } } },
    handler: async (params) => {
      const recentLimit = (params.recentLimit as number) ?? 30
      // Discourse rate-limits aggressively, and this GET runs in the same iteration
      // as a burst of reply POSTs — so a raw urlopen here regularly 429'd and the
      // step silently returned zero topics (missing genuinely-active bug reports).
      // Use the same retry-with-backoff helper as fetch_topic_updates so a 429 is
      // ridden out (respecting Retry-After) instead of dropping the pre-check.
      const script = `
import json, urllib.request, sys, time
p = json.load(open('/home/edward/profile.json'))
api_key = p['auth_pairs'][0]['user_api_key']
headers = {'User-Api-Key': api_key}

def fetch(url, retries=4):
    """GET a Discourse URL with rate-limit backoff for 429."""
    delay = 2.0
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers=headers)
            return json.load(urllib.request.urlopen(req, timeout=20))
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < retries - 1:
                retry_after = e.headers.get('Retry-After')
                sleep_s = float(retry_after) if retry_after else delay
                sys.stderr.write(f'429 on {url} — backing off {sleep_s}s (attempt {attempt+1}/{retries})\\n')
                time.sleep(sleep_s)
                delay *= 2
                continue
            raise

d = fetch('${DISCOURSE_BASE}/latest.json?order=activity&per_page=${recentLimit}')
topics = [{'id': t['id'], 'title': t['title'], 'postsCount': t['posts_count']} for t in d['topic_list']['topics']]
print(json.dumps(topics))
`
      const { stdout, stderr, code } = await sh('python3', ['-c', script])
      if (code !== 0) return { topics: [], error: `discover_active_topics fetch failed: ${stderr.slice(-200)}` }

      let rawTopics: Array<{ id: number; title: string; postsCount: number }> = []
      try { rawTopics = JSON.parse(stdout.trim()) } catch { return { topics: [], error: 'json parse failed' } }

      // Cross-reference with DB cursors so we know which topics have genuinely new posts.
      const db = getDb()
      const cursorRows = listTopicCursors(db) // {topic_id, last_post_number, title}
      const cursorMap = new Map(cursorRows.map(r => [r.topic_id, r.last_post_number]))

      const topics = rawTopics.map(t => ({
        id: t.id,
        title: t.title,
        postsCount: t.postsCount,
        cursor: cursorMap.get(t.id) ?? 0,
        hasNew: t.postsCount > (cursorMap.get(t.id) ?? 0),
      }))

      const withNew = topics.filter(t => t.hasNew)
      out(`discover_active_topics: ${withNew.length}/${topics.length} topics have new posts`)
      return { topics }
    },
  },

  {
    name: 'check_sentry',
    description: 'List unresolved Sentry issues for nuxt3 and go projects (top 10 each, by date).',
    handler: async () => {
      const script = `
import json, os, urllib.request
env = {}
for line in open('/home/edward/FreegleDockerWSL/.env'):
    if '=' in line and not line.startswith('#'):
        k, v = line.strip().split('=', 1)
        env[k] = v
token = env.get('SENTRY_AUTH_TOKEN', '')
if not token:
    print(json.dumps({'error': 'no SENTRY_AUTH_TOKEN'}))
    raise SystemExit(0)
out = {}
for slug in ['nuxt3', 'go']:
    req = urllib.request.Request(
        f'https://sentry.io/api/0/projects/freegle/{slug}/issues/?query=is:unresolved&sort=date',
        headers={'Authorization': f'Bearer {token}'},
    )
    data = json.load(urllib.request.urlopen(req))
    out[slug] = [{'id': i['id'], 'title': i['title'], 'count': i['count'], 'lastSeen': i['lastSeen'], 'permalink': i['permalink']} for i in data[:10]]
print(json.dumps(out))
`
      const { stdout, stderr, code } = await sh('python3', ['-c', script])
      if (code !== 0) throw new Error(`check_sentry failed: ${stderr}`)
      return JSON.parse(stdout.trim())
    },
  },

  {
    name: 'verify_pr_created',
    description: 'CRITICAL GATE: Count PRs authored by @me since iterationStartTs. Returns {count, prs: [{number, title, createdAt}]}. The LLM cannot fake this — it calls gh pr list directly.',
    paramsSchema: {
      type: 'object',
      properties: { iterationStartTs: { type: 'string' } },
      required: ['iterationStartTs'],
    },
    handler: async (params, context) => {
      const since = (params.iterationStartTs as string) || (context.iterationStartTs as string)
      if (!since) {
        return { count: 0, prs: [], error: 'no iterationStartTs in params or context' }
      }
      const sinceDate = new Date(since)
      const { stdout, stderr, code } = await sh('gh', [
        'pr', 'list',
        '--repo', 'Freegle/Iznik',
        '--author', '@me',
        '--state', 'all',
        '--limit', '50',
        '--json', 'number,title,createdAt,updatedAt,url,state',
      ])
      if (code !== 0) {
        return { count: 0, prs: [], error: stderr }
      }
      const all = JSON.parse(stdout) as Array<{ number: number; title: string; createdAt: string; updatedAt: string; url: string; state: string }>

      // Path A: NEW PRs created since iterationStartTs (Discourse fixes, coverage PRs, Sentry PRs)
      const recent = all.filter(p => new Date(p.createdAt) >= sinceDate)
      if (recent.length > 0) {
        return {
          count: recent.length,
          prs: recent.map(p => ({ number: p.number, title: p.title, createdAt: p.createdAt, url: p.url })),
          kind: 'new-prs',
          checkedSince: since,
        }
      }

      // Path B: commits pushed to existing OPEN @me PRs since iterationStartTs
      // (FIX_OPEN_PR_CI route — fixing CI on an existing PR is productive work too)
      const updatedOpen = all.filter(p => p.state === 'OPEN' && new Date(p.updatedAt) >= sinceDate)
      if (updatedOpen.length > 0) {
        return {
          count: updatedOpen.length,
          prs: updatedOpen.map(p => ({ number: p.number, title: `(commit pushed) ${p.title}`, createdAt: p.updatedAt, url: p.url })),
          kind: 'commits-on-open-prs',
          checkedSince: since,
        }
      }

      return { count: 0, prs: [], kind: 'none', checkedSince: since }
    },
  },

  {
    name: 'create_pr',
    description: 'Record that a PR was created. Host validates by running gh pr view on the given number, inspects changed files (for frontendOnly classification), and looks up the Netlify deploy-preview URL from `gh pr checks` so a reply draft can include it when the fix is frontend-only. Params: {prNumber, repo, topic?, post?, reporter?, excerpt?}. If topic+post are provided, immediately upserts discourse_bug with state=fix-queued so the bug is visible on the dashboard before the reply draft is written. Returns {verified, pr, files, frontendOnly, deployPreviewUrl}.',
    paramsSchema: {
      type: 'object',
      properties: {
        prNumber: { type: 'number' },
        repo: { type: 'string' },
        topic: { type: 'number', description: 'Discourse topic ID of the bug this PR fixes — persists bug row immediately' },
        post: { type: 'number', description: 'Discourse post number of the bug this PR fixes' },
        reporter: { type: 'string' },
        excerpt: { type: 'string' },
      },
      required: ['prNumber'],
    },
    handler: async (params) => {
      const prNumber = params.prNumber as number
      const repo = (params.repo as string) ?? 'Freegle/Iznik'
      const viewRes = await sh('gh', ['pr', 'view', String(prNumber), '--repo', repo, '--json', 'number,title,url,author,files,headRefName'])
      if (viewRes.code !== 0) throw new Error(`create_pr verification failed: PR #${prNumber} in ${repo}: ${viewRes.stderr}`)
      const viewData = JSON.parse(viewRes.stdout) as { number: number; title: string; url: string; author: any; files?: Array<{ path: string }>; headRefName: string }
      const files = (viewData.files ?? []).map(f => f.path)
      const frontendOnly = files.length > 0 && files.every(p => p.startsWith('iznik-nuxt3/'))

      // If the caller passed the bug coordinates, write the bug row immediately so
      // the dashboard never shows a PR without an associated bug.
      const topic = params.topic as number | undefined
      const post = params.post as number | undefined
      if (topic && post) {
        const db = getDb()
        upsertDiscourseBug(db, {
          topic, post,
          reporter: (params.reporter as string | undefined) ?? undefined,
          excerpt: (params.excerpt as string | undefined) ?? undefined,
          state: 'fix-queued',
          prNumber,
        })
      }

      let deployPreviewUrl: string | undefined
      const checksRes = await sh('gh', ['pr', 'checks', String(prNumber), '--repo', repo])
      if (checksRes.code === 0 || checksRes.stdout) {
        // Only accept a genuine deploy-preview URL (contains `deploy-preview-<N>`).
        // Netlify also surfaces admin links (app.netlify.com/...) which are NOT testable.
        // Extracting only the canonical preview URL means we never hand a dashboard
        // link to a reporter as a "please test" target.
        const urlMatch = checksRes.stdout.match(/https?:\/\/deploy-preview-\d+[^\s]+/)
        if (urlMatch) {
          deployPreviewUrl = urlMatch[0]
        }
      }

      const pr = { number: viewData.number, title: viewData.title, url: viewData.url, author: viewData.author }
      return { verified: true, pr, files, frontendOnly, deployPreviewUrl }
    },
  },

  {
    name: 'close_extra_prs',
    description: 'Close duplicate/rogue PRs that the fix delegate opened for the SAME bug as expectedBugBranch. Preserves PRs for other bugs (different branch prefix). Returns { closed: number[], kept: number }.',
    paramsSchema: {
      type: 'object',
      properties: {
        expectedPrNumber: { type: 'number', description: 'The one PR that should stay open' },
        iterationStartTs: { type: 'string', description: 'ISO timestamp — close PRs opened after this' },
        expectedBugBranch: { type: 'string', description: 'Branch name of the expected PR (e.g. fix/post-expiry-9481-531). Only PRs whose branch starts with the same fix/ prefix are eligible for closure — prevents sweeping PRs for other bugs fixed earlier in the same iteration.' },
      },
      required: ['expectedPrNumber', 'iterationStartTs'],
    },
    handler: async (params) => {
      const expectedPrNumber = params.expectedPrNumber as number
      const iterationStartTs = params.iterationStartTs as string
      const expectedBugBranch = (params.expectedBugBranch as string | undefined) ?? ''
      const listRes = await sh('gh', ['pr', 'list', '--repo', 'Freegle/Iznik', '--state', 'open', '--json', 'number,createdAt,headRefName,author'])
      if (listRes.code !== 0) return { error: `gh pr list failed: ${listRes.stderr}`, closed: [], kept: expectedPrNumber }
      const prs = JSON.parse(listRes.stdout) as Array<{ number: number; createdAt: string; headRefName: string; author: { login: string } }>
      const extras = selectExtraPrsToClose(prs, expectedPrNumber, iterationStartTs, expectedBugBranch)
      const closed: number[] = []
      for (const pr of extras) {
        const closeRes = await sh('gh', ['pr', 'close', String(pr.number), '--repo', 'Freegle/Iznik', '--comment', 'Closed: duplicate PR for same bug — FSM enforces one PR per bug fix'])
        if (closeRes.code === 0) closed.push(pr.number)
      }
      return { closed, kept: expectedPrNumber }
    },
  },

  {
    name: 'post_discourse_reply_draft',
    description: 'Queue a Discourse reply draft by APPENDING it to /tmp/freegle-monitor/retest-drafts.md. NEVER posts to Discourse — drafts require explicit human approval per iteration. Strict template (enforced here): body must be a single sentence; the file entry always renders the full [quote] block, the @username tag, the body, and a testable URL if provided. Params: {topic, post, username, quote, body, previewUrl?, prNumber?, prUrl?}. Use previewUrl ONLY for frontend-only fixes; backend/mixed fixes must include NO previewUrl because the user cannot retest until a deploy. The body should be exactly "Fix applied for <specific issue> (<prUrl>). Please retest." or (with preview) "Possible fix — please test: <url>". Always include the prUrl in the body so the reporter can see which PR fixed their issue.',
    paramsSchema: {
      type: 'object',
      properties: {
        topic: { type: 'number' },
        post: { type: 'number' },
        username: { type: 'string' },
        quote: { type: 'string', description: '15-25 word excerpt from the original post — the disambiguator so the reporter knows which of their bugs this reply addresses.' },
        body: { type: 'string', description: 'One sentence. Include the PR URL in parentheses after the fix description. No commit hashes, V1/V2, Go/Nuxt, or other internals.' },
        previewUrl: { type: 'string', description: 'Netlify deploy-preview URL. Include ONLY for frontend-only (iznik-nuxt3/**) fixes.' },
        prNumber: { type: 'number' },
        prUrl: { type: 'string' },
      },
      required: ['topic', 'post', 'username', 'quote', 'body'],
    },
    handler: async (params) => {
      const topic = params.topic as number
      const post = params.post as number
      const username = params.username as string
      const quote = (params.quote as string).trim()
      const body = (params.body as string).trim()
      const previewUrl = params.previewUrl as string | undefined
      const prNumber = params.prNumber as number | undefined
      const prUrl = params.prUrl as string | undefined

      const db = getDb()
      const draftId = queueDiscourseDraft(db, {
        topic, post, username, quote, body,
        previewUrl, prNumber, prUrl,
      })

      // Also mark the bug as fix-queued so subsequent iterations know a draft is out.
      if (prNumber) {
        upsertDiscourseBug(db, {
          topic, post, reporter: username, excerpt: quote,
          state: 'fix-queued', prNumber,
        })
      }

      // Regenerate the MD view so the copy-paste queue reflects current DB state.
      await renderAllViews(db)

      const previewLine = previewUrl ? `> @${username} Possible fix — please test: ${previewUrl}` : `> @${username} ${body}`
      return { queued: true, draftId, draft: params, file: DRAFTS_PATH, previewLineRendered: previewLine }
    },
  },

  {
    name: 'search_code',
    description: 'Search Go/Nuxt/Laravel code for a pattern and return the matching lines WITH surrounding context (so you can diagnose without a second read). Params: {pattern, path}. Returns {files: [...paths], matches: [{file, line, text}], context}. Use the returned code excerpts to form a diagnosis — do NOT keep re-searching for the same thing.',
    paramsSchema: {
      type: 'object',
      properties: { pattern: { type: 'string' }, path: { type: 'string' } },
      required: ['pattern'],
    },
    handler: async (params) => {
      const pattern = params.pattern as string
      const repo = '/home/edward/FreegleDockerWSL'
      // ROOT CAUSE of the diagnose loop: this used execFile('rg', ...), but `rg`
      // is NOT an execFile-resolvable binary here — ripgrep only exists as a
      // Claude Code *shell function*, so execFile got ENOENT and search_code
      // ALWAYS returned empty. The diagnosing model saw "0 files matched", had
      // no code to read, and re-searched forever. Use `git grep` instead: git
      // is a real binary (/usr/bin/git) and grep returns lines + context. The
      // optional `path` param is treated as a pathspec scope under the repo.
      const path = (params.path as string) ?? ''
      const rel = path ? path.replace(/^\/home\/edward\/FreegleDockerWSL\/?/, '') : ''
      const pathspec = rel ? [rel.endsWith('/') || !rel.includes('.') ? `${rel.replace(/\/$/, '')}/**` : rel] : []
      // -I skip binary, -n line numbers, -C 3 context, fixed-string off (regex).
      const args = ['grep', '-n', '-I', '-C', '3', '-e', pattern]
      if (pathspec.length) args.push('--', ...pathspec)
      const { stdout, code } = await sh('git', args, repo)
      // git grep exits 1 when there are no matches — that's not an error.
      const raw = (stdout ?? '').trim()
      const fileSet = new Set<string>()
      const matches: Array<{ file: string; line: number; text: string }> = []
      for (const ln of raw.split('\n')) {
        if (!ln || ln === '--') continue
        // git grep -C format: path:line:text (match) or path-line-text (context)
        const m = ln.match(/^(.+?)[:-](\d+)[:-](.*)$/)
        if (m) {
          const file = m[1]
          fileSet.add(file)
          if (ln.startsWith(`${m[1]}:${m[2]}:`)) {
            matches.push({ file, line: Number(m[2]), text: m[3].slice(0, 300) })
          }
        }
      }
      return {
        files: [...fileSet].slice(0, 50),
        matchCount: matches.length,
        matches: matches.slice(0, 40),
        // Cap the raw excerpt so the context fits a reasonable token budget.
        context: raw.slice(0, 6000),
        noMatch: code !== 0 && matches.length === 0,
      }
    },
  },

  {
    name: 'read_sentry_issues',
    description: 'Read detail for a single Sentry issue. Params: {issueId}',
    paramsSchema: {
      type: 'object',
      properties: { issueId: { type: 'string' } },
      required: ['issueId'],
    },
    handler: async (params) => {
      const issueId = params.issueId as string
      const script = `
import json, urllib.request
env = {}
for line in open('/home/edward/FreegleDockerWSL/.env'):
    if '=' in line and not line.startswith('#'):
        k, v = line.strip().split('=', 1)
        env[k] = v
token = env.get('SENTRY_AUTH_TOKEN', '')
req = urllib.request.Request(
    f'https://sentry.io/api/0/issues/${issueId}/',
    headers={'Authorization': f'Bearer {token}'},
)
print(urllib.request.urlopen(req).read().decode())
`
      const { stdout } = await sh('python3', ['-c', script])
      return JSON.parse(stdout)
    },
  },

  {
    name: 'check_my_open_pr_ci',
    description: 'List OPEN PRs authored by @me whose CI is red or actively pending. BEHIND branches are noted but NOT auto-updated — a PR with green CI is fine to leave BEHIND until a human is ready to merge (they will click Update Branch then). The FSM must not call update-branch preemptively because doing so invalidates the CI, queues a fresh run on the single runner, and causes thrash when master keeps advancing. A PR counts as red if any required check concluded "failure"/"cancelled"/"timed_out". Pending/queued checks count as pending. Netlify noise is ignored. Returns {redPRs, pendingPRs, behindPRs, allGreen}. allGreen is true when no PR is red and none have actively running/pending CI (BEHIND with green CI does NOT block allGreen).',
    handler: async () => {
      const listRes = await sh('gh', [
        'pr', 'list',
        '--repo', 'Freegle/Iznik',
        '--author', '@me',
        '--state', 'open',
        '--limit', '30',
        '--json', 'number,title,url,headRefName,headRefOid,mergeStateStatus',
      ])
      if (listRes.code !== 0) return { redPRs: [], pendingPRs: [], behindPRs: [], allGreen: true, error: listRes.stderr }
      const prs = JSON.parse(listRes.stdout) as Array<{ number: number; title: string; url: string; headRefName: string; headRefOid: string; mergeStateStatus: string }>

      const redPRs: Array<{ number: number; title: string; url: string; failedChecks: Array<{ context: string; state: string; url: string }> }> = []
      const pendingPRs: Array<{ number: number; title: string; url: string; pendingChecks: Array<{ context: string; state: string; url: string }> }> = []
      const behindPRs: Array<{ number: number; title: string; url: string }> = []

      for (const pr of prs) {
        // A BEHIND branch has green CI on its current HEAD — that's good enough.
        // Do NOT call update-branch here. Doing so invalidates the CI and queues a
        // new run on the single self-hosted runner, which causes thrash: master
        // advances → all PRs go BEHIND → all CIs invalidated → repeat indefinitely.
        // The human will click "Update branch" right before merging; one update-branch
        // per PR per merge is fine. The branch/up-to-date GitHub Actions check
        // posts a visual ✗ on GitHub so the stale state is visible without FSM action.
        if (pr.mergeStateStatus === 'BEHIND') {
          behindPRs.push({ number: pr.number, title: pr.title, url: pr.url })
          // Note: still fall through to CI check below. A branch can be BEHIND *and*
          // have genuinely failing CI (separate from the branch/up-to-date noise). We
          // don't auto-update the branch, but we do need to detect and fix real failures.
        }

        const chk = await sh('gh', ['pr', 'checks', String(pr.number), '--repo', 'Freegle/Iznik'])
        // gh pr checks output: tab-separated "name<TAB>status<TAB>elapsed<TAB>url<TAB>description"
        const failed: Array<{ context: string; state: string; url: string }> = []
        const pending: Array<{ context: string; state: string; url: string }> = []
        for (const rawLine of chk.stdout.split('\n')) {
          const line = rawLine.trim()
          if (!line) continue
          const cols = line.split('\t')
          const name = cols[0] ?? ''
          const state = (cols[1] ?? '').toLowerCase()
          const url = cols[3] ?? ''
          // Ignore known noise checks:
          // - Netlify "pages-changed" etc. report "skipping" when nothing changed
          // - "branch/up-to-date" is a GitHub housekeeping StatusContext that shows
          //   FAILURE whenever a branch is behind master — it is not a real CI failure
          if (/pages.?changed|header rules|redirect rules/i.test(name) && /skipping/i.test(state)) continue
          if (/branch.?up.?to.?date/i.test(name)) continue
          if (/^(fail|failure|cancelled|canceled|timed.?out|error)$/.test(state)) {
            failed.push({ context: name, state, url })
          } else if (/^(pending|queued|in.?progress|running)$/.test(state)) {
            pending.push({ context: name, state, url })
          }
        }
        if (failed.length > 0) redPRs.push({ number: pr.number, title: pr.title, url: pr.url, failedChecks: failed })
        else if (pending.length > 0) pendingPRs.push({ number: pr.number, title: pr.title, url: pr.url, pendingChecks: pending })
      }

      // allGreen: no red PRs and no actively pending CI. BEHIND PRs with green CI
      // do NOT block allGreen — they are waiting for a human to merge, not for the FSM.

      // Reset per-PR fix counters for PRs that are now green (no longer red/pending).
      // This lets us attempt again if future master changes re-break the PR.
      const db = getDb()
      const redAndPendingNums = new Set([...redPRs.map(p => p.number), ...pendingPRs.map(p => p.number)])

      // Build a branch → worktree-path map once, so that when a PR goes green we
      // can release any local container stack a fix delegate spun up to test it.
      // The fix is already validated on the cloud runner; the local worktree
      // containers (a full ~30-service compose stack) are pure waste afterwards.
      // We `docker stop` (NOT `down`) so nothing is lost and the user can restart
      // instantly. The main FSM worktree's own stack is always left running.
      const MAIN_WORKTREE = '/home/edward/FreegleDockerWSL'
      const branchToWorktree = new Map<string, string>()
      try {
        const wtRes = await sh('git', ['-C', MAIN_WORKTREE, 'worktree', 'list', '--porcelain'])
        if (wtRes.code === 0) {
          let curPath: string | null = null
          for (const raw of wtRes.stdout.split('\n')) {
            if (raw.startsWith('worktree ')) curPath = raw.slice('worktree '.length).trim()
            else if (raw.startsWith('branch ') && curPath) {
              const b = raw.slice('branch '.length).trim().replace(/^refs\/heads\//, '')
              if (b) branchToWorktree.set(b, curPath)
            }
          }
        }
      } catch { /* worktree listing is best-effort */ }

      for (const pr of prs) {
        if (!redAndPendingNums.has(pr.number)) {
          const key = `pr_fix_attempts_${pr.number}`
          const existing = kvGet(db, key)
          if (existing && existing !== '0') {
            kvSet(db, key, '0')
            out(`check_my_open_pr_ci: PR #${pr.number} is green — reset fix attempt counter (was ${existing})`)
          }

          // PR is green → release the worktree's container stack if a fix delegate
          // left one running. Skipped when the worktree is the main FSM checkout, or
          // when another session is actively working in it. Idempotent, best-effort.
          const wt = pr.headRefName ? branchToWorktree.get(pr.headRefName) : undefined
          if (wt && wt !== MAIN_WORKTREE) {
            try {
              // Don't disturb a worktree another session is using: a parallel Claude
              // session (or a human) leaves live processes whose cwd is under the
              // worktree (editors, dev servers, CI-watch loops, even a `sleep`). The
              // FSM's own delegates run in throwaway /tmp worktrees, so they never
              // match this. If anything is cwd'd in there, leave the stack up.
              let inUse = false
              try {
                for (const ent of readdirSync('/proc')) {
                  if (!/^\d+$/.test(ent)) continue
                  let cwd: string
                  try { cwd = readlinkSync(`/proc/${ent}/cwd`) } catch { continue }
                  if (cwd === wt || cwd.startsWith(wt + '/')) { inUse = true; break }
                }
              } catch { /* /proc scan best-effort */ }

              if (inUse) {
                out(`check_my_open_pr_ci: PR #${pr.number} green — worktree ${wt} in active use (live process cwd'd there), leaving containers up`)
              } else {
                const psRes = await sh('docker', ['ps', '-q', '--filter', `label=com.docker.compose.project.working_dir=${wt}`])
                const ids = psRes.code === 0 ? psRes.stdout.split('\n').map(s => s.trim()).filter(Boolean) : []
                if (ids.length > 0) {
                  const stopRes = await sh('docker', ['stop', ...ids])
                  if (stopRes.code === 0) {
                    out(`check_my_open_pr_ci: PR #${pr.number} green — stopped ${ids.length} worktree container(s) at ${wt}`)
                  } else {
                    out(`check_my_open_pr_ci: PR #${pr.number} green — failed to stop containers at ${wt}: ${stopRes.stderr.slice(-200)}`)
                  }
                }
              }
            } catch (err: any) {
              out(`check_my_open_pr_ci: PR #${pr.number} container cleanup error: ${String(err?.message || err).slice(-200)}`)
            }
          }
        }
      }

      return { redPRs, pendingPRs, behindPRs, allGreen: redPRs.length === 0 && pendingPRs.length === 0 }
    },
  },

  {
    name: 'check_master_ci',
    description: 'Check CircleCI status on the latest master commit of Freegle/Iznik. Returns {sha, overallState, circleCiStatuses: [{context, state}], failing: bool}. Use this to detect red master so the FSM can prioritise fixing it.',
    handler: async () => {
      // Use the commit status API rather than `gh run list` (GitHub Actions).
      // `gh run list` returns GH Actions workflow runs (e.g. "Update Version File")
      // which are unrelated to the main CircleCI build-and-test pipeline. The
      // commit status API aggregates all CI contexts (CircleCI, Coveralls, etc.)
      // posted to the HEAD commit, giving the true picture.
      const headRes = await sh('gh', ['api', 'repos/Freegle/Iznik/commits/master/status'])
      if (headRes.code !== 0) return { error: headRes.stderr, failing: false }
      const data = JSON.parse(headRes.stdout) as { state: string; sha: string; statuses: Array<{ context: string; state: string; target_url: string }> }
      const circleCiStatuses = data.statuses.filter(s => s.context.startsWith('ci/circleci'))
      const failingStatuses = circleCiStatuses.filter(s => s.state === 'failure' || s.state === 'error')
      return {
        sha: data.sha,
        overallState: data.state,
        circleCiStatuses: circleCiStatuses.map(s => ({ context: s.context, state: s.state, url: s.target_url })),
        failing: failingStatuses.length > 0,
      }
    },
  },

  {
    name: 'check_production_ci',
    description: 'Check CircleCI status on the latest production branch commit of Freegle/Iznik. Returns {sha, overallState, circleCiStatuses: [{context, state}], failing: bool}. Use this to detect red production so the FSM can prioritise fixing it.',
    handler: async () => {
      const headRes = await sh('gh', ['api', 'repos/Freegle/Iznik/commits/production/status'])
      if (headRes.code !== 0) return { error: headRes.stderr, failing: false }
      const data = JSON.parse(headRes.stdout) as { state: string; sha: string; statuses: Array<{ context: string; state: string; target_url: string }> }
      const circleCiStatuses = data.statuses.filter(s => s.context.startsWith('ci/circleci'))
      const failingStatuses = circleCiStatuses.filter(s => s.state === 'failure' || s.state === 'error')
      return {
        sha: data.sha,
        overallState: data.state,
        circleCiStatuses: circleCiStatuses.map(s => ({ context: s.context, state: s.state, url: s.target_url })),
        failing: failingStatuses.length > 0,
      }
    },
  },

  {
    name: 'fetch_ci_failure_logs',
    description: 'Fetch log tail from failing jobs of a GitHub Actions / CircleCI run on Freegle/Iznik. Uses `gh run view --log-failed`. Params: {runId: number, maxChars?: number}. Returns {jobs: [{name, conclusion}], logTail: string}.',
    paramsSchema: {
      type: 'object',
      properties: {
        runId: { type: 'number' },
        maxChars: { type: 'number' },
      },
      required: ['runId'],
    },
    handler: async (params) => {
      const runId = params.runId as number
      // Cap at 3000 chars to keep downstream prompt small. Long logs get truncated to the tail,
      // which is where the actual error message almost always is.
      const maxChars = Math.min((params.maxChars as number) ?? 3000, 3000)
      const meta = await sh('gh', ['api', `repos/Freegle/Iznik/actions/runs/${runId}/jobs`])
      let jobs: Array<{ name: string; conclusion: string; id: number }> = []
      if (meta.code === 0) {
        try {
          jobs = (JSON.parse(meta.stdout).jobs ?? []).map((j: any) => ({
            name: j.name,
            conclusion: j.conclusion,
            id: j.id,
          }))
        } catch {}
      }
      const failed = jobs.filter(j => j.conclusion === 'failure')
      const chunks: string[] = []
      for (const job of failed) {
        const jobLog = await sh('gh', ['api', `repos/Freegle/Iznik/actions/jobs/${job.id}/logs`])
        const body = jobLog.stdout || jobLog.stderr || ''
        chunks.push(`=== job: ${job.name} (id=${job.id}) ===\n${body.slice(-maxChars)}`)
      }
      return { jobs, logTail: chunks.join('\n').slice(-maxChars * 2) }
    },
  },

  {
    name: 'delegate_to_coder',
    description: 'Spawn a headless Claude Code session to perform an actual code change (diagnose, edit files, run tests, commit, push, open PR). The embedded FSM LLM has no tool access — this is how a FIX_* state actually produces a PR. Params: {task: string, repoCwd?: string, timeoutSec?: number}. Returns {stdout, exitCode, prNumber?}. The subagent must emit a line `PR_NUMBER=<n>` on stdout to be picked up.',
    paramsSchema: {
      type: 'object',
      properties: {
        task: { type: 'string', description: 'Full task description for the subagent: what to fix, how, acceptance criteria, exact repo path, whether to push to master or a feature branch.' },
        repoCwd: { type: 'string', description: 'Working directory. Defaults to /home/edward/FreegleDockerWSL.' },
        timeoutSec: { type: 'number', description: 'Max seconds. Default 1200 (20 min).' },
        model: { type: 'string', description: 'Claude model id for the subagent. Omit to use the iteration-active model (Haiku in peak/implementation phase, Sonnet in off-peak/analysis phase).' },
      },
      required: ['task'],
    },
    handler: async (params) => {
      const task = params.task as string
      const repoCwd = (params.repoCwd as string) ?? '/home/edward/FreegleDockerWSL'
      const timeoutSec = (params.timeoutSec as number) ?? 1200

      // Isolate the delegate's filesystem work in a throwaway git worktree so
      // its `git checkout` calls (onto branches that may predate the
      // monitor-fsm/ directory itself) cannot wipe files out from under the
      // running FSM driver.
      const { execFileSync } = await import('node:child_process')
      const worktreeDir = `/tmp/monitor-fsm-delegate-${process.pid}-${Date.now()}`
      let worktreeCreated = false
      let worktreeError: string | null = null
      for (const base of ['master', 'HEAD']) {
        try {
          execFileSync('git', ['worktree', 'add', '--detach', worktreeDir, base], {
            cwd: repoCwd, stdio: 'pipe',
          })
          worktreeCreated = true
          break
        } catch (err: any) {
          worktreeError = String(err?.stderr?.toString?.() || err?.message || err).slice(-500)
        }
      }
      const spawnCwd = worktreeCreated ? worktreeDir : repoCwd

      const fullPrompt = `${task}

==== CRITICAL EXECUTION CONTRAINTS — READ FIRST ====
${worktreeCreated ? `Your working directory is an ISOLATED git worktree at \`${worktreeDir}\`, detached from master. Run all git operations here: \`git checkout <branch>\` / \`git checkout -b <branch>\` are safe and will not affect the parent FSM driver's own checkout. Push your work to origin before emitting the output marker — the worktree is deleted when you return, so anything not pushed is lost.
` : ''}You are a HEADLESS, ONE-SHOT subprocess. When your response ends, your process exits. You have NO persistence, NO wakeups, NO /loop, NO ScheduleWakeup, NO Monitor, NO TaskCreate — those tools do not exist for you. A parent FSM (monitor-fsm) invoked you and is waiting on your stdout.

You MUST do all the work in this single session:
  1. Create or checkout the branch — see BRANCH RULES below.
  2. Reproduce / diagnose.
  3. Make the fix.
  4. Run the relevant test suite locally.
  5. Commit — see STAGING RULES below.
  6. git push (this must complete successfully before you return).
  7. Verify the push landed (git log origin/<branch> -1 shows your commit).
  8. Update the PR description — see PR DESCRIPTION RULES below.
  9. Only THEN emit the final marker and finish.

BRANCH RULES — every new branch MUST be cut from origin/master:
  - Always: \`git fetch origin && git checkout -b your-branch-name origin/master\`
  - NEVER: \`git checkout -b your-branch-name\` (no base) or \`git checkout -b your-branch-name HEAD\` — this inherits the current branch's unmerged commits and pollutes the PR with unrelated changes.
  - For FIX_OPEN_PR_CI (fixing an existing PR): \`git checkout the-pr-branch && git pull origin the-pr-branch\` — do NOT create a new branch.

STAGING RULES — never include unrelated files in a commit:
  - NEVER use \`git add -A\`, \`git add .\`, or \`git add --all\`.
  - Always stage by explicit path: \`git add path/to/file.go path/to/test.go\`
  - Before committing, run \`git diff --stat origin/master\` and verify that EVERY changed file is directly related to this task. If any unrelated files appear, do NOT stage them.

PR DESCRIPTION RULES — the description must always match what's actually in the diff:
  - After pushing, run \`gh pr diff <number> --repo Freegle/Iznik --name-only\` to see all files changed vs master.
  - If any file in the diff is not mentioned in the PR description, update the description with \`gh api repos/Freegle/Iznik/pulls/<n> -X PATCH -f body="..."\` to cover every changed file.
  - This applies whether you opened the PR or pushed to an existing one.

TEST API — always use these exact commands to run and poll for tests:
  - Go tests:      POST http://localhost:8081/api/tests/go    → poll http://localhost:8081/api/tests/go/status
  - Vitest:        POST http://localhost:8081/api/tests/vitest → poll http://localhost:8081/api/tests/vitest/status
  - Playwright:    POST http://localhost:8081/api/tests/playwright → poll http://localhost:8081/api/tests/playwright/status
  - Laravel/PHP:   POST http://localhost:8081/api/tests/laravel
  ALWAYS use port 8081 — not 38081 or any other port you discover.

  CRITICAL: the status field is one of these EXACT strings (case- and
  spelling-sensitive — DO NOT paraphrase, abbreviate, or guess):
    • "running"   — test in progress
    • "completed" — success (note the trailing "d" — NOT "complete")
    • "failed"    — failure (note: "failed", NOT "error" / "fail" / "FAIL")
  If you write s == "complete", s == "done", s == "success", s == "ok",
  s == "error", or any other variant, your poll loop will NEVER exit and the
  FSM will time you out. The literal two strings that mean "stop polling" are
  "completed" and "failed". Nothing else.

  COPY THIS POLL VERBATIM (substitute only <type> with go|vitest|playwright|laravel):
    curl -s -X POST http://localhost:8081/api/tests/<type>
    until curl -s http://localhost:8081/api/tests/<type>/status | python3 -c "
    import sys,json; d=json.load(sys.stdin); s=d.get('status','')
    print(s); exit(0 if s in ['completed','failed'] else 1)
    " 2>/dev/null; do sleep 5; done
  Do NOT rewrite the python check to use s == 'X' or grep -q '"status":"X"'.
  Use the in-list check above so all terminal states are handled.

FORBIDDEN:
  - "I've scheduled a wakeup" / "I'll check back" / "I'll come back to this later" — none of that is possible; if you exit without pushing, the work is lost.
  - Starting tests asynchronously and returning before they finish — wait for test output. If tests take too long, still wait; the FSM has a 20-minute timeout and will kill you only if truly stuck.
  - Creating a new PR when asked to fix an existing one (FIX_OPEN_PR_CI). Push a commit to the PR's branch instead.
  - Using port 38081 or any port other than 8081 for the test/status API.
  - Opening more than one PR or branch in a single session. You are fixing exactly one bug — open AT MOST ONE PR. If you encounter other bugs while reading the code, note them in the PR description but do not fix them. If you accidentally open extra PRs, close them with \`gh pr close <n> --repo Freegle/Iznik\` before emitting your output marker.

PUSH VERIFICATION — Required before any push marker:
For PR_NUMBER=, DIRECT_PUSH=, or COMMIT_PUSHED=, you MUST first verify the push landed remotely:
  git -C /home/edward/FreegleDockerWSL log origin/<branch> -1 --format=%H
If the SHA matches what you pushed, emit on its own line: PUSH_VERIFIED=<sha>
If they don't match: emit DELEGATE_FAILED=push not verified: local <local_sha> != remote <remote_sha>

OUTPUT MARKERS — MANDATORY, MACHINE-PARSED:
The parent FSM greps your stdout for these exact markers. Your prose does NOT count — "Fix pushed to PR #208" is invisible to the parser. You MUST emit exactly ONE of these on its own line at the very end:
  - Opened a NEW PR:                 PR_NUMBER=<n>
  - Pushed to master directly:       DIRECT_PUSH=<sha>
  - Pushed to an existing PR branch: COMMIT_PUSHED=<sha>
  - Read-only / analysis task done:  ANALYSIS_COMPLETE=<one-line summary>
  - Could NOT complete the task:     DELEGATE_FAILED=<one-line-reason>
ANALYSIS_COMPLETE is ONLY for tasks explicitly described as read-only (e.g. Discourse triage, Sentry listing, cursor-advance-only). If your task was to fix a bug and you investigated but could not make a code change, use DELEGATE_FAILED — not ANALYSIS_COMPLETE. ANALYSIS_COMPLETE means "the task was intentionally read-only from the start, and I completed it".
If you omit the marker, your work is considered failed regardless of what actually happened — the parent will redispatch, wasting another iteration.
`
      // claude -p (--print) expects the prompt on stdin. Pipe it explicitly.
      //
      // Timeout strategy: tool-aware silence watchdog over a stream-json
      // event feed, not a fixed wall-clock deadline. The CLI with
      // `--output-format stream-json --verbose --include-partial-messages`
      // emits one NDJSON line per event (assistant-text chunks, tool_use,
      // tool_result). Between events we measure "silence". The threshold
      // depends on state:
      //   • A long-running tool (e.g. `Bash: npm test`) fires tool_use at
      //     start and tool_result at end, with NOTHING in between. We
      //     allow SILENCE_TOOL_MS during that gap — long enough for a
      //     real test suite (Playwright, Go race tests, etc.) to finish.
      //   • When no tool is in-flight, the model should be streaming
      //     tokens or dispatching a tool within SILENCE_IDLE_MS. Longer
      //     silence means the CLI or API is genuinely stuck.
      // HARD_CAP_MS is an absolute ceiling for pathological runaway. The
      // legacy `timeoutSec` param is retained as a floor for the hard cap
      // so callers who explicitly want more wall-clock get it.
      const SILENCE_TOOL_MS = 1_800_000   // 30 min while a tool is in flight
      const SILENCE_IDLE_MS = 180_000     // 3 min between events when idle
      const HARD_CAP_MS = Math.max(timeoutSec * 1000, 3_600_000)
      const { spawn } = await import('node:child_process')
      type KillReason = 'tool-silence' | 'idle-silence' | 'hardCap' | null
      // Model selection: explicit param wins; otherwise use the iteration's
      // active delegate model (set by driver from getPhaseInfo). In peak
      // (implementation) phase this is Haiku — cheap, fast, sufficient for
      // fixing CI or writing a coverage test. In off-peak (analysis) phase
      // this is Sonnet/session-default for heavy diagnosis.
      const delegateModel = (params.model as string) ?? process.env.MONITOR_ACTIVE_DELEGATE_MODEL ?? 'claude-opus-4-8'
      startGroup(`· delegate_to_coder (model=${delegateModel})`)
      let toolCount = 0
      const result = await new Promise<{ stdout: string; stderr: string; textStream: string; code: number; killReason: KillReason; lastTool: string | null }>((resolve) => {
        const child = spawn(
          CLAUDE_BIN,
          [
            '-p',
            '--output-format', 'stream-json',
            '--verbose',
            '--include-partial-messages',
            '--permission-mode', 'acceptEdits',
            '--allowedTools', 'Bash,Edit,Write,Read,Grep,Glob',
            '--model', delegateModel,
          ],
          { cwd: spawnCwd, stdio: ['pipe', 'pipe', 'pipe'] },
        )
        let stdout = ''
        let stderr = ''
        let textStream = ''
        let lastEventAt = Date.now()
        let currentTool: string | null = null
        let lastTool: string | null = null
        let killReason: KillReason = null
        let lineBuffer = ''

        const processLine = (line: string) => {
          const trimmed = line.trim()
          if (!trimmed) return
          let ev: any
          try { ev = JSON.parse(trimmed) } catch { return }
          // Extract text content for the FSM-visible stdoutTail.
          const content = ev?.message?.content ?? ev?.content
          if (Array.isArray(content)) {
            for (const block of content) {
              if (block.type === 'text' && typeof block.text === 'string') {
                textStream += block.text
              } else if (block.type === 'tool_use' && typeof block.name === 'string') {
                currentTool = block.name
                lastTool = block.name
                toolCount += 1
                // Surface tool invocations as nested one-liners so a human
                // watching sees progress while the coder runs. `input` on
                // tool_use holds the args (Bash command, file path, etc.);
                // show a terse single-line hint where we can.
                //
                // SECURITY: redact obvious secrets before display. Tokens
                // routinely appear in bash commands (CIRCLECI_TOKEN=..., curl
                // -u user:TOKEN, gh api -H "Authorization: bearer ..."). The
                // full command still reaches debug.log but the screen + the
                // stdoutTail we echo to the FSM are scrubbed.
                const args = block.input ?? {}
                let hint = ''
                if (typeof args.command === 'string') hint = args.command.replace(/\s+/g, ' ').slice(0, 120)
                else if (typeof args.file_path === 'string') hint = args.file_path
                else if (typeof args.path === 'string') hint = args.path
                else if (typeof args.pattern === 'string') hint = args.pattern
                hint = redactSecrets(hint)
                out(`▸ ${block.name}${hint ? ': ' + truncate(hint, 90) : ''}`)
              } else if (block.type === 'tool_result') {
                // Tool completed — resume idle-silence threshold.
                currentTool = null
              }
            }
          } else if (typeof content === 'string') {
            textStream += content
          }
          // `result` event closes the session; surfaces the final text too.
          if (ev?.type === 'result' && typeof ev.result === 'string') {
            textStream += ev.result
          }
        }

        child.stdout.on('data', (d) => {
          const chunk = String(d)
          stdout += chunk
          lastEventAt = Date.now()
          lineBuffer += chunk
          const lines = lineBuffer.split('\n')
          lineBuffer = lines.pop() ?? ''
          for (const l of lines) processLine(l)
        })
        child.stderr.on('data', (d) => {
          stderr += String(d)
          lastEventAt = Date.now()
        })

        const silenceTick = setInterval(() => {
          const silence = Date.now() - lastEventAt
          const threshold = currentTool ? SILENCE_TOOL_MS : SILENCE_IDLE_MS
          if (silence > threshold) {
            killReason = currentTool ? 'tool-silence' : 'idle-silence'
            child.kill('SIGTERM')
          }
        }, 30_000)
        const hardCap = setTimeout(() => {
          killReason = 'hardCap'
          child.kill('SIGTERM')
        }, HARD_CAP_MS)

        child.on('close', (code) => {
          clearInterval(silenceTick)
          clearTimeout(hardCap)
          if (lineBuffer) processLine(lineBuffer)
          resolve({ stdout, stderr, textStream, code: code ?? 1, killReason, lastTool })
        })
        child.stdin.write(fullPrompt)
        child.stdin.end()
      })
      const { stdout, stderr, textStream, code, killReason, lastTool } = result
      // Markers are machine-emitted by the delegate in its final text
      // response. Match against the extracted textStream (the model's
      // assistant text) rather than raw NDJSON to avoid accidental matches
      // inside JSON field names or event metadata.
      const combined = `${textStream}\n${stderr}`
      const prMatch = combined.match(/PR_NUMBER=(\d+)/)
      const directMatch = combined.match(/DIRECT_PUSH=([a-f0-9]+)/)
      const commitMatch = combined.match(/COMMIT_PUSHED=([a-f0-9]+)/)
      const analysisMatch = combined.match(/ANALYSIS_COMPLETE=([^\n]+)/)
      const failedMatch = combined.match(/DELEGATE_FAILED=([^\n]+)/)
      // exitCode 143 = SIGTERM (silence watchdog or hard cap fired).
      // Surface an explicit `timedOut` flag and `timeoutReason` so the
      // VERIFY/router prompts don't have to reverse-engineer this from the
      // numeric code.
      const timedOut = killReason !== null || code === 143
      const prNumber = prMatch ? Number(prMatch[1]) : undefined
      const directPushSha = directMatch ? directMatch[1] : undefined
      const commitPushedSha = commitMatch ? commitMatch[1] : undefined
      const analysisComplete = analysisMatch ? analysisMatch[1].trim() : undefined
      const pushed = prNumber !== undefined || directPushSha !== undefined || commitPushedSha !== undefined
      // Summarise for the human watcher: what did the delegate actually do?
      let summary: string
      if (timedOut) {
        summary = `timed out (${killReason}) after ${toolCount} tool${toolCount === 1 ? '' : 's'}`
      } else if (prNumber) {
        summary = `opened PR #${prNumber} (${toolCount} tools)`
      } else if (commitPushedSha) {
        summary = `pushed ${commitPushedSha.slice(0, 9)} to existing PR (${toolCount} tools)`
      } else if (directPushSha) {
        summary = `pushed ${directPushSha.slice(0, 9)} to master (${toolCount} tools)`
      } else if (analysisComplete) {
        summary = `analysis done: ${truncate(analysisComplete, 80)}`
      } else if (failedMatch) {
        summary = `DELEGATE_FAILED: ${truncate(failedMatch[1].trim(), 80)}`
      } else if (code === 0) {
        summary = `exited 0 but no marker — ${toolCount} tools, no PR`
      } else {
        summary = `exited ${code} (${toolCount} tools)`
      }
      endGroup(summary)
      try {
        return {
          exitCode: code,
          timedOut,
          timeoutReason: killReason,
          silenceToolMs: SILENCE_TOOL_MS,
          silenceIdleMs: SILENCE_IDLE_MS,
          hardCapMs: HARD_CAP_MS,
          lastTool,
          stdoutTail: redactSecrets(textStream.slice(-2000)),
          stderrTail: redactSecrets(stderr.slice(-2000)),
          prNumber,
          directPushSha,
          commitPushedSha,
          pushed,
          worktreeCreated,
          worktreeError,
          worktreeDir: worktreeCreated ? worktreeDir : null,
        }
      } finally {
        if (worktreeCreated) {
          try {
            execFileSync('git', ['worktree', 'remove', '--force', worktreeDir], {
              cwd: repoCwd, stdio: 'pipe',
            })
          } catch { /* best effort */ }
        }
      }
    },
  },

  // ---- Parallel delegate ----
  // Sibling of delegate_to_coder. Same spawn mechanics but runs N tasks
  // simultaneously, each in its own throwaway git worktree. Use when master is
  // green and multiple independent tasks (red PRs, pending bugs) can be fixed
  // concurrently without conflicting branches.
  {
    name: 'delegate_parallel_tasks',
    description: 'Spawn N headless Claude Code sessions in parallel, each in its own isolated git worktree. Tasks run concurrently — use when master CI is green and each task touches a different branch. Params: {tasks: [{id, task, timeoutSec?}], repoCwd?}. Returns {results: [{id, exitCode, prNumber?, directPushSha?, commitPushedSha?, timedOut?, pushed, stdoutTail, stderrTail, summary}]}.',
    paramsSchema: {
      type: 'object',
      properties: {
        tasks: {
          type: 'array',
          description: 'Array of tasks to run in parallel. Each must have a unique id string and a full task description.',
          items: {
            type: 'object',
            properties: {
              id: { type: 'string', description: 'Unique identifier for this task (e.g. "pr-208", "bug-9594-5").' },
              task: { type: 'string', description: 'Full task description, same format as delegate_to_coder.' },
              timeoutSec: { type: 'number', description: 'Per-task timeout seconds. Default 1200.' },
            },
            required: ['id', 'task'],
          },
        },
        repoCwd: { type: 'string', description: 'Repository root. Defaults to /home/edward/FreegleDockerWSL.' },
      },
      required: ['tasks'],
    },
    handler: async (params) => {
      const tasks = params.tasks as Array<{ id: string; task: string; timeoutSec?: number }>
      const repoCwd = (params.repoCwd as string) ?? '/home/edward/FreegleDockerWSL'
      const { execFileSync, spawn } = await import('node:child_process')
      const delegateModel = process.env.MONITOR_ACTIVE_DELEGATE_MODEL ?? 'sonnet'
      const SILENCE_TOOL_MS = 1_800_000
      const SILENCE_IDLE_MS = 180_000

      const runTask = async (t: { id: string; task: string; timeoutSec?: number }, idx: number) => {
        const timeoutSec = t.timeoutSec ?? 1200
        const HARD_CAP_MS = Math.max(timeoutSec * 1000, 3_600_000)
        const worktreeDir = `/tmp/monitor-fsm-parallel-${process.pid}-${Date.now()}-${idx}`
        let worktreeCreated = false
        for (const base of ['master', 'HEAD']) {
          try {
            execFileSync('git', ['worktree', 'add', '--detach', worktreeDir, base], {
              cwd: repoCwd, stdio: 'pipe',
            })
            worktreeCreated = true
            break
          } catch { /* try next base */ }
        }
        const spawnCwd = worktreeCreated ? worktreeDir : repoCwd

        const fullPrompt = `${t.task}

==== CRITICAL EXECUTION CONSTRAINTS — READ FIRST ====
${worktreeCreated ? `Your working directory is an ISOLATED git worktree at \`${worktreeDir}\`, detached from master. Run all git operations here: \`git checkout <branch>\` / \`git checkout -b <branch>\` are safe. Push your work to origin before emitting the output marker — the worktree is deleted when you return.
` : ''}You are a HEADLESS, ONE-SHOT subprocess. You MUST complete all work and push to origin in this single session. No wakeups, no ScheduleWakeup, no /loop.

BRANCH RULES: always \`git fetch origin && git checkout -b branch-name origin/master\` for new branches. For existing PR branches: \`gh pr checkout <n> -R Freegle/Iznik\`.
STAGING RULES: never \`git add -A\`. Always stage explicit paths.

PUSH VERIFICATION — Required before any push marker:
For PR_NUMBER=, DIRECT_PUSH=, or COMMIT_PUSHED=, you MUST first verify the push landed remotely:
  git -C /home/edward/FreegleDockerWSL log origin/<branch> -1 --format=%H
If the SHA matches what you pushed, emit on its own line: PUSH_VERIFIED=<sha>
If they don't match: emit DELEGATE_FAILED=push not verified: local <local_sha> != remote <remote_sha>

OUTPUT MARKERS — MANDATORY, MACHINE-PARSED (emit exactly one on its own line at the very end):
  - Opened a NEW PR:                 PR_NUMBER=<n>
  - Pushed to master directly:       DIRECT_PUSH=<sha>
  - Pushed to an existing PR branch: COMMIT_PUSHED=<sha>
  - Read-only / analysis task done:  ANALYSIS_COMPLETE=<one-line summary>
  - Could NOT complete the task:     DELEGATE_FAILED=<one-line-reason>
ANALYSIS_COMPLETE is for tasks that involve NO code changes (e.g. Discourse triage, Sentry listing). Use it whenever the task completes with no commit. Do NOT use DELEGATE_FAILED just because you found no bugs or no new posts — that is a successful outcome.
`
        type KillReason = 'tool-silence' | 'idle-silence' | 'hardCap' | null
        let toolCount = 0
        const result = await new Promise<{ stdout: string; stderr: string; textStream: string; code: number; killReason: KillReason }>((resolve) => {
          const child = spawn(
            CLAUDE_BIN,
            ['-p', '--output-format', 'stream-json', '--verbose', '--include-partial-messages',
              '--permission-mode', 'acceptEdits', '--allowedTools', 'Bash,Edit,Write,Read,Grep,Glob',
              '--model', delegateModel],
            { cwd: spawnCwd, stdio: ['pipe', 'pipe', 'pipe'] },
          )
          let stdout = '', stderr = '', textStream = '', lineBuffer = ''
          let lastEventAt = Date.now()
          let currentTool: string | null = null
          let killReason: KillReason = null

          const processLine = (line: string) => {
            const trimmed = line.trim()
            if (!trimmed) return
            let ev: any
            try { ev = JSON.parse(trimmed) } catch { return }
            const content = ev?.message?.content ?? ev?.content
            if (Array.isArray(content)) {
              for (const block of content) {
                if (block.type === 'text' && typeof block.text === 'string') textStream += block.text
                else if (block.type === 'tool_use') { currentTool = block.name; toolCount++ }
                else if (block.type === 'tool_result') currentTool = null
              }
            } else if (typeof content === 'string') textStream += content
            if (ev?.type === 'result' && typeof ev.result === 'string') textStream += ev.result
          }

          child.stdout.on('data', (d) => {
            const chunk = String(d); stdout += chunk; lastEventAt = Date.now()
            lineBuffer += chunk
            const lines = lineBuffer.split('\n'); lineBuffer = lines.pop() ?? ''
            for (const l of lines) processLine(l)
          })
          child.stderr.on('data', (d) => { stderr += String(d); lastEventAt = Date.now() })

          const silenceTick = setInterval(() => {
            const silence = Date.now() - lastEventAt
            if (silence > (currentTool ? SILENCE_TOOL_MS : SILENCE_IDLE_MS)) {
              killReason = currentTool ? 'tool-silence' : 'idle-silence'
              child.kill('SIGTERM')
            }
          }, 30_000)
          const hardCap = setTimeout(() => { killReason = 'hardCap'; child.kill('SIGTERM') }, HARD_CAP_MS)

          child.on('close', (code) => {
            clearInterval(silenceTick); clearTimeout(hardCap)
            if (lineBuffer) processLine(lineBuffer)
            resolve({ stdout, stderr, textStream, code: code ?? 1, killReason })
          })
          child.stdin.write(fullPrompt)
          child.stdin.end()
        })

        if (worktreeCreated) {
          try { execFileSync('git', ['worktree', 'remove', '--force', worktreeDir], { cwd: repoCwd, stdio: 'pipe' }) } catch { /* best effort */ }
        }

        const combined = `${result.textStream}\n${result.stderr}`
        const prMatch = combined.match(/PR_NUMBER=(\d+)/)
        const directMatch = combined.match(/DIRECT_PUSH=([a-f0-9]+)/)
        const commitMatch = combined.match(/COMMIT_PUSHED=([a-f0-9]+)/)
        const analysisMatch = combined.match(/ANALYSIS_COMPLETE=([^\n]+)/)
        const failedMatch = combined.match(/DELEGATE_FAILED=([^\n]+)/)
        const timedOut = result.killReason !== null || result.code === 143
        const prNumber = prMatch ? Number(prMatch[1]) : undefined
        const directPushSha = directMatch ? directMatch[1] : undefined
        const commitPushedSha = commitMatch ? commitMatch[1] : undefined
        const analysisComplete = analysisMatch ? analysisMatch[1].trim() : undefined
        const pushed = prNumber !== undefined || directPushSha !== undefined || commitPushedSha !== undefined

        let summary: string
        if (timedOut) summary = `[${t.id}] timed out (${result.killReason}) after ${toolCount} tools`
        else if (prNumber) summary = `[${t.id}] opened PR #${prNumber} (${toolCount} tools)`
        else if (commitPushedSha) summary = `[${t.id}] pushed ${commitPushedSha.slice(0, 9)} to existing PR (${toolCount} tools)`
        else if (directPushSha) summary = `[${t.id}] pushed ${directPushSha.slice(0, 9)} to master (${toolCount} tools)`
        else if (analysisComplete) summary = `[${t.id}] analysis done: ${analysisComplete.slice(0, 60)}`
        else if (failedMatch) summary = `[${t.id}] DELEGATE_FAILED: ${failedMatch[1].trim().slice(0, 60)}`
        else summary = `[${t.id}] exited ${result.code} (${toolCount} tools)`
        out(summary)

        // A timed-out agent did not complete its protocol (no OUTCOME marker, no
        // self-review), so any PR it managed to open is incomplete/unverified work.
        // Close it so it never reaches the operator as a "ready" fix (e.g. #666);
        // VERIFY_DISCOURSE_BATCH defers the bug for human triage. Only FSM fix/ or
        // fix- branches that are still OPEN; the marker keeps it out of the human
        // wontfix path. Best-effort — a gh failure must not break result collection.
        let autoClosedTimedOut = false
        if (timedOut && prNumber !== undefined) {
          try {
            const { stdout: bo } = await exec(
              'gh', ['pr', 'view', String(prNumber), '--repo', 'Freegle/Iznik', '--json', 'headRefName,state', '-q', '.headRefName + " " + .state'],
              { timeout: 20 * 1000 },
            )
            const [br, st] = (bo || '').trim().split(' ')
            if (/^fix[/-]/.test(br ?? '') && st === 'OPEN') {
              await exec(
                'gh', ['pr', 'close', String(prNumber), '--repo', 'Freegle/Iznik', '--delete-branch', '--comment',
                  `Auto-closed: the fix agent for this PR timed out before completing its protocol (no verification or self-review), so the PR is incomplete. The bug is deferred for human triage. Reopen if this was closed in error.\n${FSM_AUTOCLOSE_MARKER}`],
                { timeout: 30 * 1000 },
              )
              autoClosedTimedOut = true
              out(`delegate_parallel_tasks: auto-closed incomplete PR #${prNumber} from timed-out task ${t.id}`)
            }
          } catch (e: any) {
            outWarn(`delegate_parallel_tasks: could not close timed-out PR #${prNumber}: ${e.message}`)
          }
        }

        return {
          id: t.id,
          exitCode: result.code,
          timedOut,
          timeoutReason: result.killReason,
          autoClosedTimedOut,
          prNumber,
          directPushSha,
          commitPushedSha,
          pushed,
          analysisComplete,
          failedReason: failedMatch ? failedMatch[1].trim() : undefined,
          stdoutTail: redactSecrets(result.textStream.slice(-1500)),
          stderrTail: redactSecrets(result.stderr.slice(-500)),
          summary,
        }
      }

      out(`▸ delegate_parallel_tasks: launching ${tasks.length} agents in parallel`)
      const results = await Promise.all(tasks.map((t, i) => runTask(t, i)))
      const succeeded = results.filter(r => r.pushed).length
      out(`◂ delegate_parallel_tasks: ${succeeded}/${tasks.length} tasks pushed`)
      return { results }
    },
  },

  {
    name: 'write_summary',
    description: 'Write /tmp/freegle-monitor/summary.md. Params: {content: string}',
    paramsSchema: {
      type: 'object',
      properties: { content: { type: 'string' } },
      required: ['content'],
    },
    handler: async (params) => {
      const content = params.content as string
      await writeFile(SUMMARY_PATH, content, 'utf8')
      return { written: SUMMARY_PATH, bytes: content.length }
    },
  },


  {
    name: 'schedule_wakeup',
    description: 'Record that a wakeup should be scheduled. The driver wraps this — it returns the recorded delay, but the caller must actually call ScheduleWakeup from the host shell. Params: {delaySeconds, reason}',
    paramsSchema: {
      type: 'object',
      properties: { delaySeconds: { type: 'number' }, reason: { type: 'string' } },
      required: ['delaySeconds', 'reason'],
    },
    handler: async (params) => {
      return { scheduled: true, delaySeconds: params.delaySeconds, reason: params.reason }
    },
  },

  // ─── Tool-node helpers ──────────────────────────────────────────────────
  // These actions encode logic that used to be LLM decisions. They return a
  // `_transition` key so the driver's tool-node fast-path can pick the next
  // state without calling the LLM.

  {
    name: 'coverage_gate_decide',
    description: 'Pure-logic branch for COVERAGE_GATE. Priority: (1) red CI → CI_ROUTER; (2) dirty/needs-rebase PRs → REBASE_DIRTY_PRS; (3) PRs created this iteration → WRAP_UP; (4) else → WRITE_COVERAGE.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const verifyDef = actions.find(a => a.name === 'verify_pr_created')!
      const redDef = actions.find(a => a.name === 'check_my_open_pr_ci')!
      const iterationStartTs = (context as any)?.iterationStartTs as string | undefined
      const verify = await verifyDef.handler({ iterationStartTs }, context)
      const red = await redDef.handler({}, context)
      const v = verify as any
      const r = red as any
      const redCount = Array.isArray(r.redPRs) ? r.redPRs.length : 0
      const prCount = typeof v.count === 'number' ? v.count : 0

      // Check for PRs that need rebase (mergeStateStatus === 'DIRTY')
      const listRes = await sh('gh', [
        'pr', 'list', '--repo', 'Freegle/Iznik', '--author', '@me',
        '--state', 'open', '--limit', '30', '--json', 'number,title,headRefName',
      ])
      const openPRs = listRes.code === 0 ? JSON.parse(listRes.stdout) as Array<{ number: number; title: string; headRefName: string }> : []
      const dirtyPRs: Array<{ number: number; title: string; branch: string }> = []
      for (const pr of openPRs) {
        const viewRes = await sh('gh', ['pr', 'view', String(pr.number), '--repo', 'Freegle/Iznik', '--json', 'mergeStateStatus'])
        if (viewRes.code === 0) {
          const { mergeStateStatus } = JSON.parse(viewRes.stdout)
          if (mergeStateStatus === 'DIRTY') dirtyPRs.push({ number: pr.number, title: pr.title, branch: pr.headRefName })
        }
      }

      const pendingCount = Array.isArray(r.pendingPRs) ? r.pendingPRs.length : 0
      let target: string
      if (redCount > 0) target = 'CI_ROUTER'
      else if (dirtyPRs.length > 0) target = 'REBASE_DIRTY_PRS'
      else if (pendingCount > 0) target = 'WRAP_UP'  // drain mode — CI running, don't create coverage PRs
      else if (prCount > 0) target = 'WRAP_UP'
      else target = 'WRITE_COVERAGE'
      return { count: prCount, redCount, pendingCount, dirtyPRs, verify, red, _transition: target }
    },
  },

  {
    name: 'compose_and_write_summary',
    description: 'Render the monitor summary markdown from the DB state + this iteration\'s context and write it to /tmp/freegle-monitor/summary.md. Replaces the LLM-composed WRAP_UP step. No params; reads context.bugsFixed, iterationStartTs, and DB rows.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const iterationStartTs = ctx?.iterationStartTs ?? new Date().toISOString()
      const phase = ctx?.phase ?? 'unknown'
      const bugsFixed: Array<any> = Array.isArray(ctx?.bugsFixed) ? ctx.bugsFixed : []
      const verify = ctx?._action_coverage_gate_decide?.verify ?? ctx?._action_verify_pr_created ?? {}
      const prsThisIter: Array<any> = Array.isArray(verify?.prs) ? verify.prs : []

      const lines: string[] = []
      lines.push('# Freegle Monitor — iteration summary', '')
      lines.push(`**Full bug list (live):** ${DISCOURSE_BASE}/t/bug-reports-live-summary/9599`, '')
      lines.push(`- Iteration start: ${iterationStartTs}`)
      lines.push(`- Phase: ${phase}`)
      lines.push('')
      lines.push('## PRs this iteration', '')
      if (prsThisIter.length === 0) {
        lines.push('_None._', '')
      } else {
        for (const p of prsThisIter) {
          const title = p.title ?? ''
          lines.push(`- [#${p.number}](${p.url ?? `https://github.com/Freegle/Iznik/pull/${p.number}`}) ${title}`)
        }
        lines.push('')
      }
      const fixed = bugsFixed.filter(b => b.outcome === 'fixed')
      const deferred = bugsFixed.filter(b => b.outcome === 'deferred')
      if (fixed.length > 0) {
        lines.push('## Discourse bugs: fix PRs opened', '')
        for (const b of fixed) {
          lines.push(`- ${b.topic}.${b.post} @${b.user ?? 'reporter'}${b.prNumber ? ` → PR #${b.prNumber} (awaiting merge + deploy)` : ''}`)
        }
        lines.push('')
      }
      if (deferred.length > 0) {
        lines.push('## Discourse bugs deferred', '')
        for (const b of deferred) {
          lines.push(`- ${b.topic}.${b.post} @${b.user ?? 'reporter'} — ${b.reason ?? 'no reason recorded'}`)
        }
        lines.push('')
      }
      const content = lines.join('\n')
      await writeFile(SUMMARY_PATH, content, 'utf8')
      return { written: SUMMARY_PATH, bytes: content.length, prCount: prsThisIter.length, fixedCount: fixed.length, deferredCount: deferred.length }
    },
  },

  {
    name: 'compose_and_send_email',
    description: 'No-op: email summaries replaced by the dashboard. Returns skipped immediately.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, _context) => {
      return { skipped: true, reason: 'email replaced by dashboard' }
    },
  },

  {
    name: '_email_retired',
    description: 'RETIRED — never called',
    paramsSchema: { type: 'object', properties: {} },
    handler: async () => ({ skipped: true, reason: 'retired' }),
  },

  {
    name: 'ci_router_decide',
    description: 'Phase A router logic. No LLM — pure branch based on CHECK_CI results + iteration context. Priority: (1) master red → FIX_MASTER_CI; (2) production red → FIX_PRODUCTION_CI; (3) drain if pending PRs; (4) fix focus PR. Returns {_transition: "FIX_MASTER_CI" | "FIX_PRODUCTION_CI" | "PARALLEL_ANALYZE_AND_FIX" | "WRAP_UP"}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const master = ctx?._action_check_master_ci ?? {}
      const production = ctx?._action_check_production_ci ?? {}
      const prCheck = ctx?._action_check_my_open_pr_ci ?? {}
      const masterFailing = master.failing === true
      const productionFailing = production.failing === true
      const masterFixAttempted = ctx?.masterFixAttempted === true
      const productionFixAttempted = ctx?.productionFixAttempted === true
      const redPRs: Array<{ number: number }> = Array.isArray(prCheck.redPRs) ? prCheck.redPRs : []
      const attempts: Array<{ prNumber: number; terminal?: boolean }> = Array.isArray(ctx?.openPRFixAttempts) ? ctx.openPRFixAttempts : []
      // Allow re-picking a PR whose latest attempt did not push a commit —
      // matches the original LLM prompt's "keep trying" rule. A terminal
      // record (loop-breaker) is respected.
      const attemptedNums = new Set(attempts.filter(a => a.terminal).map(a => a.prNumber))

      // Persistent per-PR fix attempt budget: if a PR has been picked >= 3 times
      // across iterations (tracked in SQLite kv) without going green, give up and
      // move to the next PR. The counter resets when the PR's CI goes green
      // (see check_my_open_pr_ci). This prevents the FSM from thrashing on a single
      // PR indefinitely while other PRs wait.
      const MAX_FIX_ATTEMPTS = 3
      const db = getDb()
      const exhaustedPRs = new Set<number>()
      for (const pr of redPRs) {
        const countStr = kvGet(db, `pr_fix_attempts_${pr.number}`)
        const count = countStr ? parseInt(countStr, 10) : 0
        if (count >= MAX_FIX_ATTEMPTS) {
          exhaustedPRs.add(pr.number)
          out(`ci_router_decide: PR #${pr.number} exhausted after ${count} fix attempts — skipping this iteration`)
        }
      }

      // Priority 1: master red
      if (masterFailing && !masterFixAttempted) {
        return { _transition: 'FIX_MASTER_CI', reason: `master CI failing on run ${master.latestRun?.databaseId ?? '?'}` }
      }
      // Priority 1.5: production red — production branch has a failing CI run.
      // Production is auto-promoted from master; fixes should generally target master
      // (so they get auto-promoted) unless the failure is production-specific config.
      if (productionFailing && !productionFixAttempted) {
        out(`ci_router_decide: production CI failing (sha=${production.sha?.slice(0, 9) ?? '?'})`)
        return { _transition: 'FIX_PRODUCTION_CI', reason: `production CI failing on sha ${production.sha?.slice(0, 9) ?? '?'}` }
      }
      // Priority 2: drain mode — only when pending CI SATURATES the cloud runners.
      // We run on ~5 Katapult cloud runners now, NOT a single self-hosted runner,
      // so a handful of pending PRs run concurrently and do not need serialising.
      // The old rule (drain on ANY pending PR) was catastrophic: a single in-flight
      // PR (e.g. one with a flaky/infra-failing build that re-runs forever) would
      // WRAP_UP every iteration and block ALL Discourse bug work indefinitely.
      // Only drain when pending count reaches runner capacity, to avoid piling
      // unbounded load onto the queue; below that, proceed to fix/bug work.
      const MAX_CONCURRENT_CI = 5
      const pendingPRs: Array<any> = Array.isArray(prCheck.pendingPRs) ? prCheck.pendingPRs : []
      if (pendingPRs.length >= MAX_CONCURRENT_CI) {
        return {
          _transition: 'WRAP_UP',
          reason: `drain mode — ${pendingPRs.length} PR(s) have pending/running CI (>= ${MAX_CONCURRENT_CI} cloud-runner capacity); holding until CI settles`,
          drainMode: true,
          pendingCount: pendingPRs.length,
        }
      }
      // Priority 3: master green, CI queue empty — fix ONE focus PR, then do discourse/sentry
      // in parallel. Fixing all red PRs simultaneously floods the single runner queue;
      // focusing on one PR drives it to green (ready for human to merge) before touching the next.
      const activeTopics = (ctx?._action_discover_active_topics?.topics ?? []) as Array<{ id: number; hasNew?: boolean }>
      const topicsWithNew = activeTopics.filter(t => t.hasNew).length
      const phase = ctx?.phase ?? 'analysis'

      // Pick the ONE focus PR: prefer the current focus if it is still red & not exhausted;
      // otherwise advance to the next pickable red PR. This drives one PR to completion
      // before touching the next, rather than making marginal progress on all of them.
      const pickableRedPRs = redPRs.filter(p => !exhaustedPRs.has(p.number) && !attemptedNums.has(p.number))
      const storedFocus = kvGet(db, 'focus_pr_number')
      const storedFocusNum = storedFocus ? parseInt(storedFocus, 10) : null
      const keepFocus = storedFocusNum !== null && pickableRedPRs.some(p => p.number === storedFocusNum)
      const focusPR = keepFocus
        ? pickableRedPRs.find(p => p.number === storedFocusNum)!
        : pickableRedPRs[0]

      if (focusPR && focusPR.number !== storedFocusNum) {
        kvSet(db, 'focus_pr_number', String(focusPR.number))
        out(`ci_router_decide: new focus PR → #${focusPR.number}`)
      }

      if (focusPR) {
        const key = `pr_fix_attempts_${focusPR.number}`
        const prev = parseInt(kvGet(db, key) ?? '0', 10)
        kvSet(db, key, String(prev + 1))
        out(`ci_router_decide: PR #${focusPR.number} fix attempt ${prev + 1}/${MAX_FIX_ATTEMPTS} (focus PR)`)
      }

      // onlyFixPR: when there IS a focus PR to fix, the iteration should ONLY
      // run the PR fix agent — no Discourse triage, no bug dispatch, no Sentry.
      // This prevents newly-created bug PRs from flooding the CI queue and
      // wasting tokens analysing work we couldn't act on anyway.
      const onlyFixPR = focusPR != null

      return {
        _transition: 'PARALLEL_ANALYZE_AND_FIX',
        reason: `master green, CI queue empty — focus PR: ${focusPR ? `#${focusPR.number}` : 'none'} (${pickableRedPRs.length} red, ${exhaustedPRs.size} exhausted) + ${topicsWithNew} active topics (${phase} phase)${onlyFixPR ? ' [onlyFixPR: triage skipped]' : ''}`,
        focusPRNumber: focusPR?.number ?? null,
        onlyFixPR,
        exhaustedPRNumbers: [...exhaustedPRs],
        exhaustedPRCount: exhaustedPRs.size,
        activeTopicCount: topicsWithNew,
      }
    },
  },

  {
    name: 'persist_classifications',
    description: 'Persist TRIAGE classifications to the discourse_bug table so the status post reflects all identified bugs, not just ones with PRs. Upserts each bug/retest classification as "open" (or "deferred" if type is deferred, "feature-request" if type is feature_request). Already-fixed bugs are not downgraded. Returns {upserted: number, skipped: number}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const classifications: Array<any> = Array.isArray(ctx?.classifications) ? ctx.classifications : []
      const db = getDb()
      let upserted = 0, skipped = 0
      for (const c of classifications) {
        // Accept both 'post' and 'post_number' — topic delegates emit post_number
        const post = c.post ?? c.post_number
        if (!c.topic || !post) { skipped++; continue }
        c.post = post
        // Only persist actionable classifications — skip mine/off_topic/already_fixed/confirmed
        const type = c.type as string
        if (!['bug', 'retest', 'deferred', 'question', 'feature_request'].includes(type)) { skipped++; continue }

        // Feature requests are stored directly — no dedup, no regression check, no git-fixed check.
        if (type === 'feature_request') {
          const existing = db.prepare('SELECT state FROM discourse_bug WHERE topic = ? AND post = ?').get(c.topic, post) as { state: string } | undefined
          if (existing && existing.state === 'feature-request') { skipped++; continue }
          upsertDiscourseBug(db, {
            topic: Number(c.topic), post: Number(post),
            topicTitle: c.topicTitle ?? null, reporter: c.user ?? null,
            excerpt: c.summary ?? c.originalPostText?.slice(0, 200) ?? null,
            state: 'feature-request', featureArea: c.featureArea ?? undefined,
            reason: c.reason ?? undefined,
          })
          out(`persist_classifications: topic ${c.topic}/${post} persisted as feature-request`)
          upserted++
          continue
        }

        const state = type === 'deferred' ? 'deferred' : type === 'question' ? 'deferred' : 'open'
        // Don't downgrade a bug already in fix-queued or fixed state
        const existing = db.prepare('SELECT state FROM discourse_bug WHERE topic = ? AND post = ?').get(c.topic, c.post) as { state: string } | undefined
        if (existing && ['fix-queued', 'fixed', 'confirmed', 'investigating'].includes(existing.state)) { skipped++; continue }

        // Retest: a follow-up confirmation of an existing bug in the same topic.
        // Update the existing bug's last_seen_at; do NOT create a second entry for the same problem.
        if (type === 'retest') {
          const existingTopicBug = db.prepare(
            `SELECT topic, post FROM discourse_bug WHERE topic = ? AND post != ? AND state IN ('open','investigating') LIMIT 1`
          ).get(Number(c.topic), Number(post)) as { topic: number; post: number } | undefined
          if (existingTopicBug) {
            db.prepare(`UPDATE discourse_bug SET last_seen_at = datetime('now') WHERE topic = ? AND post = ?`)
              .run(existingTopicBug.topic, existingTopicBug.post)
            out(`persist_classifications: ${c.topic}/${post} is retest of existing bug ${existingTopicBug.topic}/${existingTopicBug.post} — updating timestamp, skipping new entry`)
            skipped++
            continue
          }
          // No existing open bug → treat as potential regression, fall through to persist as 'open'
        }
        // Parse tags from classification output (TRIAGE emits symptom_tags + code_area)
        const symptomTags: string[] = Array.isArray(c.symptom_tags)
          ? c.symptom_tags.map((t: unknown) => String(t).toLowerCase())
          : []
        const codeArea: string | null = typeof c.code_area === 'string' ? c.code_area : null

        // Dedup level 1: same Discourse topic already has an active PR → link, don't open new
        const topicPrRow = db.prepare(
          `SELECT pr_number FROM discourse_bug
           WHERE topic = ? AND post != ? AND pr_number IS NOT NULL
           AND state IN ('open', 'investigating', 'fix-queued')`
        ).get(c.topic, c.post) as { pr_number: number } | undefined
        if (topicPrRow) {
          upsertDiscourseBug(db, {
            topic: Number(c.topic), post: Number(c.post),
            topicTitle: c.topicTitle ?? null, reporter: c.user ?? null,
            excerpt: c.summary ?? c.originalPostText?.slice(0, 200) ?? null,
            state: 'fix-queued', prNumber: topicPrRow.pr_number,
            featureArea: c.featureArea ?? undefined, symptomTags, codeArea: codeArea ?? undefined,
          })
          out(`persist_classifications: topic ${c.topic}/${c.post} linked to existing PR #${topicPrRow.pr_number} (same topic)`)
          upserted++
          continue
        }

        // Dedup level 2: cross-topic tag similarity — if an open bug in a DIFFERENT topic
        // shares ≥50% symptom tags and the same code area, mark this as a duplicate rather
        // than opening a second PR. (Research: component filter first, then text similarity.)
        if (symptomTags.length > 0) {
          const tagDup = findTagDuplicate(db, symptomTags, codeArea, Number(c.topic), Number(c.post))
          if (tagDup) {
            upsertDiscourseBug(db, {
              topic: Number(c.topic), post: Number(c.post),
              topicTitle: c.topicTitle ?? null, reporter: c.user ?? null,
              excerpt: c.summary ?? c.originalPostText?.slice(0, 200) ?? null,
              state: 'duplicate',
              prNumber: tagDup.pr_number ?? undefined,
              featureArea: c.featureArea ?? undefined, symptomTags, codeArea: codeArea ?? undefined,
              reason: `Duplicate of topic ${tagDup.topic}/${tagDup.post} (tag overlap)`,
            })
            const cancelled = cancelDraftsForBug(db, Number(c.topic), Number(c.post), `Bug marked duplicate of topic ${tagDup.topic}/${tagDup.post}`)
            if (cancelled > 0) out(`persist_classifications: cancelled ${cancelled} pending draft(s) for duplicate bug ${c.topic}/${c.post}`)
            out(`persist_classifications: topic ${c.topic}/${c.post} marked duplicate of ${tagDup.topic}/${tagDup.post} (tag similarity)`)
            upserted++
            continue
          }
        }

        // Dedup level 3: regression detection — if there's already a FIXED bug in the same
        // topic, this new report might mean the fix didn't work. Only flag as regression when
        // symptom_tags overlap with the prior fix — completely different symptoms in the same
        // topic mean a new independent bug, not a regression of the prior PR.
        let finalState = state
        let finalReason: string | undefined = type === 'deferred' ? (c.reason ?? 'deferred by triage') : undefined
        if ((type === 'bug' || type === 'retest') && finalState === 'open') {
          const priorFix = db.prepare(`
            SELECT pr_number, symptom_tags FROM discourse_bug
            WHERE topic = ? AND state = 'fixed' AND pr_number IS NOT NULL
            ORDER BY fixed_at DESC LIMIT 1
          `).get(Number(c.topic)) as { pr_number: number; symptom_tags: string | null } | undefined
          if (priorFix) {
            const priorTags: string[] = (() => {
              try { return JSON.parse(priorFix.symptom_tags ?? '[]') } catch { return [] }
            })()
            // If both have non-empty tags but zero overlap, it's a new independent bug
            const isIndependentBug = symptomTags.length > 0 && priorTags.length > 0 && tagJaccard(symptomTags, priorTags) === 0
            if (!isIndependentBug) {
              finalState = 'deferred'
              finalReason = `REGRESSION: fix PR #${priorFix.pr_number} confirmed not working — needs human review`
              out(`persist_classifications: topic ${c.topic}/${c.post} flagged regression (PR #${priorFix.pr_number} didn't fix it)`)
            }
          }
        }

        // Git cross-reference: before persisting as 'open', check if master already
        // has a commit that fixes this. Keywords come from code_area, featureArea, and
        // the first significant words of the summary. If we find a fix/ or feat/ commit
        // on master touching the right area, mark it as already fixed+deployed so the
        // FSM doesn't re-diagnose something already shipped.
        if (finalState === 'open') {
          const gitFixed = await checkGitAlreadyFixed(codeArea, c.featureArea ?? null, c.summary ?? c.title ?? '')
          if (gitFixed) {
            out(`persist_classifications: topic ${c.topic}/${c.post} already fixed on master — ${gitFixed}`)
            upsertDiscourseBug(db, {
              topic: Number(c.topic), post: Number(c.post),
              topicTitle: c.topicTitle ?? null, reporter: c.user ?? null,
              excerpt: c.summary ?? c.originalPostText?.slice(0, 200) ?? null,
              state: 'fixed', featureArea: (c.featureArea as string) ?? undefined,
              reason: `Already fixed on master: ${gitFixed}`,
              symptomTags, codeArea: codeArea ?? undefined,
            })
            db.prepare(`UPDATE discourse_bug SET fixed_at = datetime('now'), deployed_at = datetime('now') WHERE topic = ? AND post = ?`).run(Number(c.topic), Number(c.post))
            upserted++
            continue
          }
        }

        upsertDiscourseBug(db, {
          topic: Number(c.topic), post: Number(c.post),
          topicTitle: c.topicTitle ?? null, reporter: c.user ?? null,
          excerpt: c.summary ?? c.originalPostText?.slice(0, 200) ?? null,
          state: finalState, featureArea: (c.featureArea as string) ?? undefined,
          reason: finalReason,
          symptomTags, codeArea: codeArea ?? undefined,
        })
        upserted++
      }
      return { upserted, skipped }
    },
  },

  {
    name: 'reparse_recent_topics',
    description: 'Reset topic cursors for Discourse topics that have deferred/open bug entries from the last N days so that the next triage iteration re-fetches those posts and can reclassify them (e.g. to feature_request). Self-guards with a KV key so it only runs once per schema version — safe to call unconditionally. Returns {reset: number, topics: number[], skipped?: string}. Params: {days?: number} (default 14).',
    paramsSchema: {
      type: 'object',
      properties: {
        days: { type: 'number', description: 'How many days back to look (default 14).' },
      },
    },
    handler: async (params: any) => {
      const db = getDb()
      const KV_KEY = 'reparse_done_v4'
      if (kvGet(db, KV_KEY) === '1') {
        return { reset: 0, topics: [], skipped: 'already ran for schema v4' }
      }

      const days: number = typeof params?.days === 'number' ? params.days : 14
      // Find topics with deferred entries in the last N days
      const cutoff = new Date(Date.now() - days * 24 * 3600 * 1000).toISOString()
      const staleEntries = db.prepare(`
        SELECT topic, MIN(post) AS min_post
        FROM discourse_bug
        WHERE state IN ('deferred', 'open')
          AND last_seen_at >= ?
        GROUP BY topic
      `).all(cutoff) as Array<{ topic: number; min_post: number }>

      let reset = 0
      const topics: number[] = []
      for (const entry of staleEntries) {
        // Reset cursor to just before the earliest post we want re-classified
        const targetPost = Math.max(0, entry.min_post - 1)
        setTopicCursor(db, entry.topic, targetPost)
        topics.push(entry.topic)
        reset++
        out(`reparse_recent_topics: reset cursor for topic ${entry.topic} to ${targetPost}`)
      }

      kvSet(db, KV_KEY, '1')
      return { reset, topics }
    },
  },

  {
    name: 'work_router_decide',
    description: 'Phase B router logic. No LLM — branches on context.classifications and context.bugsFixed. Returns {_transition: "DIAGNOSE_BUG" | "FIX_SENTRY_ISSUE" | "COVERAGE_GATE"}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const phase = ctx?.phase ?? 'analysis'
      const classifications: Array<any> = Array.isArray(ctx?.classifications) ? ctx.classifications : []
      const bugsFixed: Array<any> = Array.isArray(ctx?.bugsFixed) ? ctx.bugsFixed : []
      const fixedKeys = new Set(bugsFixed.map(b => `${b.topic}.${b.post}`))
      const pendingBugs = classifications.filter(c => (c.type === 'bug' || c.type === 'retest') && !fixedKeys.has(`${c.topic}.${c.post}`))

      // Always check DB for open bugs regardless of phase.
      // Note: 'investigating' means Edward has posted a fix — FSM should not duplicate that work.
      const db = getDb()
      const dbOpenBugs = (db.prepare(`
        SELECT topic, post, reporter, excerpt, feature_area AS featureArea, topic_title AS topicTitle,
               pr_rejections AS prRejections, symptom_tags AS symptomTagsJson
        FROM discourse_bug
        WHERE state = 'open' AND pr_number IS NULL
      `).all() as Array<any>).filter(b => !fixedKeys.has(`${b.topic}.${b.post}`))

      // Escalate to human only after a re-diagnosed SECOND attempt still failed.
      // A single rejected PR usually means the first diagnosis was wrong, not that
      // the bug is unfixable — the FSM should re-diagnose and retry (delegates now
      // run on Opus 4.8). The 2026-05-31 sweep found ~7 genuinely-actionable bugs
      // (9672, 9719, 9737, 9738, 9656/36) abandoned after a single rejection.
      const ESCALATION_THRESHOLD = 2
      const toEscalate = dbOpenBugs.filter(b => (b.prRejections ?? 0) >= ESCALATION_THRESHOLD)
      for (const bug of toEscalate) {
        db.prepare(`
          UPDATE discourse_bug SET state = 'deferred',
            reason = 'Escalated: ' || ? || ' rejected PR(s) — needs human triage',
            last_seen_at = datetime('now')
          WHERE topic = ? AND post = ?
        `).run(bug.prRejections, bug.topic, bug.post)
        // Escalated bugs are visible via the dashboard — no Discourse draft needed.
        // Queuing an internal "I'm stuck" message to the reporter is wrong: the human
        // operator sees deferred bugs through the FSM dashboard, not through Discourse.
        out(`work_router_decide: escalated ${bug.topic}/${bug.post} after ${bug.prRejections} rejected PRs`)
      }

      // Bugs with < ESCALATION_THRESHOLD rejections can be dispatched.
      const classificationKeys = new Set(classifications.map((c: any) => `${c.topic}.${c.post}`))
      const extraBugs = dbOpenBugs
        .filter(b => (b.prRejections ?? 0) < ESCALATION_THRESHOLD)
        .filter(b => !classificationKeys.has(`${b.topic}.${b.post}`))

      // During peak phase, defer only if there are no backlog bugs to dispatch.
      if (phase === 'implementation' && extraBugs.length === 0) {
        const pendingCount = pendingBugs.length
        return { _transition: 'COVERAGE_GATE', reason: `peak phase — no backlog bugs; deferring ${pendingCount} new bug(s) to off-peak` }
      }

      // Recent merged PR dedup: skip bugs that appear already covered by a recently
      // merged PR. The caller passes recentlyMergedPRs [{number, title}] in context.
      // A bug is considered "covered" if any merged PR title contains its featureArea
      // or any of its symptomTags as a substring (case-insensitive, min 3 chars).
      const recentlyMergedPRs: Array<{ number: number; title: string }> =
        Array.isArray(ctx?.recentlyMergedPRs) ? ctx.recentlyMergedPRs : []
      const isLikelyCoveredByMergedPR = (bug: any): boolean => {
        if (recentlyMergedPRs.length === 0) return false
        let tags: string[] = []
        try { tags = JSON.parse(bug.symptomTagsJson ?? '[]') } catch { /* ignore */ }
        const area = (bug.featureArea ?? '').toLowerCase()
        const signals = [...tags.map((t: string) => t.toLowerCase()), ...(area.length >= 3 ? [area] : [])]
        if (signals.length === 0) return false
        return recentlyMergedPRs.some(pr => {
          const title = pr.title.toLowerCase()
          return signals.some(s => s.length >= 3 && title.includes(s))
        })
      }

      // Dedup: skip bugs whose Discourse topic already has an active PR on another post,
      // or which are marked as duplicates. Cross-topic tag dedup is handled at persist
      // time; this handles the in-memory classifications path (seen this iteration).
      const topicsWithActivePr = new Set(
        (db.prepare(
          `SELECT DISTINCT topic FROM discourse_bug
           WHERE pr_number IS NOT NULL AND state IN ('open', 'investigating', 'fix-queued')`
        ).all() as Array<{ topic: number }>).map((r: { topic: number }) => r.topic)
      )
      // Skip topics where Edward posted this iteration (type='mine'): he's actively
      // engaged (e.g. waiting for a retest) and the FSM should not duplicate that work.
      const topicsWithMinePost = new Set(
        classifications.filter(c => c.type === 'mine').map(c => Number(c.topic))
      )
      const allPending = [...pendingBugs, ...extraBugs.map(b => ({ ...b, type: 'bug' }))]
        .filter(b => !topicsWithActivePr.has(Number(b.topic)))
        .filter(b => !topicsWithMinePost.has(Number(b.topic)))
        .filter(b => !isLikelyCoveredByMergedPR(b))

      if (allPending.length > 0) {
        // Dispatch a BATCH of up to MAX_PARALLEL_BUGS bugs (oldest first_seen_at
        // first) to PARALLEL_FIX_BUGS, which runs one fully self-contained
        // diagnose→test→fix→PR task per bug via delegate_parallel_tasks. Each
        // task gets its own isolated git worktree + branch + PR, and CI is now
        // Katapult cloud runners (~5 concurrent), so N PRs each get a runner
        // rather than queueing behind one. The old one-bug-at-a-time throttle
        // was premised on a single self-hosted runner that no longer exists.
        const MAX_PARALLEL_BUGS = 5
        const sorted = allPending.slice().sort((a, b) => {
          const at = typeof a.first_seen_at === 'string' ? a.first_seen_at : '9999'
          const bt = typeof b.first_seen_at === 'string' ? b.first_seen_at : '9999'
          return at < bt ? -1 : at > bt ? 1 : 0
        })
        // One bug per topic per dispatch: two posts on the same topic are almost
        // always the same underlying issue, so dispatching both spawns duplicate PRs
        // (e.g. #661/#662, both rewriting the same donate link for topic 9692). Keep
        // the oldest post per topic; a genuinely-distinct second bug is picked up a
        // later iteration (where the first post's PR makes topicsWithActivePr skip it).
        const seenDispatchTopics = new Set<number>()
        const bugBatch = sorted
          .filter((b) => {
            const t = Number(b.topic)
            if (seenDispatchTopics.has(t)) return false
            seenDispatchTopics.add(t)
            return true
          })
          .slice(0, MAX_PARALLEL_BUGS)
        const batchKeys = bugBatch.map(b => `${b.topic}.${b.post}`).join(', ')
        return {
          _transition: 'PARALLEL_FIX_BUGS',
          reason: `${allPending.length} unfixed bug(s) queued — dispatching ${bugBatch.length} in parallel (${batchKeys})${allPending.length > bugBatch.length ? `; ${allPending.length - bugBatch.length} to next iteration` : ''}`,
          dbOpenBugs: bugBatch.filter(b => !pendingBugs.some((p: any) => `${p.topic}.${p.post}` === `${b.topic}.${b.post}`)),
          bugBatch,
          // Keep singleBug for backward-compat with any path still reading it.
          singleBug: bugBatch[0],
        }
      }
      const sentry = ctx?._action_check_sentry ?? {}
      const sentryIssues: Array<any> = Array.isArray(sentry.issues) ? sentry.issues : []
      const sentryFixAttempted = ctx?.sentryFixAttempted === true
      if (sentryIssues.length > 0 && !sentryFixAttempted) {
        return { _transition: 'FIX_SENTRY_ISSUE', reason: `${sentryIssues.length} unresolved Sentry issue(s)` }
      }
      return { _transition: 'COVERAGE_GATE', reason: 'no pending bug / sentry — advance to gate' }
    },
  },

  {
    name: 'check_existing_prs',
    description: 'Search open PRs on Freegle/Iznik by keywords extracted from a bug description. Returns {existingPRs, keywords, searchedAt}. Used by DIAGNOSE_BUG to surface related open PRs before dispatching a fix.',
    paramsSchema: {
      type: 'object',
      properties: {
        bugDescription: { type: 'string', description: 'Bug symptom/summary text to extract keywords from.' },
        extraKeywords: { type: 'array', items: { type: 'string' }, description: 'Optional extra keywords (e.g. from featureArea or codeArea).' },
      },
      required: ['bugDescription'],
    },
    handler: async (params) => {
      const bugDescription = (params.bugDescription as string) ?? ''
      const extraKeywords = (params.extraKeywords as string[]) ?? []

      const stopWords = new Set([
        'the', 'a', 'an', 'is', 'in', 'on', 'at', 'to', 'of', 'and', 'or',
        'not', 'with', 'for', 'from', 'that', 'this', 'it', 'its',
        'when', 'after', 'before', 'if', 'then', 'are', 'was', 'be',
        'does', 'do', 'have', 'has', 'had', 'can', 'cannot', 'could', 'should',
        'will', 'would', 'fix', 'bug', 'issue', 'error', 'problem',
      ])

      // Extract meaningful keywords from description
      const descTokens = bugDescription
        .toLowerCase()
        .replace(/[^\w\s]/g, ' ')
        .split(/\s+/)
        .filter(t => t.length > 3 && !stopWords.has(t))
      const descKeywords = [...new Set(descTokens)]
        .sort((a, b) => b.length - a.length)
        .slice(0, 4)

      // Merge with any explicitly supplied extras (e.g. codeArea component name)
      const keywords = [...new Set([...descKeywords, ...extraKeywords.map(k => k.toLowerCase())])]

      try {
        const { stdout } = await exec('gh', [
          'pr', 'list',
          '--repo', 'Freegle/Iznik',
          '--state', 'open',
          '--limit', '50',
          '--json', 'number,title,url,state,createdAt',
        ], { maxBuffer: 5 * 1024 * 1024 })

        let allPRs: Array<{ number: number; title: string; url: string }> = []
        try {
          const parsed = JSON.parse(stdout.trim())
          if (Array.isArray(parsed)) {
            allPRs = parsed.map((p: any) => ({
              number: p.number ?? 0,
              title: p.title ?? '',
              url: p.url ?? '',
            }))
          }
        } catch { /* ignore JSON parse errors — return empty */ }

        const lowerKeywords = keywords.map(k => k.toLowerCase())
        const existingPRs = allPRs.filter(pr =>
          lowerKeywords.some(kw => pr.title.toLowerCase().includes(kw))
        )

        return { existingPRs, keywords, searchedAt: new Date().toISOString() }
      } catch (err: any) {
        outWarn(`[check_existing_prs] gh pr list failed: ${err.message}`)
        return { existingPRs: [], keywords, searchedAt: new Date().toISOString(), error: err.message }
      }
    },
  },

  {
    name: 'adversarial_review_pr',
    description: 'Review a PR using Opus model for correctness and unintended changes. Params: {prNumber, repo}. Returns {passed, issues: [{category, description, severity}], summary}.',
    paramsSchema: {
      type: 'object',
      properties: {
        prNumber: { type: 'number', description: 'PR number to review' },
        repo: { type: 'string', description: 'Repository in owner/name format', default: 'Freegle/Iznik' },
      },
      required: ['prNumber'],
    },
    handler: async (params, context) => {
      const { prNumber, repo = 'Freegle/Iznik' } = params as any
      try {
        // Fetch PR diff
        const { stdout: diff } = await exec('gh', [
          'pr', 'diff', String(prNumber),
          '--repo', repo,
        ], { maxBuffer: 50 * 1024 * 1024, timeout: 30 * 1000 })

        if (!diff || diff.trim().length === 0) {
          return {
            passed: false,
            issues: [{ category: 'diff', description: 'PR diff is empty or not accessible', severity: 'error' }],
            summary: 'Failed to fetch PR diff',
          }
        }

        // Review with Opus
        const phaseInfo = getPhaseInfo()
        const reviewModel = modelForAdversarialReview(phaseInfo)

        const prompt = `You are an adversarial code reviewer. Your job is to find problems, not to validate the author's work.

Review this PR diff using the following structured rubric. Classify each finding as CRITICAL, WARNING, or INFO.

CRITICAL (passed = false, must fix before merge):
- Partial implementation: the fix addresses the symptom but not the root cause
- Test proves nothing: test was written after the fix to confirm it doesn't crash, not to prove the bug existed first
- Security regression: SQL injection, path traversal, nil/null dereference, auth bypass, unvalidated user input reaching a write path
- Known regression: the diff removes or weakens an existing test that was passing
- Incomplete diff: the PR description claims to fix X but the diff doesn't touch the relevant code path
- Duplicate implementation: the fix reimplements logic that already exists as a helper elsewhere in the same codebase (look for similar function names or patterns in the diff context)

WARNING (passed = true, should be noted in PR):
- Other call sites with the same bug: the pattern fixed here appears to exist in adjacent files or sibling handlers — list the paths
- Dead code: unused variables, commented-out blocks, unreachable branches left over from the fix
- Naming/style inconsistency with the surrounding code
- TODO/FIXME in the changed lines (unfinished work in shipped code is a bug)

INFO (passed = true, note only):
- Minor style deviation that doesn't affect correctness
- Opportunities for simplification that are out of scope for a bug fix

COVERAGE CHECK (specific to bug-fix PRs):
- Does the test cover the exact trigger path described in the bug report?
- Does the test FAIL before the fix and PASS after? (A test that was green before the diff is not a reproduction test.)
- Is the failure assertion meaningful, or does it just check that the function doesn't throw?

Return ONLY a JSON object with exactly these keys:
{
  "passed": boolean,
  "blockers": [{"category": string, "description": string}],
  "warnings": [{"category": string, "description": string}],
  "info": [{"category": string, "description": string}],
  "summary": string
}

passed = false if and only if blockers is non-empty.
Be specific: "the test on line 47 only asserts status 200, not that the bug condition is absent" is useful; "tests could be improved" is not.

DIFF:
\`\`\`
${diff.slice(0, 20000)}
\`\`\`
${diff.length > 20000 ? '\n(diff truncated — only the first 20 000 chars shown)' : ''}`

        const response = await (global as any).__ai_flower_adapter.query(
          reviewModel,
          [{ role: 'user', content: prompt }],
          { max_tokens: 1500 }
        )

        let review: any
        try {
          const text = response.message?.content?.[0]?.text ?? ''
          const jsonMatch = text.match(/\{[\s\S]*\}/m)
          review = jsonMatch ? JSON.parse(jsonMatch[0]) : {}
        } catch (e) {
          dbg(`[adversarial-review] JSON parse error: ${e}`)
          review = {}
        }

        const passed = review.passed !== false && (!Array.isArray(review.blockers) || review.blockers.length === 0)
        const issues = [
          ...(Array.isArray(review.blockers) ? review.blockers.map((b: any) => ({ ...b, severity: 'error' })) : []),
          ...(Array.isArray(review.warnings) ? review.warnings.map((w: any) => ({ ...w, severity: 'warning' })) : []),
          ...(Array.isArray(review.info) ? review.info.map((i: any) => ({ ...i, severity: 'info' })) : []),
        ]

        // A failed review must NOT leave a bad PR open for the human to find (that's
        // how #661/#662/#663/#668/#670 reached the operator). Auto-close the PR; the
        // bug then re-enters the reopen→retry→escalate path via sync_pr_states. Only
        // touch FSM-authored fix branches (fix/ or fix-), never a human PR, and never
        // a passing one. The close comment carries FSM_AUTOCLOSE_MARKER so it is not
        // mistaken for a human wontfix (which would park instead of retry).
        let autoClosed = false
        if (!passed) {
          try {
            const { stdout: branchOut } = await exec(
              'gh', ['pr', 'view', String(prNumber), '--repo', repo, '--json', 'headRefName,state', '-q', '.headRefName + " " + .state'],
              { timeout: 20 * 1000 },
            )
            const [branch, state] = (branchOut || '').trim().split(' ')
            if (/^fix[/-]/.test(branch ?? '') && state === 'OPEN') {
              await exec(
                'gh', ['pr', 'close', String(prNumber), '--repo', repo, '--delete-branch', '--comment', buildFailedReviewCloseComment(prNumber, issues)],
                { timeout: 30 * 1000 },
              )
              autoClosed = true
              out(`adversarial_review_pr: auto-closed PR #${prNumber} (failed review: ${issues.filter((i: any) => i.severity === 'error').map((i: any) => i.category).join(', ') || 'blockers'})`)
            }
          } catch (e: any) {
            outWarn(`adversarial_review_pr: could not auto-close PR #${prNumber}: ${e.message}`)
          }
        }

        return {
          passed,
          autoClosed,
          issues,
          summary: review.summary ?? (passed ? 'PR passed review' : 'PR has issues'),
        }
      } catch (err: any) {
        outWarn(`[adversarial-review] error: ${err.message}`)
        // Tooling failure (e.g. bad gh flag, network error) — don't block the PR.
        // A review that couldn't run is not the same as a review that found issues.
        return {
          passed: true,
          issues: [{ category: 'tooling-error', description: `Review tool failed: ${err.message}`, severity: 'warning' }],
          summary: 'Review could not run (tooling error) — treating as passed',
        }
      }
    },
  },

  {
    name: 'schedule_next_auto',
    description: 'Pick the next-wakeup delay deterministically: ≤270s if @me has red or pending CI (inside 5-minute prompt cache), else 1200-1800s idle tick. No LLM input. Returns {scheduled, delaySeconds, reason}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const ciCheck = ctx?._action_coverage_gate_decide?.red ?? ctx?._action_check_my_open_pr_ci ?? {}
      const redCount = Array.isArray(ciCheck?.redPRs) ? ciCheck.redPRs.length : 0
      const pendingCount = Array.isArray(ciCheck?.pendingPRs) ? ciCheck.pendingPRs.length : 0
      let delaySeconds: number
      let reason: string
      if (redCount > 0 || pendingCount > 0) {
        delaySeconds = 270
        reason = `watching ${redCount} red + ${pendingCount} pending @me PR(s) — stay in prompt cache`
      } else {
        delaySeconds = 1500  // 25 min
        reason = 'no actionable PR state — idle tick'
      }
      return { scheduled: true, delaySeconds, reason }
    },
  },
]
