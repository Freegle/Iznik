# Promise-detection dataset contract

Shared interface between the **extraction** job (Agent A) and the **training/eval** job (Agent B).
Both jobs live under `App\Services\Promise` / `App\Console\Commands\Promise`. Neither job may edit
the other's files; this file is the only coupling.

## The CSV

One row per **windowed training example**. Header row required. Written with `fputcsv`
(RFC-4180 quoting — `span` contains commas/quotes/emoji). UTF-8.

| column | type | feature? | meaning |
|---|---|---|---|
| `room_id` | int | **no** (group key) | chat_rooms.id — used only to split train/test by conversation |
| `post_type` | `Offer`\|`Wanted` | no (metadata) | the originating post type |
| `end_turn` | int | no (metadata) | 0-based index of the last real-text turn in this window |
| `promise_turn` | int | no (metadata) | room's promise transition turn (real-text-turn space); `-1` if the room has no `Promised` event |
| `label` | `0`\|`1` | **target** | promised state at the end of this window |
| `span` | string | **YES — the only feature source** | windowed, speaker-tagged, PII-normalised dialogue |

**Critical:** the model is trained on `span` **only**. `room_id`/`post_type`/`end_turn`/`promise_turn`
are metadata for grouping and the timing metric — never features (feeding them would leak).

## `span` format

Real-text turns joined by a single space, each turn prefixed with a speaker tag:

```
[TAKER] is this still available? [GIVER] yes you can have it, what's your address? [TAKER] thanks! i can collect thursday
```

- Speaker tags: `[GIVER]` (item owner) and `[TAKER]` (receiver). For an Offer the replier is the
  TAKER; for a Wanted the replier is the GIVER. (Agent A resolves roles from post type + owner.)
- **Only real-text turns** appear (`Default`, `Interested`, text-bearing `Image` captions).
- PII is normalised to placeholder tokens, preserving the *signal* without the data:
  `<ADDRESS>`, `<POSTCODE>`, `<PHONE>`, `<EMAIL>`, `<URL>`. Emoji are kept (they carry signal).
- Newlines/CRs collapsed to spaces.

## Charset / encoding (both agents)

Freegle chat is `utf8mb4` — full of emoji (4-byte) and accented characters, plus occasional
invalid byte sequences and trash-nothing escape artefacts. Get this wrong and the model trains on
mojibake.

- **DB read:** the connection must be `utf8mb4` so 4-byte emoji survive intact (not `?`/`????`).
- **CSV file:** UTF-8, **no BOM** (a BOM corrupts the first header cell on read-back).
- **Extractor output (Agent A):** every `span` must be **guaranteed-valid UTF-8** — pass each string
  through `mb_convert_encoding($s, 'UTF-8', 'UTF-8')` (or `iconv('UTF-8','UTF-8//IGNORE',$s)`) to
  drop malformed sequences before writing. Collapse `\r`/`\n`/`\t` to single spaces. Keep emoji.
- **Reader / tokenizer (Agent B):** **all string operations must be multibyte-safe** — use
  `mb_strtolower`, `preg_split('//u', …)` / `mb_str_split` for the char-n-gram tokenizer, and never
  raw byte indexing (`$s[$i]`) which would split a multibyte char and produce garbage n-grams.
  Rubix's vectorizer lowercases internally; ensure it does so in UTF-8 (config/locale), or
  pre-lowercase with `mb_strtolower`.

## Label & leakage rules (Agent A owns these; Agent B just consumes the labels)

- Event-type messages (`Promised`, `Address`, `Reneged`, `Completed`, `System`, `Nudge`,
  `Reminder`, `Schedule`, `ModMail`) are **excluded from `span`** — they are empty/leaky. In
  particular the `Address` *event* (the click) is excluded, but a free-text address typed in a
  `Default` message is **kept** (normalised to `<ADDRESS>`) — it is genuine progression signal.
- `promise_turn` = the `Promised` event's position mapped into real-text-turn index.
- A window ending at real-text-turn `j` in a room whose promise turn is `p`:
  `label = 1` if `j >= p`, else `0`; **drop** the window if `|j - p| <= tolerance` (default 1) —
  the ±band guards against approximate event timestamps.
- A room with **no** `Promised` event: every window is `label = 0`, `promise_turn = -1`.
- Window length default = 8 real-text turns (the last ≤8 turns up to and including `j`).

## File ownership

- **Agent A (extraction):** `App\Console\Commands\Promise\ExtractPromiseDatasetCommand`,
  `App\Services\Promise\DatasetExtractor`, `App\Services\Promise\PiiNormaliser`,
  `App\Services\Promise\Turn` (value object), tests under `tests/Unit/Promise/Extraction*`.
- **Agent B (training/eval):** `App\Console\Commands\Promise\TrainPromiseClassifierCommand`,
  `App\Services\Promise\CharNgramTokenizer`, `App\Services\Promise\FeaturePipeline`,
  `App\Services\Promise\KeywordBaseline`, `App\Services\Promise\Evaluator`,
  `App\Services\Promise\DatasetReader`, tests under `tests/Unit/Promise/{Tokenizer,Baseline,Evaluator,Reader}*`.

Fixture for Agent B: `tests/Fixtures/Promise/dataset_fixture.csv`.
