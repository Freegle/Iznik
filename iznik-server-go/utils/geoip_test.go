package utils

import (
	"os"
	"path/filepath"
	"sync"
	"testing"

	"github.com/oschwald/maxminddb-golang"
	"github.com/stretchr/testify/assert"
)

// realGeoIPReader loads the embedded mmdb directly, bypassing the
// package-level sync.Once so each test gets an independent reader.
func realGeoIPReader(t *testing.T) *maxminddb.Reader {
	t.Helper()
	reader, err := maxminddb.FromBytes(embeddedGeoIP)
	assert.NoError(t, err)
	return reader
}

func TestCountryCodeForIP_NilReader(t *testing.T) {
	assert.Equal(t, "", countryCodeForIP(nil, "8.8.8.8"))
}

func TestCountryCodeForIP_EmptyIP(t *testing.T) {
	reader := realGeoIPReader(t)
	assert.Equal(t, "", countryCodeForIP(reader, ""))
}

func TestCountryCodeForIP_UnparseableIP(t *testing.T) {
	reader := realGeoIPReader(t)
	assert.Equal(t, "", countryCodeForIP(reader, "not-an-ip-address"))
}

func TestCountryCodeForIP_KnownAddress(t *testing.T) {
	reader := realGeoIPReader(t)
	// 8.8.8.8 is Google's public DNS, consistently geolocated to the US in
	// the free GeoLite2-Country dataset.
	assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
}

func TestCountryCodeForIP_IPv6Address(t *testing.T) {
	reader := realGeoIPReader(t)
	// Google public DNS IPv6.
	assert.Equal(t, "US", countryCodeForIP(reader, "2001:4860:4860::8888"))
}

func TestCountryCodeForIP_PrivateAddressHasNoCountry(t *testing.T) {
	reader := realGeoIPReader(t)
	assert.Equal(t, "", countryCodeForIP(reader, "192.168.1.1"))
}

func TestCountryCodeForIP_PublicWrapperUsesSingletonReader(t *testing.T) {
	// Reset the package-level sync.Once/reader so this test controls loading,
	// then reset again afterwards. sync.Once must never be copied, so replace
	// it with a fresh zero value rather than saving/restoring the old one.
	defer func() {
		geoipOnce = sync.Once{}
		geoipReader = nil
	}()
	geoipOnce = sync.Once{}
	geoipReader = nil

	assert.Equal(t, "US", CountryCodeForIP("8.8.8.8"))
	// Second call reuses the already-loaded singleton reader.
	assert.Equal(t, "US", CountryCodeForIP("8.8.8.8"))
}

func TestLoadGeoIPReader_FallsBackToEmbedded(t *testing.T) {
	old := os.Getenv("GEOIP_MMDB_PATH")
	defer os.Setenv("GEOIP_MMDB_PATH", old)
	os.Unsetenv("GEOIP_MMDB_PATH")

	reader := loadGeoIPReader()
	assert.NotNil(t, reader)
	assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
}

func TestLoadGeoIPReader_InvalidPathFallsBackToEmbedded(t *testing.T) {
	old := os.Getenv("GEOIP_MMDB_PATH")
	defer os.Setenv("GEOIP_MMDB_PATH", old)
	os.Setenv("GEOIP_MMDB_PATH", "/nonexistent/path/does-not-exist.mmdb")

	reader := loadGeoIPReader()
	assert.NotNil(t, reader)
	assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
}

func TestLoadGeoIPReader_ExplicitPathWins(t *testing.T) {
	old := os.Getenv("GEOIP_MMDB_PATH")
	defer os.Setenv("GEOIP_MMDB_PATH", old)

	dir := t.TempDir()
	path := filepath.Join(dir, "custom.mmdb")
	assert.NoError(t, os.WriteFile(path, embeddedGeoIP, 0o644))
	os.Setenv("GEOIP_MMDB_PATH", path)

	reader := loadGeoIPReader()
	assert.NotNil(t, reader)
	assert.Equal(t, "US", countryCodeForIP(reader, "8.8.8.8"))
}
