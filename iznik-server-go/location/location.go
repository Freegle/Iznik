package location

import (
	"encoding/json"
	"encoding/xml"
	"fmt"
	"log"
	"math"
	"sort"
	"strconv"
	"strings"
	"sync"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	geo "github.com/kellydunn/golang-geo"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

const TYPE_POSTCODE = "Postcode"
const NEARBY = 50 // In miles.

type AreaInfo struct {
	ID   uint64  `json:"id"`
	Name string  `json:"name"`
	Lat  float32 `json:"lat"`
	Lng  float32 `json:"lng"`
}

type Location struct {
	ID         uint64         `json:"id"`
	Name       string         `json:"name"`
	Type       string         `json:"type"`
	Lat        float32        `json:"lat"`
	Lng        float32        `json:"lng"`
	Areaid     uint64         `json:"areaid"`
	Areaname   string         `json:"areaname"`
	Area       *AreaInfo      `json:"area,omitempty" gorm:"-"`
	GroupsNear []ClosestGroup `json:"groupsnear" gorm:"-"`
	Dist       float32        `json:"dist" gorm:"-"`
}

// ClosestPostcode returns the nearest full postcode to a point via the spatial
// server's "postcodes" KNN dataset. Returns a zero Location if the spatial
// server has nothing nearby or is unreachable.
func ClosestPostcode(lat float32, lng float32) Location {
	// (0,0) is the codebase-wide "location unknown" sentinel (e.g. GetLatLng
	// returns it for a user with no derivable location). It sits in the Atlantic
	// off Africa, so a UK KNN would still return *some* postcode (the nearest,
	// however far) — the old expanding-bbox lookup returned empty here. Preserve
	// that: no location in, no postcode out. (lng=0 alone is valid — the Greenwich
	// meridian crosses the UK — so only the both-zero sentinel is excluded.)
	if lat == 0 && lng == 0 {
		return Location{}
	}

	results, err := spatial.KNN("postcodes", float64(lng), float64(lat), 1, "")
	if err != nil || len(results) == 0 {
		return Location{}
	}

	id := results[0].ID
	var loc Location
	database.DBConn.Table("locations l1").
		Select("l1.id, l1.name, l1.type, l1.lat, l1.lng, l1.areaid, l2.name AS areaname").
		Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
		Where("l1.id = ?", id).
		Scan(&loc)
	return loc
}

type ClosestGroup struct {
	ID          uint64          `json:"id"`
	Nameshort   string          `json:"nameshort"`
	Namefull    string          `json:"namefull"`
	Namedisplay string          `json:"namedisplay"`
	Ontn        bool            `json:"ontn"`
	Dist        float32         `json:"dist"`
	Settings    json.RawMessage `json:"settings"` // This is JSON stored in the DB as a string.
}

func ClosestSingleGroup(lat float64, lng float64, radius float64) *ClosestGroup {
	groups := ClosestGroups(lat, lng, radius, 1)

	if len(groups) > 0 {
		return &groups[0]
	} else {
		return nil
	}
}

func ClosestGroups(lat float64, lng float64, radius float64, limit int) []ClosestGroup {
	// To make this efficient we want to use the spatial index on polyindex.  But our groups are not evenly
	// distributed, so if we search immediately upto $radius, which is the maximum we need to cover, then we
	// will often have to scan many more groups than we need in order to determine the closest groups
	// (via the LIMIT clause), and this may be slow even with a spatial index.
	//
	// For example, searching in London will find ~120 groups within 50 miles, of which we are only interested
	// in 10, and the query will take ~0.03s.  If we search within 4 miles, that will typically find what we
	// need and the query takes ~0.00s.
	//
	// So we step up, using a bounding box that covers the point and radius and searching based on the lat/lng
	// centre of the group.  That's much faster.  But (infuriatingly) there are some groups which are so large that
	// the centre of the group is further away than the centre of lots of other groups, and that means that
	// we don't find the correct group.  So to deal with such groups we have an alt lat/lng which we can set to
	// be somewhere else, effectively giving the group two "centres".  This is a fudge which clearly wouldn't
	// cope with arbitrary geographies or hyperdimensional quintuple manifolds or whatever, but works ok for our
	// little old UK reuse network.
	//
	// Because this is Go we can fire off these requests in parallel and just stop when we get enough results.
	// This reduces latency significantly, even though it's a bit mean to the database server.
	db := database.DBConn

	// If this point lies inside one or more group polygons, those are the correct groups —
	// polygon containment is authoritative and beats any centre-distance heuristic.  This
	// matches the V1 PHP groupsNear() behaviour and fixes bug #9518, where a group with a
	// close centre but non-containing polygon was returned instead of the large group whose
	// polygon actually contains the point.  The radius-stepping search below filters on the
	// group centre distance (HAVING hav < currradius), so a containing group whose centre is
	// far away would otherwise be dropped entirely.
	containing := []ClosestGroup{}
	db.Table("groups").
		Select("id, nameshort, namefull, ontn, settings, 0 AS dist, "+
			"haversine(lat, lng, ?, ?) AS hav, "+
			"CASE WHEN altlat IS NOT NULL THEN haversine(altlat, altlng, ?, ?) ELSE NULL END AS hav2",
			lat, lng, lat, lng).
		Where("ST_Contains(polyindex, ST_SRID(POINT(?, ?), ?)) AND publish = 1 AND listable = 1", lng, lat, utils.SRID).
		Order("hav ASC, external ASC").
		Limit(limit).
		Scan(&containing)

	if len(containing) > 0 {
		for i, r := range containing {
			if len(r.Namefull) > 0 {
				containing[i].Namedisplay = r.Namefull
			} else {
				containing[i].Namedisplay = r.Nameshort
			}
		}
		return containing
	}

	var currradius = math.Round(float64(radius)/16.0 + 0.5)
	results := []ClosestGroup{}
	var wg sync.WaitGroup
	var mu sync.Mutex

	// Every band's query must complete before we can trust `results`: a group's
	// "hav" (distance to its registered centre) gates which band can see it at
	// all, independent of "dist" (distance to its actual polygon boundary), which
	// is what determines "nearest" for ranking. A large or awkwardly-shaped group
	// can have a polygon boundary very close to the point while its registered
	// centre is comparatively far away, so it is only discoverable via a wider
	// (and slower) band. Stopping as soon as any one band alone had accumulated
	// `limit` candidates - as this used to do - could return before a still-running
	// wider band completed, silently dropping a genuinely nearer group in favour of
	// worse-but-faster-to-find ones (Discourse #9905).
	for {
		wg.Add(1)

		go func(currradius float64) {
			defer wg.Done()

			batch := []ClosestGroup{}
			var nelat, nelng, swlat, swlng float64
			p := geo.NewPoint(lat, lng)
			ne := p.PointAtDistanceAndBearing(currradius, 45)
			nelat = ne.Lat()
			nelng = ne.Lng()
			sw := p.PointAtDistanceAndBearing(currradius, 225)
			swlat = sw.Lat()
			swlng = sw.Lng()

			// No .Group() call: clause/group_by.go's GroupBy.Build() writes
			// nothing for an empty Columns list and Clause.Build() skips the
			// "GROUP BY " name prefix when MergeClause left it "" (which it
			// does for zero columns), so .Having() alone renders a bare
			// "HAVING (...)" with no "GROUP BY" before it - matching this
			// golden, which has none.
			db.Table("groups").
				Select("id, nameshort, namefull, ontn, settings, "+
					"ST_distance(ST_SRID(POINT(?, ?), ?), polyindex) * 111195 * 0.000621371 AS dist, "+
					"haversine(lat, lng, ?, ?) AS hav, CASE WHEN altlat IS NOT NULL THEN haversine(altlat, altlng, ?, ?) ELSE NULL END AS hav2",
					lng, lat, utils.SRID, lat, lng, lat, lng).
				Where("MBRIntersects(polyindex, ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?)) "+
					"AND publish = 1 AND listable = 1",
					swlng, swlat, swlng, nelat, nelng, nelat, nelng, swlat, swlng, swlat, utils.SRID).
				Having("(hav IS NOT NULL AND hav < ? OR hav2 IS NOT NULL AND hav2 < ?)", currradius, currradius).
				Order("dist ASC, hav ASC, external ASC").
				Limit(limit).
				Scan(&batch)

			if len(batch) > 0 {
				for i, r := range batch {
					if len(r.Namefull) > 0 {
						batch[i].Namedisplay = r.Namefull
					} else {
						batch[i].Namedisplay = r.Nameshort
					}
				}

				mu.Lock()
				results = append(results, batch...)
				mu.Unlock()
			}
		}(currradius)

		currradius = currradius * 2

		if currradius >= radius {
			break
		}
	}

	wg.Wait()

	// Sort results by distance, ascending.
	if len(results) > 1 {
		sort.Slice(results, func(i, j int) bool {
			return results[i].Dist < results[j].Dist
		})
	}

	// Remove duplicates by id
	seen := make(map[uint64]struct{}, len(results))
	j := 0
	for _, v := range results {
		if _, ok := seen[v.ID]; ok {
			continue
		}
		seen[v.ID] = struct{}{}
		results[j] = v
		j++
	}

	// Limit results to the first `limit` items.
	if len(results) > limit {
		results = results[:limit]
	}

	return results
}

func FetchSingle(id uint64) *Location {
	if id == 0 {
		return nil
	}

	db := database.DBConn

	var location Location

	db.Table("locations l1").
		Select("l1.id, l1.name, l1.areaid, l1.lat, l1.lng, l2.name as areaname").
		Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
		Where("l1.id = ?", id).
		Limit(1).
		Scan(&location)

	// Return nil when location doesn't exist.
	if location.ID == 0 {
		return nil
	}

	return &location
}

func GetLocation(c *fiber.Ctx) error {
	groupsnear := c.QueryBool("groupsnear", true)

	if c.Params("id") != "" {
		// Looking for a specific location.
		id, err := strconv.ParseUint(c.Params("id"), 10, 64)

		if err == nil {
			loc := FetchSingle(id)

			if loc == nil {
				return fiber.NewError(fiber.StatusNotFound, "Location not found")
			}

			if groupsnear && loc.ID > 0 {
				loc.GroupsNear = ClosestGroups(float64(loc.Lat), float64(loc.Lng), NEARBY, 10)
			}

			return c.JSON(loc)
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "Location not found")
}

func LatLng(c *fiber.Ctx) error {
	lat, _ := strconv.ParseFloat(c.Query("lat"), 32)
	lng, _ := strconv.ParseFloat(c.Query("lng"), 32)

	loc := ClosestPostcode(float32(lat), float32(lng))
	if loc.ID > 0 {
		loc.GroupsNear = ClosestGroups(float64(loc.Lat), float64(loc.Lng), NEARBY, 10)
	}

	return c.JSON(loc)
}

// Resolve looks up a place by its EXACT name and returns the single best-matching
// location, so a term the item search can't satisfy — a county/town/postcode such as
// "Hertfordshire", "London" or "L30" — can be offered to the user as "search for items
// near <place>" instead of a dead-end zero-results page.
//
// Prefers an area Polygon (best area centroid), then a full Postcode, then lesser
// geometry types. Intended to be called ONLY when an item search returned nothing, so
// item words that happen to also be place names ("Shed", "Mosaic") never reach here —
// those return plenty of item results and so are never offered as a location. 404 when
// the name is not a known place.
func Resolve(c *fiber.Ctx) error {
	name := strings.TrimSpace(c.Query("name"))
	if name == "" {
		return fiber.NewError(fiber.StatusBadRequest, "No name")
	}

	db := database.DBConn

	var loc Location
	db.Table("locations").
		Select("id, name, type, lat, lng, areaid").
		Where("name = ?", name).
		Order("FIELD(type, 'Polygon', 'Postcode', 'Road', 'Line', 'Point'), id").
		Limit(1).
		Scan(&loc)

	if loc.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "No matching place")
	}

	return c.JSON(loc)
}

