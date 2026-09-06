# Chat signals: consolidated candidates for what to build

*6 September 2026. One document merging every recommendation from the chat-signals analysis (2–5 September) into a single ranked list, with the evidence for each and the ones that were withdrawn or superseded. Whole-corpus figures are from 158,349 Offer conversations on 76,663 posts, March–July 2026, on a production database restore; LLM-annotated figures are from a 26,486-room stratified sample read by a local model on the analysis machine. Aggregate numbers only; no member text or identifiers.*

*Supersedes and replaces: the earlier "what I'd actually change" fixes list, the friction intervention table, the "what this supports doing next" section of the results write-up, the missed-opportunities review's product recommendations, and the first version of this document. Where a recommendation changed, the change and the reason are stated rather than silently applied.*

---

## 0. What changed between the first pass and this one

The first pass filtered to conversations where the offerer had already replied and looked for turn-level friction within them. It found real things (a concrete time is the strongest single signal; givers chase for a specific day in 18% of replied rooms) but concluded that nothing needed an AI in the conversation and that a transport prompt was unsupported.

Both conclusions were artefacts of the filter. **35.5% of Offer repliers never get a word from the offerer** — about 11,200 people a month — and that is where the outcome variance lives:

| replies on the post | posts | offerer replied to each replier | collected by that replier |
|---|---|---|---|
| 1 | 35,905 | **84.8%** | 38.7% |
| 2–3 | 26,913 | 74.9% | 25.1% |
| 4–6 | 9,310 | 59.2% | 14.4% |
| 7–10 | 2,952 | 45.6% | 8.6% |
| 11–20 | 1,255 | 34.4% | 5.0% |
| 21–40 | 260 | 23.4% | 2.6% |
| 41+ | 26 | **11.3%** | 0.9% |

The post is still taken ~92% of the time regardless of load. The offerer copes by triage, not conversation. On 11+-reply posts, 70% of repliers hear nothing; 857 such posts had the offerer answer two people or fewer, ghosting 9,992 repliers between them. This is the Greenwich clearance (88 repliers, 40 answered) and the Samsung TV (87 repliers, 2 answered) from the Freegle Helper trial retrospective, measured at scale — and the Helper's own conclusion, that its main value is *stopping good candidates falling through the cracks* rather than clever allocation, holds for ordinary busy posts too.

The missed-opportunities review had in fact flagged both halves of this (its §3.8 "giver-side too-many-repliers friction on non-bulk posts" and §4.8 "expectation-setting for repliers on high-competition posts") as unexplored. They are now explored, and they are the top of the list.

---

## 1. Ranked candidates

Scored on plausibility (targets a real mechanism), evidence (how firm), reach (rooms or posts per month). "Product" means a form or flow change with no model in the loop; "assistant" means something that reads or writes in a conversation.

### 1. Triage assistant for overloaded offerers — *assistant · strong evidence · ~2,900 posts/month*

When a post passes a reply threshold (the data says 4+), give the offerer a summary of who has replied — stated time, transport where the item is heavy, distance, charity/organisation, track record — and a one-click way to send a holding message to everyone they are not going to pick. The Helper already does this for bulk offers behind an opt-in; this is the case for the ordinary busy post.

*Evidence:* the reply-rate collapse above, monotonic and steep. 13,400 posts in five months had 4+ replies (~72,000 rooms). *Untested:* whether a summary changes who gets picked or how many get a reply. A trial should measure reply rate and time-to-first-reply on busy posts.

### 2. Get the replier an answer within the hour — *assistant · strong evidence · every conversation*

Offerer first-reply latency predicts collection **within every load stratum**, which rules out the confound that busy posts simply get faster replies:

| replies on post | offerer replied <1h | 1–4h | 12–24h | 1–3 days |
|---|---|---|---|---|
| 1 | **51.6%** | 48.7% | 43.1% | 38.6% |
| 2–3 | **43.5%** | 36.4% | 29.4% | 24.7% |
| 4–6 | **33.6%** | 26.6% | 20.5% | 14.8% |
| 7–10 | **29.4%** | 21.1% | 14.7% | 12.6% |
| 11+ | **21.4%** | 13.7% | 11.4% | 7.3% |

