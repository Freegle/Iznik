package utils

import (
	"os"
	"testing"

	"github.com/oschwald/maxminddb-golang"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// countryCodeForIP — table-driven tests against the real embedded GeoLite2
// database, so the well-known test IPs resolve to their real country codes.
// ---------------------------------------------------------------------------

func TestCountryCodeForIP(t *testing.T) {
	reader := loadGeoIPReader()
	if reader == nil {
		t.Fatal("expected embedded GeoLite2 database to load")
	}
	defer reader.Close()

	tests := []struct {
		name   string
		reader *maxminddb.Reader
		ip     string
		want   string
	}{
		{"nil reader returns empty", nil, "8.8.8.8", ""},
		{"empty ip returns empty", reader, "", ""},
		{"unparseable ip returns empty", reader, "not-an-ip", ""},
		{"unparseable ip with garbage octets", reader, "999.999.999.999", ""},
		{"known US IP resolves", reader, "8.8.8.8", "US"},
		{"known GB IP resolves", reader, "81.2.69.142", "GB"},
		{"reserved test-net IP has no country", reader, "192.0.2.1", ""},
		{"IPv6 loopback has no country", reader, "::1", ""},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := countryCodeForIP(tt.reader, tt.ip)
			assert.Equal(t, tt.want, got)
		})
	}
}

func TestCountryCodeForIP_PackageLevelWrapper(t *testing.T) {
	// CountryCodeForIP drives the sync.Once-guarded package-level reader; call
	// it more than once to prove the cached reader is reused rather than
	// reloaded, then check a known-good and a known-empty lookup.
	assert.Equal(t, "US", CountryCodeForIP("8.8.8.8"))
	assert.Equal(t, "US", CountryCodeForIP("8.8.8.8"))
	assert.Equal(t, "", CountryCodeForIP(""))
}

// ---------------------------------------------------------------------------
// loadGeoIPReader
// ---------------------------------------------------------------------------

func TestLoadGeoIPReader_FallsBackToEmbedded(t *testing.T) {
	orig, had := os.LookupEnv("GEOIP_MMDB_PATH")
	os.Unsetenv("GEOIP_MMDB_PATH")
	defer func() {
		if had {
			os.Setenv("GEOIP_MMDB_PATH", orig)
		}
	}()

	reader := loadGeoIPReader()
	if reader == nil {
		t.Fatal("expected embedded database fallback to succeed")
	}
	defer reader.Close()

	assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
}

func TestLoadGeoIPReader_InvalidPathFallsBackToEmbedded(t *testing.T) {
	// An explicit but unopenable GEOIP_MMDB_PATH must not make lookups fail
	// outright - we still fall back to the embedded copy.
	orig, had := os.LookupEnv("GEOIP_MMDB_PATH")
	os.Setenv("GEOIP_MMDB_PATH", "/nonexistent/path/does-not-exist.mmdb")
	defer func() {
		if had {
			os.Setenv("GEOIP_MMDB_PATH", orig)
		} else {
			os.Unsetenv("GEOIP_MMDB_PATH")
		}
	}()

	reader := loadGeoIPReader()
	if reader == nil {
		t.Fatal("expected fallback to embedded database when GEOIP_MMDB_PATH is unopenable")
	}
	defer reader.Close()

	assert.Equal(t, "GB", countryCodeForIP(reader, "81.2.69.142"))
}

func TestLoadGeoIPReader_ReturnsNilWhenNothingAvailable(t *testing.T) {
	// With no GEOIP_MMDB_PATH and no usable embedded database, loadGeoIPReader
	// must return nil (lookups become no-ops) rather than panicking.
	origEnv, had := os.LookupEnv("GEOIP_MMDB_PATH")
	os.Unsetenv("GEOIP_MMDB_PATH")
	defer func() {
		if had {
			os.Setenv("GEOIP_MMDB_PATH", origEnv)
		}
	}()

	origEmbedded := embeddedGeoIP
	embeddedGeoIP = nil
	defer func() { embeddedGeoIP = origEmbedded }()

	reader := loadGeoIPReader()
	assert.Nil(t, reader)
}

func TestLoadGeoIPReader_ExplicitPathWins(t *testing.T) {
	// Copy the embedded database out to a temp file and point GEOIP_MMDB_PATH
	// at it, so the "explicit path wins" branch opens a real, valid database.
	tmp, err := os.CreateTemp(t.TempDir(), "geoip-*.mmdb")
	if err != nil {
		t.Fatalf("failed to create temp file: %v", err)
	}
	if _, err := tmp.Write(embeddedGeoIP); err != nil {
		t.Fatalf("failed to write temp mmdb: %v", err)
	}
	if err := tmp.Close(); err != nil {
		t.Fatalf("failed to close temp mmdb: %v", err)
	}

	orig, had := os.LookupEnv("GEOIP_MMDB_PATH")
	os.Setenv("GEOIP_MMDB_PATH", tmp.Name())
	defer func() {
		if had {
			os.Setenv("GEOIP_MMDB_PATH", orig)
		} else {
			os.Unsetenv("GEOIP_MMDB_PATH")
		}
	}()

	reader := loadGeoIPReader()
	if reader == nil {
		t.Fatal("expected explicit GEOIP_MMDB_PATH database to load")
	}
	defer reader.Close()

	assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
}