// BoxLocation represents a location returned from bounding box queries, including its polygon.
type BoxLocation struct {
	ID      uint64  `json:"id"`
	Name    string  `json:"name"`
	Type    string  `json:"type"`
	Lat     float32 `json:"lat"`
	Lng     float32 `json:"lng"`
	Areaid  uint64  `json:"areaid"`
	Polygon string  `json:"polygon"`
}

// DodgyLocation represents a dodgy location entry.
type DodgyLocation struct {
	Locationid    uint64  `json:"locationid"`
	Oldlocationid uint64  `json:"oldlocationid"`
	Newlocationid uint64  `json:"newlocationid"`
	Lat           float32 `json:"lat"`
	Lng           float32 `json:"lng"`
	Name          string  `json:"name"`
	Oldname       string  `json:"oldname"`
	Newname       string  `json:"newname"`
}

// SearchLocations handles GET /locations - search for locations by lat/lng, typeahead, or bounding box.
func SearchLocations(c *fiber.Ctx) error {
	latStr := c.Query("lat")
	lngStr := c.Query("lng")
	swlatStr := c.Query("swlat")
	nelatStr := c.Query("nelat")
	swlngStr := c.Query("swlng")
	nelngStr := c.Query("nelng")
	typeaheadStr := c.Query("typeahead")
	dodgyFlag := c.QueryBool("dodgy", false)
	areasFlag := c.QueryBool("areas", true)
	limitStr := c.Query("limit", "10")
	groupsnear := c.QueryBool("groupsnear", true)
	pconly := c.QueryBool("pconly", true)

	if latStr != "" && lngStr != "" {
		// Find closest postcode and nearby groups.
		lat, _ := strconv.ParseFloat(latStr, 32)
		lng, _ := strconv.ParseFloat(lngStr, 32)

		loc := ClosestPostcode(float32(lat), float32(lng))
		if loc.ID > 0 && groupsnear {
			loc.GroupsNear = ClosestGroups(float64(loc.Lat), float64(loc.Lng), NEARBY, 10)
		}

		return c.JSON(fiber.Map{
			"ret":      0,
			"status":   "Success",
			"location": loc,
		})
	} else if typeaheadStr != "" {
		// Typeahead search.
		limit, _ := strconv.ParseUint(limitStr, 10, 64)
		if limit > 100 {
			limit = 100
		}

		locations := []Location{}
		db := database.DBConn

		type locationWithArea struct {
			Location
			AreaLat float32 `json:"-" gorm:"column:arealat"`
			AreaLng float32 `json:"-" gorm:"column:arealng"`
		}

		// pcq is only
		// appended when pconly is set, so this statement has exactly 2
		// possible rendered forms, both proven by the retired ormharness
		// (shapes.json / TestTier3Shapes_b262bf75df3c, removed in d22ba1d6c).
		var locs []locationWithArea
		txb262bf75df3c := db.Table("locations l1").
			Select("l1.id, l1.name, l1.areaid, l1.lat, l1.lng, l1.type, l2.name as areaname, l2.lat as arealat, l2.lng as arealng").
			Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
			Where("l1.name LIKE ?", typeaheadStr+"%")
		if pconly {
			txb262bf75df3c = txb262bf75df3c.Where("l1.type = '" + TYPE_POSTCODE + "'")
		}
		txb262bf75df3c.Where("l1.name LIKE '% %'").Limit(int(limit)).Scan(&locs)

		for i, l := range locs {
			locations = append(locations, l.Location)
			if l.Areaid > 0 {
				locations[i].Area = &AreaInfo{
					ID:   l.Areaid,
					Name: l.Areaname,
					Lat:  l.AreaLat,
					Lng:  l.AreaLng,
				}
			}
		}

		if groupsnear {
			var wg sync.WaitGroup
			wg.Add(len(locations))
			for i := range locations {
				go func(i int) {
					locations[i].GroupsNear = ClosestGroups(float64(locations[i].Lat), float64(locations[i].Lng), NEARBY, 10)
					wg.Done()
				}(i)
			}
			wg.Wait()
		}

		return c.JSON(fiber.Map{
			"ret":       0,
			"status":    "Success",
			"locations": locations,
		})
	} else if swlatStr != "" || nelatStr != "" {
		// Bounding box search.
		swlat, _ := strconv.ParseFloat(swlatStr, 64)
		swlng, _ := strconv.ParseFloat(swlngStr, 64)
		nelat, _ := strconv.ParseFloat(nelatStr, 64)
		nelng, _ := strconv.ParseFloat(nelngStr, 64)

		ret := fiber.Map{"ret": 0, "status": "Success"}

		if areasFlag {
			db := database.DBConn
			var boxLocs []BoxLocation

			// Return the full-resolution geometry (ourgeometry override if present, else geometry).
			// This bbox query feeds ONLY the ModTools area-boundary editor (the sole caller passing a
			// bounding box). Applying ST_Simplify(...,0.001) (~111 m) here silently dropped a freshly
			// dragged midpoint vertex on reload, so the edit looked unsaved and adjacent vertices could
			// vanish (Discourse #9770). The write side already stores full detail; the editor needs to
			// read it back at full detail too. Edited areas are small neighbourhood polygons, so the
			// payload cost of dropping simplification here is negligible.
			// .Table() accepts args when the name has embedded "?"s (same
			// mechanism as a plain literal table name), so the derived-table
			// subquery and its own bind travel together in the FROM clause -
			// before the LIMIT and the (bindless) Joins/Where that follow it.
			db.Table("(SELECT DISTINCT locationid FROM locations_spatial "+
				"INNER JOIN locations l2 ON l2.areaid = locations_spatial.locationid "+
				"WHERE ST_Intersects(locations_spatial.geometry, "+
				"ST_GeomFromText(?, ?)) "+
				"AND l2.type = ?) ls",
				fmt.Sprintf("POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))",
					swlng, swlat, nelng, swlat, nelng, nelat, swlng, nelat, swlng, swlat),
				utils.SRID,
				utils.LOCATION_TYPE_POSTCODE).
				Select("DISTINCT l.id, l.name, l.type, l.lat, l.lng, l.areaid, " +
					"ST_AsText(CASE WHEN l.ourgeometry IS NOT NULL THEN l.ourgeometry ELSE l.geometry END) AS polygon").
				Joins("INNER JOIN locations l ON l.id = ls.locationid").
				Joins("LEFT JOIN locations_excluded ON ls.locationid = locations_excluded.locationid").
				Where("locations_excluded.locationid IS NULL").
				Limit(500).
				Scan(&boxLocs)

			// Handle POINT geometries - convert to small polygons.
			for i, loc := range boxLocs {
				if strings.HasPrefix(loc.Polygon, "POINT(") {
					sw_lat := loc.Lat - 0.0005
					sw_lng := loc.Lng - 0.0005
					ne_lat := loc.Lat + 0.0005
					ne_lng := loc.Lng + 0.0005
					boxLocs[i].Polygon = fmt.Sprintf("POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))",
						sw_lng, sw_lat, sw_lng, ne_lat, ne_lng, ne_lat, ne_lng, sw_lat, sw_lng, sw_lat)
				}
			}

			if boxLocs == nil {
				boxLocs = []BoxLocation{}
			}
			ret["locations"] = boxLocs
		}

		if dodgyFlag {
			db := database.DBConn
			var dodgyLocs []DodgyLocation
			db.Table("locations_dodgy ld").
				Select("ld.locationid, ld.oldlocationid, ld.newlocationid, ld.lat, ld.lng, "+
					"l0.name AS name, l1.name AS oldname, l2.name AS newname").
				Joins("INNER JOIN locations l0 ON l0.id = ld.locationid").
				Joins("INNER JOIN locations l1 ON l1.id = ld.oldlocationid").
				Joins("INNER JOIN locations l2 ON l2.id = ld.newlocationid").
				Where("ld.lat BETWEEN ? AND ? AND ld.lng BETWEEN ? AND ?", swlat, nelat, swlng, nelng).
				Scan(&dodgyLocs)

			if dodgyLocs == nil {
				dodgyLocs = []DodgyLocation{}
			}
			ret["dodgy"] = dodgyLocs
		}

		return c.JSON(ret)
	}

	return fiber.NewError(fiber.StatusBadRequest, "Missing required parameters (lat/lng, typeahead, or swlat/nelat)")
}

