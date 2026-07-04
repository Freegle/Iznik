package message

import (
	"encoding/json"
	"hash/crc32"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// cleanPathEnabledFor mirrors the PHP rollout gate (AutoApproveCleanService::enabledGroupIds):
// FREEGLE_AUTOAPPROVE_ENABLED truthy enables the 20-minute clean path everywhere; otherwise
// only the groups listed in FREEGLE_AUTOAPPROVE_TRIAL_GROUPS (comma-separated ids) take part.
// When the clean path is off for a group, the countdown falls back to the 48h estimate — a
// countdown that will never fire must not be shown to moderators.
func cleanPathEnabledFor(gid uint64) bool {
	v := os.Getenv("FREEGLE_AUTOAPPROVE_ENABLED")
	if v == "true" || v == "1" {
		return true
	}

	for _, part := range strings.Split(os.Getenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS"), ",") {
		if id, err := strconv.ParseUint(strings.TrimSpace(part), 10, 64); err == nil && id == gid {
			return true
		}
	}

	return false
}

// phpTruthy mirrors PHP's !empty(): nil, false, 0, "" and "0" are falsy; everything
// else is truthy. Used so the Go group-allows check matches AutoApproveCleanService's
// PHP getSetting()/empty() semantics exactly.
func phpTruthy(v interface{}) bool {
	switch x := v.(type) {
	case nil:
		return false
	case bool:
		return x
	case float64:
		return x != 0
	case string:
		return x != "" && x != "0"
	default:
		return true
	}
}

// computeAutoapproveat fills MessageGroup.Autoapproveat for the Pending group entries of
// a message a moderator is viewing. It is an ACCURATE estimate of when the post will be
// auto-approved:
//
//   - danger-signalled / Spam-on-any-group / held  -> nil (no auto-approval expected)
//   - clean path (NULL posting status, content-check clean, group allows, not sampled)
//     -> arrival + group delay (site default 20m)
//   - otherwise (not clean, but not held/spam) -> arrival + 48h (the broad fallback)
//
// then capped below by autoapprove_hold_until (the extend-only hold set on Pending load).
//
// PARITY: the clean-path eligibility and danger signals mirror
// app/Services/AutoApproveCleanService.php (hasDangerSignals, groupAllowsAutoApprove,
// isQualitySampled). Keep the two in sync.
func computeAutoapproveat(db *gorm.DB, message *Message, groups []MessageGroup, idStr string) {
	// Spam on ANY group blocks auto-approval everywhere (Discourse #9654 parity).
	for _, mg := range groups {
		if mg.Collection == utils.COLLECTION_SPAM {
			return
		}
	}

	var pendingIdx []int
	var gids []uint64
	for i := range groups {
		if groups[i].Collection == utils.COLLECTION_PENDING && groups[i].Heldby == nil {
			pendingIdx = append(pendingIdx, i)
			gids = append(gids, groups[i].Groupid)
		}
	}
	if len(pendingIdx) == 0 {
		return
	}

	idNum, _ := strconv.ParseUint(idStr, 10, 64)

	// Danger signals — mirror AutoApproveCleanService::hasDangerSignals exactly. One
	// combined query: danger=1 if ANY signal fires for this poster/message/groups.
	var danger bool
	db.Raw(`SELECT (
		EXISTS (SELECT 1 FROM microactions WHERE msgid = ? AND actiontype = 'CheckMessage' AND result = 'Reject')
		OR EXISTS (SELECT 1 FROM users_comments WHERE userid = ?)
		OR EXISTS (SELECT 1 FROM logs WHERE user = ? AND timestamp >= NOW() - INTERVAL 90 DAY
			AND (byuser != user OR byuser IS NULL)
			AND ((type = 'Message' AND subtype IN ('Rejected','Deleted','Replied'))
			  OR (type = 'User' AND subtype IN ('Mailed','Rejected','Deleted','Suspect','ClassifiedSpam'))))
		OR EXISTS (SELECT 1 FROM spam_users WHERE userid = ? AND collection IN ('Spammer','PendingAdd'))
		OR EXISTS (SELECT 1 FROM memberships WHERE userid = ? AND groupid IN ?
			AND reviewrequestedat IS NOT NULL
			AND (reviewedat IS NULL OR reviewedat < reviewrequestedat))
	) AS danger`,
		idNum, message.Fromuser, message.Fromuser, message.Fromuser, message.Fromuser, gids,
	).Scan(&danger)
	if danger {
		return
	}

	for _, i := range pendingIdx {
		mg := &groups[i]

		var row struct {
			OurPostingStatus     *string `gorm:"column:ourpostingstatus"`
			Settings             *string `gorm:"column:settings"`
			Rules                *string `gorm:"column:rules"`
			Autofunctionoverride *string `gorm:"column:autofunctionoverride"`
			Overridemoderation   *string `gorm:"column:overridemoderation"`
		}
		db.Raw("SELECT mem.ourPostingStatus AS ourpostingstatus, g.settings AS settings, g.rules AS rules, "+
			"g.autofunctionoverride AS autofunctionoverride, g.overridemoderation AS overridemoderation "+
			"FROM `groups` g LEFT JOIN memberships mem ON mem.groupid = g.id AND mem.userid = ? "+
			"WHERE g.id = ? LIMIT 1", message.Fromuser, mg.Groupid).Scan(&row)

		var settings map[string]interface{}
		if row.Settings != nil && *row.Settings != "" {
			_ = json.Unmarshal([]byte(*row.Settings), &settings)
		}

		// groupAllowsAutoApprove parity (PHP AutoApproveCleanService::groupAllowsAutoApprove).
		groupAllows := true
		if settings != nil {
			if v, ok := settings["publish"]; ok && !phpTruthy(v) { // getSetting('publish', true)
				groupAllows = false
			}
		}
		if groupAllows && settings != nil && phpTruthy(settings["closed"]) { // isClosed()
			groupAllows = false
		}
		if groupAllows && row.Autofunctionoverride != nil && phpTruthy(*row.Autofunctionoverride) {
			groupAllows = false
		}
		if groupAllows && row.Overridemoderation != nil && *row.Overridemoderation == "ModerateAll" {
			groupAllows = false
		}
		if groupAllows && settings != nil && phpTruthy(settings["moderated"]) { // getSetting('moderated', 0)
			groupAllows = false
		}
		if groupAllows && row.Rules != nil && *row.Rules != "" {
			var rules map[string]interface{}
			if json.Unmarshal([]byte(*row.Rules), &rules) == nil && phpTruthy(rules["fullymoderated"]) {
				groupAllows = false
			}
		}

		onCleanPath := cleanPathEnabledFor(mg.Groupid) &&
			groupAllows &&
			row.OurPostingStatus == nil &&
			mg.ContentcheckCheckedAt != nil &&
			mg.ContentcheckReasons == nil &&
			mg.QualitySample == 0

		// delay_minutes / quality_check_percent from settings.autoapprove (0/absent => default).
		delayMinutes := 20
		qualityPercent := 0
		if settings != nil {
			if aa, ok := settings["autoapprove"].(map[string]interface{}); ok {
				if dm, ok := aa["delay_minutes"].(float64); ok && dm > 0 {
					delayMinutes = int(dm)
				}
				if qp, ok := aa["quality_check_percent"].(float64); ok && qp > 0 {
					qualityPercent = int(qp)
				}
			}
		}
		// Deterministic quality sample (mirror PHP isQualitySampled: crc32(msgid) % 100 < percent).
		qualitySampled := qualityPercent > 0 && int(crc32.ChecksumIEEE([]byte(idStr))%100) < qualityPercent

		var base time.Time
		if onCleanPath && !qualitySampled {
			base = mg.Arrival.Add(time.Duration(delayMinutes) * time.Minute)
		} else if mg.Spamtype == nil {
			// Not on the clean path (or quality-sampled), but not spam/held: 48h fallback.
			// (Estimate nuance: the fallback also needs >=48h membership, which we ignore.)
			base = mg.Arrival.Add(48 * time.Hour)
		}

		if !base.IsZero() {
			t := base
			if mg.AutoapproveHoldUntil != nil && mg.AutoapproveHoldUntil.After(t) {
				t = *mg.AutoapproveHoldUntil
			}
			groups[i].Autoapproveat = &t
		}
	}
}
