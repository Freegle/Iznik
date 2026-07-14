import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect } from 'vitest'

// ChatFooter.vue can't be mounted in this suite (top-level await in setupChat makes
// Vue treat it as async setup - see ChatFooterDraftPersistence.spec.js for the same
// constraint). This file ties the send-failure/retry behaviour (Discourse #9913) back
// to the real source, the same way that file ties the draft-persistence fix back to
// ChatFooter.vue: assert the actual code does what the fix requires, rather than
// re-implementing it in a mock component.

const source = readFileSync(
  resolve(__dirname, '../../../components/ChatFooter.vue'),
  'utf-8'
)

describe('ChatFooter send failure surfaces to the user and is retry-safe (#9913)', () => {
  it('does not clear the typed message when a send fails', () => {
    // sendmessage.value = '' must only appear after the try block, never inside the
    // catch - otherwise a failed send silently loses what the user typed.
    const catchBlock = source.slice(
      source.indexOf('} catch (e) {', source.indexOf('const send = async')),
      source.indexOf('return\n      }', source.indexOf('const send = async'))
    )
    expect(catchBlock).not.toMatch(/sendmessage\.value\s*=\s*''/)
  })

  it('sets a user-visible sendError with a retry affordance on a generic send failure', () => {
    expect(source).toMatch(
      /sendError\.value\s*=\s*\n?\s*"Sorry, your message couldn't be sent[^"]*Tap Send to try again\."/
    )
  })

  it('reuses the same idempotency key across a retry of the same pending message', () => {
    expect(source).toMatch(/getSendIdempotencyKey\(pendingSend\.value, msg\)/)
    // Only cleared on success, so a subsequent retry (pendingSend still set) reuses it.
    expect(source).toMatch(
      /pendingSend\.value = \{ message: msg, key: idempotencyKey \}/
    )
    expect(source).toMatch(/pendingSend\.value = null/)
  })

  it('passes the idempotency key through to chatStore.send', () => {
    const sendCall = source.slice(
      source.indexOf('await chatStore.send('),
      source.indexOf('await chatStore.send(') + 200
    )
    expect(sendCall).toMatch(/idempotencyKey/)
  })
})
