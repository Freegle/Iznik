package moderation

import (
	"strings"
	"sync"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// ModerationStats summarises how posts were moderated over a period, so admins
// can judge whether auto-approving is working: the volume down each path, the
// rate at which auto-published posts later needed intervention, and the
// moderator's verdict on the quality-check sample for comparison.
type ModerationStats struct {
	Start string `json:"start"`
	End   string `json:"end"`

	// How posts were handled in the period.
	Arrived        int64 `json:"arrived"`        // posts that arrived (Received log)
	ManualApproved int64 `json:"manualApproved"` // approved by a moderator
	ManualRejected int64 `json:"manualRejected"` // rejected/deleted by a moderator
	AutoApproved   int64 `json:"autoApproved"`   // auto-approved (Checked) — Autoapproved log, clean path and 48h fallback together
	// Of the auto-approved, the ones the post-moderation clean path published after the
	// automated review's approve: the Autoapproved log's text is 'automod' on that path
	// (AutoApproveCleanService) and empty on the 48h fallback (AutoApproveService), so the
	// trial's own error rate is not diluted by every community's fallback approvals.
	AutoApprovedClean int64 `json:"autoApprovedClean"`
	Trusted           int64 `json:"trusted"` // went live immediately (trusted members)

	// Quality of the auto-published population (posts auto-approved in the period).
	// NB: 'auto-approved' (Autoapproved log) covers both the Checked delay path and
	// the 48h fallback — they aren't distinguished retrospectively. Trusted posts
	// (no log) are excluded from this error-rate denominator.
	AutoModChecked             int64 `json:"autoModChecked"`             // later marked checked, by a moderator or by two microvolunteers
	AutoModCheckedByVolunteers int64 `json:"autoModCheckedByVolunteers"` // of those, cleared by two microvolunteer approvals (no checkedby)
	AutoLaterActioned          int64 `json:"autoLaterActioned"`          // later rejected/deleted/edited/held after going live
	// Of those, the ones the clean path published (the Autoapproved log's text is 'automod'): the
	// numerator for the trial's own error rate, over AutoApprovedClean, so the 48-hour fallback's
	// posts on every community do not dilute or inflate it.
	AutoLaterActionedClean int64 `json:"autoLaterActionedClean"`

	// Quality-check sample held back for manual review. Counted in the window the automated
	// review recorded its approve in (when the sample was drawn), and in posts: a copy's
	// arrival is not the time, because a moderator's approve re-stamps it.
	QualitySampled   int64 `json:"qualitySampled"`   // posts held back for a manual quality check
	QualitySampleBad int64 `json:"qualitySampleBad"` // of those, ones a moderator then rejected/deleted
	// Of those, the ones a moderator has decided (approved, rejected or deleted): the denominator
	// for the rate of bad ones. Most of a sample is still waiting for somebody to look, and a rate
	// over all of it reads low for as long as it does.
	QualitySampleDecided int64 `json:"qualitySampleDecided"`
}

// Stats handles GET /modtools/moderationstats?start=&end= (Admin/Support only).
//
// @Summary Moderation analytics for the auto-approve approach
// @Tags moderation
// @Produce json
// @Param start query string true "Start date (YYYY-MM-DD)"
// @Param end query string true "End date (YYYY-MM-DD)"
// @Success 200 {object} ModerationStats
// @Router /modtools/moderationstats [get]
func Stats(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	start := c.Query("start", "")
	end := c.Query("end", "")
	if start == "" || end == "" {
		return fiber.NewError(fiber.StatusBadRequest, "start and end dates are required")
	}
	// Make a bare end date inclusive of the whole day.
	if !strings.Contains(end, " ") && !strings.Contains(end, "T") {
		end += " 23:59:59"
	}

	db := database.DBConn
	stats := ModerationStats{Start: c.Query("start", ""), End: c.Query("end", "")}

	var wg sync.WaitGroup
	run := func(fn func()) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			fn()
		}()
	}

	// --- Flow in the period ---
	run(func() {
		db.Table("logs l").Select("COUNT(*)").
			Where("l.type='Message' AND l.subtype='Received' AND l.timestamp BETWEEN ? AND ?", start, end).
			Scan(&stats.Arrived)
	})
	run(func() {
		db.Table("logs l").Select("COUNT(*)").
			Where("l.type='Message' AND l.subtype='Approved' AND l.byuser IS NOT NULL AND l.timestamp BETWEEN ? AND ?", start, end).
			Scan(&stats.ManualApproved)
	})
	run(func() {
		db.Table("logs l").Select("COUNT(*)").
			Where("l.type='Message' AND l.subtype IN ('Rejected','Deleted') AND l.byuser IS NOT NULL AND l.byuser <> l.user AND l.timestamp BETWEEN ? AND ?", start, end).
			Scan(&stats.ManualRejected)
	})
	run(func() {
		db.Table("logs l").Select("COUNT(*)").
			Where("l.type='Message' AND l.subtype='Autoapproved' AND l.timestamp BETWEEN ? AND ?", start, end).
			Scan(&stats.AutoApproved)
	})
	run(func() {
		db.Table("logs l").Select("COUNT(*)").
			Where("l.type='Message' AND l.subtype='Autoapproved' AND l.text='automod' AND l.timestamp BETWEEN ? AND ?", start, end).
			Scan(&stats.AutoApprovedClean)
	})
	run(func() {
		// Trusted: went live without moderation (approvedby NULL, content-checked,
		// trusted member) and with no Autoapproved log.
		//
		// Bounded by arrival as well as by approval time. messages_groups has no index on
		// approvedat, so approvedat alone is a scan of the whole table, for a panel that loads
		// a month by default; arrival is indexed, and for a copy that publishes by itself it is
		// the moment of approval or a minute or so before it. So the count reads the window's
		// rows only. (A copy that arrived just before the window and was approved just inside
		// it is in neither this window nor the one before: a minute's worth at each edge.)
		db.Table("messages_groups mg").Select("COUNT(*)").
			Joins("JOIN messages m ON m.id=mg.msgid").
			Joins("JOIN memberships mem ON mem.userid=m.fromuser AND mem.groupid=mg.groupid").
			Where("mg.arrival BETWEEN ? AND ? AND mg.approvedat BETWEEN ? AND ? AND mg.approvedby IS NULL AND mg.rippled_in = 0 "+
				"AND mg.contentcheck_checked_at IS NOT NULL AND mg.deleted=0 "+
				"AND (mem.ourPostingStatus='DEFAULT' OR mem.ourPostingStatus='UNMODERATED') "+
				"AND NOT EXISTS (SELECT 1 FROM logs l WHERE l.msgid=mg.msgid AND l.groupid=mg.groupid AND l.type='Message' AND l.subtype='Autoapproved')",
				start, end, start, end).
			Scan(&stats.Trusted)
	})

	// --- Quality of the auto-published population (AutoApproved counted above) ---
	run(func() {
		db.Table("logs l").
			Select("COUNT(DISTINCT l.msgid)").
			Joins("JOIN messages_groups mg ON mg.msgid=l.msgid AND mg.groupid=l.groupid").
			Where("l.type='Message' AND l.subtype='Autoapproved' AND l.timestamp BETWEEN ? AND ? AND mg.checkedat IS NOT NULL", start, end).
			Scan(&stats.AutoModChecked)
	})
	run(func() {
		db.Table("logs l").
			Select("COUNT(DISTINCT l.msgid)").
			Joins("JOIN messages_groups mg ON mg.msgid=l.msgid AND mg.groupid=l.groupid").
			Where("l.type='Message' AND l.subtype='Autoapproved' AND l.timestamp BETWEEN ? AND ? AND mg.checkedat IS NOT NULL AND mg.checkedby IS NULL", start, end).
			Scan(&stats.AutoModCheckedByVolunteers)
	})
	// Auto-approved posts that later needed intervention: a rejection, deletion, edit or hold by
	// someone other than the author, after the auto-approval. The same shape twice: over every
	// auto-approved post, and over the clean path's alone (see AutoLaterActionedClean).
	laterActioned := func(clean bool) *gorm.DB {
		autoapproved := "a.type='Message' AND a.subtype='Autoapproved' AND a.timestamp BETWEEN ? AND ? "
		if clean {
			autoapproved += "AND a.text='automod' "
		}

		return db.Table("logs a").
			Select("COUNT(DISTINCT a.msgid)").
			Joins("JOIN logs x ON x.msgid=a.msgid AND x.groupid=a.groupid").
			Where(autoapproved+
				"AND x.type='Message' AND x.subtype IN ('Rejected','Deleted','Edit','Hold') "+
				"AND x.byuser IS NOT NULL AND x.byuser <> a.user AND x.timestamp > a.timestamp",
				start, end)
	}
	run(func() { laterActioned(false).Scan(&stats.AutoLaterActioned) })
	run(func() { laterActioned(true).Scan(&stats.AutoLaterActionedClean) })

	// --- Quality-check sample ---
	//
	// The sample is the posts the automated review approved and the quality check then kept in
	// Pending for a moderator's verdict (messages_groups.quality_sample, stamped as the approve is
	// recorded). It is counted in the window of that approve, messages_automod.created, which is
	// when it was drawn, and not by messages_groups.arrival: a moderator's approve re-stamps
	// arrival to the time of the approval, so approved samples fell into a later window than the
	// one they were drawn in while rejected ones stayed. That also drives the query from the
	// decision's index on created, where arrival bounds a scan of the table.
	//
	// A verdict is a moderator's Approved, Rejected or Deleted log on the copy after it was drawn
	// (not the poster's own withdrawal, and not an approval from before). Bad is the rejected and
	// deleted among them. All three count posts, so a cross-post stamped on two communities is one
	// post throughout and the rate cannot pass 100%.
	qualitySample := func(verdicts string) *gorm.DB {
		q := db.Table("messages_automod ma").
			Select("COUNT(DISTINCT mg.msgid)").
			Joins("JOIN messages_groups mg ON mg.msgid=ma.msgid AND mg.groupid=ma.groupid").
			Where("mg.quality_sample=1 AND ma.created BETWEEN ? AND ?", start, end)
		if verdicts != "" {
			q = q.Joins("JOIN logs l ON l.msgid=mg.msgid AND l.groupid=mg.groupid").
				Where("l.type='Message' AND l.subtype IN (" + verdicts + ") " +
					"AND l.byuser IS NOT NULL AND l.byuser <> l.user AND l.timestamp >= ma.created")
		}

		return q
	}
	run(func() { qualitySample("").Scan(&stats.QualitySampled) })
	run(func() { qualitySample("'Approved','Rejected','Deleted'").Scan(&stats.QualitySampleDecided) })
	run(func() { qualitySample("'Rejected','Deleted'").Scan(&stats.QualitySampleBad) })

	wg.Wait()

	return c.JSON(stats)
}
