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

## Readers with no writer, left behind by the V1 removal

Removing the old PHP stack took several tables' only writer with it, while readers stayed. The
symptom is a join that silently matches nothing, forever, with no error anywhere.

At least three tables ended up in that state. Before relying on a table nobody has touched
recently, check when it was last written:

```sql
SELECT MAX(timestamp) FROM <table>;
```

A maximum that stops near the V1 removal date means the writer left with it.

## See also

- `docs/developers/reference/unsubscribe.md` - the intended unsubscribe behaviour.
- `.claude/rules/rippling.md` - why a shared body field matters more than it looks.
