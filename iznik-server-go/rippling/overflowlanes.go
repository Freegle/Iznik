package rippling

// The lanes a viewer can be in, as the spatial server names them.
//
// This is deliberately NOT a copy of the server's lane table. The server stamps
// each ring with its lane and filters on request (ContainingFiltered), so a
// caller only has to say which lanes it is in - the same path strings
// ViewerOverflowPaths already produces. Nothing here has to be kept in step with
// anything there beyond the strings themselves, which are the lane's identity.
//
// The list exists only to refuse a lane the server would reject: an unknown lane
// is a 400 there, and asking with one would cost this viewer every OTHER lane's
// posts too. Dropping it locally keeps the rest of the answer.
var knownLaneSet = map[string]struct{}{
	"$.rural.dense":  {},
	"$.rural.medium": {},
	"$.rural.sparse": {},
	`$.fairness."1"`: {},
	`$.fairness."2"`: {},
	`$.fairness."3"`: {},
	`$.fairness."4"`: {},
	"$.cluster.w1":   {},
	"$.cluster.w2":   {},
	"$.cluster.w3":   {},
}

// knownLanes keeps the paths the ring index can answer for, in the order given.
func knownLanes(paths []string) []string {
	lanes := make([]string, 0, len(paths))
	for _, p := range paths {
		if _, ok := knownLaneSet[p]; ok {
			lanes = append(lanes, p)
		}
	}
	return lanes
}
