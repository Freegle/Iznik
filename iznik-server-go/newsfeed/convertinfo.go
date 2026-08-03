package newsfeed

import (
	"strconv"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// ConvertInfoResult is what the convert modal shows a moderator before they
// commit: where the post will land, or why it cannot be posted.
//
// Canpost false is a 200, not an error. The moderator needs to READ the reason
// ("that member has no location set") in the modal they already have open;
// failing the request would just show them a generic failure after they had
// filled the form in.
type ConvertInfoResult struct {
	Canpost      bool   `json:"canpost"`
	Reason       string `json:"reason,omitempty"`
	Locationname string `json:"locationname,omitempty"`
	Groupid      uint64 `json:"groupid,omitempty"`
	Groupname    string `json:"groupname,omitempty"`
}

// ConvertInfo tells a ChitChat moderator which postcode and community a
// convert-to-post would use. The answer comes from the same resolver the post
// itself uses (message.ResolveOnBehalfPosting), so what the modal promises and
// what gets posted cannot disagree.
//
// Mod-only: it exposes where another member lives.
func ConvertInfo(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !canHidePost(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	var author uint64
	database.DBConn.Raw("SELECT userid FROM newsfeed WHERE id = ? AND deleted IS NULL", id).Scan(&author)

	if author == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Newsfeed item not found")
	}

	posting, err := message.ResolveOnBehalfPosting(author)
	if err != nil {
		return c.JSON(ConvertInfoResult{Canpost: false, Reason: err.Error()})
	}

	return c.JSON(ConvertInfoResult{
		Canpost:      true,
		Locationname: posting.Locationname,
		Groupid:      posting.Groupid,
		Groupname:    posting.Groupname,
	})
}
