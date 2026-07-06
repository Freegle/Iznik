# Authority stats — quarterly council email automation (PREPARATION, do not build yet)

Status: **design only.** Edward: "do not yet build the email reminder stuff but prepare."
Source: call transcript Edward/Natalie (see WEBVTT), lines ~162–268 and 204–255.

The spreadsheet generator (`php artisan authority:stats`) is built. This document
captures the *email* automation that should wrap around it, so it can be built
in a later pass.

## Current manual workflow (Natalie)

1. Receives the stats "data drops" (one `.xlsx` per council) from Edward.
2. Opens each, does light formatting tidy-up (now handled by the generator).
3. Maintains a **template email in a Google Doc** ("anyone with the link can view").
   Each quarter she:
   - keeps the greeting the same (councils won't remember last time's),
   - changes the dates,
   - picks a few topics to talk about, e.g. Little Free Shop update, the
     "reusual suspects" (ongoing), a link + screenshot of the latest Freegle
     Bytes, and a case study (bulky waste / bulk-freegling).
4. Sends the email **individually** to each council contact (~4–6 today:
   Cheltenham, Essex, Lancaster, Newcastle-under-Lyme, Ealing, Wandsworth),
   with that council's spreadsheet attached.
5. Pain point: individual sending is tedious and won't scale.

Only Newcastle-under-Lyme really engages / occasionally asks for extra info —
out of scope for v1 (80/20).

## Proposed automation (Edward's plan from the call)

1. After the quarter ends, generate the stats spreadsheets (existing command).
2. **Reminder email to Natalie ~2 weeks before the intended send**, WITH the
   generated spreadsheets attached, asking her to (a) glance over the stats
   (catch e.g. an unwanted user story) and (b) update the template email.
   - Not a month before — "a couple of weeks" so the news stays current.
3. Natalie reviews the stats and updates the template (dates + topics).
4. **Stale-template guard:** before sending to councils, the system inspects the
   template; if its dates are NOT updated for the current quarter, it does NOT
   send, and re-reminds Natalie (it means she hasn't updated it).
5. Once the template dates are current → the system sends the per-council emails
   **automatically, as Natalie**, each with that council's spreadsheet attached.
6. If the stats look wrong at review time — cross that bridge then (manual).

Net effect: Natalie's only recurring task becomes "edit the template"; the send
is automatic and she no longer depends on Edward to run anything.

## Open questions to resolve before building

- **Template source & "dates updated" detection.** The template is a Google Doc
  shared by link. Need: the doc id/link; a way to read its text (Google Docs API
  export, or published-to-web HTML). "Updated" = the current quarter's date
  range/label appears in the body; "stale" = it still shows a previous quarter.
  Define the exact date token to look for.
- **Recipient map.** authority id → council contact email + display name. Store
  as config (`config/authority_stats.php`) or a small table. 6 authorities today:
  72467 Cheltenham, 117233 Essex, 72572 Lancaster, 72764 Newcastle-under-Lyme,
  72899 Ealing, 72950 Wandsworth. Names kept in full ("District (B)" NOT stripped
  — decided on the call).
- **Sender identity.** Send **as Natalie** (her Gmail). Reuse the GmailService /
  service-account send-as pattern already used for the WAGGGS outreach (see
  `project_waggs_outreach_send` memory: sender2.php / GmailService, SA key). Need
  her send-as address confirmed and the SA authorised.
- **Attachments.** The per-council `.xlsx` from `authority:stats`.
- **Body assembly.** The template body is essentially the same for every council
  (greeting + topics); only the attachment (and any per-council greeting/dates)
  differs. Confirm whether any per-council text is needed (probably not for v1).
- **Scheduling.** Laravel scheduler on `batch-prod`. Quarterly, triggered after
  quarter end; reminder 2 weeks before the send date. Define the exact send date
  policy (e.g. N weeks after quarter end).
- **Safety / approvals.** The reminder-with-attached-stats IS the human review
  gate. The "go" signal is implicit: template dates freshened → send. Consider a
  dry-run mode and a hard stop if the recipient map or template can't be read.

## Components

- `authority:stats` — generate spreadsheets. **DONE.**
- `authority:stats-reminder` (scheduled quarterly, 14th of Jan/Apr/Jul/Oct) —
  generate the last full quarter's spreadsheets and email them to the
  partnerships inbox for review. **DONE** (this PR). Config in
  `config/authority_stats.php` (`reminder_recipient`, `authority_ids`).
- `authority:stats-send` (follow-up) — read the template, verify its dates are
  current; if so, send per-council emails as Natalie with attachments; if not,
  re-remind and do not send. **Not built** — needs the per-council recipient map,
  the template Google Doc reference + a "dates updated" check, and the send-as
  Gmail identity (all external config not yet available).
- Stale-template detection helper (part of the send follow-up).

## Cross-references

- Spreadsheet generator: `iznik-batch/app/Console/Commands/Authority/AuthorityStatsCommand.php`.
- Send-as-a-person Gmail pattern: WAGGGS outreach (GmailService, SA key, dry-run).
- External clearance "availability" page discussion earlier in the same call
  (notes field they can control) is a SEPARATE piece — not this automation.
