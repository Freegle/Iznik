package utils

import (
	_ "embed"
	"net"
	"os"
	"sync"

	"github.com/oschwald/maxminddb-golang"
)

// GeoLite2-ASN.mmdb is embedded in the binary for the same reason as the Country database in
// geoip.go: apiv2 runs natively on the prod DB nodes, where we do not want to ship and maintain a
// separate file. It names the network an address belongs to (the number and the operator), which
// is how the email job page tells a cloud scanner from a person at home. The data is GeoLite2
// data created by MaxMind, available from https://www.maxmind.com.
//
//go:embed GeoLite2-ASN.mmdb
var embeddedASN []byte

var (
	asnOnce   sync.Once
	asnReader *maxminddb.Reader
)

// loadASNReader opens the GeoLite2-ASN database once. An explicit GEOIP_ASN_MMDB_PATH wins so
// ops can point at a fresher database without a rebuild; otherwise the copy embedded in the
// binary is used. Returns nil (lookups become no-ops) if neither can be opened.
func loadASNReader() *maxminddb.Reader {
	if path := os.Getenv("GEOIP_ASN_MMDB_PATH"); path != "" {
		if reader, err := maxminddb.Open(path); err == nil {
			return reader
		}
	}
	if len(embeddedASN) > 0 {
		if reader, err := maxminddb.FromBytes(embeddedASN); err == nil {
			return reader
		}
	}
	return nil
}

// ASNForIP resolves an IP address to the number and operator name of the network it belongs to,
// for example 8075 and "Microsoft Corporation". Returns 0 and "" when the address is empty or
// unparseable, or the database does not know it (a private address, say), so callers can treat
// "unknown" as one case.
func ASNForIP(ip string) (uint, string) {
	asnOnce.Do(func() { asnReader = loadASNReader() })
	return asnForIP(asnReader, ip)
}

func asnForIP(reader *maxminddb.Reader, ip string) (uint, string) {
	if reader == nil || ip == "" {
		return 0, ""
	}

	parsed := net.ParseIP(ip)
	if parsed == nil {
		return 0, ""
	}

	var record struct {
		Number       uint   `maxminddb:"autonomous_system_number"`
		Organisation string `maxminddb:"autonomous_system_organization"`
	}

	if err := reader.Lookup(parsed, &record); err != nil {
		return 0, ""
	}

	return record.Number, record.Organisation
}
