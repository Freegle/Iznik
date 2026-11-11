package job

import (
	"os"
	"strings"
	"sync"
	"testing"

	"github.com/oschwald/maxminddb-golang"
	"github.com/stretchr/testify/assert"
)

// organisations returns every network operator named in the embedded GeoLite2-ASN database,
// keyed by network number. The walk reads about a million networks, so it is done once.
var (
	orgsOnce sync.Once
	orgsByAS map[uint]map[string]bool
	orgsErr  error
)

func organisations(t *testing.T) map[uint]map[string]bool {
	t.Helper()
	orgsOnce.Do(func() {
		data, err := os.ReadFile("../utils/GeoLite2-ASN.mmdb")
		if err != nil {
			orgsErr = err
			return
		}
		reader, err := maxminddb.FromBytes(data)
		if err != nil {
			orgsErr = err
			return
		}
		orgsByAS = map[uint]map[string]bool{}
		networks := reader.Networks(maxminddb.SkipAliasedNetworks)
		for networks.Next() {
			var rec struct {
				Number       uint   `maxminddb:"autonomous_system_number"`
				Organisation string `maxminddb:"autonomous_system_organization"`
			}
			if _, err := networks.Network(&rec); err != nil {
				orgsErr = err
				return
			}
			if orgsByAS[rec.Number] == nil {
				orgsByAS[rec.Number] = map[string]bool{}
			}
			orgsByAS[rec.Number][rec.Organisation] = true
		}
		orgsErr = networks.Err()
	})
	if orgsErr != nil {
		t.Fatalf("could not read the embedded GeoLite2-ASN database: %v", orgsErr)
	}
	return orgsByAS
}

// Networks that carry people. The email page must never treat any of these as hosting: Cloudflare
// carries WARP, Akamai and Fastly carry Apple Private Relay, Apple is its own egress, Jisc is
// universities, the next fourteen are UK home broadband and mobile, and the last five are VPN and
// hosting operators that the week of 9 to 15 Oct showed carrying people.
var consumerNetworks = []struct {
	asn  uint
	name string
}{
	{13335, "Cloudflare, Inc."},
	{20940, "Akamai International B.V."},
	{16625, "Akamai Technologies, Inc."},
	{36183, "Akamai Technologies, Inc."},
	{54113, "Fastly, Inc."},
	{714, "Apple Inc."},
	{786, "Jisc Services Limited"},
	{2856, "British Telecommunications Limited"},
	{6871, "British Telecommunications Limited"},
	{5607, "Sky UK Limited"},
	{5089, "Virgin Media"},
	{13285, "TalkTalk"},
	{5378, "Vodafone Limited"},
	{25135, "Vodafone Limited"},
	{206067, "Three"},
	{35228, "O2 UK"},
	{13037, "Zen Internet Ltd"},
	{56329, "Gigaclear Limited"},
	{201838, "Community Fibre Limited"},
	{58273, "Broadband for the Rural North Limited."},
	{12390, "KCOM Group Limited"},
	{136787, "PacketHub S.A."},
	{49981, "WorldStream B.V."},
	{203020, "HostRoyale Technologies Pvt Ltd"},
	{212238, "Datacamp Limited"},
	{62240, "Clouvider Limited"},
}

func TestIsHostingNetwork_ListedNumbersAreHosting(t *testing.T) {
	assert.NotEmpty(t, hostingASNs)
	for asn, name := range hostingASNs {
		assert.True(t, isHostingNetwork(asn, ""), "AS%d %s is listed by number, whatever its name says", asn, name)
	}
}

