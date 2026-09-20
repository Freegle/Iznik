package housekeeper

import (
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
)

func TestTaskHousekeeperNotifyConstant(t *testing.T) {
	if TaskHousekeeperNotify != "housekeeper_notify" {
		t.Errorf("TaskHousekeeperNotify = %q, want %q", TaskHousekeeperNotify, "housekeeper_notify")
	}
}

func TestActiveCronJobCount(t *testing.T) {
	got := ActiveCronJobCount()
	if got <= 0 {
		t.Errorf("ActiveCronJobCount() = %d, want > 0", got)
	}
}

func TestActiveCronJobCountConsistency(t *testing.T) {
	// Manually count active jobs so the test catches any mismatch in the function.
	want := 0
	for _, j := range cronJobs {
		if j.Active {
			want++
		}
	}
	got := ActiveCronJobCount()
	if got != want {
		t.Errorf("ActiveCronJobCount() = %d, want %d", got, want)
	}
}

func TestActiveCronJobCountPlusInactiveEqualsTotal(t *testing.T) {
	active := ActiveCronJobCount()
	total := len(cronJobs)
	inactive := 0
	for _, j := range cronJobs {
		if !j.Active {
			inactive++
		}
	}
	if active+inactive != total {
		t.Errorf("active(%d) + inactive(%d) = %d, want total %d", active, inactive, active+inactive, total)
	}
}

func TestCronJobsNonEmpty(t *testing.T) {
	if len(cronJobs) == 0 {
		t.Error("cronJobs slice is empty")
	}
}

func TestCronJobDataIntegrity(t *testing.T) {
	for i, j := range cronJobs {
		if strings.TrimSpace(j.Command) == "" {
			t.Errorf("cronJobs[%d]: empty Command", i)
		}
		if strings.TrimSpace(j.Name) == "" {
			t.Errorf("cronJobs[%d] (%s): empty Name", i, j.Command)
		}
		if j.IntervalMinutes <= 0 {
			t.Errorf("cronJobs[%d] (%s): IntervalMinutes = %d, want > 0", i, j.Command, j.IntervalMinutes)
		}
		if strings.TrimSpace(j.Category) == "" {
			t.Errorf("cronJobs[%d] (%s): empty Category", i, j.Command)
		}
		if strings.TrimSpace(j.Schedule) == "" {
			t.Errorf("cronJobs[%d] (%s): empty Schedule", i, j.Command)
		}
	}
}

func TestCronJobCommandsUnique(t *testing.T) {
	seen := make(map[string]int, len(cronJobs))
	for i, j := range cronJobs {
		if prev, ok := seen[j.Command]; ok {
			t.Errorf("cronJobs[%d] duplicates cronJobs[%d]: Command = %q", i, prev, j.Command)
		}
		seen[j.Command] = i
	}
}

func TestCronJobKnownInactiveJobs(t *testing.T) {
	// deploy:watch is intentionally disabled: it detects code updates via
	// version.txt, which isn't how the Docker environment deploys.
	// users:cleanup was re-enabled once the V1 cutover left it unscheduled.
	knownInactive := []string{"deploy:watch"}
	for _, cmd := range knownInactive {
		found := false
		for _, j := range cronJobs {
			if j.Command == cmd {
				found = true
				if j.Active {
					t.Errorf("cronJobs command %q should be inactive", cmd)
				}
				break
			}
		}
		if !found {
			t.Errorf("expected to find cronJobs entry for %q", cmd)
		}
	}
}

// newTestApp creates a minimal fiber app with the housekeeper routes registered.
func newTestApp() *fiber.App {
	app := fiber.New(fiber.Config{ErrorHandler: func(c *fiber.Ctx, err error) error {
		code := fiber.StatusInternalServerError
		if e, ok := err.(*fiber.Error); ok {
			code = e.Code
		}
		return c.Status(code).JSON(fiber.Map{"error": err.Error()})
	}})
	app.Post("/housekeeper/notify", Notify)
	app.Post("/housekeeper/task/:key/complete", CompleteTask)
	app.Get("/housekeeper/tasks", ListTasks)
	app.Get("/housekeeper/cronjobs", ListCronJobs)
	return app
}

func TestNotifyUnauthorized(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("POST", "/housekeeper/notify", nil)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("app.Test: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("Notify without auth: status = %d, want %d", resp.StatusCode, fiber.StatusUnauthorized)
	}
}

func TestCompleteTaskUnauthorized(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("POST", "/housekeeper/task/some-task/complete", nil)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("app.Test: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("CompleteTask without auth: status = %d, want %d", resp.StatusCode, fiber.StatusUnauthorized)
	}
}

func TestListTasksUnauthorized(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("GET", "/housekeeper/tasks", nil)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("app.Test: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("ListTasks without auth: status = %d, want %d", resp.StatusCode, fiber.StatusUnauthorized)
	}
}

