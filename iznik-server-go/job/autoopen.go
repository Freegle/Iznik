package job

import (
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// The email job page (pages/job/[id].vue) sends the browser to the advert at once. Mail security
// scanners open email links in real browsers, and the partner discards the clicks that come from
// cloud networks, so GET /job/:id says, per caller, whether the page may do that or must show a
// button first. People on a UK home or mobile network, anyone signed in, and anyone whose browser
// clock is in the UK get the advert at once.
//
// open = signedIn OR ukClock OR NOT (abroad OR hosting), and every unknown falls open:
//
//   - signed in on that browser: open.
//   - a browser whose time zone, sent by the page as the tz query, is in the UK: open. The server
//     cannot see a clock, so the page reports it. Scanners run with other zones (Microsoft's fleet
//     reports America/Los_Angeles, Amazon's UTC, and Google's front ends send none), and a person
//     abroad or on a VPN usually has a UK one. A scanner could copy the zone, so the decision log
//     records it.
//   - abroad: the country from GeoLite2-Country is anything but "" or GB, on any network.
//   - hosting: the address is in the UK, or has no country, and the network from GeoLite2-ASN is
//     Microsoft's. Any other hosting network on a UK address is not held; the decision log records
//     it as SeenOtherHosting (see hosting.go for why). An address the database does not know is
//     not a reason.

// Why the page is asked to show a button instead of opening the advert.
const (
	ReasonAbroad  = "abroad"
	ReasonHosting = "hosting"
	ReasonForced  = "forced"
)

// SeenOtherHosting is what the decision log says about a UK address on a hosting network that is
// not Microsoft's: the advert opens, and the visit is recorded.
const SeenOtherHosting = "other_hosting"

// GateSwitchKey is the config row that switches the gate. Absent or "on" is the rule above;
// "off" always opens, so the gate can be stopped with one row and no deploy; "shadow" works the
// decision out and reports it as the reason while still opening, so live traffic can be read
// before the gate bites; "force" gates everyone who is not signed in, a UK clock too, for testing.
// A value nobody defined opens.
const GateSwitchKey = "jobs_email_gate"

const (
	gateOn     = "on"
	gateOff    = "off"
	gateShadow = "shadow"
	gateForce  = "force"
)

// gateMode reads a config value as one of the four modes, ignoring case and spaces.
func gateMode(switchValue string) string {
	switch strings.ToLower(strings.TrimSpace(switchValue)) {
	case "", gateOn:
		return gateOn
	case gateShadow:
		return gateShadow
	case gateForce:
		return gateForce
	default:
		return gateOff
	}
}

// ukZones are the time zones that count as a UK clock. Browsers report a canonical name: Chromium
// says Europe/London for a Belfast clock, and says the Isle of Man, Jersey and Guernsey as
// themselves. Those three have their own country codes in GeoLite2-Country, so an address there
// reads as abroad; their zones are here so the people who live there are not held.
var ukZones = map[string]bool{
	"Europe/London":      true,
	"Europe/Belfast":     true,
	"Europe/Isle_of_Man": true,
	"Europe/Jersey":      true,
	"Europe/Guernsey":    true,
}

// maxZoneLength cuts a time zone before it is compared or logged. The client writes it, and the
// longest real one is under 40 characters.
const maxZoneLength = 64

// Decision is the gate's answer for one request, with what it was worked out from.
type Decision struct {
	// Open is whether the page may open the advert at once.
	Open bool
	// Reason is why the page is asked to show a button: ReasonAbroad, ReasonHosting or
	// ReasonForced. In shadow mode Open is true and Reason is the one that would have applied. It is
	// empty when nothing holds the visitor back, which includes a visitor with a UK clock.
	Reason string
	// Seen is what the address alone says, before the login, the clock and the switch are
	// considered: ReasonAbroad, ReasonHosting, SeenOtherHosting, or empty.
	Seen string
	// Mode is the switch: on, off, shadow or force.
	Mode string
	// SignedIn is whether the caller is signed in.
	SignedIn bool
	// UKClock is whether the browser reported a time zone in the UK.
	UKClock bool
	// Country, ASN and Org are what the address looked up as; empty, 0 and empty when unknown.
	Country string
	ASN     uint
	Org     string
	// TZ is the time zone the browser reported, cut to maxZoneLength.
	TZ string
}

// AutoOpen decides whether the email job page may open the advert at once for a caller. address is
// the caller's one address (see misc.ClientAddress), signedIn is whether the caller is signed in,
// switchValue is the jobs_email_gate config value and tz is the time zone the browser reported.
// An address that is not a single address, such as a list, is unknown and opens.
func AutoOpen(address string, signedIn bool, switchValue, tz string) Decision {
	asn, org := utils.ASNForIP(address)
	return decide(utils.CountryCodeForIP(address), asn, org, signedIn, switchValue, tz)
}

// decide is AutoOpen with the country and network already looked up.
func decide(country string, asn uint, org string, signedIn bool, switchValue, tz string) Decision {
	tz = strings.TrimSpace(tz)
	if len(tz) > maxZoneLength {
		tz = tz[:maxZoneLength]
	}

	d := Decision{
		Mode:     gateMode(switchValue),
		SignedIn: signedIn,
		UKClock:  ukZones[tz],
		Country:  country,
		ASN:      asn,
		Org:      org,
		TZ:       tz,
		Seen:     seenFor(country, asn, org),
	}

	held := d.Seen == ReasonAbroad || d.Seen == ReasonHosting

	switch {
	case d.Mode == gateOff, signedIn:
		d.Open = true
	case d.Mode == gateForce:
		d.Reason = ReasonForced
	case d.UKClock || !held:
		d.Open = true
	default:
		d.Reason = d.Seen
		d.Open = d.Mode == gateShadow
	}

	return d
}

// seenFor says what an address alone looks like: abroad (on any network), a Microsoft network on a
// UK address, another hosting network on a UK address, or nothing unusual.
func seenFor(country string, asn uint, org string) string {
	switch {
	case country != "" && country != "GB":
		return ReasonAbroad
	case isMicrosoftNetwork(asn, org):
		return ReasonHosting
	case isHostingNetwork(asn, org):
		return SeenOtherHosting
	}

	return ""
}

// gate answers for one GET /job/:id and writes the decision log.
func gate(c *fiber.Ctx) Decision {
	// Whoever opens a job link may carry a token whose session has gone. Asking WhoAmI marks the
	// request as one that relied on the login, and the auth middleware then answers 401 for such a
	// token, so the job would not load at all. Nothing here needs the login to be live: a JWT the
	// server cannot place reads as signed in, which opens the advert.
	c.Locals("skipPostAuthCheck", true)

	d := AutoOpen(misc.ClientAddress(c), user.WhoAmI(c) > 0, gateSwitch(), c.Query("tz"))
	d.log()

	return d
}

// lokiOverride, when set, takes the place of the production Loki client. Tests set it.
var lokiOverride *misc.LokiClient

func currentLoki() *misc.LokiClient {
	if lokiOverride != nil {
		return lokiOverride
	}

	return misc.GetLoki()
}

// log writes the decision to Loki as source job_gate, so the rule can be read back: what the
// address looked up as, what the clock said, what the page was told, and the hosting networks that
// were let through. The address itself is in the request log line and is not copied here.
func (d Decision) log() {
	l := currentLoki()
	if l == nil || !l.IsEnabled() {
		return
	}

	l.LogCustom("job_gate", nil, map[string]interface{}{
		"open":      d.Open,
		"reason":    d.Reason,
		"seen":      d.Seen,
		"mode":      d.Mode,
		"signed_in": d.SignedIn,
		"uk_clock":  d.UKClock,
		"country":   d.Country,
		"asn":       d.ASN,
		"org":       d.Org,
		"tz":        d.TZ,
	})
}

// The switch is read from the config table at most once a minute. A failed read keeps the last
// value, so a gate switched off stays off through a database blip; with nothing read yet, the
// rule is on.
const gateTTL = 60 * time.Second

var (
	gateMu       sync.Mutex
	gateValue    string
	gateLoadedAt time.Time
	gateLoad     = readGateSwitch
)

// gateSwitch returns the jobs_email_gate value, "" when the row is absent.
func gateSwitch() string {
	gateMu.Lock()
	defer gateMu.Unlock()

	if !gateLoadedAt.IsZero() && time.Since(gateLoadedAt) < gateTTL {
		return gateValue
	}

	if value, err := gateLoad(); err == nil {
		gateValue = value
	}
	gateLoadedAt = time.Now()

	return gateValue
}

// ResetGateSwitchCache makes the next request read the switch from the config table again.
func ResetGateSwitchCache() {
	gateMu.Lock()
	gateLoadedAt = time.Time{}
	gateMu.Unlock()
}

func readGateSwitch() (string, error) {
	var row struct {
		Value string `gorm:"column:value"`
	}

	result := database.DBConn.Table("config").Select("value").Where("`key` = ?", GateSwitchKey).Limit(1).Scan(&row)

	return row.Value, result.Error
}
