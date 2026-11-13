package testlock

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

// While a test holds the lock nobody else can take it, and it is free again once the test ends.
func TestHoldExcludesAndReleases(t *testing.T) {
	name := "selftest"
	path := filepath.Join(os.TempDir(), "iznik-testlock-"+name)
	try := func() error {
		f, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o666)
		if err != nil {
			t.Fatal(err)
		}
		defer f.Close()
		err = syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB)
		if err == nil {
			_ = syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
		}
		return err
	}

	t.Run("held", func(t *testing.T) {
		Hold(t, name)
		if try() == nil {
			t.Fatal("the lock was taken a second time while a test held it")
		}
	})
	if err := try(); err != nil {
		t.Fatalf("the lock was not released when the test ended: %v", err)
	}
}
