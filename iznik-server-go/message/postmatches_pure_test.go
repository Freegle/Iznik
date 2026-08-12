package message

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// oppositeType decides what the matched-posts email searches for. Only the Offer
// branch was exercised; the other two matter just as much:
//
//   - if Wanted stopped mapping to Offer, a wanted post would be matched against
//     other wanted posts, pairing a request with a request
//   - if the default stopped returning "", a type that has no opposite (Taken,
//     Received, Admin...) would enter matching and pull in arbitrary candidates
func TestOppositeTypeOfferIsWanted(t *testing.T) {
	assert.Equal(t, "Wanted", oppositeType("Offer"))
}

func TestOppositeTypeWantedIsOffer(t *testing.T) {
	assert.Equal(t, "Offer", oppositeType("Wanted"))
}

func TestOppositeTypeOfAnythingElseIsEmptySoMatchingIsSkipped(t *testing.T) {
	for _, in := range []string{"Taken", "Received", "Admin", "", "offer", "OFFER"} {
		assert.Equal(t, "", oppositeType(in), "type %q has no opposite and must not match", in)
	}
}
