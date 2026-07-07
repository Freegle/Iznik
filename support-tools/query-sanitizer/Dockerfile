FROM node:20-alpine

# Install wget for healthcheck
RUN apk add --no-cache wget

WORKDIR /app

# Copy package files
COPY package.json ./

# Install dependencies
RUN npm install --production --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000

# Copy application code
COPY server.js ./

# Environment variables
ENV PORT=8080
ENV PSEUDONYMIZER_URL=http://pseudonymizer:8080

EXPOSE 8080

CMD ["node", "server.js"]