func Typeahead(c *fiber.Ctx) error {
	limit := c.Query("limit", "10")
	limit64, _ := strconv.ParseUint(limit, 10, 64)

	if limit64 > 10 {
		limit64 = 10
	}

	typeahead := c.Query("q")
	pconly := c.QueryBool("pconly", true)

	// We want to select full postcodes (with a space in them).
	typeahead = strings.ReplaceAll(typeahead, `\s`, "")

	locations := []Location{}

	if typeahead != "" {
		db := database.DBConn

		type locationWithArea struct {
			Location
			AreaLat float32 `json:"-" gorm:"column:arealat"`
			AreaLng float32 `json:"-" gorm:"column:arealng"`
		}

		// Shares
		// SearchLocations's pattern: pcq is only appended when pconly is set,
		// so this statement has exactly 2 possible rendered forms, both
		// proven by the retired ormharness (shapes.json /
		// TestTier3Shapes_71f1772f4a99, removed in d22ba1d6c).
		var locs []locationWithArea
		tx71f1772f4a99 := db.Table("locations l1").
			Select("l1.id, l1.name, l1.areaid, l1.lat, l1.lng, l1.type, l2.name as areaname, l2.lat as arealat, l2.lng as arealng").
			Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
			Where("l1.name LIKE ?", typeahead+"%")
		if pconly {
			tx71f1772f4a99 = tx71f1772f4a99.Where("l1.type = '" + TYPE_POSTCODE + "'")
		}
		tx71f1772f4a99.Where("l1.name LIKE '% %'").Limit(int(limit64)).Scan(&locs)

		for i, l := range locs {
			locations = append(locations, l.Location)
			if l.Areaid > 0 {
				locations[i].Area = &AreaInfo{
					ID:   l.Areaid,
					Name: l.Areaname,
					Lat:  l.AreaLat,
					Lng:  l.AreaLng,
				}
			}
		}

		// Fetch the groups near each postcode, in parallel
		var wg sync.WaitGroup
		wg.Add(len(locations))

		for i := range locations {
			go func(i int) {
				locations[i].GroupsNear = ClosestGroups(float64(locations[i].Lat), float64(locations[i].Lng), NEARBY, 10)
				wg.Done()
			}(i)
		}

		wg.Wait()

		return c.JSON(locations)
	}

	return fiber.NewError(fiber.StatusNotFound, "q parameter not found")
}

