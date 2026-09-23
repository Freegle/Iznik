package message

import (
	"strconv"
	"strings"

	"gorm.io/gorm"
)

// maxGroupsForConstantList caps how many of the viewer's group ids we are willing to inline
// into the membership predicate below.
//
// The constant list pays because each id becomes a keyed probe of the (msgid, groupid) index;
// past some width that stops being a bargain, because a post only ever has as many
// messages_groups rows as it has been posted to or rippled into, and the join form walks just
// those. A moderator can be in hundreds of communities, which is the case this cap exists for.
//
// UNMEASURED. Every other number behind this rewrite came off production; this one did not.
// 50 is chosen to sit comfortably above ordinary membership (the measured members were in 1 to
// 12 groups) and below the moderator tail. Profile a wide-membership account against both forms
// before treating it as tuned - the method is in analysis/2026-09-17-db-cpu/README.md.
const maxGroupsForConstantList = 50

// myGroupIDs reads the viewer's group ids. Deliberately unfiltered beyond userid: the predicate
// it feeds replaces a join to `memberships` that had no other condition on that table either, so
// narrowing here would silently change which posts the browse feed shows.
func myGroupIDs(db *gorm.DB, myid uint64) []uint64 {
	var ids []uint64
	db.Raw("SELECT groupid FROM memberships WHERE userid = ?", myid).Scan(&ids)
	return ids
}

// approvedInMyGroupsPredicate renders "this post is approved in one of the viewer's groups" as a
// SQL fragment plus its bindings, correlated on msgidCol.
//
// This shape is four of db3's top spatial queries - the mygroups browse feed, its unseen COUNT,
// a feed variant and a derived-table variant - 0.41 cores between them. Measured clause by
// clause for a member in 12 groups, it was 70% of the query:
//
//	spatial scan only              13-50ms
//	+ not-viewed (messages_likes)  145-252ms
//	+ in my groups (EXISTS)        901-1080ms
//	+ pending guard                1120-1161ms
//
// The cost was never the EXISTS itself but how the viewer's groups reached it. Arriving through
// a join to `memberships`, they are not constants, so MySQL cannot push a groupid into the
// (msgid, groupid) index: for each of the ~27,000 candidate posts it walked every
// messages_groups row that post had - and a rippled post has many - before FirstMatch could
// answer. Reading the group ids first (a handful of rows from `memberships`) and passing them as
// constants lets the index do the work: 2.2x to 2.4x for members in 5 and 12 groups, a wash for
// members in one. Same lesson as the microvolunteering antijoin fix: candidates first, constant
// IN after.
//
// Membership is still tested through messages_groups, never messages_spatial.groupid - see the
// comment above myGroupsMsgIDs for why that distinction matters. This only changes how the
// viewer's groups reach the predicate, not which table answers it.
func approvedInMyGroupsPredicate(msgidCol string, groupIDs []uint64, myid uint64) (string, []interface{}) {
	if len(groupIDs) == 0 {
		// A member of no communities matches no post. Rendering IN () here would be a syntax
		// error that took out the whole browse feed, and a new account sits in exactly this
		// state between signing up and joining its first community.
		return "FALSE", nil
	}

	if len(groupIDs) > maxGroupsForConstantList {
		// Too many to inline - keep the original join form. See maxGroupsForConstantList.
		return "EXISTS (SELECT 1 FROM messages_groups mg " +
			"INNER JOIN memberships mem ON mem.groupid = mg.groupid " +
			"WHERE mg.msgid = " + msgidCol + " AND mem.userid = ? " +
			"AND mg.collection = 'Approved' AND mg.deleted = 0)", []interface{}{myid}
	}

	ids := make([]string, len(groupIDs))
	for i, g := range groupIDs {
		// uint64 rendered as decimal - no injection surface, and no binding needed.
		ids[i] = strconv.FormatUint(g, 10)
	}

	return "EXISTS (SELECT 1 FROM messages_groups mg " +
		"WHERE mg.msgid = " + msgidCol + " AND mg.groupid IN (" + strings.Join(ids, ",") + ") " +
		"AND mg.collection = 'Approved' AND mg.deleted = 0)", nil
}

// ApprovedInMyGroups is approvedInMyGroupsPredicate with the viewer's groups read for you. It
// costs one extra round trip, which is the trade the measurements above are net of.
func ApprovedInMyGroups(db *gorm.DB, msgidCol string, myid uint64) (string, []interface{}) {
	return approvedInMyGroupsPredicate(msgidCol, myGroupIDs(db, myid), myid)
}
