---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Deployment spend optimisation (recurring)

A periodic sweep to keep hosting spend down. Storage is a large part of the monthly
bill, so most of the wins are disk related: reclaiming wasted space, right-sizing
volumes, and moving data to a cheaper storage class.

Run this roughly **monthly**, or whenever a host crosses ~75% disk use. It is written so
an AI agent or an engineer with SSH access to the estate can repeat it. Work through it
top to bottom: audit first (read-only), then apply the safe cleanups, then surface the
bigger changes that need a human decision.

## Safety rules (read before doing anything)

- **Audit read-only first.** Nothing destructive until you have looked at the target and
  understood what it is. If what you find contradicts how it was described, stop and say
  so.
- **Discover hosts at runtime. Do not hardcode them here.** The estate host names live in
  `/etc/hosts`, the SSH config and the deploy env on the primary host. This page is
  public, so it names hosts only by role. Never commit host names, IPs or credentials
  into `docs/`.
- **Irreversible deletion of member or community content needs explicit sign-off.** Cold
  archive anything small and irreplaceable (a database dump, a backup tarball) off the
  host before deleting the directory it lives in.
- **The database is a Galera multi-master cluster.** Prune rows one at a time, never in
  bulk (large writes break replication). Never run schema migrations just to reclaim
  space. Table files only shrink on a careful rebuild, which is a separate, gated task.
- **Never break mail.** After any change to the mail-sending host - and especially after
  a reprovision - verify mail is flowing and the sending identity is intact (see
  "Verifying the mail host" below).
- **Some "reclaimable" space is a trap.** On the primary Docker/edge host, do **not**
  `docker image prune -a`. Many images there back production containers that are only
  rebuilt occasionally; pruning them forces slow rebuilds or breaks a deploy. Full-image
  prune is only safe on a single-purpose host where you have confirmed what runs.

## Step 1 - Estate-wide disk audit (read-only)

Enumerate the hosts, then run the same probe on each. Fan out in parallel; cap each
command with `timeout` because `du`/`find` on large trees is slow.

Per host, gather:

```
df -h -x tmpfs -x devtmpfs -x overlay          # filesystems and % used
du -x --max-depth=1 / | sort -rn | head         # biggest top-level dirs
du -x --max-depth=1 /var/log | sort -rn | head  # log breakdown
docker system df                                # images / volumes / build cache (if docker)
journalctl --disk-usage                         # journald size
find / -xdev -type f -size +800M -printf '%s\t%p\n' | sort -rn | head   # large files
```

Also list Docker detail where present: `docker ps -a`, `docker images`, and per-volume
sizes under the Docker volumes directory (these are often the largest single consumer on
a container host).

Write one short report per host so the findings are comparable.

## Step 2 - Recurring waste categories

These are the patterns that come back. For each, the action and its risk level.

| # | Pattern | Where it shows up | Action | Risk |
|---|---------|-------------------|--------|------|
| 1 | **App access logs kept for weeks and also shipped to Loki** | Database/API nodes; the Go API access log can reach tens of GB | Shorten local retention (the log ships to Loki, so local copies are redundant); find out why one node logs far more than its peers | Low |
| 2 | **journald uncapped** | Most hosts default to multi-GB | Set `SystemMaxUse` (e.g. 500M) in `journald.conf`, restart journald | Low |
| 3 | **Orphaned log directories with no rotation rule** | Legacy per-service log dirs that no config rotates, so they grow forever | Confirm nothing has them open (`lsof +D`) and the files are stale (`stat` mtime), then remove | Low, after the two checks |
| 4 | **Docker build cache and dangling images** | Any container host | `docker builder prune`; remove dangling images | Low |
| 5 | **Images of a retired service** | A single-purpose host after a service is dropped (its container is exited long-term) | Remove the exited container, then the images | Low on a single-purpose host; **never** `prune -a` on the edge host |
| 6 | **A stale duplicate of a migrated service** | After a service moves hosts, the old copy may still be running and serving nobody | Prove the live instance is elsewhere (access-log recency on both), then retire the old containers and data | Medium - it is member/community content; archive first |
| 7 | **Database table growth** | Every table replicates to every node, so a GB saved multiplies by the node count | Identify high-growth tables (ripple reach geometry, per-message view/like counters, email open-tracking, bounces, the app `logs` table). Prune by age, one row at a time. Reclaiming file space needs a separate gated rebuild | Medium/high - Galera rules apply |
| 8 | **Oversized or wrong-class storage volumes** | The upload store and per-host data disks | Right-size, and pick the cheapest class that fits the access pattern (see Step 3) | Medium |
| 9 | **The upload store (tusd)** | Large and always growing | Candidate to move to object storage; also purge incomplete/abandoned uploads (uploads that were created but never received their bytes) | Medium |