A reply inside an hour is worth roughly double a reply after a day at every level of competition. Median offerer latency on quiet posts is 16 hours; 37% take over a day. This is the Helper's rule 1 ("answer factual questions immediately") generalised. Observational — fast repliers are probably organised in other ways too — but large, consistent, and cheap to test with an acknowledgement or a nudge to the offerer.

### 3. Answer the taker's factual question from the listing — *assistant · strong evidence · ~4,100 rooms/month*

44% of openers contain a question; 29.7% of those are never answered — 13.1% of all Offer conversations. Collected: **38.4% when answered, 6.6% when not**, against 15.5% with no question. Partly survivorship, but the unanswered branch is poor enough that recovering a fraction is worthwhile. Where the question is factual (dimensions, condition, "will it fit in a car", "how many"), an assistant can answer from the post or tell the offerer a question is waiting.

*Detector already measured:* on 500 real last-messages, `qwen2.5:14b` finds a question at precision **0.972** (recall 0.733) versus 0.815 for a question-mark regex at the same recall — about one spurious flag in 35 against one in five — at 527 messages/minute, seeing only the single last message. ~8 GPU-minutes a month at Freegle's volume. Precision was scored against a larger model, not people; check a sample by eye before switching anything on.

### 4. Nudge whichever side has left a question unanswered — *product (existing reminder rail) · moderate evidence · ~5,600 rooms/month*

Both directions. **Taker's question unanswered** (above, ~4,100/month): nudge the offerer. **Offerer's question unanswered** (~1,300 time + ~300 transport/contact/location per month): nudge the taker — after a giver's question the taker goes silent 14–24% of the time and those rooms complete at 5–10%. Median reply time when people *do* answer is about an hour, so the dropped thread is the problem, not the delay. Extend the existing chase-up machinery; no new rail; cap at one per conversation; respect the chat opt-out.

Separately, **17.7% of ghosted takers chase into silence** — ~2,000 people a month sending follow-ups nobody reads. An assistant that knows the post has been promised elsewhere can say so. Within replied conversations chasers collect at 47% vs 22%, but that is engagement, not a lever: do not reward chasing.

### 5. Structured collection-time slots at reply time — *product · strongest evidence in the study · ~3,700 avoidable round-trips/month*

The most robust finding: a concrete time in the opener is associated with collection OR **1.67** (1.80 on heavy items), measured pre-treatment and distance-controlled. Yet 83% of openers already contain time wording and givers still ask "what day/time?" in 18% of replied rooms, two-thirds of the time as their first reply. The free-text box produces vagueness, not absence. Replace or precede it with day chips and morning/afternoon/evening, multi-select, plus "I'm flexible"; append as text so nothing downstream changes. Measure with the existing giver-asks-for-time detector; guardrail on reply rate. Expect a smaller effect than the correlation — the form cannot manufacture organised people.

### 6. Transport prompt at reply time, heavy items only — *product · evidence now conditional-positive · ~3,600 heavy rooms/month*

**Reversed from the first pass.** The earlier analysis tested transport marginally, found opener-transport OR 0.79 on heavy items, and wrote it off. The Helper's rule is conditional — ask only for large/heavy/bulky, assume fine otherwise — and cut by weight the corpus agrees:

| weight | offerer asks about transport | asked → taker goes silent | collected: never raised / offerer asked |
|---|---|---|---|
| under 10 kg | 1.4% | 22.9% | 32% / 37% |
| 10–25 kg | 2.0% | 15.6% | 29% / 33% |
| 25–50 kg | 3.6% | 16.2% | 26% / 28% |
| **50 kg+** | **4.2%** | 15.6% | 25% / 28% |

The decisive number: **when the offerer's first reply is a transport question on a ≥25 kg item, the taker goes silent 17.4% of the time — against 3.4% for a time question.** A transport question is five times as likely to end the conversation. So ask it at reply time, before the offerer has invested, so a would-be-silent taker self-selects out at no cost — and *not* mid-conversation. ≥25 kg only; on light items it is noise. If trialled, design it to measure whether it changes who replies, not just completion given a reply.

### 7. Surface the structural repliers the offerer would want — *assistant · weak-to-moderate evidence · ~1,400 rooms/month*

Regex over 156,107 openers for the patterns the Helper retrospective named:

