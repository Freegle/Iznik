---
last_reviewed: 2026-09-02
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
5. **[Production topology](production.md)** - the live deployment: what the machines
   are, their roles, and what routes where.

## Reference

| Topic | Doc |
|-------|-----|
| What the sysadmin job actually involves | [reference/sysadmin-duties.md](reference/sysadmin-duties.md) |
| Spam and abuse handling | [reference/spam-and-abuse.md](reference/spam-and-abuse.md) |
| Logging and observability | [reference/logging.md](reference/logging.md) |
| Database read/write split | [reference/database-read-write-split.md](reference/database-read-write-split.md) |
| Index hygiene and schema parity | [reference/database-index-hygiene.md](reference/database-index-hygiene.md) |
| CircleCI setup and the self-hosted runner | [reference/circleci.md](reference/circleci.md) |
| Runbooks | [runbooks/README.md](runbooks/README.md) |

New to the role? Read
[../handover/03-sysadmin-track.md](../handover/03-sysadmin-track.md) first - it puts these
pages in order as a first day, first week and first month.

The single best technical reference for the container and environment architecture is
[../developers/reference/architecture.md](../developers/reference/architecture.md). These pages summarise the operational shape
and link to it rather than duplicating it.
