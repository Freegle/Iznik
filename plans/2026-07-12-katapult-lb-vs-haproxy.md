# Could we shift HAProxy onto Katapult's Load Balancer?

**Date:** 2026-07-12
**Status:** Exploration / decision plan — NOT implemented.
**Motivation:** HAProxy on `ha-internal` (public `185.199.221.13` / `applb`) is a documented single point of failure (a second HAProxy/VIP was declined 2026-07-08). Katapult now offers a managed Load Balancer. Question: can we retire HAProxy onto it?

---

## 1. What Katapult's Load Balancer actually does

Sources: [product page](https://krystal.io/cloud/products/load-balancers/), [intro blog](https://katapult.io/blog/post/introducing-katapult-load-balancers/), [docs — rules](https://docs.katapult.io/manager/how-to-guides/networking/load-balancers/load-balancer-rules), [pricing](https://krystal.io/cloud/pricing/).

**Can do today:**
- **Protocols per rule:** HTTP, HTTPS, **TCP**.
- **TLS:** terminate via Certificate Manager or **automatic Let's Encrypt (HTTP-01)**, OR **pass-through** (backend terminates).
- **HTTP→HTTPS redirect** at the LB (port 80 → 443).
- **Backend encryption** (LB→VM leg can be encrypted).
- **Targets:** Katapult VMs — individually, by group, or by **tag**.
- **Algorithms:** round-robin, least-connections, **sticky**; per-target **weights**.
- **Health checks:** HTTP (path + expected status) or TCP.
- **PROXY protocol** to preserve original client IP to the backend.
- **High availability built in:** multiple LB replicas across separate hosts, round-robined at the network level — so the LB is *not* itself a SPoF.
- Per-LB logs + stats graphs; full **API** management.

**Cannot do today (the blockers):**
- ❌ **Name/host-based routing** — one `:443` fanning out to different backends by `Host` header. Listed as **coming soon**, not available.
- ❌ **Path-based routing** (`/api`, `/.well-known/...`). **Coming soon.**
- ❌ **Response/request header rewriting** — no CORS injection, no `Host` rewrite, no `X-Real-IP`/`X-Forwarded-*` beyond PROXY protocol.
- ❌ **Rate limiting / WAF / custom Lua** — nothing equivalent to our graduated-delay + role-exemption logic.
- ❌ **External (non-Katapult) backends** — can't target an arbitrary internet host (e.g. Netlify) with a Host rewrite.
- ❌ IPv6 / QUIC — coming soon.

**Cost:** £10/LB/month (or £0.01/hr). Egress £0.02/GB out; inbound/internal free.

---

## 2. What our HAProxy does today (the surface a replacement must absorb)

Config: `haproxy/haproxy.cfg`, Lua `haproxy/lua/rate_delay.lua`. Runs bare-metal v2.4.30 on `ha-internal` (`10.220.0.172` = public `185.199.221.13`), reloaded by hand after `haproxy -c`.

1. **TLS termination + Let's Encrypt** for ~9 `*.ilovefreegle.org` hostnames (per-domain PEMs in `/etc/haproxy/`, ACME http-01 → local `127.0.0.1:8888` certbot helper).
2. **Host-based L7 fan-out on a single `:443`** to 6 backends:
   - `api.ilovefreegle.org` → `api_server_backend` (db1/2/3:8192, Katapult VMs).
   - `uploads.ilovefreegle.org` → `tusd_backend` (app1:8080).
   - `delivery` / `uploadcare-cache` / `uploadcare-proxy-cache` → `http_backend_cache` (app1:80, cache-affinity).
   - `modtools.org`/`new.modtools.org` → **Netlify passthrough** (Host rewrite, `ssl verify none`).
   - default (`images`/`cdn`/`users` web) → `http_backend` (app1/app4:80).
3. **Multi-port listeners:** 80, 443, 8080 (tusd), 8192 (api), 1936 (stats).
4. **Custom Lua graduated rate-limiting** (soft 5 r/s → delay 100–2000 ms, hard 429 at 50 r/s) **with role-based exemption** learned from backend `X-User-Role` response headers. API traffic only; static assets exempt.
5. **CORS injection** — `Access-Control-Allow-Origin: *` on image backends; the **full tus CORS header set** on `tusd_backend` (app1 tusd runs `-disable-cors`, so origins depend on the edge adding these).
6. **PROXY protocol** to `:80` backends; `X-Forwarded-Proto/For`, `X-Real-IP` injection.
7. **Source-IP stickiness** (30 m) on every backend.
8. **Backup-server failover** (`backup` + `option redispatch`): db3 active / db1-2 backup; app1 active / app4 backup.

Known cruft to fix regardless: **app4 (10.220.0.188) is dead** but still an *active* (non-backup) server in `http_backend` — latent failover bug.

---

## 3. Feature gap matrix

| HAProxy responsibility | Katapult LB today | Verdict |
|---|---|---|
| TLS termination + LE | ✅ LE HTTP-01 | OK |
| TLS pass-through | ✅ | OK |
| Host-based fan-out (one :443 → 6 backends) | ❌ coming soon | **Blocker** |
| Path routing (`/api`, ACME) | ❌ coming soon | **Blocker** |
| Rate-limit + role exemption (Lua) | ❌ | **Blocker** (must relocate) |
| CORS / header injection | ❌ | **Blocker** (must relocate) |
| Netlify (external) passthrough + Host rewrite | ❌ | **Blocker** (stays on HAProxy or moves to Netlify DNS) |
| Katapult-VM backends, health checks, failover | ✅ groups/tags, HTTP/TCP checks, weights | OK |
| Source-IP stickiness | ✅ sticky mode | OK |
| PROXY protocol / real client IP | ✅ | OK |
| Multi-port listeners | ✅ (one rule per port) | OK |
| Self-HA (no SPoF) | ✅ replicas across hosts | **Better than today** |

**Conclusion: a wholesale lift-and-shift is not possible today.** Four capabilities we actively depend on — host/path routing, Lua rate-limiting, CORS/header injection, and the external Netlify passthrough — have no equivalent. Host/path routing is "coming soon"; the other three would have to be re-homed into the app/origin regardless of Katapult's roadmap.

---

## 4. Three ways forward (recommended first)

### ⭐ Option C — Katapult LB in **TCP pass-through** in front of *two* HAProxy nodes (RECOMMENDED)

Solve the actual problem (SPoF) without re-implementing any L7 logic.

- Stand up a **second HAProxy VM** (identical config, same certs) — `ha-internal` + `ha-internal-2`.
- Create **one Katapult LB** with **TCP rules** for :443, :80, :8080, :8192, **TLS pass-through** (HAProxy keeps terminating), **PROXY protocol enabled** so client IP survives.
- Targets = the two HAProxy VMs (by tag), TCP health check on :443. Source stickiness so a client consistently hits one HAProxy (keeps per-node rate-limit stick-tables coherent).
- HAProxy binds gain `accept-proxy` (e.g. `bind *:443 ssl crt-list ... accept-proxy`); `src` in stick-tables then resolves to the real client IP. Downstream `send-proxy` unchanged.
- Repoint DNS from `185.199.221.13` to the LB's IP.

**Pros:** eliminates the SPoF (both LB *and* HAProxy now redundant); zero loss of Lua rate-limiting, CORS, host routing, Netlify passthrough; config stays exactly what ops already know. **Cost:** £10/mo + one small VM.
**Cons:** two HAProxy configs to keep in sync (mitigate: put `haproxy.cfg` under config management / rsync from the repo mirror); rate-limit stick-tables are per-node (acceptable, or use `peers` to sync them between the two HAProxy nodes).

### Option B — Hybrid: Katapult LB for the **API path only**, HAProxy keeps the rest

The API backend is the cleanest 1:1 fit: single hostname `api.ilovefreegle.org`, single pool of **Katapult VMs** (db1/2/3), TLS terminate + LE, TCP/HTTP health check, sticky, failover.

- New Katapult LB (HTTPS, LE cert) → tag `apiv2`/db1-2-3:8192, health check `GET /` on :8192.
- Repoint `api.ilovefreegle.org` (and :8192 consumers) to the LB.
- **Must relocate first:** the Lua rate-limiting + 429 safety-valve and the role-based exemption → into **apiv2** (middleware), since the LB can't do it. `X-Real-IP` is replaced by PROXY protocol.

**Pros:** removes the busiest, most latency-sensitive path from the SPoF; db backends are already Katapult VMs. **Cons:** rate-limiting must be rebuilt in apiv2 before cutover; two LBs to reason about; HAProxy SPoF still fronts images/uploads/modtools.

### Option A — Full replacement (deferred)

Only viable once Katapult ships **name-based + path-based routing**, and only after:
- Rate-limiting moved into apiv2 (as Option B).
- tus CORS moved into tusd config (drop `-disable-cors`); image CORS moved to origin nginx.
- Netlify passthrough retired — point `modtools.org` DNS straight at Netlify (needs Netlify custom-domain cert, the original blocker) **or** keep a tiny HAProxy/nginx just for that one host.
- Likely several LBs (or one multi-rule LB once host-routing lands) — watch the £10/mo-each cost.

**Verdict:** revisit when Katapult announces name-based routing; not actionable now.

---

## 5. Recommendation

Pursue **Option C**. It directly retires the single-point-of-failure that motivated the question, is achievable with today's Katapult feature set (TCP + pass-through + PROXY protocol are all shipped), and costs one LB plus one VM without rewriting any of the Lua/CORS/routing logic we rely on. Treat **Option B** as a good *first slice* if we want to de-risk the API path sooner, but note it forces the rate-limiter rewrite up front. Keep **Option A** on the shelf until host/path routing ships.

**Pre-work valid under any option:** remove dead app4 from `http_backend` (and the other pools), and get `haproxy.cfg` under real config management so a second node can be stood up reproducibly.

## 6. Open questions to confirm before doing anything
- Does Katapult TCP LB + PROXY protocol interoperate cleanly with HAProxy `accept-proxy` on a TLS-passthrough listener? (Prove on a scratch LB.)
- Can one Katapult LB carry multiple TCP port rules (443/80/8080/8192) to the same target set, or is it one LB per port?
- LE HTTP-01 on the LB vs. our existing certbot-on-HAProxy flow — for Option C we keep certbot on HAProxy (pass-through), so no change; confirm for B.
- Egress accounting: image/delivery traffic is large — £0.02/GB out could matter if those ever move behind a Katapult LB (Option A), less so for API/Option C.