type Address struct {
	ID                       uint64 `json:"id"`
	Buildingname             string `json:"buildingname"`
	Buildingnumber           string `json:"buildingnumber"`
	Subbuildingname          string `json:"subbuildingname"`
	Departmentname           string `json:"departmentname"`
	Dependentlocality        string `json:"dependentlocality"`
	Dependentthoroughfare    string `json:"dependentthoroughfare"`
	Organisationname         string `json:"organisationname"`
	SubOrganisationindicator string `json:"suborganisationindicator"`
	Deliverypointsuffix      string `json:"deliverypointsuffix"`
	Udprn                    string `json:"udprn"`
	Posttown                 string `json:"posttown"`
	Postcodetype             string `json:"postcodetype"`
	Pobox                    string `json:"pobox"`
	Postcode                 string `json:"postcode"`
	Thoroughfaredescriptor   string `json:"thoroughfaredescriptor"`
}

func GetLocationAddresses(c *fiber.Ctx) error {
	if c.Params("id") != "" {
		id, err := strconv.ParseUint(c.Params("id"), 10, 64)

		if err == nil {
			var addresses []Address
			db := database.DBConn

			db.Table("paf_addresses").
				Select("paf_addresses.id,"+
					"locations.name as postcode, "+
					"buildingname, "+
					"buildingnumber, "+
					"p.subbuildingname, "+
					"departmentname, "+
					"dependentlocality, "+
					"doubledependentlocality, "+
					"dependentthoroughfaredescriptor, "+
					"organisationname, "+
					"suorganisationindicator, "+
					"deliverypointsuffix, "+
					"udprn, "+
					"posttown, "+
					"postcodetype, "+
					"pobox, "+
					"thoroughfaredescriptor").
				Joins("INNER JOIN locations ON locations.id = paf_addresses.postcodeid").
				Joins("LEFT JOIN paf_buildingname ON buildingnameid = paf_buildingname.id").
				Joins("LEFT JOIN paf_subbuildingname ON subbuildingnameid = paf_subbuildingname.id").
				Joins("LEFT JOIN paf_departmentname ON departmentnameid = paf_departmentname.id").
				Joins("LEFT JOIN paf_dependentlocality ON dependentlocalityid = paf_dependentlocality.id").
				Joins("LEFT JOIN paf_doubledependentlocality ON doubledependentlocalityid = paf_doubledependentlocality.id").
				Joins("LEFT JOIN paf_dependentthoroughfaredescriptor ON dependentthoroughfaredescriptorid = paf_dependentthoroughfaredescriptor.id").
				Joins("LEFT JOIN paf_organisationname ON organisationnameid = paf_organisationname.id").
				Joins("LEFT JOIN paf_pobox ON poboxid = paf_pobox.id").
				Joins("LEFT JOIN paf_posttown ON posttownid = paf_posttown.id").
				Joins("LEFT JOIN paf_subbuildingname p ON subbuildingnameid = p.id").
				Joins("LEFT JOIN paf_thoroughfaredescriptor ON thoroughfaredescriptorid = paf_thoroughfaredescriptor.id").
				Where("paf_addresses.postcodeid = ?", id).
				Scan(&addresses)

			// If buildingnumber is the same as buildingname, remove buildingnumber - this happens and causes dups.
			for i, address := range addresses {
				if address.Buildingnumber == address.Buildingname {
					addresses[i].Buildingnumber = ""
				}
			}

			if len(addresses) == 0 {
				// Force [] rather than null to be returned.
				return c.JSON(make([]string, 0))
			} else {
				return c.JSON(addresses)
			}
		}
	}

	return fiber.NewError(fiber.StatusBadRequest, "Valid id parameter required")
}

