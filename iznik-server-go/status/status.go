package status

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// BuildDate and GitCommit identify the deployed Go build, reported by /api/version.
// They are resolved at startup, in priority order:
//  1. ldflags injection (-X .../status.GitCommit=...) if the build set it.
//  2. /app/BUILD_INFO (Docker dev image).
//  3. .git/HEAD of the deployed checkout — the production service is a git checkout
//     (/var/www/iznik-server-go, built with `go build` and restarted by monit), so
//     we read the .git directory that sits NEXT TO THE BINARY (os.Executable's dir),
//     not the process CWD, which monit does not guarantee to be the checkout root.
var BuildDate = "unknown"
var GitCommit = "unknown"

func init() {
	// An ldflags-injected commit always wins; only fall back to file-based
	// detection when the value wasn't baked in at build time.
	if GitCommit != "unknown" {
		return
	}

	// Prefer BUILD_INFO (set by Dockerfile during Docker builds).
	data, err := os.ReadFile("/app/BUILD_INFO")
	if err == nil {
		parts := strings.Fields(strings.TrimSpace(string(data)))
		if len(parts) >= 1 {
			GitCommit = parts[0]
		}
		if len(parts) >= 2 {
			BuildDate = strings.Join(parts[1:], " ")
		}
	}

	// Fallback for git-checkout deployments (no BUILD_INFO). Probe for a .git
	// directory beside the running binary first — `go build` writes the binary
	// into the checkout root where .git lives — then its parent, then the CWD.
	if GitCommit == "unknown" {
		for _, dir := range gitSearchDirs() {
			if sha := readGitHead(dir); sha != "" {
				GitCommit = sha
				break
			}
		}
	}
}

// gitSearchDirs lists directories to probe for a .git folder. The production
// service is a subdir of the monorepo checkout (binary at <monorepo>/iznik-server-go,
// .git at <monorepo>/), so we walk UP the tree from both the binary's directory and
// the CWD, collecting ancestors. readGitHead then returns the monorepo HEAD — the
// commit that matches the PRs the deploy gate compares against.
func gitSearchDirs() []string {
	var starts []string
	if exe, err := os.Executable(); err == nil {
		starts = append(starts, filepath.Dir(exe))
	}
	if wd, err := os.Getwd(); err == nil {
		starts = append(starts, wd)
	}
	starts = append(starts, ".")

	seen := map[string]bool{}
	dirs := []string{}
	for _, s := range starts {
		d := s
		for i := 0; i < 10; i++ { // walk up to 10 levels to the monorepo root
			if !seen[d] {
				seen[d] = true
				dirs = append(dirs, d)
			}
			parent := filepath.Dir(d)
			if parent == d {
				break
			}
			d = parent
		}
	}
	return dirs
}

// readGitHead reads the current HEAD commit SHA from a git working directory.
// Returns empty string if not a git repo or HEAD cannot be resolved.
func readGitHead(dir string) string {
	headData, err := os.ReadFile(dir + "/.git/HEAD")
	if err != nil {
		return ""
	}
	head := strings.TrimSpace(string(headData))
	// Detached HEAD: the file contains the full SHA directly.
	if len(head) == 40 && !strings.Contains(head, " ") {
		return head
	}
	// Symbolic ref: "ref: refs/heads/master"
	if strings.HasPrefix(head, "ref: ") {
		refPath := dir + "/.git/" + strings.TrimPrefix(head, "ref: ")
		refData, err := os.ReadFile(refPath)
		if err != nil {
			return ""
		}
		return strings.TrimSpace(string(refData))
	}
	return ""
}

// StatusConfigKey is the config row that carries the published platform status.
// Written by Laravel's monitor:scheduled-outcomes (App\Monitoring\PlatformStatusWriter).
const StatusConfigKey = "status.platform"

// StatusMaxAge is how old a published status may be before we stop presenting it
// as current. The writer runs every ten minutes, so this tolerates three missed
// passes; beyond that the feed itself is the thing that is broken.
const StatusMaxAge = 30 * time.Minute

