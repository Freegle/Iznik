package emailtracking

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	neturl "net/url"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// EmailTracking represents an email tracking record
type EmailTracking struct {
	ID                 uint64     `json:"id" gorm:"primaryKey;column:id"`
	TrackingID         string     `json:"tracking_id" gorm:"column:tracking_id"`
	EmailType          string     `json:"email_type" gorm:"column:email_type"`
	UserID             *uint64    `json:"userid" gorm:"column:userid"`
	GroupID            *uint64    `json:"groupid" gorm:"column:groupid"`
	RecipientEmail     string     `json:"recipient_email" gorm:"column:recipient_email"`
	Subject            *string    `json:"subject" gorm:"column:subject"`
	Metadata           *string    `json:"metadata" gorm:"column:metadata"`
	SentAt             *time.Time `json:"sent_at" gorm:"column:sent_at"`
	DeliveredAt        *time.Time `json:"delivered_at" gorm:"column:delivered_at"`
	BouncedAt          *time.Time `json:"bounced_at" gorm:"column:bounced_at"`
	BounceType         *string    `json:"bounce_type" gorm:"column:bounce_type"`
	OpenedAt           *time.Time `json:"opened_at" gorm:"column:opened_at"`
	OpenedVia          *string    `json:"opened_via" gorm:"column:opened_via"`
	ClickedAt          *time.Time `json:"clicked_at" gorm:"column:clicked_at"`
	ClickedLink        *string    `json:"clicked_link" gorm:"column:clicked_link"`
	ScrollDepthPercent *uint8     `json:"scroll_depth_percent" gorm:"column:scroll_depth_percent"`
	LinksClicked       uint16     `json:"links_clicked" gorm:"column:links_clicked"`
	UnsubscribedAt     *time.Time `json:"unsubscribed_at" gorm:"column:unsubscribed_at"`
	HasAMP             bool       `json:"has_amp" gorm:"column:has_amp"`
	RepliedAt          *time.Time `json:"replied_at" gorm:"column:replied_at"`
	RepliedVia         *string    `json:"replied_via" gorm:"column:replied_via"`
	CreatedAt          time.Time  `json:"created_at" gorm:"column:created_at"`
	UpdatedAt          time.Time  `json:"updated_at" gorm:"column:updated_at"`
}

func (EmailTracking) TableName() string {
	return "email_tracking"
}

// EmailTrackingClick represents a click event (includes button actions like unsubscribe)
type EmailTrackingClick struct {
	ID              uint64    `json:"id" gorm:"primary_key"`
	EmailTrackingID uint64    `json:"email_tracking_id"`
	LinkURL         string    `json:"link_url"`
	LinkPosition    *string   `json:"link_position"`
	Action          *string   `json:"action"` // e.g., "unsubscribe", "cta", "view_item"
	IPAddress       *string   `json:"ip_address"`
	UserAgent       *string   `json:"user_agent"`
	ClickedAt       time.Time `json:"clicked_at"`
}

func (EmailTrackingClick) TableName() string {
	return "email_tracking_clicks"
}

// EmailTrackingImage represents an image load event
type EmailTrackingImage struct {
	ID                     uint64    `json:"id" gorm:"primary_key"`
	EmailTrackingID        uint64    `json:"email_tracking_id"`
	ImagePosition          string    `json:"image_position"`
	EstimatedScrollPercent *uint8    `json:"estimated_scroll_percent"`
	LoadedAt               time.Time `json:"loaded_at"`
}

func (EmailTrackingImage) TableName() string {
	return "email_tracking_images"
}

// EmailStats represents aggregate statistics
type EmailStats struct {
	TotalSent       int64   `json:"total_sent"`
	Opened          int64   `json:"opened"`
	Clicked         int64   `json:"clicked"`
	LinkedBounces   int64   `json:"linked_bounces"`   // Bounces matched to specific tracked emails via bounced_at
	OpenRate        float64 `json:"open_rate"`
	ClickRate       float64 `json:"click_rate"`
	ClickToOpenRate float64 `json:"click_to_open_rate"`
	BounceRate      float64 `json:"bounce_rate"`
	// Actual bounces from bounces_emails table (includes all bounces, not just tracked ones)
	TotalBounces     int64   `json:"total_bounces"`
	PermanentBounces int64   `json:"permanent_bounces"`
	TemporaryBounces int64   `json:"temporary_bounces"`
}

// AMPStats represents AMP-specific statistics
type AMPStats struct {
	TotalWithAMP    int64   `json:"total_with_amp"`
	TotalWithoutAMP int64   `json:"total_without_amp"`
	AMPPercentage   float64 `json:"amp_percentage"`
	// AMP rendering metrics - how many were actually rendered with AMP
	AMPRendered   int64   `json:"amp_rendered"`
	AMPRenderRate float64 `json:"amp_render_rate"`
	// AMP engagement metrics
	AMPOpened     int64   `json:"amp_opened"`
	AMPClicked    int64   `json:"amp_clicked"`
	AMPLinkedBounces int64 `json:"amp_linked_bounces"` // AMP emails with bounces matched to tracked emails
	AMPReplied    int64   `json:"amp_replied"`
	AMPOpenRate   float64 `json:"amp_open_rate"`
	AMPClickRate  float64 `json:"amp_click_rate"`
	AMPBounceRate float64 `json:"amp_bounce_rate"`
	AMPReplyRate  float64 `json:"amp_reply_rate"`
	// Reply breakdown by method for AMP-enabled emails
	AMPRepliedViaAMP   int64   `json:"amp_replied_via_amp"`   // Replies via AMP form
	AMPRepliedViaEmail int64   `json:"amp_replied_via_email"` // Replies via email
	AMPReplyViaAMPRate float64 `json:"amp_reply_via_amp_rate"`
	AMPReplyViaEmailRate float64 `json:"amp_reply_via_email_rate"`
	// Click breakdown: reply clicks (to message/chat pages) vs other clicks
	AMPReplyClicks    int64   `json:"amp_reply_clicks"`     // Clicks to reply on web
	AMPOtherClicks    int64   `json:"amp_other_clicks"`     // Other clicks (view item, etc.)
	AMPReplyClickRate float64 `json:"amp_reply_click_rate"` // Reply click rate
	AMPOtherClickRate float64 `json:"amp_other_click_rate"` // Other click rate
	// Response rate: all ways of responding (AMP reply + email reply + click to reply on web)
	AMPResponseRate float64 `json:"amp_response_rate"`
	// Legacy action rate (for backwards compatibility)
	AMPActionRate float64 `json:"amp_action_rate"`
	// Non-AMP engagement metrics (for comparison)
	NonAMPOpened      int64   `json:"non_amp_opened"`
	NonAMPClicked     int64   `json:"non_amp_clicked"`
	NonAMPLinkedBounces int64 `json:"non_amp_linked_bounces"` // Non-AMP emails with bounces matched to tracked emails
	NonAMPReplied     int64   `json:"non_amp_replied"`
	NonAMPOpenRate    float64 `json:"non_amp_open_rate"`
	NonAMPClickRate   float64 `json:"non_amp_click_rate"`
	NonAMPBounceRate  float64 `json:"non_amp_bounce_rate"`
	NonAMPReplyRate   float64 `json:"non_amp_reply_rate"`
	// Click breakdown for non-AMP
	NonAMPReplyClicks    int64   `json:"non_amp_reply_clicks"`
	NonAMPOtherClicks    int64   `json:"non_amp_other_clicks"`
	NonAMPReplyClickRate float64 `json:"non_amp_reply_click_rate"`
	NonAMPOtherClickRate float64 `json:"non_amp_other_click_rate"`
	// Response rate: email reply + click to reply on web
	NonAMPResponseRate float64 `json:"non_amp_response_rate"`
	// Legacy action rate (for backwards compatibility)
	NonAMPActionRate float64 `json:"non_amp_action_rate"`
}

// Transparent 1x1 GIF
var transparentGIF = []byte{
	0x47, 0x49, 0x46, 0x38, 0x39, 0x61, 0x01, 0x00, 0x01, 0x00,
	0x80, 0x00, 0x00, 0x00, 0x00, 0x00, 0xff, 0xff, 0xff, 0x21,
	0xf9, 0x04, 0x01, 0x00, 0x00, 0x00, 0x00, 0x2c, 0x00, 0x00,
	0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00, 0x02, 0x01, 0x44,
	0x00, 0x3b,
}

