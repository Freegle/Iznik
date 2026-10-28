package message

import (
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm/clause"
	"gorm.io/plugin/dbresolver"
)

// The "I'm interested" button (experiment interested-button, docs/developers/reference/ux-experiments.md).
//
// A member can mark a post they might want before they are ready to reply. The mark is a row in
// messages_interested; a batch command emails a reminder while the post is still open. The server says
// per member whether to offer the button (the arm), and records the exposure when a signed-in web member
// opens a post they could reply to, whatever the arm. A mark is allowed whatever the arm: a page opened
// before the experiment was stopped must still work, and a mark already made always shows.

const (
	interestedExperiment = "interested-button"
	interestedListDays   = 30
	interestedListMax    = 100
)

// InterestedState is the answer to GET /message/:id/interested.
type InterestedState struct {
	// Arm is "button" or "control" for a member in the experiment, and "" when they are not.
	Arm        string `json:"arm"`
	Interested bool   `json:"interested"`
	Eligible   bool   `json:"eligible"`
}

// InterestedRequest is the body of POST /message/:id/interested.
type InterestedRequest struct {
	Interested bool `json:"interested"`
}

// InterestedMark is one row of GET /message/interested.
type InterestedMark struct {
	Msgid    uint64  `json:"msgid"`
	Added    string  `json:"added"`
	Chasedat *string `json:"chasedat"`
	Replied  bool    `json:"replied"`
	Open     bool    `json:"open"`
}

// postStillOpenSQL is true for a message row (alias m) that is not deleted, has no outcome and is still
// approved on a community.
const postStillOpenSQL = "m.deleted IS NULL AND NOT EXISTS (SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = m.id) " +
	"AND EXISTS (SELECT 1 FROM messages_groups mg WHERE mg.msgid = m.id AND mg.collection = 'Approved' AND mg.deleted = 0)"

// interestedEligible says whether the member could mark this post: an open Offer or Wanted that is not
// theirs, with neither side a TrashNothing member (their replies travel through TrashNothing, so a
// reminder would lead nowhere). Read on the primary, because a mark may be seconds old.
func interestedEligible(myid uint64, msgid uint64) bool {
	var count int64
	database.DBConn.Clauses(dbresolver.Write).Table("messages m").
		Where("m.id = ? AND m.type IN ('Offer', 'Wanted') AND m.fromuser <> ? AND "+postStillOpenSQL, msgid, myid).
		Where("NOT EXISTS (SELECT 1 FROM users u WHERE u.id IN (?, m.fromuser) AND "+user.TrashNothingSQL("u")+")", myid).
		Count(&count)

	return count > 0
}

func interestedMarked(myid uint64, msgid uint64) bool {
	var count int64
	database.DBConn.Clauses(dbresolver.Write).Table("messages_interested").
		Where("userid = ? AND msgid = ?", myid, msgid).Count(&count)

	return count > 0
}

// GetInterested says whether to offer the button on a post, and whether the member has marked it.
//
// @Router /message/{id}/interested [get]
// @Summary Whether to offer "I'm interested" on a post, and whether it is marked
// @Tags message
// @Produce json
// @Param id path int true "Message ID"
// @Success 200 {object} message.InterestedState
// @Failure 401 {object} fiber.Error "Not logged in"
func GetInterested(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	msgid, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || msgid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	ret := InterestedState{Interested: interestedMarked(myid, msgid)}

	// The app takes no part in experiments: its build cannot switch one off quickly.
	if !utils.RequestFromApp(c) && interestedEligible(myid, msgid) {
		ret.Eligible = true
		db := database.DBConn
		ret.Arm = ux.ExperimentArm(db, myid, "interested-button")

		// Both arms leave a row. Written on the primary; a failure is logged by RecordExposure and
		// must not stop the page.
		_ = ux.RecordExposure(db, interestedExperiment, ret.Arm, myid, "view", msgid, nil, nil)
	}

	return c.JSON(ret)
}

// SetInterested marks a post, or removes the mark.
//
// @Router /message/{id}/interested [post]
// @Summary Mark a post as "I'm interested", or remove the mark
// @Tags message
// @Accept json
// @Produce json
// @Param id path int true "Message ID"
// @Param body body message.InterestedRequest true "interested true to mark, false to remove"
// @Success 200 {object} map[string]bool
// @Failure 400 {object} fiber.Error "Invalid request, or the post cannot be marked"
// @Failure 401 {object} fiber.Error "Not logged in"
func SetInterested(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	msgid, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || msgid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	var req InterestedRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn.Clauses(dbresolver.Write)

	if !req.Interested {
		if err := db.Table("messages_interested").Where("userid = ? AND msgid = ?", myid, msgid).Delete(nil).Error; err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Could not remove the mark")
		}

		return c.JSON(fiber.Map{"interested": false})
	}

	if !interestedEligible(myid, msgid) {
		return fiber.NewError(fiber.StatusBadRequest, "You can't mark this post")
	}

	// A second mark is ignored, so a double tap keeps the first time.
	if err := db.Clauses(clause.Insert{Modifier: "IGNORE"}).Table("messages_interested").
		Create(map[string]interface{}{"msgid": msgid, "userid": myid}).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not save the mark")
	}

	return c.JSON(fiber.Map{"interested": true})
}

type interestedRow struct {
	Msgid    uint64
	Added    time.Time
	Chasedat *time.Time
	Replied  bool
	Open     bool
}

// ListInterested returns the posts the member marked in the last 30 days, newest first.
//
// @Router /message/interested [get]
// @Summary The member's "I'm interested" marks from the last 30 days
// @Tags message
// @Produce json
// @Success 200 {array} message.InterestedMark
// @Failure 401 {object} fiber.Error "Not logged in"
func ListInterested(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var rows []interestedRow
	database.DBConn.Clauses(dbresolver.Write).Table("messages_interested i").
		Select("i.msgid, i.added, i.chasedat, "+
			"EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.userid = i.userid AND cm.refmsgid = i.msgid AND cm.type = 'Interested') AS replied, "+
			"EXISTS (SELECT 1 FROM messages m WHERE m.id = i.msgid AND "+postStillOpenSQL+") AS open").
		Where("i.userid = ? AND i.added >= DATE_SUB(NOW(), INTERVAL ? DAY)", myid, interestedListDays).
		Order("i.added DESC, i.id DESC").
		Limit(interestedListMax).Scan(&rows)

	ret := make([]InterestedMark, 0, len(rows))
	for _, r := range rows {
		mark := InterestedMark{Msgid: r.Msgid, Added: r.Added.UTC().Format(time.RFC3339), Replied: r.Replied, Open: r.Open}
		if r.Chasedat != nil {
			s := r.Chasedat.UTC().Format(time.RFC3339)
			mark.Chasedat = &s
		}
		ret = append(ret, mark)
	}

	return c.JSON(ret)
}
