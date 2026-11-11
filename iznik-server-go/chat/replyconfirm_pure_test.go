package chat

import (
	"strings"
	"testing"
)

func TestIsChildCarSeat(t *testing.T) {
	yes := []string{
		"OFFER: Child car seat (SW1)",
		"OFFER: Maxi-Cosi carseat and base",
		"OFFER: Booster Seat, age 4+",
		"OFFER: ISOFIX base only",
		"OFFER: Infant carrier",
		"OFFER: Child car safety seat",
		"OFFER: Children’s car seats (BS35)",
		"OFFER: Booster seats for car (G74)",
		"OFFER: Cybex Aton B2 car seat & ISO fix (HP3)",
		// A seat as well as a cover is still a seat.
		"OFFER: Car seat and seat cover",
		"OFFER: Car seat with rain cover",
	}
	for _, s := range yes {
		if !isChildCarSeat(s) {
			t.Errorf("%q should be a child car seat", s)
		}
	}

	no := []string{
		"OFFER: Garden seat",
		"OFFER: Car roof box",
		"OFFER: Seat pads x4",
		"OFFER: Child's bike",
		"",
		// Live subjects the plain substring test caught (Discourse 9939/73).
		"OFFER: Child carrier bag (BN1)",
		"WANTED: Child carrier backpack (S41)",
		"OFFER: Two car seat covers (SE26)",
		"OFFER: Dog-Hammock Type Car Seat Cover (CT2)",
		"OFFER: Joie black baby car seat cover (HP2)",
		"WANTED: Dog car seat and baby play gym (AL3)",
	}
	for _, s := range no {
		if isChildCarSeat(s) {
			t.Errorf("%q should not be a child car seat", s)
		}
	}
}

func TestChildCarSeatQuestionIsAddressedAndAsks(t *testing.T) {
	q := childCarSeatQuestion("Pat")
	if !strings.HasPrefix(q, "@Pat, ") {
		t.Errorf("addressed to the replier: %s", q)
	}
	for _, want := range []string{"child car seat", "never been in an accident", "five years", "childcarseats.org.uk", "Do you still want it?"} {
		if !strings.Contains(q, want) {
			t.Errorf("question should say %q: %s", want, q)
		}
	}
	if strings.Contains(q, "experiment") {
		t.Errorf("the question is policy, and must not read as an experiment: %s", q)
	}
}
