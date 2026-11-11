---
last_reviewed: 2026-10-20
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/VolunteeringMaintenanceService.php
  - iznik-batch/app/Mail/Volunteering/VolunteeringRenewMail.php
  - iznik-batch/app/Console/Commands/Volunteering/MaintainVolunteeringCommand.php
  - iznik-batch/resources/views/emails/mjml/volunteering/renew.blade.php
  - iznik-batch/tests/Feature/Volunteering/VolunteeringMaintenanceServiceTest.php
  - iznik-batch/tests/Unit/Mail/VolunteeringRenewMailTest.php
---

# Volunteering renewal email

A volunteering opportunity with no dates must be confirmed now and then, or it expires.
`volunteering:maintain` does this in two steps.

1. **Ask, 24 days after the opportunity was added or last renewed.** The owner gets an
   email asking whether each opportunity is still active. `askedtorenew` is stamped on
   every opportunity in the email, so a daily run asks once per cycle.
2. **Expire, 31 days after.** An opportunity not renewed by then is set `expired = 1`.

## One email per owner

`VolunteeringMaintenanceService::askRenew()` groups the due opportunities by owner and
sends one `VolunteeringRenewMail` per owner. Each opportunity in it has its own
auto-login link to `/volunteering/{id}`, where the owner renews it. A member who posted
the same opportunity to eleven communities gets one email with eleven links, not eleven
emails. An owner with one opportunity gets the single-opportunity wording and the
subject `Regarding: <title>`.

`askedtorenew` is stamped only after the spool succeeds. If it throws, none of that
owner's opportunities are stamped and the next run tries them again. The return value
counts opportunities, not emails.

## Did the reminder go out?

The mail is tracked. Each send writes one `email_tracking` row with `email_type =
'VolunteeringRenew'`, the owner's `userid`, and the opportunity ids in `metadata`
(`volunteering_ids`). The renew buttons are tracked links, so clicks are recorded too.
`FREEGLE_MAIL_LOG_TYPES` accepts `VolunteeringRenew` to BCC a copy.
