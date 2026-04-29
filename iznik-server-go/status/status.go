package status

import (
	"os"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// BuildDate and GitCommit are populated at startup from BUILD_INFO (Docker builds)
// or from the .git directory (git-checkout deployments).
var BuildDate = "unknown"
var GitCommit = "unknown"

func init() {
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

	// Fallback: read git HEAD from the working directory (git-checkout deployments
	// don't write BUILD_INFO, but the .git directory is present on the server).
	if GitCommit == "unknown" {
		if sha := readGitHead("."); sha != "" {
			GitCommit = sha
		}
	}
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

// GetStatus reads the system status file and returns its contents.
//
// @Summary Get system status
// @Description Returns the contents of /tmp/iznik.status, which is written by the batch system
// @Tags status
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/status [get]
func GetStatus(c *fiber.Ctx) error {
	data, err := os.ReadFile("/tmp/iznik.status")
	if err != nil {
		return c.JSON(fiber.Map{
			"ret":    1,
			"status": "Cannot access status file",
		})
	}
	c.Set("Content-Type", "application/json")
	return c.Send(data)
}

// GetVersion returns the deployed commit of the Go API binary and the Laravel batch server.
//
// @Summary Get API version info
// @Description Returns git commit hashes for the Go API and the Laravel batch server.
// The Go commit is read from BUILD_INFO (Docker) or .git/HEAD (checkout deployment).
// The Laravel commit is written to the config table by deploy:refresh and read here.
// @Tags status
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/version [get]
func GetVersion(c *fiber.Ctx) error {
	laravelCommit := "unknown"
	if database.DBConn != nil {
		var value string
		database.DBConn.Raw("SELECT value FROM config WHERE `key` = 'deploy.laravel_commit'").Scan(&value)
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
