FROM node:22-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM node:22-alpine AS runner
WORKDIR /app

# Runtime deps only
COPY --from=builder /app/.output .output

# Data dir — mount a Fly volume here at /data
ENV EEE_SQLITE_PATH=/data/classifications.sqlite
ENV EEE_LABELS_PATH=/data/eee-labels.db
ENV PORT=3000
ENV HOST=0.0.0.0

EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
