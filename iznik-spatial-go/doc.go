// Package main Freegle Spatial (KNN) API
//
// A spatial API for finding nearby geographic features using K-nearest-neighbour
// and polygon-within queries backed by an in-memory spatial index.
//
//	Schemes: http
//	BasePath: /
//	Version: 1.0.0
//	Produces:
//	- application/json
//
// swagger:meta
package main

// swagger:route GET /health health spatialHealthCheck
//
// Health check
//
// Returns {"status":"ok"} when the service is up. Used by Docker healthchecks.
//
// Responses:
//
//	200: genericResponse

// swagger:route GET /v1/{dataset}/knn spatial knnQuery
//
// K-nearest-neighbour query
//
// Returns the nearest records in a dataset to a given lat/lng point.
// Optionally filtered by feature type and/or a polygon boundary.
// Response: {"results": [{"id": int64, "distance": float64, "extra": {...}}]}
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name (e.g. locations, messages, userapproxlocs, jobs, groups, postcodes, newsfeed)
//     required: true
//     type: string
//   + name: lng
//     in: query
//     description: Longitude of query point
//     required: true
//     type: number
//     format: double
//   + name: lat
//     in: query
//     description: Latitude of query point
//     required: true
//     type: number
//     format: double
//   + name: limit
//     in: query
//     description: Number of results to return (1–1000, default 1)
//     required: false
//     type: integer
//     minimum: 1
//     maximum: 1000
//   + name: type
//     in: query
//     description: Optional feature-type filter (dataset-specific, e.g. "Postcode" for locations)
//     required: false
//     type: string
//   + name: polygon
//     in: query
//     description: WKT polygon to restrict results to (optional; use POST /within_coords for large polygons)
//     required: false
//     type: string
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	500: errorResponse
//	503: errorResponse

// swagger:route GET /v1/{dataset}/containing spatial containingQuery
//
// Point containment query
//
// Returns every item in the dataset whose geometry contains the given point.
// Only datasets implementing PointContainer (currently reach) support it.
// `in` are items the point is definitely inside; `partial` items sit in the
// boundary band of a rasterised geometry and the caller must resolve them
// against the exact source geometry to be sure.
// Response: {"in": [int64...], "partial": [int64...]}
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name (must implement PointContainer, e.g. reach)
//     required: true
//     type: string
//   + name: lng
//     in: query
//     description: Longitude of query point
//     required: true
//     type: number
//     format: double
//   + name: lat
//     in: query
//     description: Latitude of query point
//     required: true
//     type: number
//     format: double
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	500: errorResponse
//	501: errorResponse
//	503: errorResponse

// swagger:route POST /v1/{dataset}/within_coords spatial withinCoordsPost
//
// Within polygon — return items with coordinates (POST)
//
// Returns full item objects (including extra fields such as coordinates) for all
// records whose geometry falls inside the given WKT polygon.
// Use this POST form for large isochrone polygons that exceed safe URL length limits.
// Response: {"results": [{"extra": {...}}]}
//
// Accepts Content-Type: text/plain (raw WKT body) or
// application/x-www-form-urlencoded with field polygon=<WKT>.
//
// Binds to SPATIAL_PORT (default 8194).
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name (e.g. userapproxlocs)
//     required: true
//     type: string
//   + name: body
//     in: body
//     description: WKT polygon as raw text body (Content-Type text/plain) or polygon=<WKT> form field
//     required: true
//     schema:
//       type: string
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	413: errorResponse
//	500: errorResponse
//	503: errorResponse

// swagger:route POST /v1/{dataset}/rebuild admin rebuildDataset
//
// Rebuild dataset (admin)
//
// Triggers an asynchronous full rebuild of the named dataset from MySQL.
// The rebuild runs in a background goroutine; the endpoint returns immediately.
// Response on 200: {"status":"rebuilding","dataset":"<name>"}
//
// IMPORTANT: this route is served on SPATIAL_ADMIN_PORT (default 8195), not the
// public SPATIAL_PORT (default 8194). It must not be exposed to the public network.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name (e.g. locations, messages, userapproxlocs)
//     required: true
//     type: string
//
// Responses:
//
//	200: genericResponse
//	404: errorResponse
//	409: errorResponse

// swagger:route POST /v1/{dataset}/remove admin removeDatasetIDs
//
// Remove IDs from dataset (admin)
//
// Performs an incremental hard-delete of specific record IDs from the spatial index.
// Request body: {"ids": [<int64>, ...]}
// Response on 200: {"removed": <count>}
//
// IMPORTANT: this route is served on SPATIAL_ADMIN_PORT (default 8195), not the
// public SPATIAL_PORT (default 8194). It must not be exposed to the public network.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name
//     required: true
//     type: string
//   + name: body
//     in: body
//     description: JSON object with an "ids" array of int64 IDs to remove
//     required: true
//     schema:
//       type: object
//       properties:
//         ids:
//           type: array
//           items:
//             type: integer
//             format: int64
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	503: errorResponse

// swagger:route POST /v1/{dataset}/upsert admin upsertDatasetItems
//
// Upsert items into dataset (admin)
//
// Inserts or replaces specific items in the spatial index by WKT geometry.
// Intended for integration tests: seeds a known geometry into the live index
// (decoupled from the nightly MySQL rebuild) and removes it afterwards.
// Request body: {"items": [{"id": <int64>, "wkt": "<WKT string>", "extra": {...}}]}
// Response on 200: {"upserted": <count>}
// Returns 400 if the request body is malformed or the WKT is invalid.
// Returns 500 if the index cannot be lazily created.
// Returns 503 if the dataset is not ready (should not normally occur for this endpoint
// since it lazily ensures an index exists).
//
// IMPORTANT: this route is served on SPATIAL_ADMIN_PORT (default 8195), not the
// public SPATIAL_PORT (default 8194). It must not be exposed to the public network.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name (e.g. locations, messages, userapproxlocs)
//     required: true
//     type: string
//   + name: body
//     in: body
//     description: JSON object with an "items" array; each item has id (int64), wkt (WKT polygon or point string), and optional extra (arbitrary JSON object)
//     required: true
//     schema:
//       type: object
//       properties:
//         items:
//           type: array
//           items:
//             type: object
//             properties:
//               id:
//                 type: integer
//                 format: int64
//               wkt:
//                 type: string
//               extra:
//                 type: object
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	500: errorResponse
//	503: errorResponse

// genericResponse is a generic JSON response.
// The actual fields depend on the endpoint; see each route's description for the exact shape.
// swagger:response genericResponse
type genericResponse struct {
	// in:body
	Body interface{}
}

// errorResponse is a JSON error response containing a single "error" field.
// swagger:response errorResponse
type errorResponse struct {
	// in:body
	Body struct {
		Error string `json:"error"`
	}
}
