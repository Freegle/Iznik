# Chat signals: candidates for what to build

*Evidence base: 158,349 Offer conversations on 76,663 posts, March–July 2026, from a production database restore; plus a 26,486-room stratified sample annotated by a local model on the analysis machine. Aggregate figures only; no member text or identifiers. September 2026.*

---

## 1. The shape of the problem

**35.5% of repliers to an Offer never get a word from the offerer** — about 11,200 people a month. The rate is a function of load:

| replies on the post | posts | offerer replied to each replier | collected by that replier |
|---|---|---|---|
| 1 | 35,905 | **84.8%** | 38.7% |
| 2–3 | 26,913 | 74.9% | 25.1% |
| 4–6 | 9,310 | 59.2% | 14.4% |
| 7–10 | 2,952 | 45.6% | 8.6% |
| 11–20 | 1,255 | 34.4% | 5.0% |
| 21–40 | 260 | 23.4% | 2.6% |
| 41+ | 26 | **11.3%** | 0.9% |

The post is taken ~92% of the time regardless. Offerers cope by triage, not conversation. On 11+-reply posts, 70% of repliers hear nothing; 857 such posts had the offerer answer two people or fewer, ghosting 9,992 repliers between them. Almost all of them eventually receive the automatic "sorry, it's gone" message (96.3%) — after the item has gone. The one structure that currently decides who gets answered on a busy post is reply rank: first replier answered 60%, eleventh-or-later 21%.

The Freegle Helper trial reached the same conclusion for bulk offers — its main value is stopping good candidates falling through the cracks, not clever allocation. The corpus shows that holds for ordinary busy posts too.

Within conversations that do happen, the friction is: **vagueness** (83% of openers contain time wording, yet givers ask "what day?" in 18% of replied rooms), **unanswered questions** (13% of all rooms), and **the silent branch after a giver's question** (14–24% of takers never come back; those rooms complete at 5–10%).

---

## 2. Ranked candidates

Scored on plausibility (targets a real mechanism), evidence (how firm), and reach. *Product* = a form or flow change with no model in the loop. *Assistant* = something that reads or writes in a conversation.

### 1. Triage assistant for overloaded offerers — *assistant · strong evidence · ~2,900 posts/month*

When a post passes 4 replies, give the offerer a summary of who has replied — stated time, transport where the item is heavy, distance, charity/organisation, track record — and a one-click holding message for everyone they will not pick. The Helper does this for bulk offers behind an opt-in; this is the ordinary busy post.

*Evidence:* the reply-rate table above. 13,400 posts in five months had 4+ replies (~72,000 rooms). *Untested:* whether a summary changes who gets picked or how many get a reply. Trial metrics: reply rate and time-to-first-reply on busy posts.

### 2. Get the replier an answer within the hour — *assistant · strong evidence · every conversation*

Offerer first-reply latency predicts collection **within every load stratum**, so it is not an artefact of busy posts getting faster replies:

| replies on post | offerer replied <1h | 1–4h | 12–24h | 1–3 days |
|---|---|---|---|---|
| 1 | **51.6%** | 48.7% | 43.1% | 38.6% |
| 2–3 | **43.5%** | 36.4% | 29.4% | 24.7% |
| 4–6 | **33.6%** | 26.6% | 20.5% | 14.8% |
| 7–10 | **29.4%** | 21.1% | 14.7% | 12.6% |
| 11+ | **21.4%** | 13.7% | 11.4% | 7.3% |

A reply inside an hour is worth roughly double a reply after a day at every level of competition. Median offerer latency on quiet posts is 16 hours; 37% take over a day. Observational — fast repliers are likely organised in other ways — but large, consistent, and cheap to test with an acknowledgement or a nudge to the offerer. This is the Helper's rule 1, generalised.

### 3. Answer the taker's factual question from the listing — *assistant · strong evidence · ~4,100 rooms/month*

44% of openers contain a question; 29.7% are never answered — 13.1% of all Offer conversations. Collected: **38.4% when answered, 6.6% when not**, against 15.5% with no question. Partly survivorship, but the unanswered branch is poor enough that recovering a fraction is worthwhile. Where the question is factual (dimensions, condition, "will it fit in a car", "how many"), answer it from the post or tell the offerer a question is waiting.

*Detector:* on 500 real last-messages, `qwen2.5:14b` finds a question at precision **0.972** (recall 0.733) versus 0.815 for a question-mark regex at the same recall — one spurious flag in 35 against one in five — at 527 messages/minute, reading only the single last message. ~8 GPU-minutes a month. Precision was scored against a larger model, not people; check a sample by eye before switching on.

### 4. Nudge whichever side has left a question unanswered — *product (existing reminder rail) · moderate evidence · ~5,600 rooms/month*

