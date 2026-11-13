# discourse-frontline

First-line support on the volunteer forum. It reads new Discourse posts, works out which are
problem reports, questions or suggestions, asks the reporter for whatever a developer would
need, and writes each one up as a report. It answers a how-to question when the wiki or `docs/`
covers it.

It does not diagnose and it does not fix. Its output is a coherent report; whatever does the
diagnosing reads that report and never has to read the forum.

## What it can reach

| Reads | Never reads |
|---|---|
| The forum, as the account it is given | The production database |
| The volunteer wiki (public API) | Logs, error tracking, mail |
| `docs/members` and `docs/moderators` | The member or moderator API |
| Titles of merged pull requests (local `git log`) | Anything needing a production credential |

It replies in public, so it is given nothing it could leak. `src/config.mjs` has no setting for
any production source and `test/boundary.test.mjs` fails if one is added.

The model gets no tools. It is handed a post and returns a small form
(`src/llm/prompt.mjs`). Text from a hostile post can make the form wrong, and that is all it can
do: code decides what happens next, and almost every word posted is a fixed template
(`src/replies.mjs`). The three places the model's own words are used (the summary read back, one
optional extra question, the answer to a question) reject links, markup, addresses and long
numbers.

Reports in `state/reports/` hold what volunteers wrote, which can include a member's details.
They stay on the machine. Nothing sends them anywhere.

## How a post is handled

Cheapest first. Most posts never reach the model.

1. **Rules** (`src/prefilter.mjs`, free). The service's own posts, a developer's, private
   messages, a bare "thanks", "same here" or "still not working" threaded to a known case. Outside
   the support categories, anything that does not sound like something broken.
2. **Threading** (`src/pipeline.mjs`, a database read). What the post replies to or quotes, and
   whether its author is someone we are waiting on, says which case it belongs to.
3. **Facts by rule** (`src/slots.mjs`, free). App or website, device, browser, when, how often,
   links, numbers, error text, whether a screenshot is attached, and whether it points at "a
   member" or "some posts" without saying which.
4. **Search** (`src/kb/index.mjs`, free). BM25 over SQLite full-text tables: up to five earlier
   cases that might be the same thing, and, for a post that reads like a question, up to three
   sections of the wiki and docs.
5. **One model call** (`src/llm/`). Kind, a one-line summary, whether the report says what
   happened, what was expected and how to reproduce it, whether it matches an earlier case, and
   an answer if the sections give one.
6. **Decision by rule** (`src/decide.mjs`). Ask, acknowledge, link to the earlier case, answer,
   or say nothing.
7. **The outbound door** (`src/outbound.mjs`). The only code that posts. Every reply is written
   to a ledger first, then checked against the mode, the caps, the link allowlist and whether a
   person has already replied.

## What reporters see

- A clear report: a short acknowledgement that reads the summary back, so a misunderstanding is
  caught at once.
- A thin report: one question listing what is missing, at most three things. A second question
  if the answer still leaves a gap, then it stops and hands over what there is, marked
  incomplete.
- The same problem as an earlier case: a link to it. The earlier report gains the new post and
  its count of people affected goes up.
- A how-to question the wiki or docs cover: two to four sentences and the page it came from.
- A question they do not cover: nothing. It goes on `frontline queue` for a person. A wrong
  answer is worse than none.
- Once a developer replies in the thread, the service says nothing more there. It keeps the
  report up to date.

## Repeat problems

Each case is indexed for search when it is opened. A new post is shown the closest earlier cases
and the model says whether it is the same one. "Me too" and "still broken" threaded to a case
are counted by rule with no model call. The report carries `affected`, `stillBrokenReported` and
every post in the reporters' own words, in order.

## The report

`state/reports/case-<id>.json` and `.md`, rewritten on every change with `version` bumped.
`status` is `complete`, `incomplete` (asked and not told, or stopped asking) or `waiting`.
`unknowns` lists what is not known. `possiblyRelatedChanges` is a word match against recently
merged pull request titles and nothing more. See `src/report.mjs` for the fields.

## Modes

`FRONTLINE_MODE` is the one switch. The default posts nothing until a person says so.

