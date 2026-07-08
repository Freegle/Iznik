# Front-End Server Migration — consolidated plan (2026-06-25)

> ## REVISION 2026-07-08 — scale-in-place on the existing docker host (DECIDED)
>
> **Direction change (Edward):** no new VM. Scale the existing `docker` host,
> adopt its standalone containers into Compose, and migrate app1's services
> onto it. app1 is retired; the docker host becomes the single mixed node
> running everything (edge + batch), with priority guaranteed by **cgroup v2
> slices** (work-conserving weights - batch soaks idle capacity, yields under
> contention), not by physical partitioning. Slices implemented in **PR #1006**
> (`interactive.slice`/`batch.slice` + `docker-compose.override.batchprod.yml`).
>
> **Why consolidation won** (measured 2026-07-08): edge traffic is ~10:1
> peak:trough (app1 delivery logs: ~9K req/hr at 03:00 vs ~98K/hr 08:00-10:00)
> and CPU-light (app1 serves the whole image tier's peak on 1 vCPU at load
> 0.11), while batch is CPU-shaped and starving (docker host load 13.6 on 8
> cores, batch-prod at 428% CPU, 12G into swap). Complementary shapes: a
> dedicated edge VM would waste CPU at all hours; on a shared box batch gets
> the 12-14 cores edge doesn't use. Caveat: digests (07:00-12:00) *cause* the
> edge morning peak (emails full of image links), so the biggest batch job and
> the edge peak coincide - weights let image-serving win and digest shards
> stretch within their window; watch `/proc/pressure/cpu` there post-merge.
>
> **What this revision eliminates from the plan below:**
> - §3 two-VM target architecture and all of §7's VM provisioning.
> - tiles/geocode/wiki "migration" - they are **already on this host**; the
>   DNS A-records already point here (185.44.254.12). They don't move at all;
>   the tile server and wiki containers just get adopted into Compose.
> - The osm-data/osm-tiles/wiki data-sync problem - verified 2026-07-08: the
>   tile server (`confident_curran`) already uses named Docker volumes
>   `osm-data`/`osm-tiles` (adopt via `external: true`), and wiki uses bind
>   mounts under `/var/wiki` - zero data movement, same-host container swap.
>
> **What survives unchanged:** §2 verified facts; §5/§6 dev-validated `edge`
> profile artifacts (deployed here instead of on a VM, alongside the existing
> profiles → `COMPOSE_PROFILES=backend,production,mail,edge`); the §8 HAProxy
> cutover mechanics for uploads/delivery; §10 risks/observability (promtail,
> cache-key HIT replay, PROXY framing); §11 AI-safety protocol.
>
> **Feasibility verified live 2026-07-08 (read-only):**
> - NFS: `nfs2.nlc.storage.katapult.io` (10.11.3.10/.20) **is reachable from
>   this host** - TCP 2049 connects via the default route; no Katapult network
>   change needed. Export allowlist for this host still to be confirmed at
>   first mount (`-o ro` first, per §11).
> - Host is cgroup v2 + systemd cgroup driver + controllers delegated
>   (Docker 29.2.1/runc 1.3.4) - PR #1006 slices work with no daemon changes.
> - Port constraint: native nginx owns `:80`/`:443` (certbot TLS for
>   tiles/geocode/wiki + stale discourse) and `:8080` is the tile container's
>   published port. So the HAProxy-fronted vhosts (uploads incl. the
>   `:8080`-URL form + delivery) publish on **new ports** (e.g. `:8180`
>   proxy_protocol, `:8188` plain) and the HAProxy backends target those.
>   Native nginx keeps the direct-A TLS domains initially; moving
>   tiles/geocode/wiki behind applb (DNS repoint → HAProxy TLS) is an optional
>   later step that removes certbot from this host and makes failover uniform.
>
> **Revised staging** (each step independently reversible, human-gated).
> **See the EXECUTION STATUS section immediately below the revision block for
> what is DONE vs LEFT as of 2026-07-08.**
> 0. **[DONE 2026-07-08]** disk: reclaimed ~168G (107G orphaned old tile PG-12
>    DB, 41G legacy /opt/loki, 13G defunct /var/discourse) → 89%→55%; a 768G
>    resize is DECIDED but no longer urgent (provider-side). PR #1006 cgroup
>    slices deployed (interactive/batch). (Ripple CPU tracked separately.)
> 1. **Upsize the host ONLY on evidence** (Krystal package upgrades are
>    live/no-downtime, so buying early gains nothing). Measured 2026-07-08:
>    the 12G swap is COLD pages (photon JVM 6.2G + renderd 4.0G idle;
>    swap-in ~4 pages/s - no thrash), the hot set fits in 23G, and app1 runs
>    the entire image tier in 2.8G/1 vCPU - so consolidation adds only
>    ~1 core + 2-4G and fits the current size once the ripple CPU waste is
>    fixed. Upsize triggers: sustained swap-in (100s of pages/s), PSI memory
>    pressure, digest shards overrunning the 07:00-12:00 window, or
>    interactive-tier CPU stalls after the ripple fix. Then: ROCK-48
>    (16 vCPU/48G, +£120/mo), ROCK-96 as the further escape hatch.
> 2. **[DONE 2026-07-08]** Adopted `tile-server` (osm volumes), `wiki-media`+
>    `wiki-mysql` (/var/wiki binds) into Compose under the `edge` profile;
>    dropped `ors-app` + the stale discourse vhost. tile-server render threads
>    capped `THREADS=4` (100 thrashed the shared box). Old standalone
>    containers kept stopped as rollback (remove after soak). Photon still
>    native under monit.
> 3. **[DONE 2026-07-08]** Images cutover: NFS `/images` mounted rw at
>    `/srv/tusd-data` (→ bound to `/images` in the tusd container); `delivery`+
>    `tusd`+`frontend-nginx` up in `interactive.slice` on :8180/:8188; 41G
>    `/wsrv_cache` rsynced (chown 101:101 + nginx restart to index); replay
>    validated 99% HIT; HAProxy cut - `tusd_backend`→edge, new
>    `delivery_backend`→edge, **app1 `backup` in both**, dead `app4` removed
>    everywhere. Three fixes: tusd `-upload-dir=/images` (Storage.Path
>    symmetry), pin `tusd v2.4.0` (v2.10.0 `.lock`-on-GET broke reads), neg
>    cache 15m→5m. Steady state 95-96% HIT / 0 500s / 0.3% 404 (= app1
>    baseline). **uploadcare left on app1** (plan §8).
> 4. **[LEFT] Legacy `images`/`cdn`/`users`-web** (HAProxy default backend).
>    Verified live on app1 2026-07-08: **no PHP needs to survive** - the
>    earlier "apiv1 container or port the vhosts" framing was wrong.
>    - `users.ilovefreegle.org` web = literally one line
>      (`return 302 https://www.ilovefreegle.org$request_uri`) - a server
>      block on the edge front door.
>    - `cdn.ilovefreegle.org`: DNS still CNAMEs to applb, but **no app1 vhost
>      serves it any more** (no server_name match → falls through to the
>      default server). Decide: formally kill it (DNS + any HAProxy acl), or
>      alias it into the images rewrites if old emails used cdn-hosted
>      `timg_*` links. Check HAProxy stats for residual volume first.
>    - `images.ilovefreegle.org` is the ONLY live V1 PHP left anywhere: the
>      vhost rewrites `img_/timg_/uimg_/tuimg_/gimg_/...` forms into V1
>      `/api/image`, now ~3-5K req/day (the June audit's ~120K/day figure was
>      the shared cache tier, not this vhost alone). Measured today: ~70%
>      are DB-lookup → 302 to the modern delivery form (verified:
>      `Location: delivery/?url=uploads:8080/<externaluid>/`), ~30% serve
>      real bytes (200 image/jpeg - old user-profile thumbs with no
>      externaluid). Replacement without PHP: move the rewrite block onto the
>      edge front door and point it at a **small new apiv2 GET endpoint**
>      (attachment lookup → 301; the Go side already has the attachment
>      models and delivery-URL builder in `misc/imagedelivery.go`; V1's
>      resolution order is externaluid → externalurl → Azure archive →
>      defaultprofile fallback, see `Attachment::canRedirect`). The
>      bytes-serving 30% needs a one-time decision: migrate those legacy
>      blobs to tusd, or accept the defaultprofile fallback (cosmetic loss on
>      ancient profiles). V1 then dies entirely with app1.
>    - `users` mail-alias MX remains a separate workstream (§8).
>      **uploadcare** also still on app1 (could move to its own edge backend,
>      or leave).
> 5. **[LEFT] Retire app1** per §9 (after ≥1 week clean, and only after step 4).
>    **DECIDED 2026-07-08: NO second HAProxy / VIP** - the failover investment
>    is declined. Consequence: `ha-internal` is a single point of failure (as
>    it already is today), and once app1 is gone there is no warm failover
>    sibling for uploads/delivery. Accept that, OR keep app1 alive as the
>    backup-only sibling rather than fully decommissioning it. Either way app1
>    retirement is gated on step 4.
>
> A host reboot now takes user-facing services down with it - the reboot
> runbook (plans/2026-07-03-host-reboot-runbook.md) becomes the canonical
> procedure, and restart policies deserve hardening (most edge services are
> `restart: "no"`, relying on `freegle-docker.service`).
>
> The original plan below is retained for its verified facts, dev-validation
> results, HAProxy mechanics, and safety protocol. Read §3/§7 as superseded.

