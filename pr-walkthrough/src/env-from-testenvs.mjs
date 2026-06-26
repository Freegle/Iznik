#!/usr/bin/env node
// Turn a PR's E2E test-envs entry into shell `export` lines, so capture's ${ENV} substitution
// is fed the seeded ids / users without DB-spelunking. The e2e tests already define this data.
//
//   eval "$(node src/env-from-testenvs.mjs --env browse --testenvs ../iznik-nuxt3/tests/e2e/test-envs.json)"
//
// Flattens the chosen entry to UPPER_SNAKE_CASE vars, e.g.
//   MESSAGES_OFFER=1244  MOD_EMAIL=pw_browse_mod@test.com  USER_EMAIL=...  POSTCODE="LS1 4AP"
import { readFileSync } from 'node:fs';

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const envKey = arg('--env', null);
const path = arg('--testenvs', null);
if (!envKey || !path) {
  console.error('usage: node src/env-from-testenvs.mjs --env <key> --testenvs <path to test-envs.json>');
  process.exit(1);
}

let data;
try {
  data = JSON.parse(readFileSync(path, 'utf8'));
} catch (e) {
  console.error(`could not read/parse ${path}: ${e.message}`);
  process.exit(2);
}
const entry = data[envKey];
if (!entry) {
  console.error(`no "${envKey}" in ${path}. Keys: ${Object.keys(data).join(', ')}`);
  process.exit(3);
}

const out = [];
const walk = (obj, prefix) => {
  for (const [k, v] of Object.entries(obj)) {
    const name = `${prefix}${prefix ? '_' : ''}${k}`.replace(/[^A-Za-z0-9_]/g, '_').toUpperCase();
    if (v && typeof v === 'object') walk(v, name);
    else out.push([name, v]);
  }
};
walk(entry, '');
for (const [name, value] of out) {
  const v = /[\s]/.test(String(value)) ? `"${value}"` : value;
  console.log(`export ${name}=${v}`);
}
