FROM ghcr.io/freegle/freegle-base:latest

WORKDIR /app

COPY package.json ./
RUN npm install --fetch-retries=5 --fetch-retry-mintimeout=20000 --fetch-retry-maxtimeout=120000

COPY . .

EXPOSE 80

CMD ["npm", "start"]
