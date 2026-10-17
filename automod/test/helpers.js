// Shared by the service tests (not itself a test: npm test runs test/*.test.js).
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const chart = JSON.parse(
  readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'chart.json'), 'utf8'),
);

/**
 * Every fact the chart reads, all false, as the batch sends them, so a test names only the facts
 * it means to be true. A request that leaves one out is not answered "no" any more (it holds as
 * UNAVAILABLE), so a request without its facts is no longer a way to ask a clean question.
 */
export function completeFacts(overrides = {}) {
  const names = new Set();
  for (const state of Object.values(chart.states)) {
    if (state.check?.kind === 'fact') names.add(state.check.fact);
    if (state.check?.when) names.add(state.check.when);
  }

  return { ...Object.fromEntries([...names].map((name) => [name, false])), ...overrides };
}
