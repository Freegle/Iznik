---
paths:
  - "iznik-batch/app/Mail/**"
  - "iznik-batch/app/Services/*Digest*"
  - "iznik-batch/app/Services/Mail/**"
  - "iznik-batch/app/Console/Commands/TrashNothing/**"
  - "iznik-server-go/user/partner.go"
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

## `users_emails.backwards` cannot be filtered on and be complete

The column exists so domain search has an index: reverse an address and a domain suffix becomes
a prefix. It holds three different things, and all three are live. For
`i9-g4707@user.trashnothing.com`:

| Written from | Value | TN rows on production |
|---|---|---|
| the address | `moc.gnihtonhsart.resu@7074g-9i` | 450,846 |
| `canon` (strips the `-gNNNN` suffix **and** the dots in the domain) | `mocgnihtonhsartresu@9i` | 1,752,575 |
| nothing at all | `NULL` | 13,772 |

The split is not historical - the id ranges overlap almost exactly - so it is per code path, and
it is not confined to Trash Nothing: gmail is 105,308 dotted against 252,338 stripped, yahoo
162,201 against 128,001.

So **a prefix test on `backwards` silently returns a fraction of the rows**, and no set of
prefixes reaches the NULLs at any price. `tn:sync`'s duplicate-account merge filtered on one
prefix from 2026-05-14 (5e2a90450) and its visibility fell to 20%. Duplicate Trash Nothing
accounts then piled up for three months - 96 live pairs - while the check reported finding
"roughly zero a day", which reads like success. The first symptom to reach us was a partner API
403. Every test it had built `backwards` as `strrev($email)`, the one form the filter matched,
so the suite stayed green throughout.

**The index is not worth the incompleteness, because it is not being used.** EXPLAIN on
production picks a full scan for every form of that filter - each prefix matches far too much of
the table for a range scan to win. Measured 2026-09-19 over 4.2M rows: `backwards` 3.3s finding
94 of the 96 split usernames, `email LIKE '%@user.trashnothing.com'` 5.1s finding all 96. Filter
on the address and group in PHP; that is where 5e2a90450's real speedup came from anyway.

## Go's CanonicalizeEmail is not PHP's canonicalizeEmail

They share a name and a purpose and agree on almost nothing:

| | PHP `IncomingMailService::canonicalizeEmail` | Go `user.CanonicalizeEmail` |
|---|---|---|
| TN `-gNNNN` suffix | stripped | kept |
| dots in local part | stripped for gmail/googlemail only | stripped for every domain |
| dots in domain | stripped | kept |
| googlemail -> gmail | yes | no |

`canon` is the column both write and both match on, so the disagreement is silent and one-way: a
row Go writes cannot be found by a PHP canon lookup, and vice versa. Go writes `canon` in
`CreatePartnerUser`, `EnsurePartnerIdentifiers`, social auth and signup. PHP's `findUserByEmail`
canon fallback is what stops a member's second address minting a second account, so a row with a
Go-written canon has no such protection.

Measured on production 2026-09-19, of 4,476,251 `users_emails` rows, the ones a PHP canon lookup
cannot find are:

| | Rows | Worth fixing |
|---|---|---|
| canon holding the address unchanged, outside Trash Nothing | 51,546 | yes |
| canon holding the address unchanged, Trash Nothing | 41,803 | done at the partner write sites |
| no canon, a real mailbox | 4,010 | yes |
| no canon, a `@users.ilovefreegle.org` proxy address | 136,047 | no - matching one member to another has no meaning for these |

About a thousand a day are added to the first group, so it grows. The practical effect is that
duplicate prevention is weaker for those addresses than for the 94.8% of the table that is
PHP-shaped, not that anything breaks loudly.

Aligning them is not a rename: donation matching and social auth both look up on `canon`, so
changing the general Go function changes who those match. Fix it at the specific site and say
which semantics you mean.

## See also

- `docs/developers/reference/unsubscribe.md` - the intended unsubscribe behaviour.
- `.claude/rules/rippling.md` - why a shared body field matters more than it looks.
