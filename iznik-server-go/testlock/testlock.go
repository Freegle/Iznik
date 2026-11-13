// Package testlock lets tests in different packages take turns at something they share.
//
// `go test ./...` runs each package's tests as its own process, several at a time, against one
// database. Most tests keep to rows they made. A few need a whole table to themselves: paf_imports
// allows one unfinished import at a time, so a test in one package that commits one makes a handler
// test in another package answer 409, about one run in three. A transaction does not help, because the
// other package's row is committed. Hold serialises such tests across processes with a file lock.
package testlock

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

// Hold blocks until no other test process holds the named lock, and releases it when the test ends.
// Call it before the test first touches what is shared, and once per test: a second Hold of the same name
// while the first is held (in the same test or a subtest) would wait for itself. Where locking is not
// possible the test runs unlocked, as it did before.
func Hold(t testing.TB, name string) {
	t.Helper()
	f, err := os.OpenFile(filepath.Join(os.TempDir(), "iznik-testlock-"+name), os.O_CREATE|os.O_RDWR, 0o666)
	if err != nil {
		t.Logf("testlock: %s not taken: %v", name, err)
		return
	}
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX); err != nil {
		t.Logf("testlock: %s not taken: %v", name, err)
		f.Close()
		return
	}
	t.Cleanup(func() {
		_ = syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
		f.Close()
	})
}
