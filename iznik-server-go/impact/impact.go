package impact

import (
	"fmt"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)


// NationalResponse is the JSON shape returned by GET /api/impact/national.
type NationalResponse struct {
	Year          int     `json:"year"`
	TotalWeightKg float64 `json:"totalWeightKg"`
	TotalGifts    int64   `json:"totalGifts"`
	TotalMembers  int64   `json:"totalMembers"`
	GroupCount    int     `json:"groupCount"`
}

// TopCommunity represents a single ranked community in the top-performers list.
type TopCommunity struct {
	Name     string  `json:"name"`
	WeightKg float64 `json:"weightKg"`
}

// TopResponse is the JSON shape returned by GET /api/impact/top.
type TopResponse struct {
	Year        int            `json:"year"`
	Communities []TopCommunity `json:"communities"`
}

// GetNational handles GET /api/impact/national?year=YYYY.
// Returns national aggregate impact stats for all active Freegle groups.
func GetNational(c *fiber.Ctx) error {
	year := c.QueryInt("year", defaultYear())
	start := fmt.Sprintf("%d-01-01", year)
	end := fmt.Sprintf("%d-12-31", year)

	db := database.DBConn

	// Total weight (kg) — sum of pre-aggregated Weight stats across all Freegle groups.
	var weightTotal struct {
		Total *float64 `gorm:"column:total"`
	}
	db.Raw(`
		SELECT SUM(s.count) AS total
		FROM stats s
		INNER JOIN groups g ON s.groupid = g.id
		WHERE g.type = 'Freegle' AND g.deleted IS NULL
		  AND s.type = 'Weight'
		  AND s.date >= ? AND s.date <= ?`,
		start, end).Scan(&weightTotal)

	totalWeightKg := float64(0)
	if weightTotal.Total != nil {
		totalWeightKg = *weightTotal.Total
	}

	// Total gifts (items gifted via outcomes).
	var giftsTotal struct {
		Total *int64 `gorm:"column:total"`
	}
	db.Raw(`
		SELECT SUM(s.count) AS total
		FROM stats s
		INNER JOIN groups g ON s.groupid = g.id
		WHERE g.type = 'Freegle' AND g.deleted IS NULL
		  AND s.type = 'Outcomes'
		  AND s.date >= ? AND s.date <= ?`,
		start, end).Scan(&giftsTotal)

	totalGifts := int64(0)
	if giftsTotal.Total != nil {
		totalGifts = *giftsTotal.Total
	}

	// Total members — latest ApprovedMemberCount per group, summed.
	var membersTotal struct {
		Total *int64 `gorm:"column:total"`
	}
	db.Raw(`
		SELECT SUM(s.count) AS total
		FROM stats s
		INNER JOIN groups g ON s.groupid = g.id
		WHERE g.type = 'Freegle' AND g.deleted IS NULL
		  AND s.type = 'ApprovedMemberCount'
		  AND s.date = (
		    SELECT MAX(s2.date) FROM stats s2
		    WHERE s2.groupid = s.groupid
		      AND s2.type = 'ApprovedMemberCount'
		      AND s2.date <= ?
		  )`, end).Scan(&membersTotal)

	totalMembers := int64(0)
	if membersTotal.Total != nil {
		totalMembers = *membersTotal.Total
	}

	// Group count — groups with any Weight stats in the year.
	var groupCount struct {
		Count int `gorm:"column:count"`
	}
	db.Raw(`
		SELECT COUNT(DISTINCT g.id) AS count
		FROM groups g
		INNER JOIN stats s ON s.groupid = g.id
		WHERE g.type = 'Freegle' AND g.deleted IS NULL
		  AND s.type = 'Weight' AND s.count > 0
		  AND s.date >= ? AND s.date <= ?`,
		start, end).Scan(&groupCount)

	return c.JSON(NationalResponse{
		Year:          year,
		TotalWeightKg: totalWeightKg,
		TotalGifts:    totalGifts,
		TotalMembers:  totalMembers,
		GroupCount:    groupCount.Count,
	})
}

// GetTop handles GET /api/impact/top?year=YYYY&limit=N.
// Returns the top N Freegle communities sorted by total weight reused in the year.
func GetTop(c *fiber.Ctx) error {
	year := c.QueryInt("year", defaultYear())
	limit := c.QueryInt("limit", 5)
	if limit <= 0 || limit > 50 {
		limit = 5
	}

	start := fmt.Sprintf("%d-01-01", year)
	end := fmt.Sprintf("%d-12-31", year)

	db := database.DBConn

	type communityRow struct {
		Name     string  `gorm:"column:name"`
		WeightKg float64 `gorm:"column:weight_kg"`
	}

	var rows []communityRow
	db.Raw(`
		SELECT g.nameshort AS name, SUM(s.count) AS weight_kg
		FROM stats s
		INNER JOIN groups g ON s.groupid = g.id
		WHERE g.type = 'Freegle' AND g.deleted IS NULL
		  AND s.type = 'Weight'
		  AND s.date >= ? AND s.date <= ?
		GROUP BY g.id, g.nameshort
		ORDER BY weight_kg DESC
		LIMIT ?`,
		start, end, limit).Scan(&rows)

	communities := make([]TopCommunity, len(rows))
	for i, r := range rows {
		communities[i] = TopCommunity{Name: r.Name, WeightKg: r.WeightKg}
	}

	return c.JSON(TopResponse{
		Year:        year,
		Communities: communities,
	})
}

// defaultYear returns the most recently completed calendar year.
func defaultYear() int {
	return time.Now().Year() - 1
}
