# Candidates for AI intervention in Freegle chats

*5 September 2026. Evidence from 158,349 Offer conversations (76,663 posts), March–July 2026, on the production database restored to the GX10. This supersedes the earlier friction analysis, which conditioned on conversations where the offerer had already replied — and so could not see the dominant failure, which is that the offerer never replies at all.*

Each candidate is scored on **plausibility** (does it target a real mechanism), **evidence** (how firm the data is), and **reach** (rooms per month). These are candidates, not decisions; none is implemented. Where a candidate is what the Freegle Helper already does for bulk offers, that is noted — the question there is whether to generalise it.

---

## The finding that reframes everything

**35.5% of repliers to an Offer never get a word from the offerer — about 11,200 people a month.** This is not spread evenly. It is a function of how many people replied:

| replies on the post | posts | offerer replied to each replier | collected by that replier |
|---|---|---|---|
| 1 | 35,905 | **84.8%** | 38.7% |
| 2–3 | 26,913 | 74.9% | 25.1% |
| 4–6 | 9,310 | 59.2% | 14.4% |
| 7–10 | 2,952 | 45.6% | 8.6% |
| 11–20 | 1,255 | 34.4% | 5.0% |
| 21–40 | 260 | 23.4% | 2.6% |
| 41+ | 26 | **11.3%** | 0.9% |

The post itself is taken at about 92% regardless of load. So the offerer is coping — by triage, not by conversation. On busy posts (11+ replies), **70% of repliers hear nothing at all** (14,486 people over five months). 857 posts had 11+ replies and the offerer answered two people or fewer; 9,992 repliers were ghosted on those alone.

This is the Greenwich clearance (88 repliers, 40 replied to) and the Samsung TV (87 repliers, 2 replied to) from the Helper retrospective, measured at scale. Almost everyone who gets nothing does eventually receive the automatic "sorry, it's gone" message (96.3%) — but only after the item has gone, having been left hanging until then.

The one mitigating structure the platform has is reply rank: on busy posts the first replier is answered 60% of the time, the eleventh-or-later 21%. Early birds win. That is not what experienced offerers say they want ("weight politeness, friendliness and track record over first-come-first-served"), it is what overload produces.

---

## Ranked candidates

### 1. Triage assistant for overloaded offerers — *plausible, strong evidence, ~2,900 posts/month*

**What:** when a post passes a reply threshold (the data says 4+), offer the offerer a summary of who has replied — each with the concrete things that matter (stated time, transport where the item is heavy, distance, charity/organisation, track record) — and a one-click way to send a holding message to everyone they are not going to pick. The Helper already does exactly this for bulk offers; this is the case for the ordinary busy post.

**Why the data supports it:** the reply-rate collapse above is monotonic and steep. The offerer is not being rude; 4+ conversations is more than most people will run. The Helper trial's finding was that its biggest value was "making sure good candidates don't fall through the cracks", not clever allocation — and the corpus shows the cracks are 70% wide on busy posts.

**Sizing:** 13,400 posts/5 months with 4+ replies ≈ 2,900/month, covering ~72,000 rooms.

**Evidence status:** prevalence is solid. Whether a summary changes who gets picked or how many get a reply is untested; a trial should measure reply rate and time-to-first-reply on busy posts.

### 2. Reply within the hour — *plausible, strong evidence, applies to every conversation*

**What:** anything that gets the replier an answer inside an hour — an automated acknowledgement, an answer to a factual question drawn from the listing, or a nudge to the offerer — on the model of the Helper's rule 1, *answer factual questions immediately*.

**Why:** offerer first-reply latency predicts collection **within every load stratum**, which rules out the obvious confound that busy posts get faster replies:

| replies on post | offerer replied <1h | 1–4h | 12–24h | 1–3 days |
|---|---|---|---|---|
| 1 | **51.6%** | 48.7% | 43.1% | 38.6% |
| 2–3 | **43.5%** | 36.4% | 29.4% | 24.7% |
| 4–6 | **33.6%** | 26.6% | 20.5% | 14.8% |
| 7–10 | **29.4%** | 21.1% | 14.7% | 12.6% |
| 11+ | **21.4%** | 13.7% | 11.4% | 7.3% |

