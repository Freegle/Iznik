package message

import (
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// SitemapEntry is one post's worth of what a sitemap needs: which URL, and when it
// last changed. Deliberately tiny - the site has tens of thousands of live posts and
// the whole list is fetched in one go each time the sitemap is rebuilt.
type SitemapEntry struct {
	ID      uint64    `json:"id"`
	Lastmod time.Time `json:"lastmod"`
}

// Sitemap returns every post that is currently live, for the search-engine sitemap.
//
// We read messages_spatial rather than messages/messages_groups because it is already
// exactly the set the site treats as currently visible: the daily batch prunes expired
// posts out of it, it holds one row per message (msgid is UNIQUE), and it is small.
// The alternative - joining messages to messages_groups and messages_outcomes across
// 8m rows - is a much heavier query to run for every sitemap fetch.
//
// Excluded:
//   - successful posts (taken/received). Those pages answer 410 and are noindex, so
//     listing them would be asking Google to crawl dead URLs.
//   - anything that isn't an Offer or a Wanted. Admin/Other posts aren't items, and
//     rows with no type set aren't worth pointing a crawler at.
func Sitemap(c *fiber.Ctx) error {
	db := database.DBConn

	entries := []SitemapEntry{}

	db.Raw(""+
		"SELECT msgid AS id, "+
		// modified tracks edits; arrival is when it landed. Either is a fair lastmod,
		// so take whichever is later, and fall back to arrival when modified is null.
		"GREATEST(COALESCE(modified, arrival), arrival) AS lastmod "+
		"FROM messages_spatial "+
		"WHERE successful = 0 "+
		"AND msgtype IN (?, ?) "+
		"AND arrival IS NOT NULL "+
		"ORDER BY arrival DESC",
		utils.OFFER, utils.WANTED,
	).Scan(&entries)

	if entries == nil {
		entries = make([]SitemapEntry, 0)
	}

	// This is fetched by our own sitemap builder on a schedule, not per visitor, but
	// it's public and cheap to cache.
	c.Set("Cache-Control", "public, max-age=600")

	return c.JSON(entries)
}
