// The model backend behind every text check. walk.js only ever calls
// ask(question, text) and expects { p, model } back; it decides yes/no
// itself by comparing p to the node's threshold, and it is the one that
// turns a thrown error into a hold (model: "unavailable"), so this file
// does not need its own fallback-to-hold logic.
import { pipeline, env } from '@huggingface/transformers';
import { execFile } from 'node:child_process';
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

/**
 * Real backend: CPU zero-shot NLI classification. The question is used as
 * the single candidate label, so its entailment score against the post text
 * is the model's answer to that yes/no question.
 */
export class NliBackend {
  async ask(question, text) {
    const { classifier, model } = await getModel();
    const result = await classifier(text, [question], {
      hypothesis_template: '{}',
      multi_label: true,
    });
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

// Posts whose answers are kept, so a walk asks Claude once per post.
const ANSWER_CACHE_SIZE = 200;

/** setQuestions takes strings or {question, flags}; this is the one shape used inside. */
function normaliseQuestions(questions) {
  return questions.map((q) => (typeof q === 'string' ? { question: q, flags: [] } : { flags: [], ...q }));
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
    this.model = model || process.env.AUTOMOD_CLAUDE_MODEL || 'claude-opus-5';
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

  async askAll(text, hints) {
    if (!this.cache.has(text)) {
      const pending = this.requestAll(text, hints).catch((err) => {
        this.cache.delete(text);
        throw err;
      });
      this.cache.set(text, pending);
      if (this.cache.size > ANSWER_CACHE_SIZE) {
        this.cache.delete(this.cache.keys().next().value);
      }
    }
    return this.cache.get(text);
  }

  batchPromptFor(text, hints) {
    return batchPrompt({ questions: this.questionTexts(), text, flags: keywordFlags(text), hints });
  }

  async requestAll(text, hints) {
    const response = await this.client.messages.parse({
      model: this.model,
      max_tokens: 6000,
      output_config: { effort: this.effort, format: zodOutputFormat(ClaudeAnswers) },
      system: SYSTEM,
      messages: [{ role: 'user', content: this.batchPromptFor(text, hints) }],
    });

    if (response.stop_reason === 'refusal' || !response.parsed_output) {
      throw new Error(`claude gave no usable answer (${response.stop_reason})`);
    }

    return this.indexAnswers(response.parsed_output);
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
   * @param {{flags?: string[], flagged?: string[], hints?: string[], context?: string}} [opts]
   *   flags: keyword categories this question is about; flagged: the words from them found in
   *   the post; hints: findings from Freegle's own checks, for the batched call; context:
   *   extra text this question needs, which makes it a call of its own.
   */
  async ask(question, text, opts = {}) {
    const known = this.questions.find((q) => q.question === question);
    if (!known || opts.context) {
      return this.askOne(question, text, opts);
    }

    const all = await this.askAll(text, opts.hints);
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
    const response = await this.client.messages.parse({
      model: this.model,
      max_tokens: 2000,
      output_config: { effort: this.effort, format: zodOutputFormat(ClaudeAnswer) },
      system: SYSTEM,
      messages: [{ role: 'user', content: singlePrompt({ question, text, flagged: opts.flagged, context: opts.context }) }],
    });

    if (response.stop_reason === 'refusal' || !response.parsed_output) {
      throw new Error(`claude gave no usable answer (${response.stop_reason})`);
    }

    return this.toResult(response.parsed_output);
  }
}

/**
 * Claude through the `claude` CLI on a subscription token (CLAUDE_CODE_OAUTH_TOKEN, from
 * `claude setup-token`), for a deployment with no metered API key. A raw OAuth call to the
 * Messages API is not supported, so this shells out, the same way Community News does. Same
 * prompts, same one call per post, same answer shape as ClaudeBackend. The CLI runs in an
 * empty config directory so no settings, hooks or tools load, and with no tools allowed.
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

  run(prompt) {
    return new Promise((resolve, reject) => {
      execFile(
        this.bin,
        ['-p', prompt, '--output-format', 'json', '--model', this.cliModel, '--allowedTools', ''],
        {
          cwd: this.configDir,
          timeout: 180000,
          maxBuffer: 1 << 24,
          env: { ...process.env, CLAUDE_CODE_OAUTH_TOKEN: this.token, CLAUDE_CONFIG_DIR: this.configDir, HOME: this.configDir },
        },
        (err, stdout) => {
          if (err) return reject(new Error(`claude cli failed: ${String(err.message).slice(0, 200)}`));
          try {
            const text = JSON.parse(stdout).result || '';
            resolve(JSON.parse(text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1)));
          } catch (e) {
            reject(new Error(`claude cli gave no usable answer: ${e.message}`));
          }
        },
      );
    });
  }

  async requestAll(text, hints) {
    const out = await this.run(`${SYSTEM}\n\n${BATCH_JSON}\n\n${this.batchPromptFor(text, hints)}`);
    const parsed = ClaudeAnswers.safeParse(out);
    if (!parsed.success) {
      throw new Error('claude cli answer did not match the schema');
    }
    return this.indexAnswers(parsed.data);
  }

  async askOne(question, text, opts = {}) {
    const out = await this.run(
      `${SYSTEM}\n\n${SINGLE_JSON}\n\n${singlePrompt({ question, text, flagged: opts.flagged, context: opts.context })}`,
    );
    const parsed = ClaudeAnswer.safeParse(out);
    if (!parsed.success) {
      throw new Error('claude cli answer did not match the schema');
    }
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

  async requestAll(text) {
    const questions = {};
    this.questions.forEach((q, i) => {
      questions[`q${i}`] = {
        type: 'noul',
        instructions: q,
        criteria: { true: 'Yes, for this post as written', false: 'No, for this post as written' },
      };
    });
    const r = await fetch(`${this.baseUrl}/v1/systemone`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${this.apiKey}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: this.model, state: { freegle_post: text }, questions }),
    });
    if (!r.ok) {
      throw new Error(`jev ${r.status}`);
    }
    const j = await r.json();
    const byQuestion = new Map();
    this.questions.forEach((q, i) => {
      const noul = j.answers?.[`q${i}`]?.noul;
      if (typeof noul === 'number') {
        byQuestion.set(q, noul);
      }
    });
    return byQuestion;
  }

  async askSingle(question, text, context) {
    const r = await fetch(`${this.baseUrl}/v1/systemone`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${this.apiKey}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: this.model,
        state: { freegle_post: text, context: context || '' },
        questions: { q: { type: 'noul', instructions: question, criteria: { true: 'Yes, for this post as written', false: 'No, for this post as written' } } },
      }),
    });
    if (!r.ok) {
      throw new Error(`jev ${r.status}`);
    }
    const j = await r.json();
    const p = j.answers?.q?.noul;
    if (typeof p !== 'number') {
      throw new Error('jev gave no probability');
    }
    return { p, model: `jev:${this.model}` };
  }

  async ask(question, text, opts = {}) {
    if (opts.context || !this.questions.includes(question)) {
      return this.askSingle(question, text, opts.context);
    }
    if (!this.cache.has(text)) {
      const pending = this.requestAll(text).catch((err) => {
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
