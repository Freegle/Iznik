# Front-End Server Design

Stand up a new Katapult VM running FreegleDocker that consolidates the latency-sensitive, user-facing services currently scattered across bare-metal and ad-hoc Docker on two different hosts:

- **Stage 1 — Images** (`uploads.ilovefreegle.org`, `delivery.ilovefreegle.org`): move image upload (tusd) and image delivery (weserv) off `app1-internal`. First stage of retiring `app1-internal`.
- **Stage 2 — Map tiles** (`tiles.ilovefreegle.org`): move the `overv/openstreetmap-tile-server` container off the shared `docker` host (10.220.0.103) into the same Compose stack.

The two stages are deliberately separable: they live on **different source hosts**, cut over by **different mechanisms** (images via the `applb` load balancer, tiles via a DNS A-record change), and have **very different data-copy profiles** (images = shared NFS, no copy; tiles = ~100 GB volume rsync). They can ship independently in either order.

> **Status (2026-06-13): the front-end VM has not been provisioned yet.** Everything below is the design + verified current state, not a record of work done. The Compose services for Stage 1 (`delivery`, `tusd`) already exist in `docker-compose.yml`; the Stage 2 tile service and the `frontend-nginx` proxy do not yet exist in Compose.

> **Status (2026-08-21): the uploadcare ACLs referred to below are gone.** Image delivery is tusd
> end to end and Uploadcare is retired, so `uploadcare-cache` / `uploadcare-proxy-cache` have been
> dropped rather than migrated, and `http_backend_cache` no longer exists. Read the warnings below
> about not repointing that shared backend as a record of the constraint at the time: they no
> longer apply, and taking them at face value leads you to restore routing for two dead hostnames.

**Out of scope (stays where it is for now):**
- **Geocoding** (`geocode.ilovefreegle.org`) — Photon runs bare-metal on the same `docker` host as the tile server (Java, `127.0.0.1:2322`). It is a natural Stage 3 (same source host, same DNS-repoint cutover as tiles) but is not designed here.
- **ORS routing** (`ors-app` container on the `docker` host) — running but not used; superseded by PR459, which is not yet merged. It is not part of the front-end VM and gets dropped from the `docker` host once PR459 lands, independently of this work.
- **`images.ilovefreegle.org`** (legacy PHP serving, ~557 req/day, 0.06 % of image traffic) — stays on app1 until the wider app1 retirement.

## Live state and cutover profile

The two stages differ in how each hostname is fronted, how cutover happens, and how much data must move. This table is the load-bearing summary for the rest of the design:

| Hostname | Fronted by | Cutover mechanism | Data to move |
|----------|------------|-------------------|--------------|
| `uploads.ilovefreegle.org` | `applb` (CNAME → 185.199.221.13) | LB/HAProxy backend switch — **no DNS change** | None — image store is shared NFS |
| `delivery.ilovefreegle.org` | `applb` (CNAME → 185.199.221.13) | LB/HAProxy backend switch — **no DNS change** | rsync ~41 GB nginx cache (warm-start only) |
| `tiles.ilovefreegle.org` | **Direct A record → host public IP** | **DNS A-record repoint — TTL-bound** (TTL ~60 s) | **rsync ~100 GB local volumes** |
| `geocode.ilovefreegle.org` | Direct A record → host public IP | DNS A-record repoint (TTL ~3600 s — lower first) | rsync ~6 GB photon index |

Source hosts and key facts the design relies on:

