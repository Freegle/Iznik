#!/bin/bash
#
# geoip-update.sh — refresh a GeoLite2 database from MaxMind: Country (the default) or ASN.
#
# Freegle geolocates the sender IP of every post to a country (messages.fromcountry)
# so ModTools can flag posts from outside the UK. apiv2 embeds a copy of the
# database in its binary and iznik-batch bundles one in resources/geoip, so the
# lookup always works out of the box - but those copies are only as new as the
# files committed to the repo, and rebuilding does not change that.
# This script keeps a system copy current so GEOIP_MMDB_PATH can point at fresh
# data with no rebuild. Run weekly from cron (MaxMind updates GeoLite2 weekly).
#
# apiv2 also embeds GeoLite2-ASN, which names the network an address belongs to
# (the email job page uses it with the country). GEOIP_EDITION=GeoLite2-ASN
# refreshes that one, and GEOIP_ASN_MMDB_PATH points apiv2 at the copy.
#
# Credentials live in /etc/GeoIP.conf (the standard geoipupdate config, root-only,
# NOT committed to git). Only the LicenseKey is needed for the direct download.
#
# Usage:  [GEOIP_EDITION=GeoLite2-Country|GeoLite2-ASN] geoip-update.sh [DEST_MMDB_PATH]
#   DEST defaults to /usr/share/GeoIP/<edition>.mmdb
#
set -euo pipefail

CONF="${GEOIP_CONF:-/etc/GeoIP.conf}"
EDITION="${GEOIP_EDITION:-GeoLite2-Country}"
DEST="${1:-/usr/share/GeoIP/${EDITION}.mmdb}"

case "$EDITION" in
  GeoLite2-Country|GeoLite2-ASN) ;;
  *)
    echo "geoip-update: GEOIP_EDITION must be GeoLite2-Country or GeoLite2-ASN, not $EDITION" >&2
    exit 1
    ;;
esac

if [ ! -r "$CONF" ]; then
  echo "geoip-update: $CONF not readable" >&2
  exit 1
fi

KEY="$(awk '/^[[:space:]]*LicenseKey[[:space:]]/{print $2; exit}' "$CONF")"
if [ -z "$KEY" ]; then
  echo "geoip-update: no LicenseKey found in $CONF" >&2
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

URL="https://download.maxmind.com/app/geoip_download?edition_id=${EDITION}&license_key=${KEY}&suffix=tar.gz"
if ! curl -fsS -L -o "$TMP/db.tar.gz" "$URL"; then
  echo "geoip-update: download failed (check credentials / network)" >&2
  exit 1
fi

# The archive is GeoLite2-Country_YYYYMMDD/GeoLite2-Country.mmdb — pull just the db.
MMDB_IN_TAR="$(tar tzf "$TMP/db.tar.gz" | grep -m1 "/${EDITION}\.mmdb\$" || true)"
if [ -z "$MMDB_IN_TAR" ]; then
  echo "geoip-update: no ${EDITION}.mmdb in downloaded archive" >&2
  exit 1
fi
tar xzf "$TMP/db.tar.gz" -C "$TMP" "$MMDB_IN_TAR"
SRC="$TMP/$MMDB_IN_TAR"

# Validate: a real mmdb carries the MaxMind metadata marker, and is a few MB.
if ! grep -qa 'MaxMind.com' "$SRC"; then
  echo "geoip-update: downloaded file is not a valid mmdb (no metadata marker)" >&2
  exit 1
fi
SZ="$(stat -c%s "$SRC")"
if [ "$SZ" -lt 1000000 ]; then
  echo "geoip-update: downloaded mmdb implausibly small ($SZ bytes)" >&2
  exit 1
fi

# Atomic swap: copy onto the destination filesystem, then rename in place so a
# concurrent reader never sees a half-written file.
install -D -m 0644 "$SRC" "${DEST}.new"
mv -f "${DEST}.new" "$DEST"
echo "geoip-update: installed ${EDITION} -> ${DEST} (${SZ} bytes, $(date -u +%FT%TZ))"
