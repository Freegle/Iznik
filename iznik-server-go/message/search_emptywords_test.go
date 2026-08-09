package message

import (
	"testing"
)

// TestGetWordsEmptyListDoesNotQuery pins the empty-search guard on all four
// GetWords* variants: a search whose terms reduce to zero indexed words must
// return no results WITHOUT issuing SQL. GetWordsExact was missing the guard
// its three siblings had, so it rendered "word IN ()" - invalid SQL that
// failed with Error 1064 in production whenever a search contained only
// unindexable terms.
//
// The nil *gorm.DB is the proof mechanism: if any variant reaches its
// db.Raw call with an empty word list, the test dies on a nil dereference
// instead of passing vacuously.
func TestGetWordsEmptyListDoesNotQuery(t *testing.T) {
	empty := []string{}

	if got := GetWordsExact(nil, empty, 100, nil, nil, "", 0, 0, 0, 0); len(got) != 0 {
		t.Fatalf("GetWordsExact with no words returned %d results, want 0", len(got))
	}
	if got := GetWordsTypo(nil, empty, 100, nil, nil, "", 0, 0, 0, 0); len(got) != 0 {
		t.Fatalf("GetWordsTypo with no words returned %d results, want 0", len(got))
	}
	if got := GetWordsStarts(nil, empty, 100, nil, nil, "", 0, 0, 0, 0); len(got) != 0 {
		t.Fatalf("GetWordsStarts with no words returned %d results, want 0", len(got))
	}
	if got := GetWordsSounds(nil, empty, 100, nil, nil, "", 0, 0, 0, 0); len(got) != 0 {
		t.Fatalf("GetWordsSounds with no words returned %d results, want 0", len(got))
	}
}
