import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { WorkflowEngine, MemoryStorage } from 'ai-flower'
import { getDb, resetDbForTests, upsertDiscourseBug, listPendingDrafts } from '../db/index.js'

// COLLATE_RESULTS writes the delegates' answers into context.questionAnswers and asks for
// persist_question_answers in the SAME decision. The engine must run that action against
// the updated context. When it ran against the context from before the step, the action
// always saw no answers and queued nothing, so every answer the delegates wrote was lost
// without a trace.

const PLAIN_ANSWER =
  'Deleting a rippled post only removes it from the group you are on. ' +
  'The copies on other groups stay. Each group keeps its own copy.'

let db: ReturnType<typeof getDb>

beforeEach(() => {
  resetDbForTests()
  db = getDb(':memory:')
})

afterEach(() => {
  vi.restoreAllMocks()
  resetDbForTests()
})

describe('an LLM state that writes answers and persists them in one step', () => {
  it('queues the answers it has just written', async () => {
    upsertDiscourseBug(db, {
      topic: 10005, post: 18, state: 'question', reporter: 'Jeni',
      excerpt: 'Does deleting a rippled post delete it everywhere?',
      topicTitle: 'Rippling questions', featureArea: 'rippling',
    })

    const mod = await import('../actions/index.js')
    vi.spyOn(mod.questionAnswerDeps, 'fetchReporterQuote')
      .mockResolvedValue('Does deleting a rippled post delete it everywhere?')
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const persist = mod.actions.find((a: any) => a.name === 'persist_question_answers')!

    const decision = {
      reasoning: 'collated one answer',
      contextUpdates: {
        questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }],
      },
      actions: [{ action: 'persist_question_answers', params: {} }],
      proposedTransition: 'DONE',
    }
    const engine = new WorkflowEngine({
      workflow: {
        id: 'collate', name: 'collate', initialState: 'COLLATE_RESULTS',
        states: {
          COLLATE_RESULTS: { description: 'collate', nodeType: 'start', writeActions: ['persist_question_answers'] },
          DONE: { description: 'done', nodeType: 'end' },
        },
        transitions: [{ id: 't1', from: 'COLLATE_RESULTS', to: 'DONE', trigger: 'llm_decision' }],
      },
      storageAdapter: new MemoryStorage(),
      llmAdapter: { call: vi.fn().mockResolvedValue(JSON.stringify(decision)) },
    })
    engine.registerAction(persist)

    const instance = await engine.createInstance()
    const result = await engine.processInput(instance.id, { type: 'tick', data: {} })

    expect(result.actionsExecuted[0].result).toMatchObject({ queued: 1 })
    expect(listPendingDrafts(db).map((d: { topic: number }) => d.topic)).toContain(10005)
  })
})
