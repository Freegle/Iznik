package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/job"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// Addresses used below. The cloud ones come from the email-page pulls of 9 to 15 Oct 2026 or are
// the first address of a cloud block; the UK home ones are the first address of a BT and a Sky
// block, so none is a member's address.
const (
	azureUK       = "85.210.241.4"     // Microsoft, GB: held
	azureNL       = "20.50.1.1"        // Microsoft, NL: held by country
	awsLondon     = "35.176.1.1"       // Amazon, GB: a hosting network, not held on a UK address
	googleLondon  = "34.89.1.1"        // Google LLC (AS396982), GB: ditto, by name
	oracleLondon  = "132.145.1.1"      // Oracle, GB: ditto, by name
	leasewebUK    = "23.19.56.1"       // Leaseweb UK Limited, GB: ditto, by name
	doLondon      = "46.101.0.1"       // DigitalOcean London, GB: ditto, by name
	digitalOceanD = "164.90.228.156"   // DigitalOcean, DE: abroad
	awsUS         = "54.70.1.1"        // Amazon, US: abroad
	googleDNS     = "8.8.8.8"          // Google, US: abroad
	btHome        = "5.35.192.1"       // BT, GB: UK home broadband
	skyHome       = "2.120.0.1"        // Sky, GB: UK home broadband
	btHomeV6      = "2a00:23c0::1"     // BT, GB, IPv6
	azureUKV6     = "2a01:111:2002::1" // Microsoft, GB, IPv6: held
	privateV4     = "192.168.1.1"
	loopbackV6    = "::1"
)

// setGateSwitch writes the jobs_email_gate config row (an empty value removes it), forgets the
// cached copy, and removes the row again when the test ends.
func setGateSwitch(t *testing.T, value string) {
	t.Helper()
	db := database.DBConn
	if value == "" {
		db.Exec("DELETE FROM config WHERE `key` = ?", job.GateSwitchKey)
	} else {
		db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?", job.GateSwitchKey, value, value)
	}
	job.ResetGateSwitchCache()
	t.Cleanup(func() {
		db.Exec("DELETE FROM config WHERE `key` = ?", job.GateSwitchKey)
		job.ResetGateSwitchCache()
	})
}

// gateApp is the job route behind the same auth middleware and the same proxy setting as main.go.
// main.go trusts X-Forwarded-For without checking it, so c.IP() here returns whatever the client
// wrote; the gate must still read the caller's address from X-Real-IP, which haproxy sets, as
// these tests do. The shared test app has no proxy header and no X-Real-IP, so every request
// there comes from 0.0.0.0.
func gateApp() *fiber.App {
	a := fiber.New(fiber.Config{ProxyHeader: "X-Forwarded-For"})
	a.Use(user.NewAuthMiddleware(user.Config{}))
	a.Get("/apiv2/job/:id", job.GetJob)
	return a
}

type gateJob struct {
	ID             uint64 `json:"id"`
	Title          string `json:"title"`
	AutoOpen       *bool  `json:"autoopen"`
	AutoOpenReason string `json:"autoopen_reason"`
}

// gateReq describes one browser asking for a job.
type gateReq struct {
	ip         string   // the caller's address, sent as X-Real-IP the way haproxy sets it
	forwarded  []string // X-Forwarded-For lines the caller wrote itself
	token      string   // Authorization: a JWT
	persistent string   // Authorization2: the persistent token
	tz         string   // the tz query: the time zone the browser reported
	rawQuery   string   // anything else on the query string
}

type gateResp struct {
	status int
	job    gateJob
	body   string
	cache  string
}

// getJob asks for a job as the browser described, and returns the status, the decoded job, the raw
// body and the Cache-Control header.
func getJob(t *testing.T, app *fiber.App, jobID uint64, r gateReq) gateResp {
	t.Helper()
	target := fmt.Sprintf("/apiv2/job/%d", jobID)
	query := r.rawQuery
	if r.tz != "" {
		if query != "" {
			query += "&"
		}
		query += "tz=" + url.QueryEscape(r.tz)
	}
	if query != "" {
		target += "?" + query
	}

	req := httptest.NewRequest("GET", target, nil)
	if r.ip != "" {
		req.Header.Set("X-Real-IP", r.ip)
	}
	for _, f := range r.forwarded {
		req.Header.Add("X-Forwarded-For", f)
	}
	if r.token != "" {
		req.Header.Set("Authorization", r.token)
	}
	if r.persistent != "" {
		req.Header.Set("Authorization2", r.persistent)
	}

	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatalf("request failed: %v", err)
	}
	body := string(rsp(resp))
	var j gateJob
	json.Unmarshal([]byte(body), &j)
	return gateResp{resp.StatusCode, j, body, resp.Header.Get("Cache-Control")}
}