---

## EXECUTION STATUS (2026-07-08)

Everything below the two big rocks (Stage 1 adoption + Stage 3 images cutover)
was **executed and verified on the live host** this day. All repo changes are on
`master`. HAProxy is `ha-internal`.

### DONE
- **Disk**: reclaimed ~168G (107G orphaned PG-12 tile DB volume `_data`, 41G
  legacy `/opt/loki`, 13G defunct `/var/discourse`, ~7G docker) → 89%→55%. 768G
  resize DECIDED, no longer urgent (provider-side). Do NOT `docker image prune
  -a` (breaks the loki-backup cron image).
- **cgroup slices (PR #1006)**: `interactive.slice` (CPUWeight=800:
  embedding-sidecar, postfix, + the images tier), `batch.slice` (25: batch-prod,
  spatial, spatial-knn). Units in `/etc/systemd/system/`; host `.env`
  `COMPOSE_FILE` includes `docker-compose.override.batchprod.yml`. NB
  `IOWeight`/`ionice` are inert (scheduler is `mq-deadline`, not bfq).
- **Stage 1 adoption**: `tile-server` + `wiki-media`/`wiki-mysql` now Compose
  services under the `edge` profile in place (zero copy); `ors-app` + stale
  discourse vhost dropped. `tile-server` `THREADS=4` (100 thrashed the shared
  box; every recreate spikes CPU transiently - avoid during the 07:00-12:00
  digest window). Old standalone containers kept STOPPED as rollback.
