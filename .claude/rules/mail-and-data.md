---
paths:
  - "iznik-batch/app/Mail/**"
  - "iznik-batch/app/Services/*Digest*"
  - "iznik-batch/app/Services/Mail/**"
  - "iznik-batch/resources/views/**"
  - "scripts/bulk2/**"
  - "ops/hosts/mail-host/**"
---

# Traps in mail, digests and the data behind them

## A digest cursor that moves past posts it never sent

The digest tracker is updated on **every** exit path, including the two that end in "nothing to
send" after filtering and after removing duplicates, and it sets the cursor to the newest
arrival **examined**. The next run then windows on arrivals after that point.

So a post that was filtered out this run is behind the cursor next run and is never considered
again. Anything that examines posts and then advances a watermark has to advance it over what it
**sent**, not over what it looked at.

## Unsubscribe has two arms and both have been broken at once

A member clicked unsubscribe on a digest, believed it worked, got an auto-reply saying the
mailbox is not monitored, and was still fully subscribed an hour later. Both the one-click header
and the mail-back route failed independently.

When testing an unsubscribe change, check the member's subscription state in the database
afterwards. The member-facing confirmation proves nothing about what was written.

## The Reply button in a digest is not a link to the site

In Gmail's app the digest's Reply opens an AMP drawer inside the mail client. The website is
never reached. A report of "Reply gives a blank screen, but the link at the top works" is
therefore not a website bug, and looking for one in the site's error tracker sends you to an
unrelated issue. Establish which of the two paths the member actually used before diagnosing.

## Post body text is shared, so a per-group footer is not per group

Some communities apply a standard message at approval, and the text is written into the single
shared body field rather than a per-group one. It is not a product feature and there is nothing
in the code to grep for. On a rippled post it therefore travels everywhere, and it can dominate
the body text.

Anything that reasons about post content, including matching and search quality, has to expect
this.

## A processing flag that silently stops mail

Chat replies with the processing flags in one particular combination are never emailed to the
offerer at all. It is a small share of messages, but to the member it is total: they replied and
the other person never heard. It was misdiagnosed as a rippling problem more than once, because
rippling was the recent change.

Before blaming the newest subsystem for undelivered mail, check whether the message was ever
queued.

## Readers with no writer, left behind by the V1 removal

Removing the old PHP stack took several tables' only writer with it, while readers stayed. The
symptom is a join that silently matches nothing, forever, with no error anywhere.

At least three tables ended up in that state. Before relying on a table nobody has touched
recently, check when it was last written:

```sql
SELECT MAX(timestamp) FROM <table>;
```

A maximum that stops near the V1 removal date means the writer left with it.

## Background work that overlaps itself

The batch lock gives **no** overlap protection for work dispatched to the background: the lock
is released when the dispatching process ends, not when the work finishes. Two runs of the same
job can therefore be in flight at once.

Removing the old PHP stack also deleted cron **writers** while leaving their readers, so whole
pipelines are dead rather than merely idle, and the loop that was supposed to report on
deprecated endpoints never ran at all.

## Before you write an analytics query

The likes table has tens of millions of rows and **no index on its timestamp or source**, so any
straightforward analytical query over it will not return. Check for an index before writing the
obvious query, not after it hangs.

Two more shapes worth knowing: the spatial index empties in large bursts because old rows are
deleted in batches, and image delivery no longer involves the old third-party service at all.

## Never load a large gazetteer into the towns table

It is used to anchor content to a nearest town, so enlarging it silently re-anchors existing
content. Use the places table for area coverage instead.

## The relay runs more than one postfix instance

Every postfix command that reads or writes a queue defaults to the **default instance only**.
The relay has a second one that owns delivery to the providers we deliberately pace, so a
command without `-c <configdir>` silently answers about the wrong mail - and the mail it omits
is exactly the mail you were asking about.

This has already bitten twice in one day. Read the queue with `postqueue -j` and you see a few
thousand messages for healthy domains while tens of thousands to a throttled provider sit unseen
in the other queue, so nothing ever crosses a suppression threshold. Measure with `qshape` and a
routed provider reads as zero queued, which is the one value that takes a group off its warmed
sending address.

**Queue ids are unique only within an instance.** `postsuper` against the wrong one deletes
nothing, or deletes a different message that happens to share the id. Anything that collects ids
must record which instance each came from and pass them back per instance.

After adding an instance, grep for every `postqueue`, `postsuper`, `qshape`, `mailq`, `postconf`
and `postmap` in the repo and on the relay, and answer "which instance does this mean?" for each.
`postmulti -l` enumerates them; `postmulti -i <name> -x <cmd>` targets one; `postmulti -i -` is
the default one; a bare `postmulti -x` hits all of them.

## The hop between those instances logs exactly like a delivery

The primary hands a paced provider to the second instance over SMTP on loopback, and that hop
writes a line which is indistinguishable from success at a glance:

```
postfix-relaywarm/smtp[...]: ABC: to=<someone@yahoo.com>,
  relay=127.0.0.1[127.0.0.1]:10026, ... status=sent (250 ... queued as DEF)
```

It reached our own second instance and nothing else. **Anything parsing the maillog must exclude
it.** Counted as a send it is worse than wrong, because it is unbounded - one per message - so it
always exceeds any count it is compared against, and it reads as good news the whole time:

- counted as "the primary is accepting this provider", every paced group looks healthy and gets
  taken off its warmed address and put back on one that is refusing it;
- counted as a delivery in the member-visible mail log, someone asking "did you email me?" is
  told their provider accepted a message it may not see for hours.

Exclude it on **two** independent signals - the transport's syslog tag and the loopback `relay=`
- so that renaming the transport or changing the port cannot quietly restore the fault. And make
the check fail closed: if messages are being routed over the hop but neither signal matches any
of them, the pattern has stopped working, so refuse to draw a conclusion that run.

A message crossing the hop is logged under **two** queue ids, one per instance. Correlate them
through the `queued as <id>` in the hop's own line.

## A line count is not a time window

`tail -n 200000 /var/log/mail.log` takes a number of LINES. How much time those cover depends
entirely on how busy the relay is, and it moves by the hour. The deferral probe sampled
deliveries that way and every figure derived from it was called "per hour": measured live, the
sample spanned 2h15m, so the rates were more than double the truth.

That number is a divisor. A backlog only means something next to the rate it is draining at, so
an inflated rate makes a queue look like it clears in two hours when it needs five - and the
error is invisible, because both numbers are plausible and neither is ever wrong in a way that
throws.

If you sample by lines, make the source tell you the span it actually covered and scale by it.
Resolve the timestamps where `date` can see them, on the host, rather than parsing year-less
syslog dates; when they will not parse, or the span comes out negative across a year boundary,
report nothing and fall back rather than guess.

The same count still feeds `mxgroup_max_delivered_per_hour` in the suppression decision
unscaled, which makes a provider look healthier than it is and so errs towards not suppressing.
Scaling it would change when real mail stops being generated, so it wants its own change.

## See also

- `docs/developers/reference/unsubscribe.md` - the intended unsubscribe behaviour.
- `.claude/rules/rippling.md` - why a shared body field matters more than it looks.
