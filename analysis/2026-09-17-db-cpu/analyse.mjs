#!/usr/bin/env node
// Rank DB load from processlist samples.
//   node analyse.mjs <tsv...> [--from=HH:MM] [--to=HH:MM] [--top=N]
// Denominator is the number of polls (each poll emits the sampler's own row,
// tagged PLSAMPLE), so idle polls count.  Headline number is MEAN CONCURRENT
// THREADS - shares alone hide whether the node got busier (see the db2 plan,
// "measure in threads, not shares").
import fs from 'fs';

const files = [], opt = {};
for (const a of process.argv.slice(2)) {
  if (a.startsWith('--')) { const [k, v] = a.slice(2).split('='); opt[k] = v ?? true; } else files.push(a);
}
const TOP = +(opt.top || 25);

function fingerprint(sql) {
  let s = sql.replace(/\s+/g, ' ').trim();
  s = s.replace(/'(?:[^'\\]|\\.)*'/g, "'?'").replace(/"(?:[^"\\]|\\.)*"/g, "'?'");
  s = s.replace(/\b\d+\b/g, 'N');
  s = s.replace(/\bIN\s*\(\s*(?:N|'\?')(?:\s*,\s*(?:N|'\?'))*\s*\)/gi, 'IN(..)');
  s = s.replace(/(\?\s*,\s*)+\?/g, '..');
  return s;
}

