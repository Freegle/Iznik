package assistant

import (
	"sync"
	"time"
)

// Answerbot's count-based escalation for wandering or hostile turns. Level 1: warm
// acknowledgement, stay put. Level 2: gently steer to what Freegle can do. Level 3:
// soft close, buttons stay up.
func EscalationLevel(unclassified int) int {
	switch {
	case unclassified <= 0:
		return 0
	case unclassified <= 2:
		return 1
	case unclassified <= 4:
		return 2
	default:
		return 3
	}
}

func EscalationInstruction(level int) string {
	switch level {
	case 1:
		return "Their last message was not something Freegle can act on. Acknowledge it warmly in a few words and stay where you are; do not repeat your last question word for word."
	case 2:
		return "They have wandered off the point a couple of times. Be friendly and steer gently back to what Freegle can do: give something, find something, see what is nearby."
	case 3:
		return "They have wandered for a while. Wind down kindly in one short line and say you are here whenever they want to give or find something. Leave it there."
	}
	return ""
}

// Strikes counts abusive turns per identity; three in the window locks the model out.
type Strikes struct {
	mu     sync.Mutex
	limit  int
	window time.Duration
	now    func() time.Time
	m      map[string][]time.Time
}

func NewStrikes(limit int, window time.Duration, now func() time.Time) *Strikes {
	if now == nil {
		now = time.Now
	}
	return &Strikes{limit: limit, window: window, now: now, m: map[string][]time.Time{}}
}

func (s *Strikes) prune(key string, t time.Time) []time.Time {
	var keep []time.Time
	for _, x := range s.m[key] {
		if t.Sub(x) < s.window {
			keep = append(keep, x)
		}
	}
	s.m[key] = keep
	return keep
}

// Record adds a strike and returns the count in the window.
func (s *Strikes) Record(key string) int {
	s.mu.Lock()
	defer s.mu.Unlock()
	t := s.now()
	list := append(s.prune(key, t), t)
	s.m[key] = list
	return len(list)
}

// Locked reports whether the identity has hit the limit.
func (s *Strikes) Locked(key string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	return len(s.prune(key, s.now())) >= s.limit
}
