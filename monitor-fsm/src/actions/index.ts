import type { ActionDefinition } from 'ai-flower'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { readFile, writeFile } from 'node:fs/promises'
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
} from '../db/index.js'
import { renderAllViews } from '../db/views.js'

const exec = promisify(execFile)

const STATE_PATH = '/tmp/freegle-monitor/state.json'
const SUMMARY_PATH = '/tmp/freegle-monitor/summary.md'
const DRAFTS_PATH = '/tmp/freegle-monitor/retest-drafts.md'
const USER_FEEDBACK_PATH = '/tmp/freegle-monitor/user_feedback.md'

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
    posts = list(td.get('post_stream', {}).get('posts', []))
    if not posts:
        continue
    # /t/{id}.json returns ONLY the first 20 posts. If the topic is longer
    # than that, everything after post 20 is invisible — a tracked topic
    # with cursor=20 and highest_post_number=198 would see zero new posts
    # forever. Paginate via /t/{id}/{post_number}.json which returns a
    # window starting at that post. Walk forward in steps of 20 until we
    # cover up to highest_post_number.
    highest_in_topic = td.get('highest_post_number') or td.get('posts_count') or 0
    if highest_in_topic and tid in tracked_cursors and cursor + 1 <= highest_in_topic:
        seen_post_numbers = {p.get('post_number', 0) for p in posts}
        start = max(cursor + 1, 21)
        page_guard = 0
        while start <= highest_in_topic and page_guard < 30:
            page_guard += 1
            try:
                pd = fetch(f'https://discourse.ilovefreegle.org/t/{tid}/{start}.json')
            except Exception as e:
                sys.stderr.write(f'topic {tid} page {start} failed: {e}\\n')
                break
            page_posts = pd.get('post_stream', {}).get('posts', [])
            if not page_posts:
                break
            added = 0
            for pp in page_posts:
                pn = pp.get('post_number', 0)
                if pn not in seen_post_numbers:
                    posts.append(pp)
                    seen_post_numbers.add(pn)
                    added += 1
            page_highest = max((p.get('post_number', 0) for p in page_posts), default=start)
            if page_highest <= start:
                break  # no forward progress
            start = page_highest + 1
            if added == 0:
                break
            time.sleep(0.4)  # be kind to Discourse while paginating
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

# Cap posts per topic to keep TRIAGE prompts within the LLM context window.
# Catch-up scenarios (a tracked thread with hundreds of missed posts after a
# pagination bug fix, or a quiet monitor restart) can otherwise produce
# 400+ posts in a single tick and bust Haiku's context. We always keep the
# NEWEST N per topic because those are the actionable ones — the cursor
# still advances to highest_post_number (via topicsSeen), so older posts
# aren't re-fetched next tick; they're just dropped from this batch.
PER_TOPIC_CAP = 40
by_topic = {}
for p in posts_out:
    by_topic.setdefault(p['topic'], []).append(p)
capped = []
dropped = 0
for tid, plist in by_topic.items():
    plist.sort(key=lambda p: p['postNumber'])
    if len(plist) > PER_TOPIC_CAP:
        dropped += len(plist) - PER_TOPIC_CAP
        plist = plist[-PER_TOPIC_CAP:]  # keep newest
    capped.extend(plist)
capped.sort(key=lambda p: (p['topic'], p['postNumber']))
if dropped:
    sys.stderr.write(f'[fetch_discourse] capped to {PER_TOPIC_CAP}/topic — dropped {dropped} older posts (cursor still advances)\\n')