- **Images live on `app1-internal` (10.220.0.45).** `tusd` and the delivery nginx run bare-metal there; the image store is a Katapult NFS share both app1 and the new VM can mount at once (no copy). Detail in "Current state" below.
- **Tiles live on the `docker` host (10.220.0.103, public 185.44.254.12)** as a single non-Compose `overv/openstreetmap-tile-server` container: `:8080→:80`, `restart=always`, `UPDATES=enabled` (minutely planet replication), `ALLOW_CORS=enabled`, `THREADS=100`. Volumes `osm-data` (PostGIS DB, **56 GB**) and `osm-tiles` (rendered cache, **~44 GB**). It both stores the OSM DB and renders/caches tiles. This host also runs batch work, Photon, ORS, and the wiki — co-locating the tile tier with batch is one reason to move it.
- **`delivery` and `tusd` are defined in `docker-compose.yml` on the `frontend` profile** (their only profile). `frontend` already means the local-dev/CI "web-facing APIs" set (`apiv1, apiv2, delivery, tusd, redis, beanstalkd`), so the new VM uses a separate **`image-host`** profile rather than overloading `frontend`.
- **`delivery` is weserv itself** (`ghcr.io/weserv/images:5.x`, fronted by `delivery-nginx.conf` + `delivery-imagesweserv.conf`). On the new VM, `frontend-nginx` caches in front of this local weserv container instead of proxying to the external `wsrv.nl` that app1 uses today — removing the wsrv.nl dependency. Cache-key preservation (Stage 1, §2) keeps the migrated cache valid across that upstream change.
- **`frontend-nginx` and the tile server are not in Compose yet**, and `frontend-nginx.conf` does not exist. They are created by this design.

## Rollout: one service at a time, never a big bang

Provision the server once with no production traffic, then migrate one hostname at a time. Each step is independently validated and independently reversible, so a problem with one service never blocks or rolls back another.

| Step | What moves | Cutover action | Rollback |
|------|-----------|----------------|----------|
| **0. Provision** | Nothing (no prod traffic) | Stand up the `image-host` stack; validate every service via `Host:` headers only | Tear down the VM; production untouched |
| **1. Uploads** | `tusd` | Repoint `tusd_backend` → new VM | Revert `tusd_backend`; app1 tusd still running |
| **2. Delivery** | `delivery` (weserv + cache) | Add `delivery_backend`, switch the `delivery` ACL → new VM | Revert the ACL; app1 delivery nginx still running |
| **3. Tiles** | `tile-server` | DNS A-record → new VM (TTL-bound) | Revert the A record; old tile server still running |
| **4. Geocode** *(future)* | Photon | DNS A-record → new VM | Revert the A record |

Notes on ordering:

- **Step 0 changes nothing in production.** The whole stack runs on the new VM and is exercised with `curl -H 'Host: …'` against the VM's IP. Only when a service passes its checks do you do the one small cutover action for that service.
- **Steps 1 and 2 are genuinely independent.** Both the app1 `tusd` and the new VM's `tusd` serve the *same* NFS share, so the new VM's `delivery` (weserv) reads originals correctly whether or not uploads has cut over yet — and vice versa. Do them in either order, a day or a week apart.
- **Step 3 is independent of the images steps** — different source host, DNS cutover instead of an LB switch. It can come before or after the image steps.
- A single service can sit cut-over for as long as you like before moving the next one. There is no flag-day where everything flips at once.

## Why images first

- Largest single workload on app1-internal: ~100K delivery requests/day at ~92% cache hit, plus all resumable image uploads.
- Self-contained — delivery and tusd have no app1-side database or PHP coupling. The image store is on a Katapult NFS share that the new VM can mount the same way.
- app1-internal is being retired and there is no live failover for tusd today. Standing up the new VM removes a single point of failure at the same time as it removes a retirement blocker.

## Current state (the starting point)

### app1-internal (10.220.0.45)

