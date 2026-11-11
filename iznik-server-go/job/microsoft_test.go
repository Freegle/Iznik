package job

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// Microsoft is the only network held on a UK address. The Azure family is named by number, and
// anything else the database calls Microsoft is caught by name.
func TestIsMicrosoftNetwork_ByNumber(t *testing.T) {
	assert.NotEmpty(t, microsoftASNs)
	for _, asn := range []uint{8075, 8068, 8069, 8070, 3598, 12076} {
		assert.True(t, isMicrosoftNetwork(asn, ""), "AS%d is Azure, whatever its name says", asn)
		assert.True(t, isHostingNetwork(asn, ""), "AS%d is a hosting network too", asn)
	}
	for asn := range microsoftASNs {
		assert.True(t, isMicrosoftNetwork(asn, ""), "AS%d", asn)
	}
}

func TestIsMicrosoftNetwork_ByName(t *testing.T) {
	tests := []struct {
		org  string
		want bool
	}{
		{"Microsoft Corporation", true},
		{"Microsoft Corp", true},
		{"Microsoft Limited", true},
		{"MICROSOFT CORPORATION", true},
		{"microsoft-corp-msn-as-block", true},

		// Another company with the word inside a longer one.
		{"Microsofty Cafe Wifi", false},
		{"Macrosoft Ltd", false},

		// The other hosting networks are not Microsoft.
		{"Amazon.com, Inc.", false},
		{"Google LLC", false},
		{"DigitalOcean, LLC", false},
		{"Leaseweb UK Limited", false},
		{"M247 Europe SRL", false},
		{"Oracle Corporation", false},

		{"British Telecommunications Limited", false},
		{"", false},
	}
	for _, tt := range tests {
		t.Run(tt.org, func(t *testing.T) {
			assert.Equal(t, tt.want, isMicrosoftNetwork(0, tt.org))
		})
	}
}

// Every Microsoft network named by number is in the embedded database under Microsoft's name, and
// every operator in the database with the word Microsoft in its name is held, so a Microsoft
// network added by a refresh is caught by name even if its number is not listed.
func TestMicrosoftNetworks_AgreeWithTheDatabase(t *testing.T) {
	orgs := organisations(t)

	for asn := range microsoftASNs {
		names, ok := orgs[asn]
		if !assert.True(t, ok, "AS%d is not in the embedded GeoLite2-ASN database", asn) {
			continue
		}
		for org := range names {
			assert.Contains(t, strings.ToLower(org), "microsoft", "AS%d is listed as Microsoft but the database calls it %q", asn, org)
		}
	}

	word := normaliseName(microsoftName)
	found := 0
	for asn, names := range orgs {
		for org := range names {
			if strings.Contains(normaliseName(org), word) {
				found++
				assert.True(t, isMicrosoftNetwork(asn, org), "AS%d %q has the word Microsoft in its name", asn, org)
			}
		}
	}
	assert.Greater(t, found, 0, "no operator in the embedded database is called Microsoft: the word needs looking at")
}

// Microsoft is a hosting network too, so the wider list never disagrees with the narrower one.
func TestEveryMicrosoftNetworkIsAlsoAHostingNetwork(t *testing.T) {
	orgs := organisations(t)
	for asn, names := range orgs {
		for org := range names {
			if isMicrosoftNetwork(asn, org) {
				assert.True(t, isHostingNetwork(asn, org), "AS%d %q", asn, org)
			}
		}
	}
}
