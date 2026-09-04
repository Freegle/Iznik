package rippling

import (
	"log"

	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// ClearRippledMembership turns a ripple-created membership into an ordinary one.
//
// Rippling auto-joins a poster to every group their post reached, flagged
// memberships.rippled = 1 (ExpandService::addPosterMembershipToRippledGroups). That flag
// records how the membership came about, and it gates what the group's moderators may do:
// a ripple-only membership is not a relationship with the community, so they cannot start
// a chat off the back of it (Discourse 10102).
//
// Once the member does something that makes it a real membership - joins the group
// themselves, or moves house into its area - the flag has stopped being true and must go,
// or they stay permanently uncontactable there. Joining used to return early because a
// membership row already existed, so the flag survived every subsequent join.
//
// This does change rippling attribution for that member: EstablishedOriginMemberExists
// requires mem.rippled = 0, so a cleared membership counts them as an established member
// of the group from then on. That is the honest reading - they really are one now.
//
// reason is recorded in the log so the provenance is not simply lost. Best-effort: never
// fails the caller's own action.
func ClearRippledMembership(db *gorm.DB, userid uint64, groupid uint64, reason string) {
	result := db.Table("memberships").
		Where("userid = ? AND groupid = ? AND rippled = 1", userid, groupid).
		Update("rippled", 0)

	if result.Error != nil {
		log.Printf("Failed to clear rippled flag for user %d group %d: %v", userid, groupid, result.Error)
		return
	}

	if result.RowsAffected == 0 {
		return
	}

	// memberships_history is deliberately left alone: its rippled flag records how the
	// membership was originally created, which is still true and which the rippling
	// attribution backfill reads. Only the live membership changes.
	//
	// The log row is a Group/Joined, so it also becomes the member's most recent join for
	// that group. Rejoin suppression (ExpandService) keys on the most recent Group/Joined
	// being a ripple-join, so from here on the group counts as one they joined ordinarily -
	// which is exactly what has happened.
	text := "Ripple-created membership became ordinary: " + reason
	flog.Log(flog.LogEntry{
		Type:    flog.LOG_TYPE_GROUP,
		Subtype: flog.LOG_SUBTYPE_JOINED,
		Groupid: &groupid,
		User:    &userid,
		Byuser:  &userid,
		Text:    &text,
	})
}

// ClearRippledMembershipsAtLocation clears the ripple flag on every ripple-created
// membership whose group's area contains the given point - the member has moved into
// those communities, so they are ordinary members of them now.
//
// polyindex is the group's DPA-or-CGA. Groups holding only a POINT placeholder contain
// nothing and are skipped, the same test the reply gate uses (chat/chatmessage.go).
func ClearRippledMembershipsAtLocation(db *gorm.DB, userid uint64, lat float64, lng float64) {
	var groupids []uint64

	db.Table("memberships m").
		Select("m.groupid").
		Joins("JOIN `groups` g ON g.id = m.groupid").
		Where("m.userid = ? AND m.rippled = 1 "+
			"AND g.polyindex IS NOT NULL AND ST_GeometryType(g.polyindex) <> 'POINT' "+
			"AND ST_Contains(g.polyindex, ST_SRID(POINT(?, ?), ?))",
			userid, lng, lat, utils.SRID).
		Scan(&groupids)

	for _, groupid := range groupids {
		ClearRippledMembership(db, userid, groupid, "moved into the area")
	}
}

// ClearRippledMembershipsAtLocationID is ClearRippledMembershipsAtLocation for a
// locations row - the shape a postcode change arrives in. A location with no coordinates
// tells us nothing about where the member now is, so nothing is cleared.
func ClearRippledMembershipsAtLocationID(db *gorm.DB, userid uint64, locationid uint64) {
	var loc struct {
		Lat *float64
		Lng *float64
	}

	db.Table("locations").Select("lat, lng").Where("id = ?", locationid).Scan(&loc)

	if loc.Lat == nil || loc.Lng == nil {
		return
	}

	ClearRippledMembershipsAtLocation(db, userid, *loc.Lat, *loc.Lng)
}

// IsRippleOnlyMembership reports whether the member's sole tie to the group is a post of
// theirs that rippled in. Rippling auto-joins the poster so their post can live there and
// be moderated; it is not a relationship with the community, so the group's moderators
// have nobody to start a conversation with (Discourse 10102).
func IsRippleOnlyMembership(db *gorm.DB, userid uint64, groupid uint64) bool {
	var count int64

	db.Table("memberships").
		Where("userid = ? AND groupid = ? AND rippled = 1", userid, groupid).
		Count(&count)

	return count > 0
}
