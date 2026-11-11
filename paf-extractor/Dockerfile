# PAF extractor sidecar: Wine + a virtual display to run AFD's Windows-only extractor,
# plus Node for the small HTTP service batch talks to. See server.mjs.
FROM debian:bookworm-slim

RUN dpkg --add-architecture i386 \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
       wine wine32 xvfb xauth xdotool imagemagick procps curl ca-certificates nodejs \
    && rm -rf /var/lib/apt/lists/*

ENV WINEDEBUG=-all WINEPREFIX=/wine
# Create the Wine prefix at build time; the first run otherwise spends a minute on it.
RUN xvfb-run -a wineboot --init; wineserver -w; true

# AFD's SFTP host key, so a changed key fails the download rather than being trusted.
COPY known_hosts /root/.ssh/known_hosts
COPY server.mjs extract.sh /app/

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD curl -fsS http://localhost:8080/health || exit 1
CMD ["node", "/app/server.mjs"]
