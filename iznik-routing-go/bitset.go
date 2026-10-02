package main

// Bitset is a fixed-size bit vector over node ids.
//
// Go's []bool spends a whole byte on one bit. At UK scale that is 56,874,452
// bytes for DriveSnappable, of which 49,765,145 are waste, in resident anon
// memory that the mmap'd leaf tables are competing with. A bitset is also
// friendlier to the random-node lookups that read it: eight times as many
// nodes' bits fit in a cache line.
type Bitset struct {
	words []uint64
	n     int
}

// NewBitset returns a bitset able to hold ids 0..n-1, all clear.
func NewBitset(n int) *Bitset {
	if n <= 0 {
		return &Bitset{}
	}
	return &Bitset{words: make([]uint64, (n+63)/64), n: n}
}

// Len is the number of bits the set can hold.
func (b *Bitset) Len() int {
	if b == nil {
		return 0
	}
	return b.n
}

// Set turns bit i on. Out-of-range ids are ignored rather than panicking, so a
// caller cannot turn a stale id into a crash.
func (b *Bitset) Set(i int) {
	if b == nil || i < 0 || i >= b.n {
		return
	}
	b.words[i>>6] |= 1 << uint(i&63)
}

// Get reports whether bit i is on. A nil bitset reads as all-clear; callers
// that treat nil as "no filtering" must check for nil themselves, exactly as
// they did with a nil []bool.
func (b *Bitset) Get(i int) bool {
	if b == nil || i < 0 || i >= b.n {
		return false
	}
	return b.words[i>>6]&(1<<uint(i&63)) != 0
}

// Count returns how many bits are set.
func (b *Bitset) Count() int {
	if b == nil {
		return 0
	}
	total := 0
	for _, w := range b.words {
		total += popcount(w)
	}
	return total
}

// popcount is math/bits.OnesCount64 by another name, kept local so the type has
// no import surface beyond the standard integer ops.
func popcount(w uint64) int {
	w -= (w >> 1) & 0x5555555555555555
	w = (w & 0x3333333333333333) + ((w >> 2) & 0x3333333333333333)
	w = (w + (w >> 4)) & 0x0f0f0f0f0f0f0f0f
	return int((w * 0x0101010101010101) >> 56)
}
