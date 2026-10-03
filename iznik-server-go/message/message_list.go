package message

import (
	"encoding/json"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// ModtoolsMessageItem is one row for GET /modtools/messages: national, no groups.
type ModtoolsMessageItem struct {
	ID                  uint64           `json:"id"`
	Subject             string           `json:"subject"`
	Type                string           `json:"type"`
	Fromuser            uint64           `json:"fromuser"`
	Collection          string           `json:"collection,omitempty"`
	Arrival             time.Time        `json:"arrival"`
	Deleted             *time.Time       `json:"deleted,omitempty"`
	ContentcheckReasons *json.RawMessage `json:"contentcheck_reasons,omitempty"`
}

// ListMessagesMT handles GET /modtools/messages?filter=published|takendown&since=<hours>.
// National moderator listing: one row per message, no groups, no queues. "published"
// is posts currently live (messages.deleted IS NULL); "takendown" is posts the system
// or a moderator has taken down (messages.deleted IS NOT NULL), with the reasons
// recorded by handleMessageTakeDown in messages.contentcheck_reasons. Newest first.
func ListMessagesMT(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
	}

	filter := c.Query("filter", "published")

	sinceHours := 24
	if s := c.Query("since"); s != "" {
		if v, err := strconv.Atoi(s); err == nil && v > 0 {
			sinceHours = v
		}
	}

	db := database.DBConn
	q := db.Table("messages").
		Select("id, subject, type, fromuser, collection, arrival, deleted, contentcheck_reasons").
		Where("arrival >= ?", time.Now().Add(-time.Duration(sinceHours)*time.Hour))

	if filter == "takendown" {
		q = q.Where("deleted IS NOT NULL")
	} else {
		filter = "published"
		q = q.Where("deleted IS NULL")
	}

	var items []ModtoolsMessageItem
	q.Order("arrival DESC").Limit(500).Scan(&items)

	if items == nil {
		items = []ModtoolsMessageItem{}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "messages": items})
}

// GetMessagesWithHistory handles GET /message/:ids - fetches one or more messages.
// Message history is now returned via the user endpoint (GET /user/fetchmt?modtools=true).
func GetMessagesWithHistory(c *fiber.Ctx) error {
	ids := strings.Split(c.Params("ids"), ",")
	myid := user.WhoAmI(c)
	isPartner := false
	if key := c.Query("partner"); key != "" {
		if _, _, _, err := user.ValidatePartnerKey(database.DBConn, key); err == nil {
			isPartner = true
		}
	}

	if len(ids) >= 20 {
		return fiber.NewError(fiber.StatusBadRequest, "Steady on")
	}

	messages := GetMessagesByIds(myid, ids, isPartner)
	addRoadMetrics(myid, messages)

	if len(ids) == 1 {
		if len(messages) == 1 {
			return c.JSON(messages[0])
		}
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	return c.JSON(messages)
}