// Human labels, first match wins.  Patterns run against the fingerprint.
const LABELS = [
  [/FROM messages_spatial ms LEFT JOIN messages_likes ml .*COUNT\(DISTINCT|SELECT COUNT\(DISTINCT ms\.msgid\)/i, 'apiv2 browse: spatial unseen COUNT'],
  [/FROM messages_spatial/i, 'apiv2 browse: messages_spatial feed'],
  [/FROM newsfeed|newsfeed\.id, newsfeed\.userid/i, 'apiv2 newsfeed'],
  [/^COMMIT|^BEGIN|^START TRANSACTION/i, 'transaction control (wsrep)'],
  [/EXISTS\(SELECT N FROM messages_outcomes mo WHERE mo\.msgid = messages\.id\) AS has_outcome/i, 'digest/push: getPostsForUser post fetch'],
  [/FROM `?users`? .*lastaccess.*\bIN\(\.\.\)|select `id` from `users` where `deleted` is null and `lastaccess`/i, 'digest: active-user scan (users.lastaccess)'],
  [/JSON_EXTRACT\(u\.settings, '\?'\$\.mylocation\.lat|JSON_EXTRACT\(u\.settings/i, 'reach mail: recipient query (JSON_EXTRACT settings)'],
  [/FROM rippling_reach/i, 'rippling_reach read'],
  [/FROM `?messages_likes`?/i, 'messages_likes'],
  [/attachments|illustration/i, 'illustrations / attachments'],
  [/FROM `?chat_messages`?/i, 'chat_messages'],
  [/FROM `?chat_rooms`?|chat_roster/i, 'chat rooms / roster'],
  [/FROM `?memberships`?/i, 'memberships'],
  [/FROM `?logs`?\b/i, 'logs (purge?)'],
  [/users_notifications/i, 'microvolunteering / notifications'],
  [/FROM `?jobs`?\b/i, 'jobs'],
  [/messages_outcomes/i, 'messages_outcomes'],
  [/FROM `?messages_groups`?|FROM `?messages`?\b/i, 'messages / messages_groups (other)'],
  [/FROM `?users`?\b/i, 'users (other)'],
];
const label = fp => (LABELS.find(([re]) => re.test(fp)) || [null, 'other'])[1];

function srcOf(host) {
  if (/^docker-internal/.test(host)) return 'batch (FD host)';
  const m = host.match(/^(db\d)-internal/); if (m) return `apiv2 ${m[1]}`;
  if (/^localhost/.test(host)) return 'local';
  return host.split(':')[0] || 'unknown';
}

const hhmm = ts => new Date(ts * 1000).toISOString().slice(11, 16);
const inWin = t => (!opt.from || t >= opt.from) && (!opt.to || t <= opt.to);

const polls = new Set();
const buckets = new Map();      // fingerprint -> {n, label, secs, maxTime, src:Map, sample}
const bySrc = new Map();
const byMinute = new Map();     // HH:MM -> {polls:Set, rows}
let rows = 0, host = '';

for (const f of files) {
  for (const line of fs.readFileSync(f, 'utf8').split('\n')) {
    if (!line) continue;
    const c = line.split('\t');
    if (c.length < 8) continue;
    const [tsS, id, , h, cmd, timeS, state, lenS] = c;
    const info = c.slice(8).join('\t');
    const ts = +tsS; if (!ts) continue;
    const t = hhmm(ts);
    if (!inWin(t)) continue;
    const key = tsS;
    if (info.includes('information_schema.processlist')) { polls.add(key); continue; }
    if (!info || info === '') continue;
    polls.add(key);
    rows++;
    const fp = fingerprint(info).slice(0, 220);
    let b = buckets.get(fp);
    if (!b) { b = { n: 0, label: label(fp), maxTime: 0, src: new Map(), sample: info.slice(0, 300), states: new Map() }; buckets.set(fp, b); }
    b.n++; b.maxTime = Math.max(b.maxTime, +timeS || 0); b.maxLen = Math.max(b.maxLen||0, +lenS || 0);
    const s = srcOf(h); b.src.set(s, (b.src.get(s) || 0) + 1);
    b.states.set(state || '-', (b.states.get(state || '-') || 0) + 1);
    bySrc.set(s, (bySrc.get(s) || 0) + 1);
    let m = byMinute.get(t); if (!m) { m = { p: new Set(), r: 0 }; byMinute.set(t, m); }
    m.r++;
  }
}
// second pass for per-minute poll counts
for (const f of files) {
  for (const line of fs.readFileSync(f, 'utf8').split('\n')) {
    if (!line) continue; const c = line.split('\t'); if (c.length < 8) continue;
    const ts = +c[0]; if (!ts) continue; const t = hhmm(ts); if (!inWin(t)) continue;
    let m = byMinute.get(t); if (!m) { m = { p: new Set(), r: 0 }; byMinute.set(t, m); }
    m.p.add(c[0]);
  }
}

const P = polls.size;
console.log(`polls=${P}  active-thread observations=${rows}  mean concurrent=${(rows / P).toFixed(2)}`);
if (opt.from || opt.to) console.log(`window ${opt.from || '--'}..${opt.to || '--'} UTC`);

console.log('\n--- by source ---');
for (const [s, n] of [...bySrc].sort((a, b) => b[1] - a[1]))
  console.log(`${(n / P).toFixed(2).padStart(6)} thr  ${(100 * n / rows).toFixed(1).padStart(5)}%  ${s}`);

// group by label
const byLabel = new Map();
for (const [, b] of buckets) byLabel.set(b.label, (byLabel.get(b.label) || 0) + b.n);
console.log('\n--- by label ---');
for (const [l, n] of [...byLabel].sort((a, b) => b[1] - a[1]))
  console.log(`${(n / P).toFixed(2).padStart(6)} thr  ${(100 * n / rows).toFixed(1).padStart(5)}%  ${l}`);

console.log(`\n--- top ${TOP} query shapes ---`);
for (const [fp, b] of [...buckets].sort((a, b) => b[1].n - a[1].n).slice(0, TOP)) {
  const src = [...b.src].sort((a, b) => b[1] - a[1]).map(([s, n]) => `${s}:${(100 * n / b.n) | 0}%`).join(' ');
  const st = [...b.states].sort((a, b) => b[1] - a[1]).slice(0, 3).map(([s, n]) => `${s}=${(100 * n / b.n) | 0}%`).join(' ');
  console.log(`\n${(b.n / P).toFixed(2)} thr  ${(100 * b.n / rows).toFixed(1)}%  maxtime=${b.maxTime}s len<=${b.maxLen}  [${b.label}]  ${src}  | ${st}`);
  console.log('   ' + b.sample.replace(/\s+/g, ' ').slice(0, 260));
}

if (opt.minutes) {
  console.log('\n--- mean concurrent threads by minute ---');
  for (const [t, m] of [...byMinute].sort()) console.log(`${t}  ${(m.r / m.p.size).toFixed(2)}  (${m.p.size} polls)`);
}