A reply under an hour is worth roughly double a reply after a day, at every level of competition. Median offerer latency is 16 hours on quiet posts and 37% take over a day. The taker "came back" 89% of the time after a sub-hour reply, 81% after 1–3 days; so most of the effect is not the taker leaving but the arrangement losing momentum.

**Caveat:** this is observational. Fast repliers are probably more organised offerers in other ways too. But the gradient is large, consistent, and matches the trial log directly (replier F chasing "hope you can see my message"; four messages in ten hours from another). The intervention is cheap enough to test.

### 3. Answer the taker's question from the listing — *plausible, strong evidence, ~4,100 rooms/month*

**What:** 44% of openers contain a question. **29.7% of those questions are never answered** — 13.1% of all Offer conversations, ~4,100 a month. Where the question is factual and answerable from the post (dimensions, condition, "will it fit in a car", "how many"), an assistant can answer it on the offerer's behalf, or at least tell the offerer that an unanswered question is waiting.

**Why:** collection rate is **38.4% when the question is answered, 6.6% when it is not**, against 15.5% when no question was asked. That gap is partly survivorship (an answered question means an engaged offerer) — but the unanswered branch is so poor that even a fraction of it being recoverable is worthwhile. The earlier work measured a cheap detector for this: a 14B local model finds questions at precision 0.97 versus 0.82 for a question-mark regex, at 500 messages a minute. Detection is a solved problem; the intervention isn't.

**This is the Helper's rule 1 generalised**, and the trial log's most-repeated pattern ("question replies need answering first").

### 4. Stop the taker chasing into silence — *plausible, moderate evidence, ~2,000 rooms/month*

**What:** 17.7% of repliers who never get an answer send a second, third, fourth message anyway — 9,948 people over five months chasing an offerer who has already moved on. An assistant that knows the post has been promised elsewhere can tell them so, or at least stop the chase from being read as pushiness.

**Why this and not "reward chasers":** within conversations the offerer did reply to, chasers collect at 47% versus 22% — but that is engagement, not a lever; chasing is what keen people do. The waste is the other 9,948, whose follow-ups nobody reads. Separately, an opener regex for demanding language ("please reply", "still waiting", "hello??") finds 0.5% of openers and they collect at 0.77× baseline — impatience does not help them, which matches the retrospective's "impatient repliers aren't bad candidates, they're anxious".

### 5. Transport question, but only where it is load-bearing — *plausible, evidence now conditional, ~430–600 rooms/month*

The earlier analysis tested "transport mentioned" marginally, found opener-transport OR 0.79 on heavy items, and wrote the idea off. That was the wrong test. The Helper's rule is conditional — *ask only for large/heavy/bulky items; assume fine otherwise* — and the corpus, cut by weight, says the same:

| weight | offerer asks about transport | asked → taker goes silent | collected: never raised / offerer asked |
|---|---|---|---|
| under 10 kg | 1.4% | 22.9% | 32% / 37% |
| 10–25 kg | 2.0% | 15.6% | 29% / 33% |
| 25–50 kg | 3.6% | 16.2% | 26% / 28% |
| **50 kg+** | **4.2%** | 15.6% | 25% / 28% |

Two things. First, offerers already ask more on heavy items, and when they ask, the taker who answers collects at a higher rate than when nobody raised it. Second — and this is the actual cost — **when the offerer's first reply is a transport question on a heavy item, the taker goes silent 17.4% of the time, against 3.4% for a time question.** A transport question is five times as likely to end the conversation as a time question. That makes it the strongest case for *asking it at reply time instead*, before the offerer has invested, so a would-be-silent taker self-selects out at zero cost — and it argues against the assistant asking it mid-conversation.

Recommendation: a reply-time transport prompt on ≥25 kg items only (the 25–50 and 50+ tiers, ~3,600 rooms/month, of which ~600 currently get the question from the offerer). Not on light items, where it is noise. Untested; needs the trial, but the prior is now positive rather than neutral.