- **Stage 3 images cutover** (the big user-facing move):
  - NFS `/images` mounted **rw** at host `/srv/tusd-data`, bound to `/images`
    inside the tusd container; durable in `/etc/fstab` (`_netdev,nofail`).
  - `delivery`+`tusd`+`frontend-nginx` up in `interactive.slice` on :8180
    (proxy_protocol) / :8188 (plain :8080-form). 41G `/wsrv_cache` copied from
    app1 (needs `chown 101:101` + a frontend-nginx **restart** to index the
    keys_zone). Replay-validated **99% HIT**.
  - HAProxy: `tusd_backend` → `edge 10.220.0.103:8188` (app1:8080 backup); NEW
    `delivery_backend` → `edge 10.220.0.103:8180` send-proxy (app1:80 backup);
    only the `delivery` ACL switched; dead `app4` removed from ALL backends.
  - Three fixes found live (tusd rolled back to app1 while diagnosing each):
    (1) tusd `-upload-dir=/images` - tusd bakes an absolute `Storage.Path:
    /images/<id>` into every `.info`; a `/srv/tusd-data` mount 404'd every
    existing upload and broke app1-backup symmetry. (2) Pin `tusd v2.4.0` -
    base `:latest`=v2.10.0 creates a `<id>.lock` even on GET → denied on NFS →
    intermittent 500s; app1 runs v2.4.0. (3) frontend-nginx neg cache
    `any 15m`→`5m` to match app1.
  - **Steady state: 95-96% HIT, 0 500s, 0.3% 404 (all legit-missing = app1's
    baseline).** app1 is the warm **backup** for uploads+delivery.

### STILL LIVE ON app1 (so app1 is NOT retired)
- `images.ilovefreegle.org` (legacy PHP image serving ~120k/day) → `http_backend`.
- `cdn.ilovefreegle.org` (email image links) → `http_backend`.
- `users.ilovefreegle.org` web 302 → `http_backend`; **and** the
  `<id>@users.ilovefreegle.org` mail-alias reply domain → MX/exim.
- `uploadcare-cache` / `uploadcare-proxy-cache` → `http_backend_cache` → app1.
- app1 also = warm backup for uploads + delivery.

### LEFT TO DO
- **Step 4** - migrate legacy `images`/`cdn`/`users`-web off app1 (needs legacy
  PHP served on the edge: apiv1 container or ported vhosts). The blocker to app1
  retirement. `uploadcare` likewise (or leave).
- `users.ilovefreegle.org` **mail-alias MX/exim** migration (separate workstream).
- **promtail on the edge → Loki** (§10) with `X-Cache-Status` in the log format,
  so cutover health is queryable in Loki (currently only via container logs).
- **Sentry** `OurUploadedImage.vue` per-broken-image `captureMessage` (§10):
  the acute flood was the cutover spike (passed); steady-state = pre-existing
  app1 baseline and the signal is useful while the migration settles. DEFER
  rate-limiting, revisit after a stable stretch.
- **geocode/Photon** still native under monit - containerize later (optional).
- **Soak, then `docker rm`** the stopped rollback containers (`confident_curran`,
  standalone `wiki-media`/`wiki-mysql`).
- **768G disk resize** (provider-side, unhurried).

### DECIDED: NO second HAProxy / VIP (2026-07-08)
The §9 failover investment (a 2nd HAProxy on a Katapult VIP, and/or a 2nd mixed
node as a backup uploads/delivery backend) is **declined**. Consequences: (a)
`ha-internal` remains a single point of failure - as it already is today; (b)
app1 cannot be replaced by a warm failover sibling, so retiring it removes the
only backup for uploads/delivery. Practical options for step 5: fully retire
app1 and accept no image-tier failover, OR keep app1 running as backup-only
rather than decommissioning. Read §9's "provision the 2nd VM before removing
app1" as superseded by this decision.

---