- **TuSD** runs bare-metal as `/var/www/tusd/tusd -upload-dir=images -behind-proxy -base-path / -disable-cors` on port 8080, started with `cwd=/`. The `-upload-dir=images` argument therefore resolves to **`/images/`, the NFS mount** (verified via `/proc/<pid>/cwd` and an open FD on `/images/.nfs*`). The local directory `/var/www/tusd/images/` exists but is unrelated leftover.
- **NFS mount** at `/images` from `nfs2.nlc.storage.katapult.io:/katapult/fsv_5ivInYUXp22oVueE` (NFS v3, TCP, 1MB rsize/wsize) — this is the canonical image store. **~823GB used of 4.9TB**, served directly by tusd as the upload directory.
- **Delivery nginx** at `delivery.ilovefreegle.org` with `proxy_cache_path /wsrv_cache levels=1:2 keys_zone=wsrv_cache:100m max_size=40g inactive=30d use_temp_path=off`, ~41GB on disk. Cache misses proxy to `https://wsrv.nl` (external free service), which fetches originals back from tusd over the public internet. Default cache key (`$scheme$proxy_host$request_uri`, no explicit `proxy_cache_key`). `proxy_intercept_errors on` plus a `@handle_redirect` location for 301/302/307 from wsrv.nl. Measured hit rate ~92%.
- `images.ilovefreegle.org` (legacy PHP image serving, ~557 req/day, 0.06% of image traffic) stays on app1-internal — out of scope.
- No Docker installed. No process supervision beyond `nohup` + a monit-driven `checktusd` script that polls `https://wsrv.nl/quota`.

### HAProxy on ha-internal (10.220.0.172)

- TLS termination for all `*.ilovefreegle.org` hostnames using per-domain certs in `/etc/haproxy/*.pem`.
- Three frontends matter here:
  - `http_frontend` binds `*:80` — plain HTTP, redirects to HTTPS.
  - `https_frontend` binds `*:443` with the cert-list, contains the host-based ACLs.
  - `tusd_frontend` binds `*:8080` only (with `uploads.ilovefreegle.org.pem`) — dedicated to tus uploads on the alt port.
- Routing for the hostnames in scope:
  - `uploads.ilovefreegle.org:443` → matched by `acl uploader hdr(host) -i uploads.ilovefreegle.org` inside `https_frontend` → `tusd_backend`.
  - `uploads.ilovefreegle.org:8080` → `tusd_frontend` → `tusd_backend` (no ACL — the whole frontend is for tusd).
  - `delivery.ilovefreegle.org:443` → ACL inside `https_frontend` → `http_backend_cache`.
  - `images.ilovefreegle.org:443` → default `http_backend`.
- **`http_backend_cache` is shared** by three ACLs: `delivery`, `uploadcare-cache.ilovefreegle.org`, and `uploadcare-proxy-cache.ilovefreegle.org`. All three currently land on `server app1 10.220.0.45:80 send-proxy check` with stale `server app4 10.220.0.188:80 send-proxy check backup`. The new VM cannot reuse this backend without also taking the uploadcare ACLs with it — a new backend is needed (see "The changes").
- **`tusd_backend`** lists `server app1 10.220.0.45:8080 check` plus the stale `server app4 10.220.0.188:8080 check backup`. HAProxy adds `X-Forwarded-Proto: https`, `X-Forwarded-For`, and all tus-related CORS response headers here. Plain HTTP to the backend (no `send-proxy`).
- **`http_backend` and `http_backend_cache` both use `send-proxy`** to the `:80` of app1/app4 (PROXY protocol). The app1 delivery vhost itself listens with plain `listen 80;` (no `proxy_protocol`), so it relies on whatever main-server config terminates PROXY ahead of the vhost — keep that detail in mind when validating the new VM's nginx (we either accept PROXY on the delivery listener or drop `send-proxy` on the new backend).
- **app4 is retired**. Every `server app4 …` line — in `tusd_backend`, `http_backend`, and `http_backend_cache` — is dead config. They should be cleaned up at cutover, not just the tusd one.
- Operators version `haproxy.cfg.*` with date suffixes (e.g. `haproxy.cfg.bak.20260515-pre8194`). The cutover edit follows the same convention.

### FreegleDocker repo