### 6. Surface the structural repliers the offerer would want — *plausible, weak-to-moderate evidence, ~1,400 rooms/month*

Regex over 156,107 openers for the patterns the Helper retrospective named:

| pattern | prevalence | offerer replied | collected vs baseline (21.4%) |
|---|---|---|---|
| household pair ("my husband has also…") | 0.9% | 70.8% | **28.2% (×1.32)** |
| connector / on behalf of a charity | 0.4% | 72.4% | 25.9% (×1.21) |
| self-declared backup ("only if no charity wants it") | 1.4% | 67.6% | 25.8% (×1.20) |
| offers to take multiple / everything / has a van | 0.6% | 67.4% | 22.4% (×1.05) |
| question-only opener | 0.3% | 66.3% | 18.3% (×0.85) |
| demanding tone | 0.5% | 57.7% | 16.4% (×0.77) |

These are small populations (the regexes are conservative), but three are worth an assistant noticing. The **bulk collector on a busy post** is the "take everything, got a big van" case from the retrospective: on 7+-reply posts, openers offering to take multiple items are replied to at 46% versus 38% and collect at 10.5% versus 6.2% — better than average, but still a 54% chance the person with the van hears nothing. **Connectors** replying for a charity do well when answered; they are the candidates most likely to match a "charities preferred" criterion and most likely to be lost in a pile. **Household pairs** are the one the retrospective said to watch for and consolidate ("your household wants the TV, coffee table and sound system — can one of you collect all three?"); they collect at the highest rate of any pattern, so they are worth finding.

### 7. Structured collection-time slots — *plausible, strong evidence, carried forward from the earlier analysis*

Unchanged: a concrete time in the opener is the one robust, pre-treatment, distance-controlled positive signal (collected OR 1.67). 83% of openers contain a time expression and offerers still ask "what day/time?" in 18% of conversations — the free-text box produces vagueness, not absence. ~3,700 avoidable round-trips a month. This is the most solidly evidenced *product* change; it is included here because an assistant that offers the taker the offerer's actual windows is the conversational form of it.

### 8. Do NOT: discourage contact details in the opener — *withdrawn earlier and stays withdrawn*

The earlier analysis found openers with a phone number or email get 37% fewer replies and proposed a hint against sharing them. The user's challenge was right: many of those conversations went off-platform and the item was collected — silent rooms with contact details collected at 8.1% versus 1.3%. The "no reply" is the arrangement succeeding elsewhere, not failing. Any assistant that treats contact-sharing as a problem will be wrong about a third of the time.

---

## What is *not* worth an AI

- **Rewarding chasers.** Chasing marks engagement; it is not a cause of success, and encouraging it would make the busy-post problem worse.
- **Mid-conversation transport questions on light items.** 1–2% of offerers ask; it does not move outcomes; it just adds a turn.
- **Anything that treats the giver's "sorry, gone" fan-out as a failure.** 96% of ghosted repliers receive it. The failure is the silence *before* it, not its absence.

## How this differs from the earlier friction analysis, and why it found more

The earlier work filtered on `two_sided = 1` — conversations where the offerer replied — and then looked for turn-level friction within them. Everything above under candidates 1, 2 and 4 lives in the `two_sided = 0` stratum it discarded, which is 35% of the corpus and where the outcome variance is. Candidate 5 is the same transport data re-cut as an interaction with weight instead of a marginal effect, which is what the Helper's rule always said it was. Candidate 6 came from reading the Helper retrospective's case notes and sizing each named pattern rather than searching for friction words. The method was fine; the conditioning and the hypotheses were wrong.

## Reproducibility

The analysis scripts (`overload.py`, `structural.py`, `chase.py`, `interaction.py`, `confounds.py`) read the `scratch.rooms` / `scratch.turns` / `scratch.friction` tables built by the earlier chat-signals pipeline from a production database restore; they can be re-run against any such restore. Member chat text was read only by a local model on the analysis machine and never left it; this document contains aggregate counts only.
