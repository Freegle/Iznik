package utils

import (
	"bytes"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/oschwald/maxminddb-golang"
	"github.com/stretchr/testify/assert"
)

// mustEmbeddedASNReader loads the real embedded GeoLite2-ASN database directly, independent of
// loadASNReader and asnOnce, so the lookup can be tested without depending on ASNForIP's cache
// having fired yet.
func mustEmbeddedASNReader(t *testing.T) *maxminddb.Reader {
	t.Helper()
	reader, err := maxminddb.FromBytes(embeddedASN)
	if err != nil {
		t.Fatalf("failed to load embedded GeoLite2-ASN db: %v", err)
	}
	return reader
}

// withASNPath sets GEOIP_ASN_MMDB_PATH for the duration of the test and restores whatever was
// there before (including "unset") on cleanup.
func withASNPath(t *testing.T, value string) {
	t.Helper()
	orig, had := os.LookupEnv("GEOIP_ASN_MMDB_PATH")
	if value == "" {
		os.Unsetenv("GEOIP_ASN_MMDB_PATH")
	} else {
		os.Setenv("GEOIP_ASN_MMDB_PATH", value)
	}
	t.Cleanup(func() {
		if had {
			os.Setenv("GEOIP_ASN_MMDB_PATH", orig)
		} else {
			os.Unsetenv("GEOIP_ASN_MMDB_PATH")
		}
	})
}

func TestASNForIP_NilReader(t *testing.T) {
	// A nil reader (the embedded db failed to load) must degrade to "unknown", not panic.
	asn, org := asnForIP(nil, "8.8.8.8")
	assert.Equal(t, uint(0), asn)
	assert.Equal(t, "", org)

	asn, org = asnForIP(nil, "")
	assert.Equal(t, uint(0), asn)
	assert.Equal(t, "", org)
}

func TestASNForIP_EmptyAndUnparseable(t *testing.T) {
	reader := mustEmbeddedASNReader(t)

	for _, ip := range []string{
		"",
		"not-an-ip",
		"999.999.999.999",
		"8.8.8",
		"gibberish-hostname.example.com",
		"   ",
		"1.2.3.4, 5.6.7.8", // a forwarded list is not one address
	} {
		asn, org := asnForIP(reader, ip)
		assert.Equal(t, uint(0), asn, "ip=%q", ip)
		assert.Equal(t, "", org, "ip=%q", ip)
	}
}

// Addresses here are cloud addresses taken from the email-page pulls of 9 to 15 Oct 2026, plus the
// first address of a BT and a Sky block, which stand for UK home broadband. None is a member's.
func TestASNForIP_KnownAddresses(t *testing.T) {
	reader := mustEmbeddedASNReader(t)

	tests := []struct {
		name    string
		ip      string
		asn     uint
		orgPart string
	}{
		{"Microsoft Azure UK", "85.210.241.4", 8075, "microsoft"},
		{"Amazon", "54.70.1.1", 16509, "amazon"},
		{"DigitalOcean", "164.90.228.156", 14061, "digitalocean"},
		{"Google DNS", "8.8.8.8", 15169, "google"},
		{"BT home broadband", "5.35.192.1", 2856, "british telecommunications"},
		{"Sky home broadband", "2.120.0.1", 5607, "sky"},
		{"BT home broadband over IPv6", "2a00:23c0::1", 2856, "british telecommunications"},
		{"Microsoft Azure UK over IPv6", "2a01:111:2002::1", 8075, "microsoft"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			asn, org := asnForIP(reader, tt.ip)
			assert.Equal(t, tt.asn, asn)
			assert.Contains(t, strings.ToLower(org), tt.orgPart)
		})
	}
}

func TestASNForIP_PrivateAndUnresolvable(t *testing.T) {
	reader := mustEmbeddedASNReader(t)

	// Private and loopback ranges have no network record, so they must come back as unknown
	// rather than as an error.
	for _, ip := range []string{"127.0.0.1", "10.0.0.1", "192.168.1.1", "::1"} {
		asn, org := asnForIP(reader, ip)
		assert.Equal(t, uint(0), asn, "ip=%s", ip)
		assert.Equal(t, "", org, "ip=%s", ip)
	}
}

