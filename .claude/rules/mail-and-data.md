---
paths:
  - "iznik-batch/app/Mail/**"
  - "iznik-batch/app/Services/*Digest*"
  - "iznik-batch/resources/views/**"
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

## See also

- `docs/developers/reference/unsubscribe.md` - the intended unsubscribe behaviour.
- `.claude/rules/rippling.md` - why a shared body field matters more than it looks.
