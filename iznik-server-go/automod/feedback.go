// Package automod holds the ModTools-facing endpoints for the automod flowchart's stored
// decisions: moderator feedback on a single step, and the SysAdmin agreement report. The
// decisions themselves are written by the batch container (messages:automod); this package
// only reads and annotates them.
package automod

import (
	"encoding/json"
	"log"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// FeedbackRequest is the request body for POST and DELETE /modtools/automod/feedback.
type FeedbackRequest struct {
	Msgid   uint64 `json:"msgid"`
	Groupid uint64 `json:"groupid"`
	Node    string `json:"node"`
}

// storedDecision is the messages_automod row a piece of feedback is about.
type storedDecision struct {
	ID      uint64
	EndNode string `gorm:"column:end_node"`
	Path    json.RawMessage
}

// feedbackDecision applies the checks marking a step and taking the mark back share: the request
// names a message, community and step, the caller moderates that community, the community is in
// one of the automod lists, and the message has a stored decision there. The error is the answer
// to send.
func feedbackDecision(myid uint64, req FeedbackRequest) (*storedDecision, error) {
	if req.Msgid == 0 || req.Groupid == 0 || req.Node == "" {
		return nil, fiber.NewError(fiber.StatusBadRequest, "msgid, groupid and node are required")
	}

	if !user.IsModOfGroup(myid, req.Groupid) {
		return nil, fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
	}

	if !utils.AutomodGroup(req.Groupid) {
		return nil, fiber.NewError(fiber.StatusForbidden, "Not an automod group")
	}

	// messages_automod has a UNIQUE (msgid, groupid): at most one row, no ORDER BY/LIMIT
	// needed to find it.
	var decision storedDecision
	database.DBConn.Table("messages_automod").Select("id, end_node, path").
		Where("msgid = ? AND groupid = ?", req.Msgid, req.Groupid).
		Scan(&decision)

	if decision.ID == 0 {
		return nil, fiber.NewError(fiber.StatusNotFound, "No automod decision for this message and group")
	}

	return &decision, nil
}

// Feedback handles POST /modtools/automod/feedback. A moderator viewing a message's
// automod decision (messages_groups[].automod, see message.populateAutomodDecisions) can
// flag that one step (node) in the recorded path was wrong. This is not a dispute of the
// final verdict - it is per-node signal the agreement endpoint counts, to find which
// question is unreliable rather than just how often the chart as a whole is right.
//
// The node must be one the stored decision actually walked (a node in its path, or its end
// node): the report groups by this string, so it is not left to the caller to invent. One
// mark per moderator per node: a second click is answered with already=true and counts once.
//
// @Summary Record moderator feedback on one step of an automod decision
// @Tags automod
// @Accept json
// @Produce json
// @Param request body FeedbackRequest true "msgid, groupid, node"
// @Success 200 {object} map[string]interface{} "success, already (true when this moderator had marked this step before)"
// @Failure 400 {object} map[string]interface{} "node is not a step of this decision"
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Failure 404 {object} map[string]interface{}
// @Router /modtools/automod/feedback [post]
func Feedback(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req FeedbackRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	decision, err := feedbackDecision(myid, req)
	if err != nil {
		return err
	}

	if !decisionHasNode(decision.EndNode, decision.Path, req.Node) {
		return fiber.NewError(fiber.StatusBadRequest, "node is not a step of this decision")
	}

	db := database.DBConn

	var already int64
	db.Table("messages_automod_feedback").
		Where("automodid = ? AND node = ? AND userid = ?", decision.ID, req.Node, myid).
		Count(&already)
	if already > 0 {
		return c.JSON(fiber.Map{"success": true, "already": true})
	}

	// INSERT IGNORE against the unique (automodid, node, userid): two clicks that race past
	// the count above still leave one row.
	res := db.Table("messages_automod_feedback").Clauses(clause.Insert{Modifier: "IGNORE"}).
		Create(map[string]interface{}{
			"automodid": decision.ID,
			"node":      req.Node,
			"userid":    myid,
			"created":   gorm.Expr("NOW()"),
		})
	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to record feedback")
	}

	log.Printf("automod: feedback msgid=%d groupid=%d byuser=%d node=%s", req.Msgid, req.Groupid, myid, req.Node)

	return c.JSON(fiber.Map{"success": true, "already": res.RowsAffected == 0})
}

// UndoFeedback handles DELETE /modtools/automod/feedback: a moderator takes back a step they
// marked wrong. It removes only the caller's own mark, under the same checks as marking it, and
// answers 404 when there is none to remove, so a second click is told so. The step is named in
// the JSON body, as ModTools sends it, or failing that in the query.
//
// @Summary Take back moderator feedback on one step of an automod decision
// @Tags automod
// @Accept json
// @Produce json
// @Param request body FeedbackRequest true "msgid, groupid, node"
// @Success 200 {object} map[string]interface{} "success"
// @Failure 400 {object} map[string]interface{} "msgid, groupid and node are required"
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Failure 404 {object} map[string]interface{} "no decision, or this moderator has not marked this step"
// @Router /modtools/automod/feedback [delete]
func UndoFeedback(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req FeedbackRequest
	if err := c.BodyParser(&req); err != nil || req.Msgid == 0 {
		// Not every client sends a body on a DELETE: fall back to the query.
		req = FeedbackRequest{
			Msgid:   uint64(c.QueryInt("msgid", 0)),
			Groupid: uint64(c.QueryInt("groupid", 0)),
			Node:    c.Query("node"),
		}
	}

	decision, err := feedbackDecision(myid, req)
	if err != nil {
		return err
	}

	res := database.DBConn.Table("messages_automod_feedback").
		Where("automodid = ? AND node = ? AND userid = ?", decision.ID, req.Node, myid).
		Delete(nil)
	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to remove feedback")
	}

	if res.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "You have not marked this step")
	}

	log.Printf("automod: feedback removed msgid=%d groupid=%d byuser=%d node=%s", req.Msgid, req.Groupid, myid, req.Node)

	return c.JSON(fiber.Map{"success": true})
}

// decisionHasNode reports whether node is a step the stored decision walked: one of the
// path entries' node ids, or the end node.
func decisionHasNode(endNode string, path json.RawMessage, node string) bool {
	if node == endNode && endNode != "" {
		return true
	}

	var steps []struct {
		Node string `json:"node"`
	}
	if len(path) == 0 || json.Unmarshal(path, &steps) != nil {
		return false
	}
	for _, s := range steps {
		if s.Node == node {
			return true
		}
	}

	return false
}
