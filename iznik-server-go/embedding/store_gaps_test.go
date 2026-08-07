package embedding

import (
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// FindByMsgid / Evict - pure in-memory Store operations, no DB required.
// ---------------------------------------------------------------------------

func TestStoreFindByMsgid(t *testing.T) {
	v1 := makeVec(1.0)
	v2 := makeVec(2.0)
	s := &Store{entries: []Entry{
		{Msgid: 1, Subject: "first", SubjectVec: v1},
		{Msgid: 2, Subject: "second", SubjectVec: v2},
	}}

	tests := []struct {
		name      string
		msgid     uint64
		wantFound bool
		wantSubj  string
	}{
		{"found first entry", 1, true, "first"},
		{"found second entry", 2, true, "second"},
		{"missing msgid not found", 999, false, ""},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			entry, found := s.FindByMsgid(tt.msgid)
			assert.Equal(t, tt.wantFound, found)
			if tt.wantFound {
				assert.Equal(t, tt.wantSubj, entry.Subject)
			}
		})
	}
}

func TestStoreFindByMsgid_EmptyStore(t *testing.T) {
	s := &Store{}
	_, found := s.FindByMsgid(1)
	assert.False(t, found)
}

func TestStoreEvict(t *testing.T) {
	v := makeVec(1.0)
	s := &Store{entries: []Entry{
		{Msgid: 1, SubjectVec: v},
		{Msgid: 2, SubjectVec: v},
		{Msgid: 3, SubjectVec: v},
	}}

	// Evict the middle entry - the others must survive, in order.
	removed := s.Evict(2)
	assert.True(t, removed)
	assert.Equal(t, 2, s.Count())
	_, found := s.FindByMsgid(2)
	assert.False(t, found)
	e1, ok1 := s.FindByMsgid(1)
	assert.True(t, ok1)
	assert.Equal(t, uint64(1), e1.Msgid)
	e3, ok3 := s.FindByMsgid(3)
	assert.True(t, ok3)
	assert.Equal(t, uint64(3), e3.Msgid)
}

func TestStoreEvict_NotFound(t *testing.T) {
	v := makeVec(1.0)
	s := &Store{entries: []Entry{{Msgid: 1, SubjectVec: v}}}

	removed := s.Evict(999)
	assert.False(t, removed)
	assert.Equal(t, 1, s.Count())
}

func TestStoreEvict_EmptyStore(t *testing.T) {
	s := &Store{}
	assert.False(t, s.Evict(1))
}

func TestStoreEvict_LastRemainingEntry(t *testing.T) {
	v := makeVec(1.0)
	s := &Store{entries: []Entry{{Msgid: 1, SubjectVec: v}}}

	removed := s.Evict(1)
	assert.True(t, removed)
	assert.Equal(t, 0, s.Count())
}

// ---------------------------------------------------------------------------
// Refresh - the db==nil guard (distinct from Load's, only reached when the
// store is already non-empty so the Count()==0 fallback to Load isn't taken).
// ---------------------------------------------------------------------------

func TestStoreRefresh_WithoutDB(t *testing.T) {
	orig := database.DBConn
	database.DBConn = nil
	defer func() { database.DBConn = orig }()

	v := makeVec(1.0)
	s := &Store{entries: []Entry{{Msgid: 1, SubjectVec: v}}}

	err := s.Refresh()
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "database not initialized")
	// The store must be left untouched on failure.
	assert.Equal(t, 1, s.Count())
}

func TestStoreRefresh_EmptyStoreFallsBackToLoad(t *testing.T) {
	// Count()==0 makes Refresh delegate to Load, which fails fast with DBConn
	// nil - proving the fallback branch is taken (not the id-diff branch).
	orig := database.DBConn
	database.DBConn = nil
	defer func() { database.DBConn = orig }()

	s := &Store{}
	err := s.Refresh()
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "database not initialized")
}

// ---------------------------------------------------------------------------
// StartRefresh - kicks off an initial Load and a background ticker. With
// DBConn nil the initial Load fails fast (logged, not fatal) and the ticker
// interval is set far beyond the test's lifetime so it never fires.
// ---------------------------------------------------------------------------

func TestStartRefresh_InitialLoadFailureDoesNotPanic(t *testing.T) {
	orig := database.DBConn
	database.DBConn = nil
	defer func() { database.DBConn = orig }()

	assert.NotPanics(t, func() {
		StartRefresh(time.Hour)
	})
}

// ---------------------------------------------------------------------------
// fetchEntries - the db==nil guard, reached directly (Load/Refresh both call
// through it, but this exercises the guard without going through either).
// ---------------------------------------------------------------------------

func TestFetchEntries_WithoutDB(t *testing.T) {
	orig := database.DBConn
	database.DBConn = nil
	defer func() { database.DBConn = orig }()

	entries, err := fetchEntries("")
	assert.Nil(t, entries)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "database not initialized")
}

func TestFetchEntries_WithoutDB_WithExtraWhere(t *testing.T) {
	orig := database.DBConn
	database.DBConn = nil
	defer func() { database.DBConn = orig }()

	entries, err := fetchEntries(" AND me.msgid IN (?)", []uint64{1, 2})
	assert.Nil(t, entries)
	assert.Error(t, err)
}