func TestListCronJobsUnauthorized(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("GET", "/housekeeper/cronjobs", nil)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("app.Test: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("ListCronJobs without auth: status = %d, want %d", resp.StatusCode, fiber.StatusUnauthorized)
	}
}

func TestCronJobStructFields(t *testing.T) {
	// Smoke-test that a sample job has expected values parsed correctly.
	var queueJob *CronJob
	for i := range cronJobs {
		if cronJobs[i].Command == "queue:background-tasks" {
			queueJob = &cronJobs[i]
			break
		}
	}
	if queueJob == nil {
		t.Fatal("expected queue:background-tasks in cronJobs")
	}
	if !queueJob.Active {
		t.Error("queue:background-tasks should be active")
	}
	if queueJob.IntervalMinutes != 1 {
		t.Errorf("queue:background-tasks IntervalMinutes = %d, want 1", queueJob.IntervalMinutes)
	}
	if queueJob.Category != "System" {
		t.Errorf("queue:background-tasks Category = %q, want System", queueJob.Category)
	}
}

// The users_approxlocs refresh is the only writer of the point cloud that drives rippling reach,
// so if it silently stops running nothing else notices - it must be visible on the SysAdmin cron
// dashboard where an overdue run shows up.
func TestCronJobsIncludesApproxLocsRefresh(t *testing.T) {
	const command = "users:update-approx-locs"

	for _, j := range cronJobs {
		if j.Command != command {
			continue
		}
		if !j.Active {
			t.Errorf("%s: Active = false, want true", command)
		}
		if j.IntervalMinutes != 1440 {
			t.Errorf("%s: IntervalMinutes = %d, want 1440 (daily)", command, j.IntervalMinutes)
		}
		if j.Category != "User Management" {
			t.Errorf("%s: Category = %q, want %q", command, j.Category, "User Management")
		}
		return
	}

	t.Errorf("cronJobs is missing %q - it would not appear in SysAdmin > Cron Jobs", command)
}

func TestPickCronJobStatusPrefersTheLatestStart(t *testing.T) {
	at := func(s string) *time.Time {
		v, err := time.Parse(time.RFC3339, s)
		if err != nil {
			t.Fatalf("bad time %q: %v", s, err)
		}
		return &v
	}
	statuses := []cronJobStatus{
		// An argument-less row left behind by a schedule change, exact match.
		{Command: "messages:chase-up", LastRunAt: at("2026-08-13T18:00:00Z")},
		{Command: "messages:chase-up --languishing-only", LastRunAt: at("2026-09-20T09:00:00Z")},
		{Command: "messages:chase-up --skip-languishing", LastRunAt: at("2026-09-20T14:00:00Z")},
		// A stale un-sharded digest row plus live shards, one of which never started.
		{Command: "mail:digest:unified --mode=immediate", LastRunAt: at("2026-05-27T09:07:49Z")},
		{Command: "mail:digest:unified --mode=daily --shard=0 --shards=8", LastRunAt: at("2026-09-20T10:59:00Z")},
		{Command: "mail:digest:unified --mode=immediate --shard=3 --shards=8", LastRunAt: at("2026-09-20T14:17:00Z")},
		{Command: "mail:digest:unified --mode=reach --shard=1 --shards=4", LastRunAt: nil},
		// A different command that merely shares a prefix string.
		{Command: "mail:digest:mark-seen", LastRunAt: at("2026-09-20T14:00:00Z")},
	}

	if s := pickCronJobStatus("messages:chase-up", statuses); s == nil || s.Command != "messages:chase-up --skip-languishing" {
		t.Errorf("chase-up: picked %v, want the 14:00 --skip-languishing row", s)
	}
	if s := pickCronJobStatus("mail:digest:unified", statuses); s == nil || s.Command != "mail:digest:unified --mode=immediate --shard=3 --shards=8" {
		t.Errorf("digest: picked %v, want the 14:17 immediate shard", s)
	}
	if s := pickCronJobStatus("mail:digest:mark-seen", statuses); s == nil || s.Command != "mail:digest:mark-seen" {
		t.Errorf("mark-seen: picked %v, want its own exact row", s)
	}
	if s := pickCronJobStatus("mail:digest", statuses); s != nil {
		t.Errorf("a bare prefix that is not a command must not match, got %v", s)
	}
	if s := pickCronJobStatus("nothing:here", statuses); s != nil {
		t.Errorf("unknown command: got %v, want nil", s)
	}
	only := []cronJobStatus{{Command: "tn:sync", LastRunAt: nil}}
	if s := pickCronJobStatus("tn:sync", only); s == nil || s.Command != "tn:sync" {
		t.Errorf("a never-started row is still returned when it is the only match, got %v", s)
	}
}
