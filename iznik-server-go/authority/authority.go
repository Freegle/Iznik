package authority

import (
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// AreaCodes maps OS area codes to friendly names.
var AreaCodes = map[string]string{
	"CUN": "Country", // Not an OS code
	"CTY": "County Council",
	"CED": "County Electoral Division",
	"DIS": "District Council",
	"DIW": "District Ward",
	"EUR": "European Region",
	"GLA": "Greater London Authority",
	"LAC": "Greater London Authority Assembly Constituency",
	"LBO": "London Borough",
	"LBW": "London Borough Ward",
	"MTD": "Metropolitan District",
	"MTW": "Metropolitan District Ward",
	"SPE": "Scottish Parliament Electoral Region",
	"SPC": "Scottish Parliament Constituency",
	"UTA": "Unitary Authority",
	"UTE": "Unitary Authority Electoral Division",
	"UTW": "Unitary Authority Ward",
	"WAE": "Welsh Assembly Electoral Region",
	"WAC": "Welsh Assembly Constituency",
	"WMC": "Westminster Constituency",
	"WST": "Waste Authority",
}

// Authority represents a local authority.
type Authority struct {
	ID       uint64                   `json:"id"`
	Name     string                   `json:"name"`
	AreaCode *string                  `json:"area_code"`
	Polygon  string                   `json:"polygon"`
	Centre   Centre                   `json:"centre"`
	Groups   []Group                  `json:"groups"`
	Stats    map[string]PostcodeStats `json:"stats,omitempty"`
}

// Centre represents the centre point of an authority.
type Centre struct {
	Lat float64 `json:"lat"`
	Lng float64 `json:"lng"`
}

// Group represents a Freegle group overlapping with an authority.
type Group struct {
	ID          uint64  `json:"id"`
	Nameshort   string  `json:"nameshort"`
	Namefull    *string `json:"namefull"`
	Namedisplay string  `json:"namedisplay"`
	Lat         float64 `json:"lat"`
	Lng         float64 `json:"lng"`
	Poly        *string `json:"poly"`
	Overlap     float64 `json:"overlap"`
	Overlap2    float64 `json:"overlap2"`
}

// SearchResult represents an authority search result.
type SearchResult struct {
	ID       uint64  `json:"id"`
	Name     string  `json:"name"`
	AreaCode *string `json:"area_code"`
}

// Single returns a single authority by ID.
// @Summary Get authority by ID
// @Description Returns a single authority by ID with polygon, centre, and overlapping groups. Optionally includes stats.
// @Tags authority
// @Produce json
// @Param id path integer true "Authority ID"
// @Param stats query boolean false "Include statistics"
// @Param start query string false "Stats start date (default: 365 days ago)"
// @Param end query string false "Stats end date (default: today)"
// @Success 200 {object} Authority
// @Failure 404 {object} fiber.Error "Authority not found"
// @Router /authority/{id} [get]
func Single(c *fiber.Ctx) error {
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid authority ID")
	}

	// Parse stats query parameters.
	includeStats := strings.ToLower(c.Query("stats")) == "true" || c.Query("stats") == "1"
	start := c.Query("start", "365 days ago")
	end := c.Query("end", "today")

	db := database.DBConn

	// Query authority details.
	var authRow struct {
		ID       uint64  `gorm:"column:id"`
		Name     string  `gorm:"column:name"`
		AreaCode *string `gorm:"column:area_code"`
		Polygon  string  `gorm:"column:polygon"`
		Lat      float64 `gorm:"column:lat"`
		Lng      float64 `gorm:"column:lng"`
	}

	result := db.Table("authorities").
		Select("id, name, area_code, ST_AsText(COALESCE(simplified, polygon)) AS polygon, "+
			"ST_Y(ST_CENTROID(polygon)) AS lat, ST_X(ST_CENTROID(polygon)) AS lng").
		Where("id = ?", id).
		Scan(&authRow)

	if result.Error != nil || result.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Authority not found")
	}

	// Map area code to friendly name.
	var areaCodeFriendly *string
	if authRow.AreaCode != nil {
		if friendly, ok := AreaCodes[*authRow.AreaCode]; ok {
			areaCodeFriendly = &friendly
		}
	}

	responseGroups := GroupsForAuthority(id)

	authority := Authority{
		ID:       authRow.ID,
		Name:     authRow.Name,
		AreaCode: areaCodeFriendly,
		Polygon:  authRow.Polygon,
		Centre: Centre{
			Lat: authRow.Lat,
			Lng: authRow.Lng,
		},
		Groups: responseGroups,
	}

	// Include stats if requested.
	if includeStats {
		stats, err := GetStatsByAuthority(id, start, end)
		if err == nil {
			authority.Stats = stats
		}
	}

	return c.JSON(authority)
}

