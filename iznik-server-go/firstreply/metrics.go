package firstreply

import (
	"context"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// Sysadmin metrics for the first-reply work.
//
// The question this has to answer is not "is it running" but "is each of the
// three levers earning its keep", so every section is built to be read against
// something. Counters on their own would say a number went up without saying
// whether the number was worth having.
//
//	passthrough - how many first replies skipped the hold, and (from the holds
//	              that DID happen) how long a hold typically lasts. The second
//	              number is the delay the first one avoided.
//	scouts      - reply rate per signal, so wanted / search / frequent can be
//	              compared directly, plus how many of those posts ended up taken.
//	              If `frequent` never converts, it should be switched off.
//	prompts     - answer rate per kind, and how often the answer actually changed
//	              the post. A prompt nobody answers is a prompt to delete.
//
// Every query here is bounded to the firstreply_* tables plus small joins, which
// is what keeps this endpoint off the heavyweight-KPI path that previously timed
// the rippling dashboard out (see rippling/metrics.go).

// MetricsDeadline caps the DB work, comfortably inside the production gateway
// timeout, for the same reason rippling/metrics.go has one: fasthttp does not
// cancel anything when a client gives up, so an abandoned request would
// otherwise leave its queries grinding and invite a retry pile-up.
const MetricsDeadline = 20 * time.Second

// DayCount is one day's worth of one counter.
type DayCount struct {
	Day   string `json:"day"`
	Event string `json:"event"`
	Count int64  `json:"count"`
}

// ScoutSignal is how one picking signal performed.
type ScoutSignal struct {
	Reason string `json:"reason"`
	// Mailed is scouts sent; Replied is those who then replied to that post.
	Mailed  int64 `json:"mailed"`
	Replied int64 `json:"replied"`
	// Posts is distinct posts this signal was used on, and Taken is how many of
	// those ended up with an outcome of Taken or Received. A signal can have a
	// good reply rate and still not rehome anything.
	Posts int64 `json:"posts"`
	Taken int64 `json:"taken"`
	// MedianHours is the typical gap between the mail and the reply. Slow
	// replies still count, but a signal that only ever produces them is not
	// doing what this feature exists to do.
	MedianHours *float64 `json:"medianhours"`
}

// PromptKind is how one kind of Freegle prompt performed.
type PromptKind struct {
	Kind     string `json:"kind"`
	Sent     int64  `json:"sent"`
	Answered int64  `json:"answered"`
	// Acted is answers that actually changed the post - "maybe" to delivery, a
	// real date to deadline. Answering "collection only" or "no rush" is a
	// legitimate answer but leaves the post exactly as it was.
	Acted int64 `json:"acted"`
}

// PassthroughSummary is the first-reply passthrough's effect.
type PassthroughSummary struct {
	// Web and Email are first replies delivered instead of held, by door.
	Web   int64 `json:"web"`
	Email int64 `json:"email"`
	// HeldMedianHours is how long a held reply typically waits before release,
	// across the same window. This is the delay a passthrough avoids, and the
	// only honest way to size the benefit.
	HeldMedianHours *float64 `json:"heldmedianhours"`
	HeldReleased    int64    `json:"heldreleased"`
}

// @Router /firstreply/metrics [get]
// @Summary First-reply effectiveness, per lever (sysadmin)
// @Description Passthrough, scouting and Freegle-chat prompt effectiveness over a window.
// @Tags firstreply
// @Produce json
// @Param start query string false "Start datetime, default 30 days ago"
// @Param end query string false "End datetime, default now"
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 403 {object} fiber.Error "Support or Admin role required"
func Metrics(c *fiber.Ctx) error {
	ctx, cancel := context.WithTimeout(c.Context(), MetricsDeadline)
	defer cancel()
	db := database.DBConn.WithContext(ctx)

	start := c.Query("start")
	end := c.Query("end")
	if start == "" {
		start = time.Now().AddDate(0, 0, -30).Format("2006-01-02 15:04:05")
	}
	if end == "" {
		end = time.Now().Format("2006-01-02 15:04:05")
	}

	daily := []DayCount{}
	db.Raw("SELECT DATE_FORMAT(day, '%Y-%m-%d') AS day, event, count "+
		"FROM firstreply_event_metrics "+
		"WHERE day BETWEEN DATE(?) AND DATE(?) "+
		"ORDER BY day, event", start, end).Scan(&daily)

	var passthrough PassthroughSummary
	db.Raw("SELECT "+
		"COALESCE(SUM(CASE WHEN event = 'passthrough_web' THEN count END), 0) AS web, "+
		"COALESCE(SUM(CASE WHEN event = 'passthrough_email' THEN count END), 0) AS email "+
		"FROM firstreply_event_metrics "+
		"WHERE day BETWEEN DATE(?) AND DATE(?)", start, end).Scan(&passthrough)

	// The counterfactual: how long holds actually last when they happen.
	var held struct {
		Released int64    `gorm:"column:released"`
		Hours    *float64 `gorm:"column:hours"`
	}
	db.Raw("SELECT COUNT(*) AS released, "+
		"AVG(TIMESTAMPDIFF(MINUTE, created_at, releasedat)) / 60 AS hours "+
		"FROM rippling_held_replies "+
		"WHERE status = 'released' AND releasedat IS NOT NULL "+
		"AND created_at BETWEEN ? AND ?", start, end).Scan(&held)
	passthrough.HeldReleased = held.Released
	passthrough.HeldMedianHours = held.Hours

	signals := []ScoutSignal{}
	db.Raw("SELECT fs.reason AS reason, "+
		"COUNT(*) AS mailed, "+
		"SUM(fs.replied_at IS NOT NULL) AS replied, "+
		"COUNT(DISTINCT fs.msgid) AS posts, "+
		"COUNT(DISTINCT CASE WHEN mo.msgid IS NOT NULL THEN fs.msgid END) AS taken, "+
		"AVG(CASE WHEN fs.replied_at IS NOT NULL "+
		"    THEN TIMESTAMPDIFF(MINUTE, fs.sent_at, fs.replied_at) / 60 END) AS medianhours "+
		"FROM firstreply_scouts fs "+
		"LEFT JOIN messages_outcomes mo ON mo.msgid = fs.msgid AND mo.outcome IN ('Taken', 'Received') "+
		"WHERE fs.sent_at BETWEEN ? AND ? "+
		"GROUP BY fs.reason "+
		"ORDER BY mailed DESC", start, end).Scan(&signals)

	prompts := []PromptKind{}
	db.Raw("SELECT cp.kind AS kind, "+
		"COUNT(*) AS sent, "+
		"SUM(cp.answered_at IS NOT NULL) AS answered, "+
		"SUM(cp.answer IN ('maybe', 'weekend', 'week', 'twoweeks', 'add')) AS acted "+
		"FROM chat_prompts cp "+
		"WHERE cp.created_at BETWEEN ? AND ? "+
		"GROUP BY cp.kind "+
		"ORDER BY sent DESC", start, end).Scan(&prompts)

	// How many posts the chat spoke to at all, so prompt counts can be read as
	// "of the silent posts we engaged" rather than as a bare total.
	var postsEngaged int64
	db.Raw("SELECT COUNT(DISTINCT msgid) FROM firstreply_prompts_sent "+
		"WHERE sent_at BETWEEN ? AND ?", start, end).Scan(&postsEngaged)

	return c.JSON(fiber.Map{
		"start":        start,
		"end":          end,
		"daily":        daily,
		"passthrough":  passthrough,
		"scouts":       signals,
		"prompts":      prompts,
		"postsengaged": postsEngaged,
	})
}
