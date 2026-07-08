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
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route POST /v1/ripple-eval routing postRippleEval
//
// Evaluate ripple schedule
//
// Accepts a proposed ripple schedule as JSON and returns evaluation metrics.
//
// Responses:
//
//	200: routingGenericResponse
//	400: routingErrorResponse

// swagger:route GET /v1/posts-for-member routing getPostsForMember
//
// Posts reachable by a member
//
// Returns freegle posts whose origin is reachable within the given travel time
// from the member's location, for a given date window.
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
//   + name: max_minutes
//     in: query
//     description: Maximum travel time in minutes
//     required: true
//     type: number
//     format: double
//   + name: date
//     in: query
//     description: Date filter (YYYY-MM-DD)
//     required: true
//     type: string
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
// optionally grouping results by poster.
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