| opener pattern | prevalence | offerer replied | collected (baseline 21.4%) |
|---|---|---|---|
| household pair ("my husband has also…") | 0.9% | 70.8% | **28.2% (×1.32)** |
| connector / on behalf of a charity | 0.4% | 72.4% | 25.9% (×1.21) |
| self-declared backup ("only if no charity wants it") | 1.4% | 67.6% | 25.8% (×1.20) |
| offers to take multiple / all / has a van | 0.6% | 67.4% | 22.4% (×1.05) |
| demanding tone | 0.5% | 57.7% | 16.4% (×0.77) |

Small populations, conservative regexes, but three are worth an assistant noticing. The bulk collector on a 7+-reply post is answered 46% of the time versus 38% for everyone else and collects at 10.5% versus 6.2% — better, but still a 54% chance the person with the van hears nothing. Connectors are the likeliest match for a "charities preferred" criterion and the likeliest to be lost in a pile. Household pairs collect at the highest rate of any pattern and are the case the retrospective said to consolidate.

### 8. Renege → auto-offer to the runner-up — *assistant · design exists, unevaluated · ~1,000 rooms/month*

Generalise the Helper's bulk reallocation FSM ("revoke the allocation and offer to the next candidate") to ordinary single-item Promise→Renege. ~1,068 reneged rooms a month; ~94% get no proactive handling today. Reneges are terminal — only 5.7% re-promise — so the runner-up is otherwise lost. Carried from the missed-opportunities review; no new evidence gathered here, but it slots directly under candidate 1's triage data.

### 9. Silent extraction of the states that have no button — *background job · high agreement · every conversation*

Address shared in text (34% of rooms vs 6% clicking the button), contact exchange, gone-elsewhere/declined/withdrew, agreed time (22% vs 3.7% trysts). No member sees anything, so a wrong guess costs a wrong row. Today the "stalled" bucket mixes real failures with unrecorded successes; these separate them. Write to a dedicated table with model and prompt version — **never** into `messages_by`/`messages_outcomes`, which two real external reports join as ground truth. Partial collection: the schema has an outcome no button can write; treat as a candidate measure until a second model has checked it.

*Do not yet* turn detected agreed-times into a "shall I set a reminder?" prompt: text-vs-click precision on that field has a measured lower bound of 0.11 and the larger-model overlap check has not run.

### 10. Add the instrumentation now — *migration · time-critical · every conversation*

No per-conversation timing metric exists — no first-reply-at, promised-at, collected-at, no experiment-bucket column. Every analysis above reconstructed it from message timestamps. At 35–42k rooms/month, every week of delay is unrecoverable trial data. One migration on `chat_rooms`, following the reengage-table experiment-column pattern. Nothing else in this list reads out cleanly without it.

### 11. Ask for a size/transport category at posting time — *product · removes a selection bias · every post*

Only 40–44% of posts resolve a weight via word-matching against the FRN table, and unresolved posts convert *better* (23.3% vs 18.4%) — a red flag the phase-0 checks reported but did not act on. A posting-time dropdown gives every post a bulk category and makes candidate 6's ≥25 kg gate real rather than inferred.

---

## 2. Withdrawn, superseded, or explicitly not recommended

**Discourage phone numbers and emails in the first message — withdrawn.** Openers with contact details get 37% fewer replies, and the first pass recommended a hint against them. Checked against rooms where we know who took the item: silent rooms *with* contact details collected at **8.1% vs 1.3%** without; overall 24.2% vs 21.3%. The silence is the arrangement moving to the phone and succeeding. Do not discourage this. The real lesson is that ~3,000 conversations in five months complete off-platform and look like total failures in every dashboard — any chat-activity success metric, including the reply-rate guardrail on candidate 5, is biased against them. If anything, detect "contact shared, then silence" and stop counting it as ghosting.

**"AI writing messages inside member conversations: nothing above needs it" — superseded.** True of the first-pass list, which was scoped to replied rooms. Candidates 1–4 and 7–8 are assistant interventions, and the reply-rate stratum is where they earn their keep. The organisation's caution on AI-on-member-content is real (one such feature was walked back in August) and the comms plan the missed-opportunities review asked for is still owed before any of them ships — but "nothing needs it" is no longer the evidence position.

