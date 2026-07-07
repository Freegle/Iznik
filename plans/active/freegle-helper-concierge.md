# Freegle Helper: Automated Reply Concierge

**Status**: Design
**Created**: 2026-03-27

## The Problem

When someone bulk-posts many items (e.g. Mind Brighton's 47 office furniture offers), they may receive dozens or hundreds of replies across those items. Managing this manually — checking who can collect when, whether they have transport, whether they match the criteria (e.g. charities only), who wants which items, how many — is overwhelming. The offerer needs a concierge that handles the back-and-forth and presents them with simple decisions: "This person can come Tuesday at 2pm and take 6 chairs and 2 desks. Accept?"

## The Name

**Freegle Helper** — friendly, descriptive, fits the brand. Messages sent by it are clearly marked as automated. The bot user could be called "Freegle Helper" with a distinctive bot avatar.

## How It Works

Freegle Helper is Claude operating as a loop-based agent, monitoring the offerer's chat inbox and managing conversations on their behalf. It sends messages through Freegle's existing chat system, flagged as coming from the Helper bot. The offerer can override or intervene at any time.

The decision-making is done by Claude, not by rigid code. Claude reads each reply, understands context, applies the criteria, and composes natural conversational messages. The "algorithm" below is guidance for Claude's judgement, not a state machine.

## Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│  Freegle     │────>│  Claude Loop │────>│  Freegle    │
│  Chat API    │<────│  (Agent)     │<────│  Chat API   │
│  (read msgs) │     │              │     │  (send msgs)│
└─────────────┘     │  Reads inbox │     └─────────────┘
                    │  Assesses    │
                    │  Composes    │     ┌─────────────┐
                    │  Decides     │────>│  Offerer    │
                    │              │<────│  Dashboard  │
                    └──────────────┘     └─────────────┘
```

**Execution model**: A lightweight bash poll script runs as a background task, checking the chat API every 30 seconds. It persists state to a file and produces output ONLY when something changes (new chat, unseen count changes). This avoids consuming LLM tokens on every poll cycle — the LLM is only invoked when there's actually something new to process.

When the poller detects a change, the LLM:
1. Reads the new messages via the chat API
2. Assesses each conversation against the FSM
3. Drafts responses for human approval (does NOT send autonomously)
4. When a conversation reaches "ready to present" state, notifies the offerer

If the background poll times out (10 min max), it's restarted. The poll script is a simple bash/curl loop with no LLM dependencies — it just compares `chatcount:unseencount` against the last known state.

**Message identity**: Messages are sent as the offerer. The Helper is invisible to the replier — they think they're talking to the person who posted the items. This means the tone must be natural and human, not robotic or formulaic. No "I'm a bot" disclosure.

## Batch Configuration

When the Helper starts managing a batch of posts, it needs a briefing — extracted from the post body and listing metadata, not hardcoded:

- **Collection constraints**: dates, times, location (from post body + `message.deadline`)
- **Recipient criteria**: who should get the items, if anyone specific (from post body — e.g. "charities only", "families", or none)
- **Items list**: what's available, quantities, sizes (from the CSV / listing data)
- **Offerer preferences**: any stated priorities (from post body — e.g. "prefer someone who can take multiple items")

The Helper reads the post body once and extracts these as its operating rules. Everything below uses these generically — "collection constraints" not "Tue 7th April", "recipient criteria" not "charities".

## Conversation Stages

### Stage 1: Acknowledge & Gather

When a new reply arrives, the Helper sends ONE message that does as much as possible in a single round-trip:

**What it always does:**
- Thanks them for their interest
- States the collection constraints (if they haven't already matched them)
- Asks about anything still unknown that matters for allocation

**What it checks from their message before composing:**
- Did they already state when they can collect? → Don't ask again
- Did they already satisfy the recipient criteria? → Don't ask again
- Did they mention transport? → Don't ask again
- Did they ask a question? → Answer it if possible from listing data
- Did they reply to multiple items? → Address all of them in one message
- Is their message a mismatch (asking about something we're not offering)? → Redirect to actual items

**What it never does:**
- Make any commitment or promise
- Ask questions out of curiosity that don't affect allocation
- Ask about things they've already told us

Every message ends with a no-commitment phrase: "We're still collecting interest and will confirm allocations shortly."

**Tone rules:**
- Never imply suitability or make value judgements ("sounds like a great fit", "you'd be perfect"). This is close to a commitment. Just gather information.
- Never say "you're down for" or "we've got you down for" — implies allocation.
- Treat every replier neutrally in conversation, even if internally they're the strongest candidate. The human decides, not the Helper.

**The aim is to get to QUALIFIED in as few messages as possible.** If their first message answers everything, skip straight there. If it answers nothing, ask everything in one go. Don't drip-feed questions across multiple round-trips.

**Don't mail-bomb.** Once the initial exchange is done (we've acknowledged, asked our questions, they've replied), don't send further messages unless there's a genuine reason: a question they asked that we can now answer, a nudge after timeout, or an allocation decision. If we can reasonably infer the answers to our checklist from what they've already said, mark them QUALIFIED without another message. Silence is fine — it means "we're working on it."

### Stage 2: Scoring & Allocation Timing

**Phase A** (immediate): Respond to every message — answer questions, acknowledge, gather info. No commitment.

**Phase B** (allocation): Present candidates to the offerer for decisions. Triggered per-item, not per-batch.

**When Phase B triggers** — the Helper becomes progressively more impatient as the deadline approaches:

- **Plenty of time (deadline > 3 days away)**: Wait ~24h from first reply before presenting candidates. Give the full pool time to form.
- **Getting closer (deadline 1-3 days away)**: Present candidates as soon as there's a reasonable pool (e.g. 2+ qualified for a contested item, or 1 qualified for an uncontested item). Don't wait for more.
- **Urgent (deadline < 24h away)**: Present immediately. Any qualified candidate is better than none. Push for confirmations aggressively.
- **Offerer can manually trigger Phase B at any time**: "Show me what we've got."

The same urgency ramp applies to non-responsive handling: closer to deadline, shorter patience. With 3 days to go, wait 24h before nudging. With 1 day to go, nudge after 6h.

**Scoring factors** (Claude weighs these with judgement, not a rigid formula):

| Factor | Signal | Weight |
|--------|--------|--------|
| Criteria match | Matches whatever the post specified (if anything). But criteria are a preference, not a hard rule — an item going to someone is always better than going to nobody. If only non-matching candidates exist, they're the best candidates. | High when choosing between candidates; irrelevant when there's only one |
| Quantity appetite | Taking more items = fewer collection slots needed | Medium |
| Transport confirmed | Mentioned suitable transport for the items | Medium |
| Availability flexibility | Flexible on collection times vs narrow window | Medium |
| Responsiveness | Time between our message and their reply | Medium |
| Reputation | Thumbs up/down ratio, completion history, reneged count | Medium |
| Multi-item interest | Wants items across multiple posts = one trip | High |
| Already collecting | QUALIFIED/ALLOCATED for another item = already coming | High |
| Self-awareness | "Only if no one else wants it" = honest, lower priority but good fallback | Low |
| Reply quality | Specific vs vague, polite vs demanding, reads the post vs generic | Low |

**What Claude considers:**
- If criteria exist, someone who matches them wanting many items is generally better than multiple people wanting one each (fewer collection slots, better criteria match)
- But spreading items across multiple worthy recipients may be preferred — the offerer decides
- Someone with poor reputation (many thumbs down, reneged promises) should be ranked lower
- Someone whose message is clearly a template/mismatch (asking for something we're not offering) ranks lowest
- Someone who hasn't replied to the availability check within 24 hours should be deprioritised

### Stage 4: Presentation to Offerer

When the waiting period ends (or when Claude judges there are enough strong candidates):

**What the offerer sees** (via a summary message in their chat, or a structured notification):

```
Ready for your decision — Blue Fabric Chairs (14 available):

1. Brighton Community Centre (charity) — wants 8 chairs
   Can collect: Tue 7th, has van, replied in 1hr, 12 thumbs up

2. St Luke's Primary School — wants 6 chairs
   Can collect: Tue or Wed, has van, replied in 3hrs, new user

3. Jane (individual) — wants 2 chairs
   Can collect: Wed only, has car, replied in 6hrs, 5 thumbs up

Recommendation: Allocate 8 to Brighton CC and 6 to St Luke's.
Reply "yes" to confirm, or tell me how you'd like to allocate.
```

**The offerer's options:**
- Accept the recommendation
- Modify allocation (e.g. "give 4 to each")
- Ask for more info about a candidate
- Override with their own choice

### Stage 5: Promise, Confirm & Logistics

Once the offerer decides:

**Promise via API:** For each allocated replier, call `POST /message` with `action: "Promise"` and `userid: <replier>`. This:
- Records in `messages_promises` table (unique per msgid+userid, multiple users can be promised the same item)
- Sends a system "Promised" chat message to the replier automatically
- Multiple promises per item are supported (e.g. promise chairs to both Bob and Alice)

**Note on partial quantities:** The promise API is binary (user X is promised item Y) — it doesn't track how many of a multi-quantity item they're getting. The Helper must track quantities internally and communicate them in the confirmation message. At outcome time, `messages_by.count` records the actual quantity collected.

**What the Helper does for successful candidates:**
- Calls the Promise API (triggers system "Promised" message)
- Sends a follow-up message confirming quantity, collection date/time, and their confirmation code (three-word memorable phrase)
- Shares collection address (via the Address chat message type)
- Asks them to confirm they'll be there

**What the Helper does for unsuccessful candidates:**
- Sends a polite message: "Thanks for your interest. Unfortunately these items have been allocated to other organisations. Keep an eye on Freegle for more items!"
- This mirrors the existing "completion message" pattern in OutcomeModal

### Partial Allocation

When an item has quantity > 1 (e.g. 14 chairs), multiple people can be allocated portions. The Helper tracks `qty_allocated` per item — the sum of all allocations must not exceed `availablenow`. When presenting to the offerer, show remaining quantity after each proposed allocation. Don't decrement `availablenow` via the API until collection is confirmed — track allocation counts internally.

### Cross-Item Priority

When someone is QUALIFIED or ALLOCATED for one item, they should rank higher for other items they've expressed interest in. The principle: **minimise the number of distinct collection visits**. One person taking 5 items in one trip is better than 5 people taking 1 item each.

Concretely:
- If someone is already confirmed to collect item A, and they've also expressed interest in item B, they automatically rank higher for item B (they're coming anyway)
- When two candidates for an item are otherwise equal, prefer the one who is already collecting other items from this batch
- When presenting allocations to the offerer, group by collector: "Rita is already coming for tables — she could also take the ladder and 2 cabinets"
- If someone is GATHERING for item B but already QUALIFIED for item A, fold the qualification forward — they've already proven they can collect, meet criteria, have transport. Only ask about item-specific gaps (e.g. "do you have room for a fridge as well as the tables?")

### Confirmation Codes

When confirming an allocation, generate a memorable three-word code (e.g. "tiger-bridge-sunset") for each collector. Include it in the confirmation message: "Your collection code is tiger-bridge-sunset — please mention this when you arrive." This helps the offerer identify who is collecting what if multiple people show up, and resolves any confusion about what was agreed.

### Item Withdrawal

If the offerer withdraws an item (marks as Taken/Withdrawn via the UI, or tells the Helper), notify all repliers in GATHERING/QUALIFIED/ALLOCATED for that item: "Sorry, this item is no longer available." Set their item_state to REJECTED for that item. If they have other items still active, their conversation continues.

### Replier Withdrawal

If a replier explicitly says they're no longer interested ("never mind", "found one elsewhere"), set their state to WITHDRAWN. No follow-up messages. If they come back later and the item is still available, treat as a new reply.

### Stage 6: Collection Day Follow-up

On collection day (or day before):

- Sends a reminder to confirmed collectors: "Just a reminder — you're collecting 8 blue fabric chairs tomorrow (Tuesday 7th) between 10am-4pm from [address]."
- If someone hasn't confirmed: flags to the offerer and suggests moving to the next candidate
- After collection: prompts the offerer to mark items as Taken with the appropriate user via the outcomes API

## Data the Helper Needs

All available via existing APIs:

| Data | Source |
|------|--------|
| Replies to offers | `GET /message/{id}` → replies array, or chat messages with `refmsgid` |
| Chat messages | `GET /chat/{id}/message` |
| User reputation | `GET /user/{id}/info` → ratings, replytime, reneged, collected |
| Item availability | `message.availablenow` / `message.availableinitially` |
| Collection criteria | The body.txt content (parsed by Claude from the post text) |
| Deadline | `message.deadline` |

## What Needs Building

### Phase 1: API Authentication
- The Helper authenticates as the offerer via the API (Link key → JWT)
- Messages sent as the offerer — invisible to the replier
- Bot messages flagged via a separate mechanism (TBD) so the offerer can distinguish Helper-sent messages from their own in the UI
- The Helper processes all chats for the offerer — no reliable way to scope to specific message IDs, so the Helper must use judgement to identify which chats relate to the current batch (by timing, item references, content)

### Phase 2: Claude Loop Agent
- A skill or loop command that:
  - Authenticates to the API as the offerer
  - Fetches all chats related to the bulk-posted message IDs
  - Reads new messages since last check
  - Applies the stage logic above
  - Sends follow-up messages via `POST /chat/{id}/message`
  - Maintains conversation state (which stage each replier is at)
- State stored in a simple JSON file or in memory across loop iterations

### Phase 3: Offerer Dashboard
- A view (could be a simple chat summary or a dedicated page) showing:
  - All items and their allocation status
  - Candidate rankings per item
  - Accept/modify/override controls
- This could initially just be messages in the offerer's own chat, formatted as structured summaries

## Conversation State Tracking

Each replier has a **conversation state** (per person — one chat per person) and **item states** (per item they're interested in, since they may be QUALIFIED for one item but GATHERING for another):

```
NEW              → Reply received, not yet acknowledged
GATHERING        → We've replied, waiting for more info from them
QUALIFIED        → All info gathered, in scoring pool for human decision
ALLOCATED        → Human approved allocation, replier not yet told
CONFIRMED        → Replier told and confirmed collection
COLLECTED        → Item collected, outcome recorded
PARKED_REPLIED   → Can't meet requirements, told them, kept as fallback
PARKED_QUIET     → Not prioritised, no reply sent, still in pool
ESCALATED        → Needs human input (photo request, subjective question, etc)
TIMED_OUT        → Didn't respond within threshold
WITHDRAWN        → Replier explicitly said they're no longer interested
REJECTED         → Items allocated to others, polite rejection sent
```

### State Transitions

Each incoming message is processed against the replier's current state and a checklist of what we still need to know. The Helper maintains a **knowledge record** per replier:

```
replier: {
  name, userid, chatid,
  items: [{msgid, item_name, qty_wanted, item_state: <per-item state>}],
  collection_ok: true/false/unknown,
  criteria_met: true/false/unknown/not_applicable,
  transport_ok: true/false/unknown,
  withdrawn: false,
  distance_miles: <calculated from API lat/lng via haversine, never from LLM>,
  other_items_mentioned: true/false,
  escalation_reason: null or string,
  is_connector: false (brokering for an org — track for info, no special timeout),
  related_to: userid or null (household member replying separately),
  offerer_last_message: {timestamp, content} or null,
  cooldown_until: timestamp or null,
  state: <one of above>,
  next_action: <locked in until trigger fires>,
  parked_reason: null or string,
}
```

**Transition rules:**

| From | Trigger | To | Action |
|------|---------|----|--------|
| NEW | Helper sends first message | GATHERING | Ask whatever is still unknown in one message |
| NEW | First message answers everything | QUALIFIED | Acknowledge, no commitment |
| GATHERING | Reply answers remaining questions | QUALIFIED | Acknowledge, no commitment |
| GATHERING | Reply answers some questions | GATHERING | Acknowledge what they said, ask what's still missing |
| GATHERING | Reply asks question we can answer | GATHERING | Answer from listing data, continue gathering |
| GATHERING | Reply asks question we can't answer | ESCALATED | Flag to human, tell replier we'll check |
| GATHERING | Reply reveals they can't meet constraints | PARKED_REPLIED | Soft decline, keep as fallback |
| GATHERING | No reply within timeout | TIMED_OUT | No message sent |
| PARKED_REPLIED | Primary candidate falls through | GATHERING | Re-engage: "Are you still interested?" |
| PARKED_QUIET | Primary candidate falls through | GATHERING | First contact: treat as NEW |
| ESCALATED | Human provides answer | Re-check | Pass answer to replier, then re-check knowledge gaps. If all answered → QUALIFIED. If gaps remain → GATHERING |
| ESCALATED | Replier sends new info while waiting | ESCALATED | Update knowledge record but stay escalated — human question still pending |
| QUALIFIED | Human approves allocation | ALLOCATED | — |
| ALLOCATED | Helper calls Promise API + sends confirmation with code | CONFIRMED | Promise recorded, "Great news..." + confirmation code |
| CONFIRMED | Collection happens | COLLECTED | Mark outcome via API |
| QUALIFIED | Human allocates to someone else | REJECTED | "Sorry, these have been allocated..." |
| ANY | Replier sends new message | Re-evaluate | Check if message changes anything |
| ANY | Offerer sends message directly | Same state, cooldown | Update knowledge record with what offerer said. Start 1-hour cooldown — no Helper messages in this chat until cooldown expires. After cooldown, assess: can the Helper continue in a way that's consistent with what the offerer said? If yes, continue the FSM. If not, escalate to human rather than contradicting them. |
| ESCALATED | Offerer replies to the chat (answering the escalated question) | Re-check after cooldown | Treat offerer's reply as the answer. After cooldown, re-check gaps. |

**What the Helper checks on every incoming message to decide what to ask:**

1. Do we know which items they want and how many? (from `refmsgid` + their message)
2. Have we mentioned that other items are available too? (if they only replied to one item in a bulk batch, they may have missed the others — ask once)
3. Do we know they can meet the collection constraints? (from their stated times vs batch config)
4. If criteria exist, do we know they meet them? (from their message content)
5. For items where transport is non-obvious, do we know about transport? Apply transport likelihood: small/light items (clocks, envelopes, a single chair) — assume they can carry it, don't ask. Large/heavy/multiple bulky items (3 trestle tables, 14 chairs, a fridge) — transport confirmation needed. The threshold is roughly: can one person carry it to a car in one trip? If yes, don't ask. If no, ask.
6. Have they asked a question we need to answer?

If all of 1-5 are answered and 6 is clear, they're QUALIFIED. Otherwise ask about the gaps — all in one message, not drip-fed.

Check 2 matters when the offerer has multiple items available. A replier who found one listing may not have seen the others. The Helper should mention the other items once — but not repeat if they don't bite. Add `other_items_mentioned: true/false` to the knowledge record.

### No LLM Geography

**CRITICAL: Never use LLM knowledge to interpret place names or estimate distances.** Place names are ambiguous — "Chesterfield" could be a city 200 miles away or a street in the next postcode. The Helper must:

- Use the replier's `lat`/`lng` from `GET /user/{id}` and the item's `lat`/`lng` from `GET /message/{id}` to calculate distance. Simple haversine — no database access needed.
- Never assume a place name mentioned in chat refers to any particular location
- Never tell a replier their location is too far based on a name — only based on their API-provided coordinates
- The Helper operates only through API calls as the offerer. No direct database access. All data comes from what the offerer can see via the API.

### Non-Responsive Handling

Responsiveness is both a filter and a scoring signal.

**Timeline for any state where we're waiting for a reply (GATHERING, ESCALATED):**

1. **T+0**: We send our message.
2. **T+24h**: No reply. Send a polite follow-up + nudge (via the API's nudge feature). Friendly tone: "Just checking you got my message — still interested?"
3. **T+48h (24h after nudge)**: Still no reply. Mark as TIMED_OUT. No further messages. They're excluded from allocation unless all other candidates for that item have also fallen through.

**Scoring impact:**
- Someone who replies within a few hours scores higher than someone who takes 20 hours, even if both are within the 24h window.
- A replier who needed a nudge before responding is viable but ranks below someone who replied promptly.
- The `users_replytime` data from their history reinforces this — a user with a historically slow reply time is more likely to be a no-show on collection day.

**After allocation:**
The same timeline applies to ALLOCATED → CONFIRMED. If we tell someone they've got the items and they don't confirm within 24h, nudge. If still nothing after 48h, revoke the allocation and offer to the next candidate.

**Transition additions:**

| From | Trigger | To | Action |
|------|---------|----|--------|
| GATHERING | 24h no reply | GATHERING | Send nudge: "Just checking — still interested?" |
| GATHERING | 48h no reply (24h after nudge) | TIMED_OUT | Exclude from allocation |
| ALLOCATED | 24h no confirmation | ALLOCATED | Send nudge: "Can you confirm you'll be collecting?" |
| ALLOCATED | 48h no confirmation | TIMED_OUT | Call Renege API (removes promise, records reliability), offer to next candidate |
| TIMED_OUT | All other candidates exhausted | GATHERING | Re-engage: "Are you still interested in [item]?" |
| TIMED_OUT | Replier sends new message, item not yet allocated | GATHERING | Resume: treat their message normally, re-check gaps |
| TIMED_OUT | Replier sends new message, item already allocated | REJECTED | "Sorry, these have been allocated" |
| REJECTED | Replier sends new message, item has become available again (revoked/no-show) | GATHERING | "Actually these are available again — still interested?" |
| ANY | Replier explicitly withdraws ("never mind", "found one") | WITHDRAWN | No further messages. |
| WITHDRAWN | Replier comes back, item still available | GATHERING | Treat as new reply. |
| ANY | Offerer withdraws item | REJECTED (for that item) | "Sorry, this item is no longer available." |

**Late replies principle:** A timed-out replier who comes back to life is always welcome if the item is still available — they just lost their priority position. They go back into the pool but score lower than someone who was responsive throughout. If the item has already been allocated and collected, they get a polite rejection. If allocated but not yet collected, they go onto the waitlist in case the allocation falls through.

**Lock-in principle:** When a replier's state changes, decide the next action once and record it. Don't re-evaluate on every monitoring cycle. Only reconsider when: the replier sends a new message, a competing replier changes the picture for the same item, a timeout threshold is hit, or the offerer intervenes.

## Example Full Flow

```
Day 1, 09:00 — 47 items bulk-posted with 2026-04-03 deadline

Day 1, 10:30 — Bob replies to "Blue Chairs x14": "Hi, I run a community centre"
  Helper: "Thanks Bob! Collection is Tue 7th or Wed 8th April, 10am-4pm.
           Would either day work? And how many chairs would you need?"

Day 1, 11:00 — Bob: "Tuesday works, we'd love all 14 if possible. I have a van."
  Helper: (marks Bob as QUALIFIED — criteria match: community centre,
           wants all 14, has transport, available Tue, fast reply)

Day 1, 14:00 — Alice replies to "Blue Chairs x14": "These for our school?"
  Helper: "Hi Alice! Yes, these chairs are available for local schools and
           charities. Collection is Tue 7th or Wed 8th, 10am-4pm.
           How many would your school need, and would you have transport?"

Day 1, 16:00 — Alice: "We'd need 6. The caretaker has a van. Wed works best."
  Helper: (marks Alice as QUALIFIED — school, wants 6, has van, Wed)

Day 2, 09:00 — Carol replies to "Blue Chairs x14": "interested"
  Helper: "Hi Carol! Collection is Tue 7th or Wed 8th, 10am-4pm.
           Would either day work? These are being offered to local charities
           and community organisations — could you tell me a bit about
           what the chairs would be used for?"

Day 3 (deadline) — Carol hasn't replied. Helper marks TIMED_OUT.

Day 3, 12:00 — Helper presents to offerer:
  "Blue Chairs (14 available):
   1. Bob (community centre) — wants 14, Tue, van, replied 30min ★
   2. Alice (school) — wants 6, Wed, van, replied 2hrs
   Recommendation: 8 to Bob + 6 to Alice (covers all 14, both verified orgs)
   Or: all 14 to Bob if you prefer single collection."

Offerer: "8 and 6 sounds good"

Helper to Bob: "Great news! 8 blue chairs have been allocated to you.
               Collection: Tuesday 7th April, 10am-4pm at [address].
               Can you confirm you'll be there?"

Helper to Alice: "Great news! 6 blue chairs have been allocated to you.
                  Collection: Wednesday 8th April, 10am-4pm at [address].
                  Can you confirm you'll be there?"

Helper to Carol: "Thanks for your interest in the blue chairs. These have
                  now been allocated. Keep an eye on Freegle for more items!"
```

## Tone Guidelines for the Helper

- Warm and friendly, not robotic — "Thanks for your interest!" not "Your request has been logged."
- Brief — people on Freegle send short messages, the Helper should too
- Sounds like a real person — the replier thinks they're talking to the offerer
- Never makes promises it can't keep — always "items are subject to availability"
- Matches the casual tone of Freegle conversations
- Uses the replier's name when known

## Real-World Insights

### How experienced offerers choose
Weight politeness, friendliness, and track record over first-come-first-served. Collection times in initial replies are often aspirational — don't take them literally.

### "First come first served" is an anti-pattern
Giving out addresses and inviting people to just turn up causes arguments, no-shows, and safety issues. The Helper approach solves this: structured allocation, no address until confirmed.

### Many first replies are questions, not commitments
Freegle requires a collection time even for questions like "Will it fit in a car?" The Helper should recognise questions and answer them from listing data before proceeding to qualification.

### Reply structure
Freegle replies include: user's message text, a stated collection time (mandatory, often meaningless), and a `refmsgid` linking to the specific offer. The Helper uses `refmsgid` to know which item is being discussed.

### Connector/broker pattern
Some repliers aren't the end recipient — they forward listings to charities/orgs on behalf of someone else. The Helper should:
- Recognise connector language ("sent to", "forwarded to", "on behalf of")
- Score highly if the named org matches criteria, but track that the actual collector may be different
- Track that the actual collector may be a different person from the one who replied

### Conversation Failure Modes

From codebase analysis of nudge/renege/timeout patterns:
- **Ghost repliers**: Express interest then never respond. The `replyexpected`/`replyreceived` flags and `users_nudges` table show this is common enough to have dedicated infrastructure.
- **Promise breakers**: The `messages_reneged` table tracks reliability. Users with high renege counts should be ranked lower.
- **Multi-item cherry pickers**: Someone replies to 20 items but only wants the best one. The Helper should ask upfront: "I see you're interested in several items. Which ones are you most keen on?"

## Open Questions

1. **Bot identity**: RESOLVED. Messages sent as the offerer (invisible). Bot messages flagged via a separate mechanism (not in the message itself — TBD how).
2. **Opt-in**: Opt-in per batch, activated when running bulk post command. RESOLVED.
3. **Manual override**: Offerer sends message → 1-hour cooldown, then Helper resumes if consistent. RESOLVED (see transition table).
4. **Multiple batches**: Each batch has its own `managed_message_ids` list. Helper scopes all operations to these IDs. RESOLVED.
5. **Multi-item repliers**: One chat per person, items tracked individually within the knowledge record. RESOLVED.
6. **Answerable questions**: Answer factual questions from listing data. Escalate subjective ones to human. RESOLVED.

## Live Monitoring Log

### Trial context
47 items bulk-posted for a Brighton charity clearance. Criteria: charities/community orgs preferred. Collection: Tue 7 / Wed 8 April. Helper operated manually (human-supervised AI), so responses were delayed hours — in production would be minutes.

### 2026-03-27 17:24 — Replier A, Fridge
- Individual, no org. Wrong dates ("collect today on Monday"). 4↑, 8 collected.
- **Pattern**: people don't read post body. Helper must clarify dates.

### 2026-03-27 17:34 — Replier B, Fridge
- Connector for charity. "Sent this to friends at [charity]". Collection TBA. 3↑, 8 collected.
- **Pattern added to plan**: connector/broker — not end recipient, brokering for org.

### 2026-03-27 19:11 — Replier C, Clocks + Ladder (multi-item)
- "Not a charity so only if a charity doesn't ask for". 21↑, 55 collected.
- **Pattern**: self-selecting backup. Don't re-ask criteria they've already addressed.

### 2026-03-27 18:54 — Replier D, Desks
- Template reply asking for computers, not desks. 13↑, 10 collected.
- **Pattern**: mismatch/spam. Redirected to actual items rather than ignoring.

### 2026-03-28 08:32 — Replier E, Trestle Tables (then Ladder)
- Community farm. "Need tables for cafe area". Collection 7th. 1↑, 8 collected.
- Later confirmed: wants all 3 tables + ladder, has transport. → QUALIFIED.
- **Pattern**: ideal reply — criteria met, specific date, clear need. Skip to QUALIFIED.

### 2026-03-28 06:54 — Replier F, C5 Envelopes
- Question: "How many envelopes?" Then chased: "Hope you can see my message".
- Later: wants ~250, confirmed charity, asked for photos (escalated to human).
- **Pattern**: question replies need answering first. Chasing = Helper too slow.

### 2026-03-28 12:20 — First responses sent (delayed by manual process)
- 6 repliers got personalised responses. No commitments. All include "still collecting interest".
- Wording refined: "you're down for" → "we've noted your interest in" (avoids implied commitment).
- Replier A confirmed can't make dates → PARKED_REPLIED.
- Replier E confirmed all details → QUALIFIED.
- Replier F needs photos → ESCALATED.

### Design findings from production data analysis
- Greenwich furniture clearance: 88 repliers, offerer replied to 40, post expired without outcome. Overwhelmed.
- Samsung TV: 87 repliers, offerer replied to 2, ghosted 85. No rejection messages sent.
- Multi-item repeat collectors exist but are a different pattern (ongoing relationship, not bulk event).

### Retrospective: Greenwich furniture clearance through the FSM

Played real conversations from a bulk furniture clearance (88 repliers) through the Helper algorithm. Key findings:

**1. The charity that got ghosted (MamaMia66)**
A DV charity connector wrote a compelling reply: "We are helping to collect & redistribute household items to families fleeing Domestic Violence." Followed up within an hour. The offerer never replied — just sent a bulk Completed message 3 days later. Under the Helper FSM: this would be QUALIFIED immediately (charity, has driver, flexible timing). The Helper would have responded within minutes and this person would be near the top of the allocation list. Instead they got nothing.

**Lesson**: The Helper's biggest value isn't clever allocation — it's making sure good candidates don't fall through the cracks because the offerer is overwhelmed.

**2. The "take everything" person who got ignored (jayes3)**
"Happy to collect the majority of your items, got a big van. TV, speakers, sofa, dining table, fridge, garden furniture." Listed specific items, had transport. Never got a reply. Under the FSM: multi-item, has transport, would score very high. The offerer instead dealt with people one item at a time.

**Lesson**: The Helper should actively identify and prioritise bulk collectors — one person taking 6 items in a van is far more efficient than 6 people taking 1 item each.

**3. The demanding replier (heraldomilan840128)**
Sent 4 messages in 10 hours before getting a reply: "Please, I need you to answer me, so I know quickly what I should do." Then "I'll take it all, if he chooses me." Eventually got dining table + chairs, coffee table, TV stand. Collection involved car problems, delays, multiple updates. Conversation was 20+ messages.

Under the FSM: the initial demanding tone ("I need you to answer me") would not change their priority — the Helper responds to everyone equally. But the Helper would have responded quickly (preventing the chasing) and the high message volume wouldn't be a burden since it's automated.

**Lesson**: Impatient repliers aren't bad candidates — they're often just anxious. Quick responses from the Helper prevent escalation. But multiple demanding messages before a response shouldn't boost their priority over someone who sent one polite message and waited.

**4. The promise-then-revoke (pattie-09)**
Offered the TV + stand + fan. Pattie arranged a van man. Then: "Apologies, tv is now gone! you can still have the TV stand and fan?" Pattie: "I'll pass." Offerer had given the TV to someone else in the meantime.

Under the FSM: the Helper tracks item availability. If the TV is promised to someone, it's marked as allocated and wouldn't be offered to pattie in the first place. If the first person falls through, it goes to the next candidate. No double-promising.

**Lesson**: Item-level availability tracking prevents the most frustrating experience — being told you can have something, arranging transport, then being told it's gone.

**5. The family pair (confidenceakahara + husband)**
She asked for TV + coffee table. Negotiated timing (has a baby, can't come in the morning). Then revealed her husband had separately replied about the sound system and was already on his way. Offerer: "who's your husband?"

Under the FSM: the Helper wouldn't know they're related unless they mention it. But once they do, it should merge their interest — "your household wants TV, coffee table, and sound system. Can one of you collect all three?"

**Lesson**: Household members sometimes reply separately. The Helper should watch for this (same address, mentioned relationship) and consolidate where possible. Add to knowledge record: `related_to: userid or null`.

**6. The Chesterfield sofa scam**
The sofa poster ("Rio Chesterfield Sofa", 55 repliers) replied to EVERY person with "Hi mate, just sold out to one of my friend." This wasn't freegling at all — they sold the items privately. 55 people got the same brush-off.

**Lesson**: Not directly relevant to the Helper (which operates on behalf of genuine offerers), but this pattern exists and the Helper should never send messages that sound like this.

### Key rules derived from trial
1. Answer factual questions from listing data immediately — don't make people wait.
2. One message, all questions — don't drip-feed across round-trips.
3. Mention other available items to single-item repliers — they may have missed them.
4. Never use LLM geography — use API lat/lng and haversine only.
5. Keep parked people warm — they may become best option if primaries fall through.
6. Detect mismatch replies — redirect rather than ignore.
7. Messages sent as offerer, not identified as AI.
8. Human makes allocation decisions, Helper manages conversation flow.
