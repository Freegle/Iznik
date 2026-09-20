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
| `longq2.sh` | full text of anything running ≥10 s, EVERY sighting (see below) |
| `analyse.mjs` | ranks a TSV by mean concurrent threads, by source host and by query shape |

## Why processlist and not the digest table

On **db2** `events_statements_summary_by_digest` sees 14.5% of the work: Laravel's prepared
statements land in `statement/com/Execute` with no digest text. Ranking db2 from it is wrong.

On **db3** it is the other way round — apiv2's Go driver sends text protocol, so the digest table
is complete and covers the whole uptime. Prefer it there; it cannot be skewed by a transient.

## The sampler already answers "how long did that take"

`pl-sampler.sh` records every poll, so `max(time)` per `(connection id, host)` is a **true**
duration for every statement it saw — not a sample of one. Before building anything to measure
query durations, run this over its files:

```
zcat db-2-YYYYMMDD.tsv.gz; cat db-2-YYYYMMDD.tsv | awk -F'\t' '
  NF>=9 && $6+0>=5 && $9!="" { k=$2":"$4; if ($6+0>mx[k]) mx[k]=$6+0; shape[k]=substr($9,1,40) }
  END { for (k in mx) { s=shape[k]; n[s]++; sum[s]+=mx[k]; if (mx[k]>m[s]) m[s]=mx[k] }
        for (s in n) printf "%5d %5d %7d  %s\n", n[s], m[s], sum[s], s }' | sort -k3 -rn
```

Split the input on a timestamp to compare before and after a change. That is how #1542 and #1543
were verified: both fixed queries went from the top of the slow list to **absent**.

Two capture scripts were written and debugged before anyone asked the sampler, and the first of
them measured the wrong thing for four hours.

## A capture keyed on connection id records a floor

If a capture writes one row per connection id and skips that id afterwards, the duration it stores
is whatever the statement had reached when a poll first caught it above the threshold. A query the
CPU watch saw at 43 s appeared in such a file at 19 s, and a duty cycle derived from it was low by
more than half. Append **every** sighting and take the max — that is what `longq2.sh` does, and it
is why its rows for one statement climb (12 s, 17 s, 22 s).

Validate any such capture by running a deliberately slow statement (`SELECT SLEEP(14), 'probe'`)
and confirming it appears. Background it with `nohup`, or ssh will kill it before it gets slow —
the first attempt at this reported a working capture as broken.

## mysqld is not the node

db2 and db3 also run `iznik-spatial-go` and `iznik-routing-go`. During a dataset rebuild spatial
takes **several cores**, and a watch that measures mysqld alone reports a quiet node while the box
is nearly full:

```
db-2 node=7.26/8 mysqld=1.90 load=9.98    <- 5.4 cores are NOT mysqld
```

The tell is a load average that makes no sense against the CPU figure. Check `b` and `wa` in
`vmstat` first: if they are zero, the load is runnable work, so something is burning CPU that you
are not measuring. `ps -eo pcpu` will not find it either - that column is a LIFETIME average, so a
process 17 days old sitting at 480% reads as 3.9%. Use `top -bn2`, or compute the node from
`/proc/stat` as `dbpoll.sh` now does.

`ReachOverflowDataset` rebuilds every 24 h (`RebuildInterval()`), reading ~30,000 rows with ten
`JSON_EXTRACT` calls each and rasterising the rings. That is by design and the delta path between
rebuilds is 2-minutely and cheap - but it is why db2 reads as busy for a few minutes a day with
mysqld nearly idle.

## Your own shell matches your own search

Any `pgrep -f`, `pkill -f`, `grep -c`, or shell `case` pattern containing the string you are
looking for **also matches the process doing the looking**. This cost real time in one session:

- `pkill -f "timeout 16h"` killed the shell running it, along with the watchers.
- `grep -cF overnight-digest-watch.sh` reported 6 watchers when there was 1.
- A `case "$c" in *purge:chats*)` loop reported the job "still running" ten minutes after it ended.

Match on the executable instead, which your shell cannot satisfy:

```
for p in /proc/[0-9]*; do
  case "$(readlink $p/exe 2>/dev/null)" in */php) ... ;; esac
done
```

Or check parentage before believing a count: a second PID with the first as its parent is a
command-substitution subshell, not a duplicate.

## Three things that will bite

- `mysql -B` escapes tabs and newlines **in the data**, so split on the two-character `\t`.
- MySQL strips `/*comments*/` from `processlist.INFO` — a sampler cannot tag its own row with one.
  Match on the query text instead.
- Replaying a captured statement into `EXPLAIN` after flattening its newlines truncates it at the
  first `--` comment, with a syntax error that points at the very end. `longq2.sh` stores newlines
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
