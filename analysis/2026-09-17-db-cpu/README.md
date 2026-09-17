# DB CPU profiling harness

Used for `plans/2026-09-17-db2-db3-cpu.md`, and the same method as
`plans/2026-09-02-db2-cpu-reduction.md`.

Copy the three scripts to `/root/` on a db node and start each with `setsid … &`. They write to
`/root/plsample/`. Pull the TSVs back and rank them with `analyse.mjs`. **Do not commit the TSVs**
— they contain member ids and reach polygons.

| script | what it does |
|---|---|
| `pl-sampler.sh` | samples `information_schema.processlist` ~20×/s in bursts, one long-lived connection |
| `cputrack.sh` | per-minute mysqld CPU and load, so "how busy was it at 02:00" is answerable afterwards |
| `longq.sh` | full text of anything running ≥45 s, once per connection id |
| `analyse.mjs` | ranks a TSV by mean concurrent threads, by source host and by query shape |

## Why processlist and not the digest table

On **db2** `events_statements_summary_by_digest` sees 14.5% of the work: Laravel's prepared
statements land in `statement/com/Execute` with no digest text. Ranking db2 from it is wrong.

On **db3** it is the other way round — apiv2's Go driver sends text protocol, so the digest table
is complete and covers the whole uptime. Prefer it there; it cannot be skewed by a transient.

## Three things that will bite

- `mysql -B` escapes tabs and newlines **in the data**, so split on the two-character `\t`.
- MySQL strips `/*comments*/` from `processlist.INFO` — a sampler cannot tag its own row with one.
  Match on the query text instead.
- Replaying a captured statement into `EXPLAIN` after flattening its newlines truncates it at the
  first `--` comment, with a syntax error that points at the very end. `longq.sh` stores newlines
  as `@@NL@@` for this reason; restore them before replaying.

## On a saturated node, rank by statement time — not by thread share

`analyse.mjs` reports mean concurrent threads because that is the right headline for a node with
headroom. It is the WRONG headline for a node that is full.

A thread count measures how long a client sits **waiting**, not how much CPU it uses. When a node is
pinned, everyone queues, every client's thread count inflates, and the node flatters whichever
caller is queueing worst. On db2 at 98% this overstated one caller's share by 2x — 27.9% by thread
share against 14.4% by statement time — and an early draft of the plan proposed a production change
on the strength of it.

So: if `cpu-<host>.tsv` shows mysqld near the core count, rank from
`events_statements_summary_by_digest` (db3) or from the plain/prepared split in
`events_statements_summary_global_by_event_name` (db2), and use thread share only to identify
WHICH queries are stuck, not how much they cost.

The two failure modes this harness exists to avoid are now both on record: the 2026-09-02 round was
burned by a post-deploy transient, and the 2026-09-17 round was nearly burned by a queue.

## Denominator

Every poll emits at least one row (the sampler's own connection), so idle polls still count.
`analyse.mjs` reports **mean concurrent threads** as the headline, not share: a share alone hides
whether the node got busier.
