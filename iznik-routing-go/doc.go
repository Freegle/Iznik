// Package main Freegle Routing & Isochrone API
//
// A routing and isochrone service for finding reachable areas from a given
// location using walk, cycle, and drive travel modes based on OpenStreetMap data.
//
//	Schemes: http
//	BasePath: /
//	Version: 1.0.0
//	Produces:
//	- application/json
//
// swagger:meta
package main

// swagger:route GET /health health routingHealthCheck
//
// Health check
//
// Returns the health status of the routing service including the number of
// loaded graph nodes.
//
// Responses:
//
//	200: routingGenericResponse

// swagger:route GET /v1/isochrone routing getIsochrone
//
// Compute isochrone
//
// Computes walk, cycle, and drive isochrone polygons for a given lat/lng point
// and travel-time budget.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of origin point (required)
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of origin point (required)
//     required: true
//     type: number
//     format: double
//   + name: minutes
//     in: query
//     description: Travel-time budget in minutes (default 15, max 120)
//     required: false
//     type: number
//     format: double
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/fairness routing getFairnessIsochrone
//
// Fairness-weighted isochrone
//
// Computes a fairness-adjusted isochrone that down-weights densely-connected
// areas relative to sparse areas, improving equity of reach estimates.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: minutes
//     in: query
//     description: Travel-time budget in minutes (default 15)
//     required: false
//     type: number
//     format: double
//   + name: fairness
//     in: query
//     description: Fairness weighting factor 0–1 (default 0)
//     required: false
//     type: number
//     format: double
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default walk)
//     required: false
//     type: string
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/nearby-freeglers routing getNearbyFreeglers
//
// Nearby freeglers
//
// Computes the isochrone polygon for the origin and returns all freeglers whose
// approximate location falls within it. Results are capped at 2000 points.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: minutes
//     in: query
//     description: Travel-time budget in minutes (default 15)
//     required: false
//     type: number
//     format: double
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default walk)
//     required: false
//     type: string
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/catchment routing getCatchment
//
// Group inbound catchment
//
// Returns the inbound catchment polygon for a group: the area from which a post
// would ripple far enough to reach it. Supply either groupid (to seed from the
// group's entire boundary polygon) or lat+lng (for an ad-hoc single-point
// catchment). Returns {catchment, bands, seeds} for the groupid form and
// {catchment} for the point form.
//
// Parameters:
//   + name: groupid
//     in: query
//     description: Group ID to compute catchment for (mutually exclusive with lat/lng)
//     required: false
//     type: integer
//   + name: lat
//     in: query
//     description: Latitude of origin point (mutually exclusive with groupid)
//     required: false
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of origin point (mutually exclusive with groupid)
//     required: false
//     type: number
//     format: double
//   + name: minutes
//     in: query
//     description: Travel-time budget in minutes (default 30, max 120)
//     required: false
//     type: number
//     format: double
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default drive)
//     required: false
//     type: string
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse
//	404: routingErrorResponse

// swagger:route GET /v1/group-proximity routing getGroupProximity
//
// Group road proximity
//
// For an offer at (lat, lng) rippling into groupid, returns the nearest
// in-group road point and the point furthest from it, each with drive-time.
// Also returns quicker=true when the offer is closer to the near edge than
// the group spans internally. Returns {reachable:false} when no path exists
// within max_minutes.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of the offer location
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of the offer location
//     required: true
//     type: number
//     format: double
//   + name: groupid
//     in: query
//     description: Group ID to measure proximity to
//     required: true
//     type: integer
//   + name: max_minutes
//     in: query
//     description: Maximum drive-time to consider in minutes (default 120)
//     required: false
//     type: number
//     format: double
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default drive)
//     required: false
//     type: string
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse
//	404: routingErrorResponse

// swagger:route GET /v1/drive-time routing getDriveTime
//
// Road drive-time between two points
//
// The road time from (lat, lng) to (tolat, tolng), as a single number of
// minutes. Used to answer "when will this post's reach expand to cover me":
// the site compares the member's drive time from the post's origin against
// the drive-time budget stored on each tick of the post's reach schedule.
// Returns {reachable:false} when no path exists within max_minutes, which is
// a real answer rather than an error.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of the origin point
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of the origin point
//     required: true
//     type: number
//     format: double
//   + name: tolat
//     in: query
//     description: Latitude of the destination point
//     required: true
//     type: number
//     format: double
//   + name: tolng
//     in: query
//     description: Longitude of the destination point
//     required: true
//     type: number
//     format: double
//   + name: max_minutes
//     in: query
//     description: Maximum drive-time to consider in minutes (default 60, max 120)
//     required: false
//     type: number
//     format: double
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default drive)
//     required: false
//     type: string
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/group-extent routing getGroupExtent
//
// Group road diameter
//
// Returns the widest road drive-time between two points inside the group —
// the group's internal "diameter" as a travel-time yardstick. Returns
// {reachable:false} when no two points are road-connected within max_minutes.
//
// Parameters:
//   + name: groupid
//     in: query
//     description: Group ID to compute extent for
//     required: true
//     type: integer
//   + name: max_minutes
//     in: query
//     description: Maximum drive-time to consider in minutes (default 240)
//     required: false
//     type: number
//     format: double
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default drive)
//     required: false
//     type: string
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse
//	404: routingErrorResponse

