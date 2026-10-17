// Run at image build time so the container never needs network access to
// Hugging Face at runtime. Downloads every model backend.js might load
// (primary and fallback) into HF_CACHE_DIR, retrying transient failures
// rather than failing the whole build on one dropped connection.
import { pipeline, env } from '@huggingface/transformers';
import { MODELS } from '../src/backend.js';

if (process.env.HF_CACHE_DIR) {
  env.cacheDir = process.env.HF_CACHE_DIR;
}

const MAX_ATTEMPTS = 3;

async function downloadWithRetry(model) {
  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    try {
      await pipeline('zero-shot-classification', model);
      console.log(`downloaded ${model}`);
      return;
    } catch (err) {
      console.error(`attempt ${attempt}/${MAX_ATTEMPTS} for ${model} failed: ${err.message}`);
      if (attempt === MAX_ATTEMPTS) throw err;
      await new Promise((resolve) => setTimeout(resolve, attempt * 2000));
    }
  }
}

for (const model of MODELS) {
  await downloadWithRetry(model);
}
