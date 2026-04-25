import type { ActionDefinition } from 'ai-flower'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { readFile, writeFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { out, outWarn, dbg, startGroup, endGroup, truncate } from '../log.js'
import {
  getDb,
  getTopicCursor,
  setTopicCursor,
  listTopicCursors,
  kvGet,
  kvSet,
  queueDiscourseDraft,
  insertReviewerFeedback,
  listUnprocessedFeedback,
  upsertDiscourseBug,
  upsertPr,
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
          last_email_sent: kvGet(db, 'last_email_sent'),
          sentry_last_check: kvGet(db, 'sentry_last_check'),
        },
        iterationStartTs: new Date().toISOString(),
      }
    },
  },

  {
    name: 'sync_pr_states',
    description: 'Sync PR states from GitHub for all PRs referenced in discourse_bug.pr_number. Updates the pr table so reconcileBugStates can move fix-queued bugs to fixed once their PR is merged. Returns {synced: [{number, state, mergedAt}], updated: number}.',
    handler: async () => {
      const db = getDb()
      // Collect all PR numbers referenced in discourse_bug (fix-queued only — fixed ones don't need updating).
      const bugPRs = db.prepare(
        `SELECT DISTINCT pr_number FROM discourse_bug WHERE pr_number IS NOT NULL AND state IN ('open','investigating','fix-queued')`
      ).all() as Array<{ pr_number: number }>
      // Also sync any OPEN PRs already in the pr table (may have been merged or closed since last check).
      const tablePRs = db.prepare(
        `SELECT DISTINCT number FROM pr WHERE state IS NULL OR state NOT IN ('MERGED','CLOSED')`
      ).all() as Array<{ number: number }>

      const toSync = new Set([...bugPRs.map(r => r.pr_number), ...tablePRs.map(r => r.number)])
      if (toSync.size === 0) return { synced: [], updated: 0 }

      const synced: Array<{ number: number; state: string; mergedAt: string | null }> = []
      let updated = 0

      for (const num of toSync) {
        const res = await sh('gh', ['pr', 'view', String(num), '--repo', 'Freegle/Iznik', '--json', 'number,state,mergedAt,title,headRefName'])
        if (res.code !== 0) continue
        try {
          const pr = JSON.parse(res.stdout) as { number: number; state: string; mergedAt: string | null; title: string; headRefName: string }
          // gh returns 'MERGED', 'CLOSED', or 'OPEN'
          const ghState = pr.state === 'MERGED' ? 'MERGED' : pr.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
          upsertPr(db, {
            number: pr.number,
            title: pr.title,
            branch: pr.headRefName,
            state: ghState,
            // Freegle auto-deploys on merge, so MERGED = live.
            deployState: ghState === 'MERGED' ? 'live' : undefined,
          })
          synced.push({ number: pr.number, state: ghState, mergedAt: pr.mergedAt })
          updated++
        } catch { /* malformed JSON from gh — skip */ }
      }

      return { synced, updated }
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
d = fetch('https://discourse.ilovefreegle.org/latest.json?order=activity&per_page=${recentLimit}')
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
        td = fetch(f'https://discourse.ilovefreegle.org/t/{tid}.json')
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
    description: 'Record that a PR was created. Host validates by running gh pr view on the given number, inspects changed files (for frontendOnly classification), and looks up the Netlify deploy-preview URL from `gh pr checks` so a reply draft can include it when the fix is frontend-only. Params: {prNumber, repo}. Returns {verified, pr, files, frontendOnly, deployPreviewUrl}.',
    paramsSchema: {
      type: 'object',
      properties: { prNumber: { type: 'number' }, repo: { type: 'string' } },
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
    name: 'post_discourse_reply_draft',
    description: 'Queue a Discourse reply draft by APPENDING it to /tmp/freegle-monitor/retest-drafts.md. NEVER posts to Discourse — drafts require explicit human approval per iteration. Strict template (enforced here): body must be a single sentence; the file entry always renders the full [quote] block, the @username tag, the body, and a testable URL if provided. Params: {topic, post, username, quote, body, previewUrl?, prNumber?, prUrl?}. Use previewUrl ONLY for frontend-only fixes; backend/mixed fixes must include NO previewUrl because the user cannot retest until a deploy. The body should be exactly "Fix applied for <specific issue>. Please retest." or (with preview) "Possible fix — please test: <url>".',
    paramsSchema: {
      type: 'object',
      properties: {
        topic: { type: 'number' },
        post: { type: 'number' },
        username: { type: 'string' },
        quote: { type: 'string', description: '15-25 word excerpt from the original post — the disambiguator so the reporter knows which of their bugs this reply addresses.' },
        body: { type: 'string', description: 'One sentence. No PR numbers, commit hashes, V1/V2, Go/Nuxt, or other internals.' },
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
    description: 'Search Go/Nuxt/Laravel code for a pattern. Params: {pattern, path}',
    paramsSchema: {
      type: 'object',
      properties: { pattern: { type: 'string' }, path: { type: 'string' } },
      required: ['pattern'],
    },
    handler: async (params) => {
      const pattern = params.pattern as string
      const path = (params.path as string) ?? '/home/edward/FreegleDockerWSL'
      const { stdout } = await sh('rg', ['-l', '-n', pattern, path], undefined)
      const files = stdout.trim().split('\n').slice(0, 50)
      return { matches: files }
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
    description: 'List OPEN PRs authored by @me whose CI is currently red. A PR counts as red if any required check-run concluded "failure" or "cancelled" or "timed_out". Pending/queued checks do NOT count as red (they are in-flight). Netlify "pages changed" rows that only say "skipping" are ignored. Returns {redPRs: [{number, title, url, failedChecks: [{context, state, url}]}], pendingPRs: [{number, title, url, pendingChecks: [...]}], allGreen: bool}. FSM uses this to refuse WRAP_UP while any PR is red — a red PR is NEVER considered flaky, environmental, or unrelated. Fix it or keep trying.',
    handler: async () => {
      const listRes = await sh('gh', [
        'pr', 'list',
        '--repo', 'Freegle/Iznik',
        '--author', '@me',
        '--state', 'open',
        '--limit', '30',
        '--json', 'number,title,url,headRefOid',
      ])
      if (listRes.code !== 0) return { redPRs: [], pendingPRs: [], allGreen: true, error: listRes.stderr }
      const prs = JSON.parse(listRes.stdout) as Array<{ number: number; title: string; url: string; headRefOid: string }>

      const redPRs: Array<{ number: number; title: string; url: string; failedChecks: Array<{ context: string; state: string; url: string }> }> = []
      const pendingPRs: Array<{ number: number; title: string; url: string; pendingChecks: Array<{ context: string; state: string; url: string }> }> = []

      for (const pr of prs) {
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
          // Ignore Netlify "pages-changed" noise — it reports "skipping" when unchanged.
          if (/pages.?changed|header rules|redirect rules/i.test(name) && /skipping/i.test(state)) continue
          if (/^(fail|failure|cancelled|canceled|timed.?out|error)$/.test(state)) {
            failed.push({ context: name, state, url })
          } else if (/^(pending|queued|in.?progress|running)$/.test(state)) {
            pending.push({ context: name, state, url })
          }
        }
        if (failed.length > 0) redPRs.push({ number: pr.number, title: pr.title, url: pr.url, failedChecks: failed })
        else if (pending.length > 0) pendingPRs.push({ number: pr.number, title: pr.title, url: pr.url, pendingChecks: pending })
      }

      return { redPRs, pendingPRs, allGreen: redPRs.length === 0 }
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

FORBIDDEN:
  - "I've scheduled a wakeup" / "I'll check back" / "I'll come back to this later" — none of that is possible; if you exit without pushing, the work is lost.
  - Starting tests asynchronously and returning before they finish — wait for test output. If tests take too long, still wait; the FSM has a 20-minute timeout and will kill you only if truly stuck.
  - Creating a new PR when asked to fix an existing one (FIX_OPEN_PR_CI). Push a commit to the PR's branch instead.

OUTPUT MARKERS — MANDATORY, MACHINE-PARSED:
The parent FSM greps your stdout for these exact markers. Your prose does NOT count — "Fix pushed to PR #208" is invisible to the parser. You MUST emit exactly ONE of these on its own line at the very end:
  - Opened a NEW PR:                 PR_NUMBER=<n>
  - Pushed to master directly:       DIRECT_PUSH=<sha>
  - Pushed to an existing PR branch: COMMIT_PUSHED=<sha>
  - Could NOT complete the task:     DELEGATE_FAILED=<one-line-reason>
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
      const delegateModel = (params.model as string) ?? process.env.MONITOR_ACTIVE_DELEGATE_MODEL ?? 'sonnet'
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
      const failedMatch = combined.match(/DELEGATE_FAILED=([^\n]+)/)
      // exitCode 143 = SIGTERM (silence watchdog or hard cap fired).
      // Surface an explicit `timedOut` flag and `timeoutReason` so the
      // VERIFY/router prompts don't have to reverse-engineer this from the
      // numeric code.
      const timedOut = killReason !== null || code === 143
      const prNumber = prMatch ? Number(prMatch[1]) : undefined
      const directPushSha = directMatch ? directMatch[1] : undefined
      const commitPushedSha = commitMatch ? commitMatch[1] : undefined
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
    name: 'send_email',
    description: 'Send email summary via SMTP. Respects 1h cooldown via state.json last_email_sent. Params: {subject, body}',
    paramsSchema: {
      type: 'object',
      properties: { subject: { type: 'string' }, body: { type: 'string' } },
      required: ['subject', 'body'],
    },
    handler: async (params) => {
      const db = getDb()
      const lastStr = kvGet(db, 'last_email_sent')
      const last = lastStr ? new Date(lastStr).getTime() : 0
      const now = Date.now()
      if (last && now - last < 3600_000) {
        return { skipped: true, reason: 'cooldown' }
      }
      const script = `
import smtplib, sys, os
from email.mime.text import MIMEText
env = {}
for line in open('/home/edward/FreegleDockerWSL/.env'):
    if '=' in line and not line.startswith('#'):
        k, v = line.strip().split('=', 1)
        env[k] = v
subject = sys.argv[1]
body = sys.stdin.read()
msg = MIMEText(body)
msg['Subject'] = subject
msg['From'] = env['SMTP_USER']
msg['To'] = env['SMTP_USER']
with smtplib.SMTP(env['SMTP_HOST'], int(env['SMTP_PORT'])) as s:
    s.starttls()
    s.login(env['SMTP_USER'], env['SMTP_PASS'])
    s.send_message(msg)
print('sent')
`
      const subject = params.subject as string
      const body = params.body as string
      const child = await import('node:child_process').then(m => m.spawn('python3', ['-c', script, subject], { stdio: ['pipe', 'pipe', 'pipe'] }))
      child.stdin.write(body)
      child.stdin.end()
      const result = await new Promise<{ code: number; stdout: string; stderr: string }>((resolve) => {
        let stdout = ''
        let stderr = ''
        child.stdout.on('data', d => (stdout += String(d)))
        child.stderr.on('data', d => (stderr += String(d)))
        child.on('close', code => resolve({ code: code ?? 0, stdout, stderr }))
      })
      if (result.code !== 0) throw new Error(`send_email failed: ${result.stderr}`)
      kvSet(db, 'last_email_sent', new Date().toISOString())
      await renderAllViews(db) // keep state.json view in sync
      return { sent: true }
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
    description: 'Pure-logic branch for COVERAGE_GATE. Reads iterationStartTs from context, counts PRs created/updated this iteration, and checks for red CI on @me open PRs. Returns {count, redPRs, _transition: "CI_ROUTER" | "WRAP_UP" | "WRITE_COVERAGE"}. Used by the COVERAGE_GATE tool node to skip an LLM call.',
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
      let target: string
      if (redCount > 0) target = 'CI_ROUTER'
      else if (prCount > 0) target = 'WRAP_UP'
      else target = 'WRITE_COVERAGE'
      return {
        count: prCount,
        redCount,
        verify,
        red,
        _transition: target,
      }
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
        lines.push('## Discourse bugs fixed', '')
        for (const b of fixed) {
          lines.push(`- ${b.topic}.${b.post} @${b.user ?? 'reporter'}${b.prNumber ? ` → PR #${b.prNumber}` : ''}`)
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
    description: 'Build an email summary from context + DB state and send it via SMTP with a 1h cooldown. Labels each PR with its REAL state queried from `gh pr view` (open / merged / closed). Never claims "merged" or "deployed" unless gh confirms it — guards against the LLM hallucinating merge status.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const db = getDb()
      const lastStr = kvGet(db, 'last_email_sent')
      const last = lastStr ? new Date(lastStr).getTime() : 0
      const now = Date.now()
      if (last && now - last < 3600_000) {
        return { skipped: true, reason: 'cooldown' }
      }
      const ctx = context as any
      const verify = ctx?._action_coverage_gate_decide?.verify ?? ctx?._action_verify_pr_created ?? {}
      const prs: Array<any> = Array.isArray(verify?.prs) ? verify.prs : []
      const bugsFixed: Array<any> = Array.isArray(ctx?.bugsFixed) ? ctx.bugsFixed : []
      if (prs.length === 0 && bugsFixed.length === 0) {
        return { skipped: true, reason: 'nothing to report' }
      }

      // ─── Verify PR state from gh. Don't trust context; query the real world. ───
      // The email you send is factual, not aspirational. A prior iteration
      // shipped an email that claimed "PR Merged & Deployed" for #210 when
      // #210 was still open. That was LLM composition; this path is rule-
      // based, but we also harden it by actively checking each PR's state.
      const prStates: Array<{ number: number; title: string; url: string; state: string; isMerged: boolean; deployState: string | null }> = []
      for (const p of prs) {
        let state = 'UNKNOWN'
        try {
          const { stdout } = await exec('gh', ['pr', 'view', String(p.number), '--repo', 'Freegle/Iznik', '--json', 'state'], { maxBuffer: 1024 * 1024 })
          const parsed = JSON.parse(stdout)
          state = parsed.state ?? 'UNKNOWN'
        } catch { /* leave UNKNOWN — better to say nothing than lie */ }
        // deploy_state, if tracked, lives in the local pr table (populated by
        // a separate process that polls production). If absent we say nothing
        // about deploy — we don't assume "live" just because merged.
        let deployState: string | null = null
        try {
          const row = db.prepare('SELECT deploy_state FROM pr WHERE number = ?').get(p.number) as { deploy_state: string | null } | undefined
          deployState = row?.deploy_state ?? null
        } catch { /* no pr table or no row — that's fine */ }
        prStates.push({
          number: p.number,
          title: p.title ?? '',
          url: p.url ?? `https://github.com/Freegle/Iznik/pull/${p.number}`,
          state,
          isMerged: state === 'MERGED',
          deployState,
        })
      }

      const lines: string[] = []
      lines.push(`Phase: ${ctx?.phase ?? 'unknown'}`, '')
      if (prStates.length > 0) {
        lines.push('PRs this iteration (state from GitHub; "opened/updated" means NOT merged):')
        for (const p of prStates) {
          let label: string
          if (p.state === 'UNKNOWN') {
            label = 'state unknown'
          } else if (p.isMerged) {
            label = p.deployState === 'live' || p.deployState === 'deployed' ? 'merged + deployed' : 'merged (not yet deployed)'
          } else if (p.state === 'CLOSED') {
            label = 'closed (not merged)'
          } else {
            // p.state === 'OPEN'
            label = 'opened/updated — still open'
          }
          lines.push(`- #${p.number} [${label}] ${p.title} — ${p.url}`)
        }
        lines.push('')
      }
      const fixedCount = bugsFixed.filter(b => b.outcome === 'fixed').length
      const deferredCount = bugsFixed.filter(b => b.outcome === 'deferred').length
      if (bugsFixed.length > 0) {
        lines.push(`Discourse bugs: ${fixedCount} fix attempted / ${deferredCount} deferred this iteration.`)
        lines.push('("fix attempted" = a PR was opened; it does not mean merged, deployed, or accepted.)')
      }

      const mergedCount = prStates.filter(p => p.isMerged).length
      const openCount = prStates.filter(p => p.state === 'OPEN').length
      const subject = `Freegle Monitor: ${openCount} PR${openCount === 1 ? '' : 's'} opened, ${mergedCount} merged, ${fixedCount} bug${fixedCount === 1 ? '' : 's'} attempted`
      const body = lines.join('\n')
      const sendDef = actions.find(a => a.name === 'send_email')!
      const res = await sendDef.handler({ subject, body }, context)
      return res
    },
  },

  {
    name: 'ci_router_decide',
    description: 'Phase A router logic. No LLM — pure branch based on CHECK_CI results + iteration context. Returns {_transition: "FIX_MASTER_CI" | "FIX_OPEN_PR_CI" | "FETCH_DISCOURSE" | "COVERAGE_GATE", pickedPR?}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const master = ctx?._action_check_master_ci ?? {}
      const prCheck = ctx?._action_check_my_open_pr_ci ?? {}
      const masterFailing = master.failing === true
      const masterFixAttempted = ctx?.masterFixAttempted === true
      const redPRs: Array<{ number: number }> = Array.isArray(prCheck.redPRs) ? prCheck.redPRs : []
      const attempts: Array<{ prNumber: number; terminal?: boolean }> = Array.isArray(ctx?.openPRFixAttempts) ? ctx.openPRFixAttempts : []
      // Allow re-picking a PR whose latest attempt did not push a commit —
      // matches the original LLM prompt's "keep trying" rule. A terminal
      // record (loop-breaker) is respected.
      const attemptedNums = new Set(attempts.filter(a => a.terminal).map(a => a.prNumber))
      const pickable = redPRs.find(p => !attemptedNums.has(p.number))
      // Priority 1: master red
      if (masterFailing && !masterFixAttempted) {
        return { _transition: 'FIX_MASTER_CI', reason: `master CI failing on run ${master.latestRun?.databaseId ?? '?'}` }
      }
      // Priority 2: any red PR not in terminal-attempts
      if (pickable) {
        return { _transition: 'FIX_OPEN_PR_CI', reason: `red PR #${pickable.number} not yet attempted`, pickedPR: pickable.number }
      }
      // Priority 3: always fetch Discourse — phase influences how bugs are handled, not whether to look
      const phase = ctx?.phase ?? 'analysis'
      return { _transition: 'FETCH_DISCOURSE', reason: `CI green (${phase} phase) — enter discovery` }
    },
  },

  {
    name: 'persist_classifications',
    description: 'Persist TRIAGE classifications to the discourse_bug table so the status post reflects all identified bugs, not just ones with PRs. Upserts each bug/retest classification as "open" (or "deferred" if type is deferred). Already-fixed bugs are not downgraded. Returns {upserted: number, skipped: number}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const classifications: Array<any> = Array.isArray(ctx?.classifications) ? ctx.classifications : []
      const db = getDb()
      let upserted = 0, skipped = 0
      for (const c of classifications) {
        if (!c.topic || !c.post) { skipped++; continue }
        // Only persist actionable classifications — skip mine/off_topic/already_fixed/confirmed
        const type = c.type as string
        if (!['bug', 'retest', 'deferred', 'question'].includes(type)) { skipped++; continue }
        const state = type === 'deferred' ? 'deferred' : type === 'question' ? 'deferred' : 'open'
        // Don't downgrade a bug already in fix-queued or fixed state
        const existing = db.prepare('SELECT state FROM discourse_bug WHERE topic = ? AND post = ?').get(c.topic, c.post) as { state: string } | undefined
        if (existing && ['fix-queued', 'fixed', 'confirmed', 'investigating'].includes(existing.state)) { skipped++; continue }
        upsertDiscourseBug(db, {
          topic: Number(c.topic),
          post: Number(c.post),
          topicTitle: c.topicTitle ?? null,
          reporter: c.user ?? null,
          excerpt: c.summary ?? c.originalPostText?.slice(0, 200) ?? null,
          state,
          reason: type === 'deferred' ? (c.reason ?? 'deferred by triage') : undefined,
        })
        upserted++
      }
      return { upserted, skipped }
    },
  },

  {
    name: 'work_router_decide',
    description: 'Phase B router logic. No LLM — branches on context.classifications and context.bugsFixed. Returns {_transition: "PICK_DISCOURSE_BUG" | "FIX_SENTRY_ISSUE" | "COVERAGE_GATE"}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      // During peak/implementation phase, Discourse is fetched (so the monitor
      // knows what's there) but bug-fixing is deferred to off-peak analysis phase.
      const phase = ctx?.phase ?? 'analysis'
      if (phase === 'implementation') {
        const classifications: Array<any> = Array.isArray(ctx?.classifications) ? ctx.classifications : []
        const pendingCount = classifications.filter(c => c.type === 'bug' || c.type === 'retest').length
        return { _transition: 'COVERAGE_GATE', reason: `peak phase — ${pendingCount} bug(s) found, deferring fixes to off-peak` }
      }
      const classifications: Array<any> = Array.isArray(ctx?.classifications) ? ctx.classifications : []
      const bugsFixed: Array<any> = Array.isArray(ctx?.bugsFixed) ? ctx.bugsFixed : []
      const fixedKeys = new Set(bugsFixed.map(b => `${b.topic}.${b.post}`))
      const pendingBug = classifications.find(c => (c.type === 'bug' || c.type === 'retest') && !fixedKeys.has(`${c.topic}.${c.post}`))
      if (pendingBug) {
        return { _transition: 'PICK_DISCOURSE_BUG', reason: `unfixed bug ${pendingBug.topic}.${pendingBug.post}` }
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

        const prompt = `You are a code review expert. Review this PR diff for:
1. Correctness - does the fix actually solve the problem?
2. Unintended changes - are there any unnecessary or harmful changes?
3. Test coverage - are there tests for the fix?
4. Code quality - does it follow the codebase patterns?

Return ONLY a JSON object with:
{
  "passed": boolean,
  "blockers": [{"category": string, "description": string}],
  "warnings": [{"category": string, "description": string}],
  "summary": string
}

If blockers exist, passed = false. Warnings do not block but should be noted.

DIFF:
\`\`\`
${diff.slice(0, 20000)}
\`\`\`
${diff.length > 20000 ? '\n(diff truncated for length)' : ''}`

        const response = await (global as any).__ai_flower_adapter.query(
          reviewModel,
          [{ role: 'user', content: prompt }],
          { max_tokens: 1000 }
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
        ]

        return {
          passed,
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
