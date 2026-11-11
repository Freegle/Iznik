package job

import (
	"bufio"
	"encoding/json"
	"errors"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/misc"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// The decision table, with the country and network already looked up, so it holds whatever the
// embedded databases say. job_gate_test.go in test/ runs the same rule through real addresses.
func TestDecide_DecisionTable(t *testing.T) {
	const (
		bt         = "British Telecommunications Limited"
		microsoft  = "Microsoft Corporation"
		amazon     = "Amazon.com, Inc."
		leaseUK    = "Leaseweb UK Limited"
		cloudflare = "Cloudflare, Inc."
	)

	tests := []struct {
		name       string
		country    string
		asn        uint
		org        string
		signedIn   bool
		sw         string
		tz         string
		wantOpen   bool
		wantReason string
		wantSeen   string
	}{
		// Nobody on a UK home or mobile network sees a change.
		{"GB home broadband opens", "GB", 2856, bt, false, "", "", true, "", ""},
		{"GB home broadband opens with the switch on", "GB", 2856, bt, false, "on", "", true, "", ""},
		{"GB behind Cloudflare opens", "GB", 13335, cloudflare, false, "", "", true, "", ""},

		// Anyone signed in is opened, whatever the address.
		{"signed in on a Microsoft address opens", "GB", 8075, microsoft, true, "", "", true, "", ReasonHosting},
		{"signed in abroad opens", "US", 7922, "Comcast", true, "", "", true, "", ReasonAbroad},
		{"signed in with the gate forced opens", "GB", 2856, bt, true, "force", "", true, "", ""},

		// Abroad is held by country, whatever the network.
		{"abroad is gated", "US", 7922, "Comcast Cable Communications, LLC", false, "", "", false, ReasonAbroad, ReasonAbroad},
		{"abroad with no network is gated", "DE", 0, "", false, "", "", false, ReasonAbroad, ReasonAbroad},
		{"abroad on Amazon is gated", "US", 16509, amazon, false, "", "", false, ReasonAbroad, ReasonAbroad},
		{"abroad wins over Microsoft", "US", 8075, microsoft, false, "", "", false, ReasonAbroad, ReasonAbroad},

		// On a UK address only Microsoft is held.
		{"Microsoft by number is gated", "GB", 8075, "", false, "", "", false, ReasonHosting, ReasonHosting},
		{"Microsoft by name is gated", "GB", 0, "Microsoft Corp", false, "", "", false, ReasonHosting, ReasonHosting},
		{"Azure's other networks are gated", "GB", 8069, microsoft, false, "", "", false, ReasonHosting, ReasonHosting},
		{"no country but a Microsoft network is gated", "", 8075, microsoft, false, "", "", false, ReasonHosting, ReasonHosting},

		// Any other hosting network on a UK address opens, and the log says what it was.
		{"Amazon London opens", "GB", 16509, amazon, false, "", "", true, "", SeenOtherHosting},
		{"Amazon by number opens", "GB", 14618, "", false, "", "", true, "", SeenOtherHosting},
		{"Google opens", "GB", 15169, "Google LLC", false, "", "", true, "", SeenOtherHosting},
		{"Leaseweb UK by name opens", "GB", 205544, leaseUK, false, "", "", true, "", SeenOtherHosting},
		{"DigitalOcean London opens", "GB", 14061, "DigitalOcean, LLC", false, "", "", true, "", SeenOtherHosting},
		{"M247 opens", "GB", 9009, "M247 Europe SRL", false, "", "", true, "", SeenOtherHosting},
		{"no country but Amazon opens", "", 16509, amazon, false, "", "", true, "", SeenOtherHosting},

		// Every unknown falls open.
		{"no country and no network opens", "", 0, "", false, "", "", true, "", ""},
		{"GB with an unknown network opens", "GB", 0, "", false, "", "", true, "", ""},
		{"GB with an unlisted network opens", "GB", 64496, "Example Hosting Ltd", false, "", "", true, "", ""},

		// The switch.
		{"off opens a Microsoft address", "GB", 8075, microsoft, false, "off", "", true, "", ReasonHosting},
		{"off opens an abroad address", "US", 7922, "Comcast", false, "off", "", true, "", ReasonAbroad},
		{"force gates a UK home address", "GB", 2856, bt, false, "force", "", false, ReasonForced, ""},
		{"shadow reports hosting but opens", "GB", 8075, microsoft, false, "shadow", "", true, ReasonHosting, ReasonHosting},
		{"shadow reports abroad but opens", "US", 7922, "Comcast", false, "shadow", "", true, ReasonAbroad, ReasonAbroad},
		{"shadow says nothing for a UK home address", "GB", 2856, bt, false, "shadow", "", true, "", ""},
		{"shadow says nothing for other hosting on a UK address", "GB", 16509, amazon, false, "shadow", "", true, "", SeenOtherHosting},
		{"shadow says nothing for a signed-in member", "US", 8075, microsoft, true, "shadow", "", true, "", ReasonAbroad},

		// A switch value is read without regard to case or spaces; one nobody defined opens.
		{"upper case on", "GB", 8075, "", false, "ON", "", false, ReasonHosting, ReasonHosting},
		{"padded off", "GB", 8075, "", false, "  off ", "", true, "", ReasonHosting},
		{"mixed case force", "GB", 2856, bt, false, "Force", "", false, ReasonForced, ""},
		{"upper case shadow", "GB", 8075, "", false, "SHADOW", "", true, ReasonHosting, ReasonHosting},
		{"an unknown value opens", "GB", 8075, "", false, "banana", "", true, "", ReasonHosting},
		{"an unknown value opens abroad too", "US", 0, "", false, "of", "", true, "", ReasonAbroad},

		// A browser whose clock is in the UK is never held, abroad or on Microsoft's network.
		{"a London clock opens an address abroad", "US", 7922, "Comcast", false, "", "Europe/London", true, "", ReasonAbroad},
		{"a London clock opens a Microsoft address", "GB", 8075, microsoft, false, "", "Europe/London", true, "", ReasonHosting},
		{"a Belfast clock opens", "DE", 0, "", false, "", "Europe/Belfast", true, "", ReasonAbroad},
		{"a Jersey clock opens", "JE", 0, "", false, "", "Europe/Jersey", true, "", ReasonAbroad},
		{"a Guernsey clock opens", "GG", 0, "", false, "", "Europe/Guernsey", true, "", ReasonAbroad},
		{"an Isle of Man clock opens", "IM", 0, "", false, "", "Europe/Isle_of_Man", true, "", ReasonAbroad},
		{"a padded London clock opens", "US", 7922, "Comcast", false, "", "  Europe/London ", true, "", ReasonAbroad},
		{"a clock in another zone does not", "US", 7922, "Comcast", false, "", "America/Los_Angeles", false, ReasonAbroad, ReasonAbroad},
		{"a UTC clock does not", "GB", 8075, microsoft, false, "", "UTC", false, ReasonHosting, ReasonHosting},
		{"a Dublin clock does not", "IE", 0, "", false, "", "Europe/Dublin", false, ReasonAbroad, ReasonAbroad},
		{"a made-up clock does not", "GB", 8075, microsoft, false, "", "Europe/Atlantis", false, ReasonHosting, ReasonHosting},
		{"a lower case zone is not a zone", "GB", 8075, microsoft, false, "", "europe/london", false, ReasonHosting, ReasonHosting},
		{"no clock does not", "GB", 8075, microsoft, false, "", "", false, ReasonHosting, ReasonHosting},
		{"force gates a London clock too", "GB", 2856, bt, false, "force", "Europe/London", false, ReasonForced, ""},
		{"force still opens a signed-in London clock", "GB", 2856, bt, true, "force", "Europe/London", true, "", ""},
		{"shadow says nothing for a London clock", "US", 7922, "Comcast", false, "shadow", "Europe/London", true, "", ReasonAbroad},
		{"shadow still reports a clock in another zone", "US", 7922, "Comcast", false, "shadow", "Asia/Kolkata", true, ReasonAbroad, ReasonAbroad},
		{"off opens whatever the clock says", "US", 7922, "Comcast", false, "off", "America/Los_Angeles", true, "", ReasonAbroad},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			d := decide(tt.country, tt.asn, tt.org, tt.signedIn, tt.sw, tt.tz)
			assert.Equal(t, tt.wantOpen, d.Open, "open")
			assert.Equal(t, tt.wantReason, d.Reason, "reason")
			assert.Equal(t, tt.wantSeen, d.Seen, "what the address alone says")
		})
	}
}

