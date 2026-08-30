//go:build !unix

package main

import (
	"os"
	"unsafe"
)

// mapFile heap-loads path on platforms without mmap support (Windows dev
// builds). The backing array is allocated as uint64s so the byte view is
// 8-aligned — the index region is cast to []ltIdx (u64-first) in place, which
// needs that alignment; a plain os.ReadFile []byte guarantees none.
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
	size := int(fi.Size())
	if size == 0 {
		return []byte{}, false, nil
	}
	backing := make([]uint64, (size+7)/8)
	buf := unsafe.Slice((*byte)(unsafe.Pointer(&backing[0])), size)
	if _, err := f.ReadAt(buf, 0); err != nil {
		return nil, false, err
	}
	return buf, false, nil
}

func unmapFile([]byte) {}
