// The model backend behind every text check. walk.js only ever calls
// ask(question, text, opts) and expects { p, model } back; it decides yes/no
// itself by comparing p to the node's threshold, and it is the one that
// turns a thrown error into a hold (model: "unavailable"), so this file
// does not need its own fallback-to-hold logic.
//
// opts.acct is the review's account (newAccount): every model call made is
// counted on it, with the tokens, cost and model the backend learns, so the
// service can log and return what one decision cost. opts.deadline is the
// time (ms since the epoch) by which the caller has given up: no call starts
// after it, and a call in flight is cut off at it.
import { pipeline, env } from '@huggingface/transformers';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { SYSTEM, BATCH_JSON, SINGLE_JSON, batchPrompt, singlePrompt, keywordFlags } from './prompt.js';
import Anthropic from '@anthropic-ai/sdk';
import { z } from 'zod';
import { zodOutputFormat } from '@anthropic-ai/sdk/helpers/zod';

if (process.env.HF_CACHE_DIR) {
  env.cacheDir = process.env.HF_CACHE_DIR;
}

// Tried in order; the first one that loads is used for the lifetime of the
// process. xsmall is the preferred model - the fallback is smaller and less
// accurate but keeps the service answering if xsmall cannot be loaded.
export const MODELS = ['Xenova/nli-deberta-v3-xsmall', 'Xenova/mobilebert-uncased-mnli'];

/** The most one CLI call may take, when the caller has set no shorter deadline. */
export const CLI_TIMEOUT_MS = 180000;

let loadingPromise = null;

async function loadModel() {
  let lastError;
  for (const model of MODELS) {
    try {
      const classifier = await pipeline('zero-shot-classification', model);
      return { classifier, model };
    } catch (err) {
      lastError = err;
    }
  }
  throw lastError;
}

async function getModel() {
  if (!loadingPromise) {
    loadingPromise = loadModel().catch((err) => {
      // Let the next call try again instead of staying stuck on a rejected
      // promise for the rest of the process's life.
      loadingPromise = null;
      throw err;
    });
  }
  return loadingPromise;
}

/** The account a review carries through its backend calls. */
export function newAccount() {
  return {
    calls: 0,
    tokens: { input: 0, output: 0 },
    cost_usd: null,
    model: null,
    cache_hit: false,
    features: null,
    prompt_sha: null,
    reply_sha: null,
  };
}

/** Add one model call's usage to the account. Unknown tokens or cost leave the totals as they were. */
export function credit(acct, { model, input = 0, output = 0, cost = null } = {}) {
  if (!acct) return;
  acct.calls += 1;
  if (model) acct.model = model;
  acct.tokens.input += input || 0;
  acct.tokens.output += output || 0;
  if (typeof cost === 'number') acct.cost_usd = (acct.cost_usd || 0) + cost;
}

export const sha = (s) => createHash('sha256').update(String(s)).digest('hex');

/** Milliseconds left before the deadline, capped at max; throws if it has already passed. */
export function remainingMs(deadline, max) {
  if (!deadline) return max;
  const left = deadline - Date.now();
  if (left <= 0) throw new Error('the deadline passed before the model was asked');
  return Math.min(max, left);
}

/**
 * Real backend: CPU zero-shot NLI classification. The question is used as
 * the single candidate label, so its entailment score against the post text
 * is the model's answer to that yes/no question.
 */
export class NliBackend {
  async ask(question, text, opts = {}) {
    const { classifier, model } = await getModel();
    const result = await classifier(text, [question], {
      hypothesis_template: '{}',
      multi_label: true,
    });
    credit(opts.acct, { model: `nli:${model}` });
    return { p: result.scores[0], model: `nli:${model}` };
  }
}