**Goal.** ~~Stand up a new Katapult VM~~ **(superseded 2026-07-08 - see REVISION above: scale the existing docker host in place)** running a FreegleDocker Compose environment that hosts **every externally-accessed service** currently split across (a) `app1-internal` bare-metal and (b) this `docker` host's Compose env + host nginx. Afterwards: **app1 is retired** - the docker host runs everything, with cgroup slices separating the tiers (it remains the prod batch host, `batch-prod`).

This supersedes and broadens `docs/superpowers/specs/2026-04-11-frontend-server-design.md` (which scoped only images + tiles). Scope is now: **anything reached from the outside world moves; internal batch processing stays.**

> Status: **PREP IMPLEMENTED + DEV-VALIDATED — nothing in production has been changed.** The repo-side §5 artifacts now exist and were brought up and exercised end-to-end on an isolated dev worktree (`edge-dev`, see §6); no VM has been provisioned and no prod/HAProxy/DNS change has been made. The Katapult key is available. SSH confirmed to app1 (`10.220.0.45`), `ha-internal` (HAProxy), this `docker` host, and `db{1,2,3}-internal`.
>
> Implemented & validated on dev (2026-06-25): `docker-compose.yml` `edge` profile (`delivery`+`tusd` joined, parameterized `${TUSD_COMMAND}`, new `frontend-nginx`+`tile-server`, `delivery-cache`/`tusd-data`/`osm-data`/`osm-tiles` volumes); `frontend-nginx.conf` (front door); `docker-compose.override.edge.yml` (VM-only ports + tusd NFS bind). See §6 for the dev test results and the four behaviours they pin down.

---

## 1. Corrected scope — external (MOVE) vs internal (STAY) vs DROP

All verified read-only on 2026-06-25.

### 1a. External-facing → MOVE to the new front-end server

| Hostname | Today: host / backend | Fronted by | Cutover | Data to move |
|---|---|---|---|---|
| `uploads.ilovefreegle.org` | app1 bare-metal **tusd** `:8080` (`-upload-dir=images -behind-proxy -base-path / -disable-cors`, cwd `/` → `/images` NFS) | HAProxy `tusd_backend` (+ `tusd_frontend :8080`) | **HAProxy backend switch — no DNS** | None — shared NFS (mount it) |
| `delivery.ilovefreegle.org` | app1 nginx/1.28.2 cache → **wsrv.nl** | HAProxy `http_backend_cache` (shared) | HAProxy backend switch (add `delivery_backend`) | 41 GB `/wsrv_cache` (warm-start, optional) |
| `uploadcare-cache` / `uploadcare-proxy-cache` | app1, **same** `http_backend_cache` | HAProxy | Rides delivery's backend (do **not** repoint shared backend; add a new one) | Shared with delivery |
| `images.ilovefreegle.org` | app1 **legacy PHP** image serving | HAProxy `http_backend` (default) | HAProxy backend switch | PHP app (not self-contained) |
| `cdn.ilovefreegle.org` | app1 nginx vhost (email image links, `timg_*`) | HAProxy `http_backend` (default) | HAProxy backend switch | app1 vhost |
| `users.ilovefreegle.org` | app1 (web 302→www **and** the `<id>@users.ilovefreegle.org` mail-alias reply domain) | HAProxy `http_backend` (default) + MX | HAProxy for web; **mail/MX is separate** | Web vhost; mail routing is its own workstream |
| `tiles.ilovefreegle.org` | **this docker host** nginx/1.24.0 → `127.0.0.1:8080` (overv tile container) | **Direct A → 185.44.254.12** | **DNS A-record repoint (TTL 60)** | `osm-data` 56 GB (live PostGIS) + `osm-tiles` 44 GB |
| `geocode.ilovefreegle.org` | **this docker host** nginx → `127.0.0.1:2322` (Photon, bare-metal Java) | **Direct A → 185.44.254.12** | **DNS A-record repoint (TTL 3600 — lower first)** | Photon index (~GBs, rebuildable) |
| `wiki.ilovefreegle.org` | **this docker host** nginx → `127.0.0.1:8088` (`wiki-media` mediawiki:1.39 + `wiki-mysql`) | **Direct A → 185.44.254.12** | **DNS A-record repoint** | MediaWiki files + `wiki-mysql` DB dump (stateful) |

### 1b. Internal-only → STAY on this docker host (becomes batch/mail/ops only)

`freegledocker-batch-prod` (scheduler), `freegledocker-mjml`, `freegledocker-redis`, `freegledocker-postfix` (`:25`), `freegledocker-rspamd`, `freegledocker-spamassassin`, `freegledocker-embedding-sidecar` (`:3200`, apiv2 semantic search — cross-host internal), `freegledocker-spatial` (`:8196`) + `freegledocker-spatial-knn` (`:8194/5`) (**batch-digest** path, localhost-only; the *public* `spatial.ilovefreegle.org` routing is on db1/2/3, not here), `freegledocker-loki` (`:3100`), `freegledocker-status` (`:18081`, ops; public `status.ilovefreegle.org` → applb).