- `delivery` and `tusd` services already exist in `docker-compose.yml`. Both carry the `frontend` profile.
- The `frontend` profile is already in use as "web-facing APIs" (`apiv1, apiv2, delivery, tusd, redis, beanstalkd`) and is part of the default local-dev profile set in `.env.example` and CircleCI's orb. It is **not** a free name for the new front-end VM.
- No `frontend-nginx` service or `frontend-nginx.conf` exists.

## Stage 1 — Images: the changes

### 1. Compose: new `image-host` profile

Add `image-host` alongside the existing `frontend` profile on `delivery` and `tusd`. Define a new `frontend-nginx` service in `image-host` only.

```yaml
delivery:
  profiles:
    - frontend
    - image-host
  # …existing config unchanged…

tusd:
  profiles:
    - frontend
    - image-host
  # …existing config unchanged, EXCEPT:
  command: -upload-dir=/srv/tusd-data -behind-proxy -base-path / -disable-cors
  volumes:
    - tusd-data:/srv/tusd-data
  # …on the new VM only, tusd-data is bind-mounted to the NFS share
  #   (see "NFS mount on the new VM" below). In local dev tusd-data
  #   remains a Docker volume.

frontend-nginx:
  image: nginx:alpine
  profiles:
    - image-host
  ports:
    - "80:80"
    - "8080:8080"
  volumes:
    - ./frontend-nginx.conf:/etc/nginx/nginx.conf:ro
    - delivery-cache:/var/cache/nginx/wsrv_cache
  depends_on:
    - delivery
    - tusd

volumes:
  delivery-cache:
  tusd-data:
```

Local dev is unaffected — `image-host` is not in the default `COMPOSE_PROFILES` set, so `frontend-nginx` does not start unless an operator opts in. The `frontend` profile keeps its existing meaning and members.

The new VM sets `COMPOSE_PROFILES=image-host` only — apiv1/apiv2/redis/beanstalkd do not start there.

### 2. nginx config: `frontend-nginx.conf`

The new nginx in front of weserv replaces the bare-metal `delivery` nginx on app1. Key requirements:

- Same `proxy_cache_path` settings so the transferred 41GB cache is readable: `levels=1:2 keys_zone=wsrv_cache:100m max_size=40g inactive=30d use_temp_path=off`.
- Same cache key as today. Since the live cache was written with `$proxy_host=wsrv.nl` and the new upstream is a local container, set `proxy_cache_key "https://wsrv.nl$request_uri"` explicitly — this preserves the warm cache regardless of the local upstream choice.
- Mirror the `@handle_redirect` location for wsrv-style 301/302/307s (weserv may rarely return them; keep parity).
- `uploads.ilovefreegle.org` server block on both `:80` and `:8080`, `proxy_pass http://tusd:8080`, `client_max_body_size 100M`, `proxy_request_buffering off`, `proxy_buffering off`, `proxy_http_version 1.1`, forward `Host` and `X-Forwarded-*` headers (tusd is `-behind-proxy`).

The `:8080` binding on `frontend-nginx` (and the matching `tusd_frontend` on HAProxy) stays indefinitely: the `:8080` URL form (`?url=https://uploads.ilovefreegle.org:8080/{hash}/`) is in active live traffic — observed in current delivery access logs from clients including third-party apps like Freebie Alerts — not just from 30-day-old cache entries. Dropping `:8080` requires a separate piece of work to identify and update every source that constructs the `:8080` form (frontend Vue/Nuxt code, V1 PHP `TUS_UPLOADER` env, anything that persists URLs into the DB or external client caches).

### 3. NFS mount on the new VM

The new VM mounts the same Katapult NFS share that app1-internal uses today:

```
nfs2.nlc.storage.katapult.io:/katapult/fsv_5ivInYUXp22oVueE  /srv/tusd-data  nfs  vers=3,tcp,rsize=1048576,wsize=1048576,hard  0  0
```