const STOP_WORDS = new Set([
  'a', 'an', 'the', 'is', 'this', 'post', 'about', 'or', 'to', 'for', 'of', 'in', 'on',
  'and', 'than', 'other', 'such', 'as', 'does', 'that', 'it', 'something', 'rather',
  'with', 'from', 'be', 'are', 'was', 'were', 'has', 'have', 'do', 'asking',
  'offer', 'offered', 'offering', 'wanted', 'item', 'items', 'being', 'given', 'giving',
  'answer', 'covered', 'another', 'question', 'physical', 'general', 'request', 'names',
  'specific', 'poster', 'freely', 'form',
]);

// The words that carry a question: its last sentence, without quoted examples.
function keywordsOf(question) {
  const sentences = question.split(/(?<=[.?])\s+/);
  return sentences[sentences.length - 1]
    .replace(/"[^"]*"/g, ' ')
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, ' ')
    .split(/\s+/)
    .filter((word) => word.length > 3 && !STOP_WORDS.has(word));
}

/**
 * Deterministic backend for tests and local runs, selected with AUTOMOD_BACKEND=fake. Given
 * yesFor (a list of questions), it answers yes to exactly those. Otherwise it answers yes
 * when a distinctive word of the question appears as a whole word in the post text.
 */
export class FakeBackend {
  constructor({ yesFor } = {}) {
    this.yesFor = yesFor || null;
  }

  async ask(question, text) {
    // Extra options (flags, hints, context) do not change a fake answer.
    let hit;
    if (this.yesFor) {
      hit = this.yesFor.includes(question);
    } else {
      const words = new Set(text.toLowerCase().replace(/[^a-z0-9\s]/g, ' ').split(/\s+/));
      hit = keywordsOf(question).some((word) => words.has(word));
    }
    return { p: hit ? 0.95 : 0.05, model: 'fake' };
  }
}

const ClaudeAnswer = z.object({
  answer: z.enum(['yes', 'no']),
  confidence: z.number(),
  evidence: z.string(),
});

const ClaudeFeatures = z.object({
  items: z.array(z.string()),
  item_named: z.boolean(),
  money: z.string(),
  sale_listing: z.string(),
  borrowing: z.string(),
  exchange: z.string(),
  animals: z.string(),
  medicines: z.string(),
  substances: z.string(),
  links_or_codes: z.string(),
  readings: z.string(),
});

const ClaudeAnswers = z.object({
  features: ClaudeFeatures,
  answers: z.array(ClaudeAnswer.extend({ id: z.number() })),
});

/**
 * The batched reply numbers its answers itself. A reply that numbers from 1, repeats a number
 * or leaves one out would silently put every answer on the wrong question, so a reply is only
 * used when its ids are exactly 0..n-1, once each.
 */
export function checkAnswerIds(answers, n) {
  if (answers.length !== n) {
    throw new Error(`claude answered ${answers.length} of ${n} questions`);
  }
  const ids = new Set(answers.map((a) => a.id));
  if (ids.size !== n || [...ids].some((id) => !Number.isInteger(id) || id < 0 || id >= n)) {
    throw new Error('claude numbered its answers wrongly');
  }
}

// Posts whose answers are kept, so a walk asks Claude once per post.
const ANSWER_CACHE_SIZE = 200;

/** setQuestions takes strings or {question, flags}; this is the one shape used inside. */
function normaliseQuestions(questions) {
  return questions.map((q) => (typeof q === 'string' ? { question: q, flags: [] } : { flags: [], ...q }));
}

/** Tokens and model from a Messages API response. The API does not say what it cost. */
function sdkUsage(response) {
  const u = response.usage || {};
  return {
    model: response.model ? `claude:${response.model}` : undefined,
    input: (u.input_tokens || 0) + (u.cache_creation_input_tokens || 0) + (u.cache_read_input_tokens || 0),
    output: u.output_tokens || 0,
  };
}

/**
 * Frontier backend: Claude, asked to extract the post's features and then answer every
 * question in one call per post (src/prompt.js). It is the default for every text node
 * because a local model only earns a node by matching it (plans/active/automod-flowchart.md,
 * "Choosing models: top down"). A question that carries keyword flags and comes back "no"
 * while its flagged words appear in the post is asked again on its own with those words
 * quoted: the review pass. Only the post's type, subject and body are sent. A refusal or a
 * malformed answer throws, which holds the post for a moderator.
 */
