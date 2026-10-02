# Can Jev do auto-moderation? Measured, and the answer is no

Date: 23 September 2026. Follows `2026-09-22-jev-structured-judgement-evaluation.md`.
Evaluation only, nothing implemented. No key or credential appears here.

Short answer: **no**, and the more useful finding is that the measurement everyone has been
arguing about cannot settle the question either way, because the labels it uses mostly do
not describe the post.

Jev does beat what is there today on the narrow question the content-embedding check asks,
and that check is nearly inert in production, so that part is worth doing. It is a small
prize, not auto-moderation.

## 1. On the published benchmark, Jev is about the same as the fine-tuned model

`llm-modbot/` holds the experiment behind "Why there is no AI moderator" in
`docs/ops/reference/spam-and-abuse.md`. Its results files record the exact examples used, so
the v3 run (200 cases, all still present in `data/moderation_test.jsonl`) can be reproduced
exactly. Positive class is "reject", matching llm-modbot's own convention.

Same 200 cases:

| decider | accuracy | precision | recall | F1 |
|---|---|---|---|---|
| fine-tuned 3B, v3 enriched (recorded) | 49.5% | 49.7% | 96.0% | 65.5 |
| Jev >= 0.5 | 60.0% | 91.7% | 22.0% | 35.5 |
| Jev >= 0.3 | 66.0% | 79.6% | 43.0% | 55.8 |

A fresh balanced 600 from the same held-out file, for a tighter estimate, against the two
published numbers (which were a different draw from the same set, so not exactly like for
like):

| decider | accuracy | precision | recall | F1 |
|---|---|---|---|---|
| base llama3.2:3b (published) | 63.0% | 77.1% | 37.0% | 50.0 |
| fine-tuned 3B, v2 (published) | 63.5% | 72.9% | 43.0% | 54.1 |
| Jev >= 0.3 | 69.5% | 84.6% | 47.7% | 61.0 |
| Jev >= 0.5 | 62.8% | 93.3% | 27.7% | 42.7 |

Better on every column, by a few points. Nobody should ship anything on that.

## 2. The benchmark cannot answer the question

`llm-modbot/scripts/extract_training_data.py:168` takes the rejection category from
`sm.title`, the title of the **standard message the moderator picked**. One of the commonest
is "Blank Letter - Reject", which is a blank template a moderator types their own reason
into. So "blank" does not mean the post was blank: in the test set it labels posts carrying
full, perfectly good descriptions, because the label records which template was opened, not
anything about the post. (Examples are not reproduced here: that dataset is gitignored, and
this repository is public.)

Of the 736 rejections in the held-out test set:

- **59%** carry no usable reason: the blank template, or a title the mapping does not cover
  and which falls through to "other".
- **34%** are duplicate, out of area or posted too soon, which by definition cannot be
  decided from the post text.
- **7%** are both labelled and decidable from the text.

The same shape holds network-wide rather than in the sample. Every rejection in the last
year, by the standard message used:

| reason | count |
|---|---|
| (no standard message at all) | 2,737 |
| duplicate, in five spellings, plus excessive crossposting | 1,738 |
| blank-template variants | 1,229 |
| out of area | 168 |
| too vague, after no reply to a request for more information | 192 |
| medicines, selling, non-landfill item | 242 |

So the task as recorded is about two thirds unrecorded reason and about a third facts
outside the text. The measured behaviour matches: on the modbot set Jev catches 9% of the
external-fact rejections and 37% of the text-decidable ones.

This does not overturn "there is no AI moderator". It supports the conclusion and removes
the number. The honest statement is not "a model scores 63.5%", it is "the recorded
decisions do not say why, and where they do, they usually turn on something the post does
not contain".

## 3. On real outcomes, Jev trims the queue but does not replace it

Better ground truth than a template name: a post was held by a concern keyword, and a
moderator then either approved it or rejected it. Original posts only, excluding ripple
copies, which auto-approve after an hour by design and are not human review.

Twelve months: **6,491 holds, 218 of which a moderator actually rejected**, a 3.4% catch rate.
All 218 plus a 591 sample of the 6,273 approved were scored by Jev and weighted back.

| rule | holds a year | precision | recall | real catches missed |
|---|---|---|---|---|
| today: hold every keyword match | 6,491 | 3.4% | 100% | 0 |
| Jev >= 0.3 | 4,358 | 4.5% | 90.7% | 20 |
| Jev >= 0.4 | 2,050 | 7.8% | 73.5% | 58 |
| Jev >= 0.5 | 886 | 13.7% | 55.8% | 96 |

AUC 0.788, against 0.933 for the same model on the matched-posts question. A third off the
queue for twenty missed posts a year is a trade a person should weigh, not a solve. And the
ceiling is structural: a good part of those 218 were rejected as duplicates or out of area,
which nothing reading the post can get.

## 4. Against the prototype check specifically, Jev is better, on a small prize

`ContentEmbeddingService` suppresses a keyword flag when the post embeds closer to hand
written "innocent" sentences than "concerning" ones by a margin of 0.05. Replicated exactly
against the local sidecar, on the 95 held posts in the four categories it covers, of which
42 were really rejected:

- Today's rule suppresses **7 of 95**, and one of those 7 was a post a moderator went on to
  reject. It is close to inert, and what little it does includes a miss.
- Separating "a moderator rejected it" from "approved": prototype gap **AUC 0.652**, Jev
  "is this a genuine instance of the concern" **0.719**, Jev "would a moderator reject it"
  **0.774**.
- Letting each rule send the same 88 of 95 to a moderator, the prototype rule finds 41 of
  the 42 and Jev finds 42.

So Jev is a straight improvement on the prototypes. But the prototypes only cover 4 of 6
categories and 282 of the 1,260 concern keywords, and the whole surface is 587 holds a year.
Worth replacing because it is strictly better and the sentences are visibly a stopgap. Not
worth calling auto-moderation.

## Caveats

- "A moderator approved it" is not the same as "the hold was unnecessary". It is the outcome
  the system actually produces, and it is what a change would be judged against, but a
  moderator approving after a look is the process working.
- Section 4 rests on 95 posts and 42 catches. It is the right comparison and it is a small
  sample.
- Jev's jaggedness applies here as everywhere: about 2% of decisions near the boundary flip
  between runs. Anything deployed near a threshold should take the majority of three.
- Everything sent to TypeSafe here was public post text. No chat, no member details.

## What follows

1. Replace the prototype sentences in `ContentEmbeddingService` with a Jev call, keeping the
   same conservative default of flagging when the service is unavailable. Small, measured,
   strictly better.
2. Do not pursue general approve/reject. Two independent measurements now say the decisions
   turn on facts the post does not contain.
3. If auto-moderation is wanted, the thing blocking it is not the model. It is that
   two thirds of rejections record no reason. Making the reason a required, structured field
   at the point of rejection would, in a year, produce the dataset this question needs. That
   is a moderator-tools change, not an AI one.
