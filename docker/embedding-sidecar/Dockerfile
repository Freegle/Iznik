FROM node:20-slim
RUN apt-get update && apt-get install -y --no-install-recommends curl && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY package.json .
# npm's --fetch-retries only retries individual package fetches; a mid-stream
# ECONNRESET (connection reset by the registry/proxy on a build VM) still aborts
# the whole command. Retry the entire install at the shell level to survive it.
RUN n=0; until npm install --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000; do \
      n=$((n+1)); \
      [ "$n" -ge 5 ] && { echo "npm install failed after $n attempts" >&2; exit 1; }; \
      echo "npm install attempt $n hit a transient network error; retrying in 15s..."; \
      sleep 15; \
    done
COPY server.mjs .
# Pre-download model on build so startup is fast
RUN echo "import {pipeline,env} from '@huggingface/transformers'; env.cacheDir='/app/model-cache'; await pipeline('feature-extraction','nomic-ai/nomic-embed-text-v1.5',{quantized:true}); console.log('Model cached.');" > /app/prebuild.mjs && \
    n=0; until node /app/prebuild.mjs; do \
      n=$((n+1)); \
      [ "$n" -ge 5 ] && { echo "model prebuild failed after $n attempts" >&2; exit 1; }; \
      echo "model prebuild attempt $n hit a transient network error; retrying in 15s..."; \
      sleep 15; \
    done && rm /app/prebuild.mjs
EXPOSE 3200
CMD ["node", "server.mjs"]
