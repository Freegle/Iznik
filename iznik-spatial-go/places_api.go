package main

// GET /api — photon-compatible forward geocoding over the places index.
// The response shape is byte-compatible with what the consumers parse:
// WhatJobsService (properties.extent [W,N,E,S], properties.name equality
// gate), PlaceAutocomplete.vue and the leaflet-control-geocoder Photon class
// (geometry.coordinates [lng,lat], extent, name/county/state props).
// CORS is answered here because production nginx proxies OPTIONS straight
// through, exactly as it did to photon.

import (
	"database/sql"
	"encoding/json"
	"regexp"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
)

type placeGeometry struct {
	Coordinates [2]float64 `json:"coordinates"` // [lng, lat]
	Type        string     `json:"type"`
}

type placeProps struct {
	OsmID       int64     `json:"osm_id"`
	OsmType     string    `json:"osm_type"`
	OsmKey      string    `json:"osm_key"`
	OsmValue    string    `json:"osm_value"`
	Type        string    `json:"type"`
	Name        string    `json:"name"`
	Extent      []float64 `json:"extent,omitempty"` // [W,N,E,S]
	County      string    `json:"county,omitempty"`
	State       string    `json:"state,omitempty"`
	Country     string    `json:"country"`
	CountryCode string    `json:"countrycode"`
}

type placeFeature struct {
	Geometry   placeGeometry `json:"geometry"`
	Type       string        `json:"type"`
	Properties placeProps    `json:"properties"`
}

func setPlacesCORS(c *fiber.Ctx) {
	c.Set("Access-Control-Allow-Origin", "*")
	c.Set("Access-Control-Allow-Headers", "*")
}

// placesDB serves the postcode lookup path; nil (e.g. in handler tests)
// simply disables it and postcode-shaped queries fall through to the index.
var placesDB *sql.DB

func registerPlacesRoutes(app *fiber.App, db *sql.DB) {
	placesDB = db
	app.Options("/api", func(c *fiber.Ctx) error {
		setPlacesCORS(c)
		c.Set("Access-Control-Allow-Methods", "GET")
		return c.SendString("OK")
	})
	app.Get("/api", placesAPIHandler)
}

// ukPostcodeCompact matches a full UK postcode with the spaces removed:
// outward (area letters, district digit, optional digit/letter) + inward
// (digit + two letters). Partial/outward-only queries are NOT matched — they
// fall through to the place-name index like any other text.
var ukPostcodeCompact = regexp.MustCompile(`^[A-Z]{1,2}[0-9][0-9A-Z]?[0-9][A-Z]{2}$`)

// normalizeUKPostcode canonicalises q ("b160lr", "S5  9fe") to the form the
// locations table stores ("B16 0LR"), or ok=false when q is not a full UK
// postcode.
func normalizeUKPostcode(q string) (string, bool) {
	compact := strings.ToUpper(strings.Join(strings.Fields(q), ""))
	if !ukPostcodeCompact.MatchString(compact) {
		return "", false
	}

	return compact[:len(compact)-3] + " " + compact[len(compact)-3:], true
}

// lookupPostcode answers a full-postcode query from the platform's own
// locations table (every UK postcode, name-indexed) — Photon resolved
// postcodes from OSM, the OSM-places index deliberately does not carry them,
// and members type their postcode into the app's place search hundreds of
// times a day. Misses (and any DB error) return nil so the caller falls
// through to the ordinary index search.
func lookupPostcode(name string) *placeFeature {
	if placesDB == nil {
		return nil
	}

	var id int64
	var lat, lng float64
	err := placesDB.QueryRow(
		`SELECT locations.id,
		        ST_Y(ST_Centroid(COALESCE(ourgeometry, geometry))),
		        ST_X(ST_Centroid(COALESCE(ourgeometry, geometry)))
		 FROM locations
		 LEFT JOIN locations_excluded le ON locations.id = le.locationid
		 WHERE le.locationid IS NULL
		   AND locations.type = 'Postcode'
		   AND locations.name = ?
		 LIMIT 1`, name).Scan(&id, &lat, &lng)
	if err != nil {
		return nil
	}

	return &placeFeature{
		Geometry: placeGeometry{Coordinates: [2]float64{lng, lat}, Type: "Point"},
		Type:     "Feature",
		Properties: placeProps{
			OsmID:       id,
			OsmType:     "N",
			OsmKey:      "place",
			OsmValue:    "postcode",
			Type:        "postcode",
			Name:        name,
			Country:     "United Kingdom",
			CountryCode: "GB",
		},
	}
}