// generateTrackingID creates a random tracking ID
func generateTrackingID() string {
	b := make([]byte, 16)
	rand.Read(b)
	return hex.EncodeToString(b)
}

// Pixel serves a tracking pixel and records an email open
// @Router /e/d/p/{id} [get]
// @Summary Delivery pixel
// @Description Returns a 1x1 transparent GIF
// @Tags delivery
// @Produce image/gif
// @Param id path string true "ID"
// @Success 200 {file} file
func Pixel(c *fiber.Ctx) error {
	trackingID := c.Params("id")

	recordOpen(trackingID, "pixel")

	c.Set("Content-Type", "image/gif")
	c.Set("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0")
	c.Set("Pragma", "no-cache")
	c.Set("Expires", "Thu, 01 Jan 1970 00:00:00 GMT")

	return c.Send(transparentGIF)
}

// Click tracks a link click and redirects to the original URL
// Also handles button actions like unsubscribe via the 'a' (action) parameter
// @Router /e/d/r/{id} [get]
// @Summary Delivery redirect
// @Description Redirects to the destination URL
// @Tags delivery
// @Param id path string true "ID"
// @Param url query string true "Base64 encoded destination URL"
// @Param p query string false "Position identifier"
// @Param a query string false "Action type (e.g., unsubscribe, cta)"
// @Success 302 {string} string "Redirect"
func Click(c *fiber.Ctx) error {
	db := database.DBConn
	trackingID := c.Params("id")

	// Decode the URL
	urlEncoded := c.Query("url", "")
	urlBytes, err := base64.StdEncoding.DecodeString(urlEncoded)
	if err != nil {
		return c.Redirect("/")
	}
	destinationURL := RepairDoubledSiteURL(string(urlBytes))

	// Validate URL
	if destinationURL == "" || !isValidRedirectURL(destinationURL) {
		return c.Redirect("/")
	}

	// Get tracking record
	var tracking EmailTracking
	result := db.Where("tracking_id = ?", trackingID).First(&tracking)
	if result.Error != nil {
		return c.Redirect(destinationURL)
	}

	position := c.Query("p", "")
	action := c.Query("a", "")
	ipAddress := c.IP()
	userAgent := c.Get("User-Agent")

	// Record the click
	now := time.Now()

	// If not opened yet, mark as opened via click
	if tracking.OpenedAt == nil {
		openedVia := "click"
		db.Model(&tracking).Updates(map[string]interface{}{
			"opened_at":  now,
			"opened_via": openedVia,
		})
	}

	// Handle special actions
	if action == "unsubscribe" {
		db.Model(&tracking).Update("unsubscribed_at", now)
	}

	// Update first click info
	if tracking.ClickedAt == nil {
		db.Model(&tracking).Updates(map[string]interface{}{
			"clicked_at":   now,
			"clicked_link": destinationURL,
		})
	}

	// Increment click count
	db.Model(&tracking).UpdateColumn("links_clicked", tracking.LinksClicked+1)

	// Create click record
	click := EmailTrackingClick{
		EmailTrackingID: tracking.ID,
		LinkURL:         destinationURL,
		LinkPosition:    &position,
		Action:          &action,
		IPAddress:       &ipAddress,
		UserAgent:       &userAgent,
		ClickedAt:       now,
	}
	db.Create(&click)

	return c.Redirect(destinationURL)
}

// Image tracks an image load for scroll depth estimation
// @Router /e/d/i/{id} [get]
// @Summary Delivery image
// @Description Redirects to the original image
// @Tags delivery
// @Param id path string true "ID"
// @Param url query string true "Base64 encoded original image URL"
// @Param p query string true "Position identifier"
// @Param s query integer false "Scroll percentage"
// @Success 302 {string} string "Redirect to original image"
func Image(c *fiber.Ctx) error {
	db := database.DBConn
	trackingID := c.Params("id")

	// Get tracking record
	var tracking EmailTracking
	result := db.Where("tracking_id = ?", trackingID).First(&tracking)

	if result.Error == nil {
		position := c.Query("p", "unknown")
		scrollPercent := c.QueryInt("s", -1)

		now := time.Now()

		// If not opened yet, mark as opened via image. Guard the write in SQL,
		// not just on the stale Go read: an email's images load near-together and
		// all see OpenedAt==nil, so without "WHERE opened_at IS NULL" every one of
		// them UPDATEs the same parent row and they serialise on its lock.
		if tracking.OpenedAt == nil {
			openedVia := "image"
			db.Model(&tracking).Where("opened_at IS NULL").Updates(map[string]interface{}{
				"opened_at":  now,
				"opened_via": openedVia,
			})
		}

		// Create image load record. This per-load row (with its position and
		// estimated scroll percent) is the source of truth for image/scroll-depth
		// analytics.
		imageLoad := EmailTrackingImage{
			EmailTrackingID: tracking.ID,
			ImagePosition:   position,
			LoadedAt:        now,
		}

		if scrollPercent >= 0 && scrollPercent <= 100 {
			sp := uint8(scrollPercent)
			imageLoad.EstimatedScrollPercent = &sp

			// Update scroll depth if this is deeper.
			if tracking.ScrollDepthPercent == nil || sp > *tracking.ScrollDepthPercent {
				db.Model(&tracking).Update("scroll_depth_percent", sp)
			}
		}

		db.Create(&imageLoad)

		// Deliberately do NOT increment email_tracking.images_loaded. A per-hit
		// counter UPDATE takes an exclusive lock on the parent row that every
		// concurrent image load contends for (see ImageCompact) - and the old
		// read-modify-write form here also lost updates. The count is unread and
		// derivable as a COUNT over email_tracking_images, so it is not kept.
	}

	// Redirect to original image
	urlEncoded := c.Query("url", "")
	urlBytes, err := base64.StdEncoding.DecodeString(urlEncoded)
	if err != nil || len(urlBytes) == 0 {
		// Return transparent GIF as fallback
		c.Set("Content-Type", "image/gif")
		return c.Send(transparentGIF)
	}

	return c.Redirect(string(urlBytes))
}

// Note: MDN read receipts are processed by the incoming mail handler
// which updates the database directly. No HTTP endpoint needed here.

// Future enhancement: scroll depth analytics endpoints.
// Data is collected via email_tracking.scroll_depth_percent and
// email_tracking_images table but not yet exposed in the stats API.

// Stats returns email statistics (requires authentication)
// @Router /email/stats [get]
// @Summary Get email statistics
// @Description Returns aggregate email statistics for authorized users
// @Tags emailtracking
// @Produce json
// @Security BearerAuth
// @Param type query string false "Email type filter"
// @Param start query string false "Start date (YYYY-MM-DD)"
// @Param end query string false "End date (YYYY-MM-DD)"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Unauthorized"
func Stats(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user has support/admin role
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	emailType := c.Query("type", "")
	startDate := c.Query("start", "")
	endDate := c.Query("end", "")

	// Build query - exclude Trash Nothing users from stats
	query := db.Model(&EmailTracking{}).
		Joins("LEFT JOIN users ON email_tracking.userid = users.id").
		Where("users.tnuserid IS NULL")
	if emailType != "" {
		query = query.Where("email_type = ?", emailType)
	}
	if startDate != "" && endDate != "" {
		// If endDate doesn't include time, add end of day
		endDateTime := endDate
		if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
			endDateTime = endDate + " 23:59:59"
		}
		query = query.Where("sent_at BETWEEN ? AND ?", startDate, endDateTime)
	}

	// Get counts. Clone the base query before each .Where() to avoid accumulating conditions.
	var totalSent, opened, clicked, linkedBounces int64
	query.Session(&gorm.Session{}).Count(&totalSent)
	query.Session(&gorm.Session{}).Where("opened_at IS NOT NULL").Count(&opened)
	query.Session(&gorm.Session{}).Where("clicked_at IS NOT NULL").Count(&clicked)
	query.Session(&gorm.Session{}).Where("bounced_at IS NOT NULL").Count(&linkedBounces)

	// Calculate rates (bounce rate uses linked bounces for backwards compatibility)
	var openRate, clickRate, clickToOpenRate, bounceRate float64
	if totalSent > 0 {
		openRate = float64(opened) / float64(totalSent) * 100
		clickRate = float64(clicked) / float64(totalSent) * 100
		bounceRate = float64(linkedBounces) / float64(totalSent) * 100
	}
	if opened > 0 {
		clickToOpenRate = float64(clicked) / float64(opened) * 100
	}

	stats := EmailStats{
		TotalSent:       totalSent,
		Opened:          opened,
		Clicked:         clicked,
		LinkedBounces:   linkedBounces,
		OpenRate:        openRate,
		ClickRate:       clickRate,
		ClickToOpenRate: clickToOpenRate,
		BounceRate:      bounceRate,
	}

	// Get actual bounce counts from bounces_emails table
	bounceStats := getBouncesEmailsStats(db, startDate, endDate)
	stats.TotalBounces = bounceStats.Total
	stats.PermanentBounces = bounceStats.Permanent
	stats.TemporaryBounces = bounceStats.Temporary

	// Get AMP statistics
	ampStats := getAMPStats(db, emailType, startDate, endDate)

	return c.JSON(fiber.Map{
		"stats":     stats,
		"amp_stats": ampStats,
		"period": fiber.Map{
			"start": startDate,
			"end":   endDate,
			"type":  emailType,
		},
	})
}

