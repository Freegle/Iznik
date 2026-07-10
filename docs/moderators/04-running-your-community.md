---
last_reviewed: 2026-07-09
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/settings/**
  - iznik-nuxt3/modtools/pages/members/stories.vue
  - iznik-nuxt3/modtools/pages/communityevents/**
  - iznik-nuxt3/modtools/pages/admins.vue
  - iznik-nuxt3/modtools/pages/logs.vue
  # cross-stack behaviour tests (change when the behaviour changes)
  - iznik-nuxt3/tests/e2e/test-modtools-settings-modconfig.spec.js
---

# Running your community

Beyond the daily queues, ModTools lets you configure how your community works and look
after its wider life: stories, events, broadcasts and more.

## Community settings

![Community settings](assets/settings.png)

**Settings** (`/settings`) is where you configure a community (you need to be an Owner or
Moderator of it). It is a set of sections, each covering one topic:

- **Addresses** - the community's moderator email and short links.
- **Your settings** - your own active and notify preferences for this community.
- **How it looks** - appearance, logo and welcome.
- **Rules** - the local rules members see, and toggles for common cases (for example
  waste carriers, car boot sales, certain hazardous items).
- **Features for members** and **Features for moderators** - including "quicker chat
  review".
- **Micro-volunteering**, **Spam detection** and **Duplicate detection** tuning.
- **Mapping** - the community boundary, with a link to the map.
- **Social media** and **Status**.

Keep local rules short, apply them kindly, and do not try to enforce them on other
communities. A homepage with a logo, a tagline and a warm welcome message helps new
members settle in.

## Standard message sets

The **Standard Messages** tab under Settings is where you build the canned messages used
across the queues. A set of messages is a "config" that can be shared across several
communities and locked by whoever created it, and you can see who else is using a given
set.

Freegle provides plenty of starting-point messages for common situations, and a library
of whole-community **broadcast** templates (for example bank holidays, keeping it local,
no-shows, too many emails, safety, reselling). Adapt them to your own voice.

## Stories and newsletters

- **Stories** (`/members/stories`) is a queue of member-submitted "why I freegle"
  stories. Review, edit lightly, and approve or decline them.
- **Newsletter** (`/members/newsletter`, needs the Newsletter permission) reviews stories
  destined for the newsletter specifically.

## Events and volunteering

- **Community events** (`/communityevents`) - review member-submitted local events, with
  Approve, Edit or Delete. Approved events appear to members and feed the weekly event
  roundup email.
- **Volunteering** (`/volunteering`) - the same review pattern for volunteering
  opportunities, including opportunities fed in from partners.

## Broadcasts to the whole community

**ADMINs** (`/admins`) sends a message to the whole community. You can create an
**Essential** message (which members cannot opt out of) or a **Newsletter** message
(which they can), optionally with a call-to-action button. Admins and Support can target a
single community or suggest copies to many communities that each community then edits and
approves. Use these sparingly and keep them warm.

## Logs and maps

- **Logs** (`/logs`) is a searchable audit trail of moderation, tabbed by Messages and
  Members and filterable by community and free text. It is where you check "who did what".
- **Map** (`/map`) shows a community's boundary and overlaps, all your communities
  together, or "caretaker" communities that currently have no active moderators.

## Rippling explorer

**Rippling** (`/rippling`) is an interactive map and analytics tool showing how posts
ripple between neighbouring communities. It needs a moment to warm up its backing spatial
service. It is useful for understanding why a post turned up where it did.

## Specialist tools (permission-gated)

- **Gift Aid** (`/giftaid`, Gift Aid permission) - search Gift Aid declarations and their
  linked donations.
- **Freegle Helper / Clearances** (`/helper-escalated` and the helper flow, Clearance
  permission) - the queue for the AI concierge that manages replies to bulk clearance
  offers, plus a read-only explainer of how it works.

## Support and sysadmin tools (Support and Admin only)

These pages are only available to volunteers with the Support or Admin system role:

- **Sysadmin** (`/sysadmin`) - housekeeping, cron jobs, outgoing and incoming email,
  scrolling and click-through analytics, and rippling analytics.
- **Support** (`/support`) - look up a user, community or message, the AI Support Helper
  (work in progress), and spam keyword configuration.
- **Images** (`/images`) - review and regenerate AI-generated item images that volunteers
  have flagged.

## Partner platforms: TrashNothing and LoveJunk

Freegle works with two partner platforms, and their users turn up in your community. The
one thing to remember is simple: **treat these members exactly like any other** - approve,
chat and moderate them as normal.

- **TrashNothing** is another app people can use to read and reply to Freegle posts. Someone
  who joined through TrashNothing appears as an ordinary member and posts and replies just
  like anyone else. A few of their settings (some email and notification preferences) are
  managed by TrashNothing rather than Freegle, so those options may be hidden on their
  member panel.
- **LoveJunk** is a reuse marketplace that Freegle shares OFFERs with, to help more items
  get taken. By default your community's OFFERs are also shown on LoveJunk, and LoveJunk
  users can reply through the normal chat. Those members show a "LoveJunk user" note on
  their panel, and their chat messages are kept in step between the two sites.

Whether your community shares posts with TrashNothing and LoveJunk is a community setting.
There is technical detail for the curious in [../developers/reference/trashnothing.md](../developers/reference/trashnothing.md).

## Next steps

- Back to the daily work: [Moderating posts](02-moderating-posts.md) and
  [Managing members](03-managing-members.md).
- New to ModTools? Start with [Getting started](01-getting-started.md).
