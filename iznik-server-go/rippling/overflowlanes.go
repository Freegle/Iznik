package rippling

// The lane table shared with the spatial server.
//
// iznik-spatial-go rasterises one item per (post, ring lane) and stamps the
// lane into the item id, because one index has to answer a per-lane question:
// the same post admits a sparse-band member and refuses a dense-band one, on
// different rings. This is the other half of that contract - the decode - and
// the two tables must agree exactly. `dataset_reachoverflow.go` holds the
// original; both sides carry a test asserting the ten pairs verbatim, because a
// disagreement would silently admit members to a lane they are not in, and no
// surface could tell.
//
// Codes are permanent: a retired lane keeps its code rather than letting a
// later lane inherit it, since an index built before the change still holds
// items stamped with it.

// OverflowLaneCodes maps a ring's JSON path to its spatial-index lane code.
var OverflowLaneCodes = map[string]int64{
	"$.rural.dense":  1,
	"$.rural.medium": 2,
	"$.rural.sparse": 3,
	`$.fairness."1"`: 4,
	`$.fairness."2"`: 5,
	`$.fairness."3"`: 6,
	`$.fairness."4"`: 7,
	"$.cluster.w1":   8,
	"$.cluster.w2":   9,
	"$.cluster.w3":   10,
}

// overflowLaneShift matches the spatial server's packing: the low four bits are
// the lane, everything above is the msgid.
const overflowLaneShift = 4

// overflowLaneMask selects the lane code out of a packed id.
const overflowLaneMask = (1 << overflowLaneShift) - 1

// DecodeOverflowExtID splits a spatial item id back into its post and lane
// code. Code 0 is never issued, so a bare msgid that reached here by mistake
// decodes as lane 0 and matches nothing.
func DecodeOverflowExtID(extID int64) (msgid uint64, code int64) {
	if extID <= 0 {
		return 0, 0
	}
	return uint64(extID >> overflowLaneShift), extID & overflowLaneMask
}

// laneCodesFor turns the viewer's ring paths into the set of lane codes that
// may admit them. Paths the spatial server does not know are dropped rather
// than guessed at: an unknown lane is one this index cannot answer, and the
// caller must not treat it as admitted.
func laneCodesFor(paths []string) map[int64]string {
	codes := make(map[int64]string, len(paths))
	for _, p := range paths {
		if code, ok := OverflowLaneCodes[p]; ok {
			codes[code] = p
		}
	}
	return codes
}