// BouncesEmailsStats represents bounce statistics from the bounces_emails table
type BouncesEmailsStats struct {
	Total     int64
	Permanent int64
	Temporary int64
}

// getBouncesEmailsStats queries the bounces_emails table for actual bounce counts
func getBouncesEmailsStats(db *gorm.DB, startDate, endDate string) BouncesEmailsStats {
	var stats BouncesEmailsStats

	query := `
		SELECT
			COUNT(*) as total,
			SUM(CASE WHEN permanent = 1 THEN 1 ELSE 0 END) as permanent,
			SUM(CASE WHEN permanent = 0 THEN 1 ELSE 0 END) as temporary
		FROM bounces_emails
		WHERE reset = 0
	`

	var args []interface{}

	if startDate != "" && endDate != "" {
		// If endDate doesn't include time, add end of day
		endDateTime := endDate
		if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
			endDateTime = endDate + " 23:59:59"
		}
		query += " AND date BETWEEN ? AND ?"
		args = append(args, startDate, endDateTime)
	}

	db.Raw(query, args...).Scan(&stats)

	return stats
}

// getAMPStats calculates AMP-specific statistics for the given filters
func getAMPStats(db *gorm.DB, emailType, startDate, endDate string) AMPStats {
	var stats AMPStats

	// Build base query conditions - exclude Trash Nothing users from stats
	conditions := "1=1 AND users.tnuserid IS NULL"
	var args []interface{}

	if emailType != "" {
		conditions += " AND email_type = ?"
		args = append(args, emailType)
	}
	if startDate != "" && endDate != "" {
		// If endDate doesn't include time, add end of day
		endDateTime := endDate
		if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
			endDateTime = endDate + " 23:59:59"
		}
		conditions += " AND sent_at BETWEEN ? AND ?"
		args = append(args, startDate, endDateTime)
	}

	// All queries JOIN users to exclude Trash Nothing users
	tnJoin := "LEFT JOIN users ON email_tracking.userid = users.id"

	// Query for AMP emails
	ampConditions := conditions + " AND has_amp = 1"
	var ampCounts struct {
		Total           int64
		Opened          int64
		Clicked         int64
		LinkedBounces   int64
		Replied         int64
		RepliedViaAMP   int64
		RepliedViaEmail int64
		Rendered        int64
	}
	db.Raw(`
		SELECT
			COUNT(*) as total,
			SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
			SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked,
			SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces,
			SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied,
			SUM(CASE WHEN replied_via = 'amp' THEN 1 ELSE 0 END) as replied_via_amp,
			SUM(CASE WHEN replied_via = 'email' THEN 1 ELSE 0 END) as replied_via_email,
			SUM(CASE WHEN opened_via = 'amp' THEN 1 ELSE 0 END) as rendered
		FROM email_tracking
		`+tnJoin+`
		WHERE `+ampConditions, args...).Scan(&ampCounts)

	// Query for non-AMP emails
	nonAMPConditions := conditions + " AND has_amp = 0"
	var nonAMPCounts struct {
		Total         int64
		Opened        int64
		Clicked       int64
		LinkedBounces int64
		Replied       int64
	}
	db.Raw(`
		SELECT
			COUNT(*) as total,
			SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
			SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked,
			SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces,
			SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied
		FROM email_tracking
		`+tnJoin+`
		WHERE `+nonAMPConditions, args...).Scan(&nonAMPCounts)

	// Populate stats
	stats.TotalWithAMP = ampCounts.Total
	stats.TotalWithoutAMP = nonAMPCounts.Total
	stats.AMPRendered = ampCounts.Rendered
	stats.AMPOpened = ampCounts.Opened
	stats.AMPClicked = ampCounts.Clicked
	stats.AMPLinkedBounces = ampCounts.LinkedBounces
	stats.AMPReplied = ampCounts.Replied
	stats.AMPRepliedViaAMP = ampCounts.RepliedViaAMP
	stats.AMPRepliedViaEmail = ampCounts.RepliedViaEmail
	stats.NonAMPOpened = nonAMPCounts.Opened
	stats.NonAMPClicked = nonAMPCounts.Clicked
	stats.NonAMPLinkedBounces = nonAMPCounts.LinkedBounces
	stats.NonAMPReplied = nonAMPCounts.Replied

	// Query for click breakdown (reply clicks vs other clicks)
	// Reply clicks are clicks to message/chat pages where users can reply
	// Exclude Trash Nothing users via JOIN on users table
	clickConditions := strings.Replace(conditions, "sent_at", "e.sent_at", -1)
	clickConditions = strings.Replace(clickConditions, "users.tnuserid", "u.tnuserid", -1)
	clickTNJoin := "LEFT JOIN users u ON e.userid = u.id"

	var ampClickBreakdown struct {
		ReplyClicks int64
		OtherClicks int64
	}
	db.Raw(`
		SELECT
			COUNT(DISTINCT CASE WHEN c.link_url LIKE '%/message/%' OR c.link_url LIKE '%/chat/%' OR c.link_url LIKE '%/chats/%' THEN c.email_tracking_id END) as reply_clicks,
			COUNT(DISTINCT CASE WHEN c.link_url NOT LIKE '%/message/%' AND c.link_url NOT LIKE '%/chat/%' AND c.link_url NOT LIKE '%/chats/%' AND c.link_url NOT LIKE 'amp://%' THEN c.email_tracking_id END) as other_clicks
		FROM email_tracking_clicks c
		JOIN email_tracking e ON c.email_tracking_id = e.id
		`+clickTNJoin+`
		WHERE e.has_amp = 1 AND `+clickConditions, args...).Scan(&ampClickBreakdown)

	var nonAMPClickBreakdown struct {
		ReplyClicks int64
		OtherClicks int64
	}
	db.Raw(`
		SELECT
			COUNT(DISTINCT CASE WHEN c.link_url LIKE '%/message/%' OR c.link_url LIKE '%/chat/%' OR c.link_url LIKE '%/chats/%' THEN c.email_tracking_id END) as reply_clicks,
			COUNT(DISTINCT CASE WHEN c.link_url NOT LIKE '%/message/%' AND c.link_url NOT LIKE '%/chat/%' AND c.link_url NOT LIKE '%/chats/%' THEN c.email_tracking_id END) as other_clicks
		FROM email_tracking_clicks c
		JOIN email_tracking e ON c.email_tracking_id = e.id
		`+clickTNJoin+`
		WHERE e.has_amp = 0 AND `+clickConditions, args...).Scan(&nonAMPClickBreakdown)

	stats.AMPReplyClicks = ampClickBreakdown.ReplyClicks
	stats.AMPOtherClicks = ampClickBreakdown.OtherClicks
	stats.NonAMPReplyClicks = nonAMPClickBreakdown.ReplyClicks
	stats.NonAMPOtherClicks = nonAMPClickBreakdown.OtherClicks

	// Calculate percentages
	totalEmails := stats.TotalWithAMP + stats.TotalWithoutAMP
	if totalEmails > 0 {
		stats.AMPPercentage = float64(stats.TotalWithAMP) / float64(totalEmails) * 100
	}

	// AMP render rate: of AMP emails sent, how many were actually rendered with AMP
	if stats.TotalWithAMP > 0 {
		stats.AMPRenderRate = float64(stats.AMPRendered) / float64(stats.TotalWithAMP) * 100
	}

	// AMP rates
	if stats.TotalWithAMP > 0 {
		stats.AMPOpenRate = float64(stats.AMPOpened) / float64(stats.TotalWithAMP) * 100
		stats.AMPClickRate = float64(stats.AMPClicked) / float64(stats.TotalWithAMP) * 100
		stats.AMPBounceRate = float64(stats.AMPLinkedBounces) / float64(stats.TotalWithAMP) * 100
		stats.AMPReplyRate = float64(stats.AMPReplied) / float64(stats.TotalWithAMP) * 100
		// Reply breakdown by method
		stats.AMPReplyViaAMPRate = float64(stats.AMPRepliedViaAMP) / float64(stats.TotalWithAMP) * 100
		stats.AMPReplyViaEmailRate = float64(stats.AMPRepliedViaEmail) / float64(stats.TotalWithAMP) * 100
		// Click breakdown
		stats.AMPReplyClickRate = float64(stats.AMPReplyClicks) / float64(stats.TotalWithAMP) * 100
		stats.AMPOtherClickRate = float64(stats.AMPOtherClicks) / float64(stats.TotalWithAMP) * 100
		// Response rate: all ways of responding (AMP replies + email replies + clicks to reply on web)
		stats.AMPResponseRate = float64(stats.AMPReplied+stats.AMPReplyClicks) / float64(stats.TotalWithAMP) * 100
		// Legacy action rate (for backwards compatibility)
		stats.AMPActionRate = float64(stats.AMPClicked+stats.AMPReplied) / float64(stats.TotalWithAMP) * 100
	}

	// Non-AMP rates
	if stats.TotalWithoutAMP > 0 {
		stats.NonAMPOpenRate = float64(stats.NonAMPOpened) / float64(stats.TotalWithoutAMP) * 100
		stats.NonAMPClickRate = float64(stats.NonAMPClicked) / float64(stats.TotalWithoutAMP) * 100
		stats.NonAMPBounceRate = float64(stats.NonAMPLinkedBounces) / float64(stats.TotalWithoutAMP) * 100
		stats.NonAMPReplyRate = float64(stats.NonAMPReplied) / float64(stats.TotalWithoutAMP) * 100
		// Click breakdown
		stats.NonAMPReplyClickRate = float64(stats.NonAMPReplyClicks) / float64(stats.TotalWithoutAMP) * 100
		stats.NonAMPOtherClickRate = float64(stats.NonAMPOtherClicks) / float64(stats.TotalWithoutAMP) * 100
		// Response rate: email replies + clicks to reply on web
		stats.NonAMPResponseRate = float64(stats.NonAMPReplied+stats.NonAMPReplyClicks) / float64(stats.TotalWithoutAMP) * 100
		// Legacy action rate (for backwards compatibility)
		stats.NonAMPActionRate = float64(stats.NonAMPClicked+stats.NonAMPReplied) / float64(stats.TotalWithoutAMP) * 100
	}

	return stats
}

