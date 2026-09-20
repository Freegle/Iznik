---
last_reviewed: 2026-08-17
covers:
  - iznik-server-go/chat/chatprompt.go
  - iznik-nuxt3/components/ChatMessagePrompt.vue
  - iznik-nuxt3/components/ChatPromptPost.vue
---

# Chat Prompts

A **prompt** is a chat message from Freegle with tappable answers - "Could you deliver?", "When
does it need to go?" - sent into an ordinary `User2User` chat so that delivery, push,
notification, unread state and history all work with no new machinery.

This page covers the **mechanism**: the message type, how a client renders it, and how an answer
is applied. It does not cover what decides to send one; that is a separate feature.

## Nothing sends prompts yet

This ships the ability to **understand** a prompt, not to create one. No code here writes a
`Prompt` message. That is deliberate - see "Why this shipped on its own" below.

## The shape

| Piece | Where |
|---|---|
| The message | `chat_messages` with `type = 'Prompt'` and readable text in `message` |
| The tappable part | `chat_prompts`, keyed on `chatmsgid` |
| Which posts it covers | `chat_prompts.msgids`, a JSON array |
| Serving it | `attachPrompts()` in `chat/chatmessage.go` fills `ChatMessage.prompt` |
| Answering it | `POST /chat/{id}/message/{mid}/prompt` (`chat.AnswerChatPrompt`) |
| Rendering it | `ChatMessagePrompt.vue`, one `ChatPromptPost.vue` per covered post |

The message carries **normal text whatever happens**, so anything that does not understand the
type - an email notification, a push, a mod tool, an older app - still has something sensible to
show.

Answering is server-side only. The server applies what the answer means (setting
`messages.deadline`, `messages.deliverypossible`) as well as recording it, so a client never has
to know what "by this weekend" resolves to, and two clients cannot disagree about it.

Rules the endpoint enforces: only the member the prompt was sent to may answer; only an option
that was actually offered is accepted; only once; and not after the prompt has expired - a
week-old "could you deliver?" on a long-gone item still renders, but its buttons no longer work.

## Why this shipped on its own

**Old apps cannot be upgraded in arrears.** A client that does not know a message type falls
through to the catch-all branch in `ChatMessage.vue`. Until this change that branch rendered

```
Unknown chat message type Prompt, [object] [object]
```

directly into the member's conversation. That is not hypothetical - it already happened once in
the wild with the `System` type (`6d833bb62`, "render System chat messages instead of a raw error
dump").

So the fallback now renders `message` instead of a debug dump, and **any** future type degrades to
plain readable text rather than something broken.

That fixes clients carrying this build. It does nothing for the app somebody is already running,
which is why the sending side is a separate change: every client has to be able to understand a
prompt *before* anything starts sending them.

**There is no way to gate this per member.** `users_builddates` has an `appversion` column, but it
is empty for every user seen in the last 30 days on live (41,402 of 41,402) - only `webversion` is
populated. So we can neither suppress prompts for old clients nor measure app adoption from it. The
control is therefore time and release adoption, not a query, and that is worth fixing before the
sending side is switched on.

## Testing

`iznik-server-go/test/chatprompt_test.go` covers serving a prompt with its options and every
answer rule (wrong member, unoffered option, twice, expired, picked date, out-of-range date, and
"no rush" which records without patching the post). `ChatMessagePrompt.spec.js` and
`ChatPromptPost.spec.js` cover rendering and the answered state.
