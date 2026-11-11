import { describe, it, expect } from 'vitest'
import { FlowName } from '~/composables/useFlowTelemetry'
import {
  FLOW_ORDER,
  FLOW_TITLES,
  FLOW_SHORT,
  flowTitle,
  flowShort,
} from '~/modtools/composables/useUxFlows'

describe('useUxFlows', () => {
  const flows = Object.values(FlowName)

  it('has an order, a title and a short name for every flow the app can send', () => {
    for (const f of flows) {
      expect(FLOW_ORDER, f).toContain(f)
      expect(FLOW_TITLES[f], f).toBeTruthy()
      expect(FLOW_SHORT[f], f).toBeTruthy()
    }
  })

  it('lists no flow the app does not send, and none twice', () => {
    for (const f of FLOW_ORDER) expect(flows, f).toContain(f)
    expect(new Set(FLOW_ORDER).size).toBe(FLOW_ORDER.length)
    expect(Object.keys(FLOW_TITLES).sort()).toEqual([...flows].sort())
    expect(Object.keys(FLOW_SHORT).sort()).toEqual([...flows].sort())
  })

  it('puts the funnels in the order members meet them', () => {
    expect(FLOW_ORDER).toEqual([
      'give',
      'ask',
      'reply',
      'chat_promise',
      'chat_outcome',
      'login',
      'settings',
    ])
  })

  it('names a funnel in words, and prints an unknown one as its key', () => {
    expect(flowTitle('chat_outcome')).toBe('Marking taken or received')
    expect(flowShort('chat_outcome')).toBe('Marking taken')
    expect(flowTitle('something_new')).toBe('something_new')
    expect(flowShort('something_new')).toBe('something_new')
  })
})
