package utils

import (
	"os"
	"path/filepath"
	"sync"
	"testing"

	"github.com/oschwald/maxminddb-golang"
	"github.com/stretchr/testify/assert"
)

// mustEmbeddedReader loads the real embedded GeoLite2 database directly,
// independent of loadGeoIPReader/geoipOnce, so tests can exercise
// countryCodeForIP without depending on CountryCodeForIP's cache having
// fired yet.
func mustEmbeddedReader(t *testing.T) *maxminddb.Reader {
	t.Helper()
	reader, err := maxminddb.FromBytes(embeddedGeoIP)
	if err != nil {
		t.Fatalf("failed to load embedded GeoIP db: %v", err)
	}
	return reader
}

// withGeoIPPath sets GEOIP_MMDB_PATH for the duration of the test and
// restores whatever was there before (including "unset") on cleanup.
func withGeoIPPath(t *testing.T, value string) {
	t.Helper()
	orig, had := os.LookupEnv("GEOIP_MMDB_PATH")
	if value == "" {
		os.Unsetenv("GEOIP_MMDB_PATH")
	} else {
		os.Setenv("GEOIP_MMDB_PATH", value)
	}
	t.Cleanup(func() {
		if had {
			os.Setenv("GEOIP_MMDB_PATH", orig)
		} else {
			os.Unsetenv("GEOIP_MMDB_PATH")
		}
	})
}

func TestCountryCodeForIP_NilReader(t *testing.T) {
	// A nil reader (e.g. the embedded db failed to load) must degrade to "",
	// not panic.
	assert.Equal(t, "", countryCodeForIP(nil, "8.8.8.8"))
	assert.Equal(t, "", countryCodeForIP(nil, ""))
}

func TestCountryCodeForIP_EmptyAndUnparseable(t *testing.T) {
	reader := mustEmbeddedReader(t)

	tests := []string{
		"",
		"not-an-ip",
		"999.999.999.999",
		"8.8.8",
		"gibberish-hostname.example.com",
		"   ",
	}
	for _, ip := range tests {
		assert.Equal(t, "", countryCodeForIP(reader, ip), "ip=%q", ip)
	}
}

func TestCountryCodeForIP_KnownPublicIPs(t *testing.T) {
	reader := mustEmbeddedReader(t)

	tests := []struct {
		name    string
		ip      string
		country string
	}{
		{"Google DNS v4 is US", "8.8.8.8", "US"},
		{"BBC is GB", "212.58.244.22", "GB"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			assert.Equal(t, tt.country, countryCodeForIP(reader, tt.ip))
		})
	}
}

func TestCountryCodeForIP_PrivateAndUnresolvable(t *testing.T) {
	reader := mustEmbeddedReader(t)

	// Private/loopback ranges have no country entry in the GeoLite2 db, so
	// these must all resolve to "" rather than erroring.
	tests := []string{
		"127.0.0.1",
		"10.0.0.1",
		"192.168.1.1",
		"::1",
	}
	for _, ip := range tests {
		assert.Equal(t, "", countryCodeForIP(reader, ip), "ip=%s", ip)
	}
}

func TestLoadGeoIPReader_NoEnvFallsBackToEmbedded(t *testing.T) {
	withGeoIPPath(t, "")

	reader := loadGeoIPReader()
	if assert.NotNil(t, reader) {
		assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
	}
}

func TestLoadGeoIPReader_MissingPathFallsBackToEmbedded(t *testing.T) {
	withGeoIPPath(t, "/nonexistent/path/does-not-exist.mmdb")

	reader := loadGeoIPReader()
	if assert.NotNil(t, reader) {
		assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
	}
}

func TestLoadGeoIPReader_UnreadableFileFallsBackToEmbedded(t *testing.T) {
	// A file that exists but isn't a valid mmdb - Open should error and the
	// function must fall through to the embedded copy rather than returning nil.
	dir := t.TempDir()
	badPath := filepath.Join(dir, "bad.mmdb")
	if err := os.WriteFile(badPath, []byte("not a real mmdb file"), 0o644); err != nil {
		t.Fatalf("write temp file: %v", err)
	}

	withGeoIPPath(t, badPath)

	reader := loadGeoIPReader()
	if assert.NotNil(t, reader) {
		assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
	}
}

func TestLoadGeoIPReader_ExplicitValidPathWins(t *testing.T) {
	// Point GEOIP_MMDB_PATH at a real mmdb file (a copy of the embedded one) -
	// the explicit path must be used successfully, not just fall through.
	dir := t.TempDir()
	dbPath := filepath.Join(dir, "explicit.mmdb")
	if err := os.WriteFile(dbPath, embeddedGeoIP, 0o644); err != nil {
		t.Fatalf("write temp mmdb: %v", err)
	}

	withGeoIPPath(t, dbPath)

	reader := loadGeoIPReader()
	if assert.NotNil(t, reader) {
		assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
	}
}

func TestLoadGeoIPReader_EmptyEmbeddedFallsBackToNil(t *testing.T) {
	// Defensive branch that can't occur via go:embed in practice (the file is
	// always present at build time), but the code guards it explicitly with
	// len(embeddedGeoIP) > 0 - exercise the false side directly.
	orig := embeddedGeoIP
	t.Cleanup(func() { embeddedGeoIP = orig })
	embeddedGeoIP = nil

	withGeoIPPath(t, "")

	assert.Nil(t, loadGeoIPReader())
}

func TestLoadGeoIPReader_CorruptEmbeddedBytesFallsBackToNil(t *testing.T) {
	// Non-empty but invalid bytes - FromBytes should error and the function
	// returns nil rather than panicking.
	orig := embeddedGeoIP
	t.Cleanup(func() { embeddedGeoIP = orig })
	embeddedGeoIP = []byte("not a real mmdb")

	withGeoIPPath(t, "")

	assert.Nil(t, loadGeoIPReader())
}

func TestCountryCodeForIP_ExportedWrapperConcurrentUse(t *testing.T) {
	// CountryCodeForIP lazily initialises the shared reader via sync.Once;
	// concurrent first-use must be race-free and every caller must see the
	// same, correctly-loaded result.
	const n = 20
	var wg sync.WaitGroup
	results := make([]string, n)
	for i := 0; i < n; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			results[idx] = CountryCodeForIP("8.8.8.8")
		}(i)
	}
	wg.Wait()

	for _, r := range results {
		assert.Equal(t, "US", r)
	}

	// An unparseable IP through the exported wrapper must also degrade to "".
	assert.Equal(t, "", CountryCodeForIP("not-an-ip"))
}
