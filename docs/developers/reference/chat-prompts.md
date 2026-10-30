---
last_reviewed: 2026-10-30
covers:
  - iznik-server-go/chat/chatprompt.go
  - iznik-server-go/chat/replyconfirm.go
  - iznik-server-go/user/user.go
  - iznik-server-go/isochrone/message.go
  - iznik-batch/app/Services/UnifiedDigestService.php
  - iznik-batch/app/Services/StatsGenerationService.php
  - iznik-batch/app/Services/ChatProcessService.php
  - iznik-batch/app/Services/ChatNotificationService.php
  - iznik-batch/app/Services/PushNotificationService.php
  - iznik-batch/app/Services/UserManagementService.php
  - iznik-batch/app/Services/UserDataExportService.php
  - iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php
  - iznik-nuxt3/modtools/components/ModChatReview.vue
  - iznik-nuxt3/components/ChatMessagePrompt.vue
  - iznik-nuxt3/components/ChatPromptPost.vue
---

# Chat Prompts

A **prompt** is a chat message from Freegle with tappable answers - "Could you deliver?", "When
does it need to go?" - sent into an ordinary `User2User` chat so that delivery, push,
notification, unread state and history all work with no new machinery.

This page covers the **mechanism**: the message type, how a client renders it, and how an answer
is applied. It does not cover what decides to send one; that is a separate feature.

## What sends prompts

- `App\Services\FirstReply\PromptService` asks a poster about their outstanding posts, in their
  own chat with Freegle.
- `chat/replyconfirm.go` asks a **replier**, in their chat with the poster - see "Confirming a
  reply" below.

The client side shipped first, on its own - see "Why this shipped on its own".

## Confirming a reply

