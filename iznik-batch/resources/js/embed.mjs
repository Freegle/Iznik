#!/usr/bin/env node
// Embedding generator for Freegle messages
// Reads NDJSON from stdin: {"msgid": 123, "text": "Sofa in good condition"}
// Writes NDJSON to stdout: {"msgid": 123, "embedding": [0.1, 0.2, ...]}
//
// Uses nomic-embed-text-v1.5 via ONNX runtime (no Python, no GPU needed).
// Truncates to 256 dimensions (Matryoshka) — identical ranking quality at 1/3 size.

import { pipeline, env } from '@huggingface/transformers';
import { createInterface } from 'readline';

const EMBEDDING_DIM = 256;
const BATCH_SIZE = 64;

// Cache model files alongside the script
env.cacheDir = process.env.HF_CACHE_DIR || '/tmp/hf-cache';

// Load model once
const extractor = await pipeline(
  'feature-extraction',
  'nomic-ai/nomic-embed-text-v1.5',
  { quantized: true }
);

// Collect all messages first, then process in batches on close.
const messages = [];

const rl = createInterface({ input: process.stdin });

rl.on('line', (line) => {
  try {
    messages.push(JSON.parse(line));
  } catch (e) {
    process.stderr.write(`Error parsing line: ${e.message}\n`);
  }
});

rl.on('close', async () => {
  for (let i = 0; i < messages.length; i += BATCH_SIZE) {
    await processBuffer(messages.slice(i, i + BATCH_SIZE));
  }
});

async function processBuffer(batch) {
  // nomic-embed-text uses "search_document:" prefix for document embeddings
  const texts = batch.map(m => `search_document: ${m.text}`);

  const output = await extractor(texts, { pooling: 'mean', normalize: true });
  const dim = output.dims[1];

  for (let i = 0; i < batch.length; i++) {
    // Truncate to EMBEDDING_DIM and re-normalize
    const full = [];
    for (let j = 0; j < EMBEDDING_DIM; j++) {
      full.push(output.data[i * dim + j]);
    }
    // Re-normalize after truncation
    let norm = 0;
    for (let j = 0; j < EMBEDDING_DIM; j++) norm += full[j] * full[j];
    norm = Math.sqrt(norm);
    const embedding = full.map(v => v / norm);

    process.stdout.write(JSON.stringify({
      msgid: batch[i].msgid,
      embedding
    }) + '\n');
  }
}