// wantDecision checks the status, autoopen and the reason of a response.
func wantDecision(t *testing.T, r gateResp, wantOpen bool, wantReason string, msg string) {
	t.Helper()
	assert.Equal(t, 200, r.status, "%s: %s", msg, r.body)
	if assert.NotNil(t, r.job.AutoOpen, "%s: %s", msg, r.body) {
		assert.Equal(t, wantOpen, *r.job.AutoOpen, "%s: autoopen", msg)
	}
	assert.Equal(t, wantReason, r.job.AutoOpenReason, "%s: autoopen_reason", msg)
}

// The decision, address by address, through the embedded Country and ASN databases.
func TestJobAutoOpen_DecisionTable(t *testing.T) {
	tests := []struct {
		name       string
		ip         string
		signedIn   bool
		sw         string
		tz         string
		wantOpen   bool
		wantReason string
	}{
		{"BT home broadband opens", btHome, false, "", "", true, ""},
		{"Sky home broadband opens", skyHome, false, "", "", true, ""},
		{"BT home broadband over IPv6 opens", btHomeV6, false, "", "", true, ""},

		{"abroad: DigitalOcean in Germany", digitalOceanD, false, "", "", false, job.ReasonAbroad},
		{"abroad: Amazon in the US", awsUS, false, "", "", false, job.ReasonAbroad},
		{"abroad: Google in the US", googleDNS, false, "", "", false, job.ReasonAbroad},
		{"abroad: Azure in the Netherlands", azureNL, false, "", "", false, job.ReasonAbroad},

		{"Microsoft: Azure in the UK", azureUK, false, "", "", false, job.ReasonHosting},
		{"Microsoft: Azure in the UK over IPv6", azureUKV6, false, "", "", false, job.ReasonHosting},

		// Any other hosting network on a UK address opens.
		{"Amazon London opens", awsLondon, false, "", "", true, ""},
		{"Google London opens", googleLondon, false, "", "", true, ""},
		{"Oracle London opens", oracleLondon, false, "", "", true, ""},
		{"Leaseweb UK opens", leasewebUK, false, "", "", true, ""},
		{"DigitalOcean London opens", doLondon, false, "", "", true, ""},

		{"signed in on an Azure address opens", azureUK, true, "", "", true, ""},
		{"signed in abroad opens", digitalOceanD, true, "", "", true, ""},

		// Every unknown falls open.
		{"no address opens", "", false, "", "", true, ""},
		{"an unparseable address opens", "not-an-address", false, "", "", true, ""},
		{"a private IPv4 address opens", privateV4, false, "", "", true, ""},
		{"the IPv6 loopback address opens", loopbackV6, false, "", "", true, ""},

		// A list is not an address, so it is unknown and opens. The gate is given one address.
		{"a forwarded list opens", azureUK + ", 10.0.0.1", false, "", "", true, ""},

		// The switch.
		{"off opens an Azure address", azureUK, false, "off", "", true, ""},
		{"off opens an abroad address", digitalOceanD, false, "off", "", true, ""},
		{"force gates a BT home address", btHome, false, "force", "", false, job.ReasonForced},
		{"force still opens for someone signed in", btHome, true, "force", "", true, ""},
		{"shadow opens an Azure address and says why", azureUK, false, "shadow", "", true, job.ReasonHosting},
		{"shadow opens an abroad address and says why", digitalOceanD, false, "shadow", "", true, job.ReasonAbroad},
		{"shadow says nothing for a BT home address", btHome, false, "shadow", "", true, ""},
		{"shadow says nothing for Amazon London", awsLondon, false, "shadow", "", true, ""},
		{"an unknown switch value opens", azureUK, false, "banana", "", true, ""},

		// A browser whose clock is in the UK is never held.
		{"a London clock opens an Azure address", azureUK, false, "", "Europe/London", true, ""},
		{"a Belfast clock opens an Azure address", azureUK, false, "", "Europe/Belfast", true, ""},
		{"a London clock opens an address abroad", digitalOceanD, false, "", "Europe/London", true, ""},
		{"a Los Angeles clock does not", azureUK, false, "", "America/Los_Angeles", false, job.ReasonHosting},
		{"a UTC clock does not", digitalOceanD, false, "", "UTC", false, job.ReasonAbroad},
		{"force gates a London clock", btHome, false, "force", "Europe/London", false, job.ReasonForced},
		{"shadow says nothing for a London clock", azureUK, false, "shadow", "Europe/London", true, ""},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			d := job.AutoOpen(tt.ip, tt.signedIn, tt.sw, tt.tz)
			assert.Equal(t, tt.wantOpen, d.Open)
			assert.Equal(t, tt.wantReason, d.Reason)
		})
	}
}