(Mounted at `/srv/tusd-data` on the host so the bind mount into the tusd container is direct.)

Because NFS is shared, both app1 and the new VM can mount it simultaneously during cutover. There is no data copy step for the 823GB image store — only an HAProxy backend switch.

### 4. HAProxy edit on ha-internal

In `/etc/haproxy/haproxy.cfg`:

- In `backend tusd_backend`: replace `server app1 10.220.0.45:8080 check` with the new VM's IP on port 8080. Delete the stale `server app4 10.220.0.188:8080 check backup` line.
- **Add a new `backend delivery_backend`** (don't repoint `http_backend_cache` — it's shared with the uploadcare ACLs). Same mode/balance/stick-table shape as `http_backend_cache`. Point its `server` line at the new VM. Since the new VM's `frontend-nginx` will read client IPs from `X-Forwarded-For` (set by HAProxy), the new server line can drop `send-proxy` — simpler than enabling `proxy_protocol` listener config on the new nginx. Decide explicitly during cutover, document the choice.
- Change `use_backend http_backend_cache if delivery` to `use_backend delivery_backend if delivery`. Uploadcare ACLs continue to point at `http_backend_cache` and are unaffected.
- Optional housekeeping (not required for cutover): remove the stale `server app4 …` lines from `http_backend` and `http_backend_cache` while we're in the file.
- Snapshot the file as `haproxy.cfg.bak.YYYYMMDD-pre-image-host` first (matches existing operator convention).

Single `systemctl reload haproxy` applies all changes atomically.

### 5. Decommission on app1-internal

After ~1 week of clean operation on the new VM:

- Stop bare-metal tusd and remove its monit entry (`checktusd` no longer needed once we are off wsrv.nl).
- Disable `delivery` and `iznik_delivery` sites in `/etc/nginx/sites-enabled/`.
- Delete `/wsrv_cache` (~41GB), bringing app1 disk usage from ~75% to ~48%.
- Unmount `/images` on app1 (it stays mounted on the new VM).

`images.ilovefreegle.org` (legacy PHP serving) stays on app1 until the wider app1 retirement work.

## Step 0 — Provision (no production traffic)

Stand the whole stack up and prove it works before touching any live routing.

1. Provision the new Katapult VM on the same internal network (10.220.0.0/22), with a public IP for the later tile DNS cutover. Add to ha-internal `/etc/hosts`.
2. Mount the NFS share at `/srv/tusd-data`. Verify with `ls /srv/tusd-data | head` against `ls /images | head` on app1 — same contents.
3. Clone FreegleDocker, set `COMPOSE_PROFILES=image-host`, `docker compose up -d`.
4. Validate every service by bypassing all live routing with `Host:` headers against the VM's IP:
   - `curl -H 'Host: uploads.ilovefreegle.org' -X OPTIONS http://<new-vm>/` — expect tus capability headers.
   - `curl -H 'Host: delivery.ilovefreegle.org' 'http://<new-vm>/?url=https://uploads.ilovefreegle.org/<known-hash>&w=200&h=200'` — expect 200.
   - `curl -H 'Host: tiles.ilovefreegle.org' http://<new-vm>/tile/0/0/0.png` — expect 200 (after Stage 2 data is in place).

Nothing public has changed at this point — rollback is "tear down the VM."

## Step 1 — Cut over uploads (`tusd`)

1. The new VM's `tusd` is already serving the shared NFS (Step 0). No data copy.
2. Edit `haproxy.cfg` (snapshot first as `haproxy.cfg.bak.YYYYMMDD-pre-uploads`): in `backend tusd_backend`, replace `server app1 10.220.0.45:8080 check` with the new VM, delete the stale `server app4 …` line. `systemctl reload haproxy`.
3. There is a brief upload pause for any in-flight upload at the moment of reload; tus is resumable, so clients resume against the new backend on retry.
4. Watch tusd logs on the new VM and Sentry for upload failures.
5. **Rollback:** revert `tusd_backend` from the snapshot, reload. app1 tusd is still running and serves the same NFS.

