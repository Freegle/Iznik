package embedding

import (
	"container/list"
	"os"
	"strconv"
	"sync"
)

const defaultQueryCacheSize = 10_000

type lruCache struct {
	mu    sync.Mutex
	cap   int
	items map[string]*list.Element
	list  *list.List
}

type cacheEntry struct {
	key string
	vec []float32
}

var queryCache *lruCache

func init() {
	size := defaultQueryCacheSize
	if s := os.Getenv("EMBEDDING_QUERY_CACHE_SIZE"); s != "" {
		if n, err := strconv.Atoi(s); err == nil && n >= 0 {
			size = n
		}
	}
	queryCache = newLRUCache(size)
}

func newLRUCache(cap int) *lruCache {
	return &lruCache{
		cap:   cap,
		items: make(map[string]*list.Element),
		list:  list.New(),
	}
}

func (c *lruCache) get(key string) ([]float32, bool) {
	if c.cap == 0 {
		return nil, false
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	el, ok := c.items[key]
	if !ok {
		return nil, false
	}
	c.list.MoveToFront(el)
	return el.Value.(*cacheEntry).vec, true
}

func (c *lruCache) set(key string, vec []float32) {
	if c.cap == 0 {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	if el, ok := c.items[key]; ok {
		c.list.MoveToFront(el)
		el.Value.(*cacheEntry).vec = vec
		return
	}
	if c.list.Len() >= c.cap {
		back := c.list.Back()
		if back != nil {
			c.list.Remove(back)
			delete(c.items, back.Value.(*cacheEntry).key)
		}
	}
	el := c.list.PushFront(&cacheEntry{key: key, vec: vec})
	c.items[key] = el
}

func (c *lruCache) reset() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.items = make(map[string]*list.Element)
	c.list = list.New()
}

// ResetQueryCache clears the query embedding cache. Used in tests.
func ResetQueryCache() {
	queryCache.reset()
}
