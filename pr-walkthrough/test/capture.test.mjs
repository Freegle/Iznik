import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { readFileSync, rmSync, existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { validateCapturePlan } from '../src/capture-plan-schema.mjs';
import { capture, isMutating } from '../src/capture.mjs';

const FIXTURE = `<!doctype html><html><head><meta charset="utf-8">
<style>body{font-family:sans-serif;margin:40px;width:720px}input,textarea{display:block;width:100%;margin:8px 0}</style>
</head><body>
<h1>Offer lots of items at once</h1>
<input data-testid="clearance-title" placeholder="e.g. Office Clearance"/>
<textarea data-testid="paste" rows="3"></textarea>
<button id="add">Add these items</button>
<div id="out"></div>
<script>
document.getElementById('add').onclick = () => {
  const n = document.querySelector('[data-testid=paste]').value.split('\\n').filter(Boolean).length;
  document.getElementById('out').textContent = 'Added ' + n + ' items';
};
</script></body></html>`;

function pngSize(path) {
  const b = readFileSync(path);
  return { w: b.readUInt32BE(16), h: b.readUInt32BE(20), isPng: b[0] === 0x89 && b[1] === 0x50 };
}

test('capture-plan schema rejects malformed plans', () => {
  assert.equal(validateCapturePlan({}).ok, false);
  assert.equal(validateCapturePlan({ shots: [{ name: 'a.png' }] }).ok, false); // missing route
  assert.equal(validateCapturePlan({ shots: [{ name: 'a.png', route: '/x', steps: [{ nope: 1 }] }] }).ok, false);
  assert.equal(validateCapturePlan({ shots: [{ name: 'a.png', route: '/x', steps: [{ fill: 'testid=t' }] }] }).ok, false); // fill needs value
  assert.equal(validateCapturePlan({ shots: [{ name: 'a.png', route: '/x', steps: [{ clickText: 'Go' }] }] }).ok, true);
});

test('capture refuses mutating clicks (read-only by design)', () => {
  // Would write to the target's DB — must be refused.
  assert.equal(isMutating('Register interest'), true);
  assert.equal(isMutating('Post these items'), true);
  assert.equal(isMutating('testid=clearance-submit'), true);
  assert.equal(isMutating('text=Send'), true);
  // Non-mutating UI we DO drive to reach a state worth filming.
  assert.equal(isMutating('testid=mode-manual'), false);
  assert.equal(isMutating('testid=add-item'), false);
  assert.equal(isMutating('Add these items'), false);
  assert.equal(isMutating('[data-testid^="pick-"]'), false);
  assert.equal(isMutating('testid=bulk-preview-btn'), false);
});

test('capture drives a live page and screenshots it', async (t) => {
  if (!['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome-stable']
    .some((p) => existsSync(p)) && !process.env.CHROME) {
    t.skip('no system Chrome available');
    return;
  }
  const server = http.createServer((_req, res) => { res.setHeader('content-type', 'text/html'); res.end(FIXTURE); });
  await new Promise((r) => server.listen(0, r));
  const baseUrl = `http://127.0.0.1:${server.address().port}`;
  const out = mkdtempSync(join(tmpdir(), 'cap-'));

  try {
    const plan = {
      shots: [{
        name: 'fixture.png',
        route: '/',
        viewport: { width: 800, height: 600 },
        fullPage: true,
        steps: [
          { fill: 'testid=clearance-title', value: 'Office Clearance' },
          { fill: 'testid=paste', value: 'Office desk\nChair' },
          { clickText: 'Add these items' },
          { waitForText: 'Added 2 items' },
        ],
      }],
    };
    assert.equal(validateCapturePlan(plan).ok, true);
    const results = await capture(plan, { baseUrl, assetsDir: out });
    assert.equal(results[0].ok, true, results[0].error);
    const png = pngSize(join(out, 'fixture.png'));
    assert.equal(png.isPng, true);
    assert.equal(png.w, 800, 'screenshot width should match viewport');
    assert.ok(png.h >= 200, 'fullPage screenshot should have real height');
  } finally {
    server.close();
    rmSync(out, { recursive: true, force: true });
  }
});
