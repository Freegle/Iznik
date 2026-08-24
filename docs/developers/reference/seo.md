---
last_reviewed: 2026-08-19
owner: Freegle dev team
covers:
  - iznik-nuxt3/server/utils/sitemap.ts
  - iznik-nuxt3/server/routes/sitemap.xml.ts
  - iznik-nuxt3/server/routes/sitemap-pages.xml.ts
  - iznik-nuxt3/composables/useBuildHead.js
  - iznik-nuxt3/composables/useMessageJsonLd.js
  - iznik-nuxt3/pages/message/[id].vue
  - iznik-nuxt3/public/robots.txt
  - iznik-server-go/message/sitemap.go
  - iznik-server-go/group/groupMessages.go
  - delivery-imagesweserv.conf
---

# SEO: how posts get found

How a Freegle post becomes something Google can find, and the decisions behind
each piece. Written up after a member searched for one of our own posts and got
the Trash Nothing copy of it instead.

## The three things a crawler needs

1. **To learn the URL exists.** From the sitemap, or from a link on a page it
   already crawls.
2. **To fetch something worth indexing.** Real content in the server-rendered
   HTML, a description that isn't identical on every page, a heading.
3. **Not to be told to go away.** No `robots.txt` rule blocking it, no
   `noindex`, no 404.

We used to fail all three for individual posts, and the third one for every
photo on the site.

## Sitemaps

`robots.txt` advertises `/sitemap.xml`, which is a **sitemap index**, not a list
of URLs:

| Route | Contents | ISR |
|-------|----------|-----|
| `/sitemap.xml` | index listing the children below | 600s |
| `/sitemap-pages.xml` | landing/policy pages, `/compare/*`, one per community | 3600s |
| `/sitemap-posts/<n>` | up to 20,000 live posts each, with `lastmod` | 600s |

Building blocks live in `server/utils/sitemap.ts` (pure functions, unit tested in
`tests/unit/server/sitemap.spec.js`); the routes are thin wrappers that fetch and
render.

**`/sitemap.xml` must not be prerendered.** It was, once, which meant a copy was
baked at deploy time. That is harmless for a list of static pages and useless for
a list of posts, which turn over hourly: within minutes it would be advertising
posts that had already been taken. It is served on demand with a short ISR window
instead (`nuxt.config.ts` `routeRules`).

The post sitemap routes have no `.xml` extension because a Nitro route parameter
has to be a whole path segment. `/sitemap-posts-:chunk.xml` does not match
anything in radix3. What a crawler cares about is the content type, which the
route sets.

### Where the post list comes from

`GET /message/sitemap` in the Go API (`message/sitemap.go`) reads
**`messages_spatial`**, not `messages`/`messages_groups`/`messages_outcomes`.
That table:

- is already exactly the set the site treats as currently visible, because the
  daily batch prunes expired posts out of it;
- holds one row per message (`msgid` is `UNIQUE`), so no dedup is needed;
- is ~51,000 rows, against 8.3m in `messages`.

Joining the message tables to work out the same set is a far heavier query to run
every time the sitemap regenerates.

Excluded from the sitemap: `successful` posts (they answer 410, see below), and
anything that isn't an `Offer` or a `Wanted`.

## Renamed routes answer 301

The WANTED flow moved from `/find` to `/ask` in Aug 2026. `/find` had been in the
sitemap and the prerender list for years, so it has inbound links and accumulated
ranking; it answers a permanent redirect rather than a 404 so that carries over.
`/ask` replaces it in `staticLinks()` and in the prerender list - the old path is
not listed in either, because a sitemap should only advertise URLs that answer 200.

The redirect is deliberately in three places, and all three are load bearing:
`public/_redirects` (forced, so Netlify's edge answers before it looks for a
static file), `routeRules` in `nuxt.config.ts` (any non-Netlify host, including
`prod-local`), and `middleware/ask.global.js` (navigation inside the running app,
which never reaches a server at all - the Capacitor build is a static export).
`tests/unit/middleware/ask.global.spec.js` and
`tests/e2e/test-ask-redirect.spec.js` hold all three in place. They are the first
redirect tests in the repo: `server/middleware/councils.js` quietly became dead
code because a `routeRules` entry shadowed it, and nothing noticed.

## Finished posts answer 410

A post that has been taken, received, withdrawn, deleted, or rejected everywhere
is **gone**. `pages/message/[id].vue` computes this once (`gone`) and:

- calls `setResponseStatus(410)` (a no-op on the client);
- adds `noindex, follow`;
- still renders the friendly "This post isn't available" page for humans.

410 rather than 404 because these are posts that genuinely existed and are
deliberately, permanently finished.

Why this matters: there are roughly **8.3 million** such URLs against **~42,000**
live ones. They used to answer HTTP 200 with the item's subject as the `<title>`
and an identical body, which reads to a crawler as millions of near-identical
thin pages, and gets the whole `/message/*` path discounted and crawled less.

`?showtaken=1` deliberately overrides this, for links that are meant to show a
finished post.

## Community pages are a crawl path

`/explore/<group>` renders its interactive content inside `<client-only>`, so the
HTML a crawler receives contained **no links to posts at all**. Google can run our
JavaScript, but on a delayed second pass and not for every page every time, so
which communities surfaced a given post was effectively arbitrary.