print(json.dumps({'posts': capped, 'topicsSeen': topics_seen}))
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
    description: 'Check latest master CI run on Freegle/Iznik. Returns {latestRun: {databaseId, conclusion, headSha, displayTitle, createdAt, url}, failing: bool}. Use this to detect red master so the FSM can prioritise fixing it.',
    handler: async () => {
      const { stdout, stderr, code } = await sh('gh', [
        'run', 'list',
        '--repo', 'Freegle/Iznik',
        '--branch', 'master',
        '--limit', '1',
        '--json', 'databaseId,status,conclusion,headSha,displayTitle,createdAt,url',
      ])
      if (code !== 0) return { error: stderr, failing: false }
      const runs = JSON.parse(stdout) as Array<any>
      if (runs.length === 0) return { latestRun: null, failing: false }
      const latestRun = runs[0]
      return {
        latestRun,
        failing: latestRun.status === 'completed' && latestRun.conclusion === 'failure',
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
    description: 'Spawn a headless Claude Code session to perform an actual code change (diagnose, edit files, run tests, commit, push, open PR). The embedded FSM LLM has no tool access — this is how a FIX_* state actually produces a PR. Params: {task: string, repoCwd?: string, timeoutSec?: number, model?: string}. Returns {stdout, exitCode, prNumber?}. The subagent must emit a line `PR_NUMBER=<n>` on stdout to be picked up. Use `model` to steer cost: "opus" for hard diagnostic/architectural fixes (default); "sonnet" for routine TDD fixes once the bug is understood; "haiku" for mechanical follow-ups (nursing tests green, trivial CI fixes, coverage top-ups). If unsure, omit — the default picks a safe model.',
    paramsSchema: {
      type: 'object',
      properties: {
        task: { type: 'string', description: 'Full task description for the subagent: what to fix, how, acceptance criteria, exact repo path, whether to push to master or a feature branch.' },
        repoCwd: { type: 'string', description: 'Working directory. Defaults to /home/edward/FreegleDockerWSL.' },
        timeoutSec: { type: 'number', description: 'Max seconds. Default 1200 (20 min).' },
        model: { type: 'string', description: 'Claude model alias or full ID to pass via `claude --model`. "opus" | "sonnet" | "haiku" or full IDs like "claude-sonnet-4-6". Default: $FSM_CODER_DEFAULT_MODEL or "sonnet".' },
      },
      required: ['task'],
    },
    handler: async (params) => {
      const task = params.task as string
      const repoCwd = (params.repoCwd as string) ?? '/home/edward/FreegleDockerWSL'
      const timeoutSec = (params.timeoutSec as number) ?? 1200
      // Model selection: caller can request a specific tier per task. If
      // omitted, fall back to FSM_CODER_DEFAULT_MODEL or "sonnet". Sonnet is
      // a reasonable default for agentic TDD work; opus only when explicitly
      // asked for. Use haiku for mechanical nursing where the fix is already
      // understood.
      const model = (params.model as string | undefined) || process.env.FSM_CODER_DEFAULT_MODEL || 'sonnet'
      const fullPrompt = `${task}

==== CRITICAL EXECUTION CONTRAINTS — READ FIRST ====
You are a HEADLESS, ONE-SHOT subprocess. When your response ends, your process exits. You have NO persistence, NO wakeups, NO /loop, NO ScheduleWakeup, NO Monitor, NO TaskCreate — those tools do not exist for you. A parent FSM (monitor-fsm) invoked you and is waiting on your stdout.

You MUST do all the work in this single session:
  1. Checkout the branch.
  2. Reproduce / diagnose.
  3. Make the fix.
  4. Run the relevant test suite locally.
  5. Commit.
  6. git push (this must complete successfully before you return).
  7. Verify the push landed (git log origin/<branch> -1 shows your commit).
  8. Only THEN emit the final marker and finish.

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
      console.log(`[delegate_to_coder] spawning claude --model ${model} in ${repoCwd}`)
      const { spawn } = await import('node:child_process')
      type KillReason = 'tool-silence' | 'idle-silence' | 'hardCap' | null
      const result = await new Promise<{ stdout: string; stderr: string; textStream: string; code: number; killReason: KillReason; lastTool: string | null }>((resolve) => {
        const child = spawn(
          'claude',
          [
            '-p',
            '--output-format', 'stream-json',
            '--verbose',
            '--include-partial-messages',
            '--permission-mode', 'acceptEdits',
            '--allowedTools', 'Bash,Edit,Write,Read,Grep,Glob',
            '--model', model,
          ],
          { cwd: repoCwd, stdio: ['pipe', 'pipe', 'pipe'] },
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
      // exitCode 143 = SIGTERM (silence watchdog or hard cap fired).
      // Surface an explicit `timedOut` flag and `timeoutReason` so the
      // VERIFY/router prompts don't have to reverse-engineer this from the
      // numeric code.
      const timedOut = killReason !== null || code === 143
      const prNumber = prMatch ? Number(prMatch[1]) : undefined
      const directPushSha = directMatch ? directMatch[1] : undefined
      const commitPushedSha = commitMatch ? commitMatch[1] : undefined
      const pushed = prNumber !== undefined || directPushSha !== undefined || commitPushedSha !== undefined
      return {
        exitCode: code,
        timedOut,
        timeoutReason: killReason,
        silenceToolMs: SILENCE_TOOL_MS,
        silenceIdleMs: SILENCE_IDLE_MS,
        hardCapMs: HARD_CAP_MS,
        lastTool,
        stdoutTail: textStream.slice(-2000),
        stderrTail: stderr.slice(-2000),
        prNumber,
        directPushSha,
        commitPushedSha,
        pushed,
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
]
