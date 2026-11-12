// Everything the service can be told, read once from the environment.
//
// The list is short on purpose. There is no database string, no Loki address and no
// Sentry token here, and test/boundary.test.mjs fails if one is added: the service
// answers in public, so it holds nothing it could leak.

import { readFileSync } from 'node:fs'

export const MODES = ['off', 'shadow', 'ask', 'live']

/** Which kinds of reply each mode lets out. Everything else is stored as a draft. */
export const MODE_ALLOWS = {
  off: [],
  shadow: [],
  ask: ['ask', 'ack', 'duplicate'],
  live: ['ask', 'ack', 'duplicate', 'answer'],
}

function list(v) {
  return (v ?? '').split(',').map((s) => s.trim()).filter(Boolean)
}

function int(v, fallback) {
  const n = Number.parseInt(v ?? '', 10)
  return Number.isFinite(n) ? n : fallback
}

export function loadConfig(env = process.env) {
  const mode = env.FRONTLINE_MODE || 'shadow'
  if (!MODES.includes(mode)) throw new Error(`FRONTLINE_MODE must be one of ${MODES.join(', ')}`)
  const discourseBase = (env.DISCOURSE_URL || 'https://discourse.ilovefreegle.org').replace(/\/$/, '')
  return {
    mode,
    stateDir: env.FRONTLINE_STATE_DIR || './state',
    discourseBase,
    discourseKey: env.FRONTLINE_DISCOURSE_KEY || keyFromProfile(env.FRONTLINE_PROFILE_PATH),
    // 'Api-Key' for a key made in the admin screens, 'User-Api-Key' for one a user granted.
    discourseKeyHeader: env.FRONTLINE_DISCOURSE_KEY_HEADER || 'Api-Key',
    // An all-users admin key needs a username beside it. A single-user key does not.
    discourseUser: env.FRONTLINE_DISCOURSE_USER || '',
    // The account the service posts as. Its posts are never read as reports.
    botUsername: (env.FRONTLINE_BOT_USERNAME || '').toLowerCase(),
    // People whose posts are a developer speaking, not a report.
    staff: list(env.FRONTLINE_STAFF).map((s) => s.toLowerCase()),
    // Category ids where support happens: the service reads everything there and may
    // reply. In every other category it reads only posts that sound like something is
    // broken, and never replies. Empty means every category is a support category.
    categories: list(env.FRONTLINE_CATEGORIES).map(Number),
    model: env.FRONTLINE_MODEL || 'claude-haiku-5-5',
    // 'api' calls Anthropic with an API key. 'cli' goes through the Claude Code login.
    llm: env.FRONTLINE_LLM === 'cli' ? 'cli' : 'api',
    wikiApi: env.FRONTLINE_WIKI_API || 'https://wiki.ilovefreegle.org/api.php',
    wikiBase: env.FRONTLINE_WIKI_BASE || 'https://wiki.ilovefreegle.org',
    docsDirs: list(env.FRONTLINE_DOCS_DIRS || '../docs/members,../docs/moderators'),
    docsBase: env.FRONTLINE_DOCS_BASE || 'https://github.com/Freegle/Iznik/blob/master/docs',
    maxAsks: int(env.FRONTLINE_MAX_ASKS, 2),
    staleDays: int(env.FRONTLINE_STALE_DAYS, 7),
    maxPostsPerLap: int(env.FRONTLINE_MAX_POSTS_PER_LAP, 60),
    maxRepliesPerLap: int(env.FRONTLINE_MAX_REPLIES_PER_LAP, 8),
    maxRepliesPerTopicPerDay: int(env.FRONTLINE_MAX_REPLIES_PER_TOPIC_DAY, 3),
    maxRepliesPerDay: int(env.FRONTLINE_MAX_REPLIES_PER_DAY, 40),
    maxTokensPerDay: int(env.FRONTLINE_MAX_TOKENS_PER_DAY, 600000),
    // Hosts a reply may link to. Anything else stops the reply.
    linkHosts: [
      new URL(discourseBase).host,
      new URL(env.FRONTLINE_WIKI_BASE || 'https://wiki.ilovefreegle.org').host,
      'www.ilovefreegle.org',
      'modtools.org',
      'github.com',
    ],
  }
}

function keyFromProfile(path) {
  if (!path) return ''
  try {
    return JSON.parse(readFileSync(path, 'utf8')).auth_pairs?.[0]?.user_api_key ?? ''
  } catch {
    return ''
  }
}