**Transport prompt "not supported observationally" — reversed** into candidate 6, for the reason given there: the marginal test was the wrong test.

**Replacing the Taken button by reading chat — no.** Conversations end at the arrangement; people do not come back to say they collected. Text recall of Collected is 5–25% of the click record; reneges are largely disjoint from clicks. The buttons are not redundant.

**A "conversation souring" tone-triage flag for moderators — not without a capacity check.** Mods already run a review backlog; a query against the pending-review queue's own 48-hour/7-day constants should precede any new flag landing on it.

**Rewarding chasers — no.** Chasing marks engagement; encouraging it makes the busy-post problem worse.

**Treating the "sorry, it's gone" fan-out as a failure — no.** 96% of ghosted repliers receive it. The failure is the silence before it, not its absence.

---

## 3. Analyses still worth running (data already on hand, no product change)

Carried from the missed-opportunities review, none yet run:

1. **Split Ghosted by read receipt** — "opened the room, then silent" vs "never opened it", using `giver_read_last`/`taker_read_last` already on the room table. Changes the meaning of every ghosting figure above.
2. **Held-reply artefacts vs Ghosted/Stalled** — does the ripple hold (up to 47 h) inflate the causal story? A candidate instrument for reply latency that is exogenous to the replier, which would give candidate 2 a causal read without waiting for a trial.
3. **Seasonal drift** — the whole analysis is March–July; August (41,644 rooms, the single highest month) is in the same restore and unchecked.
4. **WANTED's 38% ghost rate** — over 3× Offer's, ~1,200 rooms/month of giver-side silence, no taxonomy row anywhere. Also: Wanted's Promise→Collected click path is used in 0.69% of rooms vs Offer's 18.6% — a 27× gap that may be a fixable UI bug under Wanted's reversed roles rather than a measurement artefact.
5. **Language-barrier cohort** — `reportreason LIKE '%Language%'` against ghosting and reneging, as an equity question.
6. **Density band as a confounder** — the routing engine's rural/urban band is a more direct confound for transport than the IMD quintile already used.

---

## 4. Governance and communications, owed before any assistant ships

- **No model-inferred label may be written into `messages_by` or `messages_outcomes`.** `AuthorityStatsService` and `ElectricalsStatsService` join those as ground truth for council reports and the public `/electricals` page. The electricals service's own docblock ("72%/65% accuracy … may not become a published number") is the precedent; it should apply here.
- **A member-facing explanation** of what reads chat text and why, before it does. The reengagement/ripple docs already have the template line ("this improves visibility; it should not be presented as closing that gap").
- **DPIA / legitimate-interest sign-off** for LLM reading of private chat text — the privacy policy names only human spam checks. Resolved for the analysis; not yet for a live assistant.
- **Delivery rail.** The in-chat question rail (`chat_prompts`) has never sent a message in production — zero rows all-time — and the Helper's send-as-offerer path likewise. Candidates 1–4 need a rail that exists; the existing reminder channel is the only one that does.

---

## 5. Two inconsistencies to resolve in the Helper's own documents

- `helper/prompt.md` says the API appends *"some of these messages may come from our automated assistant"* to the first auto-sent message so the conversation is never silently automated. `plans/active/freegle-helper-concierge.md` Open Question 1 says **"RESOLVED. Messages sent as the offerer (invisible)"** and the tone guidelines say the replier thinks they are talking to the offerer. These are opposite policies; the prompt looks newer and is the safer one, but the design doc still reads as the decision of record.
- The retrospective's trial patterns (connector, household pair, bulk collector, self-selecting backup, demanding tone) are all now sized on the corpus (candidate 7). The retrospective's own `related_to` knowledge-record field for household pairs was proposed and, as far as the schema shows, never added.

---

## 6. Reproducibility

Scripts `overload.py`, `structural.py`, `chase.py`, `interaction.py`, `confounds.py` (reply-rate stratum, structural openers, chasing, weight interaction, deconfounding) plus the earlier pipeline's `friction.py`, `opener.py`, `analyse.py`, `question_test.py`, all reading the `scratch.rooms` / `scratch.turns` / `scratch.friction` tables built from a production restore. Re-runnable against any such restore. Member chat text was read only by a local model on the analysis machine and never left it.
