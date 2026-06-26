# Reply-to-Chat UX Redesign

## Summary

Replace the inline reply section (`MessageReplySection`) with a chat-styled
reply pane (`ChatReplyPane`) that opens as a **full-screen overlay on every
breakpoint** (mobile, tablet and desktop alike). Clicking "Reply" opens the
overlay in place — the underlying page is not navigated away from — so closing
it returns you to exactly where you were. After sending: from a single-message
page you land in the real conversation; from a list page (browse/explore) you
stay on the list so you can scroll on and reply to more items. The pane is
styled to match the real Freegle chat, so the transition into the chat is
seamless.

## Old Flow

1. User views message (MessageExpanded)
2. Clicks "Reply" → inline `MessageReplySection` expanded on desktop, or (in the
   first iteration of this feature) navigated to a `/chats/reply` page on mobile
3. Fills in: reply text, collection time (Offers), email (logged-out)
4. Clicks "Send" → state machine handles auth / group-join / chat-creation
5. Navigated to `/chats/:id`

## New Flow

1. User views a post (MessageExpanded, on `/message/:id` or in the browse modal)
2. Clicks "Reply" → the `ChatReplyPane` opens as a full-screen overlay on top of
   the current page (URL is unchanged). The same overlay is used on mobile,
   tablet and desktop; on desktop it is centred as a card on a dimmed backdrop.
3. The pane looks like a chat: a green header with the poster's avatar/name, the
   post shown as a received message bubble (`ChatMessageCard`), and a composer
   styled like the real chat footer.
4. For logged-out users: an email field appears in the composer; they type their
   reply before the forced login.
5. Clicks "Send" → same state machine flow (auth / group-join / chat-creation)
6. After send:
   - From the standalone message page (`/message/:id`): navigated to `/chats/:id`.
   - From a list page (the message was opened in a modal/overlay on browse or
     explore): the reply is sent **without** navigating; the message closes after
     a brief "Message sent" confirmation, leaving the user on the list to reply
     to more items.
7. Closing the overlay (back chevron, backdrop, or browser back) returns the user
   to exactly where they were — no navigation, no lost scroll position.

## Architecture

### Changed files

1. **MessageExpanded.vue** — the Reply button calls `expandReply()`, which now
   simply sets `showReplyOverlay = true` on every breakpoint. The pane is
   rendered via `<Teleport to="body"><Suspense><ChatReplyPane … /></Suspense>`
   so its async setup doesn't suspend the page. The old inline
   `MessageReplySection` rendering and the `replyExpanded` state are removed.

2. **ChatReplyPane.vue** — the reply pane, restyled to match the real chat:
   - `position: fixed; inset: 0` overlay; on `lg+` it becomes a centred card
     on a dimmed backdrop. Hidden behind the forced-login modal but above page
     chrome.
   - Header (green), poster avatar + name + OFFER/WANTED tag.
   - Body uses the real chat background (`#F5F5F5` + `chat-pattern.svg`) and
     shows the post as a `ChatMessageCard`, plus distance / promised / delivery
     notices when relevant.
   - Composer matches the chat footer: rounded inputs, reply textarea
     (`name="reply"`), collection-time textarea (`name="collect"`, Offers only),
     logged-out email field, and a primary "Send" button.
   - Emits `close` (handled by the opener) and `sent`.

3. **pages/chats/reply.vue** — kept as a deep-link fallback (e.g. for direct
   links). Renders the same `ChatReplyPane`; closing returns to `/message/:id`.

4. **LayoutCommon.vue** + **stores/misc.js** — a `replyOverlayOpen` flag hides
   the sticky ad/jobs banner (z-index 10000) while the overlay is open so it
   doesn't overlap the composer.

5. **Stay-on-list after send** — a `noNavigate` flag threads from
   `MessageExpanded` (set to `inModal || fullscreenOverlay`, i.e. the message was
   opened from a list) → `ChatReplyPane` (`stayOnSend` prop) →
   `useReplyStateMachine` (`stayOnPage` option) → `useReplyToPost.replyToPost` →
   `ChatButton.openChat`. When set, `openChat` creates and sends the chat but
   skips `router.push`, and `MessageExpanded.sent()` closes the message after the
   confirmation instead. The standalone message page leaves `noNavigate` off, so
   it still navigates to the chat.

### Unchanged

- **useReplyStateMachine / useReplyToPost / ChatButton** — same flow; each just
  gained an additive, default-off `noNavigate`/`stayOnPage` parameter (above).
- **stores/reply.js** — same persistence
- **LayoutCommon** still completes a pending reply globally after an OAuth
  redirect, so the overlay being torn down by a full-page reload is safe.

## Login-forcing for logged-out users

Preserved exactly: user types reply in `ChatReplyPane` → clicks Send → state
machine triggers AUTHENTICATING → forced login modal appears (above the overlay)
→ after login the state machine continues → chat created → navigated to the real
chat. For OAuth (full page reload) the persisted reply is completed by
`LayoutCommon` on the page it returns to.

## Responsive

- Mobile / tablet (`< lg`): full-screen overlay — header, scrollable chat body,
  sticky composer.
- Desktop (`lg+`): the same pane centred as a card (max-width ~640px) on a
  dimmed backdrop.

## Test plan

Vitest unit tests:
- `ChatReplyPane.spec.js` — rendering, collect/delivery/distance/promised
  notices, send-button state, post card, closing (emits `close`).
- `MessageExpanded.spec.js` — `expandReply` opens the overlay on every
  breakpoint; `sent` closes it and sets `replied`.
- `pages/chats/reply.spec.js` — deep-link page renders the pane / empty state.

Playwright e2e (`test-reply-to-chat.spec.js`):
- Mobile / tablet / desktop: Reply opens the overlay in place (URL stays on the
  message), and sending lands in the real chat.
- Back button closes the overlay and stays on the message page.
- WANTED messages hide the collection-time field.
- Empty state for `/chats/reply` without `replyto`.
- Logged-out deep link shows the email field.
