package authority

import (
	"fmt"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

// Constants for authority statistics types.
const (
	TypeOffer    = "Offer"
	TypeWanted   = "Wanted"
	StatSearches = "Searches"
	StatWeight   = "Weight"
	StatOutcomes = "Outcomes"
	StatReplies  = "Replies"
)

// PostcodeStats represents stats for a partial postcode.
type PostcodeStats struct {
	Offer    int     `json:"Offer"`
	Wanted   int     `json:"Wanted"`
	Searches int     `json:"Searches"`
	Weight   float64 `json:"Weight"`
	Replies  int     `json:"Replies"`
	Outcomes int     `json:"Outcomes"`
}

// GetStatsByAuthority retrieves statistics for an authority area.
// Returns a map of partial postcodes to their stats.
func GetStatsByAuthority(authorityID uint64, start, end string) (map[string]PostcodeStats, error) {
	// Every statement here must run on the WRITE host (under the read/write
	// split a plain SELECT would otherwise route to the read replica, where the
	// temp table does not exist). .Clauses(dbresolver.Write) is a no-op when no
	// replica is configured.
	ret := make(map[string]PostcodeStats)

	// Temporary tables are CONNECTION-local, but DBConn is a pool: without
	// pinning, the CREATE TEMPORARY TABLE and the SELECTs that read `pc` can
	// land on different pooled connections. A transaction is the pin that
	// dbresolver respects: Begin() on the Write clause fixes ONE connection on
	// the source pool and every statement inside stays on it (gorm.Connection()
	// does NOT give that guarantee under dbresolver - its per-statement routing
	// acquires fresh pool connections, which both defeats the pin and can
	// deadlock a capped pool). Read-only + temp table, so Rollback suffices.
	txh := database.DBConn.Clauses(dbresolver.Write).Begin()
	if txh.Error != nil {
		return nil, txh.Error
	}
	defer txh.Rollback()

	connErr := func(tx *gorm.DB) error {
		writer := func() *gorm.DB { return tx }

		// Parse dates, defaulting to last 365 days.
		startTime, err := parseRelativeDate(start)
		if err != nil {
			startTime = time.Now().AddDate(-1, 0, 0)
		}
		endTime, err := parseRelativeDate(end)
		if err != nil {
			endTime = time.Now()
		}

		startStr := startTime.Format("2006-01-02")
		// NB "23:59:59" cannot live inside the Format layout - Go treats those
		// digits as reference-time directives and renders garbage (e.g.
		// "78:369:369"), which MySQL cannot parse, so every BETWEEN silently
		// matched nothing and this endpoint always returned empty.
		endStr := endTime.Format("2006-01-02") + " 23:59:59"

		// Create temporary table of locationids for postcodes within the authority.
		// Use a temporary table of postcode locationids within the authority.
		if err := writer().Exec(`DROP TEMPORARY TABLE IF EXISTS pc`).Error; err != nil {
			return err
		}

		err = writer().Exec(`CREATE TEMPORARY TABLE pc AS (
		SELECT locationid
		FROM authorities
		INNER JOIN locations_spatial ON authorities.id = ?
			AND ST_Contains(authorities.polygon, locations_spatial.geometry)
	)`, authorityID).Error
		if err != nil {
			return err
		}

		// Query offers and wanteds.
		for _, msgType := range []string{TypeOffer, TypeWanted} {
			var stats []struct {
				PartialPostcode string `gorm:"column:PartialPostcode"`
				Count           int    `gorm:"column:count"`
			}

			if err := writer().Table("pc").
				Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
				Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
				Joins("INNER JOIN locations ON messages.locationid = locations.id").
				Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ?",
					utils.LOCATION_TYPE_POSTCODE, msgType, startStr, endStr).
				Group("PartialPostcode").
				Order("locations.name").
				Scan(&stats).Error; err != nil {
				return err
			}

			for _, stat := range stats {
				ps := ret[stat.PartialPostcode]
				if msgType == TypeOffer {
					ps.Offer += stat.Count
				} else {
					ps.Wanted += stat.Count
				}
				ret[stat.PartialPostcode] = ps
			}
		}

		// Query replies (Interested chat messages) for non-bulk messages.
		// Bulk messages are excluded here; their replies are counted separately below.
		for _, msgType := range []string{TypeOffer, TypeWanted} {
			var stats []struct {
				PartialPostcode string `gorm:"column:PartialPostcode"`
				Count           int    `gorm:"column:count"`
			}

			if err := writer().Table("pc").
				Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
				Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
				Joins("INNER JOIN locations ON messages.locationid = locations.id").
				Joins("INNER JOIN chat_messages cm ON messages.id = cm.refmsgid AND cm.type = ?", utils.CHAT_MESSAGE_INTERESTED).
				Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM messages_bulk_items bi WHERE bi.msgid = messages.id)",
					utils.LOCATION_TYPE_POSTCODE, msgType, startStr, endStr).
				Group("PartialPostcode").
				Order("locations.name").
				Scan(&stats).Error; err != nil {
				return err
			}

			for _, stat := range stats {
				ps := ret[stat.PartialPostcode]
				ps.Replies += stat.Count
				ret[stat.PartialPostcode] = ps
			}
		}

		// Query replies for bulk messages: structured interest rows.
		for _, msgType := range []string{TypeOffer, TypeWanted} {
			var stats []struct {
				PartialPostcode string `gorm:"column:PartialPostcode"`
				Count           int    `gorm:"column:count"`
			}

			if err := writer().Table("pc").
				Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
				Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
				Joins("INNER JOIN locations ON messages.locationid = locations.id").
				Joins("INNER JOIN messages_bulk_items_interest mbi ON mbi.msgid = messages.id").
				Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ?",
					utils.LOCATION_TYPE_POSTCODE, msgType, startStr, endStr).
				Group("PartialPostcode").
				Order("locations.name").
				Scan(&stats).Error; err != nil {
				return err
			}

			for _, stat := range stats {
				ps := ret[stat.PartialPostcode]
				ps.Replies += stat.Count
				ret[stat.PartialPostcode] = ps
			}
		}

		// Query replies for bulk messages: Interested chat messages where no structured
		// interest row exists for that user (free-text replies not via the interest flow).
		for _, msgType := range []string{TypeOffer, TypeWanted} {
			var stats []struct {
				PartialPostcode string `gorm:"column:PartialPostcode"`
				Count           int    `gorm:"column:count"`
			}

			if err := writer().Table("pc").
				Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
				Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
				Joins("INNER JOIN locations ON messages.locationid = locations.id").
				Joins("INNER JOIN chat_messages cm ON messages.id = cm.refmsgid AND cm.type = ?", utils.CHAT_MESSAGE_INTERESTED).
				Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM messages_bulk_items bi WHERE bi.msgid = messages.id) AND NOT EXISTS ( SELECT 1 FROM messages_bulk_items_interest WHERE msgid = messages.id AND userid = cm.userid )",
					utils.LOCATION_TYPE_POSTCODE, msgType, startStr, endStr).
				Group("PartialPostcode").
				Order("locations.name").
				Scan(&stats).Error; err != nil {
				return err
			}

			for _, stat := range stats {
				ps := ret[stat.PartialPostcode]
				ps.Replies += stat.Count
				ret[stat.PartialPostcode] = ps
			}
		}

		// Query outcomes (Taken/Received) for non-bulk messages.
		// Bulk messages are excluded here; their per-item outcomes are counted separately below.
		var outcomeStats []struct {
			PartialPostcode string `gorm:"column:PartialPostcode"`
			Count           int    `gorm:"column:count"`
		}

		if err := writer().Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) AS count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ? AND outcome IN (?, ?) AND NOT EXISTS (SELECT 1 FROM messages_bulk_items WHERE msgid = messages.id)",
				utils.LOCATION_TYPE_POSTCODE, startStr, endStr, utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED).
			Group("PartialPostcode").
			Order("locations.name").
			Scan(&outcomeStats).Error; err != nil {
			return err
		}

		for _, stat := range outcomeStats {
			ps := ret[stat.PartialPostcode]
			ps.Outcomes += stat.Count
			ret[stat.PartialPostcode] = ps
		}

		// Query outcomes for bulk messages: SUM(bi.quantity) for items marked taken
		// (available=0), one item at a time rather than one per message.
		var bulkOutcomeStats []struct {
			PartialPostcode string `gorm:"column:PartialPostcode"`
			Count           int    `gorm:"column:count"`
		}

		if err := writer().Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, CAST(SUM(bi.quantity) AS SIGNED) AS count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items bi ON bi.msgid = messages.id AND bi.available = 0").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				utils.LOCATION_TYPE_POSTCODE, startStr, endStr).
			Group("PartialPostcode").
			Order("locations.name").
			Scan(&bulkOutcomeStats).Error; err != nil {
			return err
		}

		for _, stat := range bulkOutcomeStats {
			ps := ret[stat.PartialPostcode]
			ps.Outcomes += stat.Count
			ret[stat.PartialPostcode] = ps
		}

		// Query outcomes for bulk messages collected in-app: interest rows with
		// state='Collected'. In-app collection deducts from bi.quantity before the
		// owner may flip available=0, so the available=0 arm only counts what remained
		// at the flip; this arm counts what was collected via the interest flow.
		// Together the two arms sum to the true total with no overlap.
		var bulkInterestOutcomeStats []struct {
			PartialPostcode string `gorm:"column:PartialPostcode"`
			Count           int    `gorm:"column:count"`
		}

		if err := writer().Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, CAST(SUM(mbii.quantity) AS SIGNED) AS count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items_interest mbii ON mbii.msgid = messages.id AND mbii.state = 'Collected'").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				utils.LOCATION_TYPE_POSTCODE, startStr, endStr).
			Group("PartialPostcode").
			Order("locations.name").
			Scan(&bulkInterestOutcomeStats).Error; err != nil {
			return err
		}

		for _, stat := range bulkInterestOutcomeStats {
			ps := ret[stat.PartialPostcode]
			ps.Outcomes += stat.Count
			ret[stat.PartialPostcode] = ps
		}

		// Query weights.
		// First get the average weight.
		var avgResult struct {
			Average *float64 `gorm:"column:average"`
		}
		if err := writer().Table("items").Select("SUM(popularity * weight) / SUM(popularity) AS average").Where("weight IS NOT NULL AND weight != 0").Scan(&avgResult).Error; err != nil {
			return err
		}

		avg := float64(0)
		if avgResult.Average != nil {
			avg = *avgResult.Average
		}

		// Weight for non-bulk messages: existing per-message-item join.
		// Bulk messages are excluded here; their per-item weight is counted separately below.
		var weightStats []struct {
			PartialPostcode string  `gorm:"column:PartialPostcode"`
			Weight          float64 `gorm:"column:weight"`
		}

		// The WHERE/JOIN structure was
		// always an ordinary fixed toggle - the real blocker was avg, a
		// live-recomputed aggregate, spliced in via fmt.Sprintf("%f", avg) as
		// literal text rather than a bind. Moved onto a genuine bind
		// (Select's own placeholder support, same convention as
		// microvolunteering.go:170's "COALESCE(trustlevel, ?)"). This is a
		// deliberate precision IMPROVEMENT, not just a refactor: %f truncates
		// to 6 decimal places, discarding up to 5e-7 of the true float64 value
		// on every substitution, whereas a bind carries the full value MySQL's
		// wire protocol supports.
		//
		// DECISION: that per-value bound was weighed against its consequence,
		// not converted on the bound alone - authority/stats_precision_test.go
		// proves the worst-case aggregate drift on PostcodeStats.Weight, across
		// a deliberately generous 100,000 substituted rows, is 0.05kg. Fifty
		// grams on a kg-scale weight statistic is what justified converting
		// this site instead of leaving it raw; a kilogram-scale answer would
		// have meant staying raw instead.
		if err := writer().Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, SUM(COALESCE(weight, ?)) AS weight", avg).
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Joins("INNER JOIN messages_items mi ON messages.id = mi.msgid").
			Joins("INNER JOIN items i ON mi.itemid = i.id").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ? AND outcome IN (?, ?) AND NOT EXISTS (SELECT 1 FROM messages_bulk_items WHERE msgid = messages.id)",
				utils.LOCATION_TYPE_POSTCODE, startStr, endStr, utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED).
			Group("PartialPostcode").
			Order("locations.name").
			Scan(&weightStats).Error; err != nil {
			return err
		}

		for _, stat := range weightStats {
			ps := ret[stat.PartialPostcode]
			ps.Weight += stat.Weight
			ret[stat.PartialPostcode] = ps
		}

		// Weight for bulk messages: per item weight × quantity for items marked taken
		// (available=0). Matches by item name to the items catalogue; falls back to the
		// global average weight when there is no name match or the matched weight is 0.
		var bulkWeightStats []struct {
			PartialPostcode string  `gorm:"column:PartialPostcode"`
			Weight          float64 `gorm:"column:weight"`
		}

		// Same avg-precision fix as
		// 6d50d3895aa7 above; see that site's comment and
		// authority/stats_precision_test.go.
		if err := writer().Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, SUM(COALESCE(NULLIF(i.weight, 0), ?) * bi.quantity) AS weight", avg).
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items bi ON bi.msgid = messages.id AND bi.available = 0").
			Joins("LEFT JOIN items i ON i.name = bi.name").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				utils.LOCATION_TYPE_POSTCODE, startStr, endStr).
			Group("PartialPostcode").
			Order("locations.name").
			Scan(&bulkWeightStats).Error; err != nil {
			return err
		}

		for _, stat := range bulkWeightStats {
			ps := ret[stat.PartialPostcode]
			ps.Weight += stat.Weight
			ret[stat.PartialPostcode] = ps
		}

		// Weight for bulk messages collected in-app: mirrors the interest-Collected
		// outcomes arm above. Uses the same items-table name match and average fallback.
		var bulkInterestWeightStats []struct {
			PartialPostcode string  `gorm:"column:PartialPostcode"`
			Weight          float64 `gorm:"column:weight"`
		}

		// Same avg-precision fix as
		// 6d50d3895aa7 above; see that site's comment and
		// authority/stats_precision_test.go.
		if err := writer().Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, SUM(COALESCE(NULLIF(i.weight, 0), ?) * mbii.quantity) AS weight", avg).
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items_interest mbii ON mbii.msgid = messages.id AND mbii.state = 'Collected'").
			Joins("INNER JOIN messages_bulk_items bi ON bi.id = mbii.bulkitemid").
			Joins("LEFT JOIN items i ON i.name = bi.name").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				utils.LOCATION_TYPE_POSTCODE, startStr, endStr).
			Group("PartialPostcode").
			Order("locations.name").
			Scan(&bulkInterestWeightStats).Error; err != nil {
			return err
		}

		for _, stat := range bulkInterestWeightStats {
			ps := ret[stat.PartialPostcode]
			ps.Weight += stat.Weight
			ret[stat.PartialPostcode] = ps
		}

		// Query searches.
		var searchStats []struct {
			PartialPostcode string `gorm:"column:PartialPostcode"`
			Count           int    `gorm:"column:count"`
		}

		if err := writer().Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) AS count").
			Joins("INNER JOIN search_history ON search_history.locationid = pc.locationid").
			Joins("INNER JOIN locations ON search_history.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND search_history.date BETWEEN ? AND ?",
				utils.LOCATION_TYPE_POSTCODE, startStr, endStr).
			Group("PartialPostcode").
			Order("locations.name").
			Scan(&searchStats).Error; err != nil {
			return err
		}

		for _, stat := range searchStats {
			ps := ret[stat.PartialPostcode]
			ps.Searches += stat.Count
			ret[stat.PartialPostcode] = ps
		}

		// Clean up temporary table.
		writer().Exec(`DROP TEMPORARY TABLE IF EXISTS pc`)

		return nil
	}(txh)

	return ret, connErr
}

// parseRelativeDate parses dates like "365 days ago", "30 days ago", "today".
func parseRelativeDate(s string) (time.Time, error) {
	if s == "" || s == "today" {
		return time.Now(), nil
	}

	// Try parsing as a standard date first.
	t, err := time.Parse("2006-01-02", s)
	if err == nil {
		return t, nil
	}

	// Try parsing relative dates like "365 days ago", "30 days ago".
	var days int
	if _, err := fmt.Sscanf(s, "%d days ago", &days); err == nil {
		return time.Now().AddDate(0, 0, -days), nil
	}

	// Try single day "1 day ago".
	if _, err := fmt.Sscanf(s, "%d day ago", &days); err == nil {
		return time.Now().AddDate(0, 0, -days), nil
	}

	return time.Time{}, fmt.Errorf("cannot parse date: %s", s)
}
