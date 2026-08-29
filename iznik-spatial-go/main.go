package main

import (
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"os"
	"strconv"
	"strings"

	_ "github.com/go-sql-driver/mysql"
	"github.com/gofiber/fiber/v2"
	"github.com/peterstace/simplefeatures/geom"

	"spatial-server/cellset"
)

func main() {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true",
		getenv("MYSQL_USER", "iznik"),
		getenv("MYSQL_PASSWORD", "iz"),
		getenv("MYSQL_HOST", "localhost"),
		getenv("MYSQL_PORT", "3306"),
		getenv("MYSQL_DBNAME", "iznik"),
	)

	mysqlDB, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatalf("mysql open: %v", err)
	}
	defer mysqlDB.Close()

	forceRebuild := flag.Bool("rebuild", false, "force a full index rebuild from MySQL on startup")
	flag.Parse()

	idxDir := getenv("SPATIAL_INDEX_DIR", "/data")

	allDatasets := []Dataset{
		&LocationsDataset{},
		&MessagesDataset{},
		&NewsfeedDataset{},
		&UserApproxLocsDataset{},
		&GroupsDataset{},
		&JobsDataset{},
		&PostcodesDataset{},
		&ReachDataset{},
		&ReachOverflowDataset{},
	}

	srv := newServer(mysqlDB, idxDir, allDatasets)
	srv.startupLoad(*forceRebuild)
	srv.startScheduler()

	// Public API — 256 KB read buffer to accommodate large polygon WKT query params.
	api := fiber.New(fiber.Config{
		DisableStartupMessage: true,
		ReadBufferSize:        256 * 1024,
	})

	api.Get("/health", func(c *fiber.Ctx) error {
		return c.JSON(fiber.Map{"status": "ok"})
	})

	// GET /v1/datasets
	api.Get("/v1/datasets", func(c *fiber.Ctx) error {
		type datasetInfo struct {
			Name  string `json:"name"`
			Count int64  `json:"count"`
			Ready bool   `json:"ready"`
		}
		var infos []datasetInfo
		for name, state := range srv.datasets {
			info := datasetInfo{Name: name}
			_ = state.withIndex(func(idx *Index) error {
				info.Ready = true
				if n, err := idx.CountRows(); err == nil {
					info.Count = n
				}
				return nil
			})
			infos = append(infos, info)
		}
		return c.JSON(fiber.Map{"datasets": infos})
	})

	// GET /v1/:dataset/status
	api.Get("/v1/:dataset/status", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}
		var n int64
		err := state.withIndex(func(idx *Index) error {
			var e error
			n, e = idx.CountRows()
			return e
		})
		if err == errIndexNotReady {
			return c.JSON(fiber.Map{"ready": false, "count": 0})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		return c.JSON(fiber.Map{
			"ready":     true,
			"count":     n,
			"last_sync": state.lastSync,
		})
	})

	// GET /v1/:dataset/containing — all items whose geometry contains the point.
	// Only datasets implementing PointContainer (reach, reachoverflow) support it.
	// `in` are definite; `partial` items sit in the raster's boundary band and
	// the caller must resolve them against the exact source geometry.
	api.Get("/v1/:dataset/containing", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}
		pc, ok := state.ds.(PointContainer)
		if !ok {
			return c.Status(fiber.StatusNotImplemented).JSON(fiber.Map{"error": "dataset does not support containing queries"})
		}

		lng := c.QueryFloat("lng", 0)
		lat := c.QueryFloat("lat", 0)
		if lng == 0 && lat == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "lng and lat required"})
		}

		// `lanes` selects on whatever category the dataset stamps its items with
		// (the overflow rings' lane paths) and returns plain ids. Without it the
		// dataset's own ids come back, encoding and all.
		lanes := strings.Split(c.Query("lanes", ""), ",")
		if len(lanes) == 1 && lanes[0] == "" {
			lanes = nil
		}
		pcf, filtered := state.ds.(PointContainerFiltered)

		var in, partial []int64
		didFilter := false
		err := state.withIndex(func(idx *Index) error {
			var e error
			if lanes != nil && filtered {
				in, partial, e = pcf.ContainingFiltered(idx, lng, lat, lanes)
				didFilter = e == nil
				return e
			}
			in, partial, e = pc.Containing(idx, lng, lat)
			return e
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err != nil && strings.HasPrefix(err.Error(), "unknown overflow lane") {
			// A lane the index does not know is the caller and the index
			// disagreeing about what lanes exist. Said out loud: answering
			// "nobody" would let a surface quietly stop admitting people the
			// others still do.
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		// Empty slices, not nulls, so clients can range without nil checks.
		if in == nil {
			in = []int64{}
		}
		if partial == nil {
			partial = []int64{}
		}
		// `filtered` says whether the ids are the dataset's own (packed, in the
		// reachoverflow case) or plain external ids narrowed to the lanes asked
		// for. A caller that asked for lanes and is answered by a server too old
		// to know the parameter would otherwise read packed ids as msgids and
		// admit entirely the wrong posts - silently, and only for the members a
		// ring covers. Absent means "not filtered", which is what an old server
		// implicitly says.
		return c.JSON(fiber.Map{"in": in, "partial": partial, "filtered": didFilter})
	})

	// POST /v1/reachoverflow/admits — the ring question from the MAIL's end:
	// one post, many candidate members, which of them does its ring admit?
	// Body: {"msgid": N, "points": [{"lng": x, "lat": y, "lanes": ["$.rural.sparse"]}]}
	// Returns the INDEXES of the admitted points, so the caller keeps whatever
	// it had attached to them.
	api.Post("/v1/reachoverflow/admits", func(c *fiber.Ctx) error {
		state, ok := srv.getDataset("reachoverflow")
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}
		ds, ok := state.ds.(*ReachOverflowDataset)
		if !ok {
			return c.Status(fiber.StatusNotImplemented).JSON(fiber.Map{"error": "dataset does not answer admits"})
		}

		var body struct {
			Msgid  int64       `json:"msgid"`
			Points []LanePoint `json:"points"`
		}
		if err := c.BodyParser(&body); err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "bad body: " + err.Error()})
		}
		if body.Msgid <= 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "msgid required"})
		}
		if len(body.Points) == 0 {
			return c.JSON(fiber.Map{"admitted": []int{}})
		}

		var admitted []int
		err := state.withIndex(func(idx *Index) error {
			var e error
			admitted, e = ds.AdmitsPoints(idx, body.Msgid, body.Points)
			return e
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err != nil && strings.HasPrefix(err.Error(), "unknown overflow lane") {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		if admitted == nil {
			admitted = []int{}
		}
		return c.JSON(fiber.Map{"admitted": admitted})
	})

	// POST /v1/reach/rasterize — WKT in (raw text/plain body), the compact
	// cellset form out (application/octet-stream). This is the only place a
	// rippling reach polygon is converted to its canonical stored
	// representation (plans/2026-08-24-rippling-reach-raster-storage.md) -
	// callers store the returned bytes verbatim; they never rasterise it
	// themselves.
	api.Post("/v1/reach/rasterize", func(c *fiber.Ctx) error {
		out, err := rasterizeWKT(string(c.Body()))
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}
		c.Set("Content-Type", "application/octet-stream")
		return c.Send(out)
	})

	// POST /v1/reach/vectorize — the inverse valve: encoded cell bytes in
	// (application/octet-stream), a boundary out, for the few places that
	// genuinely need a vector now the grid is the stored form (the map
	// overlay; re-deriving the sandwich bounds after a clip). `tolerance` is
	// a query param in degrees: 0 (default) keeps the exact lattice outline,
	// whose rasterisation reproduces the input grid bit for bit; positive
	// values simplify for display. Returns {"wkt": ..., "geojson": ...}.
	// Tracing, like rasterising, exists ONLY here - it carries judgement
	// (pinch handling, hole nesting) that must not live twice.
	api.Post("/v1/reach/vectorize", func(c *fiber.Ctx) error {
		body := c.Body()
		cs, err := cellset.Decode(body)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}
		tol := c.QueryFloat("tolerance", 0)
		if tol < 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "tolerance must be >= 0"})
		}
		wkt, err := cs.ToMultiPolygonWKT(tol)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}
		g, err := geom.UnmarshalWKT(wkt, geom.NoValidate{})
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "traced WKT does not parse: " + err.Error()})
		}
		gj, err := g.MarshalJSON()
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		return c.JSON(fiber.Map{"wkt": wkt, "geojson": json.RawMessage(gj)})
	})

	// POST /v1/groups/intersecting — encoded cell bytes in, the groups whose
	// area shares at least one covered cell out, each flagged with whether
	// the grid lies entirely WITHIN that group. The cell form of the
	// ST_Intersects/ST_Within pair the rejection clip, the retraction pass
	// and the crosspost count ask; answered here so the comparison happens on
	// the same lattice as the reach itself.
	api.Post("/v1/groups/intersecting", func(c *fiber.Ctx) error {
		state, ok := srv.getDataset("groups")
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}
		ds, ok := state.ds.(*GroupsDataset)
		if !ok {
			return c.Status(fiber.StatusNotImplemented).JSON(fiber.Map{"error": "dataset does not answer intersecting"})
		}
		cs, err := cellset.Decode(c.Body())
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}

		var rel []GroupCellRelation
		err = state.withIndex(func(idx *Index) error {
			var e error
			rel, e = ds.IntersectingCells(idx, cs)
			return e
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		if rel == nil {
			rel = []GroupCellRelation{}
		}
		return c.JSON(fiber.Map{"groups": rel})
	})

	// GET /v1/:dataset/knn	// GET /v1/:dataset/knn
	api.Get("/v1/:dataset/knn", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}

		lng := c.QueryFloat("lng", 0)
		lat := c.QueryFloat("lat", 0)
		if lng == 0 && lat == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "lng and lat required"})
		}
		limitStr := c.Query("limit", "1")
		limit, err := strconv.Atoi(limitStr)
		if err != nil || limit < 1 || limit > 1000 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "limit must be 1–1000"})
		}

		params := QueryParams{
			Lng:        lng,
			Lat:        lat,
			Limit:      limit,
			TypeFilter: c.Query("type"),
		}

		if polygonWKT := c.Query("polygon"); polygonWKT != "" {
			pg, err := parsePolygonParam(polygonWKT)
			if err != nil {
				return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
			}
			params.Polygon = pg
		}

		var results []QueryResult
		err = state.withIndex(func(idx *Index) error {
			var e error
			results, e = state.ds.Query(idx, params)
			return e
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		return c.JSON(fiber.Map{"results": results})
	})

	// GET /v1/:dataset/within
	api.Get("/v1/:dataset/within", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}

		polygonWKT := c.Query("polygon")
		if polygonWKT == "" {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "polygon parameter required"})
		}
		pg, err := parsePolygonParam(polygonWKT)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}

		var ids []int64
		err = state.withIndex(func(idx *Index) error {
			var e error
			ids, e = state.ds.Within(idx, QueryParams{Polygon: pg})
			return e
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err == ErrTooManyResults {
			return c.Status(fiber.StatusRequestEntityTooLarge).JSON(fiber.Map{
				"error": ErrTooManyResults.Error(),
			})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		return c.JSON(fiber.Map{"ids": ids})
	})

	// GET /v1/:dataset/within_coords — like /within but returns items with coordinates.
	// Used by the routing server to find freeglers within an isochrone polygon without
	// the centre-distance bias of a KNN query.
	// Polygon can be passed as a query parameter (for short polygons) or via POST body.
	api.Get("/v1/:dataset/within_coords", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}

		polygonWKT := c.Query("polygon")
		if polygonWKT == "" {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "polygon parameter required"})
		}
		pg, err := parsePolygonParam(polygonWKT)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}

		var items []Item
		err = state.withIndex(func(idx *Index) error {
			var e error
			items, e = idx.QueryWithinFull(*pg)
			return e
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err == ErrTooManyResults {
			return c.Status(fiber.StatusRequestEntityTooLarge).JSON(fiber.Map{
				"error": ErrTooManyResults.Error(),
			})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}

		type result struct {
			Extra map[string]any `json:"extra,omitempty"`
		}
		results := make([]result, len(items))
		for i, item := range items {
			results[i] = result{Extra: item.Extra}
		}
		return c.JSON(fiber.Map{"results": results})
	})

	// POST /v1/:dataset/within_coords — same as GET but accepts polygon in request body.
	// For large polygons (e.g., 77-point isochrones), POST avoids URL length limits.
	// Accepts Content-Type: text/plain (raw WKT) or application/x-www-form-urlencoded (polygon=WKT).
	api.Post("/v1/:dataset/within_coords", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}

		var polygonWKT string
		contentType := c.Get("Content-Type")

		// Try to extract polygon from body based on content type.
		if strings.HasPrefix(contentType, "application/x-www-form-urlencoded") {
			// Parse form data: polygon=WKT
			var req struct {
				Polygon string `form:"polygon"`
			}
			if err := c.BodyParser(&req); err == nil {
				polygonWKT = req.Polygon
			}
		}
		// If not found or no specific content type, try raw body.
		if polygonWKT == "" {
			polygonWKT = string(c.Body())
		}

		if polygonWKT == "" {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "polygon parameter required"})
		}

		pg, err := parsePolygonParam(polygonWKT)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": err.Error()})
		}

		var items []Item
		err = state.withIndex(func(idx *Index) error {
			var e error
			items, e = idx.QueryWithinFull(*pg)
			return e
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err == ErrTooManyResults {
			return c.Status(fiber.StatusRequestEntityTooLarge).JSON(fiber.Map{
				"error": ErrTooManyResults.Error(),
			})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}

		type result struct {
			Extra map[string]any `json:"extra,omitempty"`
		}
		results := make([]result, len(items))
		for i, item := range items {
			results[i] = result{Extra: item.Extra}
		}
		return c.JSON(fiber.Map{"results": results})
	})

	// Swagger UI (Redoc) — mirrors the v2 Go API pattern.
	api.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/index.html", 302)
	})
	api.Static("/swagger", "./swagger", fiber.Static{Index: "index.html"})

	// Admin API
	admin := fiber.New(fiber.Config{DisableStartupMessage: true})

	// POST /v1/:dataset/rebuild
	admin.Post("/v1/:dataset/rebuild", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		if _, ok := srv.getDataset(name); !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}
		state := srv.datasets[name]
		if state.rebuilding.Load() {
			return c.Status(fiber.StatusConflict).JSON(fiber.Map{"error": "rebuild already in progress"})
		}
		go func() {
			if err := srv.rebuild(name); err != nil {
				log.Printf("spatial-server: async rebuild of %s failed: %v", name, err)
			}
		}()
		return c.JSON(fiber.Map{"status": "rebuilding", "dataset": name})
	})

	// POST /v1/rebuild — rebuild all datasets
	admin.Post("/v1/rebuild", func(c *fiber.Ctx) error {
		go func() {
			srv.rebuildAll()
		}()
		return c.JSON(fiber.Map{"status": "rebuilding_all"})
	})

	// POST /v1/:dataset/remove — remove specific IDs (incremental hard-delete)
	admin.Post("/v1/:dataset/remove", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}

		var req struct {
			IDs []int64 `json:"ids"`
		}
		if err := c.BodyParser(&req); err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "invalid request body"})
		}
		if len(req.IDs) == 0 {
			return c.JSON(fiber.Map{"removed": 0})
		}

		var removed int
		err := state.withIndex(func(idx *Index) error {
			for _, id := range req.IDs {
				if e := idx.DeleteByExtID(id); e != nil {
					log.Printf("spatial-server: remove %s id=%d: %v", name, id, e)
				} else {
					removed++
				}
			}
			return nil
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		return c.JSON(fiber.Map{"removed": removed})
	})

	// POST /v1/:dataset/upsert — insert/replace specific items by WKT geometry.
	// Intended for integration tests, which seed a known geometry into the live
	// index (decoupled from the nightly MySQL rebuild) and remove it afterwards.
	admin.Post("/v1/:dataset/upsert", func(c *fiber.Ctx) error {
		name := c.Params("dataset")
		state, ok := srv.getDataset(name)
		if !ok {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"error": "unknown dataset"})
		}

		var req struct {
			Items []struct {
				ID    int64          `json:"id"`
				WKT   string         `json:"wkt"`
				Extra map[string]any `json:"extra"`
			} `json:"items"`
		}
		if err := c.BodyParser(&req); err != nil {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "invalid request body"})
		}

		items := make([]Item, 0, len(req.Items))
		for _, in := range req.Items {
			g, err := geom.UnmarshalWKT(in.WKT, geom.NoValidate{})
			if err != nil {
				return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "bad wkt for id " + fmt.Sprint(in.ID) + ": " + err.Error()})
			}
			min, max, okEnv := g.Envelope().MinMaxXYs()
			if !okEnv {
				return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "empty geometry for id " + fmt.Sprint(in.ID)})
			}
			it := Item{
				ExtID:  in.ID,
				MinLng: min.X,
				MaxLng: max.X,
				MinLat: min.Y,
				MaxLat: max.Y,
				Extra:  in.Extra,
			}
			// Polygon (non-degenerate envelope) → keep WKB + area so the polygon
			// nearest/within queries work; point → leave WKB nil.
			if min.X != max.X || min.Y != max.Y {
				it.WKB = g.AsBinary()
				it.Area = g.Area()
			}
			items = append(items, it)
		}

		if len(items) == 0 {
			return c.JSON(fiber.Map{"upserted": 0})
		}

		// Upsert is the integration-test seeding path and must work even when no
		// index was built at startup (e.g. an empty test DB), which otherwise
		// leaves state.idx nil and 503s. Lazily create an empty index to seed into;
		// this never SERVES an empty index (the row is inserted right after).
		if err := state.ensureIndex(); err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}

		err := state.withIndex(func(idx *Index) error {
			return InsertItems(idx, items, nil)
		})
		if err == errIndexNotReady {
			return c.Status(fiber.StatusServiceUnavailable).JSON(fiber.Map{"error": "dataset not ready"})
		}
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": err.Error()})
		}
		return c.JSON(fiber.Map{"upserted": len(items)})
	})

	port := getenv("SPATIAL_PORT", "8194")
	adminPort := getenv("SPATIAL_ADMIN_PORT", "8195")

	go func() {
		log.Printf("spatial-server: admin listening on :%s", adminPort)
		if err := admin.Listen(":" + adminPort); err != nil {
			log.Fatalf("admin listener failed: %v", err)
		}
	}()

	log.Printf("spatial-server: listening on :%s", port)
	log.Fatal(api.Listen(":" + port))
}

// parsePolygonParam validates and parses a WKT polygon query parameter.
// Returns HTTP 400 if the WKT is malformed, too large, or has too many vertices.
func parsePolygonParam(wkt string) (*geom.Geometry, error) {
	if len(wkt) > 100*1024 {
		return nil, fmt.Errorf("polygon parameter exceeds 100 KB limit")
	}
	// Count vertices as proxy (each coordinate pair is separated by a comma).
	vertexCount := strings.Count(wkt, ",") + 1
	if vertexCount > 10_000 {
		return nil, fmt.Errorf("polygon has too many vertices (max 10000)")
	}

	var g geom.Geometry
	var parseErr error
	func() {
		defer func() {
			if r := recover(); r != nil {
				parseErr = fmt.Errorf("invalid polygon WKT: %v", r)
			}
		}()
		g, parseErr = geom.UnmarshalWKT(wkt, geom.NoValidate{})
	}()
	if parseErr != nil {
		return nil, fmt.Errorf("invalid polygon WKT: %w", parseErr)
	}
	return &g, nil
}

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
