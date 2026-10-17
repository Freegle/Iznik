package ux

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestClip(t *testing.T) {
	assert.Equal(t, "short", clip("short", 10))
	assert.Equal(t, "trimmed", clip("  trimmed \n", 10), "white space at the ends is dropped before counting")
	assert.Equal(t, "abcde", clip("abcdefgh", 5))
	assert.Equal(t, "", clip("   ", 5))
	assert.Equal(t, "", clip("anything", 0))

	// It counts characters, so a cut never splits a multi-byte one.
	assert.Equal(t, "cafés", clip("cafés and more", 5))
	assert.Equal(t, "££", clip("£££", 2))
}

func TestTstr(t *testing.T) {
	assert.Nil(t, tstr(nil), "a missing time stays null")

	at := time.Date(2026, 10, 14, 9, 5, 7, 123456789, time.UTC)
	s := tstr(&at)
	if assert.NotNil(t, s) {
		assert.Equal(t, "2026-10-14 09:05:07", *s, "to the second, no zone")
	}

	// A time in another zone is written in UTC.
	plusTwo := time.Date(2026, 10, 14, 11, 5, 7, 0, time.FixedZone("plus2", 2*60*60))
	s = tstr(&plusTwo)
	if assert.NotNil(t, s) {
		assert.Equal(t, "2026-10-14 09:05:07", *s)
	}
}

func TestTimeFormat(t *testing.T) {
	at := time.Date(2041, 3, 9, 4, 2, 1, 0, time.UTC)
	assert.Equal(t, "2041-03-09 04:02:01", at.Format(timeFormat))
	back, err := time.Parse(timeFormat, "2041-03-09 04:02:01")
	assert.NoError(t, err)
	assert.True(t, at.Equal(back), "the format reads back what it wrote")
}

func TestShare(t *testing.T) {
	assert.Equal(t, 0.0, share(5, 0), "an empty whole is 0, not a divide by zero")
	assert.Equal(t, 0.0, share(5, -3))
	assert.Equal(t, 0.0, share(0, 10))
	assert.Equal(t, 0.5, share(1, 2))
	assert.Equal(t, 1.0, share(7, 7))
	assert.Equal(t, 0.333, share(1, 3), "to three places")
	assert.Equal(t, 0.667, share(2, 3))
}

func TestKnownFlows(t *testing.T) {
	want := []string{"give", "ask", "reply", "login", "chat_promise", "chat_outcome", "settings"}
	assert.Len(t, knownFlows, len(want), "there are seven funnels and no more")
	for _, f := range want {
		assert.True(t, knownFlows[f], f)
		assert.True(t, flowRe.MatchString(f), "%s is a valid flow name for the flow parameter", f)
	}
	assert.False(t, knownFlows["general"])
	assert.False(t, knownFlows[""])
}
