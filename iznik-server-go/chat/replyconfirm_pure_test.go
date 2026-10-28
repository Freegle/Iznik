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
