package test

import (
	"net/http"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/hostingad"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The Krystal hosting ad: GET and POST /user/hostingad. See hostingad/hostingad.go.

type hostingAdFixture struct {
	userID uint64
	token  string
	domain string
	siteID uint64
}

// newHostingAdFixture makes a member whose email is on a domain of its own, a domain_hosting row for it
// with the given flags, and the experiment config (arm "" means no config: off).
func newHostingAdFixture(t *testing.T, name string, wordpress, krystal bool, arm string) hostingAdFixture {
	t.Helper()
	prefix := uniquePrefix(name)
	f := hostingAdFixture{domain: prefix + ".example"}
	f.userID = CreateTestUserWithEmail(t, prefix, prefix+"@"+f.domain)
	_, f.token = CreateTestSession(t, f.userID)

	db := database.DBConn
	wp, kr := 0, 0
	if wordpress {
		wp = 1
	}
	if krystal {
		kr = 1
	}
	require.NoError(t, db.Exec("INSERT INTO domain_hosting (domain, wordpress, krystal, checked_at) VALUES (?, ?, ?, NOW())", f.domain, wp, kr).Error)
	db.Raw("SELECT id FROM domain_hosting WHERE domain = ?", f.domain).Scan(&f.siteID)
	require.NotZero(t, f.siteID)

	if arm == "" {
		writeReplyConfirmConfig(t, "", "", false)
	} else {
		writeReplyConfirmConfig(t, `{"krystal-hosting-ad":{"arms":{"`+arm+`":[0,99]},"adopted":true}}`, "1", true)
	}

	t.Cleanup(func() {
		db.Exec("DELETE FROM ux_experiment_exposures WHERE experiment = ? AND userid = ?", hostingad.Experiment, f.userID)
		db.Exec("DELETE FROM domain_hosting WHERE domain = ?", f.domain)
	})

	return f
}

type hostingAdExposure struct {
	Arm       string
	SubjectID uint64
	Detail    *string
}

func hostingAdExposures(t *testing.T, userID uint64) []hostingAdExposure {
	t.Helper()
	var rows []hostingAdExposure
	database.DBConn.Raw("SELECT arm, subject_id, detail FROM ux_experiment_exposures WHERE experiment = ? AND userid = ? AND subject_type = 'hosting_ad' ORDER BY id",
		hostingad.Experiment, userID).Scan(&rows)

	return rows
}

func hostingAdSetting(t *testing.T, userID uint64) *string {
	t.Helper()
	var v *string
	database.DBConn.Raw("SELECT JSON_EXTRACT(settings, '$.hostingad') FROM users WHERE id = ?", userID).Scan(&v)

	return v
}

func hostingAdGet(t *testing.T, token string) (int, map[string]interface{}) {
	t.Helper()
	code, obj, _ := interestedCall(t, "GET", "/api/user/hostingad", token, "", nil)

	return code, obj
}

func hostingAdPost(t *testing.T, token string, body string) (int, map[string]interface{}) {
	t.Helper()
	code, obj, _ := interestedCall(t, "POST", "/api/user/hostingad", token, body, nil)

	return code, obj
}

func TestHostingAdNeedsALogin(t *testing.T) {
	code, _ := hostingAdGet(t, "")
	assert.Equal(t, http.StatusUnauthorized, code)

	code, _ = hostingAdPost(t, "", `{"event":"click"}`)
	assert.Equal(t, http.StatusUnauthorized, code)
}

func TestHostingAdIsShownOnceToTheAdArmAndRecorded(t *testing.T) {
	f := newHostingAdFixture(t, "hostingad_ad", true, false, "ad")

	code, obj := hostingAdGet(t, f.token)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, true, obj["show"])

	rows := hostingAdExposures(t, f.userID)
	require.Len(t, rows, 1)
	assert.Equal(t, "ad", rows[0].Arm)
	assert.Equal(t, f.siteID, rows[0].SubjectID, "the subject is the domain_hosting row")
	assert.Nil(t, rows[0].Detail)
	assert.NotNil(t, hostingAdSetting(t, f.userID), "the decision is kept in the member's settings")

	code, obj = hostingAdGet(t, f.token)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, false, obj["show"], "a member is shown the ad once")
	assert.Len(t, hostingAdExposures(t, f.userID), 1, "and counted once")
}