export class ClaudeBackend {
  constructor({ apiKey = process.env.ANTHROPIC_API_KEY, model, effort } = {}) {
    this.client = new Anthropic({ apiKey });
    this.model = model || process.env.AUTOMOD_CLAUDE_MODEL || 'claude-opus-5-5';
    this.effort = effort || process.env.AUTOMOD_CLAUDE_EFFORT || 'medium';
    this.questions = [];
    this.cache = new Map();
  }

  setQuestions(questions) {
    this.questions = normaliseQuestions(questions);
  }

  questionTexts() {
    return this.questions.map((q) => q.question);
  }

  /**
   * What a batched answer depends on: the text and the findings the model is told about it. A
   * post sent to two communities is two reviews of one text, and one community's content check
   * may have found a concern word that the other's did not, so the text alone is not the key.
   */
  cacheKey(text, hints) {
    return sha(JSON.stringify([text, hints ?? []]));
  }

  /**
   * The batched call for a post, once: a second review of the same text and findings, or a
   * second question in the same walk, reads the cached entry. The first ask in a walk is
   * credited with the call's usage, or marked a cache hit, and either way carries the entry's
   * features and hashes.
   */
  async askAll(text, hints, opts = {}) {
    const key = this.cacheKey(text, hints);
    const hit = this.cache.has(key);
    if (!hit) {
      const pending = this.requestAll(text, hints, opts).catch((err) => {
        this.cache.delete(key);
        throw err;
      });
      this.cache.set(key, pending);
      if (this.cache.size > ANSWER_CACHE_SIZE) {
        this.cache.delete(this.cache.keys().next().value);
      }
    }
    const entry = await this.cache.get(key);
    const acct = opts.acct;
    if (acct && !acct.batch) {
      acct.batch = true;
      if (hit) {
        acct.cache_hit = true;
        if (entry.usage?.model) acct.model = entry.usage.model;
      } else {
        credit(acct, entry.usage);
      }
      acct.features = entry.features;
      acct.prompt_sha = entry.prompt_sha;
      acct.reply_sha = entry.reply_sha;
    }
    return entry.byQuestion;
  }

  batchPromptFor(text, hints) {
    return batchPrompt({ questions: this.questionTexts(), text, flags: keywordFlags(text), hints });
  }

  /** Request options for one Messages API call: cut off at the caller's deadline. */
  requestOptions(opts = {}) {
    const ms = remainingMs(opts.deadline, 10 * 60 * 1000);
    return { signal: AbortSignal.timeout(ms), timeout: ms };
  }

  async requestAll(text, hints, opts = {}) {
    const prompt = this.batchPromptFor(text, hints);
    const response = await this.client.messages.parse(
      {
        model: this.model,
        max_tokens: 6000,
        output_config: { effort: this.effort, format: zodOutputFormat(ClaudeAnswers) },
        system: SYSTEM,
        messages: [{ role: 'user', content: prompt }],
      },
      this.requestOptions(opts),
    );

    if (response.stop_reason === 'refusal' || !response.parsed_output) {
      throw new Error(`claude gave no usable answer (${response.stop_reason})`);
    }

    return this.entryFor(prompt, response.parsed_output, sdkUsage(response));
  }

  /** One cache entry: the answers by question, plus what the call cost and what it extracted. */
  entryFor(prompt, parsed, usage) {
    checkAnswerIds(parsed.answers, this.questions.length);
    return {
      byQuestion: this.indexAnswers(parsed),
      usage,
      features: parsed.features,
      prompt_sha: sha(prompt),
      reply_sha: sha(JSON.stringify(parsed)),
    };
  }

  indexAnswers({ features, answers }) {
    const byQuestion = new Map();
    for (const a of answers) {
      if (this.questions[a.id] !== undefined) {
        byQuestion.set(this.questions[a.id].question, { ...a, features });
      }
    }
    return byQuestion;
  }

