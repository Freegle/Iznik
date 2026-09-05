package isochrone

import "testing"

// The reference close term (1 - driveMin/MaxMinutes, the digest simulator's
// formula) applies whenever a drive time is known; the crow proxy remains the
// fallback for nil - unknown means "use the proxy", never "score zero".
func TestScoreT_DriveMinutesReferenceTerm(t *testing.T) {
	env := ScoreEnv{WindowHours: 24, BudgetDecay: 25, DefaultReachM: 30000, MaxMinutes: 30}
	w := ScoreWeights{Close: 1}

	mins := 15.0
	s := ScoreT(&mins, 999999, 100, 5, 0, 0, false, w, env)
	approxScore(t, "close from 15 of 30 minutes", s.Close, 0.5, 1e-9)

	far := 45.0
	s = ScoreT(&far, 0, 100000, 5, 0, 0, false, w, env)
	approxScore(t, "beyond the horizon clamps", s.Close, 0, 1e-9)
}

func TestScoreT_NilDriveMinutesFallsBackToCrow(t *testing.T) {
	env := ScoreEnv{WindowHours: 24, BudgetDecay: 25, DefaultReachM: 30000, MaxMinutes: 30}
	w := ScoreWeights{Close: 1}

	s := ScoreT(nil, 15000, 30000, 5, 0, 0, false, w, env)
	approxScore(t, "crow proxy for unknown drive time", s.Close, 0.5, 1e-9)
}

func TestScoreT_ZeroHorizonKeepsCrowEvenWithMinutes(t *testing.T) {
	// An env without the horizon cannot apply the reference term.
	env := ScoreEnv{WindowHours: 24, BudgetDecay: 25, DefaultReachM: 30000}
	w := ScoreWeights{Close: 1}

	mins := 10.0
	s := ScoreT(&mins, 15000, 30000, 5, 0, 0, false, w, env)
	approxScore(t, "crow proxy when MaxMinutes unset", s.Close, 0.5, 1e-9)
}

// Score() is ScoreT(nil, ...): the legacy entry keeps its exact behaviour.
func TestScoreDelegatesToScoreTWithNil(t *testing.T) {
	env := ScoreEnv{WindowHours: 24, BudgetDecay: 25, DefaultReachM: 30000, MaxMinutes: 30}
	w := ScoreWeights{Close: 1, Fresh: 1, Budget: 1, Anchor: 1}

	a := Score(12345, 30000, 7, 3, 1, true, w, env)
	b := ScoreT(nil, 12345, 30000, 7, 3, 1, true, w, env)
	if a != b {
		t.Fatalf("Score %+v != ScoreT(nil,...) %+v", a, b)
	}
}
