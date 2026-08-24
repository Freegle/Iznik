# SEO — post indexation and image search

Design only. Nothing here has been implemented. Written 2026-07-27 in response to a
report that a Freegle post ("OFFER: Kitchen cupboard units (Moulton NN3)") surfaced on
Google via **Trash Nothing** rather than ilovefreegle.org, did not show on the
Northampton group page where it originated, and whose photos appear nowhere in Google
image search.

Everything below was verified against the live site and the production database on
2026-07-27, not inferred from the code.

## The reported post

`messages.id = 121054975`, subject `OFFER: Kitchen cupboard units (Moulton NN3)`,
date `2026-07-17 17:15:33`.

```
groupid | nameshort                  | arrival                    | collection | rippled_in
  21538 | Northampton-Freegle        | 2026-07-17 17:15:33        | Approved   | 0
  21684 | Wellingboroughfreegle      | 2026-07-17 17:16:21        | Approved   | 1
 126602 | KetteringUKFreegle         | 2026-07-17 17:16:21        | Approved   | 1
 253448 | rothwell-desboroughfreegle | 2026-07-18 06:02:16        | Approved   | 1
 253451 | freegle_seleicestershire   | 2026-07-22 13:01:01        | Approved   | 1
  21599 | RushdenHighamFreegle       | 2026-07-22 17:15:59        | Approved   | 1
 253499 | towcester-freegle          | 2026-07-24 17:16:29        | Approved   | 1
```

Northampton is the **origin** group (`rippled_in = 0`, arrival identical to the post
date). So "it didn't show on Northampton" is not a rippling propagation delay. It is an
indexation problem.

