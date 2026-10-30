package addedorigin

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func recordReports(t *testing.T) *[]string {
	var got []string
	t.Cleanup(SetReporter(func(msg string) { got = append(got, msg) }))
	return &got
}

func TestForInsertKeepsAKnownOriginSilently(t *testing.T) {
	reports := recordReports(t)

	for _, origin := range all {
		if origin == Unknown {
			continue
		}
		assert.Equal(t, origin, ForInsert("test", 1, 2, origin))
	}
	assert.Empty(t, *reports)
}

func TestForInsertReportsUnknownOnceAndStillReturnsIt(t *testing.T) {
	for _, origin := range []string{Unknown, "", "nonsense"} {
		reports := recordReports(t)

		assert.Equal(t, Unknown, ForInsert("site.Name", 11, 22, origin))
		if assert.Len(t, *reports, 1, origin) {
			assert.Contains(t, (*reports)[0], "site.Name")
			assert.Contains(t, (*reports)[0], "user 11")
			assert.Contains(t, (*reports)[0], "group 22")
		}
	}
}

func TestFromClientRefusesTheValuesOnlyTheServerSets(t *testing.T) {
	for _, origin := range []string{Manual, Posted, Replied, Signup} {
		assert.True(t, FromClient(origin), origin)
	}
	for _, origin := range []string{Subscribed, Rippled, Moderator, Partner, TNUnaddressed, Unknown, "", "Manual"} {
		assert.False(t, FromClient(origin), origin)
	}
	assert.Equal(t, "origin must be one of: manual, posted, replied, signup", ClientValuesMessage())
}