  /**
   * @param {string} question
   * @param {string} text
   * @param {{flags?: string[], flagged?: string[], hints?: string[], context?: string, acct?: object, deadline?: number}} [opts]
   *   flags: keyword categories this question is about; flagged: the words from them found in
   *   the post; hints: findings from Freegle's own checks, for the batched call; context:
   *   extra text this question needs, which makes it a call of its own; acct and deadline as
   *   described at the top of this file.
   */
  async ask(question, text, opts = {}) {
    const known = this.questions.find((q) => q.question === question);
    if (!known || opts.context) {
      return this.askOne(question, text, opts);
    }

    const all = await this.askAll(text, opts.hints, opts);
    const a = all.get(question);
    if (!a) {
      throw new Error('claude left a question unanswered');
    }

    // The review pass: a flagged word in the post and a "no" is worth a second, focused look.
    if (a.answer === 'no' && opts.flagged?.length) {
      const again = await this.askOne(question, text, opts);
      return { ...again, model: `${again.model}+review`, features: a.features };
    }

    return this.toResult(a);
  }

  toResult({ answer, confidence, evidence, features }) {
    const c = Math.min(1, Math.max(0, confidence));
    // p is the probability of "yes"; the stated answer is the decision.
    const p = answer === 'yes' ? Math.max(c, 0.5) : Math.min(c, 0.49);
    return { p, answer, model: `claude:${this.model}`, evidence, features };
  }

  async askOne(question, text, opts = {}) {
    const response = await this.client.messages.parse(
      {
        model: this.model,
        max_tokens: 2000,
        output_config: { effort: this.effort, format: zodOutputFormat(ClaudeAnswer) },
        system: SYSTEM,
        messages: [{ role: 'user', content: singlePrompt({ question, text, flagged: opts.flagged, context: opts.context }) }],
      },
      this.requestOptions(opts),
    );

    if (response.stop_reason === 'refusal' || !response.parsed_output) {
      throw new Error(`claude gave no usable answer (${response.stop_reason})`);
    }

    credit(opts.acct, sdkUsage(response));
    return this.toResult(response.parsed_output);
  }
}

/** What one `claude -p --output-format json` run reports it used. */
function cliUsage(out, fallbackModel) {
  const models = Object.keys(out.modelUsage || {});
  const u = out.usage || {};
  return {
    model: models[0] ? `cli:${models[0]}` : `cli:${fallbackModel}`,
    input: (u.input_tokens || 0) + (u.cache_creation_input_tokens || 0) + (u.cache_read_input_tokens || 0),
    output: u.output_tokens || 0,
    cost: typeof out.total_cost_usd === 'number' ? out.total_cost_usd : null,
  };
}

/**
 * Claude through the `claude` CLI on a subscription token (CLAUDE_CODE_OAUTH_TOKEN, from
 * `claude setup-token`), for a deployment with no metered API key. A raw OAuth call to the
 * Messages API is not supported, so this shells out, the same way Community News does. Same
 * prompts, same one call per post, same answer shape as ClaudeBackend.
 *
 * The CLI runs in an empty config directory so no settings, hooks or MCP servers load;
 * `--tools ""` removes every built-in tool (`--allowedTools ""` alone only pre-approves none,
 * leaving the permission-free ones callable), `--max-turns 1` stops it after one reply,
 * `--no-session-persistence` stops it writing the prompt, which holds a member's text, to a
 * session file on disk for every call, and the prompt goes in on stdin, since a long post would
 * overrun the size of one command-line argument. The model it reports having used is recorded,
 * not the alias it was asked for.
 */
