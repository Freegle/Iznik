---
last_reviewed: 2026-10-24
owner: Freegle dev team
covers:
  - iznik-server-go/alert/alert.go
  - iznik-server-go/alert/reply.go
  - iznik-batch/app/Services/AlertService.php
  - iznik-batch/app/Mail/Alert/AlertMail.php
  - iznik-batch/app/Services/EmailSpoolerService.php
  - iznik-batch/resources/views/emails/mjml/alert/alert.blade.php
  - iznik-batch/database/migrations/2026_10_24_000001_alert_require_reply_and_attachments.php
  - iznik-batch/database/migrations/2026_10_24_000001_alert_require_reply_and_attachments_migration.sql
  - iznik-nuxt3/modtools/components/ModSupportContactGroup.vue
  - iznik-nuxt3/modtools/components/ModAlertReplyGate.vue
  - iznik-nuxt3/modtools/components/ModAlertHistory.vue
  - iznik-nuxt3/modtools/stores/alert.js
  - iznik-nuxt3/api/AlertAPI.js
  - iznik-server-go/test/alert_reply_test.go
---

# Contacting a community's volunteers (alerts)

Support and Admin use **Support, Contact** in ModTools to email the volunteers of one community,
or of every Freegle community. Each send is a row in `alerts`. `mail:alerts:send`
(`AlertService`) turns it into one `AlertMail` per moderator and owner, plus the community's
contact address, and a copy to the sender for a single-community alert.

## A member's email, in full

For a single-community alert the form takes the member's **original email**, pasted. It is stored
in `alerts.quoted` and shown in the mail under the message in a grey quoted block, escaped and
never trimmed. It is also shown to the moderator in the ModTools reply prompt below.

`GET /modtools/alert/:id` is public, so it never returns `quoted` or the attachments.

## Attachments

Up to five pictures or PDFs, 2.5MB in all, go in the create request as base64 and are stored in
`alerts_attachments`. The API refuses other types and cleans the file name. The limit is the API's
4MB request body, not a policy.

`AlertMail::attachments()` attaches them. The spool file (`EmailSpoolerService`) keeps any
attachments as `attachments` (name, mime, base64) and `processSpool()` wraps the body with them on
send, because setting a body by hand stops Symfony adding attachments itself. Every mailable gets
this; nearly all have none.

## A required reply

A single-community alert can set `requirereply`. The quote, the attachments and this are
refused for an all-communities alert, in the API and in the form.

A moderator or owner of that community who has not replied sees a **modal on every ModTools page**
(`ModAlertReplyGate`, mounted in the ModTools layout, polling `GET /modtools/alert/pending` every
minute). It cannot be dismissed. It shows the message, the member's email if there is one, and a
reply box.

- **Send reply** posts `POST /modtools/alert/:id/reply`. The reply goes to the person who sent the
  alert as a chat message, and `alerts_acknowledgements.replied` is set.
- **Sleep for 30 minutes** posts `POST /modtools/alert/:id/snooze`. Each moderator gets this once per
  alert. When it ends the prompt returns without the button.
- The prompt stops after **14 days**, or when Support or Admin presses **Stop requiring replies** in
  the history (`POST /modtools/alert/:id/cancel`, which sets `alerts.cancelled`).
- The person who sent the alert is never asked, even if they moderate the community.

The sender sees how many have replied in the alert's stats.

The mail also says a reply is needed and links to ModTools.
