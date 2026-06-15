package isochrone

import (
	"net/http"
	"time"
)

// isochroneHTTPClient is shared by the Mapbox and routing-server callers. Both
// previously constructed an identical per-call client; sharing one reuses
// connections and keeps the 60s timeout defined in a single place.
var isochroneHTTPClient = &http.Client{Timeout: 60 * time.Second}