export class ClaudeCliBackend extends ClaudeBackend {
  constructor({ token = process.env.CLAUDE_CODE_OAUTH_TOKEN, model, bin } = {}) {
    super({ apiKey: 'unused' });
    this.token = token;
    this.cliModel = model || process.env.AUTOMOD_CLAUDE_CLI_MODEL || 'opus';
    this.model = `cli-${this.cliModel}`;
    this.bin = bin || process.env.AUTOMOD_CLAUDE_BIN || 'claude';
    this.configDir = mkdtempSync(join(tmpdir(), 'automod-claude-'));
  }

  run(prompt, opts = {}) {
    const timeout = remainingMs(opts.deadline, CLI_TIMEOUT_MS);
    return new Promise((resolve, reject) => {
      const child = execFile(
        this.bin,
        ['-p', '--output-format', 'json', '--model', this.cliModel, '--tools', '', '--allowedTools', '', '--max-turns', '1', '--no-session-persistence'],
        {
          cwd: this.configDir,
          timeout,
          maxBuffer: 1 << 24,
          env: {
            ...process.env,
            CLAUDE_CODE_OAUTH_TOKEN: this.token,
            CLAUDE_CONFIG_DIR: this.configDir,
            HOME: this.configDir,
            DISABLE_AUTOUPDATER: '1',
          },
        },
        (err, stdout, stderr) => {
          if (err) {
            const detail = String(stderr || '').trim().slice(0, 200);
            return reject(new Error(`claude cli failed: ${String(err.message).slice(0, 200)}${detail ? ` (${detail})` : ''}`));
          }
          let out;
          try {
            out = JSON.parse(stdout);
          } catch (e) {
            return reject(new Error(`claude cli output was not JSON: ${e.message}`));
          }
          if (out.is_error || (out.subtype && out.subtype !== 'success') || (out.terminal_reason && out.terminal_reason !== 'completed')) {
            return reject(new Error(`claude cli ${out.terminal_reason || out.subtype || 'error'}: ${String(out.result || '').slice(0, 200)}`));
          }
          const text = String(out.result || '');
          let json;
          try {
            json = JSON.parse(text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1));
          } catch (e) {
            return reject(new Error(`claude cli gave no usable answer: ${e.message}`));
          }
          resolve({ json, usage: cliUsage(out, this.cliModel) });
        },
      );
      // A closed pipe (the CLI exiting early) must not turn into an uncaught error.
      child.stdin.on('error', () => {});
      child.stdin.end(prompt);
    });
  }

  async requestAll(text, hints, opts = {}) {
    const prompt = `${SYSTEM}\n\n${BATCH_JSON}\n\n${this.batchPromptFor(text, hints)}`;
    const { json, usage } = await this.run(prompt, opts);
    const parsed = ClaudeAnswers.safeParse(json);
    if (!parsed.success) {
      throw new Error('claude cli answer did not match the schema');
    }
    return this.entryFor(prompt, parsed.data, usage);
  }

  async askOne(question, text, opts = {}) {
    const { json, usage } = await this.run(
      `${SYSTEM}\n\n${SINGLE_JSON}\n\n${singlePrompt({ question, text, flagged: opts.flagged, context: opts.context })}`,
      opts,
    );
    const parsed = ClaudeAnswer.safeParse(json);
    if (!parsed.success) {
      throw new Error('claude cli answer did not match the schema');
    }
    credit(opts.acct, usage);
    return this.toResult(parsed.data);
  }
}

/**
 * TypeSafe's Jev: a structured-decision model that answers typed questions with calibrated
 * probabilities. Every chart question goes in one request per post as a yes/no ("noul")
 * question, and its probability of yes is p. TYPESAFE_BASE_URL points it at any compatible
 * server instead of the hosted one. Only the post's type, subject and body are sent.
 */
export class JevBackend {
  constructor({ apiKey = process.env.TYPESAFE_API_KEY, baseUrl, model } = {}) {
    this.apiKey = apiKey;
    this.baseUrl = (baseUrl || process.env.TYPESAFE_BASE_URL || 'https://api.typesafe.ai').replace(/\/$/, '');
    this.model = model || process.env.AUTOMOD_JEV_MODEL || 'jev-latest';
    this.questions = [];
    this.cache = new Map();
  }

