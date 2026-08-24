package domain

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// GetDomain handles GET /domains - check if a domain exists or suggest alternatives.
//
// @Summary Check domain and suggest alternatives
// @Tags domain
// @Produce json
// @Param domain query string true "Domain to check"
// @Success 200 {object} map[string]interface{}
// @Router /api/domains [get]
func GetDomain(c *fiber.Ctx) error {
	domainName := c.Query("domain", "")

	if domainName == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing domain"})
	}

	db := database.DBConn

	// Check if domain exists.
	var id uint64
	db.Table("domains_common").Select("id").Where("domain LIKE ?", domainName).Scan(&id)

	if id > 0 {
		// Domain found - return empty success.
		return c.JSON(fiber.Map{
			"ret":    0,
			"status": "Success",
		})
	}

	// Domain not found - suggest similar domains using damlevlim().
	var suggestions []string
	db.Table("domains_common").
		Select("domain").
		Where("damlevlim(domain, ?, LENGTH(?)) < 3", domainName, domainName).
		Order("count DESC").
		Limit(5).
		Scan(&suggestions)

	if suggestions == nil {
		suggestions = make([]string, 0)
	}

	return c.JSON(fiber.Map{
		"ret":         0,
		"status":      "Success",
		"suggestions": suggestions,
	})
}
