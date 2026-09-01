package main

// Reach-engine isochrone materialisation: expand a labeling to the same
// "reached nodes with arrival seconds" map the flat full-graph search
// produces, so everything downstream of an isochrone - the catchment polygon,
// its drive-time bands, the sandwich bounds, the fairness weighting - runs
// unchanged on top of a 1-2ms label query plus table lookups instead of a
// 0.3-2s Dijkstra over the whole edge list.
//
// The expansion is exact for junctions (the same entry-arrival + region-table
// arithmetic the membership queries use, verified against the flat search on
// 10.3M points), and reconstructs chain-interior nodes from their end
// junctions plus the overlay's stored per-node offsets - the same values the
// contraction folded in, so interior arrivals equal the flat search's too.

// ReachedNodes expands a labeling to arrival seconds for every base node
// reached within limit. Junction arrivals come from the region tables (one
// entries×nodes pass per reached region); chain interiors from a single
// linear pass over the chain table.
func (e *ReachEngine) ReachedNodes(lbl *ReachLabels, limit float32) map[NodeID]float32 {
	// Size the hint to the reach actually being expanded, not to a nationwide
	// one. The old unconditional 1<<17 hint built a 131,072-entry bucket
	// skeleton (~2.6MB) on every call, including the 15-minute local
	// catchments that are the common case. Go grows the map past any hint, so
	// this changes nothing but the up-front allocation.
	hint := 4096
	if n := len(lbl.Reached); n > 0 {
		// Leaves cap at 10,000 nodes, but a typical reached leaf contributes far
		// fewer; 2,048 per leaf tracks the real distribution without over-sizing
		// small reaches, and large reaches simply grow.
		if want := n * 2048; want > hint {
			hint = want
		}
		if hint > 1<<17 {
			hint = 1 << 17
		}
	}
	out := make(map[NodeID]float32, hint)

	// Region interiors: min over reached entries of (entry arrival + stored
	// intra-region distance). Regions are disjoint, so no cross-leaf clashes.
	for leaf, rl := range lbl.Reached {
		t := e.tables.get(e, leaf)
		arr := make([]float32, len(t.nodes))
		for i := range arr {
			arr[i] = f32Inf
		}
		for i, ea := range rl.EntryArr {
			if ea == f32Inf || ea > limit {
				continue
			}
			row := t.dist[i]
			for j, d := range row {
				if a := ea + d; a < arr[j] {
					arr[j] = a
				}
			}
		}
		for j, oi := range t.nodes {
			if a := arr[j]; a <= limit {
				out[e.Ov.BaseNode[oi]] = a
			}
		}
	}

	// The origin's seed region(s): the live query's exact internal arrivals
	// (these cover the interior even when no entry was reached from outside,
	// and can undercut an entry-derived arrival near the origin).
	for oi, a := range lbl.OriginArr {
		if a > limit {
			continue
		}
		v := e.Ov.BaseNode[oi]
		if cur, ok := out[v]; !ok || a < cur {
			out[v] = a
		}
	}

	// Chain interiors: every absorbed node's arrival is its best reachable
	// end-junction arrival plus the contraction's stored end→node offset.
	// One linear pass, no search.
	for v := 1; v < len(e.Ov.ChainEndA); v++ {
		a := e.Ov.ChainEndA[v]
		if a == 0 {
			continue
		}
		best := f32Inf
		if offA, okA := e.Ov.OffA(NodeID(v)); okA {
			if ja, ok := out[a]; ok {
				if c := ja + offA; c < best {
					best = c
				}
			}
		}
		if b := e.Ov.ChainEndB[v]; b != 0 {
			if offB, okB := e.Ov.OffB(NodeID(v)); okB {
				if jb, ok := out[b]; ok {
					if c := jb + offB; c < best {
						best = c
					}
				}
			}
		}
		if best <= limit {
			id := NodeID(v)
			if cur, ok := out[id]; !ok || best < cur {
				out[id] = best
			}
		}
	}

	// A mid-chain ORIGIN reaches its own chain segment directly (before any
	// junction): the flat search seeds there, so must we.
	if lbl.originChain != 0 {
		e.refineOriginChain(out, lbl.originChain, lbl.seedBase, limit)
	}

	return out
}

// refineOriginChain overlays the direct along-chain arrivals from a mid-chain
// seed onto the reached map: the flat search seeds there, so every node of
// the seed's own chain is reachable at seedBase plus the walk along it, which
// can undercut the via-a-junction reconstruction (or reach nodes the junction
// route misses entirely on a one-way loop). A bounded walk along the chain's
// own out-edges - a handful of nodes, no search.
func (e *ReachEngine) refineOriginChain(out map[NodeID]float32, origin NodeID, seedBase, limit float32) {
	set := func(v NodeID, c float32) {
		if c > limit {
			return
		}
		if cur, ok := out[v]; !ok || c < cur {
			out[v] = c
		}
	}
	set(origin, seedBase)
	for _, first := range e.G.EdgesFrom(origin) {
		cost := seedBase + first.Sec()
		v := first.To
		prev := origin
		for e.Ov.Idx[v] == 0 && cost <= limit {
			set(v, cost)
			next := noNode
			var step float32
			for _, ed := range e.G.EdgesFrom(v) {
				if ed.To != prev {
					next = ed.To
					step = ed.Sec()
					break
				}
			}
			if next == noNode {
				break
			}
			prev = v
			v = next
			cost += step
		}
	}
}

// MergeLabels folds src into dst as an elementwise minimum: the labeling of
// "reachable from ANY of the merged origins", which is exactly what a
// multi-source isochrone computes. Merging at the LABEL level (a few KB per
// origin) is what makes the group-boundary catchment cheap: one expansion of
// the merged label instead of one full reached-map per seed.
func MergeLabels(dst, src *ReachLabels) {
	if src.T > dst.T {
		dst.T = src.T
	}
	for leaf, srl := range src.Reached {
		drl, ok := dst.Reached[leaf]
		if !ok {
			dst.Reached[leaf] = srl
			continue
		}
		for i, a := range srl.EntryArr {
			if a < drl.EntryArr[i] {
				drl.EntryArr[i] = a
			}
		}
		if len(srl.EntryMet) == len(drl.EntryMet) {
			for i, m := range srl.EntryMet {
				if srl.EntryArr[i] <= drl.EntryArr[i] && m < drl.EntryMet[i] {
					drl.EntryMet[i] = m
				}
			}
		}
		if srl.Full {
			drl.Full = true
		}
	}
	for oi, a := range src.OriginArr {
		if cur, ok := dst.OriginArr[oi]; !ok || a < cur {
			dst.OriginArr[oi] = a
			if m, ok := src.OriginMet[oi]; ok {
				dst.OriginMet[oi] = m
			}
		}
	}
	for oi, a := range src.Seeds {
		if cur, ok := dst.Seeds[oi]; !ok || a < cur {
			dst.Seeds[oi] = a
			if m, ok := src.SeedMet[oi]; ok {
				dst.SeedMet[oi] = m
			}
		}
	}
}