Both directions. Taker's question unanswered (~4,100/month): nudge the offerer. Offerer's question unanswered (~1,300 time + ~300 transport/contact/location): nudge the taker. Median reply time when people *do* answer is about an hour, so the dropped thread is the problem, not the delay. Extend the existing chase-up machinery; no new rail; one per conversation; respect the chat opt-out.

Also: **17.7% of ghosted takers chase into silence** — ~2,000 people a month sending follow-ups nobody reads. An assistant that knows the post is promised elsewhere can say so. Chasers who *do* get a reply collect at 47% vs 22%, but that is engagement, not a lever — do not reward chasing.

### 5. Structured collection-time slots at reply time — *product · strongest evidence in the study · ~3,700 avoidable round-trips/month*

A concrete time in the opener is associated with collection OR **1.67** (1.80 on heavy items), measured before the giver has replied and with distance controlled — the most robust finding in the data. Yet 83% of openers contain time wording and givers still ask "what day/time?" in 18% of replied rooms, two-thirds of the time as their first reply. Free text produces vagueness, not absence.

Replace or precede the free-text collection question with day chips and morning/afternoon/evening, multi-select, plus "I'm flexible"; append as text so nothing downstream changes. Measure with the existing giver-asks-for-time detector; guardrail on reply rate. Expect less than the correlation — the form cannot manufacture organised people.

### 6. Transport prompt at reply time, heavy items only — *product · conditional evidence · ~3,600 heavy rooms/month*

Cut by item weight:

| weight | offerer asks about transport | asked → taker goes silent | collected: never raised / offerer asked |
|---|---|---|---|
| under 10 kg | 1.4% | 22.9% | 32% / 37% |
| 10–25 kg | 2.0% | 15.6% | 29% / 33% |
| 25–50 kg | 3.6% | 16.2% | 26% / 28% |
| **50 kg+** | **4.2%** | 15.6% | 25% / 28% |

**When the offerer's first reply is a transport question on a ≥25 kg item, the taker goes silent 17.4% of the time — against 3.4% for a time question.** A transport question is five times as likely to end the conversation. Ask it at reply time, before the offerer has invested, so a would-be-silent taker self-selects out at no cost — and not mid-conversation. ≥25 kg only; on light items it is noise (volunteered transport shows no benefit there, and mentioning it in the opener is associated with slightly *worse* outcomes overall). If trialled, measure whether it changes who replies, not just completion given a reply.

### 7. Surface the structural repliers the offerer would want — *assistant · weak-to-moderate evidence · ~1,400 rooms/month*

Regex over 156,107 openers:

| opener pattern | prevalence | offerer replied | collected (baseline 21.4%) |
|---|---|---|---|
| household pair ("my husband has also…") | 0.9% | 70.8% | **28.2% (×1.32)** |
| connector / on behalf of a charity | 0.4% | 72.4% | 25.9% (×1.21) |
| self-declared backup ("only if no charity wants it") | 1.4% | 67.6% | 25.8% (×1.20) |
| offers to take multiple / all / has a van | 0.6% | 67.4% | 22.4% (×1.05) |
| demanding tone | 0.5% | 57.7% | 16.4% (×0.77) |

Small populations, conservative regexes. The bulk collector on a 7+-reply post is answered 46% of the time versus 38% for everyone else — better, but still a 54% chance the person with the van hears nothing. Connectors are the likeliest match for a "charities preferred" criterion and the likeliest to be lost in a pile. Household pairs collect at the highest rate of any pattern and are the case to consolidate ("can one of you collect all three?"). Demanding tone does not help the sender; quick replies prevent it.

### 8. Renege → auto-offer to the runner-up — *assistant · design exists, unevaluated · ~1,000 rooms/month*

Generalise the Helper's bulk reallocation ("revoke the allocation and offer to the next candidate") to ordinary single-item Promise→Renege. ~1,068 reneged rooms a month; ~94% get no proactive handling. Reneges are terminal — only 5.7% re-promise — so the runner-up is otherwise lost. Slots directly under candidate 1's triage data.

### 9. Silent extraction of the states that have no button — *background job · high agreement · every conversation*

Address shared in text (34% of rooms vs 6% clicking the button), contact exchange, gone-elsewhere/declined/withdrew, agreed time (22% vs 3.7% trysts). Nothing is shown to a member, so a wrong guess costs a wrong row. Today the "stalled" bucket mixes real failures with unrecorded successes; these separate them. Write to a dedicated table with model and prompt version — never into `messages_by`/`messages_outcomes` (§4). Partial collection has a schema outcome no button can write; treat as a candidate measure until a second model has checked it.

Detected agreed-times are not yet fit for a "shall I set a reminder?" prompt: text-vs-click precision on that field has a measured lower bound of 0.11, pending the larger-model overlap check.

### 10. Instrumentation — *migration · time-critical · every conversation*

