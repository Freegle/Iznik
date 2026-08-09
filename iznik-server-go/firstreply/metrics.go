package firstreply

import (
	"context"
	"os"
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
	//
	// Explicit column tag: GORM derives snake_case from the field name, so this
	// would look for median_hours and silently leave the field nil against the
	// medianhours alias - a dash in the table that reads as "no data" rather
	// than as a mapping mistake.
	MedianHours *float64 `json:"medianhours" gorm:"column:medianhours"`
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

// ArmOutcome is one side of the trial split: did the posts we did this to end up
// better off than the ones we did not?
//
// This is the KPI the whole feature answers to. Everything else on this dashboard
// is a lever's own counter - passthroughs fired, scouts mailed, prompts answered -
// and every one of them can go up while the thing that matters does not move. More
// mail sent is not more items rehomed.
//
// The population is RIPPLED posts, not all posts, because that is the population
// the levers act on and the one the 44%-no-reply figure comes from. Split by
// CRC32(msgid|firstreply) % 100 against the SAME percentage the levers bucket on
// (see RolloutBucket), so the arms are the posts that did and did not get the
// treatment, assigned identically on both doors.
//
// Two honest limits, stated because the numbers look authoritative either way:
// at 0% or 100% one arm is empty and the comparison means nothing; and Taken
// depends on the poster coming back to say so, which is itself a behaviour the
// trial may change.
type ArmOutcome struct {
	// Arm is "trial" or "holdout".
	Arm string `json:"arm"`
	// Posts is rippled posts in the window on this side of the split.
	Posts int64 `json:"posts"`
	// Replied is how many of those got at least one Interested reply from
	// somebody other than the poster.
	Replied int64 `json:"replied"`
	// Taken is how many reached an outcome of Taken or Received - the actual
	// point of the exercise, and the one that is hard to move.
	Taken int64 `json:"taken"`
}

// PassthroughSummary is the first-reply passthrough's effect.
type PassthroughSummary struct {
	// Web and Email are first replies delivered instead of held, by door.
	Web   int64 `json:"web"`
	Email int64 `json:"email"`
	// Sized is how many of those we could work out a saving for, and
	// AvgHoursEarlier / MaxHoursEarlier are how much earlier the poster heard
	// than they would have. This is per reply - "for THIS replier, when would
	// the reach have got to them?" - not the average hold duration across a
	// different population.
	Sized           int64    `json:"sized"`
	AvgHoursEarlier *float64 `json:"avghoursearlier"`
	MaxHoursEarlier *float64 `json:"maxhoursearlier"`
	// SameDay is replies that would have waited less than a day anyway. A
	// passthrough that saves twenty minutes is worth much less than one that
	// saves three days, and an average alone hides which kind these are.
	SameDay int64 `json:"sameday"`
	// Unsized is passthroughs the sweep looked at but could not answer: the
	// post's schedule could not say which tick would have covered the replier.
	// Counted from the passthrough rows themselves rather than by subtracting
	// Sized from the daily counters - those are a different table and can
	// legitimately diverge (retention, a counter written where the row insert
	// failed), which would put a wrong number in front of the reader.
	Unsized int64 `json:"unsized"`
	// HeldReleased is holds that still happened in the window, for context on
	// how much of the problem the passthrough is actually catching.
	HeldReleased int64 `json:"heldreleased"`
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

	// The dashboard's date filter sends bare dates. A bare end date means midnight,
	// so a plain BETWEEN against a datetime column silently drops everything that
	// happened TODAY - which is the most interesting part and the least likely to
	// be questioned, because the panel still looks perfectly plausible. Widen a
	// date-only bound to cover its whole day. The daily-counter query is unaffected
	// (it compares DATE() to DATE()), but these row-level ones are not.
	if len(start) == 10 {
		start += " 00:00:00"
	}
	if len(end) == 10 {
		end += " 23:59:59"
	}

	daily := []DayCount{}
	db.Table("firstreply_event_metrics").
		Select("DATE_FORMAT(day, '%Y-%m-%d') AS day, event, count").
		Where("day BETWEEN DATE(?) AND DATE(?)", start, end).
		Order("day, event").
		Scan(&daily)

	var passthrough PassthroughSummary
	db.Table("firstreply_event_metrics").
		Select("COALESCE(SUM(CASE WHEN event = 'passthrough_web' THEN count END), 0) AS web, "+
			"COALESCE(SUM(CASE WHEN event = 'passthrough_email' THEN count END), 0) AS email").
		Where("day BETWEEN DATE(?) AND DATE(?)", start, end).
		Scan(&passthrough)

	// How much earlier the poster actually heard, per reply, from the sweep that
	// asks which tick would have covered each replier.
	var sized struct {
		Sized   int64    `gorm:"column:sized"`
		AvgHrs  *float64 `gorm:"column:avghrs"`
		MaxHrs  *float64 `gorm:"column:maxhrs"`
		SameDay int64    `gorm:"column:sameday"`
		Unsized int64    `gorm:"column:unsized"`
	}
	db.Table("firstreply_passthroughs").
		Select("COUNT(waited_hours) AS sized, "+
			"AVG(waited_hours) AS avghrs, "+
			"MAX(waited_hours) AS maxhrs, "+
			"COALESCE(SUM(waited_hours < 24), 0) AS sameday, "+
			"COALESCE(SUM(waited_hours IS NULL AND computed_at IS NOT NULL), 0) AS unsized").
		Where("created_at BETWEEN ? AND ?", start, end).
		Scan(&sized)
	passthrough.Sized = sized.Sized
	passthrough.AvgHoursEarlier = sized.AvgHrs
	passthrough.MaxHoursEarlier = sized.MaxHrs
	passthrough.SameDay = sized.SameDay
	passthrough.Unsized = sized.Unsized

	// Holds that still happened, for context on how much of the problem the
	// passthrough is catching.
	var heldReleased int64
	db.Table("rippling_held_replies").
		Where("status = 'released' AND releasedat IS NOT NULL AND created_at BETWEEN ? AND ?",
			start, end).
		Count(&heldReleased)
	passthrough.HeldReleased = heldReleased

	signals := []ScoutSignal{}
	db.Table("firstreply_scouts fs").
		Select("fs.reason AS reason, "+
			"COUNT(*) AS mailed, "+
			"SUM(fs.replied_at IS NOT NULL) AS replied, "+
			"COUNT(DISTINCT fs.msgid) AS posts, "+
			"COUNT(DISTINCT CASE WHEN mo.msgid IS NOT NULL THEN fs.msgid END) AS taken, "+
			"AVG(CASE WHEN fs.replied_at IS NOT NULL "+
			"    THEN TIMESTAMPDIFF(MINUTE, fs.sent_at, fs.replied_at) / 60 END) AS medianhours").
		Joins("LEFT JOIN messages_outcomes mo ON mo.msgid = fs.msgid AND mo.outcome IN ('Taken', 'Received')").
		Where("fs.sent_at BETWEEN ? AND ?", start, end).
		Group("fs.reason").
		Order("mailed DESC").
		Scan(&signals)

	prompts := []PromptKind{}
	db.Table("chat_prompts cp").
		Select("cp.kind AS kind, "+
			"COUNT(*) AS sent, "+
			"SUM(cp.answered_at IS NOT NULL) AS answered, "+
			"SUM(cp.answer IN ('maybe', 'weekend', 'week', 'twoweeks', 'add')) AS acted").
		Where("cp.created_at BETWEEN ? AND ?", start, end).
		Group("cp.kind").
		Order("sent DESC").
		Scan(&prompts)

	// How many posts the chat spoke to at all, so prompt counts can be read as
	// "of the silent posts we engaged" rather than as a bare total.
	//
	// Counted from chat_prompts.msgids, not from firstreply_prompts_sent. Once
	// prompts were grouped, that table stopped being per-post: it is keyed on the
	// MEMBER and carries a postcount, so it can say how many post-slots were
	// covered but not which posts, and the same post covered by both a photo and
	// a delivery question would be counted twice. msgids is the actual set, so
	// DISTINCT over it is the honest answer.
	//
	// The previous version selected COUNT(DISTINCT msgid) from that table, which
	// stopped existing when it was re-keyed. The query errored, Scan left this at
	// zero, and the dashboard's v-if on the value then hid the sentence
	// altogether - so the number did not go wrong in front of anyone, it quietly
	// stopped being there at all.
	var postsEngaged int64
	db.Table("chat_prompts cp").
		Joins("JOIN JSON_TABLE(cp.msgids, '$[*]' COLUMNS (msgid BIGINT UNSIGNED PATH '$')) jt").
		Where("cp.created_at BETWEEN ? AND ?", start, end).
		Distinct("jt.msgid").
		Count(&postsEngaged)

	// The arm comparison only means anything over posts that LIVED under
	// treatment. Fetched over a wide window it fills BOTH arms with pre-trial
	// history - posts that got replies the ordinary way before the feature
	// existed - and the trial column reads as a claim the feature never made
	// (live case: "1,834 trial posts replied" hours after switch-on).
	// FIRSTREPLY_ENABLED_AT, stamped when the trial went live, floors the arm
	// population; the response carries the effective floor so the panel can
	// say what is being counted. Same layout as start/end, so a string
	// comparison orders correctly.
	armStart := start
	if enabledAt := os.Getenv("FIRSTREPLY_ENABLED_AT"); enabledAt != "" && enabledAt > armStart {
		armStart = enabledAt
	}

	// The overall KPI: replies and rehomes, trial against holdout.
	//
	// Bounded to rippled posts by rr.created_at, which is indexed
	// (rippling_reach_created_freeglers). The two EXISTS are per candidate post
	// rather than joins with COUNT(DISTINCT), so neither chat_messages nor
	// messages_outcomes is materialised - this dashboard has run into query
	// deadlines before, and a KPI that times out is worse than no KPI because the
	// panel just renders empty.
	arms := []ArmOutcome{}
	db.Table("rippling_reach rr").
		Select("CASE WHEN CRC32(CONCAT(rr.msgid, '|firstreply')) % 100 < ? THEN 'trial' ELSE 'holdout' END AS arm, "+
			"COUNT(*) AS posts, "+
			"COALESCE(SUM(EXISTS(SELECT 1 FROM chat_messages cm "+
			"    WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested' "+
			"      AND cm.userid <> m.fromuser)), 0) AS replied, "+
			"COALESCE(SUM(EXISTS(SELECT 1 FROM messages_outcomes mo "+
			"    WHERE mo.msgid = rr.msgid AND mo.outcome IN ('Taken', 'Received'))), 0) AS taken",
			rolloutPercent()).
		Joins("JOIN messages m ON m.id = rr.msgid").
		Where("rr.created_at BETWEEN ? AND ?", armStart, end).
		Where("m.deleted IS NULL").
		Group("arm").
		Scan(&arms)

	return c.JSON(fiber.Map{
		"start":          start,
		"end":            end,
		"armsfrom":       armStart,
		"daily":          daily,
		"passthrough":    passthrough,
		"scouts":         signals,
		"prompts":        prompts,
		"postsengaged":   postsEngaged,
		"arms":           arms,
		"rolloutpercent": rolloutPercent(),
	})
}
