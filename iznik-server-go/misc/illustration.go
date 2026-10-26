package misc

import (
	"database/sql"
	"os"
	"regexp"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// Pre-compiled regexps for item name normalisation.
var prefixPattern = regexp.MustCompile(`(?i)^(OFFER|OFFERED|WANTED|REQUESTED|TAKEN|RECEIVED):\s*`)
var suffixPattern = regexp.MustCompile(`\s*\([^)]+\)\s*$`)

// IllustrationResult is the response structure for the illustration endpoint.
type IllustrationResult struct {
	Ret          int                    `json:"ret"`
	Status       string                 `json:"status"`
	Illustration *IllustrationData      `json:"illustration,omitempty"`
}

// IllustrationData contains the AI illustration details.
type IllustrationData struct {
	ExternalUID string `json:"externaluid"`
	URL         string `json:"url"`
	Cached      bool   `json:"cached"`
}

// IllustrationItemName is the name a post's picture is keyed by: the subject without its
// OFFER/WANTED prefix, location or courtesy words. The batch's extractItemName does the same.
func IllustrationItemName(subject string) string {
	itemName := strings.TrimSpace(subject)
	itemName = prefixPattern.ReplaceAllString(itemName, "")
	itemName = suffixPattern.ReplaceAllString(itemName, "")
	// "iron please" is keyed as "iron".
	itemName = StripCourtesy(itemName)

	return strings.TrimSpace(itemName)
}

// GetIllustration returns an AI illustration for an item name.
// This endpoint checks the cache and returns cached illustrations.
// If not cached, returns ret=3 so the frontend can fall back to generation.
func GetIllustration(c *fiber.Ctx) error {
	item := c.Query("item")

	if item == "" || strings.TrimSpace(item) == "" {
		return c.JSON(IllustrationResult{
			Ret:    2,
			Status: "Item name required",
		})
	}

	itemName := IllustrationItemName(item)

	if itemName == "" {
		return c.JSON(IllustrationResult{
			Ret:    2,
			Status: "Item name required",
		})
	}

	// Check the cache.
	db := database.DBConn
	var externalUID sql.NullString

	// Only an active image is offered: rejected, regenerating and suppressed ones are
	// blanked when a post is shown, so attaching one leaves the post with an empty picture.
	err := db.Table("ai_images").Select("externaluid").Where("name = ? AND status = 'active'", itemName).Scan(&externalUID).Error

	if err == nil && (!externalUID.Valid || externalUID.String == "") {
		// A name the batch has matched to another name's picture ("Sofas" -> "Sofa"),
		// see AiImageMatcherService. The match itself runs in the batch, not here.
		err = db.Table("ai_image_aliases").
			Select("ai_images.externaluid").
			Joins("INNER JOIN ai_images ON ai_images.id = ai_image_aliases.aiimageid").
			Where("ai_image_aliases.name = ? AND ai_images.status = 'active'", itemName).
			Scan(&externalUID).Error
	}

	if err != nil || !externalUID.Valid || externalUID.String == "" {
		// Not cached - frontend should fall back to image generation.
		return c.JSON(IllustrationResult{
			Ret:    3,
			Status: "Not cached - use generation API",
		})
	}

	// Build the image URL.
	imagesHost := os.Getenv("IMAGES_HOST")
	if imagesHost == "" {
		imagesHost = "https://images.ilovefreegle.org"
	}

	return c.JSON(IllustrationResult{
		Ret:    0,
		Status: "Success",
		Illustration: &IllustrationData{
			ExternalUID: externalUID.String,
			URL:         imagesHost + "/" + externalUID.String,
			Cached:      true,
		},
	})
}
