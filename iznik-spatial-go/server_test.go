package main

import (
	"testing"
	"time"
)

// TestSwapIndexWaitsForActiveReader verifies the use-after-close fix: swapIndex
// must not Close the old index while a withIndex reader is still using it, and
// must still Close it (no leak) once the reader has released.
func TestSwapIndexWaitsForActiveReader(t *testing.T) {
	dir := t.TempDir()
	idx1, err := CreateIndex(dir + "/a.db")
	if err != nil {
		t.Fatalf("create idx1: %v", err)
	}
	idx2, err := CreateIndex(dir + "/b.db")
	if err != nil {
		t.Fatalf("create idx2: %v", err)
	}

	s := &datasetState{idx: idx1}

	entered := make(chan struct{})
	proceed := make(chan struct{})
	readerErr := make(chan error, 1)

	// A reader holds the index via withIndex and then uses it after a pause.
	go func() {
		readerErr <- s.withIndex(func(idx *Index) error {
			close(entered)
			<-proceed
			// idx1 must still be open here — swapIndex must not have Closed it.
			_, e := idx.CountRows()
			return e
		})
	}()

	<-entered

	// While the reader is active, a concurrent rebuild swaps the index.
	swapDone := make(chan struct{})
	go func() {
		s.swapIndex(idx2)
		close(swapDone)
	}()

	// swapIndex must block (it Closes the old index) until the reader releases.
	select {
	case <-swapDone:
		t.Fatal("swapIndex returned while a reader was active — old index would be Closed mid-query")
	case <-time.After(100 * time.Millisecond):
		// expected: still blocked on the write lock
	}

	close(proceed)

	if e := <-readerErr; e != nil {
		t.Fatalf("reader failed — old index was Closed underneath it: %v", e)
	}

	select {
	case <-swapDone:
	case <-time.After(2 * time.Second):
		t.Fatal("swapIndex did not complete after the reader released")
	}

	// Old index must now be Closed (no leak): a query should error.
	if _, e := idx1.CountRows(); e == nil {
		t.Error("old index was not Closed after swap — resource leak")
	}
	// New index must be live.
	if _, e := idx2.CountRows(); e != nil {
		t.Errorf("new index not usable after swap: %v", e)
	}
}
