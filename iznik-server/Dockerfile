FROM ghcr.io/freegle/freegle-base:latest

# Build arg to bust Docker layer cache - set to current timestamp during build
ARG CACHE_BUST=1

ENV DEBIAN_FRONTEND=noninteractive \
	  TZ='UTZ' \
	  NOTVISIBLE="in users profile" \
	  STANDALONE=TRUE \
	  SQLHOST=percona \
	  SQLPORT=3306 \
	  SQLUSER=root \
	  SQLPASSWORD=iznik \
	  SQLDB=iznik \
	  PGSQLHOST=postgres \
	  PGSQLUSER=root \
	  PGSQLPASSWORD=iznik \
	  PGSQLDB=iznik \
	  PGSQLPORT=5432 \
	  PHEANSTALK_SERVER=beanstalkd \
	  IMAGE_DOMAIN=apiv1.localhost

# Install PCOV for code coverage (faster than Xdebug)
# Disable xdebug from base image - PCOV is faster for coverage-only use
RUN apt-get update && apt-get install -y php8.1-pcov && rm -rf /var/lib/apt/lists/* \
    && phpdismod xdebug \
    && phpenmod pcov \
    && echo "pcov.directory=/var/www/iznik" >> /etc/php/8.1/cli/conf.d/20-pcov.ini

# Configure Postfix for MailHog relay
RUN echo "postfix postfix/mailname string localhost" | debconf-set-selections \
    && echo "postfix postfix/main_mailer_type string 'Satellite system'" | debconf-set-selections \
    && echo "postfix postfix/relayhost string [mailhog]:1025" | debconf-set-selections

# Copy iznik-server from local submodule (build context is ./iznik-server)
# Use build arg to bust cache - any change to CACHE_BUST invalidates this layer
ARG CACHE_BUST=1
RUN echo "Cache bust: $CACHE_BUST"
COPY . /var/www/iznik

RUN touch /var/www/iznik/standalone \
  && mkdir -p /var/www/iznik/spool \
  && chown www-data:www-data /var/www/iznik/spool \
  && touch /tmp/iznik.uploadlock \
  && chmod 777 /tmp/iznik.uploadlock

# SSHD
RUN mkdir /var/run/sshd \
	&& echo 'root:password' | chpasswd \
	&& sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config \
	&& sed 's@session\s*required\s*pam_loginuid.so@session optional pam_loginuid.so@g' -i /etc/pam.d/sshd \
	&& echo "export VISIBLE=now" >> /etc/profile

WORKDIR /var/www/iznik

# /etc/iznik.conf is where our config goes.
RUN cp install/iznik.conf.php /etc/iznik.conf \
    && echo secret > /etc/iznik_jwt_secret \
    && sed -ie "s/upload_max_filesize = 2M/upload_max_filesize = 8M/" /etc/php/8.1/fpm/php.ini \
    && sed -ie "s/'SQLHOST', '.*'/'SQLHOST', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLHOSTS_READ', '.*'/'SQLHOSTS_READ', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLHOSTS_MOD', '.*'/'SQLHOSTS_MOD', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLUSER', '.*'/'SQLUSER', '$SQLUSER'/" /etc/iznik.conf \
    && sed -ie "s/'SQLPASSWORD', '.*'/'SQLPASSWORD', '$SQLPASSWORD'/" /etc/iznik.conf \
    && sed -ie "s/'SQLDB', '.*'/'SQLDB', '$PGSQLDB'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLHOST', '.*'/'PGSQLHOST', '$PGSQLHOST:$PGSQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLUSER', '.*'/'PGSQLUSER', '$PGSQLUSER'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLPASSWORD', '.*'/'PGSQLPASSWORD', '$PGSQLPASSWORD'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLDB', '.*'/'PGSQLDB', '$PGSQLDB'/" /etc/iznik.conf \
    && sed -ie "s/'PHEANSTALK_SERVER', '.*'/'PHEANSTALK_SERVER', '$PHEANSTALK_SERVER'/" /etc/iznik.conf \
    && sed -ie "s/'IMAGE_DOMAIN', '.*'/'IMAGE_DOMAIN', '$IMAGE_DOMAIN'/" /etc/iznik.conf \
    && sed -ie "s/'IMAGE_DELIVERY', '.*'/'IMAGE_DELIVERY', '$IMAGE_DELIVERY'/" /etc/iznik.conf \
    && sed -ie "s/case 'iznik.ilovefreegle.org'/default/" /etc/iznik.conf \
    && echo "[mysql]" > ~/.my.cnf \
    && echo "host=$SQLHOST" >> ~/.my.cnf \
    && echo "user=$SQLUSER" >> ~/.my.cnf \
    && echo "password=$SQLPASSWORD" >> ~/.my.cnf \
    && echo "redis" > /etc/iznikredis

# Install composer dependencies.
# Retry composer install: codeload.github.com (composer's `dist` source)
# intermittently returns HTTP 400 for a random subset of packages, which would
# otherwise fail the whole build. Composer caches packages it already fetched,
# so each retry only re-downloads the ones that failed previously. Mirrors the
# --fetch-retries convention used for npm installs elsewhere in this repo.
RUN wget https://getcomposer.org/composer-2.phar -O composer.phar \
    && php composer.phar self-update \
    && cd composer \
    && { n=0; until echo Y | php ../composer.phar install; do \
           n=$((n+1)); \
           [ "$n" -ge 5 ] && { echo "composer install failed after $n attempts" >&2; exit 1; }; \
           echo "composer install attempt $n hit a transient network error; retrying in 15s..."; \
           sleep 15; \
         done; } \
    && cd ..

# Cron jobs for background scripts (excluding messages_spatial)
RUN grep -v "message_spatial.php" install/crontab | crontab -u root -

# Tidy image
RUN rm -rf /var/lib/apt/lists/*

CMD /etc/init.d/ssh start \
  && cp install/nginx.conf /etc/nginx/sites-available/default \
  && /etc/init.d/nginx start \
	&& /etc/init.d/php8.1-fpm start \
	&& /etc/init.d/postfix start \

  # Update crontab with PHEANSTALK_TUBE from environment (defaults to 'default')
  && TUBE=${PHEANSTALK_TUBE:-default} \
  && crontab -l > /tmp/current_crontab \
  && echo "PHEANSTALK_TUBE=$TUBE" | cat - /tmp/current_crontab | crontab - \
  && rm /tmp/current_crontab \
	&& /etc/init.d/cron start \

  # Update config from environment variables (uses PHP, immune to shell/sed quoting issues)
  && for s in LOVE_JUNK_API LOVE_JUNK_SECRET PARTNER_KEY PARTNER_NAME IMAGE_DOMAIN; do \
       if [ -f "/run/secrets/$s" ]; then export "$s=$(cat /run/secrets/$s)"; fi; \
     done \
  && php install/startup-config.php \

  # Wait for database to be ready. All databases are created before containers start:
  # - iznik: by batch migrations (phase 1 of docker-compose up)
  # - iznik_phpunit_test/iznik_go_test: by setup-test-database.sh (between phases)
  && echo "Waiting for database $SQLDB..." \
  && for i in $(seq 1 120); do mysql -u root -e "SELECT 1 FROM $SQLDB.partners_keys LIMIT 1" 2>/dev/null && break; sleep 2; done \
  && mysql -u root -e "SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" \
  && mysql -u root -e "use $SQLDB;REPLACE INTO partners_keys (partner, \`key\`) VALUES ('$PARTNER_NAME', '$PARTNER_KEY');" \
  && mysql -u root -e "SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));" \
  && if [ "$SQLDB" != "iznik_phpunit_test" ]; then php scripts/cron/get_app_release_versions.php >> /tmp/iznik.get_app_release_versions.out 2>&1; fi \
  && sh -c 'nohup sh -c "while true; do cd /var/www/iznik/scripts/cron && php ./message_spatial.php >> /tmp/iznik.message_spatial.out 2>&1; sleep 10; done" </dev/null >/dev/null 2>&1 & exec sleep infinity'

EXPOSE 80
