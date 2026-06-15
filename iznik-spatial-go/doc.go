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
// Returns the health status of the spatial service.
//
// Responses:
//
//	200: genericResponse

// swagger:route GET /v1/datasets spatial listDatasets
//
// List datasets
//
// Returns all available datasets with their names, record counts, and readiness status.
//
// Responses:
//
//	200: genericResponse

// swagger:route GET /v1/{dataset}/status spatial getDatasetStatus
//
// Get dataset status
//
// Returns the readiness, record count and last-sync time for a named dataset.
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

// swagger:route GET /v1/{dataset}/knn spatial knnQuery
//
// K-nearest-neighbour query
//
// Returns the nearest records in a dataset to a given lat/lng point.
// Optionally filtered by feature type and/or a polygon boundary.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name
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
//     description: Optional feature-type filter
//     required: false
//     type: string
//   + name: polygon
//     in: query
//     description: WKT polygon to restrict results to (optional)
//     required: false
//     type: string
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	503: errorResponse

// swagger:route GET /v1/{dataset}/within spatial withinQuery
//
// Within polygon — return IDs
//
// Returns the IDs of all records whose geometry falls inside the given WKT polygon.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name
//     required: true
//     type: string
//   + name: polygon
//     in: query
//     description: WKT polygon (required)
//     required: true
//     type: string
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	503: errorResponse

// swagger:route GET /v1/{dataset}/within_coords spatial withinCoordsGet
//
// Within polygon — return items with coordinates (GET)
//
// Returns full item objects (including coordinates) for records inside the polygon.
// Use POST for large polygons that exceed URL length limits.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name
//     required: true
//     type: string
//   + name: polygon
//     in: query
//     description: WKT polygon (required)
//     required: true
//     type: string
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	503: errorResponse

// swagger:route POST /v1/{dataset}/within_coords spatial withinCoordsPost
//
// Within polygon — return items with coordinates (POST)
//
// Same as GET /within_coords but accepts the polygon in the request body to
// avoid URL length limits for large isochrone polygons.
// Body may be raw WKT (Content-Type: text/plain) or form-encoded (polygon=WKT).
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name
//     required: true
//     type: string
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	503: errorResponse

// swagger:route POST /v1/{dataset}/rebuild admin rebuildDataset
//
// Rebuild dataset (admin)
//
// Triggers an asynchronous full rebuild of the named dataset from MySQL.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name
//     required: true
//     type: string
//
// Responses:
//
//	200: genericResponse
//	404: errorResponse
//	409: errorResponse

// swagger:route POST /v1/rebuild admin rebuildAllDatasets
//
// Rebuild all datasets (admin)
//
// Triggers an asynchronous full rebuild of every dataset from MySQL.
//
// Responses:
//
//	200: genericResponse

// swagger:route POST /v1/{dataset}/remove admin removeDatasetIDs
//
// Remove IDs from dataset (admin)
//
// Performs an incremental hard-delete of specific record IDs from the spatial index.
//
// Parameters:
//   + name: dataset
//     in: path
//     description: Dataset name
//     required: true
//     type: string
//
// Responses:
//
//	200: genericResponse
//	400: errorResponse
//	404: errorResponse
//	503: errorResponse

// genericResponse is a generic JSON response
// swagger:response genericResponse
type genericResponse struct {
	// in:body
	Body interface{}
}

// errorResponse is a JSON error response
// swagger:response errorResponse
type errorResponse struct {
	// in:body
	Body struct {
		Error string `json:"error"`
	}
}