// GetStatus returns the platform status published by the batch system.
//
// This used to read /tmp/iznik.status, written by iznik-server/scripts/cron/status.php.
// That cron went with the V1 PHP removal and nothing replaced it, so the endpoint
// returned "Cannot access status file" for a month and nothing noticed. A file on
// this host cannot work now anyway: the writer is Laravel, in a different container
// on a different host. The status therefore travels through the `config` table, the
// same channel /api/version already uses for the Laravel deploy commit.
//
// A status older than StatusMaxAge is reported as a warning naming the staleness,
// rather than served as though it were current. That is the specific failure that
// went unnoticed last time: a dead writer must not look like a healthy platform.
//
// @Summary Get system status
// @Description Returns the platform status published by the batch system's outcome monitoring. A status older than 30 minutes is downgraded to a warning about the status feed itself.
// @Tags status
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/status [get]
func GetStatus(c *fiber.Ctx) error {
	if database.DBConn == nil {
		return c.JSON(fiber.Map{
			"ret":    1,
			"status": "Cannot read platform status",
		})
	}

	var value string
	database.DBConn.Table("config").
		Select("value").
		Where("`key` = ?", StatusConfigKey).
		Scan(&value)

	if value == "" {
		// Never published. Distinct from "published but stale": on a fresh
		// deploy the monitor may simply not have run yet.
		return c.JSON(fiber.Map{
			"ret":    1,
			"status": "Platform status has not been published yet",
		})
	}

	var payload map[string]interface{}
	if err := json.Unmarshal([]byte(value), &payload); err != nil {
		return c.JSON(fiber.Map{
			"ret":    1,
			"status": "Platform status is not readable",
		})
	}

	if age, ok := statusAge(payload); ok && age > StatusMaxAge {
		markStatusStale(payload, age)
	}

	return c.JSON(payload)
}

// statusAge returns how long ago the payload was generated. The bool is false if
// the payload carries no parseable generated_at, in which case we leave it alone
// rather than guess — an unreadable timestamp is the writer's bug, not a staleness
// signal, and inventing one would hide a status that is otherwise fine.
func statusAge(payload map[string]interface{}) (time.Duration, bool) {
	raw, ok := payload["generated_at"].(string)
	if !ok || raw == "" {
		return 0, false
	}

	generated, err := time.Parse(time.RFC3339, raw)
	if err != nil {
		return 0, false
	}

	return time.Since(generated), true
}

// markStatusStale rewrites a payload in place so a stale feed presents as a
// warning in its own right. The existing contents are kept: the last known state
// is still the best information available, it just cannot be trusted as current.
func markStatusStale(payload map[string]interface{}, age time.Duration) {
	payload["warning"] = true

	info, ok := payload["info"].(map[string]interface{})
	if !ok || info == nil {
		info = map[string]interface{}{}
		payload["info"] = info
	}

	info["Status feed"] = map[string]interface{}{
		"warning": true,
		"warningtext": fmt.Sprintf(
			"Platform status was last published %d minutes ago (expected every 10). "+
				"The monitoring that produces it may have stopped, so what follows may be out of date.",
			int(age.Minutes()),
		),
		"error":     false,
		"errortext": nil,
	}
}

// GetVersion returns the deployed commit of the Go API binary and the Laravel batch server.
//
// @Summary Get API version info
// @Description Returns git commit hashes for the Go API and the Laravel batch server.
// The Go commit is baked in at build time via ldflags (Netlify production build),
// or read from BUILD_INFO (Docker) / .git/HEAD (git checkout) as a fallback.
// The Laravel commit is written to the config table by deploy:record-commit and read here.
// @Tags status
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/version [get]
func GetVersion(c *fiber.Ctx) error {
	laravelCommit := "unknown"
	if database.DBConn != nil {
		var value string
		database.DBConn.Table("config").Select("value").Where("`key` = 'deploy.laravel_commit'").Scan(&value)
		if value != "" {
			laravelCommit = value
		}
	}

	return c.JSON(fiber.Map{
		"build":           BuildDate,
		"commit":          GitCommit,
		"laravel_commit":  laravelCommit,
	})
}
