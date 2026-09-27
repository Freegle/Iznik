package automod

import (
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// EndCount is how often the chart ended at one node, and how often a moderator then did the
// opposite: approved a post the chart held, or rejected, deleted or held one it approved.
type EndCount struct {
	End          string `json:"end" gorm:"column:end_node"`
	Verdict      string `json:"verdict" gorm:"column:verdict"`
	Count        int64  `json:"count" gorm:"column:count"`
	ModDisagreed int64  `json:"modDisagreed" gorm:"column:mod_disagreed"`
}

// FeedbackCount is how many times moderators marked one node's answer as wrong.
type FeedbackCount struct {
	Node  string `json:"node" gorm:"column:node"`
	Count int64  `json:"count" gorm:"column:count"`
}

// ModeCount is how many decisions were recorded in one mode.
type ModeCount struct {
	Count int64 `json:"count"`
}

// AgreementResponse is the SysAdmin automated review report.
type AgreementResponse struct {
	Days     int             `json:"days"`
	Total    int64           `json:"total"`
	ByEnd    []EndCount      `json:"byEnd"`
	Feedback []FeedbackCount `json:"feedback"`
	Shadow   ModeCount       `json:"shadow"`
	Approve  ModeCount       `json:"approve"`
}

// modDisagreedSQL: a hold is contradicted by a human approval of that copy; an approve by a
// moderator's Rejected, Deleted or Hold log on that copy after the decision.
const modDisagreedSQL = `SUM(CASE
	WHEN ma.verdict = 'hold' THEN mg.approvedby IS NOT NULL
	ELSE EXISTS (SELECT 1 FROM logs l
		WHERE l.msgid = ma.msgid AND l.groupid = ma.groupid AND l.type = 'Message'
		AND l.subtype IN ('Rejected', 'Deleted', 'Hold')
		AND l.byuser IS NOT NULL AND l.timestamp >= ma.created)
	END) AS mod_disagreed`

// Agreement handles GET /modtools/automod/agreement: how the chart's decisions over the last
// N days compare with what moderators then did, by end node, plus "this step is wrong"
// counts by node. Support or Admin only, like the moderation stats it sits beside.
//
// @Summary Automated review agreement with moderators
// @Tags automod
// @Produce json
// @Param days query int false "Window in days (1-365, default 30)"
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

	db := database.DBConn
	since := gorm.Expr("NOW() - INTERVAL ? DAY", days)
	resp := AgreementResponse{Days: days, ByEnd: []EndCount{}, Feedback: []FeedbackCount{}}

	db.Table("messages_automod ma").
		Select("ma.end_node, ma.verdict, COUNT(*) AS count, "+modDisagreedSQL).
		Joins("LEFT JOIN messages_groups mg ON mg.msgid = ma.msgid AND mg.groupid = ma.groupid").
		Where("ma.created >= ?", since).
		Group("ma.end_node, ma.verdict").
		Order("count DESC").
		Scan(&resp.ByEnd)

	db.Table("messages_automod_feedback").
		Select("node, COUNT(*) AS count").
		Where("created >= ?", since).
		Group("node").
		Order("count DESC").
		Scan(&resp.Feedback)

	db.Table("messages_automod").Where("created >= ? AND mode = ?", since, "shadow").Count(&resp.Shadow.Count)
	db.Table("messages_automod").Where("created >= ? AND mode = ?", since, "approve").Count(&resp.Approve.Count)
	resp.Total = resp.Shadow.Count + resp.Approve.Count

	return c.JSON(resp)
}
