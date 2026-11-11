package ux

import (
	"sync"
	"time"
)

// The UX tab asks the same few questions of the same rollup tables every time a support person
// opens it, and the answers change once a day. This is the dashboard package's cache pattern
// (a TTL cache with single-flight, see dashboard/cache.go), in a smaller form: concurrent callers
// asking the identical question wait for the one computation instead of each starting their own.

const (
	cacheTTL        = 10 * time.Minute
	cacheMaxEntries = 500
)

type cacheEntry struct {
	val     interface{}
	expires time.Time
}

type inflightCall struct {
	done chan struct{}
	val  interface{}
	err  error
}

var (
	cacheMu       sync.Mutex
	cache         = map[string]cacheEntry{}
	cacheInflight = map[string]*inflightCall{}
)

// ResetCache drops every cached answer. Tests use it, and so would a deploy that changed a query.
func ResetCache() {
	cacheMu.Lock()
	defer cacheMu.Unlock()
	cache = map[string]cacheEntry{}
}

// cached serves key from the cache, joins an identical in-flight computation, or runs compute. An
// answer is stored only when compute reports it cacheable (no error, nothing degraded), and only
// if there is room: a full cache of live entries is never emptied to make space.
func cached(key string, compute func() (interface{}, bool, error)) (interface{}, error) {
	cacheMu.Lock()
	if e, ok := cache[key]; ok && time.Now().Before(e.expires) {
		cacheMu.Unlock()
		return e.val, nil
	}
	if call, ok := cacheInflight[key]; ok {
		cacheMu.Unlock()
		<-call.done
		return call.val, call.err
	}
	call := &inflightCall{done: make(chan struct{})}
	cacheInflight[key] = call
	cacheMu.Unlock()

	// Released in a defer so a panic in compute still frees the waiters.
	cacheable := false
	defer func() {
		cacheMu.Lock()
		delete(cacheInflight, key)
		if cacheable && call.err == nil {
			if len(cache) >= cacheMaxEntries {
				now := time.Now()
				for k, e := range cache {
					if !now.Before(e.expires) {
						delete(cache, k)
					}
				}
			}
			if len(cache) < cacheMaxEntries {
				cache[key] = cacheEntry{val: call.val, expires: time.Now().Add(cacheTTL)}
			}
		}
		cacheMu.Unlock()
		close(call.done)
	}()

	call.val, cacheable, call.err = compute()
	return call.val, call.err
}