The page now also renders a plain, server-rendered list of post links, hidden from
sighted users with `visually-hidden` but present in the served HTML with real
`<a href="/message/...">` and the post subject as link text. It is fed by
`GET /group/:id/message/summary` (`group/groupMessages.go`), which returns id +
subject for up to 200 recent live posts.

That endpoint is deliberately **anonymous** — unlike `GET /group/:id/message` it
never folds in the caller's own pending posts, because its output is rendered into
a page that gets cached and served to everyone.

`/explore/**` is on a 600s ISR window, down from 3600s: a community page is the
crawl path into that community's new posts, so an hour of cache meant a new post
could sit invisible for an hour after it landed.

## Meta tags

Everything goes through `buildHead` (`composables/useBuildHead.js`), which now
also emits:

- **`rel=canonical`**, agreeing with `og:url`. Both are the clean path with query
  strings stripped, so `?src=` tracking on inbound links doesn't fragment the
  signal. Pass `options.canonical` to override, which the post page does
  (`/message/<id>`) and the community page does (`/explore/<group>`, so that
  `/explore/<group>/<msgid>` folds into it).
- **`options.noindex`**, which adds `robots: noindex, follow` while leaving the
  rest of the tags in place. Call sites used to do this by assigning `head.meta`,
  which replaced the array and silently threw away the description and every
  `og:`/`twitter:` tag.
- **cleaned descriptions**, via `seoDescription()`: strips HTML (group
  descriptions are WYSIWYG and arrived as raw `<p><strong>...` in the meta tag),
  decodes entities, collapses whitespace, truncates at a word boundary.

`seoDescription` strips only an **allowlist** of real HTML tags rather than
anything shaped like `<word>`, so an item described as fitting "`<angle>`
brackets" survives.

Each entry carries a `key:`, which is what deduplicates a tag when a page
overrides a site-wide default. That field used to be `hid:`; unhead 2 (Nuxt 4)
dropped `hid` entirely, and a stray `hid:` is silently ignored rather than
rejected, so the tag it was meant to replace ends up emitted twice.

### Post descriptions

The post page used to emit `content="Click for more details"` on **every post on
the site**, because it read `message.snippet` and the API has no such field. It
now derives the description from `textbody`.

## Structured data

`composables/useMessageJsonLd.js` emits a JSON-LD `Product` block on post pages.

It replaces microdata in `OurMessage.vue` which never did anything: it declared
`schema.org/Product` but carried only `price`, `priceCurrency` and
`availability`, with no `name`, `image` or `description`, so Google discarded it.
`availability` was the string `"Instock"`, which is not a schema.org value, and
the whole block sat inside a `d-none` element, which Google's guidelines do not
allow for microdata.

Deliberately emits **nothing** for:

- **Wanted** posts. A `Product`/`Offer` describes something available; a Wanted is
  a request. Marking one up as a product on offer would be false.
- **gone** posts, which are 410 and noindex anyway.

## Images

Every item photo is served from `delivery.ilovefreegle.org` as a query-string URL:

```
https://delivery.ilovefreegle.org/?filename=<hash>&w=640&url=https://uploads.ilovefreegle.org/<hash>
```

We self-host images.weserv (`delivery-imagesweserv.conf`, rooted at
`/var/www/imagesweserv/public`), and that directory ships **upstream's**
`robots.txt`, which ends:

```
User-agent: *
Disallow: /*?*
Allow: /$
```

Upstream use that to keep their free public proxy from being crawled. On our
domain, serving our own members' photos, it meant `Disallow: /*?*` matched every
photo URL on the site under longest-match-wins, so **Googlebot-Image was blocked
from all ~40,000 live post photos**.

The vhost now serves its own `robots.txt` from a `location = /robots.txt` block
that wins over the file on disk. If image indexing ever silently stops, check that
first:

```bash
curl -s --compressed https://delivery.ilovefreegle.org/robots.txt
```

It is Cloudflare-cached, so purge after changing it.

Alt text is the item subject rather than the literal `"Item Photo"` that used to
be on every image (`MessageExpanded.vue`, `MessageSummary.vue`). Alt text is a
primary ranking input for image search.

`buildHead` also strips the internal `:8080` port from image URLs; crawlers and
social preview fetchers are wary of images on non-standard ports, and the same
file serves fine on 443.

## Checking it

```bash
# What a crawler actually receives
curl -s -A "Googlebot" https://www.ilovefreegle.org/message/<id> | grep -o '<title>[^<]*</title>'
curl -s -A "Googlebot" https://www.ilovefreegle.org/message/<id> | grep -o '<meta name="description"[^>]*>'

# A finished post should be 410
curl -s -o /dev/null -w '%{http_code}\n' https://www.ilovefreegle.org/message/<taken-id>

# Community page should contain post links in the raw HTML
curl -s https://www.ilovefreegle.org/explore/<group> | grep -c 'href="/message/'

# Photos must not be disallowed
curl -s --compressed https://delivery.ilovefreegle.org/robots.txt | tail -5

# Sitemap chain
curl -s https://www.ilovefreegle.org/sitemap.xml
curl -s https://www.ilovefreegle.org/sitemap-posts/0 | grep -c '<loc>'
```

Worth watching in Search Console after any change here: indexed count for
`/message/*`, the "Discovered but not indexed" bucket, and image impressions.
