---
last_reviewed: 2026-08-31
owner: Freegle dev team
---

# Geocoder cutover: Photon → the places index in spatial-knn

`geocode.ilovefreegle.org` (forward geocoding of UK place names for the member
site's place search and the jobs feed import) has historically been served by
Photon — a Java service with its own Elasticsearch database, together ~3.6GB of
RAM and ~6GB of disk on the Docker host. Its replacement is built into the
spatial-knn container: a places index extracted from the same OpenStreetMap
file the routing service already uses, answering in the same format on
`GET /api` (see the [spatial servers reference](../../developers/reference/spatial-servers.md)).

The host nginx in front of the domain (10-day response cache, rate limiting)
stays exactly as it is; only its upstream changes.

## Order of operations (each step reversible)

1. **Generate the artifact** on the Docker host with the `placesextract`
   command from the spatial servers reference, writing `places.jsonl.gz` into
   the routing data folder. Confirm the spatial-knn container logs
   `places: loaded <n> entries` (n ≈ 200,000).
2. **Expose the port.** The compose ports overlay publishes spatial-knn's API
   on loopback (`PORT_SPATIAL_KNN`, default 8198) for nginx to reach —
   `docker compose up -d spatial-knn` after pulling the change. Verify with a
   local `curl 'http://127.0.0.1:8198/api?q=Kendal'`.
3. **Replay before flipping.** Fire a day of real queries from the nginx
   access log at both Photon and the new port and compare (the harness and
   the measured parity report live with the PR that introduced this). Do not
   flip on a red replay.
4. **Flip nginx.** Back up the geocode site file, change `proxy_pass` from
   Photon's port to the new one, `nginx -t`, reload. Reloads are graceful —
   verify against a fresh worker, not an instant curl. The response cache
   will keep serving cached Photon answers for repeats for up to 10 days;
   flush the cache directory if you want honest traffic immediately.
5. **Soak.** The jobs import runs every 3 hours and caches its own geocode
   results for days, so a brief misbehaviour is absorbed. Watch the nginx
   error log and the spatial-knn container logs.
6. **Retire Photon** (only after the soak): stop the Photon process and its
   monit supervision, stop the standalone Elasticsearch service, delete the
   ~6GB index. Keep the JARs for a week in case of rollback.

## Rollback

At any point before retirement: restore the backed-up nginx site file and
reload — Photon is still running and warm. After retirement: restart Photon
from the kept JARs (its index rebuild is the slow part; that is why the index
is deleted last).

## Failure modes

- **`/api` answers 503**: the places file is absent or unreadable on that
  instance. nginx does not cache 503s, so this is safe but visible; check the
  container logs and regenerate the file. Instances that never had the file
  (the finder copies on the database hosts) always answer 503 — nothing
  routes public traffic to them.
- **Artifact refresh**: regenerating the file after an OSM refresh is picked
  up within a minute, no restart. A corrupt or truncated file is rejected and
  the previous index keeps serving.
