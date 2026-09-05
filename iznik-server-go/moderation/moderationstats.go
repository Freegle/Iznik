package moderation

import (
	"strings"
	"sync"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
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
	AutoApproved   int64 `json:"autoApproved"`   // auto-approved (Checked) — Autoapproved log
	Trusted        int64 `json:"trusted"`        // went live immediately (trusted members)

	// Quality of the auto-published population (posts auto-approved in the period).
	// NB: 'auto-approved' (Autoapproved log) covers both the Checked delay path and
	// the 48h fallback — they aren't distinguished retrospectively. Trusted posts
	// (no log) are excluded from this error-rate denominator.
	AutoModChecked    int64 `json:"autoModChecked"`    // a moderator later marked it checked
	AutoLaterActioned int64 `json:"autoLaterActioned"` // later rejected/deleted/edited/held after going live

	// Quality-check sample held back for manual review.
	QualitySampled   int64 `json:"qualitySampled"`   // posts held back for a manual quality check
	QualitySampleBad int64 `json:"qualitySampleBad"` // of those, ones a moderator then rejected/deleted
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
		db.Raw("SELECT COUNT(*) FROM logs l WHERE l.type='Message' AND l.subtype='Received' "+
			"AND l.timestamp BETWEEN ? AND ?", start, end).Scan(&stats.Arrived)
	})
	run(func() {
		db.Raw("SELECT COUNT(*) FROM logs l WHERE l.type='Message' AND l.subtype='Approved' "+
			"AND l.byuser IS NOT NULL AND l.timestamp BETWEEN ? AND ?", start, end).Scan(&stats.ManualApproved)
	})
	run(func() {
		db.Raw("SELECT COUNT(*) FROM logs l WHERE l.type='Message' AND l.subtype IN ('Rejected','Deleted') "+
			"AND l.byuser IS NOT NULL AND l.byuser <> l.user AND l.timestamp BETWEEN ? AND ?", start, end).Scan(&stats.ManualRejected)
	})
	run(func() {
		db.Raw("SELECT COUNT(*) FROM logs l WHERE l.type='Message' AND l.subtype='Autoapproved' "+
			"AND l.timestamp BETWEEN ? AND ?", start, end).Scan(&stats.AutoApproved)
	})
	run(func() {
		// Trusted: went live without moderation (approvedby NULL, content-checked,
		// trusted member) and with no Autoapproved log.
		db.Raw("SELECT COUNT(*) FROM messages_groups mg "+
			"JOIN messages m ON m.id=mg.msgid "+
			"JOIN memberships mem ON mem.userid=m.fromuser AND mem.groupid=mg.groupid "+
			"WHERE mg.approvedat BETWEEN ? AND ? AND mg.approvedby IS NULL AND mg.rippled_in = 0 "+
			"AND mg.contentcheck_checked_at IS NOT NULL AND mg.deleted=0 "+
			"AND (mem.ourPostingStatus='DEFAULT' OR mem.ourPostingStatus='UNMODERATED') "+
			"AND NOT EXISTS (SELECT 1 FROM logs l WHERE l.msgid=mg.msgid AND l.groupid=mg.groupid AND l.type='Message' AND l.subtype='Autoapproved')",
			start, end).Scan(&stats.Trusted)
	})

	// --- Quality of the auto-published population (AutoApproved counted above) ---
	run(func() {
		db.Raw("SELECT COUNT(DISTINCT l.msgid) FROM logs l "+
			"JOIN messages_groups mg ON mg.msgid=l.msgid AND mg.groupid=l.groupid "+
			"WHERE l.type='Message' AND l.subtype='Autoapproved' AND l.timestamp BETWEEN ? AND ? "+
			"AND mg.checkedat IS NOT NULL", start, end).Scan(&stats.AutoModChecked)
	})
	run(func() {
		// Auto-approved posts that later needed intervention: a rejection, deletion,
		// edit or hold by someone other than the author, after the auto-approval.
		db.Raw("SELECT COUNT(DISTINCT a.msgid) FROM logs a "+
			"JOIN logs x ON x.msgid=a.msgid AND x.groupid=a.groupid "+
			"WHERE a.type='Message' AND a.subtype='Autoapproved' AND a.timestamp BETWEEN ? AND ? "+
			"AND x.type='Message' AND x.subtype IN ('Rejected','Deleted','Edit','Hold') "+
			"AND x.byuser IS NOT NULL AND x.byuser <> a.user AND x.timestamp > a.timestamp",
			start, end).Scan(&stats.AutoLaterActioned)
	})

	// --- Quality-check sample ---
	run(func() {
		db.Raw("SELECT COUNT(*) FROM messages_groups mg WHERE mg.quality_sample=1 "+
			"AND mg.arrival BETWEEN ? AND ?", start, end).Scan(&stats.QualitySampled)
	})
	run(func() {
		db.Raw("SELECT COUNT(DISTINCT mg.msgid) FROM messages_groups mg "+
			"JOIN logs l ON l.msgid=mg.msgid AND l.groupid=mg.groupid "+
			"WHERE mg.quality_sample=1 AND mg.arrival BETWEEN ? AND ? "+
			"AND l.type='Message' AND l.subtype IN ('Rejected','Deleted') "+
			"AND l.byuser IS NOT NULL AND l.byuser <> l.user", start, end).Scan(&stats.QualitySampleBad)
	})

	wg.Wait()

	return c.JSON(stats)
}
