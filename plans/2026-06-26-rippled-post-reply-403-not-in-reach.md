# Rippled-post reply 403 "not_in_reach" → "Oh No" on app and website

**Date:** 2026-06-26
**Reported by:** fgl@ericaworld.me.uk (LadyF, user 38683453, Android) — "Oh No" error as soon as
logged in and requesting a particular post, on both the app and the website.

## Symptom
Requesting (replying to) certain posts throws the fatal "Oh No" error page. NOT an app-version
issue: reproduced on both the native app (`https://localhost/browse`) and the website
(`https://www.ilovefreegle.org/browse`). Client Sentry logs show:
```
API Error POST /chat/20940106/message ... -> status: 403
API Error POST /chat/20940211/message ... -> status: 403
```
(apiv2 access log mislabels these as 200 — separate logging bug — so the server looked clean at first.)

## Root cause
`iznik-server-go/chat/chatmessage.go:407-417` — the rippling reply-gate. On an Interested reply it
runs:
```sql
SELECT COUNT(*) FROM rippling_reach
WHERE msgid = ? AND ST_Contains(polygon, ST_SRID(POINT(lng,lat), 3857)) = 0
```
and returns `403 not_in_reach` if the post has a reach row whose polygon does NOT contain the
replier's point.

**The inconsistency:** a post is rippled into a *whole group* when the group polygon **intersects**
the reach (`ExpandService::rippleIntoNewGroups`, `ST_Intersects`), but the reply gate requires the
user's **point** to be **inside** the reach polygon (`ST_Contains`). A member living in the part of a
rippled-into group the reach has not yet covered therefore SEES the post in browse but is FORBIDDEN
from requesting it.

### Confirmed with live data (user 38683453, location lat 51.453908, lng -2.139306)
| post | visibility | reach contains her point | result |
|---|---|---|---|
| 120463763 (Oak Table Legs, grp 21244) | not rippled (rippled_in=0) | no reach row → gate skipped | 200 OK |
| 120729713 (coat hangers) | rippled into grp 21592 (she's a member) | NO (tick 6, outside) | 403 |
| 120747027 (castor wheels) | rippled into grp 21592 (she's a member) | NO (tick 4, outside) | 403 |

She is a member of group 21592 (a rippling-experiment group); both failing posts were rippled into
21592 so they appear in her browse, but her home point is outside their reach polygons.

### Secondary aggravator
The chat room is created (POST /chat succeeds, rooms 20940106/20940211 exist, 0 messages) but every
message POST 403s. So she's left with a dead room the app keeps retrying into → repeated "Oh No".

## Resolution: by-design server behaviour + STALE APP (not a server bug)
The `403 not_in_reach` is intentional. The frontend is supposed to show a "hasn't reached you yet"
notice and suppress the reply for not-in-reach rippled posts. The user experience broke only because
her **native app is 9 days stale**.

Client versions (from `webversion` in her requests):
- **App (Capacitor): build 2026-06-17T21:20:29Z** (session e6760302, plat=APP)
- **Website: build 2026-06-26T11:15:10Z** (current)

Frontend reply-gate commits (iznik-nuxt3):
| commit | date | in her 06-17 app |
|---|---|---|
| b0ccd9697 reach-gated reply-eligibility (view-only block + notice) | 2026-06-17 05:58 | yes |
| 7992eca53 enforce reply gate on write path + close ?reply bypass | 2026-06-18 12:27 | NO |
| 594d2f51d **rippled-in no-message reject**, per-group reject, member list | 2026-06-22 14:03 | NO |

Her app has only the first cut and is missing the 06-18 and especially the 06-22 "rippled-in
no-message reject" fix — exactly her case. So the old app shows the reply, lets her POST, and the
server returns the by-design 403 → "Oh No". The current website handles it gracefully.

**Action:** user should update the app (force app-store / OTA bundle refresh). No server change needed.

## Open caveat
The two dead rooms her old app already created (20940106, 20940211, zero messages) may keep
surfacing/erroring even after she updates, since they now exist and the reply-eligibility UI gate is
on the reply action, not on sending into an already-open room. Confirm the updated client handles an
already-open-but-not-in-reach room, or clear those empty rooms.

## Notes
- apiv2 access-log middleware logs these 403 chat-message responses as 200 — worth fixing so this
  class of failure is visible server-side in Loki (it masked the 403 during diagnosis).
- Related: project_ripple_insertselect_lock_storm_20260626, project_ripple_leavecheck_overbroad,
  feedback_rippling_immediate_is_intended, reference_stale_app_ohdear_diagnosis.