## Step 2 — Cut over delivery (`weserv` + cache)

1. `rsync` `/wsrv_cache` from app1 to the new VM's `delivery-cache` volume directory. Run incrementally over days; one final delta pass just before the switch keeps the 41 GB cache warm.
2. Validate again with the delivery `Host:`-header curl above, expecting `X-Cache-Status: HIT` for a known-cached entry (confirms the cache key matched).
3. Edit `haproxy.cfg` (snapshot first): **add** `backend delivery_backend` pointing at the new VM (do *not* repoint `http_backend_cache` — it is shared with the uploadcare ACLs), and change `use_backend http_backend_cache if delivery` → `use_backend delivery_backend if delivery`. `systemctl reload haproxy`.
4. Watch the new VM's `delivery.access.log` for a `MISS` spike (would mean the cache key didn't match), plus HAProxy stats and Sentry.
5. **Rollback:** revert the ACL line from the snapshot, reload. app1's delivery nginx is still running.

After both image steps are clean for ~1 week, run the app1 decommission (Stage 1, §5).

### Failover note

While app1's tusd binary and nginx site config are still in place, each step's rollback above restores service immediately (NFS is shared, so app1 sees the same files when it comes back). Once app1 is decommissioned that rollback target is gone, and a second image-host VM becomes the only recovery option. Decide whether to provision two VMs from the start (one primary, one HAProxy `backup`) based on cost vs the appetite for running on a single VM during the watch period.

## Stage 2 — Map tiles

Move the `overv/openstreetmap-tile-server` container off the shared `docker` host (10.220.0.103) into the front-end Compose stack. This is independent of Stage 1 — different source host, different cutover mechanism, different data story — and can ship before or after it.

### What the tile server is today

A single non-Compose container, started by hand (`docker run … overv/openstreetmap-tile-server run`), `RESTART=always`. Inside it: PostGIS + `osm2pgsql` + `renderd` + Apache + `mod_tile`. It both **stores** the OSM database (`osm-data`, 56 GB PostGIS) and **renders + caches** raster tiles on demand (`osm-tiles`, ~44 GB). `UPDATES=enabled` keeps the DB current via minutely planet replication (`REPLICATION_URL=planet.openstreetmap.org/replication/minute/`), so the DB is **live and continuously mutating** — this is the crux of the data-copy problem.

`tiles.ilovefreegle.org` resolves **directly** to this host's public IP (185.44.254.12); there is no `applb`/HAProxy hop in front of it. Cutover is therefore a **DNS change**, not a backend switch.

### 1. Compose: add the tile server to `image-host`

```yaml
tile-server:
  image: overv/openstreetmap-tile-server
  profiles:
    - image-host
  command: ["run"]
  shm_size: 256mb
  environment:
    - UPDATES=enabled
    - ALLOW_CORS=enabled
    - REPLICATION_URL=https://planet.openstreetmap.org/replication/minute/
    - MAX_INTERVAL_SECONDS=60
    - OSM2PGSQL_EXTRA_ARGS=-C 4096
    - THREADS=100
    - AUTOVACUUM=on
  volumes:
    - osm-data:/data/database
    - osm-tiles:/data/tiles
  restart: unless-stopped

volumes:
  osm-data:
  osm-tiles:
```

Env values mirror the live container exactly (verified via `docker inspect`). The new VM still sets `COMPOSE_PROFILES=image-host`, so the same profile carries delivery + tusd + frontend-nginx + tile-server.

`frontend-nginx` gains a `tiles.ilovefreegle.org` server block. Whether to put an nginx disk cache in front of the tile server is **optional**: `mod_tile`/`renderd` already cache rendered tiles on disk in `osm-tiles` and serve them fast, so the nginx layer mostly adds CORS/header normalisation and a single TLS-less front door. If we do add a tile cache zone, keep it separate from the image cache (`proxy_cache_path … keys_zone=tiles:10m max_size=10g inactive=30d`) because 256×256 PNGs and large variable-size image transforms have very different eviction profiles.

