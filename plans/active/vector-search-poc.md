# Vector Search Design

## Status: Design (not yet approved for implementation)

## Problem

Current search uses keyword matching with soundex and typo tolerance. This works for direct queries ("sofa", "bike") but fails for conceptual queries ("something to sit on", "furniture for a home office", "things for the garden"). Users who don't know the exact item name get poor results.

## Approach

Server-side hybrid search: vector similarity via nomic-embed-text-v1.5 embeddings combined with keyword presence boosting. Background Laravel batch job generates embeddings using Node.js ONNX runtime (already available in batch container). Go API does the brute-force similarity scan.

## Key Design Principle: Exact Matches First

When a user searches "sofa", messages containing the word "sofa" MUST appear at the top. Semantic matches (couch, settee) are valuable but secondary. The hybrid scoring achieves this by adding a keyword-presence bonus on top of vector similarity.

**PoC-validated scoring** (vector_score + keyword_bonus × 0.3):

| Query | #1 result | Score breakdown |
|-------|-----------|-----------------|
| "sofa" | Sofa | vec=0.83 + kw=1.0×0.3 = **1.13** |
| "sofa" | Couch (no keyword match) | vec=0.68 + kw=0 = **0.68** |
| "washing machine" | Washing machine | vec=0.83 + kw=1.0×0.3 = **1.13** |
| "something to sit on" | Cushion (pure vector) | vec=0.59 + kw=0 = **0.59** |

Exact keyword matches get a ~50% score boost over purely semantic matches. Stop words are excluded from keyword matching (same list as existing search).

## What "Embedding" Means

An embedding converts text into a fixed-length list of numbers (a "vector" — here, 768 numbers). Texts with similar meaning produce similar vectors. The distance between vectors measures semantic similarity.

The model (nomic-embed-text-v1.5) has learned these relationships from training on vast amounts of text, so it "knows" that sofa ≈ couch ≈ settee without explicit programming.

## Data Profile (from production DB, 2026-03-16)

- **48,809** total messages in `messages_spatial`
- **23,127** open (not successful, not promised)
- **~1,500–3,000 messages/day** arrival rate (trending up; recent peak 3,004/day)
- **Peak hour**: ~178 msgs/hour (3pm), ~8/hour (5am)
- Average message text: 188 chars (subject + body)
- Geographic: ~15,700 within 100mi of London, ~8,900 within 100mi of Manchester

## PoC Results (2026-03-16)

### Vector vs Keyword comparison (500 real messages)

| Query | Keyword top results | Vector top results |
|-------|--------------------|--------------------|
| "something to sit on" | House Plants, Packing Boxes, Spice Jars | Cushion, Sofa, Office chair, Garden chairs |
| "something to keep warm" | Motorbike, Cami top, Glass table top | Throw/blanket, Heater, Chimney balloon |
| "exercise equipment" | 1 result (fishing equipment) | Dumbbells, Badminton set, Running arm strap |
| "toys for children" | Mushroom headpiece, Smart TV | Kids toys, Battery toy, Tiger soft toy |
| "furniture for a home office" | HP Laptop, Electric bread maker | Office chair, Leather chair |

### Typo handling (inherent — no special logic needed)
- "sofar" → finds sofas (score 0.59 vs 0.83 for "sofa")
- "washing masheen" → finds washing machine at rank 3

### Matryoshka dimension truncation
nomic-embed-text supports truncating embeddings to fewer dimensions with minimal quality loss:

| Dimensions | Sofa score | Couch score | Bicycle score | Size per 23K msgs |
|------------|-----------|-------------|---------------|-------------------|
| 768 (full) | 0.833 | 0.685 | 0.462 | 68MB |
| 256 | 0.840 | 0.680 | 0.478 | 23MB |
| 128 | 0.853 | 0.711 | 0.513 | 11MB |

**Recommendation**: Use 256 dimensions. Nearly identical quality at 1/3 the storage and memory. Rankings are preserved — sofa > couch > bicycle at every dimension.

### Encoding throughput

| Runtime | Msgs/sec | Time for 23K | Time for daily 3K |
|---------|----------|-------------|-------------------|
| Python (24 threads) | 15 | 25 min | 3.3 min |
| **Node.js ONNX** | **48** | **8 min** | **1 min** |

Node.js ONNX is 3× faster than Python and already available in the batch container (Node 20 installed for MJML).

## Architecture

### 1. Embedding Storage — new MySQL table

```sql
CREATE TABLE messages_embeddings (
    msgid BIGINT UNSIGNED NOT NULL,
    embedding BLOB NOT NULL,           -- 256 float32 = 1024 bytes
    model_version VARCHAR(50) NOT NULL, -- 'nomic-embed-text-v1.5-dim256'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (msgid),
    FOREIGN KEY (msgid) REFERENCES messages(id) ON DELETE CASCADE
);
```

