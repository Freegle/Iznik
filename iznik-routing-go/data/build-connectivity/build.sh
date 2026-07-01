#!/usr/bin/env bash
# build.sh — regenerate iznik-routing-go/data/uk_lsoa_connectivity.csv from source.
# Run this when the DfT publishes a new Transport Connectivity Metric release.
#
#   ./build.sh [path-to.ods]
#
# With no argument it downloads the pinned 2025 release. To update: find the latest ODS
# on the DfT "Transport connectivity metric" / connectivity statistics page (see README),
# then either pass its local path or update ODS_URL below.
set -euo pipefail
cd "$(dirname "$0")"

# Pinned source — DfT Transport Connectivity Metric 2025 (experimental, Q4 2024, E&W).
# Landing page: https://www.gov.uk/government/publications/transport-connectivity-metric
ODS_URL="${ODS_URL:-https://assets.publishing.service.gov.uk/media/68c966fc07d9e92bc5517b80/connectivity_metrics_2025.ods}"

ODS="${1:-connectivity_metrics.ods}"
if [[ ! -f "$ODS" ]]; then
  echo "Downloading DfT connectivity ODS → $ODS"
  curl -sSL -o "$ODS" "$ODS_URL"
fi

echo "1/3 Extracting LSOA connectivity from ODS…"
unzip -p "$ODS" content.xml | node extract_lsoa.js lsoa_conn_codes.csv

echo "2/3 Fetching ONS LSOA 2021 centroids and joining…"
node join_centroids.js lsoa_conn_codes.csv uk_lsoa_connectivity.csv

echo "3/4 Installing E&W → ../uk_lsoa_connectivity.csv"
cp uk_lsoa_connectivity.csv ../uk_lsoa_connectivity.csv

echo "4/4 Appending Scotland (SIMD 2020 Access domain, quantile-mapped onto E&W)…"
# Uses the just-installed E&W file as the quantile reference, then appends Scotland rows.
node scotland_append.js ../uk_lsoa_connectivity.csv scotland_rows.csv
cat scotland_rows.csv >> ../uk_lsoa_connectivity.csv
# Northern Ireland: designed + agent-verified (NIMDM 2017 Access domain, SOA2001 + proj4
# reprojection) — see plans/active/scotland-ni-connectivity.md. Add as a 5th stage when
# the proj4 dependency is introduced into build-connectivity/.

wc -l ../uk_lsoa_connectivity.csv
echo "Done. Commit ../uk_lsoa_connectivity.csv (and note the release/date in README)."