| Mode | Posts |
|---|---|
| `off` | Does not run |
| `confirm` (default) | Nothing by itself. Every reply is written as a draft and waits for a person |
| `ask` | Questions, acknowledgements and links to earlier cases |
| `live` | Those and answers to how-to questions |

`shadow` is `confirm` under another name, for a trial nobody means to send from.

### Confirming replies

```bash
bin/frontline.mjs review        # one draft at a time: send, discard or leave
bin/frontline.mjs drafts        # list them
bin/frontline.mjs send 12       # send one
bin/frontline.mjs discard 12    # throw one away
```

Each draft shows the post it answers, how long ago it was written, how many posts have been
made in the topic since, and whether a person has replied. A draft a person has overtaken is
not sent unless you say so (`--force`). A draft with something wrong in it (a link off the
allowlist, an address, an answer that is hard to read) is refused when it is written and never
offered. A question you confirm is waited on from then, like any other.

## Running it

Needs Node 22.13 or later and the `claude` command line. The model is reached through it: sign in
on the machine, or run `claude setup-token` once and put the token in `CLAUDE_CODE_OAUTH_TOKEN`
for the service. `npm install` is needed only for the API route.

```bash
bin/frontline.mjs sync        # build the search index: docs, wiki, recent changes (about 10s)
bin/frontline.mjs lap         # read what is new; the first lap only marks where to start
bin/frontline.mjs review      # confirm what it wants to say
bin/frontline.mjs status      # cursor, cases, replies, tokens today
bin/frontline.mjs queue       # what is waiting for a person
bin/frontline.mjs backfill 200  # start 200 posts back, to try it on recent posts
bin/frontline.mjs search approve spinner   # try the index; costs nothing
```

Run `lap` from a timer every few minutes and `sync` once a day. `lap` exits non-zero when it
stops early (the forum or the model failed, or the daily token cap was reached), so cron mails
it. The cursor moves one post at a time and only when that post is stored, so a failed lap
re-reads from where it stopped.

| Setting | Default | |
|---|---|---|
| `FRONTLINE_MODE` | `confirm` | See above |
| `FRONTLINE_CATEGORIES` | all | Support category ids. Elsewhere only posts that sound broken are read, and nothing is posted |
| `FRONTLINE_BOT_USERNAME` | | The account it posts as; its own posts are never read as reports |
| `FRONTLINE_STAFF` | | Usernames whose posts are a developer speaking |
| `FRONTLINE_DISCOURSE_KEY` or `FRONTLINE_PROFILE_PATH` | | The forum API key, or a profile file holding one |
| `FRONTLINE_LLM` | `cli` | `cli` uses the Claude Code login or setup token. `api` uses `ANTHROPIC_API_KEY` |
| `FRONTLINE_MODEL` | `claude-opus-5-5` | |
| `FRONTLINE_MAX_ASKS` | 2 | Rounds of questions before handing over |
| `FRONTLINE_MAX_TOKENS_PER_DAY` | 600000 | The lap stops when reached |
| `FRONTLINE_MAX_REPLIES_PER_DAY`, `_PER_TOPIC_DAY`, `_PER_LAP` | 40, 3, 8 | |
| `FRONTLINE_STATE_DIR` | `./state` | Database and reports |

## Tests

```bash
./check.sh          # unit suite: no network, no model, nothing to install
bin/frontline.mjs eval   # the made-up threads in eval/golden.mjs through the real model
bin/frontline.mjs replay posts.jsonl out.json   # an archive of past posts, as if arriving now
```

`eval` prints how many threads came out right and the tokens used. Run it after any change to
the prompt, the model or the rules. Every post in `eval/golden.mjs` was written for that file;
none is copied from the forum.

`replay` is how a change is judged against history without waiting: it runs an archive of real
posts through the service in order, posts nothing, and writes what it would have done with each
(`eval/replay.mjs` gives the archive format). Give it a state directory of its own.

## Not built

- It polls. Discourse webhooks would be faster and are not needed at a lap every few minutes.
- Nothing reads the reports yet. The monitor still does its own triage.
- The API route (`src/llm/anthropic.mjs`) has not been run against the live API; the
  command-line route has.
