package message

import (
	"log"

	"github.com/freegle/iznik-server-go/database"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// MarkCheckedRequest is the request body for marking auto-published posts as
// checked by a moderator (clearing them from the Check oversight queue).
type MarkCheckedRequest struct {
	Groupid uint64   `json:"groupid"`          // 0 = all of the mod's groups
	Filter  string   `json:"filter"`           // "checked" (used when no ids given)
	IDs     []uint64 `json:"ids,omitempty"`    // specific messages, else mark the whole bucket
	Reject  bool     `json:"reject,omitempty"` // true = pull the specified posts back to Pending, held, for a decision
}

// checkRejectReason is the spamreason on the copy a Check reject pulls back on the community
// the moderator acted on, where "Check" is a word moderators know. Every other copy pulled
// back (on a community that has no Check queue) is told backToPendingReason instead.
const checkRejectReason = "A moderator pulled this post back from Check for review."

// MarkChecked records that a moderator has reviewed auto-published posts. These
// are posts that went live without a manual approval from members with no
// posting status, so there is no approvedby/heldby to key off - this sets a
// dedicated checkedat/checkedby marker that drives the Check count.
//
// Which posts those are is utils.CheckQueueConditions, the same definition the Check list and
// the menu badge use: marking a bucket, marking particular ids and rejecting all read it, so
// none of them can touch a post the moderator was not shown (a deleted post, a deleted
// member's, a copy a moderator approved, a trusted member's, a post older than the window).
//
// @Summary Mark auto-published posts as checked by a moderator
// @Tags message
// @Accept json
// @Produce json
// @Param request body MarkCheckedRequest true "groupid (0 = all of the moderator's communities), then either ids (particular posts) or filter=checked (the whole queue); reject=true pulls the ids back to Pending"
// @Success 200 {object} map[string]interface{} "success, and checked (posts marked) or rejected (posts pulled back)"
// @Failure 400 {object} map[string]interface{} "reject without ids, or no ids and a filter other than checked"
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /modtools/messages/markchecked [post]
func MarkChecked(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req MarkCheckedRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	// Resolve the groups the mod may act on.
	var groupIDs []uint64
	if req.Groupid == 0 {
		groupIDs = user.GetActiveModGroupIDs(myid)
		if len(groupIDs) == 0 {
			// Not a moderator of any group - cannot mark anything checked (D11).
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
		}
	} else {
		if !user.IsModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
		}
		groupIDs = []uint64{req.Groupid}
	}

	// The Check queue exists only on communities in the post-moderation trial, and so do
	// its actions: outside the trial the same predicate (approvedby NULL, no posting status)
	// matches the 48-hour fallback's approvals on every community, and a stamp there would
	// count as a review in the moderation stats and lift the earned-reach hold on a post
	// nobody looked at. Mirrors the list filter in ListMessagesMT.
	groupIDs = utils.AutoapproveTrialGroups(groupIDs)
	if len(groupIDs) == 0 {
		return c.JSON(fiber.Map{"success": true, "checked": 0, "rejected": 0})
	}

	// Reject: pull the specified auto-published posts back out of the live feed for a
	// proper moderation decision. ModTools then shows each one as an ordinary Pending card,
	// held by this moderator, who approves, edits or rejects it as normal; that later action
	// writes its own log. This is Back to pending on the post's own community
	// (pullBackToPending, the same path handleBackToPending uses) but NOT the home community's
	// decision (homeDecision false): a Check reject is a second look at a post that published
	// itself, so it withdraws nothing, deletes nothing, bars nothing from rippling and writes
	// nothing against the poster. A copy a neighbouring community's moderator reviewed by hand
	// stays live, every unreviewed copy elsewhere goes back to its own community's Pending
	// queue (in neutral words: those communities have no Check queue), needs_moderator stops
	// the auto-approve cron re-publishing the post if the hold is released, and the reach is
	// frozen while the post is under review. A moderator's later Approve of the home copy
	// resumes it (ResumeReachIfHeld), because nothing here barred the post from rippling.
	// Targeted only - there is no bulk "reject the whole bucket" (that would be far too
	// blunt). rippled_in = 0: oversight actions apply to a post's ORIGIN row only -
	// rippled-in copies are the origin community's responsibility, never yanked directly
	// from another group's queue.
	if req.Reject {
		if len(req.IDs) == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "reject requires specific ids")
		}

		// Snapshot the rows the reject will hit, so the pull-back and the log rows apply
		// to exactly the posts actually rejected (not e.g. ids the mod lacks permission
		// for, which the WHERE filters out). Only a copy in the Check queue can be pulled
		// back through it, which is why a copy a moderator approved by hand is not: undoing a
		// moderator's approval is Back to pending, on purpose. A copy already stamped
		// checked (two microvolunteers) is still in reach of a moderator's reject.
		var hit []struct {
			Msgid    uint64
			Groupid  uint64
			Fromuser uint64
		}
		db.Table("messages_groups mg").
			Select("mg.msgid, mg.groupid, m.fromuser").
			Joins(utils.CheckQueueJoins).
			Where("mg.msgid IN ? AND mg.groupid IN ? AND "+utils.CheckQueueConditions(false), req.IDs, groupIDs).
			Scan(&hit)

		acting := map[uint64][]uint64{}
		fromuser := map[uint64]uint64{}
		var order []uint64
		for _, h := range hit {
			if _, seen := acting[h.Msgid]; !seen {
				order = append(order, h.Msgid)
			}
			acting[h.Msgid] = append(acting[h.Msgid], h.Groupid)
			fromuser[h.Msgid] = h.Fromuser
		}

		for _, msgid := range order {
			groups := acting[msgid]
			pullBackToPending(db, myid, msgid, groups, backToPendingReason, false)

			// Check-specific: the post is no longer "checked" on the communities acted on, and
			// their copy says why it is back in Pending in the words of the queue it came from.
			// (Clearing a stamp that was already clear changes no row, so no count is read off
			// this write.)
			db.Table("messages_groups").
				Where("msgid = ? AND groupid IN ? AND collection = ?", msgid, groups, utils.COLLECTION_PENDING).
				Updates(map[string]interface{}{
					"checkedat":  gorm.Expr("NULL"),
					"checkedby":  gorm.Expr("NULL"),
					"spamreason": checkRejectReason,
				})

			// The earned-reach await clock is settled. A post the gate had paused stops
			// waiting for review the moment a moderator pulls it back, so the time waited
			// is banked and the stamp cleared; leaving the stamp set would keep this frozen
			// row in the SysAdmin "awaiting review now" count for ever.
			db.Table("rippling_reach").Where("msgid = ? AND awaiting_review_since IS NOT NULL", msgid).
				Updates(map[string]interface{}{
					"awaiting_review_seconds": gorm.Expr(
						"awaiting_review_seconds + IFNULL(TIMESTAMPDIFF(SECOND, awaiting_review_since, NOW()), 0)"),
					"awaiting_review_since": gorm.Expr("NULL"),
				})

			for _, gid := range groups {
				// Logged as a Hold, not a Rejected: nothing is decided yet. The moderation-stats
				// "later actioned" analytic counts it as an auto-published post a moderator had
				// to act on. If the moderator then rejects it, that Reject writes the Rejected
				// log, which is what vetoes this member's next post from auto-publishing; if they
				// approve it, the member is not penalised.
				logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_HOLD, gid, fromuser[msgid], myid, msgid, 0, utils.CheckRejectLogText)
				log.Printf("automod: check reject msgid=%d groupid=%d byuser=%d", msgid, gid, myid)
			}
		}

		// Copies pulled back (the snapshot's length), not rows changed: clearing a stamp that was
		// never set changes no row, so a count of rows would say 0 for a real Check-queue post,
		// and ModTools reads 0 as "nothing happened, the post had changed since the page loaded".
		return c.JSON(fiber.Map{"success": true, "rejected": len(hit)})
	}

	var rowsAffected int64
	if len(req.IDs) > 0 {
		// Mark the specified posts checked - only ones in the Check queue and still
		// unchecked, and only on groups the mod moderates. A checkedat stamped on anything
		// else would corrupt the AutoModChecked analytic (it measures review of posts that
		// auto-published HERE).
		// keep-raw: multi-table UPDATE ... JOIN ... SET mg.col; GORM's Update cannot render a JOIN.
		r := db.Exec("UPDATE messages_groups mg "+utils.CheckQueueJoins+
			"SET mg.checkedat = NOW(), mg.checkedby = ? "+
			"WHERE mg.msgid IN ? AND mg.groupid IN ? AND "+utils.CheckQueueConditions(true),
			myid, req.IDs, groupIDs)
		rowsAffected = r.RowsAffected
	} else {
		// Mark the whole bucket checked (the "mark all as checked" action): every post in
		// the Check queue of the moderator's communities, by the same definition the list
		// and the badge use.
		if req.Filter != "checked" {
			return fiber.NewError(fiber.StatusBadRequest, "filter must be 'checked' (D10)")
		}
		// keep-raw: multi-table UPDATE ... JOIN ... SET mg.col; GORM's Update cannot render a JOIN.
		r := db.Exec("UPDATE messages_groups mg "+utils.CheckQueueJoins+
			"SET mg.checkedat = NOW(), mg.checkedby = ? "+
			"WHERE mg.groupid IN ? AND "+utils.CheckQueueConditions(true),
			myid, groupIDs)
		rowsAffected = r.RowsAffected
	}

	return c.JSON(fiber.Map{"success": true, "checked": rowsAffected})
}
