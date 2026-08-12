---
last_reviewed: 2026-07-09
owner: Freegle dev team
---

# Runbooks

Short, non-sensitive summaries of what to do for recurring operational events. The
detailed, host-specific steps (which reference internal hosts and are therefore not
public) are maintained in the ops team's operational notes; these summaries describe the
shape so anyone can understand the impact.

## Background-host reboot

The background host runs the batch (Laravel) scheduled jobs, log aggregation, and the
local spatial, routing, geocoding and email-rendering (MJML) services.

- **Stays up during the reboot:** the member site, the database and the application APIs,
  which are hosted separately. Members and moderators keep using Freegle.
- **Pauses during the reboot:** the batch crons (digests, notifications, reposts), and the
  locally hosted spatial, routing, geocoder and MJML services.
- **After the reboot:** work through the verification checklist - confirm the batch
  scheduler is running, the spatial and routing services have finished their in-memory
  rebuild (several minutes), and email rendering and log shipping are flowing again.

Because the spatial and routing services rebuild their graph on start, expect a few
minutes before rippling, browse ordering and the digest are fully back.

## Edge-tier change

Changes to the production-facing "edge" services (map tiles, wiki, image delivery) are
being brought into the same Compose stack as batch processing, one step at a time.

- Each step (adopting a container into the stack, retiring a superseded service, removing
  a stale reverse-proxy entry) is designed to be **independently reversible** and
  **human-gated**.
- Roll changes forward one at a time and verify each before the next, so any single step
  can be undone cleanly.

## Deployment spend optimisation

A recurring, mostly disk-focused sweep to keep hosting spend down: reclaim wasted space,
right-size volumes, and move data to a cheaper storage class. It covers the estate-wide
audit, the waste patterns that keep recurring, the storage cost model, and the safety
gates (Galera rules, the "do not full-prune the edge host" trap, and verifying the mail
host after any change).

- Full steps: **[deployment-spend-optimisation.md](deployment-spend-optimisation.md)**.
- Run roughly monthly, or when a host crosses ~75% disk use.

## Annual AGM category on Discourse

Once a year the AGM gets its own Discourse category, which every user is put on
"Watching" so the announcements reach them. Set up, announced and closed with the
`discourse:agm` artisan command, run by hand in three separate steps.

- Full steps: **[agm-category.md](agm-category.md)**.
- The steps are separate on purpose: switching Watching on before the information
  posts exist means every draft notifies the whole forum.

## Adding a runbook

Keep summaries here **non-confidential**: describe impact, what pauses, what stays up, and
the verification shape. Put host names, IPs and credentials in the internal operational
notes, never here.
