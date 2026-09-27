// Loads chart.json, validates it, and walks it for one review request at a
// time. The chart itself decides the questions; this file only knows how to
// read a 'check' object and turn it into a yes/no answer, then advance the
// ai-flower engine to whichever transition matches that answer.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { WorkflowEngine, TransitionValidator, MemoryStorage } from 'ai-flower';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_CHART_PATH = path.join(__dirname, '..', 'chart.json');

/**
 * Read chart.json (or an explicit path, used by tests) and validate it.
 * Throws on any structural problem rather than returning a broken chart.
 */
export function loadChart(chartPath = DEFAULT_CHART_PATH) {
  const raw = readFileSync(chartPath, 'utf8');
  const workflow = JSON.parse(raw);
  validateChart(workflow);
  return workflow;
}

/**
 * ai-flower's own validateDefinition() checks states/transitions reference
 * each other correctly. It does not know about this chart's own convention
 * (a 'check' object on every tool node, exactly one yes and one no
 * transition per tool node) or that every path must actually end, so those
 * are checked here as well.
 */
export function validateChart(workflow) {
  const validator = new TransitionValidator(workflow);
  const result = validator.validateDefinition();
  if (!result.valid) {
    throw new Error(`Invalid chart: ${result.errors.map((e) => e.message).join('; ')}`);
  }

  for (const [id, state] of Object.entries(workflow.states)) {
    if (state.nodeType !== 'tool') continue;

    if (!state.check || (state.check.kind !== 'fact' && state.check.kind !== 'text')) {
      throw new Error(`Invalid chart: tool node '${id}' has no valid check.kind`);
    }
    if (state.check.kind === 'fact' && !state.check.fact) {
      throw new Error(`Invalid chart: fact node '${id}' has no check.fact`);
    }
    if (state.check.kind === 'text' && !state.check.question) {
      throw new Error(`Invalid chart: text node '${id}' has no check.question`);
    }

    const outgoing = workflow.transitions.filter((t) => t.from === id);
    const yes = outgoing.filter((t) => t.metadata?.answer === 'yes');
    const no = outgoing.filter((t) => t.metadata?.answer === 'no');
    if (yes.length !== 1 || no.length !== 1) {
      throw new Error(
        `Invalid chart: tool node '${id}' must have exactly one yes and one no transition ` +
          `(found ${yes.length} yes, ${no.length} no)`,
      );
    }
  }

  // A state is only usable if the walk can actually get to it from the
  // start (reachableFromStart) and, once there, can get from it to an end
  // (canReachEnd). Checking canReachEnd alone misses a state that has been
  // cut off upstream: its own outgoing edges may still lead to an end, but
  // nothing walking the chart from START will ever arrive at it.
  const reachableFromStart = reachableFrom(workflow, workflow.initialState);
  for (const id of Object.keys(workflow.states)) {
    if (!reachableFromStart.has(id) || !canReachEnd(workflow, id, new Set())) {
      throw new Error(`Invalid chart: state '${id}' has no path to an end node`);
    }
  }
}

function reachableFrom(workflow, startId) {
  const seen = new Set();
  const stack = [startId];
  while (stack.length > 0) {
    const id = stack.pop();
    if (seen.has(id)) continue;
    seen.add(id);
    for (const t of workflow.transitions.filter((t) => t.from === id)) {
      stack.push(t.to);
    }
  }
  return seen;
}

function canReachEnd(workflow, stateId, seenOnThisPath) {
  const state = workflow.states[stateId];
  if (!state) return false;
  if (state.nodeType === 'end') return true;
  if (seenOnThisPath.has(stateId)) return false; // looped back without reaching an end
  const nextSeen = new Set(seenOnThisPath).add(stateId);
  const outgoing = workflow.transitions.filter((t) => t.from === stateId);
  return outgoing.some((t) => canReachEnd(workflow, t.to, nextSeen));
}