// =============================================================================
// Merged from location/location_write.go
// =============================================================================

type CreateLocationRequest struct {
	Name    string `json:"name"`
	Polygon string `json:"polygon"`
}

// CreateLocation handles PUT /locations - create a new location (system mod/admin only).
func CreateLocation(c *fiber.Ctx) error {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsSystemMod(myid) {
		return fiber.NewError(fiber.StatusForbidden, "System moderator or admin role required")
	}

	var req CreateLocationRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Name == "" || req.Polygon == "" {
		return fiber.NewError(fiber.StatusBadRequest, "name and polygon are required")
	}

	canon := strings.ToLower(req.Name)

	db := database.DBConn
	// GORM's map-Create
	// reads the id back from the same sql.Result the INSERT returned (under
	// the map key "@id"), the same write-connection guarantee the old
	// sqlDB.Exec()+LastInsertId() call had. SRID folded into the gorm.Expr
	// string via fmt.Sprintf, the same idiom this function's own
	// locations_spatial REPLACE a few lines below (site 25b7b92e33fd) uses.
	row := map[string]interface{}{
		"name":       req.Name,
		"type":       gorm.Expr("'Polygon'"),
		"geometry":   gorm.Expr(fmt.Sprintf("ST_GeomFromText(?, %d)", utils.SRID), req.Polygon),
		"canon":      canon,
		"popularity": gorm.Expr("0"),
	}
	if err := db.Table("locations").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create location")
	}

	var id uint64
	if idInt64, ok := row["@id"].(int64); ok && idInt64 > 0 {
		id = uint64(idInt64)
	}

	if id > 0 {
		// Sync to the spatial index table (required by PostcodeRemapService).
		// utils.SRID is spliced as a
		// literal into the gorm.Expr SQL text, matching exactly what the
		// original fmt.Sprintf produced, rather than bound as "?" - the
		// recorded golden is the literal-spliced form (manifest.json's
		// dynamic:true "%d" placeholder resolved to utils.SRID's value).
		db.Table("locations_spatial").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"locationid": id,
				"geometry":   gorm.Expr(fmt.Sprintf("ST_GeomFromText(?, %d)", utils.SRID), req.Polygon),
			})

		// Cache centroid and max dimension, as UpdateLocation does. Without this a
		// created area has NULL lat/lng, unlike every edited one.
		db.Table("locations").Where("id = ?", id).Updates(map[string]interface{}{
			"maxdimension": gorm.Expr("GetMaxDimension(geometry)"),
			"lat":          gorm.Expr("ST_Y(ST_Centroid(geometry))"),
			"lng":          gorm.Expr("ST_X(ST_Centroid(geometry))"),
		})

		// Put the new area into the spatial KNN index before the remap below runs,
		// otherwise the remap can't find it and the area gets no postcodes.
		if err := spatial.UpsertLocation(id, req.Polygon, req.Name, "Polygon"); err != nil {
			log.Printf("CreateLocation: spatial upsert of %d failed, postcodes may not remap until the next delta sync: %v", id, err)
		}

		// Queue postcode remapping for the new area.
		go queue.QueueTask(queue.TaskRemapPostcodes, map[string]interface{}{
			"location_id": id,
			"polygon":     req.Polygon,
		})
	}

	return c.JSON(fiber.Map{"id": id})
}

