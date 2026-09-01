package main

// Precomputed leaf tables: the entry→node intra-region distance/metre tables
// that answer every per-target reach lookup (drive-metrics, arrivals,
// isochrones), computed for ALL leaves at artifact-build time instead of
// lazily at query time.
//
// Why: the tables are pure functions of the road network — they depend on
// nothing per-query — but building one takes ~3-4ms of Dijkstra, so the lazy
// path made the first query into any cold region pay milliseconds per leaf
// and a scattered 1,000-target batch pay seconds. Cache sizing only moves the
// cliff around: someone somewhere is always the cold one. Precomputing all
// ~23,675 UK leaves costs ~90s once in the artifact build and ~2GB on disk;
// the file is memory-mapped, so a "cold" leaf costs a page fault (µs) and the
// kernel page cache decides residency — no heap growth, no warmup, uniform
// latency wherever the member lives.
//
// The file is fingerprinted against the partition (the same FNV the stored
// labels use), so a stale artifact from an older partition is refused and the
// engine falls back to the lazy path — which also remains the answer for the
// origin's own leaf (arbitrary-source Dijkstra) and stored-label seed rows,
// neither of which this file can precompute.
//
// Layout (little-endian; every offset 4-aligned by construction):
//
//	0   8  magic "FRGLTAB1"
//	8   4  version (2)
//	12  4  leaf count
//	16  8  partition fingerprint
//	24  8  reserved
//	32  leafCount × 24: {data offset u64, entries u32, nodes u32,
//	                     metShift u32, reserved u32}
//	...   per leaf, in leaf order: dist f32[entries×nodes],
//	                              met  u16[entries×nodes], padded to 4 bytes
//
// Entry order matches rm.LeafEntries(leaf); node order matches
// part.LeafNodes[leaf] ("local index = position"), so no id lists are stored.
//
// Version 2 halves the metres. Metres were f32 alongside f32 seconds, 8 bytes
// per cell over 188,266,103 cells on the UK artifact; storing them as uint16 in
// per-leaf units of 1<<metShift metres takes that to 6 bytes, about 376MB off a
// mmap'd file that production could only keep 868MB of resident.
//
// SECONDS ARE DELIBERATELY STILL f32. The engine is checked against a flat
// Dijkstra to within 0.01s, and metres are not: the metres test already allows
// divergence, because metres follow whichever path won on time and equal-time
// ties can take different roads. Quantising seconds to anything that fits a
// uint16 across the real leaf-size distribution costs more than that gate
// allows - the census found 1,477 of 23,675 leaves exceeding 655.34s, so most
// CELLS would need 0.1s units, five times the tolerance. The gate is worth more
// than the remaining 376MB, especially now the anon heap that was starving this
// file has itself halved.

import (
	"encoding/binary"
	"fmt"
	"log"
	"os"
	"time"
	"unsafe"
)

const (
	leafTablesMagic   = "FRGLTAB1"
	leafTablesVersion = uint32(2)
	leafTablesHdrLen  = 32
	leafTablesIdxLen  = 24
	leafTablesName    = "leaftables.snap"
)

// metUnreachable is the metres sentinel for a cell the entry cannot reach. It
// pairs with +Inf in the seconds block, which dijkstraFromM writes.
const metUnreachable uint16 = 65535

// maxMetShift bounds the per-leaf metre unit at 1<<8 = 256m, which covers a
// 16,776km intra-leaf road distance. Anything beyond that is not a leaf.
const maxMetShift = 8

// ltIdx mirrors one index record. Fixed 24-byte layout so the index region can
// be viewed in place. Entries/Nodes stay uint32 rather than being squeezed into
// uint16 to make room for metShift: the partition's leaf size is a tunable, and
// a format that silently truncates when someone raises it is not worth 2 bytes
// per leaf.
type ltIdx struct {
	Off      uint64
	Entries  uint32
	Nodes    uint32
	MetShift uint32
	_        uint32
}

// metUnit is the metres one stored unit represents for this leaf.
func (ix ltIdx) metUnit() float32 { return float32(uint32(1) << ix.MetShift) }

