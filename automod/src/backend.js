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
]);

function keywordsOf(question) {
  return question
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, '')
    .split(/\s+/)
    .filter((word) => word.length > 3 && !STOP_WORDS.has(word));
}

/**
 * Deterministic backend for tests. Pulls the distinctive words out of the
 * question (chart.json's wording, not hand-listed here) and answers "yes"
 * with high confidence if any of them appear as a whole word in the post text, "no" with
 * low confidence otherwise. Selected with AUTOMOD_BACKEND=fake.
 */
export class FakeBackend {
  async ask(question, text) {
    const words = new Set(text.toLowerCase().replace(/[^a-z0-9\s]/g, ' ').split(/\s+/));
    const hit = keywordsOf(question).some((word) => words.has(word));
    return { p: hit ? 0.95 : 0.05, model: 'fake' };
  }
}

const ClaudeAnswer = z.object({
  answer: z.enum(['yes', 'no']),
  confidence: z.number(),
  evidence: z.string(),
});

const ClaudeAnswers = z.object({
  answers: z.array(ClaudeAnswer.extend({ id: z.number() })),
});

// Posts whose answers are kept, so a walk asks Claude once per post.
const ANSWER_CACHE_SIZE = 200;

const CLAUDE_SYSTEM =
  'You check posts on Freegle, a UK site where people give away and ask for unwanted ' +
  'items for free. You are asked one yes/no question about one post. Answer only that ' +
  'question, about this post as written. "confidence" is how sure you are that the answer ' +
  'to the question is yes, from 0 to 1. "evidence" is a short quote from the post that ' +
  'decided it, or an empty string.';

/**
 * Frontier backend: Claude answers with a strict JSON verdict. It is the default for every
 * text node because a local model only earns a node by matching it (see
 * plans/active/automod-flowchart.md, "Choosing models: top down"). Given the chart's text
 * questions (setQuestions), the first question asked about a post answers all of them in
 * one call and the rest of the walk reads the cached answers. Only the post's type,
 * subject and body are sent. A refusal or a malformed answer throws, which holds the post
 * for a moderator.
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
    this.questions = questions;
  }

  async askAll(text) {
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
    return this.cache.get(text);
  }

  async requestAll(text) {
    const numbered = this.questions.map((q, i) => `${i}: ${q}`).join('\n');
    const response = await this.client.messages.parse({
      model: this.model,
      max_tokens: 4000,
      output_config: { effort: this.effort, format: zodOutputFormat(ClaudeAnswers) },
      system: CLAUDE_SYSTEM.replace('one yes/no question', 'several yes/no questions'),
      messages: [
        {
          role: 'user',
          content: `Answer every question, giving its number as id.\n\nQuestions:\n${numbered}\n\nPost:\n${text}`,
        },
      ],
    });

    if (response.stop_reason === 'refusal' || !response.parsed_output) {
      throw new Error(`claude gave no usable answer (${response.stop_reason})`);
    }

    const byQuestion = new Map();
    for (const a of response.parsed_output.answers) {
      if (this.questions[a.id] !== undefined) {
        byQuestion.set(this.questions[a.id], a);
      }
    }
    return byQuestion;
  }

  async ask(question, text) {
    if (this.questions.includes(question)) {
      const all = await this.askAll(text);
      const a = all.get(question);
      if (!a) {
        throw new Error('claude left a question unanswered');
      }
      return this.toResult(a);
    }

    return this.askOne(question, text);
  }

  toResult({ answer, confidence, evidence }) {
    const c = Math.min(1, Math.max(0, confidence));
    // p is the probability of "yes"; the stated answer wins over a contradictory confidence.
    const p = answer === 'yes' ? Math.max(c, 0.5) : Math.min(c, 0.49);
    return { p, model: `claude:${this.model}`, evidence };
  }

  async askOne(question, text) {
    const response = await this.client.messages.parse({
      model: this.model,
      max_tokens: 2000,
      output_config: { effort: this.effort, format: zodOutputFormat(ClaudeAnswer) },
      system: CLAUDE_SYSTEM,
      messages: [{ role: 'user', content: `Question: ${question}\n\nPost:\n${text}` }],
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
 * questions, same one call per post, same answer shape as ClaudeBackend. The CLI runs in an
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

  async requestAll(text) {
    const numbered = this.questions.map((q, i) => `${i}: ${q}`).join('\n');
    const out = await this.run(
      CLAUDE_SYSTEM.replace('one yes/no question', 'several yes/no questions') +
        '\n\nReply with ONLY a JSON object {"answers":[{"id":number,"answer":"yes"|"no","confidence":number,"evidence":string}]}, one entry per question.' +
        `\n\nQuestions:\n${numbered}\n\nPost:\n${text}`,
    );
    const parsed = ClaudeAnswers.safeParse(out);
    if (!parsed.success) {
      throw new Error('claude cli answer did not match the schema');
    }
    const byQuestion = new Map();
    for (const a of parsed.data.answers) {
      if (this.questions[a.id] !== undefined) {
        byQuestion.set(this.questions[a.id], a);
      }
    }
    return byQuestion;
  }

  async askOne(question, text) {
    const out = await this.run(
      CLAUDE_SYSTEM +
        '\n\nReply with ONLY a JSON object {"answer":"yes"|"no","confidence":number,"evidence":string}.' +
        `\n\nQuestion: ${question}\n\nPost:\n${text}`,
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
    this.questions = questions;
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

  async ask(question, text) {
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

  ask(question, text, name) {
    return this.get(name).ask(question, text);
  }
}

export function createBackend(env = process.env) {
  return new BackendRouter(env);
}
