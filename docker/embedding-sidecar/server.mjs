import { pipeline, env } from '@huggingface/transformers';
import { createServer } from 'http';

const PORT = process.env.PORT || 3200;
const EMBEDDING_DIM = 256;

env.cacheDir = process.env.HF_CACHE_DIR || '/app/model-cache';

console.log('Loading nomic-embed-text-v1.5 model...');
const extractor = await pipeline(
  'feature-extraction',
  'nomic-ai/nomic-embed-text-v1.5',
  { quantized: true }
);
console.log('Model loaded.');

const server = createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  if (req.method !== 'POST' || req.url !== '/embed') {
    res.writeHead(404);
    res.end('Not found');
    return;
  }

  let body = '';
  for await (const chunk of req) {
    body += chunk;
    if (body.length > 100_000) {
      res.writeHead(413, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Request too large' }));
      return;
    }
  }

  const t0 = process.hrtime.bigint();
  try {
    const { texts } = JSON.parse(body);
    if (!Array.isArray(texts) || texts.length === 0) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'texts must be a non-empty array' }));
      return;
    }

    // Prefix for query embedding (vs "search_document:" for documents)
    const prefixed = texts.map(t => `search_query: ${t}`);
    const output = await extractor(prefixed, { pooling: 'mean', normalize: true });
    const dim = output.dims[1];

    const embeddings = [];
    for (let i = 0; i < texts.length; i++) {
      const vec = [];
      for (let j = 0; j < EMBEDDING_DIM; j++) {
        vec.push(output.data[i * dim + j]);
      }
      // Re-normalize after truncation
      let norm = 0;
      for (let j = 0; j < EMBEDDING_DIM; j++) norm += vec[j] * vec[j];
      norm = Math.sqrt(norm);
      embeddings.push(vec.map(v => v / norm));
    }

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ embeddings }));

    const elapsedMs = Number(process.hrtime.bigint() - t0) / 1e6;
    // Fingerprint first embedding so identical queries across calls can be
    // cross-checked in Loki — deterministic extractor → identical fp.
    const fp = embeddings[0]
      .slice(0, 4)
      .map(v => v.toFixed(4))
      .join(',');
    const sample = texts[0].length > 40 ? texts[0].slice(0, 40) + '...' : texts[0];
    console.log(JSON.stringify({
      level: 'info',
      event: 'embed',
      count: texts.length,
      elapsed_ms: Number(elapsedMs.toFixed(2)),
      first_text_len: texts[0].length,
      first_text: sample,
      fp,
    }));
  } catch (e) {
    const elapsedMs = Number(process.hrtime.bigint() - t0) / 1e6;
    res.writeHead(500, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: e.message }));
    console.log(JSON.stringify({
      level: 'error',
      event: 'embed',
      elapsed_ms: Number(elapsedMs.toFixed(2)),
      error: e.message,
    }));
  }
});

server.listen(PORT, () => {
  console.log(`Embedding sidecar listening on port ${PORT}`);
});
