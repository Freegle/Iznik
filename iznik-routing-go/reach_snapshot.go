package main

// Reach engine snapshot: a fast binary cache of the built base graph + overlay so
// prototype iterations load in seconds instead of re-parsing the PBF (~90s+).
// Raw same-architecture slice dumps with a magic+version header — this is a
// local build artifact (like the PBF itself), not a wire format. The monthly
// full-build artifact lifecycle from the parent plan will formalise this.

import (
	"bufio"
	"encoding/binary"
	"fmt"
	"io"
	"log"
	"os"
	"time"
	"unsafe"
)

const graphSnapMagic = "FRGS2SNAP"

// graphSnapVersion 2: drive-only, quantised, padding-free.
//
//	Node loses Quintile to a parallel slice (12B -> 8B)
//	Edge loses walk/cycle and becomes {To uint32; Secs uint16} (16B -> 8B),
//	  and edges no car can use are not stored at all
//	OverlayEdge becomes {To uint32; Secs uint16; Metres uint16} (20B -> 8B),
//	  likewise pruned to drive
//	OffFromA/B become uint16 deciseconds (4B -> 2B per node)
//	Idx and ChainEndA merge into one Ref array, being mutually exclusive
//	DriveSnappable is not stored: it is a pure function of the drive edges and
//	  is recomputed at load by the same code the build uses
//
// A version mismatch is refused, which sends the caller down the rebuild path
// rather than reading v1 bytes through v2 strides - every field after the first
// changed one would be cascading garbage.
const graphSnapVersion = uint32(2)

func writeSlice[T any](w io.Writer, s []T) error {
	var t T
	sz := int(unsafe.Sizeof(t))
	if err := binary.Write(w, binary.LittleEndian, uint64(len(s))); err != nil {
		return err
	}
	if len(s) == 0 {
		return nil
	}
	b := unsafe.Slice((*byte)(unsafe.Pointer(&s[0])), len(s)*sz)
	_, err := w.Write(b)
	return err
}

func readSlice[T any](r io.Reader) ([]T, error) {
	var t T
	sz := int(unsafe.Sizeof(t))
	var n uint64
	if err := binary.Read(r, binary.LittleEndian, &n); err != nil {
		return nil, err
	}
	s := make([]T, n)
	if n == 0 {
		return s, nil
	}
	b := unsafe.Slice((*byte)(unsafe.Pointer(&s[0])), int(n)*sz)
	_, err := io.ReadFull(r, b)
	return s, err
}

// SaveReachSnapshot writes the base graph + overlay to path.
func SaveReachSnapshot(path string, g *Graph, ov *Overlay) error {
	start := time.Now()
	f, err := os.Create(path)
	if err != nil {
		return err
	}
	defer f.Close()
	w := bufio.NewWriterSize(f, 1<<20)

	if _, err := w.WriteString(graphSnapMagic); err != nil {
		return err
	}
	if err := binary.Write(w, binary.LittleEndian, graphSnapVersion); err != nil {
		return err
	}
	if err := writeSlice(w, g.Nodes); err != nil {
		return err
	}
	if err := writeSlice(w, g.Quintile); err != nil {
		return err
	}
	if err := writeSlice(w, g.EdgeStart); err != nil {
		return err
	}
	if err := writeSlice(w, g.Edges); err != nil {
		return err
	}
	if err := writeSlice(w, ov.BaseNode); err != nil {
		return err
	}
	if err := writeSlice(w, ov.Ref); err != nil {
		return err
	}
	if err := writeSlice(w, ov.EdgeStart); err != nil {
		return err
	}
	if err := writeSlice(w, ov.Edges); err != nil {
		return err
	}
	if err := writeSlice(w, ov.ChainEndB); err != nil {
		return err
	}
	if err := writeSlice(w, ov.OffFromA); err != nil {
		return err
	}
	if err := writeSlice(w, ov.OffFromB); err != nil {
		return err
	}
	if err := w.Flush(); err != nil {
		return err
	}
	fi, _ := f.Stat()
	log.Printf("reach: snapshot saved to %s (%.1fMB) in %v", path, float64(fi.Size())/1e6, time.Since(start).Round(time.Millisecond))
	return nil
}

// LoadReachSnapshot reads a snapshot and rebuilds the derived spatial grid.
func LoadReachSnapshot(path string) (*Graph, *Overlay, error) {
	start := time.Now()
	f, err := os.Open(path)
	if err != nil {
		return nil, nil, err
	}
	defer f.Close()
	r := bufio.NewReaderSize(f, 1<<20)

	magic := make([]byte, len(graphSnapMagic))
	if _, err := io.ReadFull(r, magic); err != nil {
		return nil, nil, err
	}
	if string(magic) != graphSnapMagic {
		return nil, nil, fmt.Errorf("bad snapshot magic %q", magic)
	}
	var ver uint32
	if err := binary.Read(r, binary.LittleEndian, &ver); err != nil {
		return nil, nil, err
	}
	if ver != graphSnapVersion {
		return nil, nil, fmt.Errorf("snapshot version %d, want %d", ver, graphSnapVersion)
	}

	g := &Graph{}
	ov := &Overlay{}
	if g.Nodes, err = readSlice[Node](r); err != nil {
		return nil, nil, err
	}
	if g.Quintile, err = readSlice[Quintile](r); err != nil {
		return nil, nil, err
	}
	if g.EdgeStart, err = readSlice[int32](r); err != nil {
		return nil, nil, err
	}
	if g.Edges, err = readSlice[Edge](r); err != nil {
		return nil, nil, err
	}
	if ov.BaseNode, err = readSlice[NodeID](r); err != nil {
		return nil, nil, err
	}
	if ov.Ref, err = readSlice[uint32](r); err != nil {
		return nil, nil, err
	}
	if ov.EdgeStart, err = readSlice[int32](r); err != nil {
		return nil, nil, err
	}
	if ov.Edges, err = readSlice[OverlayEdge](r); err != nil {
		return nil, nil, err
	}
	if ov.ChainEndB, err = readSlice[NodeID](r); err != nil {
		return nil, nil, err
	}
	if ov.OffFromA, err = readSlice[uint16](r); err != nil {
		return nil, nil, err
	}
	if ov.OffFromB, err = readSlice[uint16](r); err != nil {
		return nil, nil, err
	}

	// Rebuild what is derived rather than storing it: the grid, and the
	// drive-snappable set (a pure function of the drive edges, computed by the
	// same code the build uses, so the two cannot drift).
	n := g.NodeCount()
	g.Grid = buildGrid(g.Nodes)
	g.DriveSnappable = computeDriveSnappable(g)
	log.Printf("reach: snapshot loaded from %s in %v (%d nodes, %d overlay junctions)",
		path, time.Since(start).Round(time.Millisecond), n, ov.NodeCount())
	return g, ov, nil
}