To confirm before finalizing: `freegledocker-ai-support-helper` (`:8083`) — appears mod/support-internal (`/api/log-analysis` requires mod auth); classify internal unless a public hostname points at it.

### 1c. DROP (do not migrate)

- `ors-app` (OpenRouteService) — superseded by **PR459** (merged `242d8a15c`); routing now runs natively on db1/2/3 as `spatial.ilovefreegle.org`. Decommission on this host independently.
- The `discourse.ilovefreegle.org` nginx vhost here is **stale** — DNS now resolves to `freegle.discoursehosting.net (178.156.182.236)` (external SaaS). Remove the dead vhost; nothing to move.

---

## 2. Load-bearing verified facts

- **HAProxy** on `ha-internal` (= `10.220.0.172` = `applb` = public `185.199.221.13`), v2.4.30, `/etc/haproxy/haproxy.cfg`, backups `haproxy.cfg.bak.YYYYMMDD-*`. ACLs: `uploader→tusd_backend`; `delivery`+`uploadcare_cache`+`uploadcare_proxy_cache`→`http_backend_cache` (**shared**); `api`+shortlinks→`api_server_backend` (db1/2/3:8192); `spatial`→`spatial_backend` (db1/2/3:8196); `modtools.org`→Netlify; default→`http_backend`. **app4 (10.220.0.188) is dead** ("no route to host") yet still listed in `tusd_backend`/`http_backend_cache` (backup) AND `http_backend` (**non-backup active** — clean all three). New rate-limit layer (`per_ip_rates`/`per_user_rates`, 200 req/s/IP, `lua.rate_delay`) — verify image bursts aren't shaped post-cutover.
- **DNS fronting**: `uploads`/`delivery`/`images`/`cdn`/`users`(A)/`spatial` → applb (HAProxy) ⇒ **backend switch, no DNS**. `tiles`(TTL 60)/`geocode`(TTL 3600)/`wiki` → direct A `185.44.254.12` (this host) ⇒ **DNS repoint**.
- **Delivery cache key (de-risked)**: app1 sets *no* explicit `proxy_cache_key`, but a live cache file's header shows `KEY: https://wsrv.nl/?url=…&w=240&h=240&fit=cover`. So the new front nginx MUST pin `proxy_cache_key "https://wsrv.nl$request_uri"` (since its upstream becomes the local weserv container, not wsrv.nl) or the 41 GB orphans. `proxy_cache_path … levels=1:2 keys_zone=wsrv_cache:100m max_size=40g inactive=30d use_temp_path=off` (mirror exactly).
- **tusd CORS**: app1 tusd runs `-disable-cors`; **all** tus CORS comes from HAProxy `tusd_backend`. New front nginx adds **zero** CORS on the uploads vhost.
- **Tiles double-CORS**: today both the host nginx and the overv container (`ALLOW_CORS=enabled`) emit `Access-Control-Allow-Origin: *` → **two** headers. New front nginx must emit exactly one (run overv with `ALLOW_CORS=disabled`).
- **PROXY protocol**: HAProxy reaches `:80` backends with `send-proxy`; `:8080` (tusd) plain. New front nginx: `listen 80 proxy_protocol; listen 8080;` and recover client IP from the PROXY header.
- **Traffic (HAProxy stats)**: delivery tier ~**864 K req/day / ~78 GB/day** (incl. uploadcare); uploads ~**38 K req/day / ~17.8 GB/day**; legacy `images` ~**120 K/day** (92 K emit the `:8080` delivery form). Tiles ~**35–42 K/day** (this host's `maps_access.log`). Cache-hit ~92% claim is **unverifiable** (app1 nginx logs don't ship to Loki — see §10 observability).
- **`:8080` upload-URL form is in active live traffic** (Freebie Alerts, native apps, weserv fetcher) and hardcoded in apiv2 `GetImageDeliveryUrl` + frontend `config.js` + V1 — keep the `:8080` listener indefinitely; its retirement is separate work.
- **app1 disk** 75% (112/158 G); `/images` NFS = `nfs2.nlc.storage.katapult.io:/katapult/fsv_5ivInYUXp22oVueE` (882 G/4.9 T, concurrent-mountable). **This host** `/` at 86% (after laravel.log rotation). osm-data 56 G, osm-tiles ~44 G. **NFS storage net = `10.11.3.0/24`** (separate from `10.220.0.0/22`) — the new VM needs a route to it.

---

## 3. Target architecture

