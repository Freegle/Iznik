package rippling

import (
	"encoding/json"
	"fmt"
	"net/http"
	"time"
)

var quintileHTTPClient = &http.Client{Timeout: 2 * time.Second}

// QuintileFor is a point's deprivation fifth: 1 = most deprived, 5 = least, 0 = we cannot say.
//
// Asked of the routing server rather than stored, because that is the only place the data
// exists - it holds the index for the fairness isochrone itself. Asking keeps it that way:
// nothing else has to carry deprivation data, and nothing anywhere records what fifth a given
// person is in.
//
// Called at most ONCE per browse feed load, not once per post, and only when the deprivation
// lane is switched on.
//
// Returns 0 on any failure - unreachable, slow, malformed, or an index that is not loaded.
// Callers must treat 0 as "not eligible", so an outage costs the lane its extra posts rather
// than showing everyone inside a stretched ring, which is the behaviour the fifth exists to
// prevent. Timeout is short for the same reason: the feed is worth more than the lane.
func QuintileFor(lat, lng float64) int {
	url := fmt.Sprintf("%s/v1/quintile?lat=%f&lng=%f", routingInternalURL(), lat, lng)

	resp, err := quintileHTTPClient.Get(url)
	if err != nil {
		return 0
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return 0
	}

	var body struct {
		Quintile  int  `json:"quintile"`
		Available bool `json:"available"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil || !body.Available {
		return 0
	}
	if body.Quintile < 1 || body.Quintile > 5 {
		return 0
	}

	return body.Quintile
}
