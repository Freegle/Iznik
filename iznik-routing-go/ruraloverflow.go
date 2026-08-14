package main

// The rural-access overflow lane.
//
// The audience cap (target_users, the extent governor) sizes a post's reach by the NEAREST N
// members. In a dense area that binds long before the travel-time ceiling does, and a member
// just outside the headcount can be comfortably inside the travel time their own settings say
// they will travel. Measured on live 2026-08-14: a sparse-band moderator 31.4 minutes from a
// Birmingham post whose reach stopped at 28.0 minutes on exactly 4000 members, with his own
// slider already at the 45-minute maximum. Nothing about his preferences excluded him; a
// headcount taken around the POST did.
//
// This is the same argument PR #1308 already settled for the TIME cap ("the cap belongs to the
// person who would travel, not to the item"), applied to the headcount.
//
// The lane ships one ring per density-band ceiling alongside the committed reach. A member is
// admitted to a post if they fall inside the ring for their OWN band. It changes nothing that
// is pushed: no extra mail, no extra group copies. It only lets a member who goes looking find
// a post their own band already entitles them to.
//
// Deliberately NOT a fix for deprivation equity. Measured the same day: reaches where the cap
// binds are already close to quintile parity (Q1 20.7% against a 20.2% population share), so
// widening them does nothing for fairness. The deprivation deficit lives in the reaches the cap
// never touches. Two different problems, two different mechanisms.

// ruralBandCeilings are the density-band travel-time ceilings, in minutes, that a member's own
// browseMaxMinutes is drawn from (dense 20 / medium 30 / sparse 45 - see the DensityService
// bands in iznik-batch). One ring per band, so the read side can pick the ring matching the
// member's band without the routing server needing to know anything about individual members.
var ruralBandCeilings = []struct {
	name    string
	minutes float64
}{
	{"dense", 20},
	{"medium", 30},
	{"sparse", 45},
}

// ruralOverflowRings slices one polygon per band ceiling out of an ALREADY-COMPUTED reached-node
// set. There is no second Dijkstra: since PR #1308 every post routes to the ceiling anyway, so
// the wider rings are already paid for.
//
// Returns nil when there is nothing worth shipping, so the caller can omit the field entirely
// rather than send empty geometry.
//
// ceilingMinutes is how far the Dijkstra actually ran. Rings beyond it cannot be honoured (the
// nodes simply are not there), so they are clamped to it; a clamped ring is still correct, just
// no wider than what was routed.
func ruralOverflowRings(g *Graph, reached map[NodeID]float32, res float64, ceilingMinutes float64) map[string]*GeoJSONPolygon {
	if len(reached) == 0 || res <= 0 {
		return nil
	}

	out := make(map[string]*GeoJSONPolygon, len(ruralBandCeilings))
	for _, band := range ruralBandCeilings {
		limit := band.minutes
		if limit > ceilingMinutes {
			limit = ceilingMinutes
		}
		limitSecs := float32(limit * 60)

		filtered := make(map[NodeID]float32, len(reached))
		for nid, t := range reached {
			if t <= limitSecs {
				filtered[nid] = t
			}
		}
		if len(filtered) == 0 {
			continue
		}

		poly := IsochronePolygon(g, filtered, res)
		if len(poly.Geometry.Coordinates) == 0 || len(poly.Geometry.Coordinates[0]) < 4 {
			continue
		}
		p := poly
		out[band.name] = &p
	}

	if len(out) == 0 {
		return nil
	}
	return out
}
