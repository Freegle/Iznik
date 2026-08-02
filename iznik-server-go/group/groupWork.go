package group

import (
	"encoding/json"
	"sort"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// GroupWork represents per-group work counts for a moderator.
// Active groups get primary fields (red badges), inactive/backup groups get
// "other" fields (blue badges).
type GroupWork struct {
	Groupid             uint64 `json:"groupid"`
	Pending             int64  `json:"pending"`
	Pendingother        int64  `json:"pendingother"`
	Spam                int64  `json:"spam"`
	Pendingmembers      int64  `json:"pendingmembers"`
	Pendingmembersother int64  `json:"pendingmembersother"`
	Spammembers         int64  `json:"spammembers"`
	Spammembersother    int64  `json:"spammembersother"`
	Pendingevents       int64  `json:"pendingevents"`
	Pendingvolunteering int64  `json:"pendingvolunteering"`
	Editreview          int64  `json:"editreview"`
	Pendingadmins       int64  `json:"pendingadmins"`
	Happiness           int64  `json:"happiness"`
	Relatedmembers      int64  `json:"relatedmembers"`
	Chatreview          int64  `json:"chatreview"`
	Chatreviewother     int64  `json:"chatreviewother"`
}

// isActiveModForGroup checks membership settings JSON for the active flag.
// Defaults to active=1 unless explicitly set otherwise.
func isActiveModForGroup(settingsJSON *string) bool {
	if settingsJSON == nil || *settingsJSON == "" {
		return true
	}
	var settings map[string]interface{}
	if err := json.Unmarshal([]byte(*settingsJSON), &settings); err != nil {
		return true
	}
	if active, ok := settings["active"]; ok {
		switch v := active.(type) {
		case float64:
			return v != 0
		case bool:
			return v
		}
	}
	if showmessages, ok := settings["showmessages"]; ok {
		switch v := showmessages.(type) {
		case float64:
			return v != 0
		case bool:
			return v
		}
	}
	return true
}

// GetGroupWork returns per-group work counts for the logged-in moderator.
//
// @Summary Get per-group work counts
// @Tags group
// @Produce json
// @Security BearerAuth
// @Success 200 {array} GroupWork
// @Router /api/group/work [get]
func GetGroupWork(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// Get all mod/owner memberships with settings to determine active/inactive.
	type membershipRow struct {
		Groupid  uint64  `json:"groupid"`
		Settings *string `json:"settings"`
	}
	var memberships []membershipRow
	// ORM migration site dfcf063d9320 (wave 1).
	db.Table("memberships").Select("groupid, settings").
		Where("userid = ? AND role IN (?, ?) AND collection = ?",
			myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).
		Scan(&memberships)

	if len(memberships) == 0 {
		return c.JSON([]GroupWork{})
	}

	// Split into active and inactive group IDs.
	var allGroupIDs, activeGroupIDs, inactiveGroupIDs []uint64
	activeMap := make(map[uint64]bool)
	for _, m := range memberships {
		allGroupIDs = append(allGroupIDs, m.Groupid)
		if isActiveModForGroup(m.Settings) {
			activeGroupIDs = append(activeGroupIDs, m.Groupid)
			activeMap[m.Groupid] = true
		} else {
			inactiveGroupIDs = append(inactiveGroupIDs, m.Groupid)
		}
	}

	// Build result map keyed by groupid.
	workMap := make(map[uint64]*GroupWork)
	var mapMutex sync.RWMutex
	for _, gid := range allGroupIDs {
		workMap[gid] = &GroupWork{Groupid: gid}
	}

	// countRow is used for GROUP BY groupid queries.
	type countRow struct {
		Groupid uint64
		Count   int64
	}

	// heldCountRow adds a held flag for pending/spam splitting.
	type heldCountRow struct {
		Groupid uint64
		Count   int64
		Held    int
	}

	var wg sync.WaitGroup

	// --- Pending messages: split by held status, active groups get primary/other, inactive all → pendingother ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		var rows []heldCountRow
		// Held is per-group: a message held on one group must not show as held on
		// another it is also pending on, so read mg.heldby. There is no message-wide
		// hold field - a hold belongs to a (message, group) pair.
		//
		// Only count a post once the content check has run on it: until then it may still
		// be auto-approved, and counting it raises a number the moderator cannot act on
		// (Discourse 9481/563). This mirrors the same filter in session.go, which feeds
		// the ModTools badge - without it the two disagreed about the same queue.
		// A HELD post is exempt: a moderator has claimed it, so it will never
		// auto-approve and is already in their list (Discourse 9481/635).
		// ORM migration site 31e336f98156 (wave 4).
		db.Table("messages_groups mg").
			Select("mg.groupid, COUNT(*) as count, (mg.heldby IS NOT NULL) as held").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND (mg.contentcheck_checked_at IS NOT NULL OR mg.heldby IS NOT NULL)",
				allGroupIDs, utils.COLLECTION_PENDING).
			Group("mg.groupid, held").
			Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			w := workMap[r.Groupid]
			if w == nil {
				continue
			}
			if activeMap[r.Groupid] {
				if r.Held == 0 {
					w.Pending = r.Count
				} else {
					w.Pendingother = r.Count
				}
			} else {
				w.Pendingother += r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Spam messages: only active groups ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		if len(activeGroupIDs) == 0 {
			return
		}
		var rows []countRow
		// ORM migration site f749b5bc26ed (wave 4).
		db.Table("messages_groups mg").
			Select("mg.groupid, COUNT(*) as count").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL",
				activeGroupIDs, utils.COLLECTION_SPAM).
			Group("mg.groupid").
			Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Spam = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Pending members: all groups, no active/inactive split ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		var rows []countRow
		// ORM migration site 1de7b48d433b (wave 1).
		db.Table("memberships").Select("groupid, COUNT(*) as count").
			Where("groupid IN ? AND collection = ?", allGroupIDs, utils.COLLECTION_PENDING).
			Group("groupid").Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Pendingmembers = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Spam members: split by held, active → primary/other, inactive → other ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		var rows []heldCountRow
		// ORM migration site 45bb8a4c8133 (wave 4).
		db.Table("memberships m").
			Select("m.groupid, COUNT(*) as count, (m.heldby IS NOT NULL) as held").
			Joins("INNER JOIN users u ON u.id = m.userid").
			Where("m.groupid IN ? AND m.reviewrequestedat IS NOT NULL AND (m.reviewedat IS NULL OR m.reviewrequestedat > m.reviewedat)", allGroupIDs).
			Group("m.groupid, held").
			Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			w := workMap[r.Groupid]
			if w == nil {
				continue
			}
			if activeMap[r.Groupid] {
				if r.Held == 0 {
					w.Spammembers = r.Count
				} else {
					w.Spammembersother = r.Count
				}
			} else {
				w.Spammembersother += r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Pending community events: only active groups ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		if len(activeGroupIDs) == 0 {
			return
		}
		var rows []countRow
		// ORM migration site 083af5c9e0a1 (wave 4).
		db.Table("communityevents ce").
			Select("ceg.groupid, COUNT(DISTINCT ce.id) as count").
			Joins("INNER JOIN communityevents_groups ceg ON ceg.eventid = ce.id").
			Joins("INNER JOIN communityevents_dates ced ON ced.eventid = ce.id").
			Where("ceg.groupid IN ? AND ce.pending = 1 AND ce.deleted = 0 AND ced.end >= NOW()", activeGroupIDs).
			Group("ceg.groupid").
			Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Pendingevents = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Pending volunteering: only active groups ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		if len(activeGroupIDs) == 0 {
			return
		}
		var rows []countRow
		// ORM migration site 1f888c4d9a0a (wave 4).
		db.Table("volunteering v").
			Select("vg.groupid, COUNT(DISTINCT v.id) as count").
			Joins("INNER JOIN volunteering_groups vg ON vg.volunteeringid = v.id").
			Joins("LEFT JOIN volunteering_dates vd ON vd.volunteeringid = v.id").
			Where("vg.groupid IN ? AND v.pending = 1 AND v.deleted = 0 AND v.expired = 0 AND (vd.end IS NULL OR vd.end >= NOW())", activeGroupIDs).
			Group("vg.groupid").
			Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Pendingvolunteering = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Edit reviews: only active groups, last 7 days ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		if len(activeGroupIDs) == 0 {
			return
		}
		var rows []countRow
		// rippled_in = 0: an edit belongs to the post's ORIGIN group only. A post
		// rippled INTO a group has an Approved messages_groups row (rippled_in=1)
		// there, so without this filter its edit is counted in every receiving
		// group's Edit badge while the Edit list (which filters rippled_in=0) shows
		// nothing — a "ghost" count (Discourse 9839). Matches the ListMessagesMT
		// Edit query and the session editreview count.
		// ORM migration site 7233641f67ad (wave 4).
		db.Table("messages_edits me").
			Select("mg.groupid, COUNT(DISTINCT me.msgid) as count").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = me.msgid").
			Where("mg.groupid IN ? AND me.reviewrequired = 1 AND me.approvedat IS NULL AND me.revertedat IS NULL AND me.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY) AND mg.deleted = 0 AND mg.rippled_in = 0",
				activeGroupIDs).
			Group("mg.groupid").
			Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Editreview = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Pending admins: only active groups ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		if len(activeGroupIDs) == 0 {
			return
		}
		var rows []countRow
		// ORM migration site de52b33ad2c2 (wave 1).
		db.Table("admins").Select("groupid, COUNT(DISTINCT id) as count").
			Where("groupid IN ? AND complete IS NULL AND pending = 1 AND heldby IS NULL", activeGroupIDs).
			Group("groupid").Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Pendingadmins = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Happiness: only active groups ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		if len(activeGroupIDs) == 0 {
			return
		}
		hapCutoff := time.Now().AddDate(0, 0, -utils.CHAT_ACTIVE_LIMIT).Format("2006-01-02")
		var rows []countRow
		// ORM migration site 1f1e8962edcb (wave 4).
		// rippled_in = 0: count Feedback only for posts that originated on the
		// group, not rippled-in copies, so the badge matches the Feedback list
		// (getHappinessMembers) and the Edit badge above. Discourse 9808/633.
		db.Table("messages_outcomes mo").
			Select("mg.groupid, COUNT(DISTINCT mo.id) as count").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = mo.msgid").
			Where("mo.timestamp >= ? AND mg.arrival >= ? AND mg.groupid IN ? AND mg.rippled_in = 0 "+
				"AND mo.comments IS NOT NULL AND mo.comments != '' "+
				"AND mo.comments != 'Sorry, this is no longer available.' "+
				"AND mo.comments != 'Thanks, this has now been taken.' "+
				"AND mo.comments != 'Thanks, I''m no longer looking for this.' "+
				"AND mo.comments != 'Sorry, this has now been taken.' "+
				"AND mo.comments != 'Thanks for the interest, but this has now been taken.' "+
				"AND mo.comments != 'Thanks, these have now been taken.' "+
				"AND mo.comments != 'Thanks, this has now been received.' "+
				"AND mo.comments != 'Withdrawn on user unsubscribe' "+
				"AND mo.comments != 'Auto-Expired' "+
				"AND (mo.happiness = 'Happy' OR mo.happiness IS NULL) "+
				"AND mo.reviewed = 0",
				hapCutoff, hapCutoff, activeGroupIDs).
			Group("mg.groupid").
			Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Happiness = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Related members: only active groups ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		if len(activeGroupIDs) == 0 {
			return
		}
		var rows []countRow
		db.Raw("SELECT groupid, COUNT(*) as count FROM ("+
			"SELECT ur.user1, m.groupid FROM users_related ur "+
			"INNER JOIN memberships m ON m.userid = ur.user1 "+
			"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User' "+
			"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User' "+
			"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0 "+
			"UNION "+
			"SELECT ur.user1, m.groupid FROM users_related ur "+
			"INNER JOIN memberships m ON m.userid = ur.user2 "+
			"INNER JOIN users u1 ON ur.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User' "+
			"INNER JOIN users u2 ON ur.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User' "+
			"WHERE ur.user1 < ur.user2 AND ur.notified = 0 AND m.groupid IN ? "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user1) > 0 "+
			"AND (SELECT COUNT(*) FROM users_logins WHERE userid = ur.user2) > 0 "+
			") t GROUP BY groupid", activeGroupIDs, activeGroupIDs).Scan(&rows)
		mapMutex.Lock()
		for _, r := range rows {
			if w := workMap[r.Groupid]; w != nil {
				w.Relatedmembers = r.Count
			}
		}
		mapMutex.Unlock()
	}()

	// --- Chat review: per-group, split by active/inactive and held status ---
	wg.Add(1)
	go func() {
		defer wg.Done()
		chatCutoff := time.Now().AddDate(0, 0, -utils.CHAT_ACTIVE_LIMIT).Format("2006-01-02")

		// Get per-group chat review counts. The recipient determines which group the review belongs to.
		// Primary: recipient is a member of the mod's group. Secondary: recipient not a member, use sender's group.
		type chatCountRow struct {
			Groupid uint64
			Count   int64
			Held    int
		}

		chatReviewByGroup := func(groupIDs []uint64) []chatCountRow {
			if len(groupIDs) == 0 {
				return nil
			}
			var rows []chatCountRow
			db.Raw("SELECT groupid, COUNT(*) as count, held FROM ("+
				"SELECT DISTINCT cm.id, "+
				"COALESCE("+
				"  (SELECT m1.groupid FROM memberships m1 INNER JOIN `groups` g ON m1.groupid = g.id AND g.type = 'Freegle' "+
				"   WHERE m1.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) "+
				"   AND m1.groupid IN ? LIMIT 1), "+
				"  (SELECT m2.groupid FROM memberships m2 INNER JOIN `groups` g2 ON m2.groupid = g2.id AND g2.type = 'Freegle' "+
				"   WHERE m2.userid = cm.userid AND m2.groupid IN ? LIMIT 1)"+
				") as groupid, "+
				"(cmh.userid IS NOT NULL) as held "+
				"FROM chat_messages cm "+
				"INNER JOIN chat_rooms cr ON cr.id = cm.chatid "+
				"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id "+
				"WHERE cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? "+
				"AND ("+
				"  EXISTS (SELECT 1 FROM memberships m3 INNER JOIN `groups` g3 ON m3.groupid = g3.id AND g3.type = 'Freegle' "+
				"   WHERE m3.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND m3.groupid IN ?) "+
				"  OR (NOT EXISTS (SELECT 1 FROM memberships m4 INNER JOIN `groups` g4 ON m4.groupid = g4.id AND g4.type = 'Freegle' "+
				"   WHERE m4.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)) "+
				"   AND EXISTS (SELECT 1 FROM memberships m5 INNER JOIN `groups` g5 ON m5.groupid = g5.id AND g5.type = 'Freegle' "+
				"   WHERE m5.userid = cm.userid AND m5.groupid IN ?))"+
				")"+
				") sub WHERE groupid IS NOT NULL GROUP BY groupid, held",
				groupIDs, groupIDs, chatCutoff, groupIDs, groupIDs).Scan(&rows)
			return rows
		}

		// Active groups: not-held → chatreview, held → chatreviewother.
		activeRows := chatReviewByGroup(activeGroupIDs)
		mapMutex.Lock()
		for _, r := range activeRows {
			if w := workMap[r.Groupid]; w != nil {
				if r.Held == 0 {
					w.Chatreview = r.Count
				} else {
					w.Chatreviewother = r.Count
				}
			}
		}
		mapMutex.Unlock()
		// Inactive groups: all → chatreviewother.
		inactiveRows := chatReviewByGroup(inactiveGroupIDs)
		mapMutex.Lock()
		for _, r := range inactiveRows {
			if w := workMap[r.Groupid]; w != nil {
				w.Chatreviewother += r.Count
			}
		}
		mapMutex.Unlock()

		// Wider chat review: if user is eligible, count unheld messages from groups with
		// widerchatreview=1. These appear as new group entries with chatreviewother counts.
		if user.HasWiderReview(myid) {
			type widerCountRow struct {
				Groupid uint64
				Count   int64
			}
			var widerRows []widerCountRow
			// ORM migration site a9a8c24df5e0 (wave 4).
			db.Table("chat_messages cm").
				Select("m1.groupid, COUNT(DISTINCT cm.id) as count").
				Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
				Joins("LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id").
				Joins("INNER JOIN memberships m1 ON m1.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)").
				Joins("INNER JOIN `groups` g ON m1.groupid = g.id AND g.type = 'Freegle'").
				Where("cm.reviewrequired = 1 AND cm.reviewrejected = 0 AND cm.date >= ? AND JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 AND cmh.id IS NULL AND (cm.reportreason IS NULL OR cm.reportreason != 'User')",
					chatCutoff).
				Group("m1.groupid").
				Scan(&widerRows)

			mapMutex.Lock()
			for _, r := range widerRows {
				if w, exists := workMap[r.Groupid]; exists {
					// Group already in workMap — add to chatreviewother.
					w.Chatreviewother += r.Count
				} else {
					// New group from wider review — add as new entry with chatreviewother only.
					workMap[r.Groupid] = &GroupWork{
						Groupid:         r.Groupid,
						Chatreviewother: r.Count,
					}
				}
			}
			mapMutex.Unlock()
		}
	}()

	wg.Wait()

	// Convert to flat array sorted by groupid for deterministic output.
	result := make([]GroupWork, 0, len(workMap))
	for _, w := range workMap {
		result = append(result, *w)
	}
	sort.Slice(result, func(i, j int) bool {
		return result[i].Groupid < result[j].Groupid
	})

	return c.JSON(result)
}
