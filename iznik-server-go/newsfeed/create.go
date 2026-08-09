package newsfeed

import (
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
	"log"
)

// Newsfeed type constants.
const (
	TypeCommunityEvent       = "CommunityEvent"
	TypeVolunteerOpportunity = "VolunteerOpportunity"
)

// CreateNewsfeedEntry creates a newsfeed entry for side effects like addGroup.
//
// When a community event or volunteering opportunity is added to a group, a
// newsfeed entry is created so nearby users see it. Position is derived from
// the user's lat/lng, falling back to the group's lat/lng.
//
// Behaviour:
// - Checks spam/suppression status (sets hidden=NOW() for suppressed/spammer users)
// - Duplicate protection (skips if last entry by user has same type)
// - Sets location display name from the group
func CreateNewsfeedEntry(nfType string, userid uint64, groupid uint64, eventid *uint64, volunteeringid *uint64) (uint64, error) {
	db := database.DBConn

	// Get position: try user location first, fall back to group.
	var lat, lng *float64

	// Try user location first (via lastlocation FK to locations table).
	if userid > 0 {
		type UserLoc struct {
			Lat *float64
			Lng *float64
		}
		var ul UserLoc
		// The LEFT JOIN matters: a
		// user with no lastlocation must still yield a row, with NULL lat/lng,
		// which is why both fields are pointers.
		db.Table("users u").
			Select("l.lat, l.lng").
			Joins("LEFT JOIN locations l ON l.id = u.lastlocation").
			Where("u.id = ?", userid).
			Scan(&ul)
		lat = ul.Lat
		lng = ul.Lng
	}

	// Fall back to group location.
	if lat == nil && groupid > 0 {
		type GroupLoc struct {
			Lat *float64
			Lng *float64
		}
		var gl GroupLoc
		db.Table("groups").Select("lat, lng").Where("id = ?", groupid).Scan(&gl)
		lat = gl.Lat
		lng = gl.Lng
	}

	if lat == nil || lng == nil {
		// Can't create a newsfeed entry without a location.
		return 0, nil
	}

	// If user is suppressed or a known spammer, set hidden=NOW() so only they can see it.
	hidden := "NULL"
	if userid > 0 {
		var modStatus string
		db.Table("users").Select("COALESCE(newsfeedmodstatus, 'Unmoderated')").Where("id = ?", userid).Scan(&modStatus)

		var spamCount int64
		db.Table("spam_users").Where("userid = ? AND collection = ?", userid, utils.SPAM_COLLECTION_SPAMMER).Count(&spamCount)

		if modStatus == utils.NEWSFEED_MODSTATUS_SUPPRESSED || spamCount > 0 {
			hidden = "NOW()"
		}
	}

	// Duplicate protection: skip if last entry by this user was the same type.
	if userid > 0 {
		type LastEntry struct {
			Type *string
		}
		var last LastEntry
		db.Table("newsfeed").Select("`type`").Where("userid = ?", userid).Order("id DESC").Limit(1).Scan(&last)

		if last.Type != nil && *last.Type == nfType {
			// Last entry by this user was the same type - skip to prevent duplicate.
			return 0, nil
		}
	}

	// Set location display name from the group.
	var location *string
	if groupid > 0 {
		var groupName string
		db.Table("groups").Select("nameshort").Where("id = ?", groupid).Scan(&groupName)
		if groupName != "" {
			location = &groupName
		}
	}

	// Same zero-precision-change
	// conversion as newsfeed.go's createRefer/createPost (10bcbd6a6404,
	// f961504c334d): the WKT text is built exactly as before via
	// fmt.Sprintf("POINT(%f %f)", ...), then bound as a genuine ST_GeomFromText
	// argument rather than spliced into the SQL text. hidden is a fixed
	// two-way literal ("NULL" or "NOW()"), never a bound value, so
	// gorm.Expr(hidden) with no args is exact. deleted/reviewrequired/pinned
	// were always fixed literals (NULL, 0, 0), not runtime values.
	row := map[string]interface{}{
		"type":           nfType,
		"userid":         userid,
		"groupid":        groupid,
		"eventid":        eventid,
		"volunteeringid": volunteeringid,
		"position":       gorm.Expr("ST_GeomFromText(?, ?)", fmt.Sprintf("POINT(%f %f)", *lng, *lat), utils.SRID),
		"location":       location,
		"hidden":         gorm.Expr(hidden),
		"deleted":        gorm.Expr("NULL"),
		"reviewrequired": gorm.Expr("0"),
		"pinned":         gorm.Expr("0"),
	}
	if err := db.Table("newsfeed").Create(row).Error; err != nil {
		log.Printf("Failed to create newsfeed entry: %v", err)
		return 0, err
	}

	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	return id, nil
}