func TestIsHostingNetwork_ByName(t *testing.T) {
	tests := []struct {
		org  string
		want bool
	}{
		// The providers on the list, in the spelling GeoLite2-ASN uses.
		{"Microsoft Corporation", true},
		{"Microsoft Corp", true},
		{"Amazon.com, Inc.", true},
		{"Amazon Technologies Inc.", true},
		{"Amazon", true},
		{"Google LLC", true},
		{"Google, LLC", true},
		{"DigitalOcean, LLC", true},
		{"Hetzner Online GmbH", true},
		{"OVH SAS", true},
		{"Akamai Connected Cloud", true},
		{"The Constant Company, LLC", true},
		{"Leaseweb UK Limited", true},
		{"LeaseWeb Netherlands B.V.", true},
		{"M247 Europe SRL", true},
		{"Contabo GmbH", true},
		{"Scaleway SAS", true},
		{"Oracle Corporation", true},
		{"IONOS SE", true},
		{"IONOS Cloud Inc.", true},

		// Case and punctuation do not matter.
		{"MICROSOFT CORPORATION", true},
		{"digitalocean llc", true},
		{"m247-europe", true},

		// Names that contain a keyword as part of a longer word are other companies.
		{"AMAZONIA TELECOMUNICACOES LTDA", false},
		{"Banco da Amazonia S/A", false},
		{"Datacampus SAS", false},
		{"Vultris", false},
		{"Microsofty Cafe Wifi", false},
		{"Googleplex Fibre Ltd", false},

		// Google's home broadband is not Google LLC.
		{"Google Fiber Inc.", false},

		// Home broadband and mobile in the UK.
		{"British Telecommunications Limited", false},
		{"Sky UK Limited", false},
		{"Virgin Media", false},
		{"TalkTalk", false},
		{"Vodafone Limited", false},
		{"Three", false},
		{"O2 UK", false},
		{"Zen Internet Ltd", false},

		// Egress that carries people.
		{"Cloudflare, Inc.", false},
		{"Akamai Technologies, Inc.", false},
		{"Akamai International B.V.", false},
		{"Fastly, Inc.", false},
		{"Apple Inc.", false},
		{"Jisc Services Limited", false},

		// Providers the pulls showed carrying people: members, or only slow phone clicks.
		{"PacketHub S.A.", false},
		{"WorldStream B.V.", false},
		{"HostRoyale Technologies Pvt Ltd", false},
		{"Datacamp Limited", false},

		{"", false},
	}
	for _, tt := range tests {
		t.Run(tt.org, func(t *testing.T) {
			assert.Equal(t, tt.want, isHostingNetwork(0, tt.org))
		})
	}
}

// Every hosting provider named by number is a hosting network in the embedded database, whether
// the number is listed or its operator's name is: the Azure family, every Amazon and Google
// network, the Leaseweb ones, and the server hosts. The four providers the week showed people on
// are not.
func TestIsHostingNetwork_ProvidersByNumberAgainstTheDatabase(t *testing.T) {
	orgs := organisations(t)

	hosting := map[string][]uint{
		"Microsoft":    {8075, 8068, 8069, 8070, 3598, 12076},
		"Amazon":       {16509, 14618, 8987, 7224},
		"Google":       {15169, 396982},
		"DigitalOcean": {14061},
		"OVH":          {16276},
		"Hetzner":      {24940, 213230},
		"Leaseweb":     {60781, 7203, 28753, 394380, 396362, 395954, 30633, 205544},
		"M247":         {9009},
		"Linode":       {63949},
		"Vultr":        {20473},
		"Contabo":      {51167},
		"Scaleway":     {12876},
		"Oracle":       {31898},
		"IONOS":        {8560},
	}
	for provider, numbers := range hosting {
		for _, asn := range numbers {
			names, ok := orgs[asn]
			if !assert.True(t, ok, "%s AS%d is not in the embedded database", provider, asn) {
				continue
			}
			for org := range names {
				assert.True(t, isHostingNetwork(asn, org), "%s AS%d (%s) should be a hosting network", provider, asn, org)
			}
		}
	}

	people := map[string]uint{"PacketHub": 136787, "WorldStream": 49981, "HostRoyale": 203020, "Datacamp": 212238}
	for provider, asn := range people {
		for org := range orgs[asn] {
			assert.False(t, isHostingNetwork(asn, org), "%s AS%d (%s) carries people and must not be a hosting network", provider, asn, org)
		}
	}
}

