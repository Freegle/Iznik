---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - discourse-frontline/src/pipeline.mjs
  - discourse-frontline/src/prefilter.mjs
  - discourse-frontline/src/slots.mjs
  - discourse-frontline/src/decide.mjs
  - discourse-frontline/src/outbound.mjs
  - discourse-frontline/src/replies.mjs
  - discourse-frontline/src/report.mjs
  - discourse-frontline/src/config.mjs
  - discourse-frontline/src/llm/prompt.mjs
  - discourse-frontline/src/kb/index.mjs
---

# First-line support on the forum (discourse-frontline)

A small service that reads new posts on the volunteer forum and turns them into problem
reports. It asks the reporter for what is missing, recognises a problem it has seen before, and
answers a how-to question when the wiki or `docs/` covers it. It does not diagnose or fix.

How to run it, its settings and its modes are in
[`discourse-frontline/README.md`](../../../discourse-frontline/README.md). This page is why it is
shaped the way it is.

## Why it is separate from the monitor

Talking to a reporter and fixing a bug are different jobs with different risks.

- **Talking** is a chat that runs over days: thread, ask, wait, read the answer. It speaks in
  public, so it must hold nothing confidential. It reads the forum, the wiki, `docs/` and the
  local git history, and has no credential for anything else.
- **Fixing** needs production data, a shell and a repository. It must not be steered by forum
  text.

Keeping them apart puts the untrusted text on the side with no tools and no data. The two meet
at one file per case, the report written by
[`report.mjs`](../../../discourse-frontline/src/report.mjs).

## Where the tokens go

One model call per post, and only for posts the rules could not settle. The order of work is in
[`pipeline.mjs`](../../../discourse-frontline/src/pipeline.mjs):

| Step | Code | Cost |
|---|---|---|
| Skip own, developer, private and thank-you posts; count "me too" and "still broken" | `prefilter.mjs` | none |
| Find the case a post belongs to | `pipeline.mjs` `threadCase` | none |
| Read off device, app or website, when, links, screenshot | `slots.mjs` | none |
| Find similar earlier cases and wiki or docs sections | `kb/index.mjs` | none |
| Kind, summary, what the report covers, same-as, answer | `llm/prompt.mjs` | one call |
| Choose the reply | `decide.mjs`, `replies.mjs` | none |
| Check and post | `outbound.mjs` | none |

The model has no tools and returns a fixed form. Replies are templates.

## Rules that are easy to break

- **Only `outbound.mjs` posts.** `test/boundary.test.mjs` fails if anything else calls the
  forum's reply.
- **No production source.** The same test fails if a database, log or error-tracking setting
  appears in the code.
- **The cursor moves with the stored post, in one transaction.** A lap that fails re-reads.
- **A question that was not sent is not waited on.** Otherwise the reporter's next post would be
  read as their answer.
- **A relevance floor is counted in shared words, not a BM25 score.** A score means something
  different in a small index and a large one.
- **The cached part of the prompt holds nothing that changes.** A date in it would cost a cache
  write on every call.

## Checking a change

`discourse-frontline/check.sh` runs the unit suite with a stand-in forum and model.
`bin/frontline.mjs eval` runs made-up threads through the real model and prints how many came
out right and the tokens used.