// The address databases the decision reads, for the networks it names.
func TestJobAutoOpen_NetworksBehindTheAddresses(t *testing.T) {
	for _, tt := range []struct {
		ip      string
		country string
		asn     uint
	}{
		{azureUK, "GB", 8075},
		{azureNL, "NL", 8075},
		{awsLondon, "GB", 16509},
		{googleLondon, "GB", 396982},
		{oracleLondon, "GB", 31898},
		{leasewebUK, "GB", 205544},
		{doLondon, "GB", 14061},
		{digitalOceanD, "DE", 14061},
		{awsUS, "US", 16509},
		{btHome, "GB", 2856},
		{skyHome, "GB", 5607},
		{btHomeV6, "GB", 2856},
		{azureUKV6, "GB", 8075},
	} {
		asn, _ := utils.ASNForIP(tt.ip)
		assert.Equal(t, tt.asn, asn, "network of %s", tt.ip)
		assert.Equal(t, tt.country, utils.CountryCodeForIP(tt.ip), "country of %s", tt.ip)
	}
}

func TestJobGate_GetJobCarriesTheDecision(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "")

	// A visitor on Microsoft's network is told not to open the advert at once.
	r := getJob(t, app, jobID, gateReq{ip: azureUK})
	assert.Equal(t, jobID, r.job.ID)
	wantDecision(t, r, false, job.ReasonHosting, "Azure")

	// Abroad gives its own reason.
	r = getJob(t, app, jobID, gateReq{ip: digitalOceanD})
	wantDecision(t, r, false, job.ReasonAbroad, "abroad")

	// A UK home visitor is told to open it, with no reason. The true value is sent, not left out:
	// autoopen is a pointer, and only a missing one means the page opens the advert itself.
	r = getJob(t, app, jobID, gateReq{ip: btHome})
	wantDecision(t, r, true, "", "BT home")
	assert.NotContains(t, r.body, "autoopen_reason", "an empty reason is left out")

	// Nothing else in the response changed.
	var raw map[string]interface{}
	assert.NoError(t, json.Unmarshal([]byte(r.body), &raw))
	for _, k := range []string{"id", "url", "title", "location", "body", "job_reference", "category", "cpc", "clickability", "expectation", "ambit", "dist", "area"} {
		assert.Contains(t, raw, k)
	}
	assert.Equal(t, "http://example.com/job", raw["url"])
}

func TestJobGate_GetJobIsNeverSharedBetweenCallers(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "")

	assert.Equal(t, "private, no-store", getJob(t, app, jobID, gateReq{ip: azureUK}).cache)
	assert.Equal(t, "private, no-store", getJob(t, app, jobID, gateReq{ip: btHome}).cache)

	// The route as the real router registers it says the same.
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/apiv2/job/%d", jobID), nil))
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, "private, no-store", resp.Header.Get("Cache-Control"))
	var j gateJob
	json.Unmarshal(rsp(resp), &j)
	if assert.NotNil(t, j.AutoOpen, "the real route carries the field too") {
		assert.True(t, *j.AutoOpen, "the shared test app has no X-Real-IP, so the caller is 0.0.0.0: unknown opens")
	}
}

func TestJobGate_SignedInOpensEvenOnAHostingAddress(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "force")

	prefix := uniquePrefix("jobgate")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, token: token}), true, "", "signed in, even with the gate forced")

	// The same address with nobody signed in is forced.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK}), false, job.ReasonForced, "nobody signed in")
}