func placesAPIHandler(c *fiber.Ctx) error {
	setPlacesCORS(c)

	ix := placesIdx.Load()
	if ix == nil {
		return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "places index not loaded"})
	}

	q := c.Query("q")
	if strings.TrimSpace(q) == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "missing search term 'q'"})
	}

	// Full UK postcodes come from the platform's locations table, not OSM.
	// After the index-loaded gate above, so instances without the places
	// artifact (the database hosts) keep answering 503 to everything.
	if name, ok := normalizeUKPostcode(q); ok {
		if f := lookupPostcode(name); f != nil {
			return c.JSON(fiber.Map{
				"type":     "FeatureCollection",
				"features": []placeFeature{*f},
			})
		}
	}

	opts := searchOpts{limit: c.QueryInt("limit", placesDefaultLimit)}

	// bbox=swlng,swlat,nelng,nelat — the map components send it with spaces
	// after the commas, so trim each part. Unparsable bboxes are ignored
	// rather than rejected: every live consumer sends a valid one.
	if bb := c.Query("bbox"); bb != "" {
		parts := strings.Split(bb, ",")
		if len(parts) == 4 {
			var vals [4]float64
			okAll := true
			for i, p := range parts {
				v, err := strconv.ParseFloat(strings.TrimSpace(p), 64)
				if err != nil {
					okAll = false
					break
				}
				vals[i] = v
			}
			if okAll && vals[0] < vals[2] && vals[1] < vals[3] {
				opts.bbox = &vals
			}
		}
	}

	// Repeated layer= params (WhatJobs sends city, locality and district for
	// town lookups).
	if layerArgs := c.Context().QueryArgs().PeekMulti("layer"); len(layerArgs) > 0 {
		opts.layers = map[string]bool{}
		for _, l := range layerArgs {
			opts.layers[string(l)] = true
		}
	}

	// lat/lon/zoom map-centre bias, sent by leaflet-control-geocoder.
	if latS, lonS := c.Query("lat"), c.Query("lon"); latS != "" && lonS != "" {
		lat, errLat := strconv.ParseFloat(latS, 64)
		lon, errLon := strconv.ParseFloat(lonS, 64)
		if errLat == nil && errLon == nil {
			opts.biasLat, opts.biasLng = &lat, &lon
			opts.biasZoom = c.QueryInt("zoom", 14)
		}
	}

	results := ix.search(q, opts)

	features := make([]placeFeature, 0, len(results))
	for _, r := range results {
		e := r.e
		props := placeProps{
			OsmID:       e.ID,
			OsmType:     e.OsmType,
			OsmKey:      e.Key,
			OsmValue:    e.Value,
			Type:        e.Layer,
			Name:        e.Name,
			County:      e.County,
			State:       e.State,
			Country:     "United Kingdom",
			CountryCode: "GB",
		}
		if len(e.Extent) == 4 {
			props.Extent = e.Extent
		}
		features = append(features, placeFeature{
			Geometry:   placeGeometry{Coordinates: [2]float64{e.Lng, e.Lat}, Type: "Point"},
			Type:       "Feature",
			Properties: props,
		})
	}

	body, err := json.Marshal(struct {
		Features []placeFeature `json:"features"`
		Type     string         `json:"type"`
	}{Features: features, Type: "FeatureCollection"})
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
	}
	c.Set("Content-Type", "application/json;charset=utf-8")
	return c.Send(body)
}
