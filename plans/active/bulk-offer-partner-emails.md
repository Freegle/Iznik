# Bulk Offer — Draft partner emails

Advance-notice drafts for the three outbound integrations, ahead of the bulk
("clearance") offer rollout (PR #618, branch `feature/bulk-offer-clearance`,
not yet merged). Background and the fan-out scheme: see
[`bulk-offer-partner-integrations.md`](bulk-offer-partner-integrations.md).

A bulk offer is one normal `Offer` message carrying many items. All three
partners assume one post = one item, so each needs to know what's coming.

---

## To: Freebie Alerts
**Subject: Heads-up: new "bulk clearance" listings coming to the Freegle feed**

Hi [name],

We're about to introduce a new kind of OFFER on Freegle: a **bulk "clearance"**
post, where a single offerer (typically a charity or office clearance) lists
many distinct items — name, quantity, condition, photos — in *one* listing
instead of dozens of separate posts.

Technically these are still normal `Offer` posts, so they'll keep flowing to
your `/freegle/post/create` endpoint as today. The thing to be aware of: **one
post will now represent many items**. With no changes on your side, you'd
receive a single freebie whose `title` is the general offer (e.g. "Office
Clearance"), whose `description` summarises all the items, and whose `images`
field contains the photos of *all* the items concatenated.

We can handle this two ways and wanted your view:

1. **We fan it out for you** — we send you one create per item, each with its
   own title and its own photos, so they look like normal single-item freebies
   and you need no changes. The only wrinkle is the post `id`: we'd give each
   item a distinct id from a reserved range, and we'd make those ids resolve to
   the listing on our site. *Do you build the clickthrough link from the `id`
   (e.g. ilovefreegle.org/message/{id}), or store a URL we give you?* That
   determines what we need to do so links don't break.
2. **You consume them as one** — we add an `items` array to the payload
   (name/qty/condition/photos per item) and you render them however suits you.

No action needed yet — this is a heads-up while it's still on a branch. Which
approach works better for you, and is there anything about per-item photos or
counts you'd want in the payload?

Thanks,
[you]

---

## To: Love Junk
**Subject: Heads-up: new multi-item "clearance" listings on Freegle — will affect the drafts feed**

Hi [name],

A quick but important heads-up. We're adding a **bulk "clearance"** listing type
to Freegle: one OFFER that contains many distinct items (name, quantity,
condition, photos each) — aimed at charities/offices clearing lots of items at
once.

These remain normal `Offer` posts, so they'll continue to POST to
`/freegle/drafts` as now. **But** because our current payload sets `title` from
the *first* item only, a bulk listing would currently reach you as a single
draft showing just one item's name — the rest would be invisible to Love Junk.
We don't want that, so before this ships we'd like to agree the right approach:

1. **We fan it out** — we send you one draft per item (each with its own
   `freegleId`, title and images), so they appear as normal single-item offers
   and you need minimal/no changes. This needs a couple of things on our side
   (distinct ids per item, and routing your reply callback back to the specific
   item), which we're happy to build.
2. **You consume a multi-item payload** — we extend the draft body with an
   `items` array and you handle them your end.

One thing to flag for option 1: fanning out multiplies the post count, which
feeds into the monthly revenue split we calculate with Trash Nothing. We'd want
to agree how clearance items are counted before turning it on.

This is still on a branch — nothing changes today. Which option suits you, and
how would you prefer the count/accounting to work?

Thanks,
[you]

---

## To: Trash Nothing
**Subject: New multi-item "clearance" post type on Freegle — API impact**

Hi [name],

Giving you early notice of a new listing type before it ships. We're adding
**bulk "clearance"** offers: a single Freegle `Offer` message that carries a
structured catalogue of many items (each with name, quantity, condition,
dimensions and its own photos), with per-item interest tracking. The motivating
case is large charity/office clearances (e.g. ~120 items) that today become
~120 separate posts.

Importantly, **these are still normal `Offer` messages** — same `type`, same
`/api/changes` → `/api/message/{id}` flow you already poll. The `subject`,
`textbody` (which carries a readable summary of all items), `attachments` and
`availableinitially` (now the *total* across items) all stay populated, so
nothing breaks. But the existing single `item:{id,name}` can't represent a
multi-item catalogue, and the flat `attachments[]` won't tell you which photo
belongs to which item.

To support these properly, the V2 message payload **now includes a `bulkitems`
array** on `GET /api/message/{id}`:

```
bulkitems: [ { id, position, name, quantity, condition, dimensions, description,
               attachments: [...],   // photos for THIS item
               interestcount, interestedquantity } ]
```

Since you already consume this exact API, the cleanest path is for you to read
`bulkitems` when present (a post is "bulk" if the array is non-empty) and
represent items individually your side. Per-item write-back (promise/outcome on
a specific item) would map to our new `BulkInterestState` action — happy to walk
through that.

If consuming `bulkitems` is awkward for you, the alternative is that we
synthesise one virtual single-item post per item on our side, but that's more
involved across reads and write-backs, so we'd lean towards you reading the
array natively if you're up for it.

Nothing is live yet — it's on a branch (PR #618). Could we grab 20 minutes to
agree the approach and what you'd need in the payload?

Thanks,
[you]
