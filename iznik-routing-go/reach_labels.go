package main

// Reach engine label wire format ("FRL2"): the stored per-post reach representation
// that replaces per-advance geometry recomputation. A blob is a few hundred
// bytes to a few KB (measured 0.6-3.8KB on real posts) and answers membership
// exactly via ReachEngine.ArrivalFromStored.
//
// Live query labels carry OriginArr (every internal arrival in the origin's
// seed regions) for speed; that is far too big to store. The stored form
// instead keeps the SEED junctions with their departure costs, and membership
// recomputes intra-region distances from a seed on demand via the engine's
// source-row cache — same values, exact, post-independent and shared.
//
// Layout, little-endian:
//
//	magic  "FRL2"
//	partFP     uint64  (fingerprint of the partition the leaf ids refer to)
//	T          float32
//	originChain uint32  (base node id of an absorbed origin, 0 if junction)
//	seedBase   float32
//	nSeeds     uint16, then per seed: overlay idx uint32, arrival float32
//	nRegions   uint32, then per region:
//	    leaf     int32
//	    flags    uint8 (bit0 = fully-in)
//	    nEntries uint16, then per reached entry: entry index uint16, arrival float32

import (
	"encoding/binary"
	"fmt"
	"math"
	"sort"
)

const labelsMagic = "FRL2"

// EncodeLabels serialises a query result to the stored form. The blob embeds
// the engine's partition fingerprint: leaf ids are assignment-order artifacts
// of ONE partition build, so labels stored under one partition must never be
// evaluated against another (DecodeLabels rejects them).
func (e *ReachEngine) EncodeLabels(lbl *ReachLabels) []byte {
	out := make([]byte, 0, 512)
	out = append(out, labelsMagic...)
	out = le64(out, e.partFP)
	out = le32(out, math.Float32bits(lbl.T))
	out = le32(out, uint32(lbl.originChain))
	out = le32(out, math.Float32bits(lbl.seedBase))
	out = le16(out, uint16(len(lbl.Seeds)))
	seedIDs := make([]uint32, 0, len(lbl.Seeds))
	for oi := range lbl.Seeds {
		seedIDs = append(seedIDs, oi)
	}
	sort.Slice(seedIDs, func(i, j int) bool { return seedIDs[i] < seedIDs[j] })
	for _, oi := range seedIDs {
		out = le32(out, oi)
		out = le32(out, math.Float32bits(lbl.Seeds[oi]))
	}
	leaves := make([]int32, 0, len(lbl.Reached))
	for leaf := range lbl.Reached {
		leaves = append(leaves, leaf)
	}
	sort.Slice(leaves, func(i, j int) bool { return leaves[i] < leaves[j] })
	out = le32(out, uint32(len(leaves)))
	for _, leaf := range leaves {
		rl := lbl.Reached[leaf]
		out = le32(out, uint32(leaf))
		flags := uint8(0)
		if rl.Full {
			flags |= 1
		}
		out = append(out, flags)
		n := 0
		for _, a := range rl.EntryArr {
			if a != f32Inf {
				n++
			}
		}
		out = le16(out, uint16(n))
		for i, a := range rl.EntryArr {
			if a != f32Inf {
				out = le16(out, uint16(i))
				out = le32(out, math.Float32bits(a))
			}
		}
	}
	return out
}

// DecodeLabels parses a stored blob against the engine's current artifacts.
// The result has no OriginArr; membership goes through the seed path.
func (e *ReachEngine) DecodeLabels(b []byte) (*ReachLabels, error) {
	if len(b) < 4+8+4+4+4+2 || string(b[:4]) != labelsMagic {
		return nil, fmt.Errorf("bad labels magic")
	}
	p := 4
	rd32 := func() uint32 { v := binary.LittleEndian.Uint32(b[p:]); p += 4; return v }
	rd16 := func() uint16 { v := binary.LittleEndian.Uint16(b[p:]); p += 2; return v }
	if fp := binary.LittleEndian.Uint64(b[p:]); fp != e.partFP {
		return nil, fmt.Errorf("labels were computed against a different partition build (re-run ripple:backfill-reach-labels --all after a partition rebuild)")
	}
	p += 8
	lbl := &ReachLabels{
		Reached: make(map[int32]*RegionLabel),
		Seeds:   make(map[uint32]float32),
	}
	lbl.T = math.Float32frombits(rd32())
	lbl.originChain = NodeID(rd32())
	lbl.seedBase = math.Float32frombits(rd32())
	nSeeds := int(rd16())
	for i := 0; i < nSeeds; i++ {
		if p+8 > len(b) {
			return nil, fmt.Errorf("truncated seeds")
		}
		oi := rd32()
		lbl.Seeds[oi] = math.Float32frombits(rd32())
	}
	if p+4 > len(b) {
		return nil, fmt.Errorf("truncated region count")
	}
	nRegions := int(rd32())
	for r := 0; r < nRegions; r++ {
		if p+7 > len(b) {
			return nil, fmt.Errorf("truncated region header")
		}
		leaf := int32(rd32())
		flags := b[p]
		p++
		n := int(rd16())
		if leaf < 0 || int(leaf) >= len(e.Part.LeafNodes) {
			return nil, fmt.Errorf("label leaf %d out of range (artifact mismatch?)", leaf)
		}
		ents := e.RM.LeafEntries(leaf)
		rl := &RegionLabel{Full: flags&1 != 0, EntryArr: make([]float32, len(ents))}
		for i := range rl.EntryArr {
			rl.EntryArr[i] = f32Inf
		}
		for i := 0; i < n; i++ {
			if p+6 > len(b) {
				return nil, fmt.Errorf("truncated entries")
			}
			ei := int(rd16())
			arr := math.Float32frombits(rd32())
			if ei >= len(rl.EntryArr) {
				return nil, fmt.Errorf("entry index %d out of range for leaf %d", ei, leaf)
			}
			rl.EntryArr[ei] = arr
		}
		lbl.Reached[leaf] = rl
	}
	return lbl, nil
}

