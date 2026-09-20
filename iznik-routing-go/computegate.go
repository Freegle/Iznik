package main

import (
	"log"
	"os"
	"runtime"
	"strconv"
	"time"

	"github.com/gofiber/fiber/v2"
)

// computeGate bounds how many graph computations run at once.
//
// Every Dijkstra holds maps sized by the reached area — a long drive-mode run
// reaches millions of nodes, hundreds of MB per computation. Concurrency was
// previously unbounded, so on 2026-08-27, when a cron pile-up on the batch
// host turned into a burst of group-proximity calls whose callers had long
// since given up, this process accumulated ~26GB (8.3G RSS + 17.3G swap) of
// concurrent working sets and swapped the host to a standstill, three times
// in one day.
//
// Bounding makes overload QUEUE instead: a waiter is a parked goroutine
// costing a few KB, and at most `slots` working sets exist at any moment.
// There is deliberately no timeout on the wait — timing work out server-side
// converts a slow answer into an error the caller retries, changing nothing;
// a queued caller applies its own patience. The long-wait log line is
// visibility, not enforcement.
//
// ROUTING_MAX_CONCURRENT overrides the slot count (default: NumCPU). Note
// /v1/isochrone fans out one Dijkstra per requested mode inside its one slot,
// so the worst case is slots × modes working sets — still bounded.
var computeGate = newGate(computeSlots())

func computeSlots() int {
	if v := os.Getenv("ROUTING_MAX_CONCURRENT"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n >= 1 {
			return n
		}
		log.Printf("compute gate: ignoring invalid ROUTING_MAX_CONCURRENT=%q", v)
	}
	n := runtime.NumCPU()
	if n < 1 {
		n = 1
	}
	return n
}

type gate chan struct{}

func newGate(n int) gate { return make(gate, n) }

// enter blocks until a computation slot is free. Waits are expected to be
// momentary; anything over 5s means the gate is saturated and is logged so a
// building queue is visible in the log before it is visible as latency.
func (g gate) enter() {
	select {
	case g <- struct{}{}:
		return
	default:
	}
	start := time.Now()
	g <- struct{}{}
	if wait := time.Since(start); wait > 5*time.Second {
		log.Printf("compute gate: waited %.1fs for a slot (%d slots saturated)", wait.Seconds(), cap(g))
	}
}

func (g gate) exit() { <-g }

// gated wraps a handler that runs graph computations so it holds a compute
// slot for the duration of the request. Applied at the route table, so the
// full set of bounded routes is visible in one place.
func gated(h fiber.Handler) fiber.Handler {
	return func(c *fiber.Ctx) error {
		computeGate.enter()
		defer computeGate.exit()
		return h(c)
	}
}
