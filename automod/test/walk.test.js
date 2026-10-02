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
    delete chart.states.MOD_NOTE.check;
    assert.throws(() => validateChart(chart), /MOD_NOTE' has no valid check\.kind/);
  });

  test('rejects a fact node with no check.fact', () => {
    const chart = realChart();
    delete chart.states.MOD_NOTE.check.fact;
    assert.throws(() => validateChart(chart), /MOD_NOTE' has no check\.fact/);
  });

  test('rejects a text node with no check.question', () => {
    const chart = realChart();
    delete chart.states.MONEY.check.question;
    assert.throws(() => validateChart(chart), /MONEY' has no check\.question/);
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
    chart.transitions.push({ ...yesTransition, to: 'HOLD_MOD_NOTE' });
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
    delete chart.states.MOD_NOTE.check;
    const brokenPath = writeChart(chart);
    assert.throws(() => loadChart(brokenPath), /MOD_NOTE' has no valid check\.kind/);
  });
});

describe('createReviewer / review() - every branch holds', () => {
  const chart = realChart();
  const tools = Object.entries(chart.states).filter(([, s]) => s.nodeType === 'tool');

  // For each question, a request that makes exactly that question answer yes.
  for (const [id, state] of tools) {
    test(`${id} holds at HOLD_${id}`, async () => {
      const check = state.check;
      const facts = {};
      const rules = {};
      const request = { msgid: 1, groupid: 1, subject: 'Test post', body: 'A tidy box of odds and ends, works fine.', type: 'Offer' };
      if (check.kind === 'fact') facts[check.fact] = true;
      if (check.when) facts[check.when] = true;
      if (check.requiresRule) rules[check.requiresRule] = true;
      if (check.context) request[check.context] = ['WANTED: ladder'];
      const backend = new FakeBackend({ yesFor: check.kind === 'text' ? [check.question] : [] });
      const reviewer = createReviewer({ backend, chartPath: CHART_PATH });

      const result = await reviewer.review({ ...request, facts, rules });

      assert.equal(result.end, `HOLD_${id}`);
      assert.equal(result.verdict, 'hold');
      assert.equal(result.reason, chart.states[`HOLD_${id}`].description);
      assert.equal(result.path.at(-1).question, state.description, 'a step shows the short wording');
    });
  }

  test('a clean post with no matching facts or answers is approved', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [] }), chartPath: CHART_PATH });
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
    // Every question is walked when nothing matches.
    assert.equal(result.path.length, tools.length);
  });
});

describe('createReviewer / review() - gating', () => {
  const loanQuestion = realChart().states.LOAN.check.question;

  test('a rule the community allows answers no without asking', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [loanQuestion] }), chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 3, groupid: 1, body: 'Can I borrow your ladder?', type: 'Wanted', facts: {}, rules: { allowloans: true } });
    const step = result.path.find((s) => s.node === 'LOAN');
    assert.equal(step.answer, 'no');
    assert.equal(step.model, 'rule');
    assert.equal(step.evidence, 'This community allows it');
    assert.notEqual(result.end, 'HOLD_LOAN');
  });

  test('a question that needs a rule the community has not set is not asked', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [] }), chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 3, groupid: 1, body: 'Ring me on 07700 900000', type: 'Offer', facts: { personal_info: true, personal_info_detail: 'phone number' }, rules: {} });
    const step = result.path.find((s) => s.node === 'PERSONAL_INFO');
    assert.equal(step.answer, 'no');
    assert.equal(step.model, 'rule');
    assert.notEqual(result.end, 'HOLD_PERSONAL_INFO');
  });

  test('a when precondition that is not met skips the question', async () => {
    const offerQuestion = realChart().states.ANIMALS_OFFER.check.question;
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [offerQuestion] }), chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 4, groupid: 1, body: 'Kitten needs a home', type: 'Offer', facts: {}, rules: {} });
    const step = result.path.find((s) => s.node === 'ANIMALS_OFFER');
    assert.equal(step.answer, 'no');
    assert.equal(step.model, 'skipped');
    assert.equal(step.evidence, 'Does not apply to this post');
  });

  test('a fact step carries the detail the batch gave as evidence', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [] }), chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 5, groupid: 1, facts: { mod_note: true, mod_note_detail: 'Note left 3 Sep' }, rules: {} });
    assert.equal(result.end, 'HOLD_MOD_NOTE');
    assert.equal(result.path.at(-1).evidence, 'Note left 3 Sep');
  });
});