// UserEmailTrackingResponse represents email tracking data for a user
type UserEmailTrackingResponse struct {
	ID            uint64     `json:"id"`
	EmailType     string     `json:"email_type"`
	Subject       *string    `json:"subject"`
	SentAt        *time.Time `json:"sent_at"`
	OpenedAt      *time.Time `json:"opened_at"`
	OpenedVia     *string    `json:"opened_via"`
	ClickedAt     *time.Time `json:"clicked_at"`
	ClickedLink   *string    `json:"clicked_link"`
	LinksClicked  uint16     `json:"links_clicked"`
	BouncedAt     *time.Time `json:"bounced_at"`
	UnsubscribedAt *time.Time `json:"unsubscribed_at"`
	CreatedAt     time.Time  `json:"created_at"`
}

// UserEmails returns email tracking for a specific user (requires authentication)
// @Router /email/user/{id} [get]
// @Summary Get email tracking for a user
// @Description Returns email tracking records for a specific user (Support/Admin only). Can specify user by ID in path or email in query. When searching by email, also searches recipient_email in tracking records.
// @Tags emailtracking
// @Produce json
// @Security BearerAuth
// @Param id path int true "User ID (use 0 if searching by email)"
// @Param email query string false "User email address (alternative to ID)"
// @Param limit query int false "Number of records (default 50)"
// @Param offset query int false "Offset for pagination"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Unauthorized"
// @Failure 403 {object} fiber.Error "Forbidden"
func UserEmails(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user has support/admin role
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	// Get target user ID from path or resolve from email
	targetUserID, _ := c.ParamsInt("id")
	email := c.Query("email", "")
	searchByRecipientEmail := false

	// If no valid ID but email provided, look up user by email
	if targetUserID <= 0 && email != "" {
		var userLookup struct {
			UserID uint64 `gorm:"column:userid"`
		}
		// First try users_emails table (for users with multiple emails)
		// ORM migration site 5335567292aa (wave 1).
		result := db.Table("users_emails").Select("userid").Where("email = ? AND backwards IS NULL", email).Limit(1).Scan(&userLookup)
		if result.Error != nil || userLookup.UserID == 0 {
			// Fallback to users table (for new users whose email is only in users.email)
			var userFallback struct {
				ID uint64 `gorm:"column:id"`
			}
			// ORM migration site 39805074ce3d (wave 1).
			result = db.Table("users").Select("id").Where("email = ?", email).Limit(1).Scan(&userFallback)
			if result.Error != nil || userFallback.ID == 0 {
				// No user found - search by recipient_email in email_tracking table directly
				searchByRecipientEmail = true
			} else {
				userLookup.UserID = userFallback.ID
			}
		}
		if !searchByRecipientEmail {
			targetUserID = int(userLookup.UserID)
		}
	}

	if targetUserID <= 0 && !searchByRecipientEmail {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid user ID or email")
	}

	limit := c.QueryInt("limit", 50)
	offset := c.QueryInt("offset", 0)

	// Cap limit at 100
	if limit > 100 {
		limit = 100
	}

	var emails []EmailTracking
	var total int64

	if searchByRecipientEmail {
		// Search by recipient_email directly in email_tracking table
		result := db.Where("recipient_email = ?", email).
			Order("created_at DESC").
			Limit(limit).
			Offset(offset).
			Find(&emails)

		if result.Error != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Database error")
		}

		// Get total count
		db.Model(&EmailTracking{}).Where("recipient_email = ?", email).Count(&total)
	} else {
		// Search by user ID
		result := db.Where("userid = ?", targetUserID).
			Order("created_at DESC").
			Limit(limit).
			Offset(offset).
			Find(&emails)

		if result.Error != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Database error")
		}

		// Get total count
		db.Model(&EmailTracking{}).Where("userid = ?", targetUserID).Count(&total)
	}

	// Convert to response format
	response := make([]UserEmailTrackingResponse, len(emails))
	for i, e := range emails {
		response[i] = UserEmailTrackingResponse{
			ID:             e.ID,
			EmailType:      e.EmailType,
			Subject:        e.Subject,
			SentAt:         e.SentAt,
			OpenedAt:       e.OpenedAt,
			OpenedVia:      e.OpenedVia,
			ClickedAt:      e.ClickedAt,
			ClickedLink:    e.ClickedLink,
			LinksClicked:   e.LinksClicked,
			BouncedAt:      e.BouncedAt,
			UnsubscribedAt: e.UnsubscribedAt,
			CreatedAt:      e.CreatedAt,
		}
	}

	// Build response - include email when searching by recipient_email
	responseMap := fiber.Map{
		"emails": response,
		"total":  total,
		"limit":  limit,
		"offset": offset,
	}

	if searchByRecipientEmail {
		responseMap["email"] = email
	} else {
		responseMap["userid"] = targetUserID
	}

	return c.JSON(responseMap)
}

// recordOpen records an email open event
func recordOpen(trackingID string, via string) {
	db := database.DBConn

	var tracking EmailTracking
	result := db.Where("tracking_id = ?", trackingID).First(&tracking)
	if result.Error != nil {
		return
	}

	// Only record first open
	if tracking.OpenedAt != nil {
		return
	}

	now := time.Now()
	db.Model(&tracking).Updates(map[string]interface{}{
		"opened_at":  now,
		"opened_via": via,
	})
}

