# Freegle Helper driver

The AI concierge that manages replies to a bulk offer ("clearance") on the
offerer's behalf. Design: `plans/active/freegle-helper-concierge.md`. Backend +
schema + page: `plans/active/freegle-helper-implementation.md`.

It runs on a Claude Code **subscription** (not a metered API key): the loop unsets
`ANTHROPIC_API_KEY` before invoking `claude`, mirroring monitor-fsm.

## How it works

```
run-loop.sh ──poll.sh (curl, no LLM)──► change?
     │  yes (or hourly tick) and batch status == active
     ▼
driver.sh ── gathers helper state + offer + chats + PRE-COMPUTED distances
     │        (haversine.mjs — never LLM geography)
     ▼
claude -p (prompt.md = FSM rules) ── acts via the Helper API:
     • UpsertReplier / SetItemState   (knowledge record + scores)
     • Send                           (auto-send simple messages)
     • Proposal                       (queue allocations/rejections/escalations
                                        for the human to confirm/edit/send)
```

`poll.sh` is the cost gate: the LLM only runs when the offerer's chats actually
change, or on the periodic timeout tick (nudges/timeouts). Idle batches cost ~no
tokens.

## Run

```bash
cp config.example.env config.env     # set APIV2, JWT (or PERSISTENT_TOKEN), MSGID
./run-loop.sh
```

Works for **any** offerer account — supply that account's JWT and the clearance
`MSGID`. Messages are sent as the offerer; repliers see no bot.

The offerer controls it live from the clearance management page: **Pause** sets the
batch status to `paused` and the loop skips the brain entirely until resumed.

## Files
- `run-loop.sh`   poll cadence + single-instance lock + pause gate
- `poll.sh`       LLM-free chat-change detector
- `driver.sh`     one think cycle: assemble context, invoke the brain
- `prompt.md`     the FSM operating prompt (stages, scoring, tone, auto-vs-propose)
- `haversine.mjs` distance from API lat/lng only (no LLM geography)
- `config.example.env`  template
