import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HelperProposalCard from '~/components/HelperProposalCard.vue'

const mountOpts = {
  global: {
    stubs: { 'b-button': true, 'b-badge': true, 'b-form-textarea': true },
  },
}

const mountCard = (proposal) =>
  mount(HelperProposalCard, { ...mountOpts, props: { proposal } })

describe('HelperProposalCard', () => {
  it('shows an editable draft and emits resolve send with edited text', async () => {
    const w = mountCard({
      id: 5,
      type: 'allocation',
      summary: 'Allocate 2 desks',
      proposed_text: 'Good news!',
      rationale: 'Only candidate',
    })
    expect(w.vm.hasText).toBe(true)
    expect(w.vm.typeLabel).toBe('Allocate')
    w.vm.text = 'Edited message'
    await w.vm.send()
    const ev = w.emitted().resolve
    expect(ev).toBeTruthy()
    expect(ev[0][0]).toMatchObject({
      id: 5,
      decision: 'send',
      text: 'Edited message',
    })
  })

  it('emits resolve dismiss', async () => {
    const w = mountCard({ id: 6, type: 'message', proposed_text: 'hi' })
    await w.vm.dismiss()
    expect(w.emitted().resolve[0][0]).toEqual({ id: 6, decision: 'dismiss' })
  })

  it('omits text when the proposal carries no message (e.g. escalation)', async () => {
    const w = mountCard({ id: 7, type: 'escalation', summary: 'Photo request' })
    expect(w.vm.hasText).toBe(false)
    await w.vm.send()
    expect(w.emitted().resolve[0][0]).toEqual({ id: 7, decision: 'send' })
  })

  it('labels an allocation send as a confirmation', () => {
    const alloc = mountCard({ id: 8, type: 'allocation', proposed_text: 'x' })
    expect(alloc.vm.sendLabel).toBe('Confirm & send')
    const msg = mountCard({ id: 9, type: 'message', proposed_text: 'x' })
    expect(msg.vm.sendLabel).toBe('Send')
  })
})