func TestHostingAdControlIsNotShownButIsCounted(t *testing.T) {
	f := newHostingAdFixture(t, "hostingad_control", true, false, "control")

	code, obj := hostingAdGet(t, f.token)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, false, obj["show"])

	rows := hostingAdExposures(t, f.userID)
	require.Len(t, rows, 1)
	assert.Equal(t, "control", rows[0].Arm)
	assert.NotNil(t, hostingAdSetting(t, f.userID))
}

func TestHostingAdWhenTheExperimentIsOffDecidesNothing(t *testing.T) {
	f := newHostingAdFixture(t, "hostingad_off", true, false, "")

	code, obj := hostingAdGet(t, f.token)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, false, obj["show"])
	assert.Empty(t, hostingAdExposures(t, f.userID))
	assert.Nil(t, hostingAdSetting(t, f.userID), "the member is still eligible when the experiment starts")
}

func TestHostingAdNeedsAWordPressSiteNotOnKrystal(t *testing.T) {
	for _, c := range []struct {
		name      string
		wordpress bool
		krystal   bool
	}{
		{"hostingad_nowp", false, false},
		{"hostingad_onkrystal", true, true},
	} {
		t.Run(c.name, func(t *testing.T) {
			f := newHostingAdFixture(t, c.name, c.wordpress, c.krystal, "ad")

			code, obj := hostingAdGet(t, f.token)
			require.Equal(t, http.StatusOK, code)
			assert.Equal(t, false, obj["show"])
			assert.Empty(t, hostingAdExposures(t, f.userID))
			assert.Nil(t, hostingAdSetting(t, f.userID))
		})
	}
}

func TestHostingAdAMemberWithNoKnownDomainIsNotDecided(t *testing.T) {
	prefix := uniquePrefix("hostingad_unknown")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	writeReplyConfirmConfig(t, `{"krystal-hosting-ad":{"arms":{"ad":[0,99]},"adopted":true}}`, "1", true)

	code, obj := hostingAdGet(t, token)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, false, obj["show"])
	assert.Empty(t, hostingAdExposures(t, userID))
	assert.Nil(t, hostingAdSetting(t, userID))
}

func TestHostingAdADecisionAlreadyMadeIsNotMadeAgain(t *testing.T) {
	f := newHostingAdFixture(t, "hostingad_decided", true, false, "ad")
	database.DBConn.Exec("UPDATE users SET settings = JSON_OBJECT('hostingad', 1700000000) WHERE id = ?", f.userID)

	code, obj := hostingAdGet(t, f.token)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, false, obj["show"])
	assert.Empty(t, hostingAdExposures(t, f.userID))
}

func TestHostingAdSettingsThatAreNotJSONAreReplaced(t *testing.T) {
	f := newHostingAdFixture(t, "hostingad_badjson", true, false, "ad")
	database.DBConn.Exec("UPDATE users SET settings = 'not json' WHERE id = ?", f.userID)

	code, obj := hostingAdGet(t, f.token)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, true, obj["show"])
	assert.NotNil(t, hostingAdSetting(t, f.userID))
	assert.Len(t, hostingAdExposures(t, f.userID), 1)
}

func TestHostingAdEventsAreWrittenOnceEachToTheExposure(t *testing.T) {
	f := newHostingAdFixture(t, "hostingad_events", true, false, "ad")
	_, obj := hostingAdGet(t, f.token)
	require.Equal(t, true, obj["show"])

	code, obj := hostingAdPost(t, f.token, `{"event":"click"}`)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, true, obj["recorded"])

	code, obj = hostingAdPost(t, f.token, `{"event":"click"}`)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, false, obj["recorded"], "the first click is the one kept")

	code, obj = hostingAdPost(t, f.token, `{"event":"dismiss"}`)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, true, obj["recorded"])

	rows := hostingAdExposures(t, f.userID)
	require.Len(t, rows, 1)
	require.NotNil(t, rows[0].Detail)
	assert.Regexp(t, `^\{"clicked": ?\d{10}, ?"dismissed": ?\d{10}\}$`, *rows[0].Detail, "numbers only")

	code, _ = hostingAdPost(t, f.token, `{"event":"other"}`)
	assert.Equal(t, http.StatusBadRequest, code)
	code, _ = hostingAdPost(t, f.token, `not json`)
	assert.Equal(t, http.StatusBadRequest, code)
}

func TestHostingAdAnEventWithNoExposureRecordsNothing(t *testing.T) {
	f := newHostingAdFixture(t, "hostingad_noexposure", true, false, "ad")

	code, obj := hostingAdPost(t, f.token, `{"event":"click"}`)
	require.Equal(t, http.StatusOK, code)
	assert.Equal(t, false, obj["recorded"])
}
