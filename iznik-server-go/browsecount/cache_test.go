package browsecount

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func reset() {
	mu.Lock()
	cache = map[uint64]entry{}
	mu.Unlock()
}

func TestGetReturnsWhatWasPut(t *testing.T) {
	reset()

	Put(42, "nearby", 10, 0, 7)
	got, ok := Get(42, "nearby", 10, 0)

	assert.True(t, ok)
	assert.Equal(t, uint64(7), got)
}

// Marking posts seen is the whole reason this cache can be trusted. The badge dropping to
// zero is how a member knows it worked, so their count must be forgotten at once rather
// than standing until it expires.
func TestInvalidateForgetsTheViewerImmediately(t *testing.T) {
	reset()

	Put(42, "nearby", 10, 0, 7)
	Invalidate(42)

	_, ok := Get(42, "nearby", 10, 0)
	assert.False(t, ok, "a count must not survive the viewer marking posts seen")
}

func TestInvalidateLeavesOtherViewersAlone(t *testing.T) {
	reset()

	Put(42, "nearby", 10, 0, 7)
	Put(43, "nearby", 10, 0, 9)
	Invalidate(42)

	got, ok := Get(43, "nearby", 10, 0)
	assert.True(t, ok, "one member marking seen must not cost everyone else their count")
	assert.Equal(t, uint64(9), got)
}

// A member who switches view or moves the distance slider is asking a different question,
// so the previous answer must not be handed back.
func TestADifferentQuestionIsAMiss(t *testing.T) {
	reset()

	Put(42, "nearby", 10, 0, 7)

	_, ok := Get(42, "mygroups", 10, 0)
	assert.False(t, ok, "switching view must not reuse the other view's count")

	_, ok = Get(42, "nearby", 25, 0)
	assert.False(t, ok, "moving the distance slider must not reuse the old distance's count")

	_, ok = Get(42, "nearby", 10, 25)
	assert.False(t, ok, "changing the drive-minutes budget must not reuse the old budget's count")
}

func TestAnExpiredCountIsNotReused(t *testing.T) {
	reset()

	mu.Lock()
	cache[42] = entry{browseView: "nearby", maxDistance: 10, count: 7, expires: time.Now().Add(-time.Second)}
	mu.Unlock()

	_, ok := Get(42, "nearby", 10, 0)
	assert.False(t, ok)
}

// Logged-out requests have no viewer to key on, so they must not all share one entry.
func TestTheAnonymousViewerIsNeverCached(t *testing.T) {
	reset()

	Put(0, "nearby", 10, 0, 7)
	_, ok := Get(0, "nearby", 10, 0)

	assert.False(t, ok, "counts must never be shared between logged-out requests")
}

// Filling the map must not throw away counts that are still live - that would turn a burst
// of new members into everyone else's queries running again at once.
func TestAFullMapOfLiveEntriesIsNotThrownAway(t *testing.T) {
	reset()

	for i := 1; i <= maxEntries; i++ {
		Put(uint64(i), "nearby", 10, 0, uint64(i))
	}
	Put(uint64(maxEntries+1), "nearby", 10, 0, 1)

	got, ok := Get(1, "nearby", 10, 0)
	assert.True(t, ok, "an existing live count was discarded to make room")
	assert.Equal(t, uint64(1), got)
}

// Expired entries are fair game, so an honestly busy cache still turns over.
func TestExpiredEntriesAreReclaimedWhenFull(t *testing.T) {
	reset()

	mu.Lock()
	for i := 1; i <= maxEntries; i++ {
		cache[uint64(i)] = entry{browseView: "nearby", maxDistance: 10, count: 1, expires: time.Now().Add(-time.Hour)}
	}
	mu.Unlock()

	Put(uint64(maxEntries+1), "nearby", 10, 0, 5)

	got, ok := Get(uint64(maxEntries+1), "nearby", 10, 0)
	assert.True(t, ok, "a new count must be stored once expired ones have been cleared out")
	assert.Equal(t, uint64(5), got)
}

// An existing viewer refreshing must always be able to replace their own entry, even when
// the map is full of live counts.
func TestAViewerCanAlwaysReplaceTheirOwnCount(t *testing.T) {
	reset()

	for i := 1; i <= maxEntries; i++ {
		Put(uint64(i), "nearby", 10, 0, 1)
	}
	Put(1, "nearby", 10, 0, 99)

	got, ok := Get(1, "nearby", 10, 0)
	assert.True(t, ok)
	assert.Equal(t, uint64(99), got, "a viewer's own refreshed count must replace the old one")
}
