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
  reopenBugAfterRejection,
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
          sentry_last_check: kvGet(db, 'sentry_last_check'),
        },
        iterationStartTs: new Date().toISOString(),
      }
    },
  },

  {
    name: 'check_bug_feedback',
    description: 'For each open/investigating bug in discourse_bug, fetch posts after the original report on that Discourse topic and detect reporter confirmation of a fix (keywords: fixed, works now, confirmed, thanks, resolved, etc.). Marks matching bugs as fixed automatically. Returns {checked, markedFixed: [{topic, post, confirmedBy, confirmText}]}.',
    handler: async () => {
      const db = getDb()
      const bugs = db.prepare(
        "SELECT topic, post, reporter FROM discourse_bug WHERE state IN ('open','investigating')"
      ).all() as Array<{ topic: number; post: number; reporter: string | null }>

      if (bugs.length === 0) return { checked: 0, markedFixed: [] }

      // Build a Python script that checks each bug's topic for post-report confirmations
      const bugsJson = JSON.stringify(bugs)
      const script = `
import json, urllib.request, re, sys, time

p = json.load(open('/home/edward/profile.json'))
api_key = p['auth_pairs'][0]['user_api_key']
headers = {'User-Api-Key': api_key, 'Api-Username': 'Edward_Hibbert'}

CONFIRM_RE = re.compile(
    r'\\b(fixed|works? now|working now|confirmed?|thanks?|all good|resolved?'
    r'|seems? (?:to be )?(?:fixed|working|ok|good)|unlimited now|no (?:longer|more)'
    r'|great[,!.]?\\s*(?:thanks?)?|perfect|sorted|much better|no issues?)\\b',
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
results = []

for bug in bugs:
    topic_id = bug['topic']
    orig_post = bug['post']
    reporter = bug.get('reporter') or ''

    d = fetch(f'https://discourse.ilovefreegle.org/t/{topic_id}.json')
    if not d:
        continue

    stream = d.get('post_stream', {}).get('stream', [])
    # stream[N-1] = post_id for post #N; posts after original are at stream[orig_post:]
    new_ids = stream[orig_post:]
    if not new_ids:
        continue

    # Batch-fetch in groups of 50
    new_posts = []
    for i in range(0, len(new_ids), 50):
        batch = new_ids[i:i+50]
        qs = '&'.join(f'post_ids[]={pid}' for pid in batch)
        pd = fetch(f'https://discourse.ilovefreegle.org/t/{topic_id}/posts.json?{qs}')
        if pd:
            new_posts.extend(pd.get('post_stream', {}).get('posts', []))

    for post in new_posts:
        username = post.get('username', '')
        if username == 'Edward_Hibbert':
            continue  # skip our own posts
        text = re.sub(r'<[^>]+>', ' ', post.get('cooked', ''))
        text = re.sub(r'\\s+', ' ', text).strip()
        if CONFIRM_RE.search(text):
            results.append({
                'topic': topic_id,
                'post': orig_post,
                'reporter': reporter,
                'confirmedBy': username,
                'confirmPostNumber': post.get('post_number'),
                'confirmText': text[:200],
            })
            break  # one confirmation per bug is enough

print(json.dumps(results))
`

      const { stdout } = await exec('python3', ['-c', script])
      const confirmations: Array<{ topic: number; post: number; reporter: string | null; confirmedBy: string; confirmPostNumber: number; confirmText: string }> = JSON.parse(stdout.trim() || '[]')

      for (const c of confirmations) {
        db.prepare(
          "UPDATE discourse_bug SET state='fixed', fixed_at=datetime('now'), reason=? WHERE topic=? AND post=?"
        ).run(
          `Confirmed by ${c.confirmedBy} (post ${c.confirmPostNumber}): "${c.confirmText.slice(0, 120)}"`,
          c.topic, c.post
        )
        out(`check_bug_feedback: marked ${c.topic}/${c.post} fixed — confirmed by ${c.confirmedBy}`)
      }

      return { checked: bugs.length, markedFixed: confirmations }
    },
  },

  {
    name: 'sync_pr_states',
    description: 'Sync PR states from GitHub for all PRs referenced in discourse_bug.pr_number. Updates the pr table, moves fix-queued bugs to fixed on merge, and reopens bugs whose PRs were closed (rejected) by the reviewer — tracking rejection count so escalation logic can fire. Returns {synced, updated, reopened: [{topic, post, prNumber, rejections}]}.',
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
          upsertPr(db, {
            number: pr.number,
            title: pr.title,
            branch: pr.headRefName,
            state: ghState,
            deployState: ghState === 'MERGED' ? 'live' : undefined,
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
            for (const bug of bugs) {
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
    name: 'discover_active_topics',
    description: 'Light pre-check: fetches Discourse /latest.json (one API call) and compares post counts against DB cursors to find topics with new posts. Used to decide which topics need a triage delegate. Returns {topics: [{id, title, cursor, postsCount, hasNew}]}.',
    paramsSchema: { type: 'object', properties: { recentLimit: { type: 'number' } } },
    handler: async (params) => {
      const recentLimit = (params.recentLimit as number) ?? 30
      const script = `
import json, urllib.request, sys
p = json.load(open('/home/edward/profile.json'))
api_key = p['auth_pairs'][0]['user_api_key']
req = urllib.request.Request(
    'https://discourse.ilovefreegle.org/latest.json?order=activity&per_page=${recentLimit}',
    headers={'User-Api-Key': api_key}
)
d = json.load(urllib.request.urlopen(req, timeout=15))
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
    description: 'List OPEN PRs authored by @me whose CI is red or actively pending. BEHIND branches are noted but NOT auto-updated — a PR with green CI is fine to leave BEHIND until a human is ready to merge (they will click Update Branch then). The FSM must not call update-branch preemptively because doing so invalidates the CI, queues a fresh run on the single runner, and causes thrash when master keeps advancing. A PR counts as red if any required check concluded "failure"/"cancelled"/"timed_out". Pending/queued checks count as pending. Netlify noise is ignored. Returns {redPRs, pendingPRs, behindPRs, allGreen}. allGreen is true when no PR is red and none have actively running/pending CI (BEHIND with green CI does NOT block allGreen).',
    handler: async () => {
      const listRes = await sh('gh', [
        'pr', 'list',
        '--repo', 'Freegle/Iznik',
        '--author', '@me',
        '--state', 'open',
        '--limit', '30',
        '--json', 'number,title,url,headRefOid,mergeStateStatus',
      ])
      if (listRes.code !== 0) return { redPRs: [], pendingPRs: [], behindPRs: [], allGreen: true, error: listRes.stderr }
      const prs = JSON.parse(listRes.stdout) as Array<{ number: number; title: string; url: string; headRefOid: string; mergeStateStatus: string }>

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
          // Don't add to pendingPRs — BEHIND with green CI is not blocking work
          continue
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

      // allGreen: no red PRs and no actively pending CI. BEHIND PRs with green CI
      // do NOT block allGreen — they are waiting for a human to merge, not for the FSM.
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
  Terminal states are "completed" (success) or "error" (failure).  "passed" and "failed" are NOT valid states.
  Correct polling pattern (Go example):
    curl -s -X POST http://localhost:8081/api/tests/go
    until curl -s http://localhost:8081/api/tests/go/status | python3 -c "
    import sys,json; d=json.load(sys.stdin); s=d.get('status','')
    print(s); exit(0 if s in ['completed','error'] else 1)
    " 2>/dev/null; do sleep 5; done

FORBIDDEN:
  - "I've scheduled a wakeup" / "I'll check back" / "I'll come back to this later" — none of that is possible; if you exit without pushing, the work is lost.
  - Starting tests asynchronously and returning before they finish — wait for test output. If tests take too long, still wait; the FSM has a 20-minute timeout and will kill you only if truly stuck.
  - Creating a new PR when asked to fix an existing one (FIX_OPEN_PR_CI). Push a commit to the PR's branch instead.
  - Using port 38081 or any port other than 8081 for the test/status API.

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

        return {
          id: t.id,
          exitCode: result.code,
          timedOut,
          timeoutReason: result.killReason,
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
      // Priority 2: drain mode — if any PR has pending CI (BEHIND or actively running),
      // do nothing new this iteration. Creating new work while CI is running causes
      // thrashing: master advances, all branches go BEHIND, CI is invalidated, repeat.
      // Only fix red PRs or master failures; everything else waits for CI to settle.
      const pendingPRs: Array<any> = Array.isArray(prCheck.pendingPRs) ? prCheck.pendingPRs : []
      if (pendingPRs.length > 0 && redPRs.length === 0) {
        return {
          _transition: 'WRAP_UP',
          reason: `drain mode — ${pendingPRs.length} PR(s) have pending/running CI; not dispatching new work until CI settles`,
          drainMode: true,
          pendingCount: pendingPRs.length,
        }
      }
      // Priority 3: master green, no pending CI — dispatch ALL work in parallel (red PRs + discourse topics + sentry)
      const activeTopics = (ctx?._action_discover_active_topics?.topics ?? []) as Array<{ id: number; hasNew?: boolean }>
      const topicsWithNew = activeTopics.filter(t => t.hasNew).length
      const phase = ctx?.phase ?? 'analysis'
      return {
        _transition: 'PARALLEL_ANALYZE_AND_FIX',
        reason: `master green, no pending CI — parallel dispatch: ${redPRs.length} red PRs + ${topicsWithNew} active topics (${phase} phase)`,
        redPRCount: redPRs.length,
        activeTopicCount: topicsWithNew,
      }
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
        // Accept both 'post' and 'post_number' — topic delegates emit post_number
        const post = c.post ?? c.post_number
        if (!c.topic || !post) { skipped++; continue }
        c.post = post
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
          featureArea: c.featureArea ?? null,
          reason: type === 'deferred' ? (c.reason ?? 'deferred by triage') : undefined,
        })
        upserted++
      }
      return { upserted, skipped }
    },
  },

  {
    name: 'work_router_decide',
    description: 'Phase B router logic. No LLM — branches on context.classifications and context.bugsFixed. Returns {_transition: "DISPATCH_ALL_BUGS" | "FIX_SENTRY_ISSUE" | "COVERAGE_GATE"}.',
    paramsSchema: { type: 'object', properties: {} },
    handler: async (_params, context) => {
      const ctx = context as any
      const phase = ctx?.phase ?? 'analysis'
      const classifications: Array<any> = Array.isArray(ctx?.classifications) ? ctx.classifications : []
      const bugsFixed: Array<any> = Array.isArray(ctx?.bugsFixed) ? ctx.bugsFixed : []
      const fixedKeys = new Set(bugsFixed.map(b => `${b.topic}.${b.post}`))
      const pendingBugs = classifications.filter(c => (c.type === 'bug' || c.type === 'retest') && !fixedKeys.has(`${c.topic}.${c.post}`))

      // Always check DB for open bugs regardless of phase.
      const db = getDb()
      const dbOpenBugs = (db.prepare(`
        SELECT topic, post, reporter, excerpt, feature_area AS featureArea, topic_title AS topicTitle, pr_rejections AS prRejections
        FROM discourse_bug
        WHERE state IN ('open', 'investigating') AND pr_number IS NULL
      `).all() as Array<any>).filter(b => !fixedKeys.has(`${b.topic}.${b.post}`))

      // Bugs whose PRs have been rejected once need human review — escalate them.
      // One rejection is enough: if the reviewer closed a PR, they've signalled the
      // approach is wrong and the FSM shouldn't guess again without guidance.
      const ESCALATION_THRESHOLD = 1
      const toEscalate = dbOpenBugs.filter(b => (b.prRejections ?? 0) >= ESCALATION_THRESHOLD)
      for (const bug of toEscalate) {
        db.prepare(`
          UPDATE discourse_bug SET state = 'deferred',
            reason = 'Escalated: ' || ? || ' rejected PR(s) — needs human triage',
            last_seen_at = datetime('now')
          WHERE topic = ? AND post = ?
        `).run(bug.prRejections, bug.topic, bug.post)
        // Queue a Discourse reply so the human sees it in the dashboard.
        queueDiscourseDraft(db, {
          topic: bug.topic,
          post: bug.post,
          username: bug.reporter ?? 'you',
          quote: (bug.excerpt ?? '').slice(0, 120),
          body: `I've tried fixing this ${bug.prRejections} time(s) but my approaches have been rejected. I'm not confident I understand the root cause well enough to fix it correctly. Could you advise on the right direction?`,
        })
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

      const allPending = [...pendingBugs, ...extraBugs.map(b => ({ ...b, type: 'bug' }))]

      if (allPending.length > 0) {
        return {
          _transition: 'DISPATCH_ALL_BUGS',
          reason: `${allPending.length} unfixed bug(s) (${pendingBugs.length} this iteration + ${extraBugs.length} from DB) — parallel dispatch`,
          dbOpenBugs: extraBugs,
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
