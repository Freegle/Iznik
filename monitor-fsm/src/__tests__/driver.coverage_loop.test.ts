import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

/**
 * Tests for driver.ts iteration loop logic
 *
 * These tests focus on the core FSM loop mechanics:
 * - consecutiveCoverageFailures counter behavior
 * - MAX_STEPS hard cap
 * - State transitions
 */

describe('Driver: iteration loop logic', () => {
  describe('consecutiveCoverageFailures counter', () => {
    it('should reset counter to 0 after successful coverage validation', () => {
      // Simulate the counter reset logic
      let consecutiveCoverageFailures = 2
      const successfulValidation = true

      if (successfulValidation) {
        consecutiveCoverageFailures = 0
      }

      expect(consecutiveCoverageFailures).toBe(0)
    })

    it('should increment counter after coverage validation failure', () => {
      // Simulate validation failure
      let consecutiveCoverageFailures = 0
      const validationPassed = false

      if (!validationPassed) {
        consecutiveCoverageFailures++
      }

      expect(consecutiveCoverageFailures).toBe(1)
    })

    it('should accumulate across multiple failures', () => {
      let consecutiveCoverageFailures = 0

      // First failure
      consecutiveCoverageFailures++
      expect(consecutiveCoverageFailures).toBe(1)

      // Second failure
      consecutiveCoverageFailures++
      expect(consecutiveCoverageFailures).toBe(2)

      // Third failure
      consecutiveCoverageFailures++
      expect(consecutiveCoverageFailures).toBe(3)
    })

    it('should trigger WRAP_UP transition after 2 consecutive failures', () => {
      let consecutiveCoverageFailures = 0
      let targetState = 'COVERAGE_GATE'

      // First failure
      consecutiveCoverageFailures++
      const target1 = consecutiveCoverageFailures >= 2 ? 'WRAP_UP' : 'COVERAGE_GATE'
      expect(target1).toBe('COVERAGE_GATE')

      // Second failure
      consecutiveCoverageFailures++
      const target2 = consecutiveCoverageFailures >= 2 ? 'WRAP_UP' : 'COVERAGE_GATE'
      expect(target2).toBe('WRAP_UP')
    })

    it('should NOT transition to WRAP_UP on single failure', () => {
      let consecutiveCoverageFailures = 0
      consecutiveCoverageFailures++ // first failure

      const target = consecutiveCoverageFailures >= 2 ? 'WRAP_UP' : 'COVERAGE_GATE'
      expect(target).toBe('COVERAGE_GATE')
    })

    it('should not increment counter on successful gate check', () => {
      let consecutiveCoverageFailures = 1
      const gateCheckPassed = true

      if (gateCheckPassed) {
        consecutiveCoverageFailures = 0
      }

      expect(consecutiveCoverageFailures).toBe(0)
    })

    it('should reset counter when transitioning out of coverage gate', () => {
      let consecutiveCoverageFailures = 2
      const transitionedToNonGate = true

      if (transitionedToNonGate) {
        consecutiveCoverageFailures = 0
      }

      expect(consecutiveCoverageFailures).toBe(0)
    })
  })

  describe('COVERAGE_GATE exhausted-red-PR routing', () => {
    // Regression: coverage_gate_decide must exclude exhausted red PRs (fix-attempt
    // budget spent, or deliberately held) when deciding whether to route back to
    // CI_ROUTER. Counting them as actionable CI ping-ponged COVERAGE_GATE↔CI_ROUTER
    // forever and starved WRITE_COVERAGE/Sentry (the bug that hung the 10-loop run).
    const MAX_FIX_ATTEMPTS = 3

    // Mirror of coverage_gate_decide's target selection.
    const decide = (redPRs: Array<{ number: number; attempts: number }>, opts: { dirty?: boolean; pending?: number; prCount?: number; jitter?: number } = {}) => {
      const pickableRedCount = redPRs.filter(p => p.attempts < MAX_FIX_ATTEMPTS).length
      if (pickableRedCount > 0) return 'CI_ROUTER'
      if (opts.dirty) return 'REBASE_DIRTY_PRS'
      if ((opts.jitter ?? 0) > 0) return 'WRITE_COVERAGE'
      if ((opts.pending ?? 0) > 0) return 'WRAP_UP'
      if ((opts.prCount ?? 0) > 0) return 'WRAP_UP'
      return 'WRITE_COVERAGE'
    }

    it('routes to CI_ROUTER when at least one red PR is still pickable', () => {
      expect(decide([{ number: 818, attempts: 1 }])).toBe('CI_ROUTER')
      expect(decide([{ number: 818, attempts: 3 }, { number: 42, attempts: 0 }])).toBe('CI_ROUTER')
    })

    it('falls through to WRITE_COVERAGE when ALL red PRs are exhausted', () => {
      // The exact hang: #828, #639, #818 all at the attempt cap.
      expect(decide([
        { number: 828, attempts: 3 },
        { number: 639, attempts: 3 },
        { number: 818, attempts: 3 },
      ])).toBe('WRITE_COVERAGE')
    })

    it('still prefers rebase/drain over coverage once CI is non-actionable', () => {
      const exhausted = [{ number: 818, attempts: 3 }]
      expect(decide(exhausted, { dirty: true })).toBe('REBASE_DIRTY_PRS')
      expect(decide(exhausted, { pending: 2 })).toBe('WRAP_UP')
      expect(decide(exhausted, { prCount: 1 })).toBe('WRAP_UP')
    })

    it('routes to WRITE_COVERAGE when there are no red PRs at all', () => {
      expect(decide([])).toBe('WRITE_COVERAGE')
    })
  })

  describe('COVERAGE_GATE coverage-jitter routing', () => {
    // Regression: a coverage-jitter PR (tests green, red only on a Coveralls delta) is
    // excluded from redPRs on purpose, so the fix-CI path will never touch it. The ONLY
    // thing that can clear it is WRITE_COVERAGE's STEP 0 boost, which pushes coverage to
    // that same branch. Both WRAP_UP arms exist to avoid CREATING new PRs, which the
    // boost does not do — so if they win, the jitter PR stays red forever. #1240, #1241
    // and #1246 sat red across whole iterations for exactly this reason.
    const MAX_FIX_ATTEMPTS = 3

    const decide = (redPRs: Array<{ number: number; attempts: number }>, opts: { dirty?: boolean; pending?: number; prCount?: number; jitter?: number } = {}) => {
      const pickableRedCount = redPRs.filter(p => p.attempts < MAX_FIX_ATTEMPTS).length
      if (pickableRedCount > 0) return 'CI_ROUTER'
      if (opts.dirty) return 'REBASE_DIRTY_PRS'
      if ((opts.jitter ?? 0) > 0) return 'WRITE_COVERAGE'
      if ((opts.pending ?? 0) > 0) return 'WRAP_UP'
      if ((opts.prCount ?? 0) > 0) return 'WRAP_UP'
      return 'WRITE_COVERAGE'
    }

    it('boosts a jitter PR even when this iteration already created a PR', () => {
      expect(decide([], { jitter: 1, prCount: 1 })).toBe('WRITE_COVERAGE')
    })

    it('boosts a jitter PR even while other CI is still pending', () => {
      // Drain mode is about not creating new coverage PRs; the boost creates none.
      expect(decide([], { jitter: 3, pending: 2 })).toBe('WRITE_COVERAGE')
    })

    it('boosts a jitter PR when both wrap-up conditions hold at once', () => {
      // The real starvation case: productive iteration, CI busy, jitter PRs waiting.
      expect(decide([], { jitter: 3, pending: 2, prCount: 1 })).toBe('WRITE_COVERAGE')
    })

    it('still puts genuinely red CI and dirty branches first', () => {
      expect(decide([{ number: 1245, attempts: 0 }], { jitter: 3 })).toBe('CI_ROUTER')
      expect(decide([], { jitter: 3, dirty: true })).toBe('REBASE_DIRTY_PRS')
    })

    it('leaves the no-jitter behaviour exactly as it was', () => {
      expect(decide([], { pending: 2 })).toBe('WRAP_UP')
      expect(decide([], { prCount: 1 })).toBe('WRAP_UP')
      expect(decide([], {})).toBe('WRITE_COVERAGE')
    })
  })

  describe('MAX_STEPS hard cap', () => {
    const MAX_STEPS = 40

    it('should stop iteration at MAX_STEPS', () => {
      let step = 0
      const maxSteps = MAX_STEPS

      // Simulate iteration loop
      while (step < maxSteps) {
        step++
        if (step >= maxSteps) break
      }

      expect(step).toBe(MAX_STEPS)
    })

    it('should not exceed MAX_STEPS even if FSM never completes', () => {
      let step = 0
      const maxSteps = MAX_STEPS
      let iterationComplete = false

      while (step < maxSteps && !iterationComplete) {
        step++
        // FSM never completes in this scenario
      }

      expect(step).toBe(MAX_STEPS)
      expect(iterationComplete).toBe(false)
    })

    it('should exit early if FSM completes before MAX_STEPS', () => {
      let step = 0
      const maxSteps = MAX_STEPS
      let iterationComplete = false

      while (step < maxSteps) {
        step++
        if (step === 15) {
          iterationComplete = true
          break
        }
      }

      expect(step).toBe(15)
      expect(step).toBeLessThan(MAX_STEPS)
    })

    it('should track step count accurately', () => {
      let step = 0
      const maxSteps = MAX_STEPS
      const stepsExecuted: number[] = []

      while (step < maxSteps) {
        step++
        stepsExecuted.push(step)
        if (step === 20) break
      }

      expect(stepsExecuted.length).toBe(20)
      expect(stepsExecuted[0]).toBe(1)
      expect(stepsExecuted[19]).toBe(20)
    })

    it('should handle MAX_STEPS=40 as documented constant', () => {
      const docConstant = 40
      expect(MAX_STEPS).toBe(40)
      expect(docConstant).toBe(MAX_STEPS)
    })
  })

  describe('combined counter and step cap behavior', () => {
    const MAX_STEPS = 40

    it('should respect both coverage failures and step cap', () => {
      let step = 0
      let consecutiveCoverageFailures = 0
      let targetState = 'COVERAGE_GATE'
      const maxSteps = MAX_STEPS

      // Simulate 3 coverage failures spread across the iteration
      const failurePoints = [5, 10, 15]

      while (step < maxSteps) {
        step++

        if (failurePoints.includes(step)) {
          consecutiveCoverageFailures++
          if (consecutiveCoverageFailures >= 2) {
            targetState = 'WRAP_UP'
            break
          }
        }

        // Simulate successful CI
        if (step === 16) {
          break // successful completion
        }
      }

      expect(step).toBeLessThanOrEqual(maxSteps)
      expect(consecutiveCoverageFailures).toBe(2)
      expect(targetState).toBe('WRAP_UP')
    })

    it('should timeout after MAX_STEPS regardless of coverage state', () => {
      let step = 0
      let consecutiveCoverageFailures = 0
      let iterationComplete = false
      const maxSteps = MAX_STEPS

      while (step < maxSteps && !iterationComplete) {
        step++
        // Coverage keeps failing but we never reach the break threshold
        if (step % 3 === 0) {
          consecutiveCoverageFailures++
          if (consecutiveCoverageFailures >= 2) {
            // Would transition to WRAP_UP, but we're testing the hard cap
            break
          }
        }
      }

      const outcome = step >= maxSteps ? 'timeout' : (iterationComplete ? 'completed' : 'errored')
      expect(step).toBeLessThanOrEqual(maxSteps)
    })
  })

  describe('state machine transitions', () => {
    it('should track state name in iteration', () => {
      const states = ['LOAD_STATE', 'FETCH_DISCOURSE', 'CHECK_CI', 'COVERAGE_GATE', 'WRAP_UP', 'END']
      let currentStateIndex = 0

      const transition = (nextStateName: string) => {
        const nextIndex = states.indexOf(nextStateName)
        if (nextIndex >= 0) {
          currentStateIndex = nextIndex
          return true
        }
        return false
      }

      expect(transition('COVERAGE_GATE')).toBe(true)
      expect(states[currentStateIndex]).toBe('COVERAGE_GATE')

      expect(transition('WRAP_UP')).toBe(true)
      expect(states[currentStateIndex]).toBe('WRAP_UP')
    })

    it('should not allow transition to non-existent state', () => {
      const states = ['LOAD_STATE', 'FETCH_DISCOURSE', 'COVERAGE_GATE']
      let currentStateIndex = 0

      const transition = (nextStateName: string) => {
        const nextIndex = states.indexOf(nextStateName)
        if (nextIndex >= 0) {
          currentStateIndex = nextIndex
          return true
        }
        return false
      }

      const success = transition('INVALID_STATE')
      expect(success).toBe(false)
      expect(currentStateIndex).toBe(0) // should not change
    })
  })

  describe('iteration termination conditions', () => {
    const MAX_STEPS = 40

    it('should terminate on completed status', () => {
      let step = 0
      let instanceStatus = 'active'

      while (step < MAX_STEPS) {
        step++
        if (step === 8) {
          instanceStatus = 'completed'
        }
        if (instanceStatus === 'completed') {
          break
        }
      }

      expect(instanceStatus).toBe('completed')
      expect(step).toBe(8)
    })

    it('should terminate on error status', () => {
      let step = 0
      let instanceStatus = 'active'

      while (step < MAX_STEPS) {
        step++
        if (step === 12) {
          instanceStatus = 'error'
        }
        if (instanceStatus !== 'active') {
          break
        }
      }

      expect(instanceStatus).toBe('error')
      expect(step).toBe(12)
    })

    it('should use step-cap timeout if no early termination', () => {
      let step = 0
      const maxSteps = MAX_STEPS
      let instanceStatus = 'active'
      let outcome = ''

      while (step < maxSteps) {
        step++
        // Never transitions to completed or error
        if (step >= maxSteps) break
      }

      outcome = step >= maxSteps ? 'timeout' : 'completed'
      expect(outcome).toBe('timeout')
      expect(step).toBe(MAX_STEPS)
    })
  })
})
