---
last_reviewed: 2026-11-15
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/ChatScamWaveService.php
  - iznik-batch/app/Console/Commands/Chat/ScamWaveDetectCommand.php
---

# Chat scam wave: the detector and its alert

A scam wave in chat is many accounts sending the same text to many members in a short
time. `chat:scam-wave-detect` looks for that every 15 minutes and mails geeks@ when it
finds it. It only reads. Deciding that a cluster is a scam, and acting on it, is yours.

## What the mail says

The subject is `[Freegle] possible chat scam wave`. Each cluster looks like this:

```
Cluster 1: 12 messages from 12 senders (9 with accounts under 48 hours old) in 12 chats
What they said:
  [113200001] "the text of the first message, on one line"
  [113200002] "the text of the second"
  [113200003] "the text of the third"
```

The number in brackets is the chat message id. Up to three messages are shown per cluster
and each is cut at 400 characters. A list of sites named by five or more messages follows
when there are any, because a wave that varies its wording usually still names one site.

## Reading it

- **Read the text first.** Members say the same ordinary things at the same time. If the
  text is something a real member would write, it is a coincidence.
- **New accounts are a strong sign, old accounts are not a clearance.** The September 2026
  wave used accounts created well beforehand.
- **One chat per message** means the text went to many different members, which is the
  shape of a wave. Many messages in few chats is a conversation.

## What counts as a cluster

Eight or more messages from five or more senders in the last 30 minutes whose first 60
characters match once spacing, capitals and styled letters are folded away. Only delivered
member messages count: rejected ones, and system and moderator messages, are left out.

Text under 30 characters ("ok", "yes please", "thank you") is ignored unless new accounts
sent at least half of it. Without that rule the detector mailed every time eight members
happened to reply "ok" in the same half hour.

The thresholds are constants at the top of `ChatScamWaveService`.

## Running it by hand

On the batch host, inside the batch container:

```bash
php artisan chat:scam-wave-detect --no-mail              # print the report, send nothing
php artisan chat:scam-wave-detect --window=120 --no-mail # look further back
```

The scheduled runs log to `storage/logs/cron/chat_scam-wave-detect.log`.

## Responding to a real wave

- If it is going out faster than it can be removed, use the lockdown switch:
  **[lockdown.md](lockdown.md)**.
- To stop the text itself, add a Freegle-wide block keyword for it. Chat that matches a
  block keyword is dropped, and adding the keyword also rejects matching messages
  delivered in the last 24 hours. `content:reject-blocked-keyword` does the same by hand
  for a longer period.
- The scripts used in September 2026 to reject delivered messages and warn the members who
  received them are on the batch host in `storage/scam-wave/`, with a README. They are not
  in this repository and were written for that wave, so read one before running it and
  use its dry run first.