type UpdateLocationRequest struct {
	ID      uint64  `json:"id"`
	Name    *string `json:"name,omitempty"`
	Polygon *string `json:"polygon,omitempty"`
}

// UpdateLocation handles PATCH /locations - update a location (system mod/admin only).
func UpdateLocation(c *fiber.Ctx) error {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsSystemMod(myid) {
		return fiber.NewError(fiber.StatusForbidden, "System moderator or admin role required")
	}

	var req UpdateLocationRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	if req.Polygon != nil && *req.Polygon != "" {
		// Validate geometry first.
		var valid bool
		// Same bare-scalar-SELECT technique as group.go's validateGeometry
		// (site 6d0982e798b5): Statement.BuildClauses={"SELECT"} suppresses
		// GORM's automatic FROM. SRID is folded into the Select() string via
		// fmt.Sprintf, matching the shipped gorm.Expr(fmt.Sprintf(...)) idiom
		// this same function's locations_spatial REPLACE already uses below
		// (site 6f1d6543e5c0). .Table(...) is required even though it never
		// renders - without it GORM's schema-parse-failure branch rejects the
		// statement for having no table set.
		tx := db.Table("locations").Select(fmt.Sprintf("ST_IsValid(ST_GeomFromText(?, %d)) AS valid", utils.SRID), *req.Polygon)
		tx.Statement.BuildClauses = []string{"SELECT"}
		tx.Scan(&valid)

		if !valid {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid geometry")
		}

		// Note: V1 PHP called ST_Simplify(polygon, 0.001) here before saving. That 0.001-degree
		// (~111 m) Douglas-Peucker pass silently dropped any new vertex placed within ~111 m of the
		// line between its neighbours — exactly what happens when a user drags a geoman midpoint
		// marker (the new point starts on the original edge and may be moved only a short distance).
		// Result: the vertex the user just placed was discarded on every Save (Discourse #9770).
		// We deliberately skip write-time simplification here to preserve user intent.
		// The SELECT queries above still use ST_Simplify for display-only rendering, which is fine.

		// Capture old geometry and compute union with new for remap scope (matching V1).
		// If old and new intersect, remap the union (covers both). If separate, remap both.
		type OldGeom struct {
			OldGeometry *string
			Unioned     *string
		}
		var oldGeom OldGeom
		// Not a SQL
		// UNION - ST_UNION() is a geometry function inside one ordinary SELECT
		// - so this needed no BuildClauses override, just the same
		// fmt.Sprintf-folded-SRID technique as this function's other two
		// sites (745c0a9ca82e, aa63c688e6b1).
		db.Table("locations").
			Select(fmt.Sprintf(`ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS old_geometry,
				CASE WHEN ST_Intersects(
					CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END,
					ST_GeomFromText(?, %d))
				THEN ST_AsText(ST_UNION(
					CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END,
					ST_GeomFromText(?, %d)))
				ELSE NULL
				END AS unioned`, utils.SRID, utils.SRID),
				*req.Polygon, *req.Polygon).
			Where("id = ?", req.ID).
			Scan(&oldGeom)

		// Update ourgeometry (the human-edited override), not geometry (which is from OSM).
		// An
		// explicit clause.Set (not Updates(map)) keeps type before ourgeometry
		// as the original SET list had it. `type` = 'Polygon' is a literal in
		// the original (not a bind), so its Value is gorm.Expr("'Polygon'"),
		// not a plain Go string - a plain string would bind it, adding a
		// placeholder the original SQL never had.
		result := db.Table("locations").
			Clauses(clause.Set{
				{Column: clause.Column{Name: "type"}, Value: gorm.Expr("'Polygon'")},
				{Column: clause.Column{Name: "ourgeometry"}, Value: gorm.Expr(
					fmt.Sprintf("ST_GeomFromText(?, %d)", utils.SRID), *req.Polygon)},
			}).
			Where("id = ?", req.ID).
			Updates(map[string]interface{}{})

		if result.Error != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to update geometry")
		}

		// Update the spatial index table.
		// See CreateLocation's
		// 25b7b92e33fd for why SRID is spliced into the gorm.Expr text
		// rather than bound.
		db.Table("locations_spatial").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"locationid": req.ID,
				"geometry":   gorm.Expr(fmt.Sprintf("ST_GeomFromText(?, %d)", utils.SRID), *req.Polygon),
			})

		// Update cached centroid and max dimensions.
		db.Table("locations").Where("id = ?", req.ID).Updates(map[string]interface{}{
			"maxdimension": gorm.Expr("GetMaxDimension(ourgeometry)"),
			"lat":          gorm.Expr("ST_Y(ST_Centroid(ourgeometry))"),
			"lng":          gorm.Expr("ST_X(ST_Centroid(ourgeometry))"),
		})

		// Refresh the spatial KNN index before the remap below runs, so it remaps
		// against the new shape rather than the one from the last delta sync.
		var locName string
		db.Table("locations").Select("name").Where("id = ?", req.ID).Scan(&locName)
		if err := spatial.UpsertLocation(req.ID, *req.Polygon, locName, "Polygon"); err != nil {
			log.Printf("UpdateLocation: spatial upsert of %d failed, postcodes may not remap until the next delta sync: %v", req.ID, err)
		}

		// Queue postcode remapping. Matching V1: remap the union if geometries overlap,
		// or remap both old and new separately if they don't.
		if oldGeom.Unioned != nil {
			// Old and new overlap — remap the union (single task).
			go queue.QueueTask(queue.TaskRemapPostcodes, map[string]interface{}{
				"location_id": req.ID,
				"polygon":     *oldGeom.Unioned,
			})
		} else {
			// Completely separate — remap both old and new areas.
			if oldGeom.OldGeometry != nil {
				go queue.QueueTask(queue.TaskRemapPostcodes, map[string]interface{}{
					"location_id": req.ID,
					"polygon":     *oldGeom.OldGeometry,
				})
			}
			go queue.QueueTask(queue.TaskRemapPostcodes, map[string]interface{}{
				"location_id": req.ID,
				"polygon":     *req.Polygon,
			})
		}
	}

	if req.Name != nil && *req.Name != "" {
		canon := strings.ToLower(*req.Name)
		db.Table("locations").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"name": *req.Name, "canon": canon})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

