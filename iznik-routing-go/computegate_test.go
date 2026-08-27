package main

import (
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// The gate must never admit more than its slot count at once, and must admit
// exactly one waiter per exit. Proven by hammering a 2-slot gate from 20
// goroutines and watching the high-water mark of concurrent holders.
func TestGateBoundsConcurrency(t *testing.T) {
	g := newGate(2)

	var inside, highWater int32
	var wg sync.WaitGroup

	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			g.enter()
			defer g.exit()

			now := atomic.AddInt32(&inside, 1)
			for {
				prev := atomic.LoadInt32(&highWater)
				if now <= prev || atomic.CompareAndSwapInt32(&highWater, prev, now) {
					break
				}
			}
			time.Sleep(5 * time.Millisecond)
			atomic.AddInt32(&inside, -1)
		}()
	}
	wg.Wait()

	if hw := atomic.LoadInt32(&highWater); hw > 2 {
		t.Fatalf("gate admitted %d concurrent holders; capacity is 2", hw)
	}
	if hw := atomic.LoadInt32(&highWater); hw != 2 {
		t.Fatalf("gate should have reached its full capacity of 2 under load; high water was %d", hw)
	}
}

// A waiter blocked on a full gate must be released when a holder exits —
// queueing, not rejection.
func TestGateWaiterAdmittedOnExit(t *testing.T) {
	g := newGate(1)
	g.enter()

	admitted := make(chan struct{})
	go func() {
		g.enter()
		close(admitted)
		g.exit()
	}()

	select {
	case <-admitted:
		t.Fatal("waiter admitted while the only slot was held")
	case <-time.After(20 * time.Millisecond):
	}

	g.exit()

	select {
	case <-admitted:
	case <-time.After(2 * time.Second):
		t.Fatal("waiter never admitted after the slot was released")
	}
}

// Slot sizing: the env override wins when valid and is ignored when not.
func TestComputeSlotsEnvOverride(t *testing.T) {
	t.Setenv("ROUTING_MAX_CONCURRENT", "3")
	if n := computeSlots(); n != 3 {
		t.Fatalf("expected 3 slots from override, got %d", n)
	}

	t.Setenv("ROUTING_MAX_CONCURRENT", "0")
	if n := computeSlots(); n < 1 {
		t.Fatalf("invalid override must fall back to a positive default, got %d", n)
	}

	t.Setenv("ROUTING_MAX_CONCURRENT", "banana")
	if n := computeSlots(); n < 1 {
		t.Fatalf("non-numeric override must fall back to a positive default, got %d", n)
	}
}