// metShiftFor picks the smallest per-leaf unit that fits every metre value into
// a uint16 short of the sentinel.
func metShiftFor(maxMetres float32) uint32 {
	for s := uint32(0); s < maxMetShift; s++ {
		if float64(maxMetres)/float64(uint32(1)<<s) <= float64(metUnreachable-1) {
			return s
		}
	}
	return maxMetShift
}

// blockBytes is the on-disk size of one leaf's block: f32 seconds then u16
// metres, rounded up so the next leaf's seconds stay 4-aligned.
func blockBytes(n int) int {
	return 4*n + (2*n+3)/4*4
}

// LeafTables is a loaded (preferably mmap'd) leaf-tables artifact.
type LeafTables struct {
	data   []byte
	idx    []ltIdx
	mapped bool
}

// Close releases the mapping (no-op for a heap-loaded file).
func (lt *LeafTables) Close() {
	if lt != nil && lt.mapped {
		unmapFile(lt.data)
	}
}

// table returns the flat dist/met blocks for leaf, or ok=false when the
// artifact has no usable entry for it (zero-node leaves store nothing).
func (lt *LeafTables) table(leaf int32) (dist []float32, met []uint16, metUnit float32, entries, nodes int, ok bool) {
	if lt == nil || leaf < 0 || int(leaf) >= len(lt.idx) {
		return nil, nil, 0, 0, 0, false
	}
	ix := lt.idx[leaf]
	n := int(ix.Entries) * int(ix.Nodes)
	if n == 0 {
		// A leaf with no entries still has a valid (empty) table: callers get
		// entries=0 and fall through to "unreached", same as the lazy build.
		return nil, nil, ix.metUnit(), int(ix.Entries), int(ix.Nodes), true
	}
	off := int(ix.Off)
	end := off + blockBytes(n)
	if off < leafTablesHdrLen || end > len(lt.data) || off%4 != 0 {
		return nil, nil, 0, 0, 0, false
	}
	dist = unsafe.Slice((*float32)(unsafe.Pointer(&lt.data[off])), n)
	met = unsafe.Slice((*uint16)(unsafe.Pointer(&lt.data[off+4*n])), n)
	return dist, met, ix.metUnit(), int(ix.Entries), int(ix.Nodes), true
}

// LoadLeafTables opens and validates path against the expected partition
// fingerprint. The data is mmap'd where the platform supports it.
func LoadLeafTables(path string, wantFP uint64, leaves int) (*LeafTables, error) {
	data, mapped, err := mapFile(path)
	if err != nil {
		return nil, err
	}
	lt := &LeafTables{data: data, mapped: mapped}
	// Build the error BEFORE unmapping: the arguments can be views into the
	// mapping (the bad-magic case formats data[:8]), and formatting them after
	// Munmap reads unmapped memory - a SIGSEGV instead of the refusal, which
	// on a server would turn a corrupt artifact into a boot crash rather than
	// the intended fall-back to the lazy path.
	fail := func(format string, args ...any) (*LeafTables, error) {
		err := fmt.Errorf(format, args...)
		lt.Close()
		return nil, err
	}
	if len(data) < leafTablesHdrLen {
		return fail("leaftables: truncated header (%d bytes)", len(data))
	}
	if string(data[:8]) != leafTablesMagic {
		return fail("leaftables: bad magic %q", data[:8])
	}
	if v := binary.LittleEndian.Uint32(data[8:12]); v != leafTablesVersion {
		return fail("leaftables: version %d, want %d", v, leafTablesVersion)
	}
	nLeaves := int(binary.LittleEndian.Uint32(data[12:16]))
	if nLeaves != leaves {
		return fail("leaftables: %d leaves, engine has %d", nLeaves, leaves)
	}
	if fp := binary.LittleEndian.Uint64(data[16:24]); fp != wantFP {
		return fail("leaftables: partition fingerprint %x, want %x", fp, wantFP)
	}
	idxEnd := leafTablesHdrLen + nLeaves*leafTablesIdxLen
	if len(data) < idxEnd {
		return fail("leaftables: truncated index")
	}
	if nLeaves > 0 {
		lt.idx = unsafe.Slice((*ltIdx)(unsafe.Pointer(&data[leafTablesHdrLen])), nLeaves)
	}
	// Validate every record now, so serving never has to bounds-check beyond
	// the cheap per-call guard in table().
	for leaf, ix := range lt.idx {
		n := int(ix.Entries) * int(ix.Nodes)
		if n == 0 {
			continue
		}
		if int(ix.Off) < idxEnd || int(ix.Off)+blockBytes(n) > len(data) || ix.Off%4 != 0 {
			return fail("leaftables: leaf %d block out of range", leaf)
		}
	}
	return lt, nil
}