At 256 dimensions: 1024 bytes per message. 23K messages = ~23MB in table. Separate table rather than adding to `messages_spatial` to keep concerns clean and allow independent schema evolution.

### 2. Embedding Generation — Laravel batch command + Node.js

A scheduled Laravel command in `batch-prod`:
- Runs every 5 minutes via scheduler
- Queries `messages_spatial LEFT JOIN messages_embeddings` to find un-embedded messages
- Shells out to a Node.js script (same pattern as spatie/mjml-php) to generate embeddings
- Node script loads nomic-embed-text-v1.5 ONNX model, accepts texts via stdin/args, returns float32 vectors
- Model stays loaded in memory between invocations if run as a persistent process (like MJML)
- Inserts vectors into `messages_embeddings`

**Throughput**: At 48 msgs/sec, daily volume of 3,000 messages = ~1 minute. Even a full backfill of 23K is only 8 minutes. No need for parallelism or GPU — single-threaded Node.js ONNX is sufficient.

**No Python, no new container**: Uses the existing Node.js installation in the batch container.

### 3. Search API — Go endpoint

Enhance the existing search endpoint rather than adding a new one:

```
GET /apiv2/message/search/{term}
    ?messagetype=Offer|Wanted|All
    &swlat=...&swlng=...&nelat=...&nelng=...
    &limit=10
```

**Implementation in Go:**

1. **On startup**, load all embeddings from `messages_embeddings` into a contiguous `[]float32` slice in memory
   - 23K × 256 dims × 4 bytes = ~23MB — trivially fits in RAM
   - Refresh periodically (every few minutes) to pick up new embeddings
   - Pre-normalize vectors so similarity = dot product (no division needed at search time)

2. **On search request**, Go calls the Node.js embedding service to encode the query text → 256-dim vector. This is a single text, so latency is ~50ms. (Could also be a simple HTTP sidecar within the same container, or a persistent Node process.)

3. **Brute-force scan**: for each embedding, compute dot product with query vector. Apply geographic bounding box and msgtype filters from `messages_spatial` metadata (also cached in memory).

4. **Hybrid scoring**: `final_score = dot_product + keyword_bonus × 0.3`
   - `keyword_bonus` = fraction of significant query words (excluding common words) found in the message subject
   - This ensures exact keyword matches rank above purely semantic matches

5. Return top-K results in the same format as existing search.

**Why brute-force is fine at this scale:**
- 23K × 256 = ~6M multiply-adds = sub-millisecond on any modern CPU
- No need for FAISS, HNSW, or any approximate nearest neighbor index
- Simpler code, no additional dependencies, exact results
- Even at 100K messages (future growth), still under 5ms

### 4. Query Embedding in Go

For the Go service to encode query text, options:

**A. HTTP call to a Node.js sidecar** (recommended): A tiny Node.js HTTP server that loads the model once and serves `POST /embed`. Same pattern as MJML. Go calls it on each search request (~50ms for a single query).

**B. ONNX Runtime in Go**: Use `onnxruntime-go` package to run the model directly in Go. Eliminates the Node dependency for query-time encoding but requires adding a Go ONNX binding. More complex.

**C. Pre-compute query embeddings**: Cache embeddings for the top ~1000 search terms (from `search_terms` table). These cover ~80% of searches. Fall back to live embedding for rare queries.

**Decision**: Use A (Node.js sidecar). Simple, proven pattern (MJML precedent). Add C (caching top search terms) as a later optimisation if needed.

### 5. Cleanup

When messages leave `messages_spatial` (marked successful/promised, or deleted), `ON DELETE CASCADE` on the FK handles removal from `messages_embeddings`. The batch job only needs to handle inserts.

## What This Doesn't Change

- No changes to the client/frontend — search API response format stays the same
- No changes to saved searches or search history
- Existing keyword search infrastructure (words, messages_index) remains for now — vector search augments, doesn't replace

## Risks and Mitigations

| Risk | Mitigation |
|------|-----------|
| nomic-embed-text quality on short/informal text | PoC tested with real Freegle data — strong results |
| Node.js ONNX sidecar goes down | Fall back to keyword-only search (existing behaviour) |
| Memory usage grows with message volume | At 256 dims, even 100K messages = 97MB — fine |
| Model updates require re-embedding | `model_version` column; batch re-embed script |
| Query encoding latency (~50ms) | Acceptable for search; could cache frequent queries (option C above) |

## Implementation Order

1. Create `messages_embeddings` table (Laravel migration)
2. Build Node.js embedding script using `@huggingface/transformers` ONNX runtime
3. Build Laravel batch command to call Node script and populate embeddings
4. Run backfill of existing 23K open messages (~8 minutes)
5. Build Node.js HTTP sidecar for query-time embedding
6. Add vector search to Go search endpoint with in-memory scan + hybrid scoring
7. Test with real search terms from `search_terms` table (top 50)
8. Evaluate: compare hybrid results vs current keyword-only for the full search_terms list