// DailyStats represents statistics for a single day
type DailyStats struct {
	Date          string `json:"date"`
	Sent          int64  `json:"sent"`
	Opened        int64  `json:"opened"`
	Clicked       int64  `json:"clicked"`
	LinkedBounces int64  `json:"linked_bounces"` // Bounces matched to specific tracked emails via bounced_at
	// Actual bounces from bounces_emails table (all incoming bounce notifications)
	TotalBounces     int64 `json:"total_bounces"`
	PermanentBounces int64 `json:"permanent_bounces"`
	TemporaryBounces int64 `json:"temporary_bounces"`
	// AMP-specific metrics
	AMPSent          int64 `json:"amp_sent"`
	AMPOpened        int64 `json:"amp_opened"`
	AMPClicked       int64 `json:"amp_clicked"`
	AMPLinkedBounces int64 `json:"amp_linked_bounces"`
	AMPReplied       int64 `json:"amp_replied"`
	NonAMPSent          int64 `json:"non_amp_sent"`
	NonAMPOpened        int64 `json:"non_amp_opened"`
	NonAMPClicked       int64 `json:"non_amp_clicked"`
	NonAMPLinkedBounces int64 `json:"non_amp_linked_bounces"`
}

// EmailTypeStats represents statistics for a specific email type
type EmailTypeStats struct {
	EmailType       string  `json:"email_type"`
	TotalSent       int64   `json:"total_sent"`
	Opened          int64   `json:"opened"`
	Clicked         int64   `json:"clicked"`
	LinkedBounces   int64   `json:"linked_bounces"` // Bounces matched to specific tracked emails
	OpenRate        float64 `json:"open_rate"`
	ClickRate       float64 `json:"click_rate"`
	ClickToOpenRate float64 `json:"click_to_open_rate"`
	BounceRate      float64 `json:"bounce_rate"`
}

// TimeSeries returns daily email statistics for charting (requires authentication)
// @Router /email/stats/timeseries [get]
// @Summary Get daily email statistics for charting
// @Description Returns daily email statistics including sent/opened/clicked counts, linked_bounces (matched to tracked emails), and total_bounces/permanent_bounces/temporary_bounces (all incoming bounce notifications)
// @Tags emailtracking
// @Produce json
// @Security BearerAuth
// @Param type query string false "Email type filter"
// @Param start query string false "Start date (YYYY-MM-DD)"
// @Param end query string false "End date (YYYY-MM-DD)"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Unauthorized"
func TimeSeries(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user has support/admin role
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	emailType := c.Query("type", "")
	startDate := c.Query("start", "")
	endDate := c.Query("end", "")

	// Default to last 30 days if no dates provided
	if startDate == "" || endDate == "" {
		now := time.Now()
		endDate = now.Format("2006-01-02")
		startDate = now.AddDate(0, 0, -30).Format("2006-01-02")
	}

	// Build query for daily stats including AMP breakdown
	// Exclude Trash Nothing users from stats
	query := `
		SELECT
			DATE(sent_at) as date,
			COUNT(*) as sent,
			SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
			SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked,
			SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces,
			SUM(CASE WHEN has_amp = 1 THEN 1 ELSE 0 END) as amp_sent,
			SUM(CASE WHEN has_amp = 1 AND opened_at IS NOT NULL THEN 1 ELSE 0 END) as amp_opened,
			SUM(CASE WHEN has_amp = 1 AND clicked_at IS NOT NULL THEN 1 ELSE 0 END) as amp_clicked,
			SUM(CASE WHEN has_amp = 1 AND bounced_at IS NOT NULL THEN 1 ELSE 0 END) as amp_linked_bounces,
			SUM(CASE WHEN has_amp = 1 AND replied_at IS NOT NULL THEN 1 ELSE 0 END) as amp_replied,
			SUM(CASE WHEN has_amp = 0 THEN 1 ELSE 0 END) as non_amp_sent,
			SUM(CASE WHEN has_amp = 0 AND opened_at IS NOT NULL THEN 1 ELSE 0 END) as non_amp_opened,
			SUM(CASE WHEN has_amp = 0 AND clicked_at IS NOT NULL THEN 1 ELSE 0 END) as non_amp_clicked,
			SUM(CASE WHEN has_amp = 0 AND bounced_at IS NOT NULL THEN 1 ELSE 0 END) as non_amp_linked_bounces
		FROM email_tracking FORCE INDEX (sent_at)
		LEFT JOIN users ON email_tracking.userid = users.id
		WHERE users.tnuserid IS NULL AND sent_at BETWEEN ? AND ?
	`

	// If endDate doesn't include time, add end of day
	endDateTime := endDate
	if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
		endDateTime = endDate + " 23:59:59"
	}
	args := []interface{}{startDate, endDateTime}

	if emailType != "" {
		query += " AND email_type = ?"
		args = append(args, emailType)
	}

	query += " GROUP BY DATE(sent_at) ORDER BY date ASC"

	var dailyStats []DailyStats
	db.Raw(query, args...).Scan(&dailyStats)

	// Get daily bounce counts from bounces_emails table
	bounceQuery := `
		SELECT
			DATE(date) as date,
			COUNT(*) as total_bounces,
			SUM(CASE WHEN permanent = 1 THEN 1 ELSE 0 END) as permanent_bounces,
			SUM(CASE WHEN permanent = 0 THEN 1 ELSE 0 END) as temporary_bounces
		FROM bounces_emails
		WHERE reset = 0 AND date BETWEEN ? AND ?
		GROUP BY DATE(date)
	`
	var dailyBounces []struct {
		Date             string `gorm:"column:date"`
		TotalBounces     int64  `gorm:"column:total_bounces"`
		PermanentBounces int64  `gorm:"column:permanent_bounces"`
		TemporaryBounces int64  `gorm:"column:temporary_bounces"`
	}
	db.Raw(bounceQuery, startDate, endDateTime).Scan(&dailyBounces)

	// Create a map for quick lookup
	bounceMap := make(map[string]struct {
		Total     int64
		Permanent int64
		Temporary int64
	})
	for _, b := range dailyBounces {
		bounceMap[b.Date] = struct {
			Total     int64
			Permanent int64
			Temporary int64
		}{b.TotalBounces, b.PermanentBounces, b.TemporaryBounces}
	}

	// Merge bounce data into daily stats
	existingDates := make(map[string]bool)
	for i := range dailyStats {
		existingDates[dailyStats[i].Date] = true
		if bounces, ok := bounceMap[dailyStats[i].Date]; ok {
			dailyStats[i].TotalBounces = bounces.Total
			dailyStats[i].PermanentBounces = bounces.Permanent
			dailyStats[i].TemporaryBounces = bounces.Temporary
		}
	}

	// Add entries for dates that have bounces but no email_tracking entries
	for _, b := range dailyBounces {
		if !existingDates[b.Date] {
			dailyStats = append(dailyStats, DailyStats{
				Date:             b.Date,
				TotalBounces:     b.TotalBounces,
				PermanentBounces: b.PermanentBounces,
				TemporaryBounces: b.TemporaryBounces,
			})
		}
	}

	// Sort by date to ensure chronological order
	sort.Slice(dailyStats, func(i, j int) bool {
		return dailyStats[i].Date < dailyStats[j].Date
	})

	return c.JSON(fiber.Map{
		"data": dailyStats,
		"period": fiber.Map{
			"start": startDate,
			"end":   endDate,
			"type":  emailType,
		},
	})
}

