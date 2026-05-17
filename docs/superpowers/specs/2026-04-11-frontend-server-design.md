# Image Host Server Design

Move image upload (`uploads.ilovefreegle.org`) and image delivery (`delivery.ilovefreegle.org`) off `app1-internal` and onto a new Katapult VM running FreegleDocker with a new `image-host` Compose profile. This is the first stage of retiring `app1-internal`.

Tiles, geocoding, photon, and the legacy `images.ilovefreegle.org` PHP path are out of scope for this design and stay where they are.

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
- The `frontend` profile is already in use as "web-facing APIs" (`apiv1, apiv2, delivery, tusd, redis, beanstalkd`) and is part of the default local-dev profile set in `.env.example` and CircleCI's orb. It is **not** a free name for the image-only VM.
- No `frontend-nginx` service or `frontend-nginx.conf` exists.

## The changes

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

## Cutover sequence

In order, with a real but brief upload outage (no live failover today):

1. Provision the new Katapult VM on the same internal network (10.220.0.0/22). Add to ha-internal `/etc/hosts`.
2. Mount the NFS share at `/srv/tusd-data`. Verify with `ls /srv/tusd-data | head` against `ls /images | head` on app1 — same contents.
3. Clone FreegleDocker, set `COMPOSE_PROFILES=image-host`, `docker compose up -d`.
4. `rsync` `/wsrv_cache` from app1 to the new VM's `delivery-cache` volume directory. Run incrementally; final delta sync happens just before the HAProxy switch.
5. Validate the new VM by bypassing HAProxy with Host headers:
   - `curl -H 'Host: delivery.ilovefreegle.org' http://<new-vm>/?url=https://uploads.ilovefreegle.org/<known-hash>&w=200&h=200` — expect a 200 with `X-Cache-Status: HIT` for a cached entry.
   - `curl -H 'Host: uploads.ilovefreegle.org' -X OPTIONS http://<new-vm>/` — expect tus capability headers.
6. Stop tusd on app1 (`pkill tusd`). In-flight uploads pause; tus is resumable, so clients resume against the new backend on retry.
7. Final `rsync` delta for `/wsrv_cache`.
8. Edit `haproxy.cfg`, snapshot first, reload. The new VM is now serving both hostnames.
9. Watch the new VM's `delivery.access.log` (look for `MISS` rates spiking — the cache key should be preserved), HAProxy stats, and Sentry for image-load failures.

## Rollback

While app1's tusd binary and nginx site config are still in place: revert `haproxy.cfg` from the snapshot, restart bare-metal tusd on app1 via `/var/www/tusd/starttusd`, `systemctl reload haproxy`. NFS is shared so app1 sees the same files when it comes back.

Once app1 is decommissioned this rollback target is gone, and standing up a second image-host VM becomes the only failure recovery option. Decide whether to provision two image-host VMs from the start (one primary, one HAProxy `backup`) based on the cost vs the appetite for running on a single VM during the watch period.

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
