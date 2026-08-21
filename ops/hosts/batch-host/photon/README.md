# photon geocoder — batch host

Photon is the OSM geocoder behind `geocode.ilovefreegle.org`. It is a plain JVM
(`photon-0.5.0.jar`) listening on `127.0.0.1:2322`, started and watched by monit,
with a ~6.3G embedded Elasticsearch 5.6 index at `/var/www/photon/photon_data`.
nginx (`/etc/nginx/sites-available/geocode`) terminates TLS and proxy-caches it.

Two files here; the monit check itself is at
`../../monit/batch-host/conf.d/photon`.

| file | destination | mode |
|---|---|---|
| `etc-photon` | `/etc/photon` | 0755 |
| `monit.service.d-photon-jvm.conf` | `/etc/systemd/system/monit.service.d/photon-jvm.conf` | 0644 |

```sh
cp etc-photon /etc/photon && chmod 755 /etc/photon
mkdir -p /etc/systemd/system/monit.service.d
cp monit.service.d-photon-jvm.conf /etc/systemd/system/monit.service.d/photon-jvm.conf
systemctl daemon-reload && systemctl restart monit   # sandbox applies at process start
```

The index itself is **not** captured here — it is 6.3G of generated data, not
config. Rebuild or restore it separately; `/var/www/photon/photon_data` must
exist and be writable by root before photon will start.

## Why the drop-in exists — read this before "tidying" it away

`monit.service` ships systemd hardening, and **every process monit spawns
inherits it**. Three separate settings each stop photon dead, and they surface
one at a time, so this was misdiagnosed twice as a photon/JVM problem:

| setting | symptom | fix |
|---|---|---|
| `MemoryDenyWriteExecute=true` | JVM needs W+X for the JIT: `Error occurred during initialization of VM` | `MemoryDenyWriteExecute=false` (2026-07-07) |
| `ProtectSystem=strict` + `ReadWritePaths=/run /var/lib/monit /var/log` | whole FS read-only to monit's children: `FileSystemException: .../antlr4-runtime.jar: Read-only file system` | `ReadWritePaths=/var/www/photon` (2026-08-21) |
| `CapabilityBoundingSet` omits `CAP_DAC_OVERRIDE` | root gets no permission bypass, and `photon_data` was owned by uid 998 `systemd-network` mode 755 (tarball extracted with a numeric uid): `AccessDeniedException` on the same path | `chown -R root:root /var/www/photon/photon_data` (2026-08-21) |

The third one is fixed by ownership on the host, not by a file here — if the
index is ever restored from a tarball, re-check it with
`find /var/www/photon ! -user root | head`. **Chown it; do not add
`CAP_DAC_OVERRIDE` back**, which would hand that capability to everything monit
spawns.

`-Xmx4g` in `etc-photon` is also required: with no explicit cap the JVM tries to
reserve ~25% of RAM and fails during a boot storm. The index is mmap'd into page
cache, not heap, so 4G is ample.

## Why this hid for about a year

Hand-starting from a root shell has none of these restrictions, so it always
worked — and the "start it by hand" recipe quietly became the only way photon
ever started. monit's start path had never once worked. After the 2026-08-21
reboot monit looped restart-fail every 2 minutes from 11:16 and nobody saw it,
because the old `/etc/photon` used `nohup ... &` and monit's stdout is not a tty,
so `nohup.out` was never written and every error was discarded. Hence the
explicit `>> /var/log/photon-start.log` now in `etc-photon`. **If you change that
launcher, keep it logging somewhere.**

## Two traps when debugging this

- **monit's process check false-positives on your own shell.** The check is
  `matching "photon.*jar"`, which also matches the command lines of commands you
  are running, so `monit summary` can report photon `OK` while it is dead. Keep
  that literal string out of your commands (`ps ... | grep -i phot`) and trust
  the port check. Same reason `pkill -f 'photon.*jar'` kills itself.
- **The nginx cache hides outages.** `geocache` serves 200s for 10 days, so
  repeat queries keep answering while photon is down and a total outage looks
  like partial breakage. Check on the host:
  `curl -s -o /dev/null -w '%{http_code}' 'http://localhost:2322/api?q=london'`.

## Verifying a restore

Do not accept "monit says OK". Exercise the real path:

```sh
# kill the JVM, then let monit bring it back unaided
kill $(ps -eo pid,args | grep java | grep -i phot | grep -v grep | awk '{print $1}')
monit start photon
until curl -s -m 3 -o /dev/null 'http://localhost:2322/api?q=london'; do sleep 5; done
ps -eo pid,ppid,sid,args | grep java | grep -i phot | grep -v grep   # PPID must be 1
```

`PPID 1` confirms `setsid` detached it into its own session, so it sits outside
monit's cgroup and survives `systemctl restart monit`. Index load is ~40s.