// The decision carries what it was worked out from, for the log.
func TestDecide_RecordsWhatItWasWorkedOutFrom(t *testing.T) {
	d := decide("GB", 8075, "Microsoft Corporation", false, " Shadow ", "  Europe/London ")
	assert.Equal(t, Decision{
		Open:     true,
		Reason:   "",
		Seen:     ReasonHosting,
		Mode:     "shadow",
		SignedIn: false,
		UKClock:  true,
		Country:  "GB",
		ASN:      8075,
		Org:      "Microsoft Corporation",
		TZ:       "Europe/London",
	}, d)

	d = decide("US", 7922, "Comcast", true, "", "")
	assert.Equal(t, "on", d.Mode, "an absent switch is on")
	assert.True(t, d.SignedIn)
	assert.False(t, d.UKClock)

	d = decide("US", 7922, "Comcast", false, "banana", "")
	assert.Equal(t, "off", d.Mode, "a value nobody defined reads as off")
}

func TestDecide_CutsALongClockSoItCannotFloodTheLog(t *testing.T) {
	d := decide("GB", 8075, "Microsoft Corporation", false, "", strings.Repeat("x", 500))
	assert.Len(t, d.TZ, 64)
	assert.False(t, d.UKClock)
	assert.False(t, d.Open)
}