type ExcludeLocationRequest struct {
	ID        uint64 `json:"id"`
	GroupID   uint64 `json:"groupid"`
	Action    string `json:"action"`
	Byname    bool   `json:"byname"`
	MessageID uint64 `json:"messageid"`
}

// ExcludeLocation handles POST /locations with action=Exclude - exclude a location from a group (group mod only).
func ExcludeLocation(c *fiber.Ctx) error {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req ExcludeLocationRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Action != "Exclude" {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid action")
	}

	if req.ID == 0 || req.GroupID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id and groupid are required")
	}

	if !auth.IsModOfGroup(myid, req.GroupID) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator or owner of the group")
	}

	db := database.DBConn

	// Exclude the specified location.
	// Converted together with its
	// identical twin below (59411a155371): a half-converted pair renumbers
	// the survivor's site ID, so gate (h) refuses the split state.
	db.Table("locations_excluded").Clauses(clause.Insert{Modifier: "IGNORE"}).
		Create(map[string]interface{}{"locationid": req.ID, "groupid": req.GroupID, "userid": myid})

	queueExcludeRemap(req.ID)

	// If byname, also exclude all locations with the same name.
	if req.Byname {
		var name string
		db.Table("locations").Select("name").Where("id = ?", req.ID).Scan(&name)
		if name != "" {
			var otherIDs []uint64
			db.Table("locations").Where("name = ? AND id != ?", name, req.ID).Pluck("id", &otherIDs)
			for _, otherID := range otherIDs {
				// Twin of
				// 666504e10980 above.
				db.Table("locations_excluded").Clauses(clause.Insert{Modifier: "IGNORE"}).
					Create(map[string]interface{}{"locationid": otherID, "groupid": req.GroupID, "userid": myid})
				queueExcludeRemap(otherID)
			}
		}
	}

	return c.JSON(fiber.Map{"success": true})
}