When someone replies to an OFFER whose newest `messages_eee` row says it looks damaged
(`item_condition = 'damaged'`), or needs a car (`size_bucket = 'large'`, or `weight_bucket` of
20kg or more, unless the poster has said they might deliver), and the replier is in the arm
`ask` of the experiment `confirm-reply`, `CreateChatMessage` asks them in the same chat whether
they still want it, and holds the reply until they answer. While the experiment runs half of the
repliers to such an offer are in that arm; the others are counted and their reply goes through as
it always did ([ux-experiments.md](ux-experiments.md#confirming-a-reply-before-it-is-passed-on)).
Not when either member is a TrashNothing account, not for a reply held by rippling, not for a post that
already has an outcome, and only once per post per replier. The unit is the replier and the post, so after a no
or an expiry a second reply to the same post goes straight through with no question: a no delays the replier
and does not bar them.

| Piece | How |
|---|---|
| Who is asked | `ux.ExperimentArm(db, replier, "confirm-reply")` is the first line of `planReplyConfirm`, which decides before the reply is written; every eligible reply writes one `ux_experiment_exposures` row, in either arm. Only a message in the chat between the replier and the poster is eligible |
| The question | A `confirm_reply` prompt by the Freegle account, starting "@name," and with `chat_prompts.userid` = the replier. The name is the one every other surface shows (`promptName`): control characters become spaces, and it is cut at an at sign and at 32 characters |
| Who sees it | Written `processingsuccessful = 0`, so no delivery path gives it to the poster; `FetchChatMessages` shows it to `chat_prompts.userid` |
| Telling the replier | The question reaches the replier as any chat prompt does, and never the poster. While it is open (unanswered and not expired) the Go unseen count includes it for the replier (`questionAskedOfMeSQL`), `ChatNotificationService` selects it although it is unsuccessful and mails the replier alone, and `CreateChatMessage` queues `push_notify_chat_message` for it, which `PushNotificationService::getChatMessageRecipients` sends to the replier alone (`ChatMessage::questionAskedOnlyOf`). Once the reply is delivered the question is successful and goes to the poster with it, as before. Without this a replier who leaves the chat never learns of the question and the reply is dropped after 3 days |
| Who answers it | Only `chat_prompts.userid`; anyone else gets 403 |
| The hold | `chat_prompts.heldchatmsgid` names the reply; `ChatProcessService` leaves it unprocessed while the prompt is unanswered. The reply, the exposure, the question and the hold are written in one transaction (`CreateChatMessage`), because the batch takes any committed unprocessed reply |
| Yes | The next `chats:process-incoming` run delivers the reply as normal, and in the same step marks the question successful, so the poster gets the question, the answer and the reply together |
| A reply that is held or dropped | A reply held for review (a Fully moderated member, a Moderated member whose text trips a check, a message after a held one) or dropped by a block keyword is processed and successful too, and the poster is shown nothing of it. Its question stays hidden. A moderator who approves the reply shows the question with it (`revealQuestionOfApprovedReply`); one who rejects it never does |
| No | The reply is marked processed and unsuccessful, `processingfailreason = 'ReplyWithdrawn'`. If that update fails the answer is 500 and is not recorded, so the member can tap again: recorded, it would release the reply. A no that finds the reply already processed is recorded and reported as not applied |
| Another message from the replier | Never an answer: only the two buttons answer. Whatever the replier writes in that chat while the question is open waits behind it, unprocessed (`ChatProcessService::awaitingConfirmation`), so it cannot reach the poster ahead of the reply. A yes delivers it after the reply, a no withdraws it with the reply (`applyReplyConfirmAnswer`), and when the question expires it is dropped with the reply. A message written after the question expired is an ordinary message |
| The post is taken or withdrawn | `ProcessBackgroundTasksCommand::expireReplyQuestions`, run first by the outcome task, closes an open question with the answer `expired` (`answered_via` `post_gone`) and withdraws the reply, and what the replier wrote after it, with `processingfailreason = 'ReplyPostGone'`. A reply already answered yes but not yet delivered is withdrawn the same way, and `ChatProcessService` also refuses to deliver a confirmed reply whose post has an outcome. The outcome task then writes its poster-authored `Completed` message only into chats where the poster has the reply. A reply to a post that already has an outcome is not asked about (`planReplyConfirm`). The card shows an `expired` question with no buttons |
| No answer in 3 days | Dropped by `ChatProcessService`, `processingfailreason = 'ReplyNotConfirmed'` |
| The hold chain | The question is skipped when `ChatProcessService` looks for the message before the one it is processing, so it cannot let a message through ahead of a held reply |
| Merging two accounts | The merge moves `chat_prompts.userid` to the surviving account with the member's chat messages, before the old account is deleted. Left behind, the foreign key would delete the question with the old account and the reply it holds would be delivered unasked. A member who is deleted outright loses their chat messages with them, so nothing is left held |
| Forgetting a member | `UserManagementService::forgetUser` blanks the questions Freegle asked them, takes them out of the poster's chat, deletes their `chat_prompts` rows and drops a reply of theirs the batch has not processed. The data export has a `questions` section, and the support dump has the rows |

### Child car seats

A reply to an OFFER whose subject says it is a child car seat (`isChildCarSeat`: "car seat", "carseat",
"child car", "booster seat", "isofix", "infant carrier", the same words that show the poster a note under
the box in `PostItem.vue`) is asked about whatever the experiment is doing: in either arm, with no experiment
running, and from the app, which the experiment leaves out. The question is the moderators' wording from
Discourse 9939/61 (`childCarSeatQuestion`): know its history, never in an accident, the E mark, the maker's
lifespan, and read childcarseats.org.uk, then "Do you still want it?" with the same two buttons. It is held,
answered, pushed, expired and withdrawn exactly as the experiment's question is; only the deciding differs. It
writes no `ux_experiment_exposures` row, so it is not in the experiment's figures, and it is asked once per
replier and post, which is read from `chat_prompts` rather than from an exposure. A damaged or bulky car seat
gets the car seat question alone. A WANTED for a car seat is not asked about: the wording is for someone
accepting one.

`chat_prompts.answered_via` records how the question was answered; it is always `button`. The answer is
claimed by an update that only an unanswered question matches, in the transaction that withdraws the reply, so
two taps cannot both win and a no that is recorded has been applied. How
repliers answered, for all reasons and for each, is on the experiment's page in the Experiments
section of the SysAdmin UX tab.

Deploy order: run `iznik-batch/database/migrations/2026_10_13_000001_add_reply_confirm_to_chat_prompts_migration.sql`
first. `FetchChatMessages` reads `chat_prompts.userid` for every member's chat fetch and `chats:process-incoming`
reads `heldchatmsgid` on every pass, so code that is live before the columns fails loudly for all members, not
only the ones asked. Then deploy the Go API and the batch, then switch the experiment on.

ModTools chat review explains each reason a reply is not delivered (`ReplyWithdrawn`, `ReplyNotConfirmed`,
`ReplyPostGone`). The `chats:process-incoming` backlog check ignores a
reply waiting for its answer, and what the replier wrote behind the question.

Accepted: an app build that cannot draw a Prompt message never answers, so its repliers fall into
"did not answer" after three days, in the asked half only. The reply counters behind the ranking,
the digest and the daily stats, like the My Posts list, leave a held or refused reply out through one gate
(`utils.ReplyHeldByQuestionSQL`, and `ChatMessage::heldByQuestionSql` in the batch): a reply is not counted
while its question is open, after a no, after the post went, or while it waits behind the question. The
first-reply scouts (the match mail and the pass-through) ask whether anyone has replied at all, and a replier
who has been asked a question has, so they count it.

A Freegle message in a chat between two members names who it is for with an @, so whoever reads
it later can tell. The served prompt carries `userid` and `askedname`, and
`ChatMessagePrompt.vue` shows "@name said:" rather than "You said:" to anyone else. In a chat
between two members the card also carries the Freegle logo and name, and "This is an automated
message."; in a member's own chat with Freegle the header already says both, once.

`messages_eee` is filled hourly, so a reply in a post's first hour usually finds no row and goes
straight through. The model's own accuracy is about 93% for condition, 72% for size and 65% for
weight, which is why the question says "from the photo, this looks like" and lets the member
decide.

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

Rules the endpoint enforces: only the member the prompt was sent to (or, where
`chat_prompts.userid` is set, that member alone) may answer; only an option
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

`iznik-server-go/test/chatreplyconfirm_test.go` covers confirming a reply: which posts ask and
which do not (TrashNothing on either side, a poster who delivers, a WANTED, no classification,
a newer classification), that only the replier sees and answers it, and yes, no and what the
replier writes while the question is open. The `TestReplyConfirm_*` tests also cover the exposure rows, the arms and the failures, the
name on the card, the reply and its hold written in one transaction, a no that fails, a question that has
run out, and a moderator approving a held reply. `ChatProcessServiceTest` covers the hold, delivery after
yes, the drop on expiry, a held or dropped reply whose question stays hidden, and the hold chain.