func le64(b []byte, v uint64) []byte {
	var t [8]byte
	binary.LittleEndian.PutUint64(t[:], v)
	return append(b, t[:]...)
}

func le32(b []byte, v uint32) []byte {
	var t [4]byte
	binary.LittleEndian.PutUint32(t[:], v)
	return append(b, t[:]...)
}

func le16(b []byte, v uint16) []byte {
	var t [2]byte
	binary.LittleEndian.PutUint16(t[:], v)
	return append(b, t[:]...)
}

// seedArrival returns min over seeds of (seed departure cost + intra-region
// distance seed→j) for a junction j in the same leaf as the seed. This is the
// stored-form replacement for the live query's OriginArr.
func (e *ReachEngine) seedArrival(lbl *ReachLabels, j NodeID) float32 {
	best := f32Inf
	oi := e.Ov.Idx[j]
	if oi == 0 {
		return best
	}
	leaf := e.Part.LeafOf[oi]
	if leaf < 0 {
		return best
	}
	for seedOi, s := range lbl.Seeds {
		if e.Part.LeafOf[seedOi] != leaf {
			continue
		}
		row := e.tables.sourceRow(e, leaf, seedOi)
		if row == nil {
			continue
		}
		t := e.tables.get(e, leaf)
		li, in := t.ls.localOf[oi]
		if !in {
			continue
		}
		if d := row[li]; d != f32Inf && s+d < best {
			best = s + d
		}
	}
	return best
}

// ArrivalFromStored answers the exact arrival at (lat,lng) from a stored
// label blob previously produced by EncodeLabels. Equivalent to Arrival on
// the live query result.
func (e *ReachEngine) ArrivalFromStored(lbl *ReachLabels, lat, lng float64) float32 {
	v := nearestNodeForMode(e.G, lat, lng, Drive)
	if v == noNode {
		return f32Inf
	}
	return e.arrivalAtBaseNodeStored(lbl, v)
}

func (e *ReachEngine) arrivalAtBaseNodeStored(lbl *ReachLabels, v NodeID) float32 {
	junction := func(j NodeID) float32 {
		best := e.junctionArrival(lbl, j)
		if sa := e.seedArrival(lbl, j); sa < best {
			best = sa
		}
		return best
	}
	if e.Ov.Idx[v] != 0 {
		return junction(v)
	}
	best := f32Inf
	if a := e.Ov.ChainEndA[v]; a != 0 && e.Ov.OffFromA[v] >= 0 {
		if ja := junction(a); ja+e.Ov.OffFromA[v] < best {
			best = ja + e.Ov.OffFromA[v]
		}
	}
	if b := e.Ov.ChainEndB[v]; b != 0 && e.Ov.OffFromB[v] >= 0 {
		if jb := junction(b); jb+e.Ov.OffFromB[v] < best {
			best = jb + e.Ov.OffFromB[v]
		}
	}
	if o := lbl.originChain; o != 0 && e.Ov.ChainEndA[o] == e.Ov.ChainEndA[v] && e.Ov.ChainEndB[o] == e.Ov.ChainEndB[v] {
		// Walk-verified: see sameChainDepartCost — end-pair equality alone
		// wrongly matches parallel chains between the same junctions.
		if c := sameChainDepartCost(e.G, e.Ov, o, v); c >= 0 && lbl.seedBase+c < best {
			best = lbl.seedBase + c
		}
	}
	return best
}