describe('createReviewer / review() - what the backend is told', () => {
  test('keyword flags and hints reach the backend, and the flagged words are recorded', async () => {
    const seen = [];
    const backend = {
      setQuestions() {},
      async ask(question, text, opts) {
        seen.push({ question, opts });
        return { p: 0.1, answer: 'no', model: 'spy' };
      },
    };
    const reviewer = createReviewer({ backend, chartPath: CHART_PATH });
    const result = await reviewer.review({
      msgid: 6, groupid: 1, type: 'Offer', subject: 'OFFER: Bike', body: 'Selling my bike, 50 pounds ono.',
      facts: { vague: true, vague_detail: 'Subject is one word' }, rules: {},
    });
    const money = seen.find((s) => s.question === realChart().states.MONEY.check.question);
    assert.deepEqual(money.opts.flags, ['money']);
    assert.ok(money.opts.flagged.includes('ono'));
    assert.ok(money.opts.hints.some((h) => h.includes('Subject is one word')), 'the vague finding is a hint');
    const step = result.path.find((s) => s.node === 'MONEY');
    assert.match(step.evidence, /"ono"/);
  });

  test("a backend's own answer decides, whatever its probability", async () => {
    const backend = { setQuestions() {}, async ask() { return { p: 0.55, answer: 'yes', model: 'spy' }; } };
    const reviewer = createReviewer({ backend, chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 7, groupid: 1, body: 'x', type: 'Offer', facts: {}, rules: {} });
    assert.equal(result.verdict, 'hold');
    assert.equal(result.path.at(-1).answer, 'yes');
  });

  test('a question with context is asked with the request field put into words', async () => {
    let got;
    const backend = { setQuestions() {}, async ask(q, t, opts) { if (opts.context) got = opts.context; return { p: 0, answer: 'no', model: 'spy' }; } };
    const reviewer = createReviewer({ backend, chartPath: CHART_PATH });
    await reviewer.review({ msgid: 8, groupid: 1, body: 'Brown sofa', type: 'Offer', facts: { has_other_posts: true }, rules: {}, other_posts: ['OFFER: Sofa, brown (Leeds)'] });
    assert.match(got, /other open posts/);
    assert.match(got, /Sofa, brown/);
  });
});

describe('createReviewer / review() - backend failure', () => {
  test('a backend that throws holds the post, and says the review could not answer', async () => {
    const throwingBackend = { setQuestions() {}, async ask() { throw new Error('model unavailable in this test'); } };
    const reviewer = createReviewer({ backend: throwingBackend, chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 9, groupid: 1, body: 'A tidy box of odds and ends, works fine.', type: 'Offer', facts: {}, rules: {} });
    assert.equal(result.verdict, 'hold');
    assert.equal(result.end, 'UNAVAILABLE');
    assert.match(result.reason, /could not answer/);
    const failedStep = result.path.at(-1);
    assert.equal(failedStep.model, 'unavailable');
    assert.equal(failedStep.answer, 'yes');
  });
});

describe('createReviewer / review() - response shape', () => {
  test('fact steps omit p and threshold', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [] }), chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 10, groupid: 1, facts: { mod_note: true }, rules: {} });
    const step = result.path[0];
    assert.equal(step.node, 'MOD_NOTE');
    assert.equal(step.kind, 'fact');
    assert.ok(!('p' in step));
    assert.ok(!('threshold' in step));
    assert.equal(step.model, 'fact');
  });

  test('a backend-evaluated text step includes both p and threshold', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [] }), chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 11, groupid: 1, body: 'Brown sofa', type: 'Offer', facts: {}, rules: {} });
    const step = result.path.find((s) => s.node === 'MONEY');
    assert.equal(typeof step.p, 'number');
    assert.equal(step.threshold, 0.7);
    assert.equal(step.model, 'fake');
  });

  test('the top-level response has exactly the contract fields', async () => {
    const reviewer = createReviewer({ backend: new FakeBackend({ yesFor: [] }), chartPath: CHART_PATH });
    const result = await reviewer.review({ msgid: 12, groupid: 1, facts: { mod_note: true }, rules: {} });
    assert.deepEqual(Object.keys(result).sort(), ['chart', 'end', 'path', 'reason', 'verdict', 'version'].sort());
    assert.equal(result.chart, 'freegle-automod');
    assert.equal(result.version, '5');
  });
});
