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

func TestCronJobCodeDeployedRow(t *testing.T) {
	// deploy:watch (version.txt change detection) is gone. Its row is replaced by Code Deployed,
	// which deploy:check-lag keeps at the time the oldest undeployed green commit passed CI, so
	// a 7-day interval makes it overdue a week after that.
	for _, j := range cronJobs {
		if j.Command == "deploy:watch" {
			t.Errorf("deploy:watch should no longer be in the registry")
		}
	}
	found := false
	for _, j := range cronJobs {
		if j.Command == "deploy:green-master" {
			found = true
			if !j.Active {
				t.Errorf("Code Deployed should be active")
			}
			if j.IntervalMinutes != 10080 {
				t.Errorf("Code Deployed IntervalMinutes = %d, want 10080 (7 days)", j.IntervalMinutes)
			}
		}
	}
	if !found {
		t.Errorf("expected a cronJobs entry for deploy:green-master")
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

func TestCronJobGraceMinutes(t *testing.T) {
	cases := map[int]int{1: 2, 2: 2, 10: 10, 60: 60, 1440: 60, 10080: 60}
	for interval, want := range cases {
		if got := cronJobGraceMinutes(interval); got != want {
			t.Errorf("cronJobGraceMinutes(%d) = %d, want %d", interval, got, want)
		}
	}
}

func TestCronJobNeedsAttention(t *testing.T) {
	now := time.Date(2026, 10, 22, 12, 0, 0, 0, time.UTC)
	ago := func(d time.Duration) *time.Time { ts := now.Add(-d); return &ts }
	code := func(c int) *int { return &c }
	weekly := CronJob{Command: "w", IntervalMinutes: 10080, Active: true}
	minutely := CronJob{Command: "m", IntervalMinutes: 1, Active: true}

	cases := []struct {
		name string
		job  CronJob
		s    *cronJobStatus
		want bool
	}{
		{"no status row means never run", weekly, nil, true},
		{"row without a start means never run", weekly, &cronJobStatus{}, true},
		{"fresh success", weekly, &cronJobStatus{LastRunAt: ago(time.Hour), LastExitCode: code(0)}, false},
		{"fresh failure", weekly, &cronJobStatus{LastRunAt: ago(time.Hour), LastExitCode: code(1)}, true},
		{"within the week plus grace", weekly, &cronJobStatus{LastRunAt: ago(7*24*time.Hour + 59*time.Minute), LastExitCode: code(0)}, false},
		{"past the week plus grace", weekly, &cronJobStatus{LastRunAt: ago(7*24*time.Hour + 61*time.Minute), LastExitCode: code(0)}, true},
		{"per-minute job within jitter", minutely, &cronJobStatus{LastRunAt: ago(3 * time.Minute), LastExitCode: code(0)}, false},
		{"per-minute job missed a cycle", minutely, &cronJobStatus{LastRunAt: ago(4 * time.Minute), LastExitCode: code(0)}, true},
		{"no exit code yet but on time", weekly, &cronJobStatus{LastRunAt: ago(time.Hour)}, false},
		{"inactive job never counts", CronJob{Command: "i", IntervalMinutes: 1}, nil, false},
	}
	for _, c := range cases {
		if got := CronJobNeedsAttention(c.job, c.s, now); got != c.want {
			t.Errorf("%s: got %v, want %v", c.name, got, c.want)
		}
	}
}

func TestCountCronJobsNeedingAttentionWalksTheRegistry(t *testing.T) {
	now := time.Date(2026, 10, 22, 12, 0, 0, 0, time.UTC)
	zero := 0
	fresh := now.Add(-time.Minute)

	// Nothing recorded: every active entry has never run.
	if got, want := countCronJobsNeedingAttention(nil, now), int64(ActiveCronJobCount()); got != want {
		t.Errorf("empty table: got %d, want %d (every active entry never run)", got, want)
	}

	// Every active entry fresh and successful: nothing needs attention, even with
	// a stray row for a command that is not in the registry.
	var statuses []cronJobStatus
	for _, j := range cronJobs {
		statuses = append(statuses, cronJobStatus{Command: j.Command, LastRunAt: &fresh, LastExitCode: &zero})
	}
	statuses = append(statuses, cronJobStatus{Command: "retired:job", LastRunAt: &fresh, LastExitCode: &zero})
	if got := countCronJobsNeedingAttention(statuses, now); got != 0 {
		t.Errorf("all healthy: got %d, want 0", got)
	}

	// A failed row for a command outside the registry does not count.
	one := 1
	withStray := append(append([]cronJobStatus{}, statuses...), cronJobStatus{Command: "retired:job2", LastRunAt: &fresh, LastExitCode: &one})
	if got := countCronJobsNeedingAttention(withStray, now); got != 0 {
		t.Errorf("stray failed row: got %d, want 0", got)
	}

	// Failed, overdue and never run are each counted once.
	old := now.Add(-100 * 24 * time.Hour)
	var active []int
	for i, j := range cronJobs {
		if j.Active {
			active = append(active, i)
		}
	}
	statuses[active[0]].LastExitCode = &one
	statuses[active[1]].LastRunAt = &old
	statuses[active[2]].LastRunAt = nil
	if got := countCronJobsNeedingAttention(statuses, now); got != 3 {
		t.Errorf("failed+overdue+never: got %d, want 3", got)
	}
}

func TestCronJobsIncludeHostPatchFreshness(t *testing.T) {
	for _, host := range []string{"db1", "db2", "db3", "bulk2", "ha", "docker"} {
		found := false
		for _, j := range cronJobs {
			if j.Command == "host-patch "+host {
				found = true
				if j.Category != "Host Maintenance" || !j.Active {
					t.Errorf("host-patch %s: category %q active %v", host, j.Category, j.Active)
				}
			}
		}
		if !found {
			t.Errorf("host-patch %s missing from the cron registry", host)
		}
	}
}

// A stale WhatJobs feed turns the Cron Jobs tab red: the check fails, and the tab counts a
// failed last run of any active entry.
func TestCronJobsIncludeWhatJobsFeedFreshness(t *testing.T) {
	for _, j := range cronJobs {
		if j.Command == "integrations:check-whatjobs-freshness" {
			if !j.Active || j.IntervalMinutes != 60 {
				t.Errorf("WhatJobs freshness: active %v interval %d", j.Active, j.IntervalMinutes)
			}
			return
		}
	}
	t.Error("integrations:check-whatjobs-freshness missing from the cron registry")
}