/**
 * Build a reviewer bound to one backend. `backend.ask(question, text)` must
 * resolve to `{ p, model }` (see backend.js); everything else about the
 * check (fact lookup, rule short-circuit, when-skip) is decided here so the
 * backend only ever has to answer the narrow text questions.
 */
export function createReviewer({ backend, chartPath } = {}) {
  const chart = loadChart(chartPath);
  backend?.setQuestions?.(
    Object.values(chart.states)
      .filter((s) => s.check?.kind === 'text')
      .map((s) => s.check.question),
  );
  const engine = new WorkflowEngine({
    workflow: chart,
    storageAdapter: new MemoryStorage(),
  });

  async function review({ msgid, groupid, subject, body, type, facts = {}, rules = {}, backend: backendName }) {
    const text = [
      type ? `Type: ${type}` : null,
      subject ? `Subject: ${subject}` : null,
      body ? `Body: ${body}` : null,
    ]
      .filter(Boolean)
      .join('\n');

    let instance = await engine.createInstance({ msgid, groupid, subject, body, type, facts, rules });

    // START has a single unconditional transition to the first real node.
    // createInstance() does not auto-follow unconditional transitions (only
    // triggerTransition does, once it has moved somewhere), so take it
    // explicitly before the walk begins.
    if (instance.currentState === chart.initialState) {
      const startTransition = chart.transitions.find((t) => t.from === chart.initialState);
      const result = await engine.triggerTransition(instance.id, startTransition.to);
      instance = result.instance;
    }

    const path = [];

    while (chart.states[instance.currentState].nodeType === 'tool') {
      const nodeId = instance.currentState;
      const node = chart.states[nodeId];
      const step = await evaluateCheck(nodeId, node, { facts, rules, text, backend, backendName });
      path.push(step);

      const transition = chart.transitions.find(
        (t) => t.from === nodeId && t.metadata?.answer === step.answer,
      );
      const result = await engine.triggerTransition(instance.id, transition.to);
      instance = result.instance;
    }

    const endId = instance.currentState;
    const endState = chart.states[endId];

    return {
      chart: chart.id,
      version: chart.version,
      verdict: endId === 'APPROVE' ? 'approve' : 'hold',
      end: endId,
      reason: endState.description,
      path,
    };
  }

  return { review, chart, engine };
}

async function evaluateCheck(nodeId, node, { facts, rules, text, backend, backendName }) {
  const check = node.check;

  if (check.kind === 'fact') {
    const value = facts[check.fact] === true;
    return {
      node: nodeId,
      question: node.description,
      kind: 'fact',
      answer: value ? 'yes' : 'no',
      model: 'fact',
      evidence: `facts.${check.fact} is ${value}`,
    };
  }

  // check.kind === 'text'
  if (check.rule && rules[check.rule] === true) {
    return {
      node: nodeId,
      question: check.question,
      kind: 'text',
      answer: 'no',
      threshold: check.threshold,
      model: 'rule',
      evidence: `community allows ${check.rule}`,
    };
  }

  if (check.when && facts[check.when] !== true) {
    return {
      node: nodeId,
      question: check.question,
      kind: 'text',
      answer: 'no',
      threshold: check.threshold,
      model: 'skipped',
      evidence: `facts.${check.when} is not true`,
    };
  }

  let p;
  let model;
  let evidence;
  try {
    ({ p, model, evidence } = await backend.ask(check.question, text, backendName || check.backend));
  } catch {
    // A backend failure holds the post for a moderator rather than letting
    // it through unreviewed.
    p = 1;
    model = 'unavailable';
  }

  return {
    node: nodeId,
    question: check.question,
    kind: 'text',
    answer: p >= check.threshold ? 'yes' : 'no',
    p,
    threshold: check.threshold,
    model,
    evidence: evidence || `model scored ${p.toFixed(2)} against threshold ${check.threshold}`,
  };
}