// swagger:route GET /v1/group-actives routing getGroupActives
//
// Group active-member count
//
// Returns the 90-day-active approved-member count for a group and the
// Stage-A audience target N* derived from it. Result is cached in-process
// for approximately one hour.
//
// Parameters:
//   + name: groupid
//     in: query
//     description: Group ID to query
//     required: true
//     type: integer
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse
//	503: routingErrorResponse

// swagger:route GET /v1/reachable-groups routing getReachableGroups
//
// Reachable freegle groups
//
// Returns the IDs of freegle groups that have at least one active member
// whose home location is road-reachable from the given origin within the
// travel-time budget. Groups where members are technically inside the
// isochrone but separated by severed crossings (rivers, motorways) are
// excluded. reachable_group_ids is always a non-null array.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: minutes
//     in: query
//     description: Travel-time budget in minutes (default 30, max 120)
//     required: false
//     type: number
//     format: double
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default drive)
//     required: false
//     type: string
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/ripple-schedule routing getRippleSchedule
//
// Ripple send schedule
//
// Returns a time-bucketed schedule of how many freeglers are reachable at each
// travel-time step from the origin, used to pace ripple email sends.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of origin point
//     required: true
//     type: number
//     format: double
//   + name: ticks
//     in: query
//     description: Number of time steps (default 30)
//     required: false
//     type: integer
//   + name: max_minutes
//     in: query
//     description: Maximum travel time in minutes (default 30)
//     required: false
//     type: number
//     format: double
//   + name: curve
//     in: query
//     description: Send-curve shape (e.g. step-70; default step-70)
//     required: false
//     type: string
//   + name: mode
//     in: query
//     description: Travel mode (walk, cycle, drive; default drive)
//     required: false
//     type: string
//   + name: polygons
//     in: query
//     description: Set to 0 to omit the per-tick polygon geometry (slim form for the batch; each tick keeps drive_min, cumulative_users and reachable_group_ids)
//     required: false
//     type: string
//   + name: target_users
//     in: query
//     description: Stage-A audience-budget cap; when >0 limits the schedule to the N nearest freeglers (default 0 = no cap)
//     required: false
//     type: integer
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route POST /v1/ripple-eval routing postRippleEval
//
// Evaluate reach for a post origin and point set
//
// Accepts a JSON body {lat, lng, mode, max_minutes, points[][2]float64} and
// returns, for each input point, the road drive-time from the post origin
// and the point's rank among all reachable freeglers (1 = nearest). Used by
// the simulator to evaluate at which send-tick a historical replier would
// have been notified. points are [lng, lat] GeoJSON order.
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/digest-simulator routing getDigestSimulator
//
// Digest email simulator
//
// Simulates the content of a digest email for a member at a given location,
// scoring and ranking nearby posts by closeness, freshness, and budget-decay.
// Optionally groups results by poster.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of member's location
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of member's location
//     required: true
//     type: number
//     format: double
//   + name: group_by_poster
//     in: query
//     description: Whether to group results by poster (true/false)
//     required: false
//     type: boolean
//   + name: max_minutes
//     in: query
//     description: Maximum drive-time isochrone in minutes (default 30, max 120)
//     required: false
//     type: number
//     format: double
//   + name: w_closeness
//     in: query
//     description: Closeness scoring weight (default 1.0)
//     required: false
//     type: number
//     format: double
//   + name: w_freshness
//     in: query
//     description: Freshness scoring weight (default 0.5)
//     required: false
//     type: number
//     format: double
//   + name: w_budget
//     in: query
//     description: Budget scoring weight (default 1.0)
//     required: false
//     type: number
//     format: double
//   + name: w_anchor
//     in: query
//     description: Anchor scoring weight (default 0)
//     required: false
//     type: number
//     format: double
//   + name: cap
//     in: query
//     description: Maximum posts to select (default 65, mirrors DIGEST_POST_CAP)
//     required: false
//     type: integer
//   + name: window_hours
//     in: query
//     description: Lookback window in hours for posts (default 24, max 168)
//     required: false
//     type: number
//     format: double
//   + name: budget_decay
//     in: query
//     description: Budget decay rate (default 25)
//     required: false
//     type: number
//     format: double
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/groups/nearby routing getNearbyGroups
//
// Nearby groups
//
// Returns a GeoJSON FeatureCollection of freegle groups whose area overlaps
// with or is near the given lat/lng point.
//
// Parameters:
//   + name: lat
//     in: query
//     description: Latitude of query point
//     required: true
//     type: number
//     format: double
//   + name: lng
//     in: query
//     description: Longitude of query point
//     required: true
//     type: number
//     format: double
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/groups/list routing getGroupsList
//
// All publishable groups
//
// Returns every publishable Freegle group with a polygon as a flat array of
// {id, name, lat, lng} objects, ordered by short name. Used by the catchment
// tab's group picker. Returns [] (not an error) when the database is
// unavailable.
//
// Responses:
//
//	200: routingGenericResponse

// routingGenericResponse is a generic JSON response
// swagger:response routingGenericResponse
type routingGenericResponse struct {
	// in:body
	Body interface{}
}

// routingErrorResponse is a JSON error response
// swagger:response routingErrorResponse
type routingErrorResponse struct {
	// in:body
	Body struct {
		Error string `json:"error"`
	}
}
