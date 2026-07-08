// Package browse holds browse-feed instrumentation endpoints.
package browse

import (
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// maxScrollPosition caps a recorded position so a malformed or malicious client
// can't write an absurd value (the browse feed never has anywhere near this many
// items in one session).
const maxScrollPosition = 100000

type RecordScrollDepthRequest struct {
	// Session is a client-generated per-browse-session id. When present we upsert
	// one row per session keeping the max, so the debounced client can report
	// repeatedly as it scrolls (and after re-entry) without double-counting.
	Session string `json:"session"`
	// MaxPosition is the furthest 0-based feed item index reached in the session.
	MaxPosition int `json:"maxposition"`
	// ItemsAvailable is the feed length when recorded (optional context).
	ItemsAvailable int `json:"itemsavailable"`
	// Context is which feed: "browse" (default) or "search".
	Context string `json:"context"`
}

// RecordScrollDepth records how far down the browse feed a session scrolled.
//
// POST /api/scrolldepth — one row per browse session. The client reports the
// furthest position reached, debounced as it scrolls; with a session id we upsert
// (keeping GREATEST(max_position)) so repeat reports collapse to one row. No login
// required: logged-out browsing is recorded with a NULL userid. Powers the
// sysadmin "Scrolling" scroll-depth curve.
func RecordScrollDepth(c *fiber.Ctx) error {
	var req RecordScrollDepthRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	// Silently ignore nonsense rather than erroring the client; the beacon is fire-and-forget.
	if req.MaxPosition < 0 || req.MaxPosition > maxScrollPosition {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	db := database.DBConn

	// Record the userid only when logged in; NULL otherwise.
	var userid interface{}
	if myid := user.WhoAmI(c); myid > 0 {
		userid = myid
	}

	// Normalise the feed context to the two known values.
	ctx := req.Context
	if ctx != "browse" && ctx != "search" {
		ctx = "browse"
	}

	// items_available is optional context; store NULL when not supplied.
	var items interface{}
	if req.ItemsAvailable > 0 {
		items = req.ItemsAvailable
	}

	if req.Session != "" {
		// Upsert keyed on the unique session id: keep the furthest position the
		// session reached. Idempotent across the debounced client's repeat sends
		// and any re-entry, so a session is counted exactly once.
		db.Exec("INSERT INTO browse_scroll_depth (session, userid, max_position, items_available, context) VALUES (?, ?, ?, ?, ?) "+
			"ON DUPLICATE KEY UPDATE "+
			"max_position = GREATEST(max_position, VALUES(max_position)), "+
			"items_available = COALESCE(VALUES(items_available), items_available), "+
			"userid = COALESCE(VALUES(userid), userid)",
			req.Session, userid, req.MaxPosition, items, ctx)
	} else {
		// Legacy clients without a session id: a single fire-and-forget insert.
		db.Exec("INSERT INTO browse_scroll_depth (userid, max_position, items_available, context) VALUES (?, ?, ?, ?)",
			userid, req.MaxPosition, items, ctx)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// ScrollDepthPoint is one position on the scroll-depth curve.
type ScrollDepthPoint struct {
	Position         int     `json:"position"`
	SessionsReaching int64   `json:"sessions_reaching"`
	Pct              float64 `json:"pct"`
}

// ScrollDepthCurve returns the browse-feed scroll-depth curve for the sysadmin
// "Scrolling" tab: for each feed position N, the number and fraction of sessions
// that scrolled to at least position N. The browse-feed analogue of the digest
// click-by-position chart.
//
// GET /api/modtools/scroll/depth — Support or Admin only. Defaults to the last 7
// days; pass ?start=YYYY-MM-DD&end=YYYY-MM-DD for a wider window.
func ScrollDepthCurve(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}

	startDate := c.Query("start", "")
	endDate := c.Query("end", "")
	if startDate == "" || endDate == "" {
		now := time.Now()
		endDate = now.Format("2006-01-02")
		startDate = now.AddDate(0, 0, -7).Format("2006-01-02")
	}
	endDateTime := endDate
	if !strings.Contains(endDate, " ") && !strings.Contains(endDate, "T") {
		endDateTime = endDate + " 23:59:59"
	}

	// One row per distinct furthest-position reached, with how many sessions stopped there.
	var rows []struct {
		MaxPosition int   `gorm:"column:max_position"`
		Cnt         int64 `gorm:"column:cnt"`
	}
	db.Raw(`SELECT max_position, COUNT(*) AS cnt
	        FROM browse_scroll_depth
	        WHERE created_at BETWEEN ? AND ?
	        GROUP BY max_position`, startDate, endDateTime).Scan(&rows)

	var total int64
	maxObserved := 0
	for _, r := range rows {
		total += r.Cnt
		if r.MaxPosition > maxObserved {
			maxObserved = r.MaxPosition
		}
	}

	// Cap the number of points so a long tail can't bloat the payload.
	limit := maxObserved
	if limit > 200 {
		limit = 200
	}

	// reaching[n] = sessions whose furthest position was >= n. Built as a suffix sum
	// over the per-position counts: walk positions high->low accumulating the count.
	counts := make([]int64, maxObserved+1)
	for _, r := range rows {
		if r.MaxPosition >= 0 && r.MaxPosition <= maxObserved {
			counts[r.MaxPosition] += r.Cnt
		}
	}
	suffix := make([]int64, maxObserved+2)
	for n := maxObserved; n >= 0; n-- {
		suffix[n] = suffix[n+1] + counts[n]
	}

	positions := make([]ScrollDepthPoint, 0, limit+1)
	for n := 0; n <= limit; n++ {
		reaching := suffix[n]
		pct := 0.0
		if total > 0 {
			pct = float64(reaching) / float64(total) * 100
		}
		positions = append(positions, ScrollDepthPoint{Position: n, SessionsReaching: reaching, Pct: pct})
	}

	return c.JSON(fiber.Map{
		"ret":       0,
		"status":    "Success",
		"total":     total,
		"positions": positions,
	})
}