// BuildLeafTablesFile computes every leaf's table and writes the artifact,
// atomically (path+".building", then rename). Safe to run while the engine
// serves: it uses the same pure builders the lazy path uses but never touches
// the runtime cache, so it neither evicts hot entries nor races them.
func BuildLeafTablesFile(path string, e *ReachEngine, workers int) error {
	start := time.Now()
	nLeaves := len(e.Part.LeafNodes)
	if workers < 1 {
		workers = 1
	}

	// Sizes are known up front (entries × nodes per leaf), so every offset is
	// computable before any Dijkstra runs and the file is written strictly
	// sequentially.
	idx := make([]ltIdx, nLeaves)
	off := uint64(leafTablesHdrLen + nLeaves*leafTablesIdxLen)
	for leaf := 0; leaf < nLeaves; leaf++ {
		ents := len(e.RM.LeafEntries(int32(leaf)))
		nodes := len(e.Part.LeafNodes[int32(leaf)])
		idx[leaf] = ltIdx{Off: off, Entries: uint32(ents), Nodes: uint32(nodes)}
		off += uint64(blockBytes(ents * nodes))
	}

	// Per-process temp name: two builders (a server self-heal racing an
	// explicit CLI build) each write their own complete file, and the atomic
	// renames just pick a winner - never interleaved bytes under one name.
	tmp := fmt.Sprintf("%s.building.%d", path, os.Getpid())
	f, err := os.Create(tmp)
	if err != nil {
		return err
	}
	defer func() {
		f.Close()
		os.Remove(tmp)
	}()

	hdr := make([]byte, leafTablesHdrLen)
	copy(hdr, leafTablesMagic)
	binary.LittleEndian.PutUint32(hdr[8:12], leafTablesVersion)
	binary.LittleEndian.PutUint32(hdr[12:16], uint32(nLeaves))
	binary.LittleEndian.PutUint64(hdr[16:24], e.partFP)
	if _, err := f.Write(hdr); err != nil {
		return err
	}
	// The index goes down as a placeholder and is rewritten at the end: a
	// leaf's metre unit is only known once its block has been built, and
	// building every block twice to learn it would double a 90s job.
	idxBytes := unsafe.Slice((*byte)(unsafe.Pointer(&idx[0])), nLeaves*leafTablesIdxLen)
	if _, err := f.Write(idxBytes); err != nil {
		return err
	}

	// Compute in parallel, write in leaf order, bounded memory: chunks of
	// leaves are filled by a worker pool then flushed sequentially (~worst
	// case a few hundred KB per leaf in flight).
	const chunk = 256
	blocks := make([][]byte, chunk)
	shifts := make([]uint32, chunk)
	for base := 0; base < nLeaves; base += chunk {
		end := base + chunk
		if end > nLeaves {
			end = nLeaves
		}
		sem := make(chan struct{}, workers)
		done := make(chan error, end-base)
		for leaf := base; leaf < end; leaf++ {
			sem <- struct{}{}
			go func(leaf int) {
				defer func() { <-sem }()
				blocks[leaf-base], shifts[leaf-base] = buildLeafBlock(e, int32(leaf))
				done <- nil
			}(leaf)
		}
		for i := base; i < end; i++ {
			<-done
		}
		for leaf := base; leaf < end; leaf++ {
			b := blocks[leaf-base]
			idx[leaf].MetShift = shifts[leaf-base]
			if want := blockBytes(int(idx[leaf].Entries) * int(idx[leaf].Nodes)); len(b) != want {
				return fmt.Errorf("leaftables: leaf %d block %d bytes, want %d", leaf, len(b), want)
			}
			if len(b) > 0 {
				if _, err := f.Write(b); err != nil {
					return err
				}
			}
			blocks[leaf-base] = nil
		}
	}

	// Rewrite the index now every leaf's metre unit is known.
	if _, err := f.Seek(int64(leafTablesHdrLen), 0); err != nil {
		return err
	}
	if _, err := f.Write(idxBytes); err != nil {
		return err
	}

	if err := f.Sync(); err != nil {
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	if err := os.Rename(tmp, path); err != nil {
		return err
	}
	fi, _ := os.Stat(path)
	var mb float64
	if fi != nil {
		mb = float64(fi.Size()) / 1e6
	}
	log.Printf("reach: leaf tables built to %s (%d leaves, %.1fMB) in %v",
		path, nLeaves, mb, time.Since(start).Round(time.Millisecond))
	return nil
}

// buildLeafBlock computes one leaf's dist+met block, in file layout, using
// the same primitives as the lazy path so answers are bit-identical.
func buildLeafBlock(e *ReachEngine, leaf int32) ([]byte, uint32) {
	ents := e.RM.LeafEntries(leaf)
	nodes := e.Part.LeafNodes[leaf]
	n := len(ents) * len(nodes)
	if n == 0 {
		return nil, 0
	}
	dist := make([]float32, n)
	metF := make([]float32, n)
	ls := buildLeafSubgraph(e.Ov, e.Part, leaf)
	for i, ent := range ents {
		lo, hi := i*len(nodes), (i+1)*len(nodes)
		ls.dijkstraFromM(ls.localOf[ent], dist[lo:hi], metF[lo:hi])
	}

	// Pick the per-leaf metre unit from what this leaf actually holds, then
	// quantise. Unreachable cells are +Inf in both blocks and become the
	// sentinel here.
	var maxMet float32
	for _, v := range metF {
		if v != f32Inf && v > maxMet {
			maxMet = v
		}
	}
	shift := metShiftFor(maxMet)
	unit := float64(uint32(1) << shift)

	out := make([]byte, blockBytes(n))
	copy(out, unsafe.Slice((*byte)(unsafe.Pointer(&dist[0])), 4*n))
	met := unsafe.Slice((*uint16)(unsafe.Pointer(&out[4*n])), n)
	for i, v := range metF {
		if v == f32Inf {
			met[i] = metUnreachable
			continue
		}
		q := int64(float64(v)/unit + 0.5)
		if q < 0 {
			q = 0
		}
		if q >= int64(metUnreachable) {
			q = int64(metUnreachable) - 1
		}
		met[i] = uint16(q)
	}
	return out, shift
}

// maybeLoadOrBuildLeafTables attaches the artifact when present and valid;
// otherwise leaves the lazy path serving and self-heals in the background —
// build, save, mmap, attach — exactly the derive-at-boot convention the
// partition and matrices artifacts already follow, except the build is slow
// enough (~90s for the UK) that it must not block readiness.
func maybeLoadOrBuildLeafTables(dir string, e *ReachEngine) {
	path := dir + string(os.PathSeparator) + leafTablesName
	nLeaves := len(e.Part.LeafNodes)
	if lt, err := LoadLeafTables(path, e.partFP, nLeaves); err == nil {
		e.leafTabs.Store(lt)
		log.Printf("reach: leaf tables mapped from %s (%d leaves)", path, nLeaves)
		return
	} else if !os.IsNotExist(err) {
		log.Printf("reach: leaf tables at %s unusable (%v): rebuilding", path, err)
	} else {
		log.Printf("reach: leaf tables artifact missing: building in background")
	}
	go func() {
		if err := BuildLeafTablesFile(path, e, 4); err != nil {
			log.Printf("reach: WARNING: leaf tables build failed (%v); lazy path continues", err)
			return
		}
		lt, err := LoadLeafTables(path, e.partFP, nLeaves)
		if err != nil {
			log.Printf("reach: WARNING: freshly built leaf tables unusable (%v)", err)
			return
		}
		e.leafTabs.Store(lt)
		log.Printf("reach: leaf tables attached (self-heal complete)")
	}()
}