// GroupsForAuthority returns the live Freegle groups whose catchment meaningfully
// overlaps an authority's boundary. "Meaningfully" means at least 5% of the group sits
// inside the authority, or the authority is at least 5% of the group - so a small
// authority inside a big group still counts.
//
// Shared with the Partnerships page, which uses it to work out which groups a council
// sponsorship covers.
func GroupsForAuthority(id uint64) []Group {
	db := database.DBConn

	var groups []struct {
		ID        uint64  `gorm:"column:id"`
		Nameshort string  `gorm:"column:nameshort"`
		Namefull  *string `gorm:"column:namefull"`
		Lat       float64 `gorm:"column:lat"`
		Lng       float64 `gorm:"column:lng"`
		Poly      *string `gorm:"column:poly"`
		Overlap   float64 `gorm:"column:overlap"`
		Overlap2  float64 `gorm:"column:overlap2"`
	}

	// ST_Area errors on anything that isn't a polygon, and a single such group aborts the
	// whole query rather than being skipped - so the geometry-type check has to guard the
	// area calls themselves, not just filter rows. A group whose catchment is not an area
	// cannot meaningfully overlap an authority anyway, so it scores zero.
	const polygonal = "ST_GeometryType(polyindex) IN ('POLYGON', 'MULTIPOLYGON') " +
		"AND ST_GeometryType(Coalesce(simplified, polygon)) IN ('POLYGON', 'MULTIPOLYGON')"

	db.Table("groups").
		Select("groups.id, nameshort, namefull, lat, lng, "+
			"CASE WHEN poly IS NOT NULL THEN poly ELSE polyofficial END AS poly, "+
			"CASE WHEN NOT ("+polygonal+") THEN 0 "+
			"WHEN ST_GeometryType(St_intersection(polyindex, Coalesce(simplified, polygon))) != 'GEOMCOLLECTION' THEN "+
			"CASE WHEN polyindex = Coalesce(simplified, polygon) THEN 1 "+
			"ELSE St_area(St_intersection(polyindex, Coalesce(simplified, polygon))) / St_area(polyindex) "+
			"END "+
			"ELSE 0 "+
			"END AS overlap, "+
			"CASE WHEN NOT ("+polygonal+") THEN 0 "+
			"WHEN ST_GeometryType(St_intersection(polyindex, Coalesce(simplified, polygon))) != 'GEOMCOLLECTION' THEN "+
			"CASE WHEN polyindex = Coalesce(simplified, polygon) THEN 1 "+
			"ELSE St_area(polyindex) / St_area(St_intersection(polyindex, Coalesce(simplified, polygon))) "+
			"END "+
			"ELSE 0 "+
			"END AS overlap2").
		Joins("INNER JOIN authorities ON ( polyindex = Coalesce(simplified, polygon) OR St_intersects(polyindex, Coalesce(simplified, polygon)) )").
		Where("type = ? AND publish = 1 AND onmap = 1 AND authorities.id = ?", utils.GROUP_TYPE_FREEGLE, id).
		Scan(&groups)

	// Build response groups, filtering by overlap threshold.
	var responseGroups []Group
	for _, g := range groups {
		overlap := g.Overlap
		if overlap > 0.95 {
			overlap = 1
		}

		// Exclude minor overlaps.
		if overlap >= 0.05 || g.Overlap2 >= 0.05 {
			namedisplay := g.Nameshort
			if g.Namefull != nil && len(*g.Namefull) > 0 {
				namedisplay = *g.Namefull
			}

			responseGroups = append(responseGroups, Group{
				ID:          g.ID,
				Nameshort:   g.Nameshort,
				Namefull:    g.Namefull,
				Namedisplay: namedisplay,
				Lat:         g.Lat,
				Lng:         g.Lng,
				Poly:        g.Poly,
				Overlap:     overlap,
				Overlap2:    g.Overlap2,
			})
		}
	}

	if responseGroups == nil {
		responseGroups = []Group{}
	}

	return responseGroups
}

// Search searches authorities by name.
// @Summary Search authorities
// @Description Searches authorities by name and returns matching results
// @Tags authority
// @Produce json
// @Param search query string true "Search term"
// @Param limit query integer false "Maximum results (default 10)"
// @Success 200 {array} SearchResult
// @Router /authority [get]
func Search(c *fiber.Ctx) error {
	search := c.Query("search")
	if search == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Search term required")
	}

	limit := 10
	if limitStr := c.Query("limit"); limitStr != "" {
		if l, err := strconv.Atoi(limitStr); err == nil && l > 0 {
			limit = l
		}
	}

	// Remove non-alphanumeric characters except spaces.
	var cleanSearch strings.Builder
	for _, r := range search {
		if (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == ' ' {
			cleanSearch.WriteRune(r)
		}
	}
	searchTerm := "%" + cleanSearch.String() + "%"

	db := database.DBConn

	var results []struct {
		ID       uint64  `gorm:"column:id"`
		Name     string  `gorm:"column:name"`
		AreaCode *string `gorm:"column:area_code"`
	}

	db.Table("authorities").Select("id, name, area_code").Where("name LIKE ?", searchTerm).Limit(limit).Scan(&results)

	// Map area codes to friendly names.
	var searchResults []SearchResult
	for _, r := range results {
		var areaCodeFriendly *string
		if r.AreaCode != nil {
			if friendly, ok := AreaCodes[*r.AreaCode]; ok {
				areaCodeFriendly = &friendly
			}
		}

		searchResults = append(searchResults, SearchResult{
			ID:       r.ID,
			Name:     r.Name,
			AreaCode: areaCodeFriendly,
		})
	}

	if searchResults == nil {
		searchResults = []SearchResult{}
	}

	return c.JSON(searchResults)
}
