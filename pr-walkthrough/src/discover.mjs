#!/usr/bin/env node
// Resolve the base URLs of a PREEXISTING running worktree, so capture/auth don't have to be
// hand-fed a URL. Reads docker only (read-only); never edits or starts anything.
//
//   node src/discover.mjs --worktree bulk-offer
//   eval "$(node src/discover.mjs --worktree bulk-offer --export)"   # APP_URL / MODTOOLS_URL
//
// Output (human): the app + modtools URLs and whether each frontend container is up.
// With --export: shell `export` lines (APP_URL, MODTOOLS_URL, TRAEFIK_PORT).
import { execFileSync } from 'node:child_process';

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const has = (n) => process.argv.includes(n);

function dockerInspect(name, fmt) {
  try {
    return execFileSync('docker', ['inspect', name, '--format', fmt], { encoding: 'utf8' }).trim();
  } catch {
    return '';
  }
}

const worktree = arg('--worktree', null);
if (!worktree) {
  console.error('usage: node src/discover.mjs --worktree <name> [--export]');
  process.exit(1);
}
const project = `freegle-${worktree}`;

// Traefik HTTP port (host port mapped to container :80).
const port = dockerInspect(`${project}-traefik`, '{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}');
if (!port) {
  console.error(`No running Traefik for project ${project} — is the worktree stack up? (./freegle status)`);
  process.exit(2);
}

// Frontend container states. dev-local = local API (safe, seeded worktree DB); dev-live = live API.
const states = {
  'dev-local': dockerInspect(`${project}-dev-local`, '{{.State.Status}}') || 'absent',
  'dev-live': dockerInspect(`${project}-dev-live`, '{{.State.Status}}') || 'absent',
  'modtools-dev-local': dockerInspect(`${project}-modtools-dev-local`, '{{.State.Status}}') || 'absent',
};

const appUrl = `http://freegle-dev-local.localhost:${port}`;
const modtoolsUrl = `http://modtools-dev-local.localhost:${port}`;

if (has('--export')) {
  console.log(`export APP_URL=${appUrl}`);
  console.log(`export MODTOOLS_URL=${modtoolsUrl}`);
  console.log(`export TRAEFIK_PORT=${port}`);
} else {
  console.log(`worktree:   ${worktree}  (project ${project})`);
  console.log(`app:        ${appUrl}    [dev-local: ${states['dev-local']}]`);
  console.log(`modtools:   ${modtoolsUrl}    [modtools-dev-local: ${states['modtools-dev-local']}]`);
  if (states['dev-local'] !== 'running') {
    console.log(`\n⚠ dev-local is "${states['dev-local']}" — start it to capture the main app:`);
    console.log(`    docker start ${project}-dev-local`);
  }
}
