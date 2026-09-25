---
last_reviewed: 2026-09-20
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/ContentCheckService.php
  - iznik-batch/app/Services/AutoApproveService.php
  - iznik-batch/app/Services/Judgement/**
  - iznik-batch/app/Services/TakedownService.php
  - iznik-batch/app/Services/ReportResolutionService.php
  - iznik-batch/app/Services/ChatProcessService.php
  - iznik-batch/app/Services/ChatReviewService.php
  - iznik-server-go/chat/warnnothold.go
  - iznik-server-go/chat/replygate.go
  - iznik-server-go/microvolunteering/graded.go
  - iznik-nuxt3/modtools/pages/index.vue
---

# What used to be a person, and what does it now

This branch is a thought experiment: a Freegle with no communities and no more than ten
national volunteers. The rule behind it is that **humans should be able to add value, but
the system must reach an outcome with nobody there.** This page is the audit of that rule.
For every task a moderator used to do it says what replaced it, how, what can go wrong, and
what limits the damage. It is written from the code and is updated with it.

Two things run through every row:

- **Every automatic outcome is reversible and the member is told.** A post that is taken down
  can be restored with one tap and the poster hears both times. Nothing is silently hidden.
- **Nothing waits for a person.** The only states are live, taken down, and a wait of minutes.
  A volunteer who opens ModTools improves an outcome the system already reached.

## The tasks

| What a person did | What does it now | How | Risk | Mitigation |
|---|---|---|---|---|
| Approved each post in a community's pending queue | The content check and the judge, then the auto-approve wait | `ContentCheckService` runs every minute. Exact checks (phone numbers, links, language, repeats) are code; the site rules are questions the judge answers with a confidence and a reason. Clean and unrestricted is live at once; a wait question is live after `AutoApproveService`'s delay; a takedown question at or above the confidence threshold is a takedown | A rule-breaking post goes live (the judge says no); a fine post is taken down (the judge says yes) | The threshold is high for a takedown and anything below it is only a wait. The poster is told the reason in the judge's words and can fix and repost. ModTools "Just published" and "Taken down" show every judgement with one-tap take down or restore. `judgement:eval` measures accuracy on a fixture set before the questions change |
| Rejected or marked a post as spam, with a standard message | The same takedown, with the reason written by the judge | `TakedownService::takeDown` sets the post state and sends the poster a message as Freegle in their existing Freegle chat, so the email and push already work | A confused or angry poster; a wrong reason | The message says which post, why and how to post again. A volunteer can restore; the poster is told again |
| Kept word lists: spam keywords, worry words per community | Questions instead of words | The lists and the code that read them are gone. The questions live in config and are the same everywhere | The judge is unavailable, slow or expensive | Unavailable means the exact checks still run and the post is live after the short wait; nothing is held or removed because the judge was down. Cost is logged per call and per day; the model is a setting |
| Handled reports one by one in a per-community chat | Report resolution in the batch, with the judge as a third voice | Go records the report. `ReportResolutionService` judges the post against the reporter's reason every minute. Two member reports or one moderator report is quorum; one report is enough if the judge agrees; three are needed if it disagrees. On quorum the post is taken down and the poster and every reporter are told | Two members collude to remove a post; a reporter never hears back | The judge disagreeing raises the quorum to three. Each reporter is told the outcome. The poster can fix and repost, and a volunteer can restore |
| Reviewed held chat messages before the recipient could see them | Delivery behind a warning | A message a check or the judge flags is delivered with a warning the recipient taps through, naming the risk (money, a link, abuse). A hold about the sender rather than the message (fully moderated, or a chain from a rejected message) is never delivered | A scam reaches the recipient; abuse is seen | The recipient sees the warning first and the reason. The sender is flagged; the existing rule that many rejections mark a sender as spam still applies. A volunteer can reject a delivered message later and it is hidden again |
| Chased members who replied a lot, or spotted "collectors" | The reply gate | After `REPLY_GATE_AFTER` replies in a day (default five) the next reply needs a graded micro-volunteering task answered correctly. Nobody sets it and a wrong answer does not open it | A genuine frequent replier is slowed; the graded task pool is thin | The task is short and the pool is posts other members have already settled. The number is a setting |
| Reviewed members with many postcodes, or flagged notes, before they could post | Advisory flags | Flags are stored on the member and listed in ModTools. They stop nothing | A bad actor keeps posting | Posting status still restricts (`MODERATED`, `PROHIBITED`) and a ban is still one action. The judge sees every post they make |
| Answered "contact the volunteers" messages | An automatic reply, at once | The member's Freegle chat replies with what was received, the help pages and the rules, and says a volunteer may add a reply. No chase mail to moderators | A real problem gets a canned answer | The support mailbox is unchanged for anything that needs a person. ModTools "Messages to Freegle" lists them for a volunteer to add to |
| Welcomed new members per community | One welcome, nationally | Sent once when the member is created | None worth naming | |
| Approved events and volunteering opportunities | Live on creation, then the judge | Both go live when created and are judged for scams and decency; a yes takes them down and tells the author | A scam event is live for up to a minute | The judge, the report path and ModTools "Events and volunteering" with remove |
| Set community rules, settings, standard messages | One fixed behaviour per setting, and the rules as the judge's questions | Each of the 123 per-community settings became a constant at the value most of the 496 live communities used, and the code for the other value was deleted; the rules most communities had became the questions. Standard messages are a volunteer's own set, not a community's | Local nuance is lost: a community that chose the minority setting gets the majority one | Accepted for the thought experiment. A rule that matters everywhere is one edit to the questions; the majority values are recorded in the plan so any can be revisited |
| Watched queue counts and answered chase mails | Nothing to chase | Queues, badge counts and chase mails are gone. ModTools' home page lists what a volunteer could add to today | Volunteers do not know where they would help | An optional daily "things you could help with" mail that the site does not depend on being read |
| Micro-volunteering verdicts fed a moderator's decision | Verdicts grade the reply gate | Settled verdicts are the answer key for the graded task | None worth naming | |

## Risks that are not one row

- **The judge is a third party.** Post text, item names and photos go to the model provider.
  No member identifiers, emails or locations are sent, and chat messages from unmoderated
  members are not sent at all. This is a data-protection decision for people, not the code, and
  would need a privacy policy change before it was more than an experiment.
- **A post can try to talk to the judge.** The questions are the system prompt and the post is
  data in the user turn; answers are yes or no with a schema. Even a fooled judge only reaches a
  reversible outcome with the member told.
- **Bias.** A judge may over-flag posts in Welsh, other languages or dialect. The eval fixture
  set includes them, and a wrong takedown is one tap to reverse and is visible in ModTools.
- **Cost.** Every post, and every chat message from a moderated member, is a model call. Usage
  is logged; the model is a setting; the questions prompt is cached.
- **Fewer eyes.** Ten volunteers cannot read everything. That is the point: they do not have to.
  What they look at is the system's list of what it did, newest first, not a queue of what it
  could not do.