There is no per-conversation timing metric: no first-reply-at, promised-at, collected-at, no experiment-bucket column. At 35–42k rooms/month, every week without them is unrecoverable trial data. One migration on `chat_rooms`, following the reengage-table experiment-column pattern. Nothing else here reads out cleanly without it.

### 11. Size/transport category at posting time — *product · removes a selection bias · every post*

Only 40–44% of posts resolve a weight via word-matching against the FRN table, and unresolved posts convert *better* (23.3% vs 18.4%). A posting-time dropdown gives every post a bulk category and makes candidate 6's ≥25 kg gate real rather than inferred.

---

## 3. Not recommended

**Discouraging phone numbers or emails in the first message.** Openers with contact details get 37% fewer chat replies — because the arrangement moves to the phone and succeeds. Silent rooms *with* contact details collected at **8.1% vs 1.3%** without; overall 24.2% vs 21.3%. ~3,000 conversations in five months complete off-platform and look like total failures in every dashboard, which also biases any chat-activity success metric (including candidate 5's guardrail) against them. The useful build is the inverse: detect "contact shared, then silence" and stop counting it as ghosting.

**A mid-conversation transport question on light items.** 1–2% of offerers ask; it does not move outcomes; it adds a turn.

**Replacing the Taken button by reading chat.** Conversations end at the arrangement; people do not come back to say they collected. Text recall of Collected is 5–25% of the click record; reneges are largely disjoint from clicks. The buttons are not redundant.

**A "conversation souring" flag for moderators, without a capacity check.** Mods already run a review backlog; query the pending-review queue against its own 48-hour/7-day constants before adding to it.

**Rewarding chasers.** Chasing marks engagement; encouraging it makes the busy-post problem worse.

**Treating the "sorry, it's gone" fan-out as the failure.** 96% of ghosted repliers receive it. The failure is the silence before it.

---

## 4. Governance owed before any assistant ships

- **No model-inferred label may be written into `messages_by` or `messages_outcomes`.** `AuthorityStatsService` and `ElectricalsStatsService` join those as ground truth for council reports and the public `/electricals` page. The electricals service's own docblock ("72%/65% accuracy … may not become a published number") is the precedent.
- **A member-facing explanation** of what reads chat text and why, before it does. The reengagement and ripple docs carry the template line ("this improves visibility; it should not be presented as closing that gap").
- **DPIA / legitimate-interest sign-off** for LLM reading of private chat text. The privacy policy names only human spam checks. Cleared for the analysis; not for a live assistant.
- **A delivery rail.** The in-chat question rail (`chat_prompts`) has never sent a message in production — zero rows all-time — and neither has the Helper's send-as-offerer path. Candidates 1–4 need a rail that exists; the reminder channel is the only one that does.

---

## 5. Analyses still worth running (data already on hand, no product change)

1. **Split Ghosted by read receipt** — "opened the room, then silent" vs "never opened it", from `giver_read_last`/`taker_read_last` already on the room table. Changes the meaning of every ghosting figure above.
2. **Held-reply artefacts vs Ghosted/Stalled** — the ripple hold (up to 47 h) is a candidate instrument for reply latency that is exogenous to the replier, and would give candidate 2 a causal read without a trial.
3. **Seasonal drift** — the window is March–July; August (41,644 rooms, the single highest month) is in the same restore and unchecked.
4. **WANTED's 38% ghost rate** — over 3× Offer's, ~1,200 rooms/month of giver-side silence, no taxonomy row. Separately, Wanted's Promise→Collected click is used in 0.69% of rooms vs Offer's 18.6% — a 27× gap that may be a UI bug under Wanted's reversed roles rather than a measurement artefact.
5. **Language-barrier cohort** — `reportreason LIKE '%Language%'` against ghosting and reneging, as an equity question.
6. **Density band as a confounder** — the routing engine's rural/urban band is a more direct confound for transport than the IMD quintile.

---

## 6. Inconsistencies in the Helper's own documents

- `helper/prompt.md` says the API appends *"some of these messages may come from our automated assistant"* to the first auto-sent message so the conversation is never silently automated. `plans/active/freegle-helper-concierge.md` Open Question 1 says **"RESOLVED. Messages sent as the offerer (invisible)"** and the tone guidelines say the replier thinks they are talking to the offerer. Opposite policies. The prompt is the safer one; the design doc still reads as the decision of record.
- The `related_to` knowledge-record field the retrospective proposed for household pairs does not appear in the schema.

---

## 7. Reproducibility

Scripts `overload.py`, `structural.py`, `chase.py`, `interaction.py`, `confounds.py`, `friction.py`, `opener.py`, `analyse.py`, `question_test.py`, reading the `scratch.rooms` / `scratch.turns` / `scratch.friction` tables built from a production restore. Re-runnable against any such restore. Member chat text was read only by a local model on the analysis machine and never left it.