### 2. Data copy — the part with real downtime risk

Unlike images (shared NFS, zero copy), the ~100 GB of tile state lives in **local Docker volumes** that must physically move to the new VM. The two volumes need different handling:

- **`osm-tiles` (~44 GB rendered cache): rsync live, staleness-tolerant.** It is just a directory of pre-rendered PNGs. Copying it slightly stale is harmless — `mod_tile` re-renders anything missing or expired on first request. rsync it incrementally while the old server keeps serving, then a final delta pass at cutover. Not copying it at all is even an option (the new server renders cold), but a warm copy avoids a render-storm spike against the fresh DB.
- **`osm-data` (56 GB live PostGIS): cannot be rsync'd hot safely.** rsync of a running Postgres data directory yields a torn, unusable copy. Three options, cheapest first:
  1. **Stop-rsync-start (recommended).** rsync once while live to pre-stage the bulk (inconsistent but ~99 % of bytes), then **stop the tile container** on the old host, rsync the final delta (now consistent), and start the container on the new VM. The old container is down only for the delta pass (minutes, since the bulk is already staged). **Crucially, this is invisible to users** — DNS still points at the old host's *public IP* during the copy, but the old host can keep an Apache/mod_tile serving the cached `osm-tiles` even with the DB container stopped only if rendering isn't needed; simplest is to accept that **the old box keeps serving until the very end** and do the stop only in the final delta window. Minutely replication resumes on the new VM from the saved `state.txt`/sequence in `osm-data`, automatically catching up the minutes missed during the copy.
  2. **Fresh import on the new VM.** Skip the copy: import a current planet/region extract on the new VM ahead of time and let replication catch up. No coupling to the old box, but a full import is hours-to-days of CPU and needs the same extract the original used — heavier than the rsync.
  3. **pg_basebackup / DB-level dump.** Overkill for a single-node render DB; mentioned only for completeness.

**Net downtime:** with option 1 and the old server serving throughout, **users see no tile outage** — only a brief window where the *old* DB is read-only/stopped for the final delta, during which the old Apache still answers from the rendered cache. The only thing that "pauses" is minute-replication catch-up, which is self-healing.

### 3. Cutover — DNS, and why TTL matters here (but not for images)

