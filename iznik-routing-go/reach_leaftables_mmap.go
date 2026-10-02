//go:build unix

package main

import (
	"os"
	"syscall"
)

// mapFile memory-maps path read-only. The returned bool reports whether the
// bytes are a mapping (and must go back through unmapFile) or a heap read.
// A mapping is what makes the leaf-tables artifact free to "hold": pages are
// backed by the kernel page cache, faulted in on first touch and evicted
// under pressure, so the process heap never grows with the file.
func mapFile(path string) ([]byte, bool, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, false, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil, false, err
	}
	if fi.Size() == 0 {
		return []byte{}, false, nil
	}
	data, err := syscall.Mmap(int(f.Fd()), 0, int(fi.Size()), syscall.PROT_READ, syscall.MAP_SHARED)
	if err != nil {
		return nil, false, err
	}
	return data, true, nil
}

func unmapFile(data []byte) {
	if len(data) > 0 {
		_ = syscall.Munmap(data)
	}
}
