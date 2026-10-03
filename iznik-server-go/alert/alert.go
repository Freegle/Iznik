package alert

import (
	"regexp"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

var htmlTagRE = regexp.MustCompile(`(?s)<[^>]*>`)

// htmlIsBlank reports whether an HTML fragment carries no visible text.
func htmlIsBlank(s string) bool {
	stripped := htmlTagRE.ReplaceAllString(s, "")
	return strings.TrimSpace(stripped) == ""
}

type Alert struct {
	ID        uint64  `json:"id" gorm:"primary_key"`
	Createdby *uint64 `json:"createdby"`
	From      string  `json:"from"`
	To        string  `json:"to"`
	Subject   string  `json:"subject"`
	Text      string  `json:"text"`
	Html      string  `json:"html"`
	Askclick  int     `json:"askclick"`
	Tryhard   int     `json:"tryhard"`
	Complete  string  `json:"complete"`
	Created   string  `json:"created"`
}

func (Alert) TableName() string {
	return "alerts"
}

type AlertStats struct {
	Responses []AlertResponseStat `json:"responses"`
	Reached   int64               `json:"reached"`
}

type AlertResponseStat struct {
	Response string `json:"response"`
	Count    int64  `json:"count"`
}

func GetAlert(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	db := database.DBConn

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
	}

	var a Alert
	db.Table("alerts").
		Select("id, createdby, `from`, `to`, subject, text, html, askclick, tryhard, complete, created").
		Where("id = ?", id).
		Scan(&a)

	if a.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Alert not found")
	}

	response := fiber.Map{
		"ret":    0,
		"status": "Success",
		"alert":  a,
	}

	if myid > 0 && user.IsAdminOrSupport(myid) {
		var stats AlertStats
		var responseCounts []AlertResponseStat

		db.Table("alerts_tracking").
			Select("response, COUNT(*) AS count").
			Where("alertid = ? AND response IS NOT NULL", id).
			Group("response").
			Scan(&responseCounts)

		if responseCounts == nil {
			responseCounts = make([]AlertResponseStat, 0)
		}

		stats.Responses = responseCounts

		db.Table("alerts_tracking").
			Where("alertid = ?", id).
			Count(&stats.Reached)

		alertMap := response["alert"].(Alert)
		response["alert"] = fiber.Map{
			"id":        alertMap.ID,
			"createdby": alertMap.Createdby,
			"from":      alertMap.From,
			"to":        alertMap.To,
			"subject":   alertMap.Subject,
			"text":      alertMap.Text,
			"html":      alertMap.Html,
			"askclick":  alertMap.Askclick,
			"tryhard":   alertMap.Tryhard,
			"complete":  alertMap.Complete,
			"created":   alertMap.Created,
			"stats":     stats,
		}
	}

	return c.JSON(response)
}

func ListAlerts(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	db := database.DBConn

	var alerts []Alert
	db.Table("alerts").
		Select("id, createdby, `from`, `to`, subject, text, html, askclick, tryhard, complete, created").
		Order("created DESC").
		Scan(&alerts)

	if alerts == nil {
		alerts = make([]Alert, 0)
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"alerts": alerts,
	})
}

func CreateAlert(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	type CreateRequest struct {
		From     string `json:"from"`
		To       string `json:"to"`
		Subject  string `json:"subject"`
		Text     string `json:"text"`
		Html     string `json:"html"`
		Askclick *int   `json:"askclick"`
		Tryhard  *int   `json:"tryhard"`
	}

	var req CreateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.To == "" {
		req.To = "Mods"
	}

	if htmlIsBlank(req.Html) {
		req.Html = strings.ReplaceAll(req.Text, "\n", "<br>")
	}

	askclick := 1
	if req.Askclick != nil {
		askclick = *req.Askclick
	}

	tryhard := 1
	if req.Tryhard != nil {
		tryhard = *req.Tryhard
	}

	db := database.DBConn
	row := map[string]interface{}{
		"createdby": myid,
		"from":      req.From,
		"to":        req.To,
		"subject":   req.Subject,
		"text":      req.Text,
		"html":      req.Html,
		"askclick":  askclick,
		"tryhard":   tryhard,
		"created":   gorm.Expr("NOW()"),
	}

	if err := db.Table("alerts").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create alert")
	}

	alertIDInt, _ := row["@id"].(int64)
	alertID := uint64(alertIDInt)

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     alertID,
	})
}

func RecordAlert(c *fiber.Ctx) error {
	type RecordRequest struct {
		Action  string `json:"action"`
		Trackid uint64 `json:"trackid"`
	}

	var req RecordRequest
	_ = c.BodyParser(&req)

	if req.Action == "clicked" && req.Trackid > 0 {
		db := database.DBConn
		db.Table("alerts_tracking").
			Where("id = ?", req.Trackid).
			Updates(map[string]interface{}{
				"responded": gorm.Expr("NOW()"),
				"response":  gorm.Expr("'Clicked'"),
			})
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
	})
}
