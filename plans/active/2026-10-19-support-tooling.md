# Support tooling improvements

Status: placeholder. Nothing is implemented. Each item needs a decision before it is built.

## Problems raised by the support team

- Password and login questions are constant, and "reinstall the app" is now a stock answer.
- Finding a member's other email address when they have two accounts takes many emails.
- A thread of conversation with moderators through chat is hard to follow.
- Small disagreements inside the support team go unresolved.
- Questions arrive at random (gift aid, for example) with no single route to an answer.
- Bewildered, angry or confused members are hard to reply to well.

## Live measurements (read-only, 2026-10-19)

| Measure | Value |
|---|---|
| Support assistant runs since 2026-10-04 | 10 runs, 3 people, 3 errors, 0 ratings |
| Members active in the last 7 days who sign in only with Google or Facebook | about 2,600 of 20,000 |
| Members active in the last 14 days on a build older than 30 days | 724 of 30,000 |
| `users_builddates.appversion` filled in | 0 rows of 490,712 |
| Merge offers in the last year | 957 offered, 100 accepted, 18 rejected, 839 unanswered |
| `users_related` rows in the last 90 days | 1,672, of which 1,349 never notified |
| User2Mod chats active in the last 30 days | 3,383 chats, 2.4 messages each |
| Mod2Mod chats active in the last 30 days | 5 chats |
| Gift aid declarations awaiting review | 170 |

## Options

### 1. Logins

- Record the app version. The client sends `webversion` on `GET /session` but not `appversion`, so support cannot tell which app version a member runs.
- Put the sign-in method at the top of the member summary and the support snapshot, for example "signs in with Google, no password".
- Show a gentle "please update" prompt in the app for builds older than a few weeks. The forced-update switch (`app_min_webversion`) stays as it is.

### 2. Duplicate accounts

- Show a linked-accounts panel on the ModTools member page, built from `users_related`.
- Let support search by part of an email, by name with postcode sector, or by phone number.
- Make merging easier for the member: one link sent to both addresses, which merges once either is clicked. This needs a data-protection decision. TrashNothing accounts stay excluded from merging.

### 3. Threads with moderators

- A support case that holds the member, the support emails, the moderator chat and private notes.
- Until then, a convention: one chat per case, opened with the member number and a one-line summary.

### 4. Team decisions

- Ask the team to rate the support assistant's answers, which gives a low-conflict way to disagree.
- Keep a short page of settled answers. Each one becomes the assistant's default reply.

### 5. Random questions

- Sort each question into one of four outcomes: answered from docs, answered from the member's data, needs a named owner to decide, or a bug.
- Group the assistant's questions weekly so a new wave of problems shows up within days.

### 6. Difficult conversations

- Have the assistant draft replies in a fixed shape: acknowledge, one action, plain words, no history.
- For angry messages, draft a calm opening and suggest when to hand over or close.
- Let support see a screenshot of what the member sees.

## Suggested order

1. Record the app version and show the sign-in method.
2. Add the linked-accounts panel.
3. Two weeks of the team using and rating the assistant, with a settled-answers page started at the team call.

Support cases and the one-click merge are larger decisions for the team call.

## Review against the AI support helper (2026-10-22)

The AI support helper (ModTools /support, `claude-agent-sdk/`) already covers much of this plan. Its problem is reliability and use, not missing data.

### What the helper already does

- It reads `users_logins` and `users_related` in its member dump, so it already knows the sign-in method and linked accounts. Its playbook in `prompt.js` covers logins, duplicate chats, merge prompts and Apple relay addresses.
- Its device panel shows browser, OS and app version, read from Loki, with no AI call. The database still never stores the app version.
- It writes a suggested reply and can refer a case to geeks with an `SR-` reference.
- Rating is built: thumbs up or down, a comment, and a review list under SysAdmin → AI Helper.
- The `prompt.js` playbook is already a settled-answers list, but only developers can edit it.

### Live use, read-only, 4 to 20 Oct 2026

- 13 questions from 3 Support volunteers. Use is falling: one volunteer last week.
- 5 of the 13 failed at once with "You have hit your limit" (the subscription quota). All five came before the helper moved to the five-account broker, at about 19:14 UTC on 20 Oct (PR #1849); the last was at 16:40 that day. No question has been asked since, so the broker is not yet proven in use.
- 1 of 13 answers was rated. Answers took 1.5 to 11 minutes.
- Questions: email not arriving 4, posts not showing or erroring 4, reactivation reminders 2, login 1, event submit 1, who sent an email 1, rules 1.

### Revised order

1. Prove the quota failures have stopped since the broker, and show "limit reached, resets at ..." instead of a bare error.
2. A plain member-page panel with sign-in method and linked accounts, like the device panel, with no AI call.
3. Store the app version in the database.
4. Partial-email and phone search in `identify_user`.
5. Prompt work: the reply shape, a calm opening for angry messages, and hand-over advice. Let the team propose playbook entries without a code change.
6. Then two weeks of use and rating, with a "rate this" nudge and a weekly look at the review list.

### Dropped

- "Rate the assistant" as new work, because it exists.
- New tools that give the helper sign-in or linked-account data, because it has them.

### Still for the team call

- One-link merge, which needs a data-protection decision.
- Support cases, a "please update" prompt in the app, and screenshots of what the member sees.

### Missing from the plan

- Email delivery is the biggest question type and has no item.
- There is no speed target; answers take minutes.
- Only 3 volunteers use the helper, so adoption and training are open.
