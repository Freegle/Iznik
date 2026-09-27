// Exercises createReviewer()/validateChart() from walk.js against the real
// chart.json, using FakeBackend so the tests are fast and deterministic (no
// model download, no network). Test post bodies are chosen so their keywords
// only match the node under test - see the comments on FakeBackend's keyword
// extraction in backend.js. A few chart nodes share a keyword with a node
// that is checked earlier in the walk (e.g. WEAPONS and KNIVES both match
// "weapon"), so bodies below deliberately avoid an earlier node's words when
// testing a later one.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createReviewer, validateChart, loadChart } from '../src/walk.js';
import { FakeBackend } from '../src/backend.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CHART_PATH = path.join(__dirname, '..', 'chart.json');

function realChart() {
  return JSON.parse(readFileSync(CHART_PATH, 'utf8'));
}

// Writes a mutated chart to a throwaway file and returns its path, so
// validateChart()'s own file-reading path (loadChart) can be exercised too.
function writeChart(chart) {
  const dir = mkdtempSync(path.join(tmpdir(), 'automod-chart-'));
  const file = path.join(dir, 'chart.json');
  writeFileSync(file, JSON.stringify(chart));
  return file;
}

describe('validateChart', () => {
  test('the real chart.json is valid', () => {
    assert.doesNotThrow(() => loadChart(CHART_PATH));
  });

  test('rejects a tool node with no check', () => {
    const chart = realChart();
    delete chart.states.VETO.check;
    assert.throws(() => validateChart(chart), /VETO' has no valid check\.kind/);
  });

  test('rejects a fact node with no check.fact', () => {
    const chart = realChart();
    delete chart.states.VETO.check.fact;
    assert.throws(() => validateChart(chart), /VETO' has no check\.fact/);
  });

  test('rejects a text node with no check.question', () => {
    const chart = realChart();
    delete chart.states.SELLING.check.question;
    assert.throws(() => validateChart(chart), /SELLING' has no check\.question/);
  });

  test('rejects a tool node missing its no transition', () => {
    const chart = realChart();
    chart.transitions = chart.transitions.filter(
      (t) => !(t.from === 'LOAN' && t.metadata?.answer === 'no'),
    );
    assert.throws(
      () => validateChart(chart),
      /LOAN' must have exactly one yes and one no transition \(found 1 yes, 0 no\)/,
    );
  });

  test('rejects a tool node with two yes transitions', () => {
    const chart = realChart();
    const yesTransition = chart.transitions.find(
      (t) => t.from === 'LOAN' && t.metadata?.answer === 'yes',
    );
    chart.transitions.push({ ...yesTransition, to: 'HOLD_VETO' });
    assert.throws(
      () => validateChart(chart),
      /LOAN' must have exactly one yes and one no transition \(found 2 yes, 1 no\)/,
    );
  });

  test('rejects a state that can never reach an end (a closed loop)', () => {
    const chart = realChart();
    // Point LOAN's "no" transition back at LOAN itself instead of SWAP.
    // LOAN's "yes" still reaches HOLD_LOAN, but SWAP onward, having
    // lost their only way in, can no longer reach any end.
    const noTransition = chart.transitions.find(
      (t) => t.from === 'LOAN' && t.metadata?.answer === 'no',
    );
    noTransition.to = 'LOAN';
    assert.throws(() => validateChart(chart), /SWAP' has no path to an end node/);
  });

  test('loadChart() runs the same checks against a file on disk', () => {
    const chart = realChart();
    delete chart.states.VETO.check;
    const brokenPath = writeChart(chart);
    assert.throws(() => loadChart(brokenPath), /VETO' has no valid check\.kind/);
  });
});

describe('createReviewer / review() - branch reachability', () => {
  const cases = [
    { name: 'VETO', facts: { member_veto: true }, end: 'HOLD_VETO' },
    { name: 'MODERATED', facts: { member_moderated: true }, end: 'HOLD_MODERATED' },
    { name: 'GROUP_CLOSED', facts: { group_disallows: true }, end: 'HOLD_GROUP_CLOSED' },
    { name: 'NO_LOCATION', facts: { no_location: true }, end: 'HOLD_NO_LOCATION' },
    { name: 'OUTSIDE_UK', facts: { outside_uk: true }, end: 'HOLD_OUTSIDE_UK' },
    { name: 'SPAM_SIGNAL', facts: { spam_signal: true }, end: 'HOLD_SPAM_SIGNAL' },
    { name: 'PERSONAL_INFO', facts: { personal_info: true }, end: 'HOLD_PERSONAL_INFO' },
    { name: 'LANGUAGE', facts: { not_english: true }, end: 'HOLD_LANGUAGE' },
    { name: 'DUPLICATE', facts: { duplicate: true }, end: 'HOLD_DUPLICATE' },
    { name: 'VAGUE', facts: { vague: true }, end: 'HOLD_VAGUE' },
    {
      name: 'VAGUE_TEXT',
      body: 'Wanted anything and various things please.',
      end: 'HOLD_VAGUE_TEXT',
    },
    {
      name: 'SWAP',
      body: 'Happy to swap or trade for a lawnmower.',
      end: 'HOLD_SWAP',
    },
    {
      name: 'NOT_AN_ITEM',
      body: 'Just fancy a chat about something in general, nothing specific.',
      end: 'HOLD_NOT_AN_ITEM',
    },
    {
      name: 'SELLING',
      body: 'Trying to sell this for some money, will consider offers.',
      end: 'HOLD_SELLING',
    },
    {
      name: 'LOAN',
      body: 'Can I borrow your ladder for the weekend please?',
      end: 'HOLD_LOAN',
    },
    {
      name: 'ANIMALS_OFFER',
      facts: { is_offer: true },
      body: 'This animal would suit a new owner, livestock enclosure included.',
      end: 'HOLD_ANIMALS_OFFER',
    },
    {
      name: 'ANIMALS_WANTED',
      facts: { is_wanted: true },
      body: 'Looking for an animal or livestock to add to our smallholding.',
      end: 'HOLD_ANIMALS_WANTED',
    },
    {
      name: 'WEAPONS',
      body: 'This weapon needs a home urgently.',
      end: 'HOLD_WEAPONS',
    },
    {
      name: 'FIREARMS',
      body: 'These firearm and ammunition boxes are for a collector.',
      end: 'HOLD_FIREARMS',
    },
    {
      name: 'KNIVES',
      body: 'This knife set includes several bladed tools for the kitchen.',
      end: 'HOLD_KNIVES',
    },
    {
      name: 'MEDICINE_OTC',
      body: 'Spare vitamins, unopened.',
      end: 'HOLD_MEDICINE_OTC',
    },
    {
      name: 'MEDICINE_ANIMAL',
      body: 'Flea and worming treatments, unopened.',
      end: 'HOLD_MEDICINE_ANIMAL',
    },
    {
      name: 'CONTACT_LENSES',
      body: 'Daily contact lenses, unopened.',
      end: 'HOLD_CONTACT_LENSES',
    },
    {
      name: 'MEDICINE',
      body: 'Spare prescription medication no longer needed.',
      end: 'HOLD_MEDICINE',
    },
    {
      name: 'ALCOHOL',
      body: 'A bottle of alcohol from a work party gift.',
      end: 'HOLD_ALCOHOL',
    },
    {
      name: 'TOBACCO',
      body: 'Unopened tobacco and cigarettes packets.',
      end: 'HOLD_TOBACCO',
    },
    {
      name: 'VAPING',
      body: 'Unused vaping starter kit, barely used.',
      end: 'HOLD_VAPING',
    },
    {
      name: 'TICKETS',
      body: 'Two spare tickets no longer needed.',
      end: 'HOLD_TICKETS',
    },
    {
      name: 'GAS',
      body: 'Empty calor gas cylinder for collection.',
      end: 'HOLD_GAS',
    },
    {
      name: 'CONCERN',
      facts: { concern_keyword: true },
      body: 'I want to raise a safeguarding welfare concern about this post.',
      end: 'HOLD_CONCERN',
    },
  ];

  for (const testCase of cases) {
    test(`${testCase.name} holds at ${testCase.end}`, async () => {
      // Yes to exactly this node's question, so the case does not depend on its wording.
      const question = realChart().states[testCase.name].check.question;
      const backend = new FakeBackend({ yesFor: question ? [question] : [] });
      const reviewer = createReviewer({ backend, chartPath: CHART_PATH });
      const result = await reviewer.review({
        msgid: 1,
        groupid: 1,
        subject: 'Test post',
        body: testCase.body || 'A tidy box of odds and ends, works fine.',
        type: 'Offer',
        facts: testCase.facts || {},
        rules: {},
      });
      assert.equal(result.end, testCase.end);
      assert.equal(result.verdict, 'hold');
      assert.equal(result.reason, reviewer.chart.states[testCase.end].description);
    });
  }

  test('a clean post with no matching keywords or facts is approved', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend(), chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 2,
      groupid: 1,
      subject: 'Free sofa',
      body: 'Free two-seater sofa in good condition, must collect from the house.',
      type: 'Offer',
      facts: {},
      rules: {},
    });
    assert.equal(result.verdict, 'approve');
    assert.equal(result.end, 'APPROVE');
    // Every fact node (10) plus every text node (21) is walked when nothing matches.
    assert.equal(result.path.length, 31);
  });
});

describe('createReviewer / review() - rule and when short-circuits', () => {
  test('a rule toggled on forces "no" even when the keyword matches', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend(), chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 3,
      groupid: 1,
      body: 'Can I borrow your ladder for the weekend please?',
      type: 'Offer',
      facts: {},
      rules: { allowloans: true },
    });
    const loanStep = result.path.find((step) => step.node === 'LOAN');
    assert.equal(loanStep.answer, 'no');
    assert.equal(loanStep.model, 'rule');
    assert.equal(loanStep.evidence, 'This community allows it');
    // Walk continues past LOAN rather than holding there.
    assert.notEqual(result.end, 'HOLD_LOAN');
  });

  test('a when precondition that is not met is skipped regardless of wording', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend(), chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 4,
      groupid: 1,
      body: 'This animal would suit a new owner, livestock enclosure included.',
      type: 'Offer',
      facts: {}, // is_offer not set, so ANIMALS_OFFER is skipped despite the keyword match
      rules: {},
    });
    const offerStep = result.path.find((step) => step.node === 'ANIMALS_OFFER');
    assert.equal(offerStep.answer, 'no');
    assert.equal(offerStep.model, 'skipped');
    assert.equal(offerStep.evidence, 'Does not apply to this post');
    assert.notEqual(result.end, 'HOLD_ANIMALS_OFFER');
  });
});