// A network that carries people is not hosting under any name the embedded database gives it. The
// typed names above are what the database says today; the names read from the database are what a
// refresh might change, so a network renamed into a hosting keyword (Akamai Technologies becoming
// Akamai Connected Cloud, say) fails here instead of holding Apple Private Relay users.
func TestIsHostingNetwork_NeverConsumerEgress(t *testing.T) {
	orgs := organisations(t)
	for _, n := range consumerNetworks {
		assert.False(t, isHostingNetwork(n.asn, n.name), "AS%d %s carries people and must never be treated as hosting", n.asn, n.name)
		assert.False(t, isMicrosoftNetwork(n.asn, n.name), "AS%d %s carries people and must never be treated as Microsoft", n.asn, n.name)

		names, ok := orgs[n.asn]
		if !assert.True(t, ok, "AS%d %s is not in the embedded GeoLite2-ASN database", n.asn, n.name) {
			continue
		}
		for org := range names {
			assert.False(t, isHostingNetwork(n.asn, org), "AS%d is called %q in the embedded database, which looks like hosting", n.asn, org)
			assert.False(t, isMicrosoftNetwork(n.asn, org), "AS%d is called %q in the embedded database, which looks like Microsoft", n.asn, org)
		}
	}
}

func TestHostingASNs_NeverListConsumerNetworks(t *testing.T) {
	for _, n := range consumerNetworks {
		_, listed := hostingASNs[n.asn]
		assert.False(t, listed, "AS%d %s must not be in hostingASNs", n.asn, n.name)
	}
}

// Every network number on the list exists in the embedded database, and its operator is also
// named by a keyword, so the number and the name agree with each other.
func TestHostingASNs_AreInTheDatabaseAndAgreeWithTheNames(t *testing.T) {
	orgs := organisations(t)
	for asn, name := range hostingASNs {
		names, ok := orgs[asn]
		if !assert.True(t, ok, "AS%d %s is not in the embedded GeoLite2-ASN database", asn, name) {
			continue
		}
		matched := false
		for org := range names {
			if matchesHostingName(org) {
				matched = true
			}
		}
		assert.True(t, matched, "AS%d %s: no keyword matches its operator name %v", asn, name, names)
	}
}

// A keyword that matches nothing is a dead entry: the provider was renamed, or the spelling is
// not the one GeoLite2-ASN uses (Linode is "Akamai Connected Cloud", Vultr is "The Constant
// Company"). This fails on the next database refresh that renames one, so the list is looked at.
func TestHostingNames_EachMatchesAnOperatorInTheDatabase(t *testing.T) {
	orgs := organisations(t)
	for _, keyword := range hostingNames {
		want := normaliseName(keyword)
		found := false
		for _, names := range orgs {
			for org := range names {
				if strings.Contains(normaliseName(org), want) {
					found = true
				}
			}
		}
		assert.True(t, found, "keyword %q matches no operator in the embedded GeoLite2-ASN database", keyword)
	}
}

// Whatever a keyword catches in the whole database, none of it is a network that carries people.
func TestHostingNames_CatchNoConsumerOperator(t *testing.T) {
	orgs := organisations(t)
	people := []string{
		"cloudflare", "akamai technologies", "akamai international", "fastly", "apple inc", "jisc",
		"british telecommunications", "sky uk", "virgin media", "talktalk", "vodafone", "o2 uk",
		"zen internet", "gigaclear", "community fibre", "hyperoptic", "kcom", "packethub",
		"worldstream", "hostroyale", "datacamp",
	}
	for asn, names := range orgs {
		for org := range names {
			if !matchesHostingName(org) {
				continue
			}
			lower := strings.ToLower(org)
			for _, p := range people {
				assert.False(t, strings.Contains(lower, p), "AS%d %q is caught by a keyword but looks like a network that carries people", asn, org)
			}
		}
	}
}
