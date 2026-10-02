package message

import (
	"encoding/json"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// autoapproveDelayMinutes is how long a clean post waits before it publishes itself. It is
// the same for every community and mirrors the batch's freegle.autoapprove.delay_minutes
// (FREEGLE_AUTOAPPROVE_DELAY_MINUTES, default 20).
func autoapproveDelayMinutes() int {
	if n, err := strconv.Atoi(strings.TrimSpace(os.Getenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES"))); err == nil && n > 0 {
		return n
	}
	return 20
}

// groupOwnRuleChecks mirrors ContentCheckService::reasonsHoldByGroupOwnRules: the checks
// ExpandService writes when a rippled-in copy breaks the RECEIVING group's own keywords or
// worry words. Such a copy waits for one of that group's moderators, never for the clock
// (AutoApproveService::shouldApproveOnGroup), so no countdown belongs on it.
var groupOwnRuleChecks = map[string]bool{"ConcernKeyword": true, "PerGroupWorryWord": true}

func reasonsHoldByGroupOwnRules(raw *json.RawMessage) bool {
	if raw == nil {
		return false
	}
	var reasons []map[string]interface{}
	if err := json.Unmarshal(*raw, &reasons); err != nil {
		return false
	}
	for _, r := range reasons {
		if name, ok := r["check"].(string); ok && groupOwnRuleChecks[name] {
			return true
		}
	}

	return false
}

// rippledInPendingHours mirrors config('freegle.ripple.rippled_in_pending_hours'): how long
// AutoApproveService leaves a rippled-in copy Pending for the receiving group's veto before
// approving it. Read from the same variable the batch container reads.
func rippledInPendingHours() int {
	if v, err := strconv.Atoi(strings.TrimSpace(os.Getenv("RIPPLE_RIPPLED_IN_PENDING_HOURS"))); err == nil && v > 0 {
		return v
	}

	return 0
}

// automodApproved reports whether the latest messages_automod row for (msgid, groupid) says
// verdict='approve' and is not stale (its created is on or after messages.editedat, or
// editedat is null). messages_automod has a UNIQUE (msgid, groupid): a rerun of the chart
// REPLACES the row, so there is always at most one candidate and no ORDER BY / LIMIT is
// needed to find "the latest" one.
func automodApproved(db *gorm.DB, msgid uint64, groupid uint64) bool {
	var approved bool
	db.Table("messages_automod AS ma").
		Joins("JOIN messages m ON m.id = ma.msgid").
		Where("ma.msgid = ? AND ma.groupid = ? AND ma.verdict = ?", msgid, groupid, "approve").
		Where("m.editedat IS NULL OR ma.created >= m.editedat").
		Select("COUNT(*) > 0").
		Scan(&approved)

	return approved
}

// computeAutoapproveat fills MessageGroup.Autoapproveat for the Pending group entries of
// a message a moderator is viewing. The automod flowchart (run by the batch container) is
// now the single place the approve/hold decision is made; this function only READS that
// decision and turns it into a countdown, rather than recomputing it:
//
//   - Spam on ANY group, or held -> nil (no auto-approval expected).
//   - a rippled-in copy -> AutoApproveService's own path (arrival + rippled_in_pending_hours),
//     unless the RECEIVING group's own rules are holding it, in which case nil - unchanged by
//     the flowchart, since a rippled-in copy is never a flowchart candidate.
//   - otherwise: nil unless the copy is not quality-sampled and not pulled back to
//     needs_moderator, AND automodApproved says the chart's decision for this copy is a
//     current 'approve' - then arrival + the site-wide delay.
//
// then capped below by autoapprove_hold_until (the extend-only hold set on Pending load).
func computeAutoapproveat(db *gorm.DB, message *Message, groups []MessageGroup, idStr string) {
	// Spam on ANY group blocks auto-approval everywhere (Discourse #9654 parity).
	for _, mg := range groups {
		if mg.Collection == utils.COLLECTION_SPAM {
			return
		}
	}

	var pendingIdx []int
	for i := range groups {
		// Outside the trial there is no countdown at all, so a community not taking part sees
		// Pending as it did before post-moderation.
		if groups[i].Collection == utils.COLLECTION_PENDING && groups[i].Heldby == nil && utils.AutoapproveTrialGroup(groups[i].Groupid) {
			pendingIdx = append(pendingIdx, i)
		}
	}
	if len(pendingIdx) == 0 {
		return
	}

	idNum, _ := strconv.ParseUint(idStr, 10, 64)

	// The cron also excludes a post whose MESSAGE-level spam reason is set (not just the
	// per-group one). The Message payload does not carry that column, so read it once.
	var msgSpamreason bool
	db.Table("messages").Select("spamreason IS NOT NULL").Where("id = ?", idNum).Scan(&msgSpamreason)

	for _, i := range pendingIdx {
		mg := &groups[i]

		// A rippled-in copy is AutoApproveService's, not the flowchart's, and its rules are
		// the receiving group's: a copy the group's own keywords or worry words held waits for
		// a moderator of that group and never auto-approves, so it gets no countdown. Any
		// other Pending copy is released once rippled_in_pending_hours have passed (the
		// hourly sweep adds up to an hour on top, which this estimate ignores).
		if mg.RippledIn == 1 {
			if reasonsHoldByGroupOwnRules(mg.ContentcheckReasons) || mg.Spamreason != nil || msgSpamreason {
				continue
			}
			t := mg.Arrival.Add(time.Duration(rippledInPendingHours()) * time.Hour)
			if mg.AutoapproveHoldUntil != nil && mg.AutoapproveHoldUntil.After(t) {
				t = *mg.AutoapproveHoldUntil
			}
			groups[i].Autoapproveat = &t
			continue
		}

		// A manual quality-check sample, or a copy pulled back to needs_moderator, is never
		// on the auto-approve path regardless of what the flowchart decided.
		if mg.QualitySample != 0 || mg.NeedsModerator {
			continue
		}

		if !automodApproved(db, idNum, mg.Groupid) {
			continue
		}

		t := mg.Arrival.Add(time.Duration(autoapproveDelayMinutes()) * time.Minute)
		if mg.AutoapproveHoldUntil != nil && mg.AutoapproveHoldUntil.After(t) {
			t = *mg.AutoapproveHoldUntil
		}
		groups[i].Autoapproveat = &t
	}
}

// automodRow is one messages_automod row, scanned with explicit column names since Path
// and the *_node/chart_version columns don't match Go field names.
type automodRow struct {
	Groupid      uint64
	Verdict      string
	Reason       *string
	EndNode      string `gorm:"column:end_node"`
	Mode         string
	ChartVersion string `gorm:"column:chart_version"`
	Path         json.RawMessage
	Created      time.Time
}

// populateAutomodDecisions fills groups[].Automod for the groups on this message that are
// both automod groups (utils.AutomodGroup) and moderated by myid, from messages_automod.
// One membership query and one messages_automod query cover every group on the message,
// regardless of how many groups it's on. A group with no row yet (not run, or not on an
// automod path) gets no Automod field, and neither does a group myid doesn't moderate.
func populateAutomodDecisions(db *gorm.DB, myid uint64, msgid uint64, groups []MessageGroup) {
	var automodGroupids []uint64
	for _, mg := range groups {
		if utils.AutomodGroup(mg.Groupid) {
			automodGroupids = append(automodGroupids, mg.Groupid)
		}
	}

	if len(automodGroupids) == 0 {
		return
	}

	// Same moderator check as Heldby above, scoped to the groups this message is on that
	// are actually automod groups.
	var myModGroups []uint64
	db.Table("memberships").Select("groupid").
		Where("userid = ? AND groupid IN ? AND role IN (?, ?) AND collection = ?",
			myid, automodGroupids, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).
		Scan(&myModGroups)

	if len(myModGroups) == 0 {
		return
	}

	var rows []automodRow
	db.Table("messages_automod").
		Select("groupid, verdict, reason, end_node, mode, chart_version, path, created").
		Where("msgid = ? AND groupid IN ?", msgid, myModGroups).
		Scan(&rows)

	if len(rows) == 0 {
		return
	}

	byGroup := make(map[uint64]automodRow, len(rows))
	for _, r := range rows {
		byGroup[r.Groupid] = r
	}

	for i := range groups {
		if r, ok := byGroup[groups[i].Groupid]; ok {
			groups[i].Automod = &AutomodDecision{
				Verdict: r.Verdict,
				Reason:  r.Reason,
				End:     r.EndNode,
				Mode:    r.Mode,
				Version: r.ChartVersion,
				Path:    r.Path,
				Created: r.Created,
			}
		}
	}
}