func TestAutoOpen_ReadsTheAddressThroughBothDatabases(t *testing.T) {
	// An Azure UK address is held.
	d := AutoOpen("85.210.241.4", false, "", "")
	assert.False(t, d.Open)
	assert.Equal(t, ReasonHosting, d.Reason)
	assert.Equal(t, "GB", d.Country)
	assert.Equal(t, uint(8075), d.ASN)

	// So is one abroad on any network.
	d = AutoOpen("164.90.228.156", false, "", "")
	assert.False(t, d.Open)
	assert.Equal(t, ReasonAbroad, d.Reason)

	// An Amazon address in London opens, and says what it was.
	d = AutoOpen("35.176.1.1", false, "", "")
	assert.True(t, d.Open)
	assert.Equal(t, "", d.Reason)
	assert.Equal(t, SeenOtherHosting, d.Seen)

	// The first address of a BT block is a UK home.
	d = AutoOpen("5.35.192.1", false, "", "")
	assert.True(t, d.Open)
	assert.Equal(t, "", d.Reason)
	assert.Equal(t, "", d.Seen)

	// The clock opens an Azure address.
	d = AutoOpen("85.210.241.4", false, "", "Europe/London")
	assert.True(t, d.Open)
	assert.True(t, d.UKClock)
	assert.Equal(t, ReasonHosting, d.Seen)
}

// A list is not an address. The gate is given one address (see misc.ClientAddress), so a list reads
// as unknown and opens; it is never split to find a "first" one that a client could have written.
func TestAutoOpen_AListIsNotAnAddress(t *testing.T) {
	d := AutoOpen("85.210.241.4, 10.0.0.1", false, "", "")
	assert.True(t, d.Open)
	assert.Equal(t, "", d.Seen)
	assert.Equal(t, "", d.Country)
	assert.Equal(t, uint(0), d.ASN)
}

// stubGateSwitch replaces the database read with fn for the test, and forgets what was cached
// before and after.
func stubGateSwitch(t *testing.T, fn func() (string, error)) {
	t.Helper()
	forget := func() {
		gateMu.Lock()
		gateValue = ""
		gateLoadedAt = time.Time{}
		gateMu.Unlock()
	}

	orig := gateLoad
	gateLoad = fn
	forget()
	t.Cleanup(func() {
		gateLoad = orig
		forget()
	})
}

