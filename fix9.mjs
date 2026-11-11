import fs from 'fs'
function sub(s, a, b) { if (!s.includes(a)) throw new Error('missing: ' + a.slice(0, 60)); return s.replace(a, b) }
const f = 'test/backend.test.js'
let s = fs.readFileSync(f, 'utf8')
s = sub(s, `import { BackendRouter, FakeBackend, ClaudeBackend, ClaudeCliBackend, checkAnswerIds, newAccount, remainingMs } from '../src/backend.js';`, `import { BackendRouter, FakeBackend, ClaudeBackend, ClaudeCliBackend, NliBackend, checkAnswerIds, newAccount, remainingMs } from '../src/backend.js';`)
s = sub(s, `  } else if (mode === 'batch') {`, `  } else if (mode === 'prose') {
    out = { type: 'result', subtype: 'success', is_error: false, terminal_reason: 'completed', result: 'The post says: call me on 07700 900123', total_cost_usd: 0, usage: {}, modelUsage: {} };
  } else if (mode === 'batch') {`)
s = sub(s, `test('the cli backend does not start a call after the deadline', async () => {`, `test('the cli backend does not put the model\\'s own words, which may quote the post, in its error', async () => {
  const backend = new ClaudeCliBackend({ token: 'test-token', bin: fakeCli() });
  process.env.FAKE_CLI_MODE = 'prose';
  try {
    await assert.rejects(backend.askOne('Is it a loan?', 'Subject: ladder', {}), (err) => {
      assert.match(err.message, /no usable answer/);
      assert.ok(!err.message.includes('07700'), err.message);
      return true;
    });
  } finally {
    delete process.env.FAKE_CLI_MODE;
  }
});

test('the nli backend does not start a classification after the deadline', async () => {
  await assert.rejects(new NliBackend().ask('Is it a loan?', 'Subject: ladder', { deadline: Date.now() - 1 }), /deadline passed/);
});

test('a review that waited on another review\\'s failed call asks again on its own deadline', async () => {
  const claude = new ClaudeBackend({ apiKey: 'test' });
  claude.setQuestions([{ question: 'A?', flags: [] }]);
  const deadlines = [];
  claude.requestAll = async (text, hints, opts) => {
    deadlines.push(opts.deadline);
    if (deadlines.length === 1) {
      // The first caller's short deadline cuts its call off while the second review is waiting.
      await new Promise((resolve) => setTimeout(resolve, 20));
      throw new Error('the call was cut off');
    }
    return allNo(claude);
  };

  const soon = Date.now() + 10;
  const later = Date.now() + 60_000;
  const first = claude.askAll('Subject: Sofa', [], { deadline: soon });
  const second = claude.askAll('Subject: Sofa', [], { deadline: later });
  await assert.rejects(first, /cut off/);
  const answers = await second;
  assert.equal(answers.get('A?').answer, 'no', 'the second review still gets its answer');
  assert.deepEqual(deadlines, [soon, later], 'the retry used the second review\\'s own deadline');
});

test('the cli backend does not start a call after the deadline', async () => {`)
fs.writeFileSync(f, s)