// StatsByType returns email statistics broken down by email type (requires authentication)
// @Router /email/stats/bytype [get]
// @Summary Get email statistics by email type
// @Description Returns statistics for each email type for comparison charts
// @Tags emailtracking
// @Produce json
// @Security BearerAuth
// @Param start query string false "Start date (YYYY-MM-DD)"
// @Param end query string false "End date (YYYY-MM-DD)"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Unauthorized"
func StatsByType(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user has support/admin role
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	startDate := c.Query("start", "")
	endDate := c.Query("end", "")

	// Build query for stats by type - exclude Trash Nothing users
	query := `
		SELECT
			email_type,
			COUNT(*) as total_sent,
			SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
			SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked,
			SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as linked_bounces
		FROM email_tracking FORCE INDEX (sent_at)
		LEFT JOIN users ON email_tracking.userid = users.id
		WHERE users.tnuserid IS NULL
	`

	var args []interface{}

	if startDate != "" && endDate != "" {
		// If endDate doesn't include time, add end of day
		endDateTime := endDate
		if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
			endDateTime = endDate + " 23:59:59"
		}
		query += " AND sent_at BETWEEN ? AND ?"
		args = append(args, startDate, endDateTime)
	}

	query += " GROUP BY email_type ORDER BY total_sent DESC"

	var rawStats []struct {
		EmailType     string `gorm:"column:email_type"`
		TotalSent     int64  `gorm:"column:total_sent"`
		Opened        int64  `gorm:"column:opened"`
		Clicked       int64  `gorm:"column:clicked"`
		LinkedBounces int64  `gorm:"column:linked_bounces"`
	}
	db.Raw(query, args...).Scan(&rawStats)

	// Calculate rates
	stats := make([]EmailTypeStats, len(rawStats))
	for i, r := range rawStats {
		stats[i] = EmailTypeStats{
			EmailType:     r.EmailType,
			TotalSent:     r.TotalSent,
			Opened:        r.Opened,
			Clicked:       r.Clicked,
			LinkedBounces: r.LinkedBounces,
		}
		if r.TotalSent > 0 {
			stats[i].OpenRate = float64(r.Opened) / float64(r.TotalSent) * 100
			stats[i].ClickRate = float64(r.Clicked) / float64(r.TotalSent) * 100
			stats[i].BounceRate = float64(r.LinkedBounces) / float64(r.TotalSent) * 100
		}
		if r.Opened > 0 {
			stats[i].ClickToOpenRate = float64(r.Clicked) / float64(r.Opened) * 100
		}
	}

	return c.JSON(fiber.Map{
		"data": stats,
		"period": fiber.Map{
			"start": startDate,
			"end":   endDate,
		},
	})
}

// ClickedLinkStats represents a clicked link with count
type ClickedLinkStats struct {
	NormalizedURL string   `json:"normalized_url,omitempty"`
	URL           string   `json:"url,omitempty"`
	ClickCount    int64    `json:"click_count"`
	ExampleURLs   []string `json:"example_urls,omitempty"`
}

// normalizeURL removes user-specific data from URLs for aggregation
func normalizeURL(url string) string {
	// Parse the URL
	if url == "" {
		return ""
	}

	// Remove common tracking/user-specific query parameters
	// Keep the path but normalize numeric IDs
	result := url

	// Find query string start
	queryIdx := strings.Index(result, "?")
	path := result
	if queryIdx != -1 {
		path = result[:queryIdx]
	}

	// Normalize numeric IDs in the path (e.g., /message/12345 -> /message/{id})
	// Common patterns: /message/123, /user/123, /chat/123, /group/123
	pathParts := strings.Split(path, "/")
	for i, part := range pathParts {
		// Check if this part is purely numeric
		if len(part) > 0 && isNumeric(part) {
			pathParts[i] = "{id}"
		}
	}

	return strings.Join(pathParts, "/")
}

// isNumeric checks if a string contains only digits
func isNumeric(s string) bool {
	for _, c := range s {
		if c < '0' || c > '9' {
			return false
		}
	}
	return true
}

// TopClickedLinks returns the most clicked links (requires authentication)
// @Router /email/stats/clicks [get]
// @Summary Get top clicked links
// @Description Returns the most clicked links from emails, optionally normalized to remove user-specific data
// @Tags emailtracking
// @Produce json
// @Security BearerAuth
// @Param start query string false "Start date (YYYY-MM-DD)"
// @Param end query string false "End date (YYYY-MM-DD)"
// @Param limit query int false "Number of links to return (default 5, use 0 for all)"
// @Param aggregate query bool false "Whether to aggregate similar URLs by normalizing IDs (default true)"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Unauthorized"
func TopClickedLinks(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user has support/admin role
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	startDate := c.Query("start", "")
	endDate := c.Query("end", "")
	limit := c.QueryInt("limit", 5)
	// Default to aggregated (true) unless explicitly set to false
	aggregate := c.Query("aggregate", "true") != "false"

	// Get all clicked links within the date range
	query := `
		SELECT c.link_url, COUNT(*) as click_count
		FROM email_tracking_clicks c
		JOIN email_tracking e ON c.email_tracking_id = e.id
		WHERE 1=1
	`

	var args []interface{}

	if startDate != "" && endDate != "" {
		// If endDate doesn't include time, add end of day
		endDateTime := endDate
		if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
			endDateTime = endDate + " 23:59:59"
		}
		query += " AND c.clicked_at BETWEEN ? AND ?"
		args = append(args, startDate, endDateTime)
	}

	query += " GROUP BY c.link_url ORDER BY click_count DESC"

	var rawClicks []struct {
		LinkURL    string `gorm:"column:link_url"`
		ClickCount int64  `gorm:"column:click_count"`
	}
	db.Raw(query, args...).Scan(&rawClicks)

	var results []ClickedLinkStats

	if aggregate {
		// Aggregate by normalized URL
		normalizedMap := make(map[string]*ClickedLinkStats)
		for _, click := range rawClicks {
			normalized := normalizeURL(click.LinkURL)
			if normalized == "" {
				continue
			}

			if existing, ok := normalizedMap[normalized]; ok {
				existing.ClickCount += click.ClickCount
				// Keep up to 3 example URLs
				if len(existing.ExampleURLs) < 3 && !containsString(existing.ExampleURLs, click.LinkURL) {
					existing.ExampleURLs = append(existing.ExampleURLs, click.LinkURL)
				}
			} else {
				normalizedMap[normalized] = &ClickedLinkStats{
					NormalizedURL: normalized,
					ClickCount:    click.ClickCount,
					ExampleURLs:   []string{click.LinkURL},
				}
			}
		}

		// Convert map to slice
		results = make([]ClickedLinkStats, 0, len(normalizedMap))
		for _, stats := range normalizedMap {
			results = append(results, *stats)
		}

		// Sort by click count descending
		for i := 0; i < len(results); i++ {
			for j := i + 1; j < len(results); j++ {
				if results[j].ClickCount > results[i].ClickCount {
					results[i], results[j] = results[j], results[i]
				}
			}
		}
	} else {
		// Return raw URLs without aggregation
		results = make([]ClickedLinkStats, 0, len(rawClicks))
		for _, click := range rawClicks {
			if click.LinkURL == "" {
				continue
			}
			results = append(results, ClickedLinkStats{
				URL:        click.LinkURL,
				ClickCount: click.ClickCount,
			})
		}
	}

	// Apply limit (0 means all)
	totalCount := len(results)
	if limit > 0 && limit < len(results) {
		results = results[:limit]
	}

	return c.JSON(fiber.Map{
		"data":      results,
		"total":     totalCount,
		"aggregate": aggregate,
		"period": fiber.Map{
			"start": startDate,
			"end":   endDate,
		},
	})
}

// containsString checks if a slice contains a string
func containsString(slice []string, s string) bool {
	for _, item := range slice {
		if item == s {
			return true
		}
	}
	return false
}

// RepairDoubledSiteURL repairs destinations baked into emails sent while
// ChaseUpMail unconditionally prefixed the user site onto notification URLs
// that were already absolute, producing e.g.
// https://www.ilovefreegle.orghttps://www.ilovefreegle.org/stories. The
// corruption is a bare scheme+host immediately followed by a second absolute
// URL; a legitimate URL embedded in a query string is always preceded by a
// path or query separator, so only strip when the prefix contains none.
func RepairDoubledSiteURL(u string) string {
	schemeEnd := strings.Index(u, "://")
	if schemeEnd < 0 {
		return u
	}
	rest := u[schemeEnd+3:]
	for _, scheme := range []string{"https://", "http://"} {
		if i := strings.Index(rest, scheme); i > 0 && !strings.ContainsAny(rest[:i], "/?#") {
			return rest[i:]
		}
	}
	return u
}