Because `tiles.ilovefreegle.org` is a **direct A record** (185.44.254.12 → new VM's public IP), cutover is a DNS change and is governed by **DNS TTL**, which is exactly why this needs care that Stage 1 (images, behind `applb`) does not:

1. **Lower the TTL first.** `tiles.ilovefreegle.org` currently has a short TTL (~60 s observed), so propagation is already fast — but confirm and, if it's higher at the authoritative server, drop it to 60 s **at least `old_TTL` seconds before** cutover so caches expire on the new short value. (`geocode.ilovefreegle.org`, if/when Stage 3 happens, currently has a ~3600 s TTL and *must* be lowered ~1 h ahead.)
2. **Run both servers in parallel across the TTL window.** Keep the old tile server up and serving. After the data copy + final delta, the new VM is rendering/serving correctly. Flip the A record to the new VM's public IP. For up to one TTL (~60 s) some resolvers still hit the old box — harmless, because both serve the same tiles. **No flag-day, no hard outage.**
3. **Verify before flipping** by bypassing DNS with a Host header:
   `curl -H 'Host: tiles.ilovefreegle.org' http://<new-vm-public-ip>/tile/0/0/0.png` → expect 200, and a few real `{z}/{x}/{y}` tiles in a populated area to confirm the DB + render path work, not just the cache.
4. **Watch** `renderd`/Apache logs on the new VM for a render-storm (cold `osm-tiles`), replication lag (`osm2pgsql-replication status`), and Sentry/map errors in the apps.

If tiles are later moved behind `applb` (so they cut over like images, no DNS), that removes the TTL dependency — but it is extra LB config and not required.

### 4. Decommission on the old host

After ~1 week clean on the new VM: stop and `docker rm` the old `overv/openstreetmap-tile-server` container, and remove the `osm-data`/`osm-tiles` volumes once confident (frees ~100 GB on the `docker` host). Leave Photon alone (it is the Stage 3 geocode source, still live). ORS is unused and is dropped separately when PR459 merges — not part of this step.

### Rollback (tiles)

DNS is the only thing that changed, and the old container/volumes are still in place during the watch week: revert the A record to 185.44.254.12. Within one TTL (~60 s) traffic returns to the old server, which never stopped serving. The new VM's replication state is independent, so re-cutting over later just resumes from wherever its `osm-data` reached.

## Local-dev behaviour

Unchanged. Local dev uses traefik to route to the existing `delivery` and `tusd` containers via the `frontend` profile. `frontend-nginx` is in `image-host` only and does not start in the default local-dev profile set.

To smoke-test the new path locally before cutover, an operator can opt in with `COMPOSE_PROFILES=image-host docker compose up -d frontend-nginx` in an isolated worktree (ports remapped to avoid clashing with traefik).

## Risks and watch items

- **Cache key preservation**: if `proxy_cache_key` doesn't exactly match what the live nginx produces, the transferred 41GB cache rewarms from cold and wsrv.nl gets a traffic spike during warmup. Validate with a single sample URL on the staged VM before cutover.
- **NFS performance on the new VM**: Katapult NFS is the same provider/network, but verify read/write latency from the new VM matches app1's before assuming parity. A degraded NFS read time would surface as slower tusd serves and a lower delivery hit ratio.
- **No live failover during cutover**: app4 is retired. A single VM provision means a single-VM-outage risk window between cutover and the next provisioning step. The two-VM option (above) closes this.
- **CORS source**: tusd CORS headers come from HAProxy today. Confirm HAProxy continues to add them to the new VM's responses, or move the headers into `frontend-nginx.conf` to remove the HAProxy coupling.
- **Sentry image-failure flood**: `OurUploadedImage.vue` calls `Sentry.captureMessage('Failed to fetch image …')` per broken image. During any maintenance window on the new VM this fires per image per page. Remove the Sentry call (placeholder behaviour is already correct) or rate-limit it, and rely on infrastructure monitoring for delivery health instead.

## Open questions

- **One image-host VM or two?** Cost vs redundancy. Recommendation: two, with HAProxy primary/backup.
- **PROXY protocol on the delivery listener?** Two ways for the new VM's `frontend-nginx` to see real client IPs:
  - HAProxy keeps `send-proxy` on the new `delivery_backend` server line; `frontend-nginx` listens with `proxy_protocol` on its delivery server block.
  - HAProxy drops `send-proxy` and relies on `X-Forwarded-For` (which it already sets); `frontend-nginx` listens plain. Simpler — recommended unless something downstream needs the real source IP at TCP level.
- **`:8080` retirement (separate work, not blocking)**: identifying everything that constructs `https://uploads.ilovefreegle.org:8080/` URLs (Vue components, V1 `TUS_UPLOADER`, persisted DB columns, third-party apps) and migrating them to the no-port form. Until that's done, the `:8080` listener stays in both HAProxy and `frontend-nginx`.
- **CORS at HAProxy or at `frontend-nginx`?** Status-quo (HAProxy tusd_backend response-headers) is simpler but couples this design to a config we don't own. Move-into-nginx is cleaner but is an extra change to validate during cutover.
- **Clean up stale `app4` lines in `http_backend` and `http_backend_cache` as part of this cutover, or as separate housekeeping?** They're not in our path but they're dead config in the same file we're editing.
