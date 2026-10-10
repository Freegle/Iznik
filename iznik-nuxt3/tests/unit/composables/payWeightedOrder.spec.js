import { describe, it, expect } from 'vitest'
import { payWeightedOrder } from '~/composables/payWeightedOrder'

describe('payWeightedOrder', () => {
  const jobs = [
    { id: 1, cpc: 0.08 },
    { id: 2, cpc: 0.36 },
    { id: 3, cpc: 0.15 },
  ]

  it('puts better-paid jobs first when the draws are equal', () => {
    expect(payWeightedOrder(jobs, () => 0.5).map((j) => j.id)).toEqual([
      2, 3, 1,
    ])
  })

  it('still lets a lower-paid job come first on a lucky draw', () => {
    const draws = [0.0001, 0.9999, 0.9999] // job 1 draws u close to 1
    let i = 0
    expect(payWeightedOrder(jobs, () => draws[i++])[0].id).toBe(1)
  })

  it('favours pay over many runs', () => {
    let firstIsBest = 0
    for (let run = 0; run < 2000; run++) {
      if (payWeightedOrder(jobs)[0].id === 2) firstIsBest++
    }
    // Weight 0.36 of 0.59 in total: the best-paid job leads about 61% of the time.
    expect(firstIsBest).toBeGreaterThan(1050)
    expect(firstIsBest).toBeLessThan(1400)
  })

  it('keeps every job and copes with a missing cpc or list', () => {
    const out = payWeightedOrder([{ id: 9 }, ...jobs], () => 0.5)
    expect(out.map((j) => j.id).sort()).toEqual([1, 2, 3, 9])
    expect(out[out.length - 1].id).toBe(9)
    expect(payWeightedOrder(undefined)).toEqual([])
  })

  it('does not change the list it is given', () => {
    const copy = jobs.map((j) => j.id)
    payWeightedOrder(jobs)
    expect(jobs.map((j) => j.id)).toEqual(copy)
  })
})
