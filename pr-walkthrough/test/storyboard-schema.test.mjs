import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateStoryboard, totalDurationInFrames } from '../src/storyboard-schema.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

test('valid minimal storyboard passes', () => {
  const sb = { meta: { fps: 30 }, scenes: [{ type: 'title', seconds: 5 }] };
  assert.equal(validateStoryboard(sb).ok, true);
});

test('rejects non-positive durations', () => {
  const { ok, errors } = validateStoryboard({ meta: {}, scenes: [{ type: 'title', seconds: 0 }] });
  assert.equal(ok, false);
  assert.match(errors.join('\n'), /seconds/);
});

test('rejects callout box outside 0..1 and until<=at', () => {
  const sb = {
    meta: {},
    scenes: [{
      type: 'screenshot', seconds: 6, src: 'x.png',
      callouts: [
        { at: 2, until: 1, box: { x: 0.9, y: 0, w: 0.5, h: 0.1 }, label: 'bad' },
      ],
    }],
  };
  const { ok, errors } = validateStoryboard(sb);
  assert.equal(ok, false);
  assert.match(errors.join('\n'), /until/);
  assert.match(errors.join('\n'), /x\+w/);
});

test('rejects callout until beyond scene length', () => {
  const sb = {
    meta: {}, scenes: [{
      type: 'screenshot', seconds: 4, src: 'x.png',
      callouts: [{ at: 1, until: 9, box: { x: 0, y: 0, w: 0.2, h: 0.2 }, label: 'x' }],
    }],
  };
  assert.equal(validateStoryboard(sb).ok, false);
});

test('duration math accounts for transition overlaps', () => {
  const sb = { scenes: [{ seconds: 5 }, { seconds: 5 }, { seconds: 5 }] };
  const { total } = totalDurationInFrames(sb, 30, 15);
  // 3*150 frames - 2*15 overlap = 420
  assert.equal(total, 420);
});

test('the committed pr-618 storyboard is valid and external-only', () => {
  const dir = join(ROOT, 'examples', 'pr-618');
  const sb = JSON.parse(readFileSync(join(dir, 'storyboard.json'), 'utf8'));
  const { ok, errors } = validateStoryboard(sb, (src) =>
    existsSync(join(dir, 'assets', basename(src))) || src.endsWith('.masked.png'));
  assert.equal(ok, true, errors.join('\n'));
  // Walkthrough must be about external function: no code scenes, no diff stats on title.
  assert.equal(sb.scenes.some((s) => s.type === 'code'), false, 'no code scenes allowed');
  for (const s of sb.scenes.filter((s) => s.type === 'title')) {
    assert.notEqual(s.showStats, true, 'title must not show diff stats');
  }
  // Every screenshot references a masked asset (PII safety).
  for (const s of sb.scenes.filter((s) => s.type === 'screenshot')) {
    assert.match(s.src, /\.masked\.png$/, `screenshot src must be masked: ${s.src}`);
  }
});
