# FSM human-bug sweep — findings (2026-05-31, Opus 4.8 investigators)

## Genuinely ACTIONABLE (still broken, FSM gave up wrongly)

| Topic/Post | Reporter | Bug | Root-cause hint | FSM mistake |
|---|---|---|---|---|
| 9706/3 | Saira | **CRITICAL: chat scanning broken — sexting/phone/photos NOT flagged, nothing in logs** | Edward acknowledged "recently broke chat scanning" (post 2). Regression from chat_process.php→Go migration. | Deferred despite admin acknowledging the bug publicly. |
| 9518/294 | Matty | Group settings 500: `Cannot set properties of null (setting 'photorotate')` when changing most settings | Null config object not initialised before set in MT group settings save path | Marked "Dismissed by human" but post got NO response — never fixed. |
| 9655/4 | Neville | `$recentwanted` / GetUserMessageHistory now OMITS pending messages (PR #446 over-filtered) | PR #446 added `mg.collection = COLLECTION_APPROVED` — excludes Pending; should include pending, exclude only rejected/held | Marked fixed off post 4 ("fix worked"); ignored post 5 contradiction 4 days later. |
| 9672/2 | Michael | Log history: filtering by community shows duplicates + omits latest; unfiltered is fine | Missing DISTINCT/GROUP BY on community-filtered join + pagination offset over filtered set | Escalated after 1 rejected PR, no re-diagnosis. |
| 9719/1 | Neville | MT chat shows group name ("Newham Freegle Volunteers") instead of member name after editing Wanted's group then sending modmail | Chat-name init reads group context instead of recipient when message group edited | Escalated after 1 rejected PR. |
| 9738/1 | Jos | Unsubscribe button in Support doesn't remove member; no log entries; member stays | Button either doesn't call removal API, API fails silently, or UI doesn't refresh — trace all 4 steps | Escalated after 1 rejected PR. |
| 9631 (Member Review) | Susan/Derek/Matty | **Multiple distinct bugs lumped as "PR #306/#351 regression":** (a) counter stuck at 1; (b) related-member details flash then vanish; (c) single-community filter hides related members; (d) "ghost" card: count>0 but empty list | (b) store not cleared in setup() before first render; (c) client filter checks userStore but pairs live in memberStore — drop client filter, trust API groupid; (d) inverse counter bug, still live | FSM treated symptom-changes across posts as the same bug repeatedly failing one PR. |
| 9653/5 | Neville | Logging out of Freegle also logs out of ModTools (shared session) — intended? | Shared session/cookie scope between apps | Deferred no reason — needs product decision or fix. |
| 9656/15,27,36 + 9737/1,5 | Carol/Sylvain/Susan/Bunny | Chat-review filter over-sensitive (phones, "grass", "borrow"); + alerts-before-messages; + groups lost after re-login | Filter threshold/keyword tuning + notification fires before message fetch + session group-state not restored on login | Deferred / escalated after 1 PR; should consolidate 9656+9737. |

## Correctly handled (leave as-is)
- 9518/283 (approved-stays-pending) — confirmed fixed post 296.
- 9518/293 (profile pic upload) — confirmed fixed post 301.
- Most 9023/9572/9613/9620/9636/9666/9683/9687 "Dismissed by human" = genuine questions/suggestions, not bugs (not re-checked individually; spot-check if doubtful).

## DATA-INTEGRITY BUG in FSM
- **9631/22 does not exist** — topic has only 21 posts. FSM created a phantom verdict record. Must validate post exists before recording.

## FSM rule fixes needed (the core ask)
1. **Don't mark `fixed` while a reporter says still-broken.** A later contradiction (9655/4→5) must flip to regression, not stay fixed.
2. **Require fix EVIDENCE before `fixed`/`off-topic`.** No-PR-and-no-reporter-confirmation + >5 days silence → `unresolved`, not dismissed (9518/294, /219).
3. **Don't link a PR to a post by timeline proximity.** Verify the PR diff/desc touches the reported symptom (9518/234,293 mis-linked to PR #450).
4. **Symptom-change = new bug.** When a reporter's described symptom changes across posts (counter→flash→ghost), open a new bug context, don't re-blame the prior PR (9631).
5. **Collapse me-too confirmations** under the primary report (9631 posts 19,20 vs 18).
6. **Admin/staff acknowledgement blocks deferral** — if Edward says "I broke X / fix on the way", track as investigating, never defer (9706/3).
7. **Validate post exists** before recording a verdict (9631/22 phantom).
8. **One rejected PR ≠ escalate-and-stop.** After a rejection, re-diagnose (root-cause pass) before handing to human (9672, 9719, 9737, 9738 all escalated after a single rejected attempt).
9. **Consolidate duplicate-topic bugs** before fixing (9656+9737 are the same chat-filter family).
