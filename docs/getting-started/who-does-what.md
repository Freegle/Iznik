---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Who does what

A lot of what looks like "the system" is actually people. Volunteers moderate posts, thank
donors, chase spam, submit the Gift Aid claim and answer support mail. If you treat those
teams as part of the architecture, several odd-looking pieces of the code start to make
sense - in particular the many scheduled jobs whose entire purpose is to email a human a
list of things to do.

This page is about the human side. It does not name individuals, because people change; it
names the **roles and the addresses**, which are stable and are what the code refers to.

## How to read the code's view of the teams

The batch tier does not hard-code anybody. Every team address is a config value in
`iznik-batch/config/freegle.php` under `freegle.mail.*`, overridable from
`.env.background`. So the definitive, current answer to "who gets this report?" is that
file plus the live environment - not this page.

```
config('freegle.mail.fundraising_addr')   ->  FREEGLE_FUNDRAISING_ADDR
```

If you need to redirect a report to a different team, that is a config change, not a code
change.

## The teams the code emails

| Address (config key) | Who they are | What the system sends them |
|---|---|---|
| `support_addr` | **Support** volunteers | Member support mail. Also receives newsfeed (ChitChat) reports, and chat reports where the two members share no community (via `spam_addr`) |
| `spam_addr` | **Spam** team | Chat reports between members with no community in common - the shape of a scam. Defaults to Support |
| `mentors_addr` | **Mentors** | Escalations from moderators, and where new moderators go for help |
| `centralmods_addr` | **Volunteer Support** (central moderators) | The Discourse coverage report: which communities have nobody signed up, which volunteers are missing. Weekly, plus any day a community is unrepresented |
| `fundraising_addr` | **Fundraising** | The daily donation summary: every donation that came in today, flagged for recurring and birthday givers |
| `thanks_addr` | **Thanks** team | The daily thank-prep digest - one card per donor, written to be replied to. Defaults to Fundraising |
| `treasurer_addr` | **Treasurer** | Named in the monthly TrashNothing invoice as where the invoice should be sent |
| `tn_invoice_addr` | **TrashNothing** (a partner, not us) | The monthly LoveJunk revenue-share invoice request. **No default** - if it is unset the command refuses to send rather than mailing the wrong person |
| `partnerships_addr` | **Partnerships** | Charity partner sign-ups |
| `geeks_addr`, `geek_alerts_addr` | **Geeks** - the technical volunteers | Technical reports and alerts. This is the list you are joining |
| `info_addr` | General admin | General notifications |

Two details worth knowing before you debug a "missing email":

- **`thanks_addr` falls back to `fundraising_addr`, which falls back to `info_addr`.** So a
  report always goes somewhere. An unset variable does not produce an error; it produces
  mail arriving in a more general inbox than intended.
- **`tn_invoice_addr` is the exception** and deliberately has no default, because it is an
  address at another organisation.

## Moderators

Every community has its own volunteer moderators - **at least two per community**, which is
a rule the Volunteer Support team enforces, not something the code checks. They approve and
reject posts, handle member disputes, and set their community's own settings.

What matters technically:

- **Moderators are members with extra permissions**, not a separate user type. The same
  account, the same tables.
- **They work in ModTools**, a different frontend build from the same repository. If you
  break a shared component you break both surfaces
  ([../moderators/README.md](../moderators/README.md)).
- **Almost every moderation decision is per community.** A post in three communities can be
  approved in one and pending in another, and a moderator deleting it deletes their copy.
  This surprises everyone once.
- **Some settings are deliberately not moderator-controllable**, because they affect other
  communities rather than their own.

Support volunteers and mentors sit above the per-community moderators and pick up what
those moderators escalate.

## Money: who does which part

Nobody is paid to do most of this, and the split matters because the automation stops
halfway on purpose.

| Job | Who | How the automation helps |
|---|---|---|
| Thanking donors | **Thanks** volunteer | Gets a daily digest of per-donor cards. **Writes the replies by hand** - the digest is prompt material, not an outbox |
| Watching income | **Fundraising** | Daily summary of today's donations |
| Gift Aid claim | **Treasurer / finance volunteer** | `donations:giftaid-claim` produces the HMRC claim as a CSV. **A human submits it.** Nothing in the code talks to HMRC |
| Chasing Gift Aid consent | Automated | `sendGiftAidChaseUps()` emails donors who have not declared, honouring their engagement-mail preference |
| The TrashNothing invoice | **Treasurer**, with TrashNothing | We compute the revenue share and email TrashNothing to invoice us; the treasurer pays it |
| Ads | Automated | Ads switch themselves off when donations clear the target ([../developers/reference/ads.md](../developers/reference/ads.md)) |

Two consequences of the Gift Aid split:

- **Never hand-edit the claim CSV.** HMRC rejects the whole submission for a format error,
  and the code has already marked those donations as claimed. Fix the data and regenerate.
- **Run it with `--dry-run` first.** Without it, generating a claim marks donations as
  claimed and invalidates records that failed validation.

The mechanics are in
[../developers/reference/donations-and-gift-aid.md](../developers/reference/donations-and-gift-aid.md).

## The technical volunteers ("geeks")

The people who write and run the platform. Small, part-volunteer, part-paid. Practically,
this means:

- **Code review is by whoever is around.** Do not wait for a specific person.
- **Nobody is on call in a formal sense.** Alerts go to a mailing list and to whoever is
  awake. This is why the monitoring is tuned to be quiet and why a silent failure is the
  worst kind of bug we ship - see the Yahoo mail example in
  [what-freegle-is.md](what-freegle-is.md).
- **Discussion happens on Discourse**, the volunteers' own forum, not in issue trackers.
  There is a weekly automated summary of code changes posted there
  (`FREEGLE_DISCOURSE_TECH_EMAIL`), which is how non-technical volunteers see what changed.

## Freegle staff

A very small paid team handles partnerships, communications, funding and the things that
need a named accountable person. They are not developers and should not need to be. If a
tool needs a technical person to run a command, that is a gap, not a design.

## What this means when you change something

Three habits that keep the human side working:

1. **When you change a report, tell the team that receives it.** They have built habits
   around the exact shape of these emails. A "tidy-up" of a digest layout can break
   somebody's daily routine.
2. **When you add an automated email, decide who acts on it.** An alert nobody owns is
   noise, and noise gets filtered, and then the real alert is missed too.
3. **Prefer giving a volunteer a better list over automating their judgement.** That is the
   pattern throughout: the code assembles the facts, a human decides. It is also why we
   measured an AI moderator and did not ship it
   ([../ops/reference/spam-and-abuse.md](../ops/reference/spam-and-abuse.md)).

## Where to find the current people

Names, who holds which account, and who to escalate to are in the credential vault and the
volunteers' forum, not in git. See
[accounts-and-access.md](accounts-and-access.md).
