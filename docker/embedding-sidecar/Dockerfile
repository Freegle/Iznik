FROM node:20-slim
RUN apt-get update && apt-get install -y --no-install-recommends curl && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY package.json .
RUN npm install --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000
COPY server.mjs .
# Pre-download model on build so startup is fast
RUN echo "import {pipeline,env} from '@huggingface/transformers'; env.cacheDir='/app/model-cache'; await pipeline('feature-extraction','nomic-ai/nomic-embed-text-v1.5',{quantized:true}); console.log('Model cached.');" > /app/prebuild.mjs && node /app/prebuild.mjs && rm /app/prebuild.mjs
EXPOSE 3200
CMD ["node", "server.mjs"]