  setQuestions(questions) {
    this.questions = normaliseQuestions(questions).map((q) => q.question);
  }

  async post(body, opts = {}) {
    const r = await fetch(`${this.baseUrl}/v1/systemone`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${this.apiKey}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: this.model, ...body }),
      signal: AbortSignal.timeout(remainingMs(opts.deadline, 10 * 60 * 1000)),
    });
    if (!r.ok) {
      throw new Error(`jev ${r.status}`);
    }
    credit(opts.acct, { model: `jev:${this.model}` });
    return r.json();
  }

  async requestAll(text, opts = {}) {
    const questions = {};
    this.questions.forEach((q, i) => {
      questions[`q${i}`] = {
        type: 'noul',
        instructions: q,
        criteria: { true: 'Yes, for this post as written', false: 'No, for this post as written' },
      };
    });
    const j = await this.post({ state: { freegle_post: text }, questions }, opts);
    const byQuestion = new Map();
    this.questions.forEach((q, i) => {
      const noul = j.answers?.[`q${i}`]?.noul;
      if (typeof noul === 'number') {
        byQuestion.set(q, noul);
      }
    });
    return byQuestion;
  }

  async askSingle(question, text, context, opts = {}) {
    const j = await this.post(
      {
        state: { freegle_post: text, context: context || '' },
        questions: { q: { type: 'noul', instructions: question, criteria: { true: 'Yes, for this post as written', false: 'No, for this post as written' } } },
      },
      opts,
    );
    const p = j.answers?.q?.noul;
    if (typeof p !== 'number') {
      throw new Error('jev gave no probability');
    }
    return { p, model: `jev:${this.model}` };
  }

  async ask(question, text, opts = {}) {
    if (opts.context || !this.questions.includes(question)) {
      return this.askSingle(question, text, opts.context, opts);
    }
    if (!this.cache.has(text)) {
      const pending = this.requestAll(text, opts).catch((err) => {
        this.cache.delete(text);
        throw err;
      });
      this.cache.set(text, pending);
      if (this.cache.size > ANSWER_CACHE_SIZE) {
        this.cache.delete(this.cache.keys().next().value);
      }
    }
    const p = (await this.cache.get(text)).get(question);
    if (p === undefined) {
      throw new Error('jev left a question unanswered');
    }
    return { p, model: `jev:${this.model}` };
  }
}

/**
 * Picks a backend per question: the request's override first, then the node's own
 * `check.backend`, then AUTOMOD_BACKEND (default claude). Backends are built on first use.
 */
export class BackendRouter {
  constructor(env = process.env) {
    this.defaultName = env.AUTOMOD_BACKEND || 'claude';
    this.factories = {
      // A metered API key when there is one, else the subscription token through the CLI.
      claude: () => (env.ANTHROPIC_API_KEY || !env.CLAUDE_CODE_OAUTH_TOKEN ? new ClaudeBackend() : new ClaudeCliBackend()),
      nli: () => new NliBackend(),
      jev: () => new JevBackend(),
      fake: () => new FakeBackend(),
    };
    this.instances = {};
    this.questions = [];
  }

  // The chart's text questions, handed to backends that answer them all at once.
  setQuestions(questions) {
    this.questions = questions;
    for (const backend of Object.values(this.instances)) {
      backend.setQuestions?.(questions);
    }
  }

  get(name) {
    const key = name || this.defaultName;
    if (!this.factories[key]) {
      throw new Error(`unknown backend ${key}`);
    }
    if (!this.instances[key]) {
      this.instances[key] = this.factories[key]();
      this.instances[key].setQuestions?.(this.questions);
    }
    return this.instances[key];
  }

  // opts.backendName picks the backend; the rest of opts goes to it.
  ask(question, text, opts = {}) {
    return this.get(opts.backendName).ask(question, text, opts);
  }
}

export function createBackend(env = process.env) {
  return new BackendRouter(env);
}
