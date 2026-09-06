# Chat signals: candidates for what to build

*Evidence base: 158,349 Offer conversations on 76,663 posts, March–July 2026, from a production database restore; plus a 26,486-room stratified sample annotated by a local model. September 2026.*

*Confidentiality: private member chats were analysed without any of the text leaving Freegle's control. The production database was restored onto a rented GPU machine (an NVIDIA GB10 with 128 GB of unified memory), and every model that read chat text — the annotation model, the question detector, the adjudicator — ran locally on that machine under open-weight licences. No chat text was sent to any third-party API. Only aggregate counts and the analysis scripts left the machine, and the machine is wiped at the end of the rental. This document contains aggregate figures only; no member text or identifiers.*

A Freegle conversation fails at several distinct points, and each has its own evidence and its own fix. The candidates are grouped by the stage they act on.

Every intervention is a message or a control that **one side, the other, or both** can see, and that is part of the design, not an implementation detail. A conversation has two people in it; an intervention that helps one of them silently can look like ghosting or interference to the other. So each candidate below states **who sees it** — *offerer*, *replier*, or *both* — and what the other side experiences if anything. *Product* = a form or flow change with no model in the loop. *Assistant* = something that reads or writes in a conversation.

---

## A. Before the conversation: the first message is vague

The taker's opener is the one thing in the conversation that precedes any decision by the offerer, so its associations with outcome cannot be reverse-caused by the offerer's choice. A **concrete collection time** in the opener is associated with collection OR **1.67** (1.80 on heavy items) with distance controlled — the single most robust finding in the data. Yet 83% of openers contain time wording and offerers still ask "what day/time?" in 18% of replied rooms, two-thirds of the time as their first reply. The free-text box produces vagueness, not absence.

### A1. Structured collection-time slots at reply time — *product · strongest evidence · ~3,700 avoidable round-trips/month*

**Visible to:** the replier, as part of the reply form. The offerer sees a first message with concrete times in it instead of "sometime this week".

Replace or precede the free-text collection question with day chips and morning/afternoon/evening, multi-select, plus "I'm flexible"; append the result as text so nothing downstream changes. Measure with the existing giver-asks-for-time detector; guardrail on reply rate. Expect less than the correlation — a form cannot manufacture organised people.

### A2. Transport prompt at reply time, heavy items only — *product · conditional evidence · ~3,600 heavy rooms/month*

**Visible to:** the replier, as a question on the reply form for items in the heavy category. The offerer sees the answer in the first message and never has to ask.

Cut by item weight:

| weight | offerer asks about transport | asked → taker goes silent | collected: never raised / offerer asked |
|---|---|---|---|
| under 10 kg | 1.4% | 22.9% | 32% / 37% |
| 10–25 kg | 2.0% | 15.6% | 29% / 33% |
| 25–50 kg | 3.6% | 16.2% | 26% / 28% |
| **50 kg+** | **4.2%** | 15.6% | 25% / 28% |

**When the offerer's first reply is a transport question on a ≥25 kg item, the taker goes silent 17.4% of the time — against 3.4% for a time question.** A transport question is five times as likely to end the conversation. Asking it at reply time, before the offerer has invested, lets a would-be-silent taker self-select out at no cost. ≥25 kg only: on light items volunteered transport shows no benefit, and mentioning it in the opener is associated with slightly *worse* outcomes overall. If trialled, measure whether it changes who replies, not just completion given a reply.

### A3. A weight basis for the heavy-item gate — *dependency on the EEE work, not a new build*

A2 needs to know which items are ≥25 kg. Only 40–44% of posts resolve a weight by word-matching against the FRN table, and unresolved posts convert *better* (23.3% vs 18.4%), so the current basis is both incomplete and biased.

The electricals pipeline (`plans/active/2026-08-27-electricals-pipeline-and-page.md`) has already settled this question for its own purposes: it uses the `impact.go` cascade — exact `items.weight`, then fuzzy match against the `weights` reference table, then a popularity-weighted population mean — and explicitly rejects the per-item model estimate at 65% accuracy against human quorum. Size is published only as a coarse small/medium/large split (72%). A2's gate should use the same cascade, with the coarse size band as a tie-breaker, and inherit whatever improves it. No separate posting-time dropdown is proposed here.

---

## B. Getting a reply at all: the offerer is overloaded

**35.5% of repliers never get a word from the offerer** — about 11,200 people a month. The rate is a function of load:

| replies on the post | posts | offerer replied to each replier | collected by that replier |
|---|---|---|---|
| 1 | 35,905 | **84.8%** | 38.7% |
| 2–3 | 26,913 | 74.9% | 25.1% |
| 4–6 | 9,310 | 59.2% | 14.4% |
| 7–10 | 2,952 | 45.6% | 8.6% |
| 11–20 | 1,255 | 34.4% | 5.0% |
| 21–40 | 260 | 23.4% | 2.6% |
| 41+ | 26 | **11.3%** | 0.9% |

The post is taken ~92% of the time regardless; offerers cope by triage, not conversation. This is a decision, not an unread inbox: in 84% of silent rooms the offerer had read the message and chose not to answer (98% on busy posts — I.1). On 11+-reply posts 70% of repliers hear nothing; 857 such posts had the offerer answer two people or fewer, ghosting 9,992 repliers between them. The only structure currently deciding who gets answered is reply rank (first replier 60%, eleventh-or-later 21%). The Freegle Helper trial reached the same conclusion for bulk offers — its main value is stopping good candidates falling through the cracks — and the corpus shows that holds for ordinary busy posts.

### B1. Triage assistant for overloaded offerers — *assistant · strong evidence · ~2,900 posts/month*

**Visible to:** the offerer, as a summary panel on a post with 4+ replies. Repliers see nothing until the offerer acts; those the offerer does not pick receive the holding message (B3) rather than silence.

Summarise who has replied — stated time, transport where the item is heavy, distance, charity/organisation, track record — with a one-click holding message for everyone the offerer will not pick. The Helper does this for bulk offers behind an opt-in; this is the ordinary busy post. 13,400 posts in five months had 4+ replies (~72,000 rooms). Untested: whether a summary changes who gets picked or how many get a reply. Trial metrics: reply rate and time-to-first-reply on busy posts.

### B2. Surface the structural repliers the offerer would want — *assistant · weak-to-moderate evidence · ~1,400 rooms/month*

**Visible to:** the offerer only, as annotations in B1's summary. The replier is not told they have been tagged.

Regex over 156,107 openers:

| opener pattern | prevalence | offerer replied | collected (baseline 21.4%) |
|---|---|---|---|
| household pair ("my husband has also…") | 0.9% | 70.8% | **28.2% (×1.32)** |
| connector / on behalf of a charity | 0.4% | 72.4% | 25.9% (×1.21) |
| self-declared backup ("only if no charity wants it") | 1.4% | 67.6% | 25.8% (×1.20) |
| offers to take multiple / all / has a van | 0.6% | 67.4% | 22.4% (×1.05) |
| demanding tone | 0.5% | 57.7% | 16.4% (×0.77) |

Small populations, conservative regexes. The bulk collector on a 7+-reply post is answered 46% of the time versus 38% for everyone else — better, but still a 54% chance the person with the van hears nothing. Connectors are the likeliest match for a "charities preferred" criterion and the likeliest to be lost in a pile. Household pairs collect at the highest rate of any pattern and are the case to consolidate ("can one of you collect all three?").

### B3. A holding message instead of silence — *assistant · moderate evidence · ~11,000 rooms/month*

**Visible to:** the replier, as a message in the chat. The offerer sees that it was sent on their behalf.

Two forms. On a post where the offerer has replied to some people and not others, a message to the rest: thanks, the offerer has several replies and will be in touch if it works out. On a post that has been promised elsewhere, a message saying so. The second stops the chase: **17.7% of ghosted takers send a second, third, fourth message** — ~2,000 people a month sending follow-ups nobody reads. Chasers who *do* get a reply collect at 47% vs 22%, but that is engagement, not a lever — do not reward chasing. Demanding tone in the opener does not help the sender (×0.77); an early holding message prevents it.

---

## C. Speed: the reply comes too late

Offerer first-reply latency predicts collection **within every load stratum**, so it is not an artefact of busy posts getting faster replies:

| replies on post | offerer replied <1h | 1–4h | 12–24h | 1–3 days |
|---|---|---|---|---|
| 1 | **51.6%** | 48.7% | 43.1% | 38.6% |
| 2–3 | **43.5%** | 36.4% | 29.4% | 24.7% |
| 4–6 | **33.6%** | 26.6% | 20.5% | 14.8% |
| 7–10 | **29.4%** | 21.1% | 14.7% | 12.6% |
| 11+ | **21.4%** | 13.7% | 11.4% | 7.3% |

A reply inside an hour is worth roughly double a reply after a day at every level of competition. Median offerer latency on quiet posts is 16 hours; 37% take over a day. The taker still comes back 81% of the time after a 1–3 day wait, so most of the loss is momentum, not departure.

