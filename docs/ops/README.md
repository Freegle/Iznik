---
last_reviewed: 2026-07-09
owner: Freegle dev team
---

# Operations

This section describes how Freegle is **currently organised operationally**: how it is
deployed, monitored, backed up, and kept running. It is written at an **architecture and
process level**.

> ## The rule for this section
>
> **Nothing confidential goes here.** No secrets, credentials, API keys, tokens, IP
> addresses, or private hostnames. If a fact cannot be stated without one of those, state
> the shape of it and point to the internal source instead. When in doubt, leave it out.
>
> Secrets live in gitignored `.env*` files and a `secrets/` directory, never in docs.

## Contents

1. **[Overview and environments](01-overview-and-environments.md)** - the environments
   Freegle runs in and how they relate.
2. **[Deployment and CI](02-deployment-and-ci.md)** - how code gets from a commit to
   production, including the mobile apps.
3. **[Monitoring and logging](03-monitoring-and-logging.md)** - how we see what is
   happening and find problems.
4. **[Domains, services and runbooks](04-domains-services-and-runbooks.md)** - the
   public services, the spatial and mail stacks, backups, and operational runbooks.

The single best technical reference for the container and environment architecture is
[../../ARCHITECTURE.md](../../ARCHITECTURE.md). These pages summarise the operational shape
and link to it rather than duplicating it.
