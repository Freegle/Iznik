package test

import (
	"encoding/base64"
	json2 "encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/spatial"
)

// stubReachIndexFromDB points the spatial client at a stub that answers the
// reach-containment question FROM THE ROWS THIS SUITE SEEDS, by probing each
// row's stored cell grid - the same bytes and the same probe the real index
// uses. The real spatial-knn container builds its index from a different
// database, so it cannot see test fixtures; without this stub every read
// surface would take its universe from someone else's data.
//
// The ring lanes are answered the same way when askRings is true: a lane's
// base64 cells probe for the point, for exactly the lanes the caller asks
// about. Unknown paths 404, which the clients treat as "spatial cannot say" -
// the degraded outer-bound + cells-probe path, still answered from the same
// stored grids.
//
// Fixtures must rasterise BEFORE calling this: it repoints SPATIAL_KNN_URL,
// so a later rasterise would hit the stub and correctly get a 404.
func stubReachIndexFromDB(t *testing.T, askRings bool) {
	t.Helper()
	db := database.DBConn

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/v1/reach/containing":
			lng, lat := queryFloat(r, "lng"), queryFloat(r, "lat")
			var rows []struct {
				Msgid int64  `gorm:"column:msgid"`
				Cells []byte `gorm:"column:polygon_cells"`
			}
			db.Table("rippling_reach").
				Select("msgid, polygon_cells").
				Where("status != 'held'").
				Where("polygon_cells IS NOT NULL").
				Scan(&rows)
			in := []int64{}
			for _, row := range rows {
				if inside, ok := rippling.CellSetContains(row.Cells, lng, lat); ok && inside {
					in = append(in, row.Msgid)
				}
			}
			w.Header().Set("Content-Type", "application/json")
			json2.NewEncoder(w).Encode(map[string]any{"in": in, "partial": []int64{}})
		case "/v1/reachoverflow/containing":
			if !askRings {
				http.NotFound(w, r)
				return
			}
			lng, lat := queryFloat(r, "lng"), queryFloat(r, "lat")
			lanes := strings.Split(r.URL.Query().Get("lanes"), ",")
			var rows []struct {
				Msgid int64  `gorm:"column:msgid"`
				Doc   []byte `gorm:"column:overflow_cells"`
			}
			db.Table("rippling_reach").
				Select("msgid, overflow_cells").
				Where("status != 'held'").
				Where("overflow_cells IS NOT NULL").
				Scan(&rows)
			in := []int64{}
			for _, row := range rows {
				var doc map[string]json2.RawMessage
				if json2.Unmarshal(row.Doc, &doc) != nil {
					continue
				}
				for _, lane := range lanes {
					if laneCellsAdmit(doc, lane, lng, lat) {
						in = append(in, row.Msgid)
						break
					}
				}
			}
			w.Header().Set("Content-Type", "application/json")
			json2.NewEncoder(w).Encode(map[string]any{"in": in, "partial": []int64{}, "filtered": true})
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)
	t.Setenv("SPATIAL_KNN_URL", srv.URL)
}

func queryFloat(r *http.Request, name string) float64 {
	var v float64
	json2.Unmarshal([]byte(r.URL.Query().Get(name)), &v)
	return v
}

// laneCellsAdmit resolves a lane path ($.rural.sparse, $.cluster.w1,
// $.fairness."1") in an overflow_cells document and probes its base64 grid.
func laneCellsAdmit(doc map[string]json2.RawMessage, lane string, lng, lat float64) bool {
	if !strings.HasPrefix(lane, "$.") {
		return false
	}
	parts := strings.SplitN(lane[2:], ".", 2)
	if len(parts) != 2 {
		return false
	}
	family, key := parts[0], strings.Trim(parts[1], "\"")

	var bands map[string]string
	if raw, ok := doc[family]; !ok || json2.Unmarshal(raw, &bands) != nil {
		return false
	}
	b64, ok := bands[key]
	if !ok {
		return false
	}
	cells, err := base64.StdEncoding.DecodeString(b64)
	if err != nil {
		return false
	}
	inside, ok := rippling.CellSetContains(cells, lng, lat)
	return ok && inside
}

// mustRasterize builds a fixture grid via the REAL rasteriser - call it
// BEFORE stubReachIndexFromDB repoints the spatial URL.
func mustRasterize(t *testing.T, wkt string) []byte {
	t.Helper()
	cells, err := spatial.RasterizeWKT(wkt)
	if err != nil {
		t.Fatalf("the rasteriser must answer - check spatial-knn is up: %v", err)
	}
	return cells
}
