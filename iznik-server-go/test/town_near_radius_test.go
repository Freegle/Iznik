package test

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// Open sea west of the Hebrides: nothing the curated towns table (~234 major places) could
// ever put inside the handler's candidate box, at any slider position.
const townNearNoTowns = "lat=57.0&lng=-8.0&minutes=5"

// The distance slider is a travel-time budget, and the client converts it to the mile radius
// that every distance filter reads (settings.browseMaxDistance) from reach_radius_miles. That
// radius is the isochrone's road frontier; the town names beside it are display material for
// the "Near: ..." hint. So an empty towns box must still yield a radius.
//
// It did not: the handler returned before the routing call whenever no curated town fell inside
// the box, which at the narrow end of the slider is most of the country. The client read the
// missing radius as a failed derivation and stored the "no limit" sentinel, so a member who
// dragged the slider to "Nearer" had every distance filter switched off instead - on browse, on
// the unread badge, in search and in their post emails (Discourse 10096, a Hastings member whose
// nearest curated town is Lewes, 27 miles away, mailed posts from Eastbourne).
func TestTownNearDerivesRadiusWithNoCandidateTowns(t *testing.T) {
	seen := stubRouting(t, false)

	body := townNear(t, townNearNoTowns)

	// The routing call is what produces the frontier, so it has to happen at all.
	assert.Len(t, *seen, 1, "the routing server must be asked even with no candidate towns")
	assert.Equal(t, true, (*seen)[0]["frontier"])

	radius, ok := body["reach_radius_miles"].(float64)
	assert.True(t, ok, "reach_radius_miles must be present: %v", body)
	assert.Equal(t, 14.7, radius)

	// The hint itself has nothing to show, which is the whole point: it hides, and the
	// radius survives.
	towns, ok := body["towns"].([]interface{})
	assert.True(t, ok, "towns must be present: %v", body)
	assert.Empty(t, towns)
}

// The shape the browse map shades comes from the same routing pass, so it must survive an empty
// towns box too - otherwise the map is blank for exactly the members the hint already fails.
func TestTownNearReturnsPolygonWithNoCandidateTowns(t *testing.T) {
	stubRouting(t, true)

	body := townNear(t, townNearNoTowns+"&polygon=1")

	polygon, ok := body["reach_polygon"].(map[string]interface{})
	assert.True(t, ok, "reach_polygon must be present: %v", body)
	assert.Equal(t, "Feature", polygon["type"])
}
