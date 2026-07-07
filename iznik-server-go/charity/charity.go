package charity

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

type Charity struct {
	ID            uint64  `json:"id" gorm:"primaryKey;column:id"`
	OrgName       string  `json:"orgname" gorm:"column:orgname"`
	OrgType       string  `json:"orgtype" gorm:"column:orgtype"`
	CharityNumber *string `json:"charitynumber" gorm:"column:charitynumber"`
	OrgDetails    *string `json:"orgdetails" gorm:"column:orgdetails"`
	Website       *string `json:"website" gorm:"column:website"`
	Social        *string `json:"social" gorm:"column:social"`
	Description   *string `json:"description" gorm:"column:description"`
	ContactEmail  string  `json:"contactemail" gorm:"column:contactemail"`
	ContactName   *string `json:"contactname" gorm:"column:contactname"`
	Userid        *uint64 `json:"userid" gorm:"column:userid"`
	Status        string  `json:"status" gorm:"column:status"`
}

func (Charity) TableName() string {
	return "charities"
}

type CreateRequest struct {
	OrgName       string  `json:"orgname"`
	OrgType       string  `json:"orgtype"`
	CharityNumber *string `json:"charitynumber"`
	OrgDetails    *string `json:"orgdetails"`
	Website       *string `json:"website"`
	Social        *string `json:"social"`
	Description   *string `json:"description"`
	ContactEmail  string  `json:"contactemail"`
	ContactName   *string `json:"contactname"`
}

// CreateCharity handles POST /api/charities
// @Summary Register a charity partner
// @Tags charities
// @Accept json
// @Produce json
// @Param body body CreateRequest true "Charity signup details"
// @Success 200 {object} map[string]interface{}
// @Router /api/charities [post]
func CreateCharity(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	var req CreateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.OrgName == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Organisation name is required")
	}

	if req.ContactEmail == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Contact email is required")
	}

	if req.OrgType != "registered" && req.OrgType != "other" {
		req.OrgType = "registered"
	}

	charity := Charity{
		OrgName:       req.OrgName,
		OrgType:       req.OrgType,
		CharityNumber: req.CharityNumber,
		OrgDetails:    req.OrgDetails,
		Website:       req.Website,
		Social:        req.Social,
		Description:   req.Description,
		ContactEmail:  req.ContactEmail,
		ContactName:   req.ContactName,
		Status:        "Pending",
	}

	if myid > 0 {
		charity.Userid = &myid
	}

	db := database.DBConn
	result := db.Create(&charity)

	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create charity signup")
	}

	// Queue email notification to partnerships team.
	queue.QueueTask(queue.TaskEmailCharitySignup, map[string]interface{}{
		"charity_id":    charity.ID,
		"orgname":       charity.OrgName,
		"orgtype":       charity.OrgType,
		"charitynumber": charity.CharityNumber,
		"contactemail":  charity.ContactEmail,
		"contactname":   charity.ContactName,
		"website":       charity.Website,
		"description":   charity.Description,
	})

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     charity.ID,
	})
}

