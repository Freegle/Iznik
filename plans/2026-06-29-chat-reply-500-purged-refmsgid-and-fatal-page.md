# Chat reply "Oh Dear" — purged-refmsgid 500 + ungated chat send throws fatal page

**Date:** 2026-06-29
**Reported by:** geeks@ilovefreegle.org, via shelliewhitehead@hotmail.com (user 40898048, iPhone)

## Symptom
shelliewhitehead could log in again after updating the app, but then saw the global
"Oh Dear, something went wrong" (`error.vue`). The concrete signal: **`POST /chat/20939833/message`
returned HTTP 500**.

## Diagnosis
Two distinct defects both surface as the same fatal page because the chat send path doesn't catch
API failures.

### 1. The 500 itself — purged `refmsgid` → swallowed FK violation
- `chat_messages` has FK `_chat_messages_ibfk_3: refmsgid → messages.id` (and `_chat_messages_ibfk_1:
  chatid → chat_rooms.id`).
- Prod purges deleted/rejected/expired posts quickly (`reference_deleted_messages_purged`).
- When she replies (Interested) to a post whose `messages` row has since been purged, the reply flow
  creates the User2User room, then `POST /chat/{id}/message` carries `refmsgid` = the purged post.
  `db.Create(&payload)` violates the FK and fails.
- `CreateChatMessage` (`iznik-server-go/chat/chatmessage.go`) **only checked `newid == 0`** and
  threw away the `db.Create` error → generic 500 "Error creating chat message", with the real cause
  (FK violation) never logged. The apiv2 access log doesn't record 500s at all (the `status_code`
  label has no `500` value), and nothing reached the Go Sentry project — so the failure was invisible
  everywhere.
- Confirmed: room 20939833 no longer exists and never held a message (empty room → purged by
  `purge_chats`), exactly matching "room created, message insert FK-failed, room purged".
- `CreateChatMessageLoveJunk` already validates the referenced message exists; the in-app
  `CreateChatMessage` never had that guard.

### 2. The broader "Oh Dear" — ungated ongoing chat composer
- The rippling reach gate (`replyeligible === false`) is wired into `MessageExpanded.vue` and
  `ChatReplyPane.vue` (the **initial reply** entry points) only.
- The **ongoing in-chat composer is `ChatFooter.vue`**, which had no reach gate and **no try/catch** —
  `await chatStore.send(...)` throws on any non-2xx and propagates to `error.vue`.
- So sending into an already-open room that's a rippled post outside reach → server `403 not_in_reach`
  → fatal page. Sentry (nuxt3 + capacitor) shows this hitting **hundreds of users** right now
  (e.g. `API Error POST /chat/.../message -> status: 403`: 1056 events/237 users, 592/32u, 247/116u).
  This is the "open caveat" noted in `plans/2026-06-26-rippled-post-reply-403-not-in-reach.md`.

## Fixes

### Done (working tree, not committed)
1. **`iznik-server-go/chat/chatmessage.go` — guard purged `refmsgid`.** Before insert, if
   `payload.Refmsgid != nil`, verify `EXISTS(SELECT 1 FROM messages WHERE id=? AND deleted IS NULL)`;
   if gone, return `404 refmsg_gone` (clean, intended) instead of an FK 500.
2. **`iznik-server-go/chat/chatmessage.go` — stop swallowing the insert error.** Check
   `db.Create(&payload).Error` and `stdlog.Printf` it (chat id, user id, refmsgid) so this class of
   failure is ever diagnosable.
3. **`iznik-nuxt3/components/ChatFooter.vue` — catch failed sends.** Wrap `chatStore.send` in
   try/catch; on error keep the typed text, reset `sending`, and show an inline `ChatNotice`
   (403 → "…you'll be able to reply once it reaches your area"; 404 → "this post is no longer
   available"; else → generic retry). No more throw to `error.vue`.

### Follow-up (not done)
4. **apiv2 access-log middleware** records 403 chat-message responses as 200 and drops 500s entirely
   (`status_code` label has no `500`). Fix so 4xx/5xx are visible server-side — this masked the whole
   investigation. Shared middleware; do as its own change.
5. Consider gating `ChatFooter` on `message.replyeligible === false` proactively (disable composer +
   notice) as `ChatReplyPane` does, not just catching the rejection — better UX for the reach case.

## Testing
Not run on this host (per project policy — let CI run). Needs: Go suite (chat handler), vitest
(ChatFooter), and a regression test for the purged-refmsgid 404 path (mirror
`chatmessage_reach_test.go`).

## Related
`plans/2026-06-26-rippled-post-reply-403-not-in-reach.md`, `reference_deleted_messages_purged`,
`reference_stale_app_ohdear_diagnosis`, `feedback_rippling_immediate_is_intended`.
