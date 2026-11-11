package job

import (
	"strings"
	"unicode"
)

// Hosting networks are the data-centre networks that mail-security scanners open links from. The
// email job page treats them in two ways (see AutoOpen):
//
//   - A visitor abroad is held back by country, on any network, so this file does not matter to them.
//   - A visitor on a UK address, or one with no country, is held back only on a Microsoft network
//     (isMicrosoftNetwork). On any other hosting network (isHostingNetwork) the advert opens, and
//     the decision log records the visit as SeenOtherHosting, with the network and the visitor's
//     clock, so the evidence for holding more networks builds up in Loki.
//
// A network is a hosting network when its number is in hostingASNs, or when the operator name that
// GeoLite2-ASN gives it contains one of hostingNames as whole words, or when it is Microsoft's.
// Anything none of these knows is not hosting, so an unknown network opens the advert.
//
// What the lists were built from. The email landing page took 791 clicks from 9 to 15 Oct 2026
// (POST /apiv2/job, placement email_redirect): 312 from someone signed in on that browser and 479
// from nobody. Each member-less click was timed against the tracker click on the email's link,
// found by job and network, and put in one of four groups:
//
//	fast     within 2 minutes of the send
//	echo     within 20 seconds of a person's own click on the same job from another network
//	slow     2 minutes or more after the send, and no echo
//	untimed  no tracker click from the same network to time it by
//
// Scanners are fast plus echo. People are signed in plus slow. A network is listed by number when
// it had at least 5 clicks and its scanners outnumber its people, and no home broadband or mobile
// network comes near:
//
//	ASN      network               clicks  signed in  fast  echo  slow  untimed  listed
//	8075     Microsoft                112          1    72     1    38        0   yes
//	14618    Amazon                    75          0    37     0     2       36   yes
//	15169    Google                    39          0     3    29     7        0   yes
//	9009     M247                      26          0    10     1     0       15   yes
//	396362   Leaseweb USA               9          0     6     0     1        2   yes
//	395954   Leaseweb USA               7          0     5     0     1        1   yes
//	30633    Leaseweb USA               5          0     1     1     0        3   yes
//
//	2856     BT                       124         79     2     1    41        1   no
//	5607     Sky                       65         37     0     6    22        0   no
//	5089     Virgin Media              62         40     0     5    17        0   no
//	13285    TalkTalk                  42         29     1     1    11        0   no
//	5378     Vodafone                  36         22     0     0    14        0   no
//	206067   Three                     20         13     1     1     5        0   no
//
// Too few clicks to judge by number: AWS 16509 (4: one scanner, and three phones in the UK that
// came hours after the send), PacketHub 136787 (3: one member), Datacamp 212238 (2: both members),
// Clouvider 62240 (2: one member), Akamai 36183 (1). AWS 16509 is caught by name all the same: its
// tracker clicks were scanners (160 of 195 within 2 minutes).
//
// The tracker alone, 1 to 15 Oct, with clicks and how many came within 2 minutes of the send:
// Microsoft 570 and 294, AWS 16509 195 and 160, Amazon 14618 86 and 49, Google 98 and 5 (echoes of
// a person's click), M247 12 and 12, Leaseweb 19 and 15, DigitalOcean 402 and 121 (every one from
// the same Python HTTP client, on six addresses in Germany, and none ran the page). Against UK
// home and mobile: BT 394 and 18, Virgin Media 213 and 3, Sky 165 and 2, Cloudflare 50 and 1.
//
// Why only Microsoft on a UK address. Of the 479 clicks from nobody, 273 are held by the whole rule
// (216 for the country, 57 for Microsoft's UK addresses, Azure UK South and UK West) and 206 are
// left alone. The 57 were scanners: a median of 90 seconds after the send, 42 of them inside 2
// minutes. The 6 clicks from other hosting networks on UK addresses (Amazon London 3, Google 3)
// were people: every one had a UK clock, five were on a phone and came from 1 to 44 hours after the
// send. So the other networks are noted, not held. All 312 signed-in clicks are left alone.
//
// Never listed, by number or by name: Cloudflare (13335, WARP), Akamai's CDN networks (20940, 16625,
// 36183) and Fastly (54113), which carry Apple Private Relay, Apple (714), Jisc (786), and every UK
// home broadband and mobile network. Akamai Connected Cloud (Linode, 63949) is Akamai's cloud
// hosting and is listed by name. hosting_test.go checks that no network that carries people is
// hosting under any name the embedded database gives it, and checks every list entry against the
// operator names in the embedded database, so a refresh that renames one fails there.