// The persistent token (Authorization2) is the old-style login that some members have instead of a
// JWT. It counts as signed in when its session is still there.
func TestJobGate_APersistentTokenCountsAsSignedIn(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "")

	prefix := uniquePrefix("jobgatepersist")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	sessionID, _ := CreateTestSession(t, userID)
	persistent := CreatePersistentToken(t, userID, sessionID)

	// With no JWT at all, on an address that is held when nobody is signed in.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK}), false, job.ReasonHosting, "nobody signed in")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, persistent: persistent}), true, "", "persistent token only")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: digitalOceanD, persistent: persistent}), true, "", "persistent token only, abroad")

	// A token for a session that has gone does not count, and does not turn the page into an error.
	database.DBConn.Exec("DELETE FROM sessions WHERE id = ?", sessionID)
	r := getJob(t, app, jobID, gateReq{ip: azureUK, persistent: persistent})
	assert.Equal(t, jobID, r.job.ID, "the job still loads: %s", r.body)
	wantDecision(t, r, false, job.ReasonHosting, "persistent token whose session has gone")
}

// The auth middleware answers 401 when a handler that asked who is signed in was given a token
// whose session is gone. A job link must still work for a member in that state: the advert opens,
// not "that job is no longer available". A JWT whose session has gone still names its member, so
// it reads as signed in; a persistent token is checked against the session, so it does not.
func TestJobGate_AStaleSessionStillGetsTheJob(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "")

	prefix := uniquePrefix("jobgatestale")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	sessionID, token := CreateTestSession(t, userID)
	database.DBConn.Exec("DELETE FROM sessions WHERE id = ?", sessionID)

	r := getJob(t, app, jobID, gateReq{ip: azureUK, token: token})
	assert.Equal(t, jobID, r.job.ID, r.body)
	wantDecision(t, r, true, "", "a JWT the server cannot place is read as signed in, which opens")
}

func TestJobGate_MissingAndHiddenJobsAreStillNotFound(t *testing.T) {
	app := gateApp()
	setGateSwitch(t, "force")

	r := getJob(t, app, 0, gateReq{ip: azureUK})
	assert.Equal(t, 404, r.status)
	assert.Nil(t, r.job.AutoOpen)
	assert.NotContains(t, r.body, "autoopen")

	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	database.DBConn.Exec("UPDATE jobs SET visible = 0 WHERE id = ?", jobID)
	r = getJob(t, app, jobID, gateReq{ip: azureUK})
	assert.Equal(t, 404, r.status)
	assert.Nil(t, r.job.AutoOpen)
	assert.NotContains(t, r.body, "autoopen")
}

func TestJobGate_ListResponsesAreUnchanged(t *testing.T) {
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)

	jobs := job.JobsForIDs([]int64{int64(jobID)}, map[int64]float64{int64(jobID): 0.01}, 52.5833189, -2.0455619, "")
	if assert.NotEmpty(t, jobs) {
		out, err := json.Marshal(jobs)
		assert.NoError(t, err)
		assert.NotContains(t, string(out), "autoopen")
	}
}

func TestJobGate_SwitchValuesFromTheConfigTable(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)

	check := func(value string, ip string, wantOpen bool, wantReason string) {
		t.Helper()
		setGateSwitch(t, value)
		wantDecision(t, getJob(t, app, jobID, gateReq{ip: ip}), wantOpen, wantReason, fmt.Sprintf("switch=%q ip=%s", value, ip))
	}

	// No row, and "on": the rule.
	check("", azureUK, false, job.ReasonHosting)
	check("on", azureUK, false, job.ReasonHosting)
	check("on", btHome, true, "")

	// Off is the kill switch.
	check("off", azureUK, true, "")
	check("off", digitalOceanD, true, "")

	// Shadow works the decision out and reports it, but opens.
	check("shadow", azureUK, true, job.ReasonHosting)
	check("shadow", digitalOceanD, true, job.ReasonAbroad)
	check("shadow", btHome, true, "")

	// Force gates everyone not signed in.
	check("force", btHome, false, job.ReasonForced)
	check("force", azureUK, false, job.ReasonForced)
}

