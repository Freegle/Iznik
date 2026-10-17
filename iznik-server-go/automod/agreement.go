package automod

import (
	"sort"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// EndCount is how often the chart ended at one node, and how often a moderator then did the
// opposite: approved a post the chart held, or rejected, deleted or held one it approved -
// on the community the chart decided for (ModDisagreed), or, for an approve, on another
// community the post reached (NeighbourDisagreed: the post got past the trial community and a
// moderator of a neighbouring community pulled it up there). A moderator means a moderator:
// the poster withdrawing their own post is neither, and neither is the trial community's own
// moderator pulling a post back, whose withdraw and hold logs land on the neighbours.
type EndCount struct {
	End                string `json:"end"`
	Verdict            string `json:"verdict"`
	Count              int64  `json:"count"`
	ModDisagreed       int64  `json:"modDisagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed"`
}

// FeedbackCount is how often moderators marked one node's answer as wrong: Count is the marks,
// and Posts the decisions they were on, so two moderators marking one post is two marks on one
// post and a step that is wrong on many posts is told from one that one post set off.
type FeedbackCount struct {
	Node  string `json:"node" gorm:"column:node"`
	Count int64  `json:"count" gorm:"column:count"`
	Posts int64  `json:"posts" gorm:"column:posts"`
}

// ModeCount is how many decisions were recorded in one mode.
type ModeCount struct {
	Count int64 `json:"count"`
}

// GroupCount is one community's decisions over the window in one mode, so a community can be
// moved from shadow to approve-only on its own numbers. The mode is part of the row: in
// shadow a human decides every post, so disagreement there is an unbiased measure; in approve
// mode only a post somebody opens in Check meets a human, so the same community's approve-mode
// figures are a lower bound and must not be averaged into its shadow ones. Decided excludes the
// rows that are not a decision about the post (UNAVAILABLE, GROUP_MODERATES_ALL) and is the
// denominator for the disagreement counts; Late is how many decisions landed after the post's
// auto-approve wait (from the post's arrival) had already run out, so the countdown shown ran
// from the decision, not from arrival.
type GroupCount struct {
	Groupid            uint64 `json:"groupid"`
	Mode               string `json:"mode"`
	Nameshort          string `json:"nameshort"`
	Decisions          int64  `json:"decisions"`
	Decided            int64  `json:"decided"`
	Approves           int64  `json:"approves"`
	Holds              int64  `json:"holds"`
	Unavailable        int64  `json:"unavailable"`
	ModDisagreed       int64  `json:"modDisagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed"`
	Late               int64  `json:"late"`
}

// Latency is how long the chart took to decide after the content check had run: the time
// a post sat in Pending with no decision and so no countdown. Seconds.
type Latency struct {
	AvgSeconds float64 `json:"avgSeconds" gorm:"column:avg_seconds"`
	MaxSeconds float64 `json:"maxSeconds" gorm:"column:max_seconds"`
}

// VersionCount is the same breakdown per chart version, so a chart or model change shows as
// a step in the disagreement rate rather than being averaged into the window.
type VersionCount struct {
	Version            string `json:"version"`
	Decisions          int64  `json:"decisions"`
	Decided            int64  `json:"decided"`
	Approves           int64  `json:"approves"`
	Holds              int64  `json:"holds"`
	Unavailable        int64  `json:"unavailable"`
	ModDisagreed       int64  `json:"modDisagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed"`
	Late               int64  `json:"late"`
}

// DayCount is the same breakdown per day of decision, oldest first: the trend line, where a
// backlog, an outage or a regression shows as a day that looks unlike its neighbours.
type DayCount struct {
	Day                string `json:"day"`
	Decisions          int64  `json:"decisions"`
	Decided            int64  `json:"decided"`
	Approves           int64  `json:"approves"`
	Holds              int64  `json:"holds"`
	Unavailable        int64  `json:"unavailable"`
	ModDisagreed       int64  `json:"modDisagreed"`
	NeighbourDisagreed int64  `json:"neighbourDisagreed"`
	Late               int64  `json:"late"`
}

// AgreementResponse is the SysAdmin automated review report.
//
// PulledBack is the safety measure for the trial: of the posts that published themselves and a
// moderator then pulled back from Check, how many that moderator (or a colleague) went on to
// approve (PulledBackApproved: a false alarm) and how many to reject or delete
// (PulledBackRejected: a real catch). The rest are still waiting for a decision.
type AgreementResponse struct {
	Days               int             `json:"days"`
	Groupid            uint64          `json:"groupid,omitempty"`
	Total              int64           `json:"total"`
	Unavailable        int64           `json:"unavailable"`
	Late               int64           `json:"late"`
	Latency            Latency         `json:"latency"`
	ByEnd              []EndCount      `json:"byEnd"`
	ByGroup            []GroupCount    `json:"byGroup"`
	ByVersion          []VersionCount  `json:"byVersion"`
	ByDay              []DayCount      `json:"byDay"`
	Feedback           []FeedbackCount `json:"feedback"`
	Shadow             ModeCount       `json:"shadow"`
	Approve            ModeCount       `json:"approve"`
	PulledBack         int64           `json:"pulledBack"`
	PulledBackApproved int64           `json:"pulledBackApproved"`
	PulledBackRejected int64           `json:"pulledBackRejected"`
}

// undecidedEndNodes are the end nodes that are not a verdict on the post itself: the service
// could not answer, or the community has moderators check everything. They stay in the
// per-end rows but are left out of every disagreement count and denominator.
var undecidedEndNodes = []string{"UNAVAILABLE", "GROUP_MODERATES_ALL"}

func isUndecidedEnd(end string) bool {
	for _, e := range undecidedEndNodes {
		if end == e {
			return true
		}
	}

	return false
}

// decidedSQL is true for a row that is a real decision.
func decidedSQL() string {
	quoted := make([]string, len(undecidedEndNodes))
	for i, e := range undecidedEndNodes {
		quoted[i] = "'" + e + "'"
	}

	return "ma.end_node NOT IN (" + strings.Join(quoted, ", ") + ")"
}

// moderatorLogSQL is true for a log (alias l) a moderator wrote, as against the poster's own
// action on their post (a withdrawal logs Deleted with byuser = the poster). byuser is set for
// anything a person did; user is who the log is about.
const moderatorLogSQL = "l.byuser IS NOT NULL AND l.byuser <> COALESCE(l.user, 0)"

// modDisagreedRowSQL is 1 for a decision a moderator of the community then contradicted: a
// hold by a human approval of that copy, an approve by a moderator's Rejected, Deleted or Hold
// log on that copy after the decision.
func modDisagreedRowSQL() string {
	return `CASE
	WHEN ` + decidedSQL() + ` AND ma.verdict = 'hold' THEN mg.approvedby IS NOT NULL
	WHEN ` + decidedSQL() + ` THEN EXISTS (SELECT 1 FROM logs l
		WHERE l.msgid = ma.msgid AND l.groupid = ma.groupid AND l.type = 'Message'
		AND l.subtype IN ('Rejected', 'Deleted', 'Hold')
		AND ` + moderatorLogSQL + ` AND l.timestamp >= ma.created)
	ELSE 0
	END`
}

// neighbourDisagreedRowSQL is 1 for an approve contradicted on ANOTHER community the post is on:
// a moderator of that community rejecting, deleting or holding the rippled copy. The signal that
// a bad post got past the trial community. A log by a moderator of the trial community itself
// does not count: a home Check reject or Back to pending writes a withdraw log on each rippled
// copy and a hold log on every other copy it pulls back, and that is the trial community
// disagreeing with itself (already ModDisagreed), not a neighbour disagreeing with it. (A
// members' report quorum writes its logs with no byuser, so it is not counted either.)
func neighbourDisagreedRowSQL() string {
	return `CASE
	WHEN ` + decidedSQL() + ` AND ma.verdict = 'approve' THEN EXISTS (SELECT 1 FROM logs l
		WHERE l.msgid = ma.msgid AND l.groupid IS NOT NULL AND l.groupid <> ma.groupid
		AND l.type = 'Message' AND l.subtype IN ('Rejected', 'Deleted', 'Hold')
		AND ` + moderatorLogSQL + ` AND l.timestamp >= ma.created
		AND NOT EXISTS (SELECT 1 FROM memberships mm
			WHERE mm.userid = l.byuser AND mm.groupid = ma.groupid AND mm.role IN ('Moderator', 'Owner')))
	ELSE 0
	END`
}

// lateRowSQL is 1 when the decision landed after the auto-approve wait from the post's arrival
// had run out, so the post had no countdown for the whole of its wait. The wait is measured from
// messages.arrival, the moment the post came in: the copy's own arrival is re-stamped when it
// publishes (the digest keys off it), so on any post that has published it is after the decision
// and would make every decision look prompt.
func lateRowSQL() string {
	return "(m.arrival IS NOT NULL AND ma.created > m.arrival + INTERVAL " + strconv.Itoa(utils.AutoapproveDelayMinutes()) + " MINUTE)"
}

// cell is one cell of the report: the decisions sharing a community, mode, chart version, end
// node, verdict and day, with the per-decision flags summed. Every breakdown the report gives
// is a rollup of these cells, so the flags (each a correlated lookup into the huge logs table)
// are computed once per decision however many ways the report slices them.
type cell struct {
	Groupid            uint64 `gorm:"column:groupid"`
	Mode               string `gorm:"column:mode"`
	ChartVersion       string `gorm:"column:chart_version"`
	End                string `gorm:"column:end_node"`
	Verdict            string `gorm:"column:verdict"`
	Day                string `gorm:"column:day"`
	Decisions          int64  `gorm:"column:decisions"`
	ModDisagreed       int64  `gorm:"column:mod_disagreed"`
	NeighbourDisagreed int64  `gorm:"column:neighbour_disagreed"`
	Late               int64  `gorm:"column:late"`
}

// The keys the cells are rolled up by.
type groupKey struct {
	groupid uint64
	mode    string
}

type endKey struct{ end, verdict string }

// tally adds up cells.
type tally struct {
	decisions, decided, approves, holds, unavailable int64
	modDisagreed, neighbourDisagreed, late           int64
}

func (t *tally) add(c cell) {
	t.decisions += c.Decisions
	t.modDisagreed += c.ModDisagreed
	t.neighbourDisagreed += c.NeighbourDisagreed
	t.late += c.Late
	if c.End == "UNAVAILABLE" {
		t.unavailable += c.Decisions
	}
	if !isUndecidedEnd(c.End) {
		t.decided += c.Decisions
		switch c.Verdict {
		case "approve":
			t.approves += c.Decisions
		case "hold":
			t.holds += c.Decisions
		}
	}
}

// Agreement handles GET /modtools/automod/agreement: how the chart's decisions over the last
// N days compare with what moderators then did, by end node, by community and mode, by chart
// version and by day, plus "this step is wrong" counts by node, how long decisions took, how
// many came too late for a countdown, and what became of the posts pulled back from Check.
// Support or Admin only, like the moderation stats it sits beside.
//
// @Summary Automated review agreement with moderators
// @Tags automod
// @Produce json
// @Param days query int false "Window in days (1-365, default 30)"
// @Param groupid query int false "Only this community's decisions (default all)"
// @Success 200 {object} AgreementResponse
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /modtools/automod/agreement [get]
func Agreement(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	days, err := strconv.Atoi(c.Query("days", "30"))
	if err != nil || days < 1 || days > 365 {
		days = 30
	}
	groupid, _ := strconv.ParseUint(c.Query("groupid", "0"), 10, 64)

	db := database.DBConn
	since := gorm.Expr("NOW() - INTERVAL ? DAY", days)
	resp := AgreementResponse{Days: days, Groupid: groupid, ByEnd: []EndCount{}, ByGroup: []GroupCount{}, ByVersion: []VersionCount{}, ByDay: []DayCount{}, Feedback: []FeedbackCount{}}

	// Every query below starts from the decisions in the window, optionally one community's.
	decisions := func() *gorm.DB {
		q := db.Table("messages_automod ma").Where("ma.created >= ?", since)
		if groupid > 0 {
			q = q.Where("ma.groupid = ?", groupid)
		}
		return q
	}

	// One pass over the decisions in the window. Each decision's flags (did a moderator
	// disagree, did a neighbour, was it late) are worked out once, in this query, and summed into
	// cells; the four breakdowns below are rolled up from the cells in memory.
	var cells []cell
	decisions().
		Joins("LEFT JOIN messages_groups mg ON mg.msgid = ma.msgid AND mg.groupid = ma.groupid").
		Joins("LEFT JOIN messages m ON m.id = ma.msgid").
		Select("ma.groupid, ma.mode, ma.chart_version, ma.end_node, ma.verdict, " +
			"DATE_FORMAT(ma.created, '%Y-%m-%d') AS day, COUNT(*) AS decisions, " +
			"SUM(" + modDisagreedRowSQL() + ") AS mod_disagreed, " +
			"SUM(" + neighbourDisagreedRowSQL() + ") AS neighbour_disagreed, " +
			"SUM(" + lateRowSQL() + ") AS late").
		Group("ma.groupid, ma.mode, ma.chart_version, ma.end_node, ma.verdict, DATE_FORMAT(ma.created, '%Y-%m-%d')").
		Scan(&cells)

	var all tally
	byEnd := map[endKey]*tally{}
	byGroup := map[groupKey]*tally{}
	byVersion := map[string]*tally{}
	byDay := map[string]*tally{}
	for _, cl := range cells {
		all.add(cl)
		for _, t := range []*tally{
			tallyFor(byEnd, endKey{cl.End, cl.Verdict}),
			tallyFor(byGroup, groupKey{cl.Groupid, cl.Mode}),
			tallyFor(byVersion, cl.ChartVersion),
			tallyFor(byDay, cl.Day),
		} {
			t.add(cl)
		}
		switch cl.Mode {
		case "shadow":
			resp.Shadow.Count += cl.Decisions
		case "approve":
			resp.Approve.Count += cl.Decisions
		}
	}
	resp.Total = all.decisions
	resp.Unavailable = all.unavailable
	resp.Late = all.late

	for k, t := range byEnd {
		resp.ByEnd = append(resp.ByEnd, EndCount{End: k.end, Verdict: k.verdict, Count: t.decisions,
			ModDisagreed: t.modDisagreed, NeighbourDisagreed: t.neighbourDisagreed})
	}
	sort.Slice(resp.ByEnd, func(i, j int) bool {
		a, b := resp.ByEnd[i], resp.ByEnd[j]
		if a.Count != b.Count {
			return a.Count > b.Count
		}
		if a.End != b.End {
			return a.End < b.End
		}
		return a.Verdict < b.Verdict
	})

	groupids := make([]uint64, 0, len(byGroup))
	for k := range byGroup {
		groupids = append(groupids, k.groupid)
	}
	names := communityNames(db, groupids)
	for k, t := range byGroup {
		resp.ByGroup = append(resp.ByGroup, GroupCount{Groupid: k.groupid, Mode: k.mode, Nameshort: names[k.groupid],
			Decisions: t.decisions, Decided: t.decided, Approves: t.approves, Holds: t.holds, Unavailable: t.unavailable,
			ModDisagreed: t.modDisagreed, NeighbourDisagreed: t.neighbourDisagreed, Late: t.late})
	}
	sort.Slice(resp.ByGroup, func(i, j int) bool {
		a, b := resp.ByGroup[i], resp.ByGroup[j]
		if a.Decisions != b.Decisions {
			return a.Decisions > b.Decisions
		}
		if a.Groupid != b.Groupid {
			return a.Groupid < b.Groupid
		}
		return a.Mode < b.Mode
	})

	for k, t := range byVersion {
		resp.ByVersion = append(resp.ByVersion, VersionCount{Version: k,
			Decisions: t.decisions, Decided: t.decided, Approves: t.approves, Holds: t.holds, Unavailable: t.unavailable,
			ModDisagreed: t.modDisagreed, NeighbourDisagreed: t.neighbourDisagreed, Late: t.late})
	}
	sort.Slice(resp.ByVersion, func(i, j int) bool {
		a, b := resp.ByVersion[i], resp.ByVersion[j]
		if a.Decisions != b.Decisions {
			return a.Decisions > b.Decisions
		}
		return a.Version < b.Version
	})

	for k, t := range byDay {
		resp.ByDay = append(resp.ByDay, DayCount{Day: k,
			Decisions: t.decisions, Decided: t.decided, Approves: t.approves, Holds: t.holds, Unavailable: t.unavailable,
			ModDisagreed: t.modDisagreed, NeighbourDisagreed: t.neighbourDisagreed, Late: t.late})
	}
	sort.Slice(resp.ByDay, func(i, j int) bool { return resp.ByDay[i].Day < resp.ByDay[j].Day })

	feedback := db.Table("messages_automod_feedback f").
		Joins("JOIN messages_automod ma ON ma.id = f.automodid").
		Where("f.created >= ?", since)
	if groupid > 0 {
		feedback = feedback.Where("ma.groupid = ?", groupid)
	}
	feedback.Select("f.node, COUNT(*) AS count, COUNT(DISTINCT f.automodid) AS posts").
		Group("f.node").
		Order("count DESC").
		Scan(&resp.Feedback)

	// How long the post waited between its content check and the chart's decision. Only
	// real decisions: an UNAVAILABLE row is written the moment the call fails and would
	// make the service look quicker than it was.
	decisions().
		Joins("LEFT JOIN messages_groups mg ON mg.msgid = ma.msgid AND mg.groupid = ma.groupid").
		Where(decidedSQL() + " AND mg.contentcheck_checked_at IS NOT NULL").
		Select("COALESCE(AVG(TIMESTAMPDIFF(SECOND, mg.contentcheck_checked_at, ma.created)), 0) AS avg_seconds, " +
			"COALESCE(MAX(TIMESTAMPDIFF(SECOND, mg.contentcheck_checked_at, ma.created)), 0) AS max_seconds").
		Scan(&resp.Latency)

	resp.PulledBack, resp.PulledBackApproved, resp.PulledBackRejected = pulledBackFromCheck(db, days, groupid)

	return c.JSON(resp)
}

func tallyFor[K comparable](m map[K]*tally, k K) *tally {
	t, ok := m[k]
	if !ok {
		t = &tally{}
		m[k] = t
	}

	return t
}

// communityNames returns the short name of each community, by id.
func communityNames(db *gorm.DB, ids []uint64) map[uint64]string {
	names := map[uint64]string{}
	if len(ids) == 0 {
		return names
	}

	var rows []struct {
		ID        uint64
		Nameshort *string
	}
	db.Table("`groups`").Select("id, nameshort").Where("id IN ?", ids).Scan(&rows)
	for _, r := range rows {
		if r.Nameshort != nil {
			names[r.ID] = *r.Nameshort
		}
	}

	return names
}

// pulledBackFromCheck counts, over the window and optionally one community, the posts a moderator
// pulled back from Check (the Hold log MarkChecked writes), and how each ended: the next
// Approved log on that copy by a moderator other than the poster (a false alarm), or the next
// Rejected or Deleted one (a catch). "Next" is by log id, so a Reject followed by a later
// approval of a reposted copy counts as the Reject it first was. Read positionally: a struct
// scan of several aggregates can come back zero (see go-api-traps).
func pulledBackFromCheck(db *gorm.DB, days int, groupid uint64) (pulledBack, approved, rejected int64) {
	sql := "SELECT COUNT(*), " +
		"COALESCE(SUM(nx.subtype = 'Approved'), 0), " +
		"COALESCE(SUM(nx.subtype IN ('Rejected', 'Deleted')), 0) " +
		"FROM logs h " +
		"LEFT JOIN logs nx ON nx.id = (SELECT MIN(n.id) FROM logs n " +
		"WHERE n.msgid = h.msgid AND n.groupid = h.groupid AND n.type = 'Message' " +
		"AND n.subtype IN ('Approved', 'Rejected', 'Deleted') " +
		"AND n.byuser IS NOT NULL AND n.byuser <> COALESCE(n.user, 0) AND n.id > h.id) " +
		"WHERE h.type = 'Message' AND h.subtype = 'Hold' AND h.text = ? " +
		"AND h.timestamp >= NOW() - INTERVAL ? DAY"
	args := []interface{}{utils.CheckRejectLogText, days}
	if groupid > 0 {
		sql += " AND h.groupid = ?"
		args = append(args, groupid)
	}

	// keep-raw: a self-join with a correlated MIN() subquery in the join condition, which the chain builder cannot render.
	if err := db.Raw(sql, args...).Row().Scan(&pulledBack, &approved, &rejected); err != nil {
		return 0, 0, 0
	}

	return
}
