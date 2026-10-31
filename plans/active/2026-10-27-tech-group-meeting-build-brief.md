# Build brief from the tech and ops meeting, 2026-10-27

Source: Zoom transcript of the tech/ops working group (Edward, Neil Morris, John Carroll, Wendy
Lishman, Neil). Spare Claude capacity is usable 9 to 15 November. The meeting agreed the direction
(no human moderation of the online platform, group is no longer the admin unit) but that needs a
board decision first. Everything below is sorted by what can be built before that decision.

## 1. Build now, no board decision needed

### 1a. Data pack for the board paper
One read-only report (Node or PHP, against the live replica), numbers only, each with the query
that produced it so the board can see the method:

- Unique owners across all groups, and unique active moderators (owner or mod, active in the
  last 90 days). Neil Morris asked and Edward said Claude would answer.
- Active mods by tenure. Edward quoted roughly 240 active with 10+ years, 190 with 15+ years,
  130 with 17+ years. Verify, and say which account date was used.
- Founders still active. We do not hold "who set the group up"; use the earliest Owner
  membership per group as a proxy and say so.
- Net volunteer churn over 12 months. Neil Morris said about 20 new volunteers; Edward said that
  is fewer than we lost and some may be email-address changes. Count joins, leaves, and merges.
- Moderation concentration. Refresh the "25 people do half the moderation" figure.
- Rejection-rate variance between moderators on comparable posts.
- Donations and gift aid attributable to current owners and moderators: what we would lose if
  every one of them stopped giving. Edward's guess was about two thousand pounds a year.
- Micro-volunteer count (quoted as 14,700) and how many were active in the last year.

### 1b. How much of the code is the group model
Edward's range is 20 to 40 percent of the code; the board will treat 40 as the number unless
measured. Classify files and functions in iznik-server-go, iznik-batch and iznik-nuxt3 by whether
they exist only because groups are the admin unit (memberships, group settings, messages_groups,
ModTools group tabs, rippling, mentors and caretaker groups) or merely carry a group id. Output a
per-repo table with lines and a confidence note. This is measurement, not an implementation plan.

### 1c. Income visibility page
Edward: "there is no coherent place where all of that information is exposed in a single place"
and "perfectly possible to build that". Build one SysAdmin page that shows donations (amount,
recurring or one-off, giver where known), gift aid, jobs clicks and billable clicks with the
60-day payment lag made explicit, and advertising income in arrears. Read-only. Stop before any
appeal or campaign tooling: Edward's rule is that the person who will use it specifies it, and
Neil Morris has not yet written down what he wants.

### 1d. Board paper and moderator comms, drafted from the transcript
Not code, but Edward agreed to do it with Claude. John Carroll wants a short business case, not
a long paper: reasons (volunteers declining in number and energy, complexity, cost, tech capacity
shrinking), the recommendation (one national system, no group-level moderation, no opt-in, no
parallel test system), what it costs (one to two weeks of Edward plus a few thousand pounds of
tokens), what not doing it costs, and the likely objections. Separately, a first draft of the
message to moderators. Leave fundraising out; Edward declined to write that section and the
figures John quoted (about 55k donations and gift aid, 23k advertising) are the budget's.

### 1e. Correct the existing AI-first paper
Edward said parts of it are wrong and will be read as the plan: remove per-group opt-in (every
interaction between opted-in and opted-out groups multiplies the work, as rippling showed), remove
the separate test system, and answer Neil Morris's two objections, that an operations person is
not a developer and that costs are underestimated.

## 2. Ready the moment the board decides, but do not start before

- Post moderation by default. Already coded. Keep the branch rebased and green. Release is a
  board call, not a technical one.
- Removing the group as the moderation and admin unit, big bang, mop up afterwards. Edward's
  estimate is two thirds to three quarters of it inside the token window. He will not write the
  implementation plan until the board has said in plain terms that human moderation of the
  platform is ending, so no plan and no code until then.
- Roles for the few people who want to help nationally (answering real member questions,
  samples and escalations). Depends on the same decision.

Note from Edward: builds are public on GitHub and in the automated summaries, so anything in
section 2 announces the direction before the board does.

## 3. Rejected in the meeting, do not build

- Per-group opt-in or opt-out of automated moderation.
- A test system, or a frozen old.ilovefreegle.org for groups that want no change.
- A ticketing or support interface for human-driven support. John Carroll's support work is
  parked and folds into the automation work.
- Free shops, give-and-take or council-liaison tooling: no volunteers exist to use it yet.
- A fundraising pack, strategy or appeal tooling without a named person who will run it.
- Any dashboard "for somebody who doesn't exist".

## Open points for Edward

- The window is 9 to 15 November; confirm the year's month is right and whether 1b should land
  before the board paper goes out.
- Whether the data pack is shared on Discourse or stays with the board.
