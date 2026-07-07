FROM node:22-alpine

# Install Google Cloud SDK for gsutil (in /opt to avoid volume mount conflicts), Docker CLI, docker-compose, and git
RUN apk add --no-cache python3 py3-pip curl bash docker-cli docker-cli-compose git && \
    curl -O https://dl.google.com/dl/cloudsdk/channels/rapid/downloads/google-cloud-cli-linux-x86_64.tar.gz && \
    tar -xf google-cloud-cli-linux-x86_64.tar.gz && \
    mv google-cloud-sdk /opt/ && \
    /opt/google-cloud-sdk/install.sh --quiet --usage-reporting=false --path-update=false && \
    rm google-cloud-cli-linux-x86_64.tar.gz

ENV PATH="/opt/google-cloud-sdk/bin:${PATH}"

WORKDIR /app

# Install dependencies
COPY package*.json ./
RUN npm install --production

# Copy application files
COPY server.js ./

# Create data directory
RUN mkdir -p /data

# Expose API port
EXPOSE 8082

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
  CMD node -e "require('http').get('http://localhost:8082/health', (r) => {process.exit(r.statusCode === 200 ? 0 : 1)})"

# Start the API server
CMD ["node", "server.js"]
