---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/ChatNotificationService.php
  - iznik-batch/database/migrations/2026_11_12_000001_create_chat_late_mails_table.php
  - iznik-batch/tests/Unit/Services/ChatNotificationServiceTest.php
---

# Chat notification emails

`ChatNotificationService` (run by `mail:chat:user2user`, `mail:chat:user2mod` and `mail:chat:mod2mod`)
mails a member, or a moderator, about chat messages they have not seen. This page is about which
messages it picks and how it makes sure each one is mailed once.

## One mark per recipient

Each recipient has one high-water mark, `chat_roster.lastmsgemailed`: the id of the newest message
mailed to them in that chat. A message is mailed in the ordinary way only if its id is above the
mark. The mark is moved **before** the mail is built, with a conditional UPDATE, so two runs
cannot both send it and a failed write sends nothing.

The mark is also moved, on purpose and with nothing mailed, by `ChatProcessService` for a sender,
by a moderator's direct mail, and by an incoming email reply. `RewindChatEmailedCommand` moves it
back so undelivered mail is sent again. Mail to a recipient whose provider is refusing it
(`MailSuppressionService`) and mail held by a lockdown leave the mark alone and are looked at
again on the next pass.

## Which messages are looked at

Messages dated in the last 24 hours, and also any message that a **release event** made
deliverable in the last 24 hours, however old it is: a held chat message approved by a moderator
(the `Chat` / `Approved` log row), a released rippling hold, a released lockdown hold, and a
reply question answered yes. Messages not yet deliverable (held for review, held by rippling,
still processing) are left out.

## A message that is released late

A held message has an id below the messages written while it was held. If one of those is mailed
first, the recipient's mark is already past the held message when it is released, and the
ordinary rule would never mail it. The member sees it on the site and gets no email.

For a message a release event admitted, a recipient whose mark is **above** the message is owed
a **late mail** when all of these hold:

- they are not the author of the message (the author's own mark is moved past their messages as
  they are sent, and a copy to self is ordinary mail only);
- the usual checks pass: chat mail on for them, not blocked or closed, not suppressed, no lockdown
  hold on email;
- no `ChatNotification` was ever built for them for that message. The record is the
  `email_tracking` row the mail writes, with the chat id and message id in its metadata, so it
  covers mail sent before this rule existed and a message mailed in the ordinary way after its
  release and then passed by a later one. With mail tracking switched off
  (`FREEGLE_MAIL_TRACKING_ENABLED`) there is no record, so no late mail is sent.

A mark equal to the message id is not late: that is what the ordinary claim leaves.

The late mail is claimed by inserting `(chatmsgid, userid)` into `chat_late_mails`, which has a
unique key. Only the run whose insert succeeds builds the mail, so two runs or two processes
never both send it, and a crash after the claim loses that one mail rather than sending it twice.
The recipient's mark is **not** moved back, which would replay every later message. The claim row
is also what stops the next pass sending it again. While a recipient is still owed a late mail the message's
`mailedtoall` flag is left at 0, so the message is selected again and a recipient who was held back for a
moment still gets it.

A late mail goes to whoever is owed it among: the other member of a member chat, the member and
the moderators of a member-to-volunteers chat, and every volunteer in a volunteer chat except the
author. Each is decided on their own mark, so a chat can have some recipients mailed in the
ordinary way and some late for the same message.

## What a late mail does not do

- It does not look at old chats. Only a message a release event admitted in the last 24 hours is a
  candidate, through the same query as before.
- It does not replay a message the mark skipped on purpose with no release event for it.
- It does not add a query to the main selection. For a candidate it adds one indexed lookup of
  `email_tracking` by recipient, and none once the answer is known in the running process.

## Tests

`ChatNotificationServiceTest` covers a reply approved after a later one was mailed, a second run, a
second process, several recipients in member-to-volunteers and volunteer chats, the author, a
suppressed recipient, a rewound mark, an approval older than the look-back, and mail tracking off.
