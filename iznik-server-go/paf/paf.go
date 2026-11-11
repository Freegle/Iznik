// Package paf serves the PAF update on the SysAdmin Housekeeping tab: requests to download and apply a Royal
// Mail address data (PAF) release, and their progress. The work itself is done by the batch
// command paf:import-run with the paf-extractor sidecar. See
// docs/ops/runbooks/paf-address-data.md.
package paf

import (
	"encoding/json"
	"log"
	"regexp"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// staleAfter is how long an unfinished import can go without a progress write before it is
// taken to be dead (the batch stopped working on it), so that a new request can replace it.
// A live import writes every few seconds.
const staleAfter = 30 * time.Minute

// keyLength is the number of letters and digits in an AFD unlock key, which is written in
// their email as eight groups of three ("AB1 / CD2 / ...").
const keyLength = 24

var editionPattern = regexp.MustCompile(`^Y\d\dM\d\d$`)
var nonKey = regexp.MustCompile(`[^A-Z0-9]`)

// Import is a paf_imports row, without the unlock key.
type Import struct {
	ID      uint64  `json:"id"`
	Status  string  `json:"status"`
	Edition *string `json:"edition"`
	// The date AFD published the release this import took, when it is known.
	ReleasePublished *string    `json:"release_published"`
	Progress         int        `json:"progress"`
	Detail           *string    `json:"detail"`
	LinesInRelease   *uint64    `json:"lines_in_release"`
	LinesToApply     *uint64    `json:"lines_to_apply"`
	LinesApplied     uint64     `json:"lines_applied"`
	Inserted         uint64     `json:"inserted"`
	Updated          uint64     `json:"updated"`
	Skipped          uint64     `json:"skipped"`
	Error            *string    `json:"error"`
	RequestedBy      *uint64    `json:"requested_by"`
	StartedAt        *time.Time `json:"started_at"`
	FinishedAt       *time.Time `json:"finished_at"`
	CreatedAt        *time.Time `json:"created_at"`
	UpdatedAt        *time.Time `json:"updated_at"`
}

const columns = "id, status, edition, DATE_FORMAT(release_published, '%Y-%m-%d') AS release_published, progress, detail, lines_in_release, lines_to_apply, lines_applied, " +
	"inserted, updated, skipped, error, requested_by, started_at, finished_at, created_at, updated_at"

func requireAdmin(c *fiber.Ctx) (uint64, error) {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return 0, c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"error": "Not logged in"})
	}
	if !auth.IsAdmin(myid) {
		return 0, c.Status(fiber.StatusForbidden).JSON(fiber.Map{"error": "Admin required"})
	}
	return myid, nil
}

// ListImports returns the most recent PAF imports, newest first.
//
// @Summary List PAF address data imports
// @Tags sysadmin
// @Produce json
// @Success 200 {array} Import
// @Router /sysadmin/paf [get]
func ListImports(c *fiber.Ctx) error {
	myid, err := requireAdmin(c)
	if myid == 0 {
		return err
	}

	var imports []Import
	if res := database.DBConn.Table("paf_imports").Select(columns).Order("id DESC").Limit(10).Scan(&imports); res.Error != nil {
		log.Printf("[PAF] list error: %v", res.Error)
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to list imports"})
	}

	if imports == nil {
		imports = []Import{}
	}

	return c.JSON(imports)
}

// Latest is the release at the top of AFD's site, as paf:check-latest last found it (the config
// row paf_latest). Published is empty, and Error says why, when it could not be read.
type Latest struct {
	Published string `json:"published,omitempty"`
	Size      int64  `json:"size,omitempty"`
	CheckedAt string `json:"checked_at,omitempty"`
	Error     string `json:"error,omitempty"`
}

// GetLatest returns the latest release on AFD's site, as the batch last saw it. The Go API cannot
// reach the paf-extractor sidecar, so it reads what paf:check-latest stored.
//
// @Summary The latest PAF release on the AFD site
// @Tags sysadmin
// @Produce json
// @Success 200 {object} Latest
// @Router /sysadmin/paf/latest [get]
func GetLatest(c *fiber.Ctx) error {
	myid, err := requireAdmin(c)
	if myid == 0 {
		return err
	}

	var value string
	database.DBConn.Table("config").Where("`key` = ?", "paf_latest").Select("value").Scan(&value)

	latest := Latest{}
	if value == "" || json.Unmarshal([]byte(value), &latest) != nil {
		latest = Latest{Error: "Not checked yet"}
	}

	return c.JSON(latest)
}

// StartImportRequest is the body of POST /sysadmin/paf.
type StartImportRequest struct {
	// The unlock key from AFD's email. Spaces and slashes are ignored.
	Key string `json:"key"`
	// An archived release such as Y26M08. Empty for the current one.
	Edition string `json:"edition"`
}

// StartImport queues a request to download and apply a PAF release. Only one runs at a time.
//
// @Summary Start a PAF address data import
// @Tags sysadmin
// @Accept json
// @Produce json
// @Param body body StartImportRequest true "Unlock key and optional edition"
// @Success 200 {object} Import
// @Router /sysadmin/paf [post]
func StartImport(c *fiber.Ctx) error {
	myid, err := requireAdmin(c)
	if myid == 0 {
		return err
	}

	var req StartImportRequest
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "Invalid request body"})
	}

	key := nonKey.ReplaceAllString(strings.ToUpper(req.Key), "")
	if len(key) != keyLength {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "The key should be 24 letters and numbers, as in the email from AFD"})
	}

	edition := strings.ToUpper(strings.TrimSpace(req.Edition))
	if edition != "" && !editionPattern.MatchString(edition) {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"error": "The edition should look like Y26M08, or be left empty for the latest"})
	}

	db := database.DBConn

	var active []Import
	db.Table("paf_imports").Select(columns).Where("status NOT IN ?", []string{"done", "failed"}).Scan(&active)
	for _, a := range active {
		if a.UpdatedAt != nil && time.Since(*a.UpdatedAt) > staleAfter {
			db.Table("paf_imports").Where("id = ?", a.ID).Updates(map[string]interface{}{
				"status":      "failed",
				"unlock_key":  nil,
				"error":       "Stopped making progress, replaced by a new request",
				"finished_at": gorm.Expr("NOW()"),
				"updated_at":  gorm.Expr("NOW()"),
			})
			continue
		}
		return c.Status(fiber.StatusConflict).JSON(fiber.Map{"error": "An import is already in progress"})
	}

	row := map[string]interface{}{
		"status":       "queued",
		"edition":      nil,
		"unlock_key":   key,
		"detail":       "Waiting to start",
		"requested_by": myid,
		"created_at":   gorm.Expr("NOW()"),
		"updated_at":   gorm.Expr("NOW()"),
	}
	if edition != "" {
		row["edition"] = edition
	}

	if res := db.Table("paf_imports").Create(row); res.Error != nil {
		log.Printf("[PAF] insert error: %v", res.Error)
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"error": "Failed to start the import"})
	}

	var created Import
	db.Table("paf_imports").Select(columns).Where("requested_by = ?", myid).Order("id DESC").Limit(1).Scan(&created)

	log.Printf("[PAF] User %d requested import %d (edition %q)", myid, created.ID, edition)

	return c.JSON(created)
}
