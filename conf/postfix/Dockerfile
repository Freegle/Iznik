FROM alpine:3.19

RUN apk add --no-cache postfix postfix-pcre bash curl openssl postfix-policyd-spf-perl

# Generate self-signed TLS cert for opportunistic STARTTLS
RUN openssl req -x509 -newkey rsa:2048 \
    -keyout /etc/ssl/private/postfix.key \
    -out /etc/ssl/certs/postfix.pem \
    -days 3650 -nodes \
    -subj '/CN=mail.ilovefreegle.org'

# Create mail user for running the pipe
RUN adduser -D -u 1000 mail-handler

# Copy configuration files
COPY main.cf /etc/postfix/main.cf
COPY master.cf /etc/postfix/master.cf
COPY transport /etc/postfix/transport
COPY freegle-mail-handler /usr/local/bin/freegle-mail-handler
RUN chmod +x /usr/local/bin/freegle-mail-handler

# Build lookup tables (Alpine uses lmdb, not hash)
RUN postmap lmdb:/etc/postfix/transport && \
    postalias lmdb:/etc/postfix/aliases

# Create spool directories
RUN mkdir -p /var/spool/postfix && \
    postfix set-permissions

# Expose SMTP port
EXPOSE 25

# Start postfix in foreground
CMD ["postfix", "start-fg"]