// A database whose search tree is intact but whose records are damaged finds a record for an address
// and cannot read it. That must come back as unknown, not as an error or a panic.
func TestASNForIP_DamagedRecordsAnswerUnknown(t *testing.T) {
	good := mustEmbeddedASNReader(t)
	treeSize := int(good.Metadata.NodeCount) * int(good.Metadata.RecordSize) / 4
	dataStart := treeSize + 16
	metaStart := bytes.LastIndex(embeddedASN, []byte("\xab\xcd\xefMaxMind.com"))
	if metaStart < dataStart {
		t.Fatalf("cannot find the data section: data starts at %d, metadata at %d", dataStart, metaStart)
	}

	damaged := append([]byte(nil), embeddedASN...)
	for i := dataStart; i < metaStart; i++ {
		damaged[i] = 0xff
	}
	reader, err := maxminddb.FromBytes(damaged)
	if err != nil {
		t.Fatalf("the damaged copy should still open: %v", err)
	}

	// The copy really is damaged: the library itself cannot read the record for a known address.
	var raw struct {
		Number uint `maxminddb:"autonomous_system_number"`
	}
	assert.Error(t, reader.Lookup(net.ParseIP("8.8.8.8"), &raw))

	asn, org := asnForIP(reader, "8.8.8.8")
	assert.Equal(t, uint(0), asn)
	assert.Equal(t, "", org)
}

func TestLoadASNReader_NoEnvFallsBackToEmbedded(t *testing.T) {
	withASNPath(t, "")

	reader := loadASNReader()
	if assert.NotNil(t, reader) {
		asn, _ := asnForIP(reader, "8.8.8.8")
		assert.Equal(t, uint(15169), asn)
	}
}

func TestLoadASNReader_MissingPathFallsBackToEmbedded(t *testing.T) {
	withASNPath(t, "/nonexistent/path/does-not-exist.mmdb")

	reader := loadASNReader()
	if assert.NotNil(t, reader) {
		asn, _ := asnForIP(reader, "8.8.8.8")
		assert.Equal(t, uint(15169), asn)
	}
}

func TestLoadASNReader_UnreadableFileFallsBackToEmbedded(t *testing.T) {
	// A file that exists but is not a valid database: Open errors and the embedded copy is used.
	dir := t.TempDir()
	badPath := filepath.Join(dir, "bad.mmdb")
	if err := os.WriteFile(badPath, []byte("not a real mmdb file"), 0o644); err != nil {
		t.Fatalf("write temp file: %v", err)
	}

	withASNPath(t, badPath)

	reader := loadASNReader()
	if assert.NotNil(t, reader) {
		asn, _ := asnForIP(reader, "8.8.8.8")
		assert.Equal(t, uint(15169), asn)
	}
}

func TestLoadASNReader_ExplicitValidPathWins(t *testing.T) {
	// A valid database at the explicit path is the one used, not just a fall-through to the
	// embedded copy. The Country database is a valid mmdb with no network fields, so a lookup
	// through it can only be unknown: that proves the override was read.
	withASNPath(t, "GeoLite2-Country.mmdb")

	reader := loadASNReader()
	if assert.NotNil(t, reader) {
		asn, org := asnForIP(reader, "8.8.8.8")
		assert.Equal(t, uint(0), asn)
		assert.Equal(t, "", org)
	}
}

func TestLoadASNReader_EmptyEmbeddedFallsBackToNil(t *testing.T) {
	// The embedded file is always present at build time, but the loader guards the empty case
	// explicitly; exercise it directly.
	orig := embeddedASN
	t.Cleanup(func() { embeddedASN = orig })
	embeddedASN = nil

	withASNPath(t, "")

	assert.Nil(t, loadASNReader())
}

func TestLoadASNReader_CorruptEmbeddedBytesFallsBackToNil(t *testing.T) {
	orig := embeddedASN
	t.Cleanup(func() { embeddedASN = orig })
	embeddedASN = []byte("not a real mmdb")

	withASNPath(t, "")

	assert.Nil(t, loadASNReader())
}

func TestASNForIP_ExportedWrapperConcurrentUse(t *testing.T) {
	// ASNForIP opens the shared reader on first use through sync.Once; concurrent first use must
	// be race-free and every caller must see the same, correctly loaded answer.
	const n = 20
	var wg sync.WaitGroup
	asns := make([]uint, n)
	orgs := make([]string, n)
	for i := 0; i < n; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			asns[idx], orgs[idx] = ASNForIP("8.8.8.8")
		}(i)
	}
	wg.Wait()

	for i := 0; i < n; i++ {
		assert.Equal(t, uint(15169), asns[i])
		assert.Contains(t, orgs[i], "Google")
	}

	// An unparseable address through the exported wrapper is unknown too.
	asn, org := ASNForIP("not-an-ip")
	assert.Equal(t, uint(0), asn)
	assert.Equal(t, "", org)
}
