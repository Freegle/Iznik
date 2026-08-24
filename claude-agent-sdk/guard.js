'use strict'

// Read-only SQL guard for the AI Support Helper's db_query tool. Kept in its own
// module (no mysql2 dependency) so it is unit-testable on the host / in CI.
//
// Support are trusted with member data, but db_query must never WRITE, never run
// away, and never hand back auth secrets (session tokens / password hashes) that
// would let anyone impersonate a member. Blocking those just bounds accidents and
// prompt-injection, it does not restrict the member data support legitimately need.

const DANGEROUS =
  /\b(insert|update|delete|drop|alter|create|truncate|replace|grant|revoke|call|do|handler|lock|unlock|load_file|outfile|dumpfile|sleep|benchmark|get_lock|release_lock|set)\b/i
// Wholly-secret tables (SELECT * would leak secrets without naming a column) and
// secret column names. Login/session *info* is available (redacted) via
// identify_user and get_user_dump — only the raw secrets are blocked here.
const SECRET_TABLES = /\b(sessions|users_logins|partners_keys|users_2fa|config)\b/i
const SECRET_COLS =
  /\b(credentials|credentials2|salt|token|series|secret|apikey|api_key|privatekey|private_key|password|passwd)\b/i

// Strip SQL comments so /**/ or -- or # can't hide a forbidden keyword from the
// checks below (e.g. INTO/**/OUTFILE).
function stripSqlComments(s) {
  return s.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/--[^\n]*/g, ' ').replace(/#[^\n]*/g, ' ')
}

// Validate a query is a bounded, read-only SELECT/WITH and return it with a LIMIT
// enforced. Throws with an explanatory message otherwise.
function guardSelect(sql, maxRows = 500) {
  const s = String(sql || '').trim().replace(/;+\s*$/, '')
  if (!s) throw new Error('Empty query')
  const bare = stripSqlComments(s) // evaluate the comment-free form so /**/ can't hide keywords
  if (!/^\s*(select|with)\b/i.test(bare)) throw new Error('Only SELECT/WITH queries are allowed')
  if (/;/.test(bare)) throw new Error('Multiple statements are not allowed')
  if (DANGEROUS.test(bare)) throw new Error('Query contains a forbidden keyword (read-only SELECT only)')
  if (SECRET_TABLES.test(bare) || SECRET_COLS.test(bare)) {
    throw new Error(
      'Blocked: this references auth-secret tables/columns (sessions/logins/keys). Use identify_user or get_user_dump for a member\'s login state instead.'
    )
  }
  // Cap the LIMIT VALUE (existence-only check let "LIMIT 999999999" through).
  const m = /\blimit\s+(\d+)(?:\s*,\s*(\d+))?/i.exec(bare)
  if (!m) return `${s} LIMIT ${maxRows}`
  const rows = Number(m[2] != null ? m[2] : m[1])
  if (rows > maxRows) throw new Error(`LIMIT too high (max ${maxRows})`)
  return s
}

module.exports = { guardSelect, stripSqlComments, DANGEROUS, SECRET_TABLES, SECRET_COLS }