func queueExcludeRemap(locationID uint64) {
	var wkt string
	database.DBConn.Table("locations").
		Select("ST_AsText(COALESCE(ourgeometry, geometry))").
		Where("id = ?", locationID).
		Scan(&wkt)
	if wkt == "" {
		return
	}
	go queue.QueueTask(queue.TaskRemapPostcodes, map[string]interface{}{
		"location_id": locationID,
		"polygon":     wkt,
	})
}

// --- KML to WKT conversion ---

type ConvertKMLRequest struct {
	Action string `json:"action"`
	KML    string `json:"kml"`
}

type kmlDocument struct {
	XMLName  xml.Name      `xml:"kml"`
	Document kmlDocElement `xml:",any"`
}

type kmlDocElement struct {
	Placemarks []kmlPlacemark `xml:"Placemark"`
}

type kmlPlacemark struct {
	Polygon kmlPolygon `xml:"Polygon"`
}

type kmlPolygon struct {
	OuterBoundaryIs kmlOuterBoundary `xml:"outerBoundaryIs"`
}

type kmlOuterBoundary struct {
	LinearRing kmlLinearRing `xml:"LinearRing"`
}

type kmlLinearRing struct {
	Coordinates string `xml:"coordinates"`
}

// ConvertKML handles POST /locations/kml - converts KML XML to WKT format.
func ConvertKML(c *fiber.Ctx) error {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req ConvertKMLRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.KML == "" {
		return fiber.NewError(fiber.StatusBadRequest, "kml is required")
	}

	var kml kmlDocument
	if err := xml.Unmarshal([]byte(req.KML), &kml); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid KML XML")
	}

	var coordsStr string
	for _, pm := range kml.Document.Placemarks {
		coords := strings.TrimSpace(pm.Polygon.OuterBoundaryIs.LinearRing.Coordinates)
		if coords != "" {
			coordsStr = coords
			break
		}
	}

	if coordsStr == "" {
		return fiber.NewError(fiber.StatusBadRequest, "No polygon coordinates found in KML")
	}

	// KML coordinates are "lng,lat[,alt]" separated by whitespace.
	// WKT needs "lng lat" pairs separated by commas.
	fields := strings.Fields(coordsStr)
	wktPairs := make([]string, 0, len(fields))

	for _, field := range fields {
		parts := strings.Split(field, ",")
		if len(parts) < 2 {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid coordinate format in KML")
		}

		lngVal, err := strconv.ParseFloat(strings.TrimSpace(parts[0]), 64)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid longitude in KML coordinates")
		}
		latVal, err := strconv.ParseFloat(strings.TrimSpace(parts[1]), 64)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid latitude in KML coordinates")
		}

		wktPairs = append(wktPairs, strconv.FormatFloat(lngVal, 'f', -1, 64)+" "+strconv.FormatFloat(latVal, 'f', -1, 64))
	}

	wkt := "POLYGON((" + strings.Join(wktPairs, ",") + "))"

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"wkt":    wkt,
	})
}
