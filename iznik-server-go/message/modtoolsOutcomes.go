package message

import (
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// otherUser is the other member in a completed freegle, for the ModTools
// home page "thank" prompt.
type otherUser struct {
	ID          uint64 `json:"id"`
	Displayname string `json:"displayname"`
}

// outcomeItem is one row for GET /modtools/outcomes.
type outcomeItem struct {
	ID        uint64     `json:"id"`
	Subject   string     `json:"subject"`
	Outcome   string     `json:"outcome"`
	Otheruser *otherUser `json:"otheruser"`
}

// outcomeRow is the raw scan target joining messages_outcomes to messages.
type outcomeRow struct {
	ID       uint64  `json:"id"`
	Subject  string  `json:"subject"`
	Outcome  string  `json:"outcome"`
	Fromuser uint64  `json:"fromuser"`
	Userid   *uint64 `json:"userid"`
}

// ListOutcomes handles GET /modtools/outcomes - completed freegles (Taken
// and Received) with the two members, for the ModTools home page.
//
// @Summary List completed freegle outcomes
// @Tags message
// @Produce json
// @Param since query integer false "Hours to look back"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/outcomes [get]
func ListOutcomes(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	sinceHours := 24
	if s := c.Query("since"); s != "" {
		if v, err := strconv.Atoi(s); err == nil && v > 0 {
			sinceHours = v
		}
	}

	cutoff := time.Now().Add(-time.Duration(sinceHours) * time.Hour)

	db := database.DBConn

	var rows []outcomeRow
	db.Table("messages_outcomes mo").
		Select("mo.id, m.subject, mo.outcome, m.fromuser, mo.userid").
		Joins("JOIN messages m ON m.id = mo.msgid").
		Where("mo.outcome IN ('Taken', 'Received') AND mo.timestamp >= ?", cutoff).
		Order("mo.timestamp DESC").
		Limit(200).
		Scan(&rows)

	// Batch-lookup displaynames for the "other" member (the one who took or
	// received, when that's recorded and differs from the poster).
	otherIds := make(map[uint64]bool)
	for _, r := range rows {
		if r.Userid != nil && *r.Userid != r.Fromuser {
			otherIds[*r.Userid] = true
		}
	}

	names := make(map[uint64]string)
	if len(otherIds) > 0 {
		ids := make([]uint64, 0, len(otherIds))
		for id := range otherIds {
			ids = append(ids, id)
		}

		var named []struct {
			ID          uint64 `json:"id"`
			Displayname string `json:"displayname"`
		}

		db.Table("users").
			Select("id, COALESCE(fullname, firstname, lastname, 'Unknown') AS displayname").
			Where("id IN ?", ids).
			Scan(&named)

		for _, n := range named {
			names[n.ID] = n.Displayname
		}
	}

	outcomes := make([]outcomeItem, 0, len(rows))
	for _, r := range rows {
		item := outcomeItem{
			ID:      r.ID,
			Subject: r.Subject,
			Outcome: r.Outcome,
		}

		if r.Userid != nil && *r.Userid != r.Fromuser {
			if name, ok := names[*r.Userid]; ok {
				item.Otheruser = &otherUser{ID: *r.Userid, Displayname: name}
			}
		}

		outcomes = append(outcomes, item)
	}

	return c.JSON(fiber.Map{
		"outcomes": outcomes,
	})
}

type reviewOutcomeRequest struct {
	Reviewed bool `json:"reviewed"`
}

// ReviewOutcome handles PATCH /modtools/outcomes/:id.
//
// @Summary Mark a completed freegle outcome as reviewed
// @Tags message
// @Produce json
// @Param id path integer true "Outcome ID"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/outcomes/{id} [patch]
func ReviewOutcome(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	var req reviewOutcomeRequest
	_ = c.BodyParser(&req)

	db := database.DBConn
	db.Table("messages_outcomes").Where("id = ?", id).Update("reviewed", req.Reviewed)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