New front-end VM(s) run FreegleDocker with a dedicated profile (proposed name **`edge`** / `frontend-host`, broadened from the doc's `image-host`):

- `frontend-nginx` (new front door, `:80 proxy_protocol` + `:8080`) — replaces app1's delivery cache nginx + tusd proxy AND this host's tiles/geocode/wiki nginx vhosts (single nginx, multiple `server` blocks).
- `delivery` (weserv, **reuse existing compose service**) — local weserv replaces the external wsrv.nl dependency.
- `tusd` (**reuse existing compose service**) — `-upload-dir=/srv/tusd-data` (NFS bind), replicate app1's exact flags incl. **no `-hooks-http`** (app1 prod runs without hooks — do not point at a non-existent apiv1).
- `tile-server` (overv, new) + `osm-data`/`osm-tiles` volumes.
- Photon (geocode) + MediaWiki (`wiki-media`+`wiki-mysql`) — later stages; both stateful.

**Recommendation: two VMs.** VM-A = images (weserv/tusd/frontend-nginx, ~4 vCPU/8 GB/80 GB, needs NFS route). VM-B = tiles+geocode+wiki (renderd/PostGIS/Photon/MediaWiki are RAM/CPU/disk-heavy, ~8 vCPU/24 GB/160 GB) — keeps the latency-sensitive image cache away from render storms/replication. Both need a public IP (for the direct-A DNS cutovers).

---

## 4. The "existing image hosting" correction

The `delivery` and `tusd` services already **exist** in `docker-compose.yml` (used by dev/CI under the `frontend` profile); they are simply **not active** on this host (`COMPOSE_PROFILES=backend,production,mail`). So the front-end build is **not from scratch** — it promotes existing service definitions to a dedicated prod env, adds the front-door nginx + tile-server, and folds in geocode/wiki. Prod image upload/delivery itself lives on **app1**, not this compose env.

---

## 5. Repo changes (author on a branch; test on dev first)

1. **`docker-compose.yml`**: add the `edge` profile to `delivery` + `tusd`; parameterize tusd `command` via `${TUSD_COMMAND:-<current dev default>}` so dev/CI are unchanged and the VM overrides it; add `frontend-nginx`, `tile-server` services (profile `edge` only); add `delivery-cache`/`tusd-data`/`osm-data`/`osm-tiles` volumes. (Later: `photon`, reuse `wiki-media`/`wiki-mysql` under `edge`.)
2. **`frontend-nginx.conf`** — at repo root, **implemented + dev-validated** (§6). Encodes: uploads `:80`+`:8080` (no CORS), delivery cache with the **verified** `proxy_cache_key "https://wsrv.nl$request_uri"` + `@handle_redirect` + `X-Cache-Status` (the upstream weserv's own `X-Cache-Status` is `proxy_hide_header`-ed so the front door exposes exactly one), tiles single-CORS, PROXY-protocol real-IP, a plain `:8081 /healthz`, and a `return 444` catch-all. `nginx -t` clean.
3. **`docker-compose.override.edge.yml`** (VM-only, opted in via `COMPOSE_FILE`): bind `tusd` upload dir to the NFS host mount `/srv/tusd-data`.
4. VM `.env`: `COMPOSE_PROFILES=edge`, `COMPOSE_FILE` **without** `docker-compose.ports.yml` (traefik off → no `:80`/`:8080` contention), unique `COMPOSE_PROJECT_NAME`, `TUSD_COMMAND=-upload-dir=/srv/tusd-data -behind-proxy -base-path / -disable-cors`.
5. **Local-dev/CI unaffected**: `edge` is not in any default profile set; `frontend-nginx`/`tile-server` never start there; the parameterized tusd command defaults to today's literal.

---

## 6. IMPLEMENT ON DEV FIRST (the immediate next step)

Dev = local FreegleDocker (`frontend,database,backend,dev,monitoring`, traefik routing `*.localhost`). To build/validate the `edge` stack on dev without clashing with traefik's `:80`:

1. Create an **isolated worktree** (`./freegle worktree create edge-dev`) so ports/containers are namespaced and the main dev env is untouched.
2. Land the §5 repo changes on a branch in that worktree.
3. `nginx -t` the `frontend-nginx.conf` (upstreams use `set $x …; proxy_pass $x;` + Docker resolver, so it parses without backends present).
4. Bring up only the edge services with remapped ports: `COMPOSE_PROFILES=edge docker compose up -d frontend-nginx delivery tusd tile-server` (map host `:80/:8080` to spare ports to avoid traefik).
5. Validate via `Host:`-header curls against the edge nginx port:
   - `curl -H 'Host: uploads.ilovefreegle.org' -X OPTIONS …` → expect tusd reachable (CORS comes from HAProxy in prod, so absent on dev is expected).
   - `curl -H 'Host: delivery.ilovefreegle.org' '…/?url=<dev image>&w=200'` → 200 + `X-Cache-Status`.
   - `curl -H 'Host: tiles.ilovefreegle.org' …/tile/0/0/0.png` → 200, **exactly one** ACAO header.
6. Iterate the compose/nginx config on dev until green, then it's ready to deploy to the Katapult VM (§7).

This proves the front-door + cache-key + CORS behavior with zero prod and zero traefik impact before any VM exists.

### 6a. DONE — dev validation results (2026-06-25, worktree `edge-dev`)

Built on the isolated `edge-dev` worktree (own compose project/ports; main dev env untouched). Edge services brought up over the compose network (no host `:80`/`:8080` bind → no traefik clash); `:80` proxy_protocol vhosts exercised with **real PROXY framing** via `curl --haproxy-protocol` (per §10's "test with real PROXY framing, not plain curl").

| Check | Method | Result |
|---|---|---|
| `docker-compose config` — dev unaffected | default profiles | `tusd` command renders to the **unchanged** dev literal; `frontend-nginx`/`tile-server` absent ✓ |
| `docker-compose config` — edge | `COMPOSE_PROFILES=edge` | renders `delivery`/`tusd`/`frontend-nginx`/`tile-server` only ✓ |
| `docker-compose config` — VM | edge + `override.edge.yml` + `TUSD_COMMAND` | `frontend-nginx` publishes `:80`/`:8080`; tusd cmd = prod flags; tusd bound `/srv/tusd-data` ✓ |
| `nginx -t` | throwaway `nginx:1.27-alpine` | syntax ok (variable upstreams + resolver parse with no backends) ✓ |
| uploads `:8080` (plain) → tusd | `GET /tus/` | `405` (correct tus); **no** nginx-added `Access-Control-*` (HAProxy owns tus CORS) ✓ |
| delivery `:80` (proxy_protocol) | real image, repeat | `200` `image/webp`, `X-Cache-Status: MISS` → `HIT`; **exactly one** `X-Cache-Status` ✓ |
| tiles `:80` (proxy_protocol) | `/tile/0/0/0.png` | `502` (no osm import on dev) but **exactly one** `Access-Control-Allow-Origin` — the §2 double-CORS fix holds even on errors ✓ |
| catch-all `:80` | unknown `Host` | `444` (empty reply / closed) ✓ |

Findings folded back into the artifacts:
- **Healthcheck must use `127.0.0.1`, not `localhost`** — busybox resolves `localhost`→`::1`, nginx listens IPv4-only → false "unhealthy". (Would have bitten on the VM too.)
- **Don't send `Host: images.weserv.local`** to the local weserv container — that's its `allow 127.0.0.1; deny all` internal engine vhost (→ 403). Proxy to its public/default server with the original Host.
- **`proxy_hide_header X-Cache-Status`** on the delivery vhost — weserv emits its own (tiny inner cache); without hiding it the front door returned two, muddying §10 HIT-rate measurement.
- Dev-iteration note: `frontend-nginx.conf` is a **single-file bind mount**, so editing it needs a container **recreate** (not `nginx -s reload`) to re-resolve the inode.

Not locally verifiable (deferred to §7 on the VM): real tile render (needs the ~56 GB osm-data import), Photon/MediaWiki stages, NFS latency, and HAProxy `send-proxy`↔`proxy_protocol` framing against the real HAProxy (validated here with synthetic PROXY framing only).

---

## 7. Provision + non-intrusive prep (Step 0 — zero prod impact)

- **Katapult key**: capability **probe first** (list orgs/DC/networks/NFS/DNS — never `create` to discover scope). Confirm: VM create, internal `10.220.0.0/22` attach, route to NFS `10.11.3.0/24`, public IP, NFS-share access (export allowlist may need the VM's storage-net IP), DNS scope (only if `tiles/geocode/wiki` A-records are Katapult-managed — needed for tile/geocode/wiki cutover, **not** for images), firewall (`:80`/`:8080` from ha-internal; `:80/:443` internet for tiles/geocode/wiki).
- Provision VM(s) per §3; **pin Docker 27.5.1** (28+ breaks container networking — CLAUDE.md); tag `*-staging`.
- **NFS mount read-only** at `/srv/tusd-data`; verify `ls` parity vs app1 `/images`; benchmark read latency vs app1.
- Deploy the dev-proven branch; `COMPOSE_PROFILES=edge`; `docker compose up -d`.
- **Sync (non-intrusive)**: images originals = **no copy** (shared NFS). Delivery cache = read-only incremental `rsync` of app1 `/wsrv_cache` (low-priority, while app1 serves), final delta at cutover. osm-tiles = rsync live. **osm-data** = **fresh import on VM-B** (keeps prep 100% non-intrusive; replication catches up) — avoid hot-rsync of live PostGIS. wiki = `mysqldump`/file copy from `wiki-mysql`. geocode = copy or rebuild Photon index.
- **Step-0 validation** (Host-header curls vs VM IP, real cached hash → HIT, real `{z}/{x}/{y}` tile, NFS latency). Nothing public changes; rollback = delete VM.

---

## 8. Cutover (one service at a time; each independently reversible)

- **Images (uploads, then delivery)** — HAProxy edits on ha-internal (snapshot `haproxy.cfg.bak.YYYYMMDD-pre-<step>`, `haproxy -c` before reload): repoint `tusd_backend` → VM; **add** `delivery_backend` (don't touch shared `http_backend_cache`) and switch `use_backend delivery_backend if delivery`; clean **all** app4 lines. `reload`, not restart. Rollback = revert snapshot (app1 still serving shared NFS).
- **Legacy `images`/`cdn`/`users`-web** — HAProxy default backend; move when retiring app1 (these are app1-PHP-coupled).
- **tiles / geocode / wiki** — DNS A-record repoint to the VM public IP. Lower `geocode` TTL (3600→60) ≥1 h ahead. Verify via Host-header curl before flip. Rollback = revert A-record (old still serving). **Change `tiles` A only** — `tiles`+`geocode` share host nginx, so don't reconfigure the shared nginx; just stop the overv container at decommission.
- `users.ilovefreegle.org` **mail-alias** routing is an MX/exim workstream, separate from the web move.

---

## 9. Decommission

> **UPDATE 2026-07-08:** the "provision the 2nd VM/HAProxy before removing app1"
> precondition is **superseded** - a 2nd HAProxy/VIP is NOT being added (see the
> EXECUTION STATUS decision at the top). So app1 retirement is gated only on
> step 4 (legacy `images`/`cdn`/`users` moved off app1). Accept no image-tier
> failover, or keep app1 as backup-only rather than fully decommissioning.
> Also: the docker-host adoptions in the last sentence are **already DONE** -
> tile-server + `wiki-media`/`wiki-mysql` were adopted into Compose in place
> (not moved to a VM-B), `ors-app` + discourse vhost dropped. What remains here
> is `docker rm`-ing the STOPPED standalone rollback containers after a soak.

After ≥1 week clean (and only after step 4): stop app1 tusd + `checktusd` monit, disable delivery/images/cdn/users nginx sites, delete `/wsrv_cache`, unmount `/images`. On this docker host: `docker rm` the stopped standalone `confident_curran` + `wiki-media`/`wiki-mysql` rollback containers. Every destructive step is **human-gated** (see §11).

---

## 10. Risks / go-no-go (from the audit)

Critical: send-proxy↔plain-HTTP mismatch (test with real PROXY framing, not plain curl); double-CORS on tus/tiles (exactly one ACAO); tusd hook target (replicate app1 = **no hooks**); cache-key mismatch (use the verified key; replay yesterday's URLs → >90% HIT); single-VM SPOF (2 VMs before decommission); osm-data torn copy (fresh import or stop-rsync-start, never hot-rsync); geocode/tiles shared-host coupling; Sentry per-image `captureMessage` flood in `OurUploadedImage.vue` (remove/rate-limit before delivery cutover). **Observability gap**: app1 + this host don't ship access logs to Loki → put **promtail on the VM** (with `X-Cache-Status` in the log format) so cutover is measurable. Go/No-Go checklist per service: `haproxy -c` passes; exactly-one ACAO; HIT replay >90%; real tile render; NFS latency within ~20% of app1; failover sibling exists before decommission.

---

## 11. AI-safety protocol (executing with the Katapult key)

Capability probe before acting; snapshot/backup before any mutating op; `haproxy -c` + dated snapshot before any reload; NFS mounted **ro** until the human-gated upload cutover; **one host/one service at a time** (no big-bang); **explicit human GO** before each: HAProxy reload, DNS change (+TTL lowering), VM/volume delete, app1/host decommission, NFS→rw. Prod DB writes (if ever) one-row-at-a-time (Galera). Stop and ask on any surprise (missing scope, NFS export rejection, no route to `10.11.3.0/24`, sizing mismatch).

---

## 12. Open decisions

1. **One VM or two** (recommend two: images / tiles+geocode+wiki).
2. Profile name: keep `image-host` or rename to **`edge`/`frontend-host`** (scope broadened).
3. **wiki** scope+timing (stateful MediaWiki + its MySQL — biggest lift; could be its own stage).
4. **geocode** stage (Photon) — bundle with tiles (same source host) or defer.
5. Classify `ai-support-helper` / `status` (confirm not externally required).
6. CORS source for tus (keep at HAProxy) and PROXY-protocol vs XFF on `delivery_backend` (recommend drop send-proxy → XFF).
7. `:8080` upload-URL-form retirement (separate, non-blocking — keep `:8080` indefinitely).
8. `users.ilovefreegle.org` mail-alias migration (separate MX workstream).

---

## Appendix — unrelated incident discovered this session (2026-06-25)
Galera cluster commit-pipeline wedge (~07:16): all 3 nodes Primary/Synced but commits frozen in "replicating and certifying write set" after a BF-abort storm (db1 3671 / db3 3901) starting 07:13:37 — 33 s after the every-minute uncommitted `ripple:expand --within-poly` cron (07:13:04). User's structural hypothesis: **no read/write split in batch** (all batch reads+writes hit db2 while apiv2 writes hit db3 → cross-conflict) — PR en route. Diags saved on each node `/root/galera-diag-20260625-0735-db-{1,2,3}.txt`. Recovery = single-node IST restart of db1 (no bootstrap; cluster had quorum). Also fixed this session: rotated the 7 GB unbounded `iznik-batch/storage/logs/laravel.log` (durable fix still needed: `LOG_CHANNEL=daily` / lower level / logrotate). Separate `cron_job_status.command` unique-key overflow bug truncates the long `--within-group` string. **These are batch/DB concerns, not part of the front-end migration.**