func TestGateSwitch_IsReadOnceAMinute(t *testing.T) {
	reads := 0
	stubGateSwitch(t, func() (string, error) {
		reads++
		return "force", nil
	})

	for i := 0; i < 5; i++ {
		assert.Equal(t, "force", gateSwitch())
	}
	assert.Equal(t, 1, reads, "five requests inside a minute read the row once")

	// A minute and a bit later the next request reads it again.
	gateMu.Lock()
	gateLoadedAt = time.Now().Add(-gateTTL - time.Second)
	gateMu.Unlock()

	assert.Equal(t, "force", gateSwitch())
	assert.Equal(t, 2, reads)
}

func TestGateSwitch_ResetReadsAgainAtOnce(t *testing.T) {
	reads := 0
	value := "off"
	stubGateSwitch(t, func() (string, error) {
		reads++
		return value, nil
	})

	assert.Equal(t, "off", gateSwitch())
	value = "force"
	assert.Equal(t, "off", gateSwitch(), "the cached value is served until it expires")

	ResetGateSwitchCache()
	assert.Equal(t, "force", gateSwitch())
	assert.Equal(t, 2, reads)
}

func TestGateSwitch_AbsentRowMeansTheRuleIsOn(t *testing.T) {
	stubGateSwitch(t, func() (string, error) { return "", nil })

	assert.Equal(t, "", gateSwitch())
	d := decide("GB", 8075, "Microsoft Corporation", false, gateSwitch(), "")
	assert.False(t, d.Open)
	assert.Equal(t, ReasonHosting, d.Reason)
}

func TestGateSwitch_ADatabaseErrorKeepsTheLastValue(t *testing.T) {
	// If the row cannot be read, an operator who has switched the gate off keeps it off.
	fail := false
	stubGateSwitch(t, func() (string, error) {
		if fail {
			return "", errors.New("database unavailable")
		}
		return "off", nil
	})

	assert.Equal(t, "off", gateSwitch())

	fail = true
	ResetGateSwitchCache()
	assert.Equal(t, "off", gateSwitch())

	// And it does not try again on every request while the database is down.
	reads := 0
	gateLoad = func() (string, error) {
		reads++
		return "", errors.New("database unavailable")
	}
	assert.Equal(t, "off", gateSwitch())
	assert.Equal(t, "off", gateSwitch())
	assert.Equal(t, 0, reads, "a failed read is not retried inside the minute")
}

func TestGateSwitch_ADatabaseErrorWithNothingCachedMeansTheRuleIsOn(t *testing.T) {
	stubGateSwitch(t, func() (string, error) { return "", errors.New("database unavailable") })

	assert.Equal(t, "", gateSwitch())
}

// useGateLoki sends the decision log to a directory the test can read back, and returns it.
func useGateLoki(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	l := misc.NewFileLoki(dir)
	lokiOverride = l
	t.Cleanup(func() {
		lokiOverride = nil
		l.Close()
	})
	return dir
}

// gateLine is one job_gate line read back from the log directory.
type gateLine struct {
	Labels  map[string]string
	Message map[string]interface{}
}

// gateLines reads back the job_gate lines written under dir.
func gateLines(t *testing.T, dir string) []gateLine {
	t.Helper()

	var out []gateLine
	files, _ := filepath.Glob(filepath.Join(dir, "*.log"))
	for _, f := range files {
		fh, err := os.Open(f)
		if err != nil {
			t.Fatalf("cannot open %s: %v", f, err)
		}
		sc := bufio.NewScanner(fh)
		sc.Buffer(make([]byte, 1024*1024), 1024*1024)
		for sc.Scan() {
			var entry struct {
				Labels  map[string]string      `json:"labels"`
				Message map[string]interface{} `json:"message"`
			}
			if json.Unmarshal(sc.Bytes(), &entry) == nil && entry.Labels["source"] == "job_gate" {
				out = append(out, gateLine{entry.Labels, entry.Message})
			}
		}
		fh.Close()
	}
	return out
}

// gateApp is a route that runs the gate the way GetJob does, without the database.
func gateApp() *fiber.App {
	app := fiber.New(fiber.Config{ProxyHeader: "X-Forwarded-For"})
	app.Get("/gate", func(c *fiber.Ctx) error {
		return c.JSON(gate(c))
	})
	return app
}

func askGate(t *testing.T, app *fiber.App, target string, headers map[string]string) Decision {
	t.Helper()
	req := httptest.NewRequest("GET", target, nil)
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	var d Decision
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&d))
	return d
}