// isValidRedirectURL validates URL is safe for redirect
func isValidRedirectURL(url string) bool {
	if url == "" {
		return false
	}

	// Must start with http:// or https://
	if !strings.HasPrefix(url, "http://") && !strings.HasPrefix(url, "https://") {
		return false
	}

	// Build allowed domains from environment variables
	var allowedDomains []string

	if userSite := os.Getenv("USER_SITE"); userSite != "" {
		allowedDomains = append(allowedDomains, userSite)
	}
	if modSite := os.Getenv("MOD_SITE"); modSite != "" {
		allowedDomains = append(allowedDomains, modSite)
	}
	if imageDomain := os.Getenv("IMAGE_DOMAIN"); imageDomain != "" {
		allowedDomains = append(allowedDomains, imageDomain)
	}
	if archivedDomain := os.Getenv("IMAGE_ARCHIVED_DOMAIN"); archivedDomain != "" {
		allowedDomains = append(allowedDomains, archivedDomain)
	}
	if groupDomain := os.Getenv("GROUP_DOMAIN"); groupDomain != "" {
		allowedDomains = append(allowedDomains, groupDomain)
	}

	// Allow localhost for development
	allowedDomains = append(allowedDomains, "localhost")

	// Allow Google Maps for address sharing in emails
	allowedDomains = append(allowedDomains, "maps.google.com")

	// Allow delivery service for image optimization (tracked images redirect here)
	allowedDomains = append(allowedDomains, "delivery.ilovefreegle.org")

	// Allow modtools.org for moderator chat links
	allowedDomains = append(allowedDomains, "modtools.org")

	// Allow freegle.in for Freegle PayPal short links (e.g. donate CTA)
	allowedDomains = append(allowedDomains, "freegle.in")

	// Same-origin relative paths (e.g. "/mypost/123") are safe to redirect to.
	if strings.HasPrefix(url, "/") && !strings.HasPrefix(url, "//") {
		return true
	}

	// Match on the exact host, not a naive substring: strings.Contains(url, domain) would
	// accept "https://evil.com/modtools.org" and "https://modtools.org.evil.com" as valid.
	parsed, err := neturl.Parse(url)
	if err != nil {
		return false
	}
	// Only http(s) redirects (blocks javascript:, data:, etc.).
	if parsed.Scheme != "http" && parsed.Scheme != "https" {
		return false
	}
	host := strings.ToLower(parsed.Hostname())
	if host == "" {
		return false
	}
	for _, domain := range allowedDomains {
		d := strings.ToLower(domain)
		if host == d || strings.HasSuffix(host, "."+d) {
			return true
		}
	}

	// Reject URLs not matching our domains
	return false
}

// DigestPositionStat represents the click-through rate for a single post
// position within unified digest emails.
type DigestPositionStat struct {
	// Position is the zero-based ordinal of the post within the digest (0 = top).
	Position int `json:"position"`
	// Shown is the number of digest emails that displayed a post at this position.
	Shown int64 `json:"shown"`
	// EmailsClicked is the number of distinct digest emails with at least one
	// click at this position (the click-through numerator).
	EmailsClicked int64 `json:"emails_clicked"`
	// Clicks is the total number of clicks recorded at this position.
	Clicks int64 `json:"clicks"`
	// CTR is the click-through rate (EmailsClicked / Shown) as a percentage.
	CTR float64 `json:"ctr"`
}

// DigestClickPositions returns the click-through rate by post position within
// unified digest emails. This shows how a post's vertical position in the
// digest affects whether recipients click it.
//
// The metric is derived entirely from existing tracking data:
//   - The denominator ("shown") comes from email_tracking.metadata.post_msgids,
//     an ordered array of the msgids rendered in each digest. A digest with K
//     posts shows positions 0..K-1, so position N was shown by every digest with
//     more than N posts.
//   - The numerator comes from email_tracking_clicks.link_position labels of the
//     form "post_N", counting the distinct digest emails that registered a click
//     at each position.
//
// Both sides are restricted to the same cohort (digests sent in the period, with
// metadata, excluding Trash Nothing recipients) so the CTR never exceeds 100%.
//
// @Router /email/stats/digestpositions [get]
// @Summary Get digest click-through rate by post position
// @Description Returns click-through rate per post position within unified digests, for analysing how position affects engagement (Support/Admin only)
// @Tags emailtracking
// @Produce json
// @Security BearerAuth
// @Param start query string false "Start date (YYYY-MM-DD)"
// @Param end query string false "End date (YYYY-MM-DD)"
// @Param type query string false "Email type filter (default: all UnifiedDigest* types)"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Unauthorized"
// @Failure 403 {object} fiber.Error "Forbidden"
func DigestClickPositions(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user has support/admin role
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	startDate := c.Query("start", "")
	endDate := c.Query("end", "")

	// Default to the last 7 days when no range is supplied. email_tracking is
	// large (millions of rows/month), and these queries aggregate JSON metadata
	// per row, so a wide default window makes the chart hang. Callers can still
	// pass an explicit start/end for a longer range.
	if startDate == "" || endDate == "" {
		now := time.Now()
		endDate = now.Format("2006-01-02")
		startDate = now.AddDate(0, 0, -7).Format("2006-01-02")
	}

	// If endDate doesn't include a time component, extend it to end of day.
	endDateTime := endDate
	if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
		endDateTime = endDate + " 23:59:59"
	}

	// By default consider all unified digest types; allow an exact-type override.
	emailType := c.Query("type", "")
	typeClause := "e.email_type LIKE 'UnifiedDigest%'"
	var typeArgs []interface{}
	if emailType != "" {
		typeClause = "e.email_type = ?"
		typeArgs = append(typeArgs, emailType)
	}

	// Conditions shared by both queries to keep the cohort consistent.
	cohort := typeClause + `
		AND u.tnuserid IS NULL
		AND e.metadata IS NOT NULL
		AND JSON_LENGTH(e.metadata, '$.post_msgids') > 0
		AND e.sent_at BETWEEN ? AND ?`

	// 1. Denominator: distribution of digest sizes. A digest with `num_posts`
	//    posts displayed positions 0..num_posts-1.
	denomQuery := `
		SELECT JSON_LENGTH(e.metadata, '$.post_msgids') AS num_posts, COUNT(*) AS cnt
		-- Force the sent_at index: otherwise the optimiser full-scans the whole
		-- table (millions of rows + per-row JSON) instead of range-scanning the
		-- date window, which made the chart hang.
		FROM email_tracking e FORCE INDEX (sent_at)
		LEFT JOIN users u ON e.userid = u.id
		WHERE ` + cohort + `
		GROUP BY num_posts`
	denomArgs := append(append([]interface{}{}, typeArgs...), startDate, endDateTime)

	var sizeRows []struct {
		NumPosts int   `gorm:"column:num_posts"`
		Cnt      int64 `gorm:"column:cnt"`
	}
	db.Raw(denomQuery, denomArgs...).Scan(&sizeRows)

	maxPosts := 0
	for _, r := range sizeRows {
		if r.NumPosts > maxPosts {
			maxPosts = r.NumPosts
		}
	}

	// shown[n] = number of digests whose size was greater than n (i.e. displayed
	// a post at position n). sizeRows is small (one row per distinct digest size).
	shown := make([]int64, maxPosts)
	for _, r := range sizeRows {
		for n := 0; n < r.NumPosts && n < maxPosts; n++ {
			shown[n] += r.Cnt
		}
	}

	// 2. Numerator: clicks on a post CARD at position N, grouped by position label.
	//
	// Two label schemes coexist:
	//   - "post_N": the legacy (verbose) digest card link.
	//   - "pN":     the current compact card link (TrackableEmail compact form,
	//               UnifiedDigest emits "p{index}" for the per-post Reply CTA).
	// Both mean "clicked the card for the post shown at vertical position N", so
	// both must be counted - otherwise this stat only sees old emails and silently
	// under-reports (compact "pN" clicks dominate live traffic). We deliberately
	// exclude the summary-index links ("yN") and image links ("iN"): the summary
	// sits at the top of the email, so a "yN" click is not a signal about the
	// post's vertical position.
	clickQuery := `
		SELECT c.link_position AS link_position,
		       COUNT(DISTINCT c.email_tracking_id) AS emails_clicked,
		       COUNT(*) AS clicks
		FROM email_tracking_clicks c
		JOIN email_tracking e ON c.email_tracking_id = e.id
		LEFT JOIN users u ON e.userid = u.id
		WHERE ` + cohort + `
		  AND c.link_position REGEXP '^(post_[0-9]+|p[0-9]+)$'
		GROUP BY c.link_position`
	clickArgs := append(append([]interface{}{}, typeArgs...), startDate, endDateTime)

	var clickRows []struct {
		LinkPosition  string `gorm:"column:link_position"`
		EmailsClicked int64  `gorm:"column:emails_clicked"`
		Clicks        int64  `gorm:"column:clicks"`
	}
	db.Raw(clickQuery, clickArgs...).Scan(&clickRows)

	emailsClickedByPos := make(map[int]int64)
	clicksByPos := make(map[int]int64)
	for _, r := range clickRows {
		// link_position is "post_N" or "pN"; extract the trailing integer
		// position regardless of the prefix/separator.
		s := r.LinkPosition
		i := len(s)
		for i > 0 && s[i-1] >= '0' && s[i-1] <= '9' {
			i--
		}
		if i == len(s) {
			// No trailing digits - not a positional label.
			continue
		}
		n, err := strconv.Atoi(s[i:])
		if err != nil || n < 0 {
			continue
		}
		emailsClickedByPos[n] += r.EmailsClicked
		clicksByPos[n] += r.Clicks
	}

	// 3. Build the per-position result, ascending, skipping positions never shown.
	data := make([]DigestPositionStat, 0, maxPosts)
	for n := 0; n < maxPosts; n++ {
		if shown[n] <= 0 {
			continue
		}
		var ctr float64
		if shown[n] > 0 {
			ctr = float64(emailsClickedByPos[n]) / float64(shown[n]) * 100
		}
		data = append(data, DigestPositionStat{
			Position:      n,
			Shown:         shown[n],
			EmailsClicked: emailsClickedByPos[n],
			Clicks:        clicksByPos[n],
			CTR:           ctr,
		})
	}

	return c.JSON(fiber.Map{
		"data": data,
		"period": fiber.Map{
			"start": startDate,
			"end":   endDate,
			"type":  emailType,
		},
	})
}