// microsoftASNs are the networks Microsoft runs Azure from. They are the only hosting networks held
// on a UK address. An operator whose name has the word Microsoft in it is held too (microsoftName),
// which catches Microsoft's other networks and any added by a database refresh.
var microsoftASNs = map[uint]bool{
	8075:  true,
	8068:  true,
	8069:  true,
	8070:  true,
	3598:  true,
	12076: true,
}

// microsoftName is matched against the operator name as a whole word.
const microsoftName = "Microsoft"

// microsoftNameNormalised is microsoftName in the form normaliseName gives an operator name.
var microsoftNameNormalised = normaliseName(microsoftName)

// hostingASNs are the networks the table above lists, other than Microsoft's.
var hostingASNs = map[uint]string{
	14618:  "Amazon",
	15169:  "Google",
	9009:   "M247",
	396362: "Leaseweb USA",
	395954: "Leaseweb USA",
	30633:  "Leaseweb USA",
}

// hostingNames are matched against the operator name as whole words, ignoring case and
// punctuation, so "Amazon.com, Inc." matches "Amazon" and "AMAZONIA TELECOM" does not. They catch
// the other networks of a provider the table lists (Azure has more than one), DigitalOcean (seen
// at the tracker only, above), and the server hosts that sent no clicks to the email page in the
// week (Hetzner, OVH, Contabo, Scaleway, Oracle, IONOS, Linode, Vultr), which are matched for what
// they are rather than by a count.
//
// PacketHub, Datacamp, WorldStream and HostRoyale are absent on purpose. The week showed people
// on them (members signed in on PacketHub and Datacamp, which are VPN operators; only phone clicks
// hours after the send on WorldStream and HostRoyale) and nothing within 2 minutes of the send.
// The country test still stops any of them that is abroad.
//
// GeoLite2-ASN does not call Linode or Vultr by those names, so they are listed under the names it
// uses. Whole-word matching matters here: "Amazon" starts Amazonia Telecomunicacoes, "Datacamp"
// starts Datacampus and "Vultr" starts Vultris, all other companies.
var hostingNames = []string{
	"Microsoft",
	"Amazon",
	"Google LLC", // Google's home broadband, Google Fiber, is a different company.
	"DigitalOcean",
	"Hetzner",
	"OVH",
	"Akamai Connected Cloud", // Linode.
	"The Constant Company",   // Vultr.
	"Leaseweb",
	"M247",
	"Contabo",
	"Scaleway",
	"Oracle",
	"IONOS",
}

// hostingNamesNormalised is hostingNames in the form normaliseName gives an operator name.
var hostingNamesNormalised = func() []string {
	out := make([]string, 0, len(hostingNames))
	for _, name := range hostingNames {
		out = append(out, normaliseName(name))
	}
	return out
}()

// normaliseName lower-cases a name, turns every run of anything that is not a letter or digit
// into one space, and puts a space at each end, so a keyword matches only as whole words.
func normaliseName(name string) string {
	words := strings.FieldsFunc(strings.ToLower(name), func(r rune) bool {
		return !unicode.IsLetter(r) && !unicode.IsDigit(r)
	})
	return " " + strings.Join(words, " ") + " "
}

// matchesHostingName reports whether a network operator's name contains a hosting keyword.
func matchesHostingName(org string) bool {
	name := normaliseName(org)
	for _, keyword := range hostingNamesNormalised {
		if strings.Contains(name, keyword) {
			return true
		}
	}
	return false
}

// isMicrosoftNetwork reports whether a network is Microsoft's: one of the Azure networks by number,
// or an operator with the word Microsoft in its name. asn is 0 and org is empty when GeoLite2-ASN
// does not know the address, which is not Microsoft.
func isMicrosoftNetwork(asn uint, org string) bool {
	if microsoftASNs[asn] {
		return true
	}
	return strings.Contains(normaliseName(org), microsoftNameNormalised)
}

// isHostingNetwork reports whether a network is one scanners open links from: Microsoft's, or
// another listed by number or by name. asn is 0 and org is empty when GeoLite2-ASN does not know
// the address, which is not hosting.
func isHostingNetwork(asn uint, org string) bool {
	if isMicrosoftNetwork(asn, org) {
		return true
	}
	if _, listed := hostingASNs[asn]; listed {
		return true
	}
	return matchesHostingName(org)
}