describe('createReviewer / review() - backend failure', () => {
  test('a backend that throws holds the post rather than approving it', async () => {
    const throwingBackend = {
      async ask() {
        throw new Error('model unavailable in this test');
      },
    };
    const reviewer = createReviewer({ backend: throwingBackend, chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 5,
      groupid: 1,
      body: 'A tidy box of odds and ends, works fine.',
      type: 'Offer',
      facts: {},
      rules: {},
    });
    assert.equal(result.verdict, 'hold');
    // VAGUE_TEXT is the first text node, so it is the first backend call and
    // the first to fail.
    assert.equal(result.end, 'HOLD_VAGUE_TEXT');
    const failedStep = result.path.at(-1);
    assert.equal(failedStep.model, 'unavailable');
    assert.equal(failedStep.answer, 'yes');
    assert.equal(failedStep.p, 1);
  });
});

describe('createReviewer / review() - response shape', () => {
  test('fact steps omit p and threshold', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend(), chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 6,
      groupid: 1,
      facts: { member_veto: true },
      rules: {},
    });
    const step = result.path[0];
    assert.equal(step.node, 'VETO');
    assert.equal(step.kind, 'fact');
    assert.ok(!('p' in step));
    assert.ok(!('threshold' in step));
    assert.equal(step.model, 'fact');
  });

  test('rule/when short-circuit steps include threshold but omit p', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend(), chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 7,
      groupid: 1,
      body: 'Can I borrow your ladder for the weekend please?',
      facts: {},
      rules: { allowloans: true },
    });
    const step = result.path.find((s) => s.node === 'LOAN');
    assert.ok(!('p' in step));
    assert.equal(step.threshold, 0.7);
  });

  test('a real backend-evaluated text step includes both p and threshold', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend(), chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 8,
      groupid: 1,
      body: 'Trying to sell this for some money, will consider offers.',
      facts: {},
      rules: {},
    });
    const step = result.path.find((s) => s.node === 'SELLING');
    assert.equal(typeof step.p, 'number');
    assert.equal(step.threshold, 0.7);
    assert.equal(step.model, 'fake');
  });

  test('the top-level response has exactly the contract fields', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend(), chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 9,
      groupid: 1,
      facts: { member_veto: true },
      rules: {},
    });
    assert.deepEqual(Object.keys(result).sort(), [
      'chart',
      'end',
      'path',
      'reason',
      'verdict',
      'version',
    ].sort());
    assert.equal(result.chart, 'freegle-automod');
    assert.equal(result.version, '4');
  });
});
