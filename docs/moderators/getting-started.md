---
last_reviewed: 2026-11-10
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/index.vue
  - iznik-nuxt3/tests/e2e/docs-screenshots.mjs
  - iznik-nuxt3/modtools/pages/discourse.vue
  - iznik-server-go/sso/discourse.go
  - iznik-nuxt3/modtools/layouts/**
  - iznik-nuxt3/modtools/composables/useMe.js
  - iznik-nuxt3/modtools/pages/teams.vue
  # cross-stack behaviour tests (change when the behaviour changes)
  - iznik-nuxt3/tests/e2e/test-modtools-login.spec.js
  - iznik-nuxt3/tests/e2e/test-modtools-dashboard.spec.js
  - iznik-server-go/test/discourse_sso_test.go
---

# Getting started as a moderator

## Getting into ModTools

ModTools lives at [modtools.org](https://modtools.org) and has its own app. It is a
separate application from the main Freegle site, but you log in with the same account.

- Go to modtools.org and log in. If you are not signed in, a login box opens
  automatically.
- Moderators are national: once you are a moderator, you see the same tools and the same
  members and posts as every other moderator. There is no community to pick, and nothing
  is filtered down to one.
- Support sometimes sends a one-click link that logs you straight into a specific member
  for help; that is what the `/login` page handles.

## The home page

![The ModTools home page](assets/home.png)

The home page is your daily starting point. It is built from nine lists, each showing
what Freegle already did and, where there is one, the one thing a moderator can add:

- **Just published** and **Taken down** - posts, and why. See
  [Moderating posts](moderating-posts.md).
- **Held chat messages** and **Messages to Freegle** - things waiting on a reply. See
  [Moderating posts](moderating-posts.md).
- **New members** and **Flagged members** - people, and what Freegle noticed about them.
  See [Managing members](managing-members.md).
- **Completed freegles** - posts marked Taken, Received or Withdrawn. See
  [Moderating posts](moderating-posts.md).
- **Events and volunteering** - what members have added. See
  [Moderating posts](moderating-posts.md).
- **Spammers** - the shared spammer list. See [Managing members](managing-members.md).

None of these is a queue you must clear. Freegle has already acted on everything in it;
you are looking for the exceptions the checks would not catch, or a decision worth a
second opinion.

## Roles and permissions

Your **system-wide role** - **User**, then **Moderator**, then **Support**, then
**Admin** - controls what you can see: Support unlocks the support tools, and Admin alone
the sysadmin tools (see [Other ModTools pages](README.md#other-modtools-pages)).

On top of that, some features are gated by specific **permissions** (for example
Newsletter, Spam admin, Gift Aid, Clearance), granted independently of your role. If you
cannot see a feature this guide mentions, you may not have that permission yet.

You can see Freegle's volunteer **teams** and who is on them under `/teams`. A team can
also unlock a page in its own right.

## Your personal settings

Under **Settings** there is a **Personal** tab for your own preferences as a moderator:
for example whether to get ChitChat email, and your notification and beep preferences.

## Talking to other moderators

The **Us** link in ModTools signs you into the volunteers' **Discourse** forum, where
moderators discuss issues, share advice and keep up with Freegle-wide news. For anything
urgent, `mentors@ilovefreegle.org` reaches experienced volunteers who can help.

The forum is for moderators only, so the sign-in checks that your account holds an owner or
moderator role on at least one community. If it does not - for example after you have stepped
down from your only community - the link shows a message saying so instead of signing you in;
ask an owner of your community to restore your role. If ModTools cannot verify your session,
it asks you to log out and log in again before trying the link again.

## When Freegle is in lockdown

If Freegle is dealing with a spam or phishing wave, you will see a red banner at the top
of every ModTools page, whatever community or tool you are looking at. It stays up for
every moderator until Support or Admin closes the incident, and it is separate from the
platform traffic light on the dashboard (see [monitoring and logging](../ops/monitoring-and-logging.md)).
Most of your day-to-day tools keep working; the ones that are held or refused are called
out in [Managing members](managing-members.md) and the [lockdown runbook](../ops/runbooks/lockdown.md).

## When Support needs you to reply

Occasionally Support, who run the support mailbox, need an answer from the volunteers of one
community before anything else happens, for example about a member who wrote to them. They can
mark their message as needing a reply. If you are a moderator or owner of that community you
will then see a box over every ModTools page, titled "The <community> volunteers need a reply".
It shows their message, and the member's original email if they included it, with a "Your
reply" box. You can't close it or carry on until you send a reply, which goes to whoever sent
the message as a chat. If you can't answer straight away, "Sleep for 30 minutes (once only)"
hides it for half an hour, once per message. It stops after 14 days, or when Support turn it
off. See [contacting a community's volunteers](../developers/reference/contact-group-alerts.md).

## Next steps

- The core of the job: [Moderating posts](moderating-posts.md).
- Looking after people: [Managing members](managing-members.md).
- Everything else ModTools does: [README](README.md#other-modtools-pages).