func TestJobGate_SwitchIsCachedUntilItExpires(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)

	setGateSwitch(t, "off")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK}), true, "", "off")

	// Change the row behind the cache's back: the next request still sees the old value.
	database.DBConn.Exec("UPDATE config SET value = 'force' WHERE `key` = ?", job.GateSwitchKey)
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK}), true, "", "a value read inside the minute is served from the cache")

	// Once the cache is forgotten, the row is read again.
	job.ResetGateSwitchCache()
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK}), false, job.ReasonForced, "the row is read again")
}

// On a UK address only Microsoft's network is held. Every other hosting network opens, and the
// decision log says what it was.
func TestJobGate_OnlyMicrosoftIsHeldOnAUKAddress(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "")

	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK}), false, job.ReasonHosting, "Microsoft Azure")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUKV6}), false, job.ReasonHosting, "Microsoft Azure over IPv6")

	for name, ip := range map[string]string{
		"Amazon London":   awsLondon,
		"Google London":   googleLondon,
		"Oracle London":   oracleLondon,
		"Leaseweb UK":     leasewebUK,
		"DigitalOcean UK": doLondon,
	} {
		r := getJob(t, app, jobID, gateReq{ip: ip})
		wantDecision(t, r, true, "", name)
		assert.NotContains(t, r.body, "autoopen_reason", name)
	}

	// The same networks are still held when the address is abroad: the country test is first.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: awsUS}), false, job.ReasonAbroad, "Amazon in the US")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: digitalOceanD}), false, job.ReasonAbroad, "DigitalOcean in Germany")
}

// A browser whose clock is in the UK is never held. The page sends its time zone in the tz query.
func TestJobGate_ATimeZoneInTheUKOpens(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "")

	// Held with no zone, or with a zone outside the UK.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK}), false, job.ReasonHosting, "no zone")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, tz: "America/Los_Angeles"}), false, job.ReasonHosting, "Los Angeles")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, tz: "UTC"}), false, job.ReasonHosting, "UTC")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: digitalOceanD, tz: "Asia/Kolkata"}), false, job.ReasonAbroad, "Kolkata")

	// Open with a zone in the UK, on Microsoft's network or abroad.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, tz: "Europe/London"}), true, "", "London on Azure")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, tz: "Europe/Belfast"}), true, "", "Belfast on Azure")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: digitalOceanD, tz: "Europe/London"}), true, "", "London, address abroad")

	// Only the tz query counts: another name for it, or a header, is not a clock.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, rawQuery: "timezone=Europe%2FLondon"}), false, job.ReasonHosting, "wrong query name")

	// Shadow says nothing for a UK clock, and still says why for any other.
	setGateSwitch(t, "shadow")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, tz: "Europe/London"}), true, "", "shadow, London")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, tz: "Asia/Kolkata"}), true, job.ReasonHosting, "shadow, Kolkata")

	// Force gates everyone not signed in, a UK clock too, so a screenshot works from a UK browser.
	setGateSwitch(t, "force")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: btHome, tz: "Europe/London"}), false, job.ReasonForced, "force, London")
}

// A client can write X-Forwarded-For before it reaches haproxy, and main.go trusts that header
// without checking it, so the gate reads the caller's address from X-Real-IP, which haproxy sets
// itself. Nothing a client writes in X-Forwarded-For moves it.
func TestJobGate_AForgedForwardedForIsIgnored(t *testing.T) {
	app := gateApp()
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	setGateSwitch(t, "")

	// The peer is on Azure; the client wrote a BT address in front.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, forwarded: []string{btHome}}), false, job.ReasonHosting, "BT written in front")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, forwarded: []string{btHome + ", " + azureUK}}), false, job.ReasonHosting, "BT first in a list")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, forwarded: []string{btHome, azureUK}}), false, job.ReasonHosting, "two lines, the first written by the client")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: azureUK, forwarded: []string{"10.1.2.3, " + azureUK}}), false, job.ReasonHosting, "a private address in front")
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: digitalOceanD, forwarded: []string{"unknown"}}), false, job.ReasonAbroad, "junk in front")

	// The peer is at home; the client wrote an Azure address.
	wantDecision(t, getJob(t, app, jobID, gateReq{ip: btHome, forwarded: []string{azureUK}}), true, "", "Azure written in front")

	// X-Forwarded-For alone is not an address.
	wantDecision(t, getJob(t, app, jobID, gateReq{forwarded: []string{azureUK}}), true, "", "no X-Real-IP")
}