## Step 3 - Storage cost model

Storage class is the biggest lever. Katapult (Krystal Cloud) list prices, which you
should re-check as they change:

| Class | Price | Billed on | Notes |
|-------|-------|-----------|-------|
| Block storage (NVMe) | ~£0.15/GB/mo | **Provisioned** | Boot/data disks. Grows but does not shrink in place - to make smaller, reprovision |
| File storage (all-flash, NFS) | ~£0.09/GB/mo | Used | What the upload store has used historically |
| Object storage (S3-compatible) | £5/mo base (incl. 250GB + 1TB egress), then ~£0.02/GB storage or egress | Used | **No per-request charge** - good for image serving. 5GB single object, 5TB with multipart |
| Backup storage (all-flash) | ~£0.05/GB/mo | Used | Only charged for what is used |

Consequences:

- **File storage bills on usage**, so there is nothing to "right-size" - it already
  tracks what you store. The saving comes from moving to a cheaper class, not shrinking
  the volume.
- **Object storage is much cheaper per GB above the base bundle** and has no per-request
  fee. For a large, growing store like uploads, moving from file to object storage is
  usually a clear win, and the gap widens as the store grows. `tusd` has a native S3
  backend, so it can write to object storage directly. An object-storage lifecycle rule
  to abort incomplete multipart uploads also cleans up abandoned uploads automatically.
- **Block/boot volumes bill on provisioned size and cannot shrink in place.** To make a
  host's disk smaller you reprovision it with a smaller disk. Do that only after the
  cleanup, and size for steady-state use plus headroom (for a mail host, allow for log
  growth). Verify the persistent data survived the reprovision.

## Step 4 - Applying cleanups

**Do without asking (safe, reversible or regenerable):**

- Docker build-cache prune; remove dangling images.
- Cap journald.
- Delete confirmed-orphaned rotated log files (Step 2 #3, after the open-handle and mtime
  checks).
- Remove a retired service's exited container and its images **on a single-purpose host**.

**Confirm with a human first (irreversible or high-impact):**

- Deleting member or community data directories, or retiring a duplicate service (archive
  the small irreplaceable parts first).
- Any database pruning (Galera - one row at a time; separate rebuild to reclaim file
  space).
- Resizing/reprovisioning a volume, or migrating a storage class.

## Step 5 - Verifying the mail host

If the sweep touches the mail-sending host, or the host was reprovisioned, confirm mail
still flows before moving on:

- Services up: the MTA, the DKIM milter and the spam filter are `active`; the MTA is
  listening on the SMTP port.
- Accepting: the MTA is taking new mail from the internal app senders.
- Delivering: recent log lines show successful delivery to the major providers.
- Queue draining, not growing: sample the queue size twice; check the age shape so you
  can tell an old backlog (days-old deferrals, full-mailbox and bad-address bounces) from
  a new problem.
- **Sending identity intact after a reprovision:** the outbound IP is unchanged, and
  forward/reverse DNS still align (the PTR record and the MTA HELO name match). A changed
  IP or broken rDNS silently wrecks deliverability even though mail appears to "send".

## Step 6 - Record the outcome

- Note per action: space reclaimed and estimated £/month saved.
- List the bigger changes that still need a human decision (database pruning, an
  object-storage migration, a host reprovision) so they are not lost.
- Bump this page's `last_reviewed` date.

## Related

- [Monitoring and logging](../monitoring-and-logging.md) - where logs go (Loki), which
  is why local access-log retention can be shortened.
- [Domains, services and runbooks](../domains-services-and-runbooks.md) - the service
  map, including the mail and spatial stacks.
