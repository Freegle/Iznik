import fs from 'fs'
function sub(s, a, b) { if (!s.includes(a)) throw new Error('missing: ' + a.slice(0, 60)); return s.replace(a, b) }
let f = 'src/backend.js'
let s = fs.readFileSync(f, 'utf8')
// NLI deadline
s = sub(s, `  async ask(question, text, opts = {}) {
    const { classifier, model } = await getModel();`, `  async ask(question, text, opts = {}) {
    // A classification already running cannot be cut off, but none starts after the deadline.
    remainingMs(opts.deadline, Infinity);
    const { classifier, model } = await getModel();`)
// shared call retry (Claude)
s = sub(s, `    const entry = await this.cache.get(key);
    const acct = opts.acct;`, `    let entry;
    try {
      entry = await this.cache.get(key);
    } catch (err) {
      // The call this review waited on was another review's, cut off at that caller's deadline
      // or failed on its own: ask once more with this review's own deadline.
      if (!hit || opts.retried) throw err;
      return this.askAll(text, hints, { ...opts, retried: true });
    }
    const acct = opts.acct;`)
// jev
s = sub(s, `    const p = (await this.cache.get(text)).get(question);
    if (p === undefined) {`, `    let answers;
    try {
      answers = await this.cache.get(text);
    } catch (err) {
      // See ClaudeBackend.askAll: a shared call that failed is asked once more, on this
      // review's own deadline.
      if (!shared || opts.retried) throw err;
      return this.ask(question, text, { ...opts, retried: true });
    }
    const p = answers.get(question);
    if (p === undefined) {`)
s = sub(s, `    if (!this.cache.has(text)) {
      const pending = this.requestAll(text, opts).catch((err) => {`, `    const shared = this.cache.has(text);
    if (!shared) {
      const pending = this.requestAll(text, opts).catch((err) => {`)
// cli error text
s = sub(s, "return reject(new Error(`claude cli output was not JSON: ${e.message}`));", "return reject(new Error('claude cli output was not JSON'));")
s = sub(s, "return reject(new Error(`claude cli gave no usable answer: ${e.message}`));", "return reject(new Error('claude cli gave no usable answer'));")
fs.writeFileSync(f, s)
