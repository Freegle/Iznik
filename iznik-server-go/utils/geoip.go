package utils

import (
	_ "embed"
	"net"
	"os"
	"sync"

	"github.com/oschwald/maxminddb-golang"
)

// GeoLite2-Country.mmdb is embedded in the binary so a country lookup always
// works with no external file - apiv2 runs natively on the prod DB nodes where
// we don't want to ship and maintain a separate mmdb. V1 (Message.php) and the
// Laravel incoming-mail path both read a GeoLite2 database; this keeps parity.
//
//go:embed GeoLite2-Country.mmdb
var embeddedGeoIP []byte

var (
	geoipOnce   sync.Once
	geoipReader *maxminddb.Reader
)

// loadGeoIPReader opens the GeoLite2 database once. An explicit GEOIP_MMDB_PATH
// wins so ops can point at a fresher database without a rebuild; otherwise we
// fall back to the copy embedded in the binary. Returns nil (lookups become
// no-ops) if neither can be opened.
func loadGeoIPReader() *maxminddb.Reader {
	if path := os.Getenv("GEOIP_MMDB_PATH"); path != "" {
		if reader, err := maxminddb.Open(path); err == nil {
			return reader
		}
	}
	if len(embeddedGeoIP) > 0 {
		if reader, err := maxminddb.FromBytes(embeddedGeoIP); err == nil {
			return reader
		}
	}
	return nil
}

// CountryCodeForIP resolves an IP to its ISO 3166-1 alpha-2 country code (e.g.
// "GB"). Returns "" - which callers store as NULL - when the IP is empty or
// unparseable, or the database can't resolve it (e.g. a private address). This
// matches V1's skip-on-error behaviour (Message.php) so nothing breaks when a
// country can't be determined. The stored 2-letter code is expanded to a full
// name on read (FetchByIds) for the ModTools "different country" warning.
func CountryCodeForIP(ip string) string {
	geoipOnce.Do(func() { geoipReader = loadGeoIPReader() })
	return countryCodeForIP(geoipReader, ip)
}

func countryCodeForIP(reader *maxminddb.Reader, ip string) string {
	if reader == nil || ip == "" {
		return ""
	}

	parsed := net.ParseIP(ip)
	if parsed == nil {
		return ""
	}

	var record struct {
		Country struct {
			ISOCode string `maxminddb:"iso_code"`
		} `maxminddb:"country"`
	}

	if err := reader.Lookup(parsed, &record); err != nil {
		return ""
	}

	return record.Country.ISOCode
}
