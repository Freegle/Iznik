package message

// Tests for pure (no-DB) functions in bounds.go: chunkWindows (the IN (...) batching used by
// viewedMessageIDs) and applyUnseen (the Go-side replacement for the old LEFT JOIN CASE column).

import (
	"reflect"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestChunkWindows_Empty(t *testing.T) {
	got := chunkWindows(nil, 1000)
	assert.Nil(t, got)

	got = chunkWindows([]uint64{}, 1000)
	assert.Nil(t, got)
}

func TestChunkWindows_SingleWindow(t *testing.T) {
	ids := []uint64{1, 2, 3}
	got := chunkWindows(ids, 1000)
	assert.Equal(t, [][]uint64{{1, 2, 3}}, got)
}

// Exactly one window's worth must not spill into an empty second window.
func TestChunkWindows_ExactMultiple(t *testing.T) {
	ids := []uint64{1, 2, 3, 4}
	got := chunkWindows(ids, 2)
	assert.Equal(t, [][]uint64{{1, 2}, {3, 4}}, got)
}

// A remainder short of a full window must still get its own (smaller) window.
func TestChunkWindows_UnevenRemainder(t *testing.T) {
	ids := []uint64{1, 2, 3, 4, 5}
	got := chunkWindows(ids, 2)
	assert.Equal(t, [][]uint64{{1, 2}, {3, 4}, {5}}, got)
}

// Order is preserved and every id appears in exactly one window (the boundsLikesChunk-sized
// IN (...) batches must reconstruct the full input set with none dropped or duplicated).
func TestChunkWindows_CoversAllIDsInOrder(t *testing.T) {
	ids := make([]uint64, 2500)
	for i := range ids {
		ids[i] = uint64(i + 1)
	}
	windows := chunkWindows(ids, 1000)
	if assert.Len(t, windows, 3) {
		assert.Len(t, windows[0], 1000)
		assert.Len(t, windows[1], 1000)
		assert.Len(t, windows[2], 500)
	}

	var rebuilt []uint64
	for _, w := range windows {
		rebuilt = append(rebuilt, w...)
	}
	assert.Equal(t, ids, rebuilt)
}

// size <= 0 must not loop forever; it degrades to a single window rather than an infinite loop.
func TestChunkWindows_NonPositiveSizeIsOneWindow(t *testing.T) {
	ids := []uint64{1, 2, 3}
	assert.Equal(t, [][]uint64{{1, 2, 3}}, chunkWindows(ids, 0))
	assert.Equal(t, [][]uint64{{1, 2, 3}}, chunkWindows(ids, -5))
}

func TestChunkWindows_DoesNotMutateInput(t *testing.T) {
	ids := []uint64{1, 2, 3, 4, 5}
	orig := make([]uint64, len(ids))
	copy(orig, ids)
	_ = chunkWindows(ids, 2)
	if !reflect.DeepEqual(ids, orig) {
		t.Fatalf("input was mutated: %v", ids)
	}
}

// applyUnseen must reproduce the old "LEFT JOIN messages_likes ... CASE WHEN msgid IS NULL THEN 1
// ELSE 0" semantics: unseen (true) exactly when the id has no entry in viewed.
func TestApplyUnseen_MixOfViewedAndUnviewed(t *testing.T) {
	msgs := []MessageSummary{{ID: 1}, {ID: 2}, {ID: 3}}
	viewed := map[uint64]bool{2: true}

	applyUnseen(msgs, viewed)

	assert.True(t, msgs[0].Unseen, "id 1 has no messages_likes row -> unseen")
	assert.False(t, msgs[1].Unseen, "id 2 has a messages_likes row -> seen")
	assert.True(t, msgs[2].Unseen, "id 3 has no messages_likes row -> unseen")
}

// An anonymous caller (myid == 0) never gets a viewedMessageIDs lookup, so viewed is nil here -
// exactly reproducing the old LEFT JOIN, whose ON clause could never match userid = 0, so every
// post came back unseen.
func TestApplyUnseen_NilViewedMeansEverythingUnseen(t *testing.T) {
	msgs := []MessageSummary{{ID: 1}, {ID: 2}}

	applyUnseen(msgs, nil)

	assert.True(t, msgs[0].Unseen)
	assert.True(t, msgs[1].Unseen)
}

func TestApplyUnseen_EmptyMsgsDoesNotPanic(t *testing.T) {
	msgs := []MessageSummary{}
	applyUnseen(msgs, map[uint64]bool{1: true})
	assert.Empty(t, msgs)
}
