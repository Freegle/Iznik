import fs from 'fs'
function sub(s, a, b) { if (!s.includes(a)) throw new Error('missing: ' + a.slice(0, 60)); return s.replace(a, b) }
let f = 'src/evaluate.js'
let s = fs.readFileSync(f, 'utf8')
s = sub(s, `export async function evaluate(`, `// Whether production would ask this text question of this post: not where the community's rule
// answers it, not where it only applies to another kind of post, and not where it needs context
// (the member's other posts) that an export does not carry. The same gating as walk.js, so the
// per-node figures count the questions the whole-chart verdicts ask.
function asked(check, post, facts) {
  const rules = post.rules && typeof post.rules === 'object' && !Array.isArray(post.rules) ? post.rules : {};
  if (check.requiresRule && rules[check.requiresRule] !== true) return false;
  if (check.rule && rules[check.rule] === true) return false;
  if (check.when && facts[check.when] !== true) return false;
  return !check.context;
}

export async function evaluate(`)
s = sub(s, `    for (const [nodeId, state] of textNodes) {
      const node =`, `    const facts = { ...noFacts, ...(post.facts || {}) };
    for (const [nodeId, state] of textNodes) {
      if (!asked(state.check, post, facts)) continue;
      const node =`)
s = sub(s, `facts: { ...noFacts, ...(post.facts || {}) }, msgid:`, `facts, msgid:`)
fs.writeFileSync(f, s)

f = 'scripts/walk-from-answers.mjs'
s = fs.readFileSync(f, 'utf8')
s = sub(s, `    if (answers[id] === 'yes') return { verdict: 'hold', end: \`HOLD_\${id}\` };`, `    // A backend that errored on a question production asks would have left the post held as
    // unavailable, never approved.
    if (answers[id] === 'error') return { verdict: 'hold', end: 'UNAVAILABLE' };
    if (answers[id] === 'yes') return { verdict: 'hold', end: \`HOLD_\${id}\` };`)
fs.writeFileSync(f, s)

f = 'test/backend.test.js'
s = fs.readFileSync(f, 'utf8')
s = sub(s, `test('evaluate decides a post from an export that carries no facts`, `test('evaluate asks a node only of the posts production would ask it of, and never one that needs context', async () => {
  const asks = [];
  const spy = { setQuestions() {}, async ask(question, text, opts) { asks.push({ question, flags: opts.flags }); return { p: 0.05, answer: 'no', model: 'spy' }; } };
  const router = routerWith({ claude: spy });
  const posts = [{ id: 1, subject: 'WANTED: Ladder', body: 'Could I borrow a ladder', type: 'Wanted', facts: { is_wanted: true }, rules: { allowloans: true }, outcome: 'approved' }];
  const report = await evaluate(posts, { backends: ['claude'], router });

  assert.equal(report.nodes.LOAN, undefined, 'a community that allows loans is not asked about them');
  assert.equal(report.nodes.ANIMALS_OFFER, undefined, 'a question about offers is not asked of a Wanted post');
  assert.equal(report.nodes.DUPLICATE_ITEM, undefined, 'a question that needs the member\\'s other posts is not asked without them');
  assert.ok(report.nodes.MONEY.asked === 1, 'an ungated question is');
  assert.ok(!asks.some((a) => /other open posts/.test(a.question)));
});

test('evaluate decides a post from an export that carries no facts`)
fs.writeFileSync(f, s)