// The decision log is what lets the rule be read back: what the address looked like, what the
// clock said and what the page was told, including the hosting networks that are not held.
func TestGate_WritesTheDecisionToTheLog(t *testing.T) {
	stubGateSwitch(t, func() (string, error) { return "", nil })
	dir := useGateLoki(t)
	app := gateApp()

	// A Microsoft address with a clock in another zone is held.
	d := askGate(t, app, "/gate?tz=America/Los_Angeles", map[string]string{"X-Real-IP": "85.210.241.4"})
	assert.False(t, d.Open)
	assert.Equal(t, ReasonHosting, d.Reason)

	// An Amazon London address with a London clock opens, and is logged as the hosting network it is.
	d = askGate(t, app, "/gate?tz=Europe/London", map[string]string{"X-Real-IP": "35.176.1.1"})
	assert.True(t, d.Open)

	lines := gateLines(t, dir)
	if assert.Len(t, lines, 2) {
		held := lines[0].Message
		assert.Equal(t, false, held["open"])
		assert.Equal(t, ReasonHosting, held["reason"])
		assert.Equal(t, ReasonHosting, held["seen"])
		assert.Equal(t, "on", held["mode"])
		assert.Equal(t, false, held["signed_in"])
		assert.Equal(t, false, held["uk_clock"])
		assert.Equal(t, "GB", held["country"])
		assert.EqualValues(t, 8075, held["asn"])
		assert.Equal(t, "Microsoft Corporation", held["org"])
		assert.Equal(t, "America/Los_Angeles", held["tz"])
		assert.NotEmpty(t, held["timestamp"])
		assert.NotContains(t, held, "ip", "the address is in the request log already, and is not copied here")

		noted := lines[1].Message
		assert.Equal(t, true, noted["open"])
		assert.Equal(t, "", noted["reason"])
		assert.Equal(t, SeenOtherHosting, noted["seen"])
		assert.Equal(t, true, noted["uk_clock"])
		assert.Equal(t, "Europe/London", noted["tz"])
		assert.EqualValues(t, 16509, noted["asn"])
	}
}

func TestGate_TheAddressIsXRealIPAndNeverTheForwardedFor(t *testing.T) {
	stubGateSwitch(t, func() (string, error) { return "", nil })
	useGateLoki(t)
	app := gateApp()

	// The client wrote X-Forwarded-For to look like BT; haproxy set X-Real-IP to the real peer.
	d := askGate(t, app, "/gate", map[string]string{"X-Forwarded-For": "5.35.192.1", "X-Real-IP": "85.210.241.4"})
	assert.False(t, d.Open, "the peer is on Azure whatever the client wrote")
	assert.Equal(t, ReasonHosting, d.Reason)

	// The other way round: X-Forwarded-For alone is not an address.
	d = askGate(t, app, "/gate", map[string]string{"X-Forwarded-For": "85.210.241.4"})
	assert.True(t, d.Open)
	assert.Equal(t, "", d.Country)
}

func TestGate_ShadowAndTheSwitchReachTheLog(t *testing.T) {
	value := "shadow"
	stubGateSwitch(t, func() (string, error) { return value, nil })
	dir := useGateLoki(t)
	app := gateApp()

	d := askGate(t, app, "/gate", map[string]string{"X-Real-IP": "164.90.228.156"})
	assert.True(t, d.Open, "shadow opens")
	assert.Equal(t, ReasonAbroad, d.Reason, "and says why it would not")

	lines := gateLines(t, dir)
	if assert.Len(t, lines, 1) {
		assert.Equal(t, "shadow", lines[0].Message["mode"])
		assert.Equal(t, ReasonAbroad, lines[0].Message["reason"])
		assert.Equal(t, true, lines[0].Message["open"])
	}
}

func TestDecision_LogIsQuietWhenLokiIsOff(t *testing.T) {
	off := &misc.LokiClient{}
	assert.False(t, off.IsEnabled())
	lokiOverride = off
	t.Cleanup(func() { lokiOverride = nil })

	assert.NotPanics(t, func() {
		decide("GB", 8075, "Microsoft Corporation", false, "", "").log()
	})
}