The page itself is fine. `GET /message/121054975` as Googlebot returns HTTP 200 and the
item description **is** in the server-rendered HTML ("Solid oak doors. Some glazed as in
photo. Good condition."). The content is there. The problem is entirely discovery,
signal quality, and crawl economics.

## Scale

```
messages total                      8,305,754
live (approved, no outcome, 90d)       42,054
with photo (90d live)                  40,370
```

Two numbers drive most of this document: **~42k live posts** is a trivially
sitemappable set, and **8.3M total** is the size of the dead-URL problem.

## Findings

### 1. Item photos are robots-blocked on our own image host

Root cause of "the images don't come up through image searches". Categorical, not
partial.

`https://delivery.ilovefreegle.org/robots.txt` ends with:

```
User-agent: *
Disallow: /*?*
Allow: /$
```

Every Freegle item photo is served query-string-only:

```
https://delivery.ilovefreegle.org/?filename=<hash>&we&w=640&h=480&output=jpg&fit=cover&url=https://uploads.ilovefreegle.org/<hash>
```

Under RFC 9309 longest-match-wins, `Disallow: /*?*` (length 5) beats `Allow: /`
(length 1), and `Allow: /$` does not match a URL carrying a query. So **Googlebot-Image
is disallowed from every item photo on the site**, all 40,370 of them.

The file is not ours by intent. We self-host images.weserv
(`delivery-imagesweserv.conf:45`, `root /var/www/imagesweserv/public`) and inherited
their `public/robots.txt` verbatim. Upstream ship that block deliberately so their free
public proxy is not crawled. Diffing `images.weserv.nl/robots.txt` against ours, the
trailing block is byte-identical. It came along onto our own domain, where it means
something very different.

Not the cause, for the record: the images serve correctly with `content-type:
image/jpeg` and return 200 to a `Googlebot-Image/1.0` user agent. Only robots.txt stops
them.

### 2. No post URLs in the sitemap

`https://www.ilovefreegle.org/sitemap.xml` contains **522 URLs**: 496
`/explore/<group>` pages plus 26 static pages. Zero `/message/` URLs. No `lastmod` on
any entry.

Trash Nothing, for contrast, publishes `sitemap_index.xml` fanning out to 11 child
sitemaps including `posts.xml` and `posts2.xml`, 50,000 post URLs each, with `lastmod`.

This is the clearest single difference between us and them, and it is why their copy of
our post is the one Google surfaced.

Source: `server/routes/sitemap.xml.ts`.

### 3. No crawlable link from a group page to its posts

`GET /explore/Northampton-Freegle` server HTML contains **zero** `href="/message/..."`.
`pages/explore/[groupid]/[[msgid]].vue:2` wraps the whole page in `<client-only>`, so
the raw HTML is the group blurb and nothing else.

Google renders JavaScript, but on a delayed second pass and not uniformly across every
page of a large site. Which group pages happen to surface a given post is therefore
close to arbitrary. That matches the report exactly: it appeared under a couple of
groups and not the one it was actually posted to.

Combined with finding 2, there is currently **no reliable path at all** by which Google
learns a Freegle post exists.

### 4. 8.3 million soft 404s

Every post ever made remains at `/message/<id>` returning:

- HTTP **200**
- `<title>` set to the item subject
- no `noindex`, no `X-Robots-Tag`
- body "This post isn't available"

Verified on a withdrawn 2023 post (`97562973`): HTTP 200, title `OFFER: Kitchen cupboard
units with worktop (BS16)`, body "This post isn't available / This post was withdrawn by
the poster."

That is 8.3M thin, near-identical pages against ~42k real ones, a ratio of roughly
200:1. This is the textbook pattern that causes Google to throttle crawl of a path and
discount the section as a whole. It also means crawl budget that could be spent on live
posts is spent on dead ones.

Source: `pages/message/[id].vue:39-89`.

### 5. Every post page carries the same meta description

Sitewide, every `/message/<id>` returns:

```html
<meta name="description" content="Click for more details">
```

Verified on four unrelated posts (121002880, 120758051, 120917758, 121054975).

Cause: `pages/message/[id].vue:197-201` reads `message.value.snippet` and falls back to
the literal string `'Click for more details'`. APIv2 `GET /message/<id>` returns no
`snippet` field at all. The payload keys are
`id, arrival, date, fromuser, subject, type, textbody, lat, lng, ...` — the text is
there as `textbody`, just not under the name the page looks for. So the fallback fires
100% of the time.

The effect is that our most numerous page type tells Google every post is a duplicate of
every other post.

### 6. Structured data is present but invalid, so Google discards it

`components/OurMessage.vue:11-27` emits microdata:

```html
<div itemscope itemtype="http://schema.org/Product">
  <div itemprop="offers" itemscope itemtype="http://schema.org/Offer" class="d-none">
    <meta itemprop="priceCurrency" content="GBP" />
    <span itemprop="price">0</span> |
    <span itemprop="availability">Instock</span>
```

Four problems:

- The only `itemprop`s anywhere on the rendered page are `offers`, `price`,
  `priceCurrency`, `availability`. There is **no `name`, no `image`, no
  `description`**. A `Product` without `name` and `image` is dropped by Google entirely,
  so this markup currently does nothing at all.
- `availability` is `"Instock"`, which is not a schema.org value. It must be
  `https://schema.org/InStock`.
- The block is `class="d-none"`. Google's guidelines permit hidden JSON-LD but not
  hidden microdata describing content the user cannot see.
- `http://schema.org` rather than `https://`.

Trash Nothing emit a single valid JSON-LD block instead. We emit JSON-LD on exactly one
route, `pages/compare/[competitor].vue:91`.

### 7. No canonical tag anywhere on the site

`grep` across the repo finds no `rel="canonical"` on any page. A post is reachable at
`/message/<id>`, at `/explore/<group>/<id>`, and with tracking params such as `?src=`.
Nothing consolidates those. The message page response also carries `netlify-vary: query`,
so query variants are distinct CDN entries as well.

Source: `composables/useBuildHead.js` builds the whole head and emits no `link[rel=canonical]`.

### 8. No `<h1>` on post pages

Zero `h1`, `h2` or `h3` tags in the rendered post page. The subject appears as unmarked
text. TN's equivalent page has `<h1>IKEA EKENABBEN Shelving Unit (East Croydon CR0)</h1>`.

### 9. Generic image alt text

Item photos render as `alt="Item Photo"` and `alt="Thumbnail"`. Alt text is a primary
ranking input for image search specifically, so this compounds finding 1: even once the
robots block is lifted, the photos carry no descriptive text.

### 10. Numeric post URLs

`/message/121054975` versus TN's
`/post/47071013/ikea-ekenabben-shelving-unit-east-croydon-cr0`. A minor ranking factor,
but it also affects click-through from the results page.

### 11. Group page meta description contains raw HTML

`GET /explore/Northampton-Freegle`:

```html
<meta name="description" content="<p><strong>Welcome to Northampton Freegle</strong>.</p><p>Please don't throw things away!...">
```

The group description is passed through to `buildHead` untouched
(`pages/explore/[groupid]/[[msgid]].vue:52-54`). Correctly quoted, so not a markup bug,
but it wastes the description budget on markup and reads badly wherever it is shown raw.

## Proposed work, in priority order

### Tier 1 — cheap, high value, each an hour or less

**1.1 Replace the robots.txt on `delivery.ilovefreegle.org`.**
Serve `User-agent: *` / `Allow: /` from the delivery vhost, overriding the inherited
weserv `public/robots.txt`. Nothing else on this list moves image search until this is
done. Note the file is Cloudflare-cached (`x-cache-status: HIT`), so purge after.

**1.2 Give each post page a real meta description.**
Either add `snippet` to the APIv2 message response, or change `buildHead`'s caller to
fall back to a trimmed `textbody` before falling back to the literal string. Prefer the
latter: it is one file and does not need an API change. Keep the literal only for the
genuinely empty case.

**1.3 Set image alt text to the item subject.**
Replace `"Item Photo"` / `"Thumbnail"` with the post subject, ideally with a photo index
where there are several.

**1.4 Add `lastmod` to the existing sitemap entries.**

### Tier 2 — the structural fixes that actually close the gap

**2.1 Publish post URLs in the sitemap.**
Move to a sitemap index with chunked children, live posts only (approved, not
taken/withdrawn/deleted), with `lastmod` from `messages.arrival`. ~42k URLs, comfortably
inside the 50k-per-file limit, so two or three children with headroom. This is the single
biggest indexation change available to us and it is precisely what TN does that we do
not.

Open question worth deciding up front: whether to generate this at request time from the
API (simple, but a heavy query every time Google fetches it) or on a schedule into static
files (more moving parts, much kinder to the DB). Given the current sitemap already
fetches `/group` at request time and is cheap, and the post query is not, a scheduled
build is probably right.

**2.2 Serve 410 for posts that are taken, withdrawn or deleted.**
Keep the friendly "This post isn't available" page for humans, but send it with status
410 rather than 200. That retires 8.3M soft 404s and stops Google throttling crawl of
`/message/*`. 410 rather than 404 because these are deliberately and permanently gone.
Deleted-because-abusive posts should also carry `noindex` regardless.

Sequencing note: do this **before** 2.1, or at least in the same release. Submitting 42k
good URLs into a path Google has already learned to distrust wastes the submission.

**2.3 Server-render the post list on `/explore/<group>`.**
The page does not need to come out of `<client-only>` wholesale. A plain server-rendered
list of post subjects linking to `/message/<id>`, rendered above or behind the
interactive component, is enough to give Google a real crawl path into every group. This
is the direct fix for "it didn't show on the Northampton page".

### Tier 3 — polish, worth doing once tiers 1 and 2 land

**3.1 Replace the microdata with valid JSON-LD.**
A single `Product` block per post page carrying `name` (subject), `image` (all
attachment URLs), `description` (textbody), `offers` with `price: 0`,
`priceCurrency: "GBP"`, `availability: "https://schema.org/InStock"`, and `areaServed`
from the post location. This is what would earn a rich result with a photo thumbnail.
Delete the existing `d-none` microdata block at the same time rather than leaving both.

**3.2 Add `rel="canonical"` in `buildHead`.**
Clean path, query params stripped. Applies sitewide, not just to posts.

**3.3 Add an `<h1>`** with the item subject to the post page.

**3.4 Add a descriptive slug to post URLs**, `/message/<id>/<slug>`, with the bare
numeric form 301ing to it. Keep the numeric form working forever; it is in millions of
emails.

**3.5 Strip HTML from group descriptions** before they reach meta tags.

## What to measure

Before starting, capture from Search Console: indexed count for `/message/*`, "Crawled
but not indexed" and "Discovered but not indexed" counts, and total image impressions.
Tier 1.1 alone should move image impressions off zero within a few weeks. Tier 2.1 plus
2.2 should show up as a fall in "Discovered but not indexed" and a rise in indexed
`/message/*`.

## Related

- `server/routes/sitemap.xml.ts` — sitemap generation
- `composables/useBuildHead.js` — all meta tag construction
- `pages/message/[id].vue` — post page, expired-post handling
- `pages/explore/[groupid]/[[msgid]].vue` — group page, the `<client-only>` wrapper
- `components/OurMessage.vue` — the microdata block
- `delivery-imagesweserv.conf` — image delivery vhost