This is observational. The obvious rival explanation — that fast repliers are simply better organised, and it is the organisation that collects — is not ruled out by anything in the data. The ripple hold looked like a natural experiment on delay and is not one (I.2). The gradient is large and consistent enough to be worth a trial, and a trial is the only thing that will settle it.

### C1. Acknowledge the replier within the hour — *assistant · strong evidence · every conversation*

**Visible to:** both. The replier gets a chat message: thanks, your reply has been passed to the offerer. The offerer sees, in the same chat, that it was sent for them, and gets a nudge if they have not replied within a threshold.

This is an acknowledgement, not an answer — it does not pretend the offerer has read anything, and it does not commit them to anything. It comes from Freegle, in the open (§J). Where the replier's message contains a factual question the listing can answer, D1 supplies the answer in the same message. The evidence is observational — fast repliers are likely organised in other ways — but the gradient is large and consistent at every level of competition, and the intervention is cheap to test. This is the Helper's rule 1 ("answer factual questions immediately") generalised.

---

## D. Mid-conversation: a question goes unanswered

44% of openers contain a question; 29.7% are never answered — 13.1% of all Offer conversations. Collected: **38.4% when answered, 6.6% when not**, against 15.5% with no question. In the other direction, after an offerer's question the taker goes silent 14–24% of the time and those rooms complete at 5–10%. Median reply time when people *do* answer is about an hour, so the dropped thread is the problem, not the delay.

### D1. Answer the replier's factual question from the listing — *assistant · strong evidence · ~4,100 rooms/month*

**Visible to:** both. The answer goes to the replier as a chat message, marked as drawn from the listing; the offerer sees it in the chat and can correct it.

Where the question is factual (dimensions, condition, "will it fit in a car", "how many"), answer from the post; where it is not, tell the offerer a question is waiting. Detector already measured: on 500 real last-messages, `qwen2.5:14b` finds a question at precision **0.972** (recall 0.733) versus 0.815 for a question-mark regex at the same recall — one spurious flag in 35 against one in five — at 527 messages/minute, reading only the single last message. ~8 GPU-minutes a month. Precision was scored against a larger model, not people; check a sample by eye before switching on.

### D2. Nudge whichever side has left a question unanswered — *product (existing reminder rail) · moderate evidence · ~5,600 rooms/month*

**Visible to:** the side being nudged only. The other side sees nothing unless a reply results.

Replier's question unanswered (~4,100/month): nudge the offerer. Offerer's question unanswered (~1,300 time + ~300 transport/contact/location): nudge the replier. Extend the existing chase-up machinery; no new rail; one per conversation; respect the chat opt-out.

---

## E. After a promise: the arrangement falls through

Promise-to-collection timing is well defined: **median 21 hours; 57% within a day, 83% within three, 94% within seven.** Promise-to-renege runs slower: **median 36 hours; 70% within three days, 87% within seven** (I.7). After three days, most collections that will happen have happened and most reneges have been declared, so a promise still open at day 3 with no collection is a real signal, not noise. Only 17.6% of promises have an arranged date recorded, so the platform usually cannot use the agreed date and has to fall back on these distributions.

When a promise is reneged, the runner-up is worth something: on the 2,868 reneged posts that had another replier, **a sibling was later promised 54% of the time and collected 56.5% of the time**, with a mean of 2.6 other repliers to choose from. A further 1,217 reneged posts had no other replier at all. Reneges are terminal for the person who reneged — only 5.7% re-promise — and today ~94% of the ~1,068 reneged rooms a month get no proactive handling. The reneged post is still eventually taken 63.5% of the time, which means the offerer usually has to start again by hand.

### E1. Tell the offerer how long is reasonable to wait, and who is next — *assistant · strong timing evidence · ~1,000 rooms/month*

**Visible to:** the offerer, as a prompt in the promised chat once the promise has aged past the threshold. The promised replier sees nothing until the offerer acts.

Once a promise passes the 3-day mark with no collection recorded and no arranged date in the future, tell the offerer: most collections happen within three days; this one has not; here is the next-best replier from B1's triage (time stated, transport, distance), and a one-click way to ask the promised person whether they are still coming. If the offerer reneges, offer to message the runner-up. This is the Helper's bulk reallocation ("revoke the allocation and offer to the next candidate") generalised to the single-item case, with the offerer making the decision rather than the assistant. Where there is no other replier (1,217 posts), the prompt is simply the wait-time reminder and the option to repost.

---

