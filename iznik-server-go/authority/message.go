package authority

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

func Messages(c *fiber.Ctx) error {
	id := c.Params("id", "0")
	db := database.DBConn

	myid := user.WhoAmI(c)

	msgs := []message.MessageSummary{}

	db.Table("messages_spatial").
		Select("ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, "+
			"messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, "+
			"messages_spatial.msgtype AS type, messages_spatial.arrival, "+
			"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen").
		Joins("INNER JOIN authorities ON ST_Contains(authorities.polygon, point)").
		Joins("INNER JOIN `groups` ON groups.id = messages_spatial.groupid").
		Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
		Where("authorities.id = ? AND messages_spatial.msgid > 0", id).
		Order("unseen DESC, messages_spatial.arrival DESC, messages_spatial.msgid DESC").
		Scan(&msgs)

	// One batched routing call resolves every location's road-aware blur.
	coords := make([][2]float64, 0, len(msgs))
	for _, r := range msgs {
		coords = append(coords, [2]float64{float64(r.Lat), float64(r.Lng)})
	}
	roadblur.RoadBlurPrewarm(coords, utils.BLUR_USER)
	for ix, r := range msgs {
		// Protect anonymity of poster a bit.
		msgs[ix].Lat, msgs[ix].Lng = roadblur.RoadBlur(r.Lat, r.Lng, utils.BLUR_USER)
	}

	return c.JSON(msgs)
}
