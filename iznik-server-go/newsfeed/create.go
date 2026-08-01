package newsfeed

import (
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"log"
)

// Newsfeed type constants.
const (
	TypeCommunityEvent      = "CommunityEvent"
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
		// ORM migration site 1a6871aa02b9 (wave 4). The LEFT JOIN matters: a
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
		// ORM migration site f615ed45438f (wave 1).
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
		// ORM migration site 9c0779fdc3cc (wave 1).
		db.Table("users").Select("COALESCE(newsfeedmodstatus, 'Unmoderated')").Where("id = ?", userid).Scan(&modStatus)

		var spamCount int64
		// ORM migration site 606016a06713 (wave 1).
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
		// ORM migration site 94168ea2d29c (wave 1).
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
		// ORM migration site def955a54c71 (wave 1).
		db.Table("groups").Select("nameshort").Where("id = ?", groupid).Scan(&groupName)
		if groupName != "" {
			location = &groupName
		}
	}

	pos := fmt.Sprintf("ST_GeomFromText('POINT(%f %f)', %d)", *lng, *lat, utils.SRID)

	// Use the underlying sql.DB to get LastInsertId() directly from the MySQL protocol
	// response — never issue a separate SELECT LAST_INSERT_ID() as it's unsafe under
	// parallel load (GORM's connection pool may assign a different connection).
	sqlDB, err := db.DB()
	if err != nil {
		return 0, err
	}
	sqlResult, err := sqlDB.Exec(
		fmt.Sprintf("INSERT INTO newsfeed (`type`, userid, groupid, eventid, volunteeringid, position, location, hidden, deleted, reviewrequired, pinned) "+
			"VALUES (?, ?, ?, ?, ?, %s, ?, %s, NULL, 0, 0)", pos, hidden),
		nfType, userid, groupid, eventid, volunteeringid, location,
	)

	if err != nil {
		log.Printf("Failed to create newsfeed entry: %v", err)
		return 0, err
	}

	var id uint64
	lastID, err := sqlResult.LastInsertId()
	if err == nil && lastID > 0 {
		id = uint64(lastID)
	}

	return id, nil
}