// ReengageFunnel represents overall funnel counts for the localised
// re-engagement email sequence within the requested period.
type ReengageFunnel struct {
	Sent      int64 `json:"sent"`
	Opened    int64 `json:"opened"`
	Clicked   int64 `json:"clicked"`
	Reengaged int64 `json:"reengaged"`
}

// ReengageStageStat represents funnel counts for a single stage (day 1-5) of
// the re-engagement sequence.
type ReengageStageStat struct {
	Stage     uint8 `json:"stage"`
	Sent      int64 `json:"sent"`
	Opened    int64 `json:"opened"`
	Clicked   int64 `json:"clicked"`
	Reengaged int64 `json:"reengaged"`
}

// ReengageArmStat represents funnel counts for a single experiment arm
// ('control', 'a', 'b', ...). The control arm is a holdout that receives
// no mail at all, so its opened/clicked counts are always zero - it still
// has a sent count and a reengaged count, which together form the baseline
// used to compute lift for the mailed arms.
type ReengageArmStat struct {
	Arm       string `json:"arm"`
	Sent      int64  `json:"sent"`
	Opened    int64  `json:"opened"`
	Clicked   int64  `json:"clicked"`
	Reengaged int64  `json:"reengaged"`
}

// ReengageSegmentStat represents send/reengagement counts for a single
// user-journey segment ('offer', 'wanted', 'replier', 'other') captured at
// send time.
type ReengageSegmentStat struct {
	Segment   string `json:"segment"`
	Sent      int64  `json:"sent"`
	Reengaged int64  `json:"reengaged"`
}

// ReengageSourceStat breaks sends down by how the sign-off volunteer's community
// was resolved: 'home' (the member's catchment contains where they live - what we
// want), 'nearest' (no catchment matched, so nearest centre was used), 'unknown'
// (no location to test) or 'none' (no eligible volunteer; plain Freegle voice).
// It answers "are we actually signing off from the member's own community?" and
// whether a genuine local sign-off engages better. Opens/clicks are joined, so
// this needs email_tracking.
type ReengageSourceStat struct {
	Source    string `json:"source"`
	Sent      int64  `json:"sent"`
	Opened    int64  `json:"opened"`
	Clicked   int64  `json:"clicked"`
	Reengaged int64  `json:"reengaged"`
}

// ReengageEffectiveness returns funnel/effectiveness statistics for the
// localised re-engagement email sequence (requires authentication).
//
// Sends live in the `reengage` table (one row per stage sent to a user).
// Opens/clicks come from a LEFT JOIN to email_tracking via
// reengage.email_tracking_id, which is NULL for the experiment's control
// arm (a holdout that receives no mail) - the LEFT JOIN keeps those rows
// counted towards "sent" while their opened/clicked always resolve to
// zero. Actual re-engagement is reengage.reengaged_at IS NOT NULL, written
// separately from the mutable email open/click signals by the
// mail:reengage-outcomes batch job.
//
// @Router /modtools/email/stats/reengage [get]
// @Summary Get re-engagement email effectiveness
// @Description Returns funnel (sent/opened/clicked/reengaged) counts overall and broken down by stage, experiment arm and journey segment (Support/Admin only)
// @Tags emailtracking
// @Produce json
// @Security BearerAuth
// @Param start query string false "Start date (YYYY-MM-DD)"
// @Param end query string false "End date (YYYY-MM-DD)"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Unauthorized"
// @Failure 403 {object} fiber.Error "Forbidden"
func ReengageEffectiveness(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user has support/admin role
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	startDate := c.Query("start", "")
	endDate := c.Query("end", "")

	// Default to the last 90 days when no range is supplied.
	if startDate == "" || endDate == "" {
		now := time.Now()
		endDate = now.Format("2006-01-02")
		startDate = now.AddDate(0, 0, -90).Format("2006-01-02")
	}

	// If endDate doesn't include a time component, extend it to end of day.
	endDateTime := endDate
	if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
		endDateTime = endDate + " 23:59:59"
	}

	// Overall funnel. LEFT JOIN so control-arm rows (email_tracking_id IS
	// NULL, since no mail was sent) still count towards "sent" - they just
	// never contribute to opened/clicked.
	var funnel ReengageFunnel
	// ORM migration site db84f5bddc5b (wave 4).
	db.Table("reengage r").
		Select("COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
		Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
		Where("r.sentat BETWEEN ? AND ?", startDate, endDateTime).
		Scan(&funnel)

	// Funnel broken down by stage (day 1-5).
	byStage := make([]ReengageStageStat, 0)
	// ORM migration site b8401fd16dd1 (wave 4).
	db.Table("reengage r").
		Select("r.stage AS stage, COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
		Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
		Where("r.sentat BETWEEN ? AND ?", startDate, endDateTime).
		Group("r.stage").
		Order("r.stage ASC").
		Scan(&byStage)

	// Funnel broken down by experiment arm. Rows predating the experiment
	// (or sent outside of one) have arm = NULL and are excluded here - they
	// are still reflected in the overall funnel above.
	byArm := make([]ReengageArmStat, 0)
	// ORM migration site 37d4ff3aedb7 (wave 4).
	db.Table("reengage r").
		Select("r.arm AS arm, COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
		Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
		Where("r.sentat BETWEEN ? AND ? AND r.arm IS NOT NULL", startDate, endDateTime).
		Group("r.arm").
		Order("r.arm ASC").
		Scan(&byArm)

	// Sent/reengaged broken down by the user-journey segment captured at
	// send time. Segment has no bearing on opens/clicks so it isn't joined
	// to email_tracking.
	bySegment := make([]ReengageSegmentStat, 0)
	// ORM migration site 9db29fd9c43a (wave 1).
	db.Table("reengage r").
		Select("r.segment AS segment, COUNT(*) AS sent, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
		Where("r.sentat BETWEEN ? AND ? AND r.segment IS NOT NULL", startDate, endDateTime).
		Group("r.segment").
		Order("r.segment ASC").
		Scan(&bySegment)

	// Sends broken down by how the sign-off community was resolved. Rows
	// predating this instrumentation have volunteer_source = NULL and are
	// excluded here (still counted in the overall funnel). Opens/clicks are
	// joined so a genuine home-group sign-off can be compared against nearest
	// or no sign-off.
	bySource := make([]ReengageSourceStat, 0)
	// ORM migration site 639cf671aa39 (wave 4).
	db.Table("reengage r").
		Select("r.volunteer_source AS source, COUNT(*) AS sent, SUM(CASE WHEN et.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN et.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN r.reengaged_at IS NOT NULL THEN 1 ELSE 0 END) AS reengaged").
		Joins("LEFT JOIN email_tracking et ON r.email_tracking_id = et.id").
		Where("r.sentat BETWEEN ? AND ? AND r.volunteer_source IS NOT NULL", startDate, endDateTime).
		Group("r.volunteer_source").
		Order("r.volunteer_source ASC").
		Scan(&bySource)

	return c.JSON(fiber.Map{
		"funnel":    funnel,
		"byStage":   byStage,
		"byArm":     byArm,
		"bySegment": bySegment,
		"bySource":  bySource,
		"period": fiber.Map{
			"start": startDate,
			"end":   endDate,
		},
	})
}
