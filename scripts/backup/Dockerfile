FROM google/cloud-sdk:alpine

# Install tar (already included in alpine, but ensure it's there)
RUN apk add --no-cache tar

# Copy backup script
COPY loki-backup.sh /usr/local/bin/loki-backup
RUN chmod +x /usr/local/bin/loki-backup

ENTRYPOINT ["/usr/local/bin/loki-backup"]
