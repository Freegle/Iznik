FROM node:20-alpine

# Install build dependencies for better-sqlite3 and wget for healthcheck
RUN apk add --no-cache python3 make g++ wget

WORKDIR /app

# Copy package files
COPY package.json ./

# Install dependencies
RUN npm install --production --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000

# Copy application code
COPY server.js db-query.js ./

# Create data and audit log directories
RUN mkdir -p /data /var/log/mcp-audit

# Environment variables
ENV PORT=8080
ENV LOKI_URL=http://loki:3100
ENV DATA_DIR=/data
ENV AUDIT_LOG_DIR=/var/log/mcp-audit

EXPOSE 8080

CMD ["node", "server.js"]