## F. Measurement: the platform cannot see what happened

### F1. Silent extraction of the states that have no button — *background job · high agreement · every conversation*

**Visible to:** nobody. Rows in a dedicated table.

Address shared in text (34% of rooms vs 6% clicking the button), contact exchange, gone-elsewhere/declined/withdrew, agreed time (22% vs 3.7% trysts). Nothing is shown to a member, so a wrong guess costs a wrong row. Today the "stalled" bucket mixes real failures with unrecorded successes; these separate them. Write to a dedicated table with model and prompt version — never into `messages_by`/`messages_outcomes` (§H). Partial collection has a schema outcome no button can write; treat as a candidate measure until a second model has checked it. Detected agreed-times are not yet fit for a "shall I set a reminder?" prompt: text-vs-click precision on that field has a measured lower bound of 0.11, pending the larger-model overlap check.

### F2. Recognise off-platform completion — *background job · strong evidence · ~600 rooms/month*

**Visible to:** nobody directly; it changes what the dashboards and any trial guardrail count as a failure.

Openers with a phone number or email get 37% fewer chat replies — because the arrangement moves to the phone and succeeds. Silent rooms *with* contact details collected at **8.1% vs 1.3%** without; overall 24.2% vs 21.3%. ~3,000 conversations in five months complete off-platform and look like total failures in every dashboard, which biases any chat-activity metric (including A1's reply-rate guardrail) against them. Detect "contact shared, then silence" and stop counting it as ghosting.

### F3. Instrumentation — *migration · time-critical · every conversation*

**Visible to:** nobody. Columns.

There is no per-conversation timing metric: no first-reply-at, promised-at, collected-at, no experiment-bucket column. At 35–42k rooms/month, every week without them is unrecoverable trial data. One migration on `chat_rooms`, following the reengage-table experiment-column pattern. Nothing above reads out cleanly without it.

---

## G. Not recommended

**Discouraging phone numbers or emails in the first message.** See F2: the missing reply is a success the platform cannot see.

**A mid-conversation transport question on light items.** 1–2% of offerers ask; it does not move outcomes; it adds a turn. See A2 for where it does apply.

**Replacing the Taken button by reading chat.** Conversations end at the arrangement; people do not come back to say they collected. Text recall of Collected is 5–25% of the click record; reneges are largely disjoint from clicks. The buttons are not redundant.

**A "conversation souring" flag for moderators, without a capacity check.** Mods already run a review backlog; query the pending-review queue against its own 48-hour/7-day constants before adding to it.

**Rewarding chasers.** Chasing marks engagement; encouraging it makes the busy-post problem worse.

**Treating the "sorry, it's gone" fan-out as the failure.** 96.3% of ghosted repliers receive it. The failure is the silence before it.

**Any intervention visible to one side that the other side cannot see or would misread.** An acknowledgement the offerer does not know was sent reads, to them, as the replier being oddly patient; a runner-up contacted without the promised person knowing reads as a double-booking. Each candidate above states who sees it for that reason.

---

## H. Governance owed before any assistant ships

- **No model-inferred label may be written into `messages_by` or `messages_outcomes`.** `AuthorityStatsService` and `ElectricalsStatsService` join those as ground truth for council reports and the public `/electricals` page. The electricals service's own docblock ("72%/65% accuracy … may not become a published number") is the precedent.
- **A member-facing explanation** of what reads chat text and why, before it does. The reengagement and ripple docs carry the template line ("this improves visibility; it should not be presented as closing that gap").
- **DPIA / legitimate-interest sign-off** for LLM reading of private chat text. The privacy policy names only human spam checks. Cleared for the analysis; not for a live assistant.
- **A delivery rail.** The in-chat question rail (`chat_prompts`) has never sent a message in production — zero rows all-time — and neither has the Helper's send-as-offerer path. B1, B3, C1, D1 and E1 need a rail that exists; the reminder channel is the only one that does.
- **Identity.** In ordinary freegling the assistant speaks as Freegle, in the open (§J); in bulk clearance it speaks as the offerer with a disclosure line. Neither may be blurred into the other.

---

## I. Supporting analyses

Run against the same restore; results folded into the candidates above where they bear on one.

**I.1 Ghosting is read-and-ignored, not unread.** Of the 56,164 silent rooms, the giver had **read the replier's message in 84.3%** (unknown 13.5%, genuinely unread 2.2%). On busy posts it is 98%. It is not neglect of the inbox; it is a decision not to reply, made after reading — which is what B1 and B3 are for. It holds on single-reply posts too: 4,128 rooms (~825/month) where the giver read the only reply they got and never answered it, though the post went on to some outcome 97% of the time. Folded into §B.

**I.2 The ripple hold is not a latency instrument; it is the ripple working as designed.** 7,046 rooms had the replier's opener held by the ripple engine (mean 53 h). The hold is deliberate: distant repliers are delayed so that closer, on-average-more-reliable ones get a head start. It is therefore not assigned independently of the replier — hold rate runs from 0.2% under 2 km to 14.6% at 20 km+ — and distant repliers collect less anyway (35% under 2 km, 28% at 20 km+).

Within a distance band, held rooms still collect at roughly half the unheld rate (×0.37–0.51 across bands; ×0.40–0.59 splitting further by load), so distance alone does not explain the gap. But **longer holds do not do worse**: in the 10–20 km and 20 km+ bands, holds over 48 h collect at 13.9% and 12.2% against 8.7% and 9.9% for holds under 6 h. If delay itself were the mechanism, more of it would hurt more. It does not. What a held replier loses is not time but the race — by release, a closer replier has often already been chosen, which is what the hold is for. This analysis supports the ripple design and says nothing causal about reply latency. §C's latency finding remains observational.

**I.3 No seasonal drift.** Promise rate by month, January–August, from the raw tables: 18.5, 18.1, 19.0, 18.8, 18.9, 17.8, 17.9, 17.3%. August (37,739 Offer rooms, the largest month) is the lowest but within the range. The March–July window is representative.

**I.4 WANTED.** 13,548 rooms, 8% of volume. Roles reverse: the post owner is the taker and the replier is the giver. The wanter replies in 58.5% of rooms (Offer 64.5%), and 37.5% of the silent wanters had read the offer. Promise is clicked in 0.7% of Wanted rooms vs 18.6% of Offer — but the outcome path is not broken: 22.2% of Wanted posts record a `Received` outcome, so the item changes hands at a comparable rate and only the Promise step is bypassed. Wanted has the same triage problem as Offer at a smaller scale, and none of the candidates above would need changing to apply; B1's triage summary and C1's acknowledgement are the ones that transfer directly.

**I.5 Language barrier — not analysable.** The language-flag report reason matches 22 Offer rooms in the window. Nothing can be said at that n.

**I.6 Density band — not analysable at this coverage.** `rippling_reach` carries a density band, but joining it to heavy-item rooms yields 378 rows, most with no band. The ripple engine has not run on enough of the corpus for the band to serve as a confounder. When coverage grows, rerun.

**I.7 Promise-to-renege timing.** From the chat event rows (the `messages_promises` row is deleted on renege, so the DB tables cannot be joined): n = 5,021, **median 36 h; 39% within a day, 70% within three, 87% within seven**. Against promise-to-collection (median 21 h; 83% within three days), the two distributions diverge from day 3: by then most collections that will happen have happened, and most reneges too. A promise still outstanding at day 3 is therefore a real signal rather than noise. Folded into §E.

---

## J. Who the assistant is, in each product

Two products, two identities, and the difference is deliberate.

**Ordinary freegling** — every candidate in §A–§F. Assistant messages come from Freegle's own name and avatar, in a three-way chat: the offerer, the replier, and Freegle. Nothing is sent as either member. The replier sees "Freegle" thank them or answer a question; the offerer sees the same message in the same chat. There is no disclosure line because there is nothing to disclose — the sender is visibly Freegle. *(Still being worked through: exactly how a third participant renders in the existing two-party chat UI, and which of the candidates above are one-off Freegle messages versus Freegle staying in the room.)*

**Automated bulk clearance** — the Helper. Messages are sent *as the offerer*, and the API appends a one-line disclosure to the first auto-sent message in each conversation ("some of these messages may come from our automated assistant"). That is the model `helper/prompt.md` specifies. The Helper design doc's Open Question 1 ("RESOLVED. Messages sent as the offerer (invisible)") predates the disclosure line and should be updated to match the prompt.

The `related_to` knowledge-record field the Helper retrospective proposed for household pairs does not appear in the schema.

---

## K. Reproducibility

Scripts `overload.py`, `structural.py`, `chase.py`, `interaction.py`, `confounds.py`, `renege_timing.py`, `section_i.py`, `friction.py`, `opener.py`, `analyse.py`, `question_test.py`, reading the `scratch.rooms` / `scratch.turns` / `scratch.friction` tables built from a production restore. Re-runnable against any such restore on any machine that can host the local models; nothing in the pipeline calls an external API.
