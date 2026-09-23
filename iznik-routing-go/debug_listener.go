package main

// Loopback-only pprof, so the resident set can be attributed rather than
// inferred.
//
// Why this exists: on 2026-08-31 the process was at 98.3% of its 12GiB cgroup
// with 11.59GB of anonymous memory, of which the artifacts and the spatial grid
// account for only about 5.15GB. Everything said about the other half was
// arithmetic, not measurement, because there was no way to ask the runtime.
// One `/debug/pprof/heap` settles it.
//
// Binding: 127.0.0.1 only, never the docker network. The internal port (8194)
// is already unauthenticated and reachable from sibling containers, and pprof
// endpoints are unauthenticated by construction, so they must not go there.
// Loopback means the profile is reachable by `docker exec ... curl` and by
// nothing else. The port is deliberately absent from docker-compose.ports.yml.
//
// fasthttp (what fiber runs on) is not wired to net/http's DefaultServeMux, so
// this has to be its own small stdlib listener rather than a fiber route.

import (
	"log"
	"net/http"
	_ "net/http/pprof" // registers /debug/pprof/* on http.DefaultServeMux
	"runtime"
	"runtime/debug"
	"strconv"
)

// startDebugListener starts the loopback pprof listener unless
// ROUTING_DEBUG_PORT is set to "off". Steady-state cost is a registered handler
// and an idle goroutine; a profile costs only while one is being taken.
func startDebugListener() {
	port := getenv("ROUTING_DEBUG_PORT", "6060")
	if port == "off" || port == "" {
		log.Printf("spatial-server: pprof listener disabled")
		return
	}
	addr := "127.0.0.1:" + port

	mux := http.DefaultServeMux
	// A one-line summary for when a full profile is more than the question
	// needs: `curl 127.0.0.1:6060/debug/memsummary`.
	mux.HandleFunc("/debug/memsummary", func(w http.ResponseWriter, r *http.Request) {
		var ms runtime.MemStats
		runtime.ReadMemStats(&ms)
		limit := debug.SetMemoryLimit(-1) // read-only probe
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		for _, kv := range [][2]string{
			{"HeapAlloc", strconv.FormatUint(ms.HeapAlloc, 10)},
			{"HeapSys", strconv.FormatUint(ms.HeapSys, 10)},
			{"HeapInuse", strconv.FormatUint(ms.HeapInuse, 10)},
			{"HeapIdle", strconv.FormatUint(ms.HeapIdle, 10)},
			{"HeapReleased", strconv.FormatUint(ms.HeapReleased, 10)},
			{"NextGC", strconv.FormatUint(ms.NextGC, 10)},
			{"NumGC", strconv.FormatUint(uint64(ms.NumGC), 10)},
			{"GCCPUFraction", strconv.FormatFloat(ms.GCCPUFraction, 'g', 6, 64)},
			{"Sys", strconv.FormatUint(ms.Sys, 10)},
			{"GOGC", getenv("GOGC", "(unset, default 100)")},
			{"MemoryLimit", strconv.FormatInt(limit, 10)},
		} {
			if _, err := w.Write([]byte(kv[0] + " " + kv[1] + "\n")); err != nil {
				return
			}
		}
	})

	srv := &http.Server{Addr: addr, Handler: mux}
	go func() {
		log.Printf("spatial-server: pprof listener on %s (loopback only)", addr)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			// Never fatal: a debug listener that cannot bind must not stop the
			// server from serving.
			log.Printf("spatial-server: pprof listener stopped: %v", err)
		}
	}()
}
