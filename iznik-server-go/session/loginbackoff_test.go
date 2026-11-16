package session

import (
	"fmt"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func backoffAt(start time.Time) (*loginBackoff, *time.Time) {
	now := start
	b := newLoginBackoff()
	b.now = func() time.Time { return now }
	return b, &now
}

func TestLoginBackoffWaitDoublesToACap(t *testing.T) {
	assert.Equal(t, time.Duration(0), loginBackoffWait(5, 5), "the free failures cost nothing")
	assert.Equal(t, 30*time.Second, loginBackoffWait(6, 5))
	assert.Equal(t, time.Minute, loginBackoffWait(7, 5))
	assert.Equal(t, 2*time.Minute, loginBackoffWait(8, 5))
	assert.Equal(t, 8*time.Minute, loginBackoffWait(10, 5))
	assert.Equal(t, loginBackoffMaxWait, loginBackoffWait(11, 5))
	assert.Equal(t, loginBackoffMaxWait, loginBackoffWait(500, 5), "no overflow however many failures")
}

func TestLoginBackoffHoldsAfterTheFreeFailuresAndReleasesInTime(t *testing.T) {
	b, now := backoffAt(time.Date(2026, 11, 16, 12, 0, 0, 0, time.UTC))

	for i := 0; i < loginBackoffAccountFree; i++ {
		b.fail("k", loginBackoffAccountFree)
		assert.Equal(t, time.Duration(0), b.wait("k"))
	}

	b.fail("k", loginBackoffAccountFree)
	assert.Equal(t, 30*time.Second, b.wait("k"))

	*now = now.Add(29 * time.Second)
	assert.Equal(t, time.Second, b.wait("k"))

	*now = now.Add(time.Second)
	assert.Equal(t, time.Duration(0), b.wait("k"), "waiting it out lets the next attempt through")

	b.fail("k", loginBackoffAccountFree)
	assert.Equal(t, time.Minute, b.wait("k"), "a further failure waits twice as long")
	assert.Equal(t, time.Duration(0), b.wait("other"), "another key is not held")
}

func TestLoginBackoffClearAndForget(t *testing.T) {
	b, now := backoffAt(time.Date(2026, 11, 16, 12, 0, 0, 0, time.UTC))

	for i := 0; i < loginBackoffAccountFree+1; i++ {
		b.fail("k", loginBackoffAccountFree)
	}
	b.clear("k")
	assert.Equal(t, time.Duration(0), b.wait("k"), "a success starts the count again")

	for i := 0; i < loginBackoffAccountFree; i++ {
		b.fail("k", loginBackoffAccountFree)
	}
	*now = now.Add(loginBackoffForget)
	b.fail("k", loginBackoffAccountFree)
	assert.Equal(t, time.Duration(0), b.wait("k"), "failures from over an hour ago no longer count")

	b.fail("", loginBackoffAccountFree)
	assert.Equal(t, time.Duration(0), b.wait(""), "no key, no count")
}

func TestLoginBackoffTableIsBounded(t *testing.T) {
	b, now := backoffAt(time.Date(2026, 11, 16, 12, 0, 0, 0, time.UTC))
	b.maxKeys = 3

	for i := 0; i < 10; i++ {
		b.fail(fmt.Sprintf("k%d", i), 0)
	}
	assert.Len(t, b.keys, 3, "a full table of live counts does not grow")
	assert.Equal(t, time.Duration(0), b.wait("k9"), "the key that did not fit is let through")

	*now = now.Add(loginBackoffForget)
	b.fail("new", 0)
	assert.Len(t, b.keys, 1, "stale counts are swept to make room")
	assert.Equal(t, 30*time.Second, b.wait("new"))
}

func TestLoginBackoffAFullTableDropsCountsThatHoldNobody(t *testing.T) {
	b, now := backoffAt(time.Date(2026, 11, 16, 12, 0, 0, 0, time.UTC))
	b.maxKeys = 4

	// One key that is waiting, and three single failures that hold nobody.
	b.fail("held", 0)
	for i := 0; i < 3; i++ {
		b.fail(fmt.Sprintf("single%d", i), loginBackoffAddressFree)
	}
	assert.Len(t, b.keys, 4)

	*now = now.Add(time.Second)
	b.fail("held2", 0)
	assert.Len(t, b.keys, 2, "the single failures made room")
	assert.Greater(t, b.wait("held"), time.Duration(0), "the key that is waiting was kept")
	assert.Equal(t, 30*time.Second, b.wait("held2"))
}

func TestLoginWaitWords(t *testing.T) {
	assert.Equal(t, "1 second", loginWaitWords(1))
	assert.Equal(t, "30 seconds", loginWaitWords(30))
	assert.Equal(t, "1 minute", loginWaitWords(60))
	assert.Equal(t, "2 minutes", loginWaitWords(61))
	assert.Equal(t, "15 minutes", loginWaitWords(900))
}
