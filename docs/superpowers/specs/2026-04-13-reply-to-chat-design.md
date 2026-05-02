# Reply-to-Chat UX Redesign

## Summary

Replace the inline reply section (MessageReplySection) on message pages with direct navigation to the chat page. When a user clicks "Reply", they go to `/chats/new?replyto=MSG_ID` where a reply composer pane (styled like a chat) collects their message. After sending, they're in the real chat. Back button returns to the message.

## Current Flow

1. User views message (MessageExpanded)
2. Clicks "Reply" → inline MessageReplySection appears
3. Fills in: reply text, collection time (Offers), email (logged-out)
4. Clicks "Send" → state machine handles auth/group-join/chat-creation
5. Navigated to `/chats/:id`

## New Flow

1. User views message (MessageExpanded)
2. Clicks "Reply" → navigates to `/chats/new?replyto=MSG_ID`
3. Chat page shows ChatReplyPane: message context header + reply form styled like a chat
4. For logged-out users: email field, reply text, collection time — type before forced login
5. Clicks "Send" → same state machine flow (auth/group-join/chat-creation)
6. After send: navigated to `/chats/:id` (real chat with message sent)
7. Back button from reply composer returns to original message

## Architecture

### Changed Files

1. **MessageExpanded.vue** — Reply button calls `navigateToReplyChat()` instead of `expandReply()`. Remove inline MessageReplySection rendering.

2. **pages/chats/[[id]].vue** — Detect `?replyto=` query param. When present and no chat ID, show ChatReplyPane instead of ChatPane.

3. **ChatReplyPane.vue** (new) — Reply composer styled like a chat conversation. Shows:
   - Header with message poster's info and the item being replied to
   - Reply form: email (logged-out), reply text, collection time (Offers)
   - Send button using useReplyStateMachine
   - Back button to return to message

### Unchanged

- **useReplyStateMachine.js** — Same state machine, same flow
- **useReplyToPost.js** — Same chat creation logic
- **ChatButton.vue** — Still used internally by the state machine
- **stores/reply.js** — Same persistence

## Login-Forcing for Logged-Out Users

Preserved exactly: user types reply in ChatReplyPane → clicks Send → state machine triggers AUTHENTICATING → login modal appears (non-dismissible) → after login, state machine continues → chat created → navigated to real chat.

The key insight: the reply form lives in ChatReplyPane, which is on the chats page. The state machine handles auth the same way — the only change is where the form is rendered.

## Mobile/Desktop

- Desktop: Two-column layout preserved. ChatReplyPane appears in the right pane.
- Mobile: Full-screen ChatReplyPane with back button (same as ChatMobileNavbar pattern).

## Test Plan

Playwright E2E tests:
- Logged-in: Reply from message page → lands in chat
- Logged-in: Reply from browse page → lands in chat  
- Reply form shows correct fields (reply text, collection time for Offers)
- Back button returns to message
- Mobile viewport: same flow works
