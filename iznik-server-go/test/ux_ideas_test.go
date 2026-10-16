package test

import (
	"context"
	"fmt"
	"net/http/httptest"
	"os"
	"regexp"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	gormlogger "gorm.io/gorm/logger"
)

// The ideas side of the UX read API: the backlog the idea generator fills, with the experiment each
// idea became and the member problem it answers. The experiment table belongs to the experiment engine
// and the problem table to the member surveys; an idea works with either missing.

// uxIdeaRow is an idea as the tests insert it.
type uxIdeaRow struct {
	ID           uint64     `gorm:"column:id;primaryKey;autoIncrement"`
	Flow         string     `gorm:"column:flow"`
	Title        string     `gorm:"column:title"`
	Rationale    string     `gorm:"column:rationale"`
	Expected     string     `gorm:"column:expected"`
	Evidence     *string    `gorm:"column:evidence"`
	Score        float64    `gorm:"column:score"`
	Status       string     `gorm:"column:status"`
	Type         string     `gorm:"column:type"`
	Source       string     `gorm:"column:source"`
	ProblemID    *uint64    `gorm:"column:problem_id"`
	ProblemTitle *string    `gorm:"column:problem_title"`
	ExperimentID *uint64    `gorm:"column:experiment_id"`
	Attempts     int        `gorm:"column:attempts"`
	CreatedAt    *time.Time `gorm:"column:created_at"`
}

func (uxIdeaRow) TableName() string { return "ux_ideas" }

// uxIdeasClean empties the ideas table before the test and after it. Nothing else writes the table in
// this package, so the totals a test reads are its own. Rows a test made in the experiment and problem
// tables are named with the prefix and removed with it.
func uxIdeasClean(t *testing.T, prefix string) {
	t.Helper()
	cleanup := func() {
		db := database.DBConn
		db.Exec("DELETE FROM ux_ideas")
		db.Exec("DELETE FROM ux_experiments WHERE name LIKE ?", prefix+"%")
		if uxIdeaTableExists("ux_problems") {
			db.Exec("DELETE FROM ux_problems WHERE title LIKE ?", prefix+"%")
		}
	}
	cleanup()
	t.Cleanup(cleanup)
}

func uxIdeaTableExists(table string) bool {
	var names []string
	database.DBConn.Table("information_schema.tables").Select("table_name").
		Where("table_schema = DATABASE() AND table_name = ?", table).Scan(&names)
	return len(names) > 0
}

// uxEnsureSurveyTables makes the member survey tables when they are missing, so the join to a problem
// can be tested without the surveys. The statements are those of the surveys' production SQL file. Once
// the surveys are merged the tables are there and this changes nothing. Go tests do not run in a
// rolled-back transaction, so the DDL here is harmless.
func uxEnsureSurveyTables(t *testing.T) {
	t.Helper()
	for _, statement := range []string{
		"CREATE TABLE IF NOT EXISTS `ux_prompts` (" +
			"`id` bigint unsigned NOT NULL AUTO_INCREMENT, `kind` varchar(16) NOT NULL, `flow` varchar(32) DEFAULT NULL, " +
			"`question` varchar(255) NOT NULL, `body` text NOT NULL, `options` json NOT NULL, `images` json DEFAULT NULL, " +
			"`status` varchar(16) NOT NULL DEFAULT 'open', `max_responses` int unsigned NOT NULL DEFAULT '200', `settings` json DEFAULT NULL, " +
			"`created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, " +
			"PRIMARY KEY (`id`), KEY `ux_prompts_status_kind` (`status`,`kind`)" +
			") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
		"CREATE TABLE IF NOT EXISTS `ux_problems` (" +
			"`id` bigint unsigned NOT NULL AUTO_INCREMENT, `flow` varchar(32) DEFAULT NULL, `kind` varchar(16) NOT NULL DEFAULT 'problem', " +
			"`group_name` varchar(40) DEFAULT NULL, `title` varchar(160) NOT NULL, `description` text, `prompt_id` bigint unsigned DEFAULT NULL, " +
			"`parent_id` bigint unsigned DEFAULT NULL, `mentions` int unsigned NOT NULL DEFAULT '0', `weight` decimal(6,3) DEFAULT NULL, " +
			"`evidence` json DEFAULT NULL, `status` varchar(16) NOT NULL DEFAULT 'open', " +
			"`created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, " +
			"PRIMARY KEY (`id`), KEY `ux_problems_flow_status` (`flow`,`status`), KEY `ux_problems_prompt` (`prompt_id`), " +
			"KEY `ux_problems_group` (`kind`,`group_name`,`status`)" +
			") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
		"CREATE TABLE IF NOT EXISTS `ux_prompt_responses` (" +
			"`id` bigint unsigned NOT NULL AUTO_INCREMENT, `prompt_id` bigint unsigned NOT NULL, `userid` bigint unsigned NOT NULL, " +
			"`answer` json NOT NULL, `comment` text, `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, " +
			"PRIMARY KEY (`id`), UNIQUE KEY `ux_prompt_responses_once` (`prompt_id`,`userid`), KEY `ux_prompt_responses_user_time` (`userid`,`timestamp`)" +
			") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
	} {
		require.NoError(t, database.DBConn.Exec(statement).Error)
	}
}

func uxInsertIdea(t *testing.T, flow, title, status string, score float64) uxIdeaRow {
	t.Helper()
	now := time.Now().UTC()
	row := uxIdeaRow{
		Flow: flow, Title: title, Rationale: "People stall at photos. " + title, Expected: "More people finish.",
		Score: score, Status: status, Type: "experiment", Source: "ai", CreatedAt: &now,
	}
	require.NoError(t, database.DBConn.Create(&row).Error)
	return row
}

func uxIdeaExperiment(t *testing.T, name, flow, status string) uint64 {
	t.Helper()
	type experimentInsert struct {
		ID                uint64 `gorm:"column:id;primaryKey;autoIncrement"`
		Flow              string `gorm:"column:flow"`
		Name              string `gorm:"column:name"`
		Title             string `gorm:"column:title"`
		Hypothesis        string `gorm:"column:hypothesis"`
		ChangeDescription string `gorm:"column:change_description"`
		Expected          string `gorm:"column:expected"`
		Arms              string `gorm:"column:arms"`
		Status            string `gorm:"column:status"`
	}
	row := experimentInsert{Flow: flow, Name: name, Title: name + " title", Hypothesis: "h", ChangeDescription: "c", Expected: "e",
		Arms: `{"control":[0,49],"b":[50,99]}`, Status: status}
	require.NoError(t, database.DBConn.Table("ux_experiments").Create(&row).Error)
	return row.ID
}

func uxIdeaProblem(t *testing.T, title, kind string) uint64 {
	t.Helper()
	type problemInsert struct {
		ID    uint64 `gorm:"column:id;primaryKey;autoIncrement"`
		Flow  string `gorm:"column:flow"`
		Kind  string `gorm:"column:kind"`
		Title string `gorm:"column:title"`
	}
	row := problemInsert{Flow: "give", Kind: kind, Title: title}
	require.NoError(t, database.DBConn.Table("ux_problems").Create(&row).Error)
	return row.ID
}

func uxIdeasFor(t *testing.T, token, query string) ux.IdeasResponse {
	t.Helper()
	path := "ideas"
	if query != "" {
		path += "?" + query
	}
	var resp ux.IdeasResponse
	require.Equal(t, 200, uxGet(t, token, path, &resp), path)
	return resp
}

func uxIdeaTitles(ideas []ux.Idea) []string {
	out := []string{}
	for _, i := range ideas {
		out = append(out, i.Title)
	}
	return out
}

func TestUxIdeasRequireSupport(t *testing.T) {
	prefix := uniquePrefix("uxideareq")
	uxIdeasClean(t, prefix)
	uxInsertIdea(t, "give", prefix+" a secret idea", "open", 3)
	userID := CreateTestUser(t, uniquePrefix("uxideauser"), "User")
	_, userToken := CreateTestSession(t, userID)

	assert.Equal(t, 401, uxGet(t, "", "ideas", nil))
	assert.Equal(t, 403, uxGet(t, userToken, "ideas", nil))

	// A refusal that still carried the ideas would pass a test of the status alone.
	for _, token := range []string{"", userToken} {
		url := "/api/modtools/ux/ideas"
		if token != "" {
			url += "?jwt=" + token
		}
		resp, err := getApp().Test(httptest.NewRequest("GET", url, nil), 30000)
		require.NoError(t, err)
		body := string(rsp(resp))
		assert.NotContains(t, body, "a secret idea")
		assert.NotContains(t, body, `"ideas"`)
	}

	supportToken := uxSupportToken(t, "uxideasup")
	assert.Equal(t, 200, uxGet(t, supportToken, "ideas", nil))
}

func TestUxIdeasCountsAndOrder(t *testing.T) {
	prefix := uniquePrefix("uxideacount")
	uxIdeasClean(t, prefix)
	token := uxSupportToken(t, "uxideacnt")

	low := uxInsertIdea(t, "give", prefix+" low", "open", 1.5)
	high := uxInsertIdea(t, "give", prefix+" high", "open", 8.25)
	tieA := uxInsertIdea(t, "give", prefix+" tie a", "open", 4)
	tieB := uxInsertIdea(t, "give", prefix+" tie b", "open", 4)
	ask := uxInsertIdea(t, "ask", prefix+" ask", "open", 6)
	uxInsertIdea(t, "ask", prefix+" ask queued", "scheduled", 9)
	uxInsertIdea(t, "reply", prefix+" reply running", "running", 2)
	uxInsertIdea(t, "login", prefix+" login done", "done", 2)
	uxInsertIdea(t, "login", prefix+" login done 2", "done", 3)
	uxInsertIdea(t, "settings", prefix+" settings set aside", "rejected", 1)

	resp := uxIdeasFor(t, token, "")

	assert.Equal(t, map[string]int64{"open": 5, "scheduled": 1, "running": 1, "done": 2, "rejected": 1}, resp.Counts)
	// Best score first, and the lower id first among equals.
	assert.Equal(t, []string{
		prefix + " ask queued", prefix + " high", prefix + " ask", prefix + " tie a", prefix + " tie b",
		prefix + " login done 2", prefix + " reply running", prefix + " login done", prefix + " low", prefix + " settings set aside",
	}, uxIdeaTitles(resp.Ideas))
	assert.Less(t, tieA.ID, tieB.ID)

	// One entry for each funnel, in the order members meet them, with the number waiting and the best.
	require.Len(t, resp.Flows, 7)
	names := []string{}
	for _, f := range resp.Flows {
		names = append(names, f.Flow)
	}
	assert.Equal(t, []string{"give", "ask", "reply", "chat_promise", "chat_outcome", "login", "settings"}, names)
	give, askFlow, reply := resp.Flows[0], resp.Flows[1], resp.Flows[2]
	assert.Equal(t, int64(4), give.Open)
	require.NotNil(t, give.Best)
	assert.Equal(t, high.ID, give.Best.ID)
	assert.Equal(t, prefix+" high", give.Best.Title)
	assert.InDelta(t, 8.25, give.Best.Score, 0.001)
	assert.Equal(t, int64(1), askFlow.Open, "a queued idea is not waiting")
	assert.Equal(t, ask.ID, askFlow.Best.ID)
	assert.Equal(t, int64(0), reply.Open)
	assert.Nil(t, reply.Best, "a funnel with nothing waiting has no best idea")
	assert.NotZero(t, low.ID)

	// The totals and the funnel summary are over every idea, whatever the filters.
	filtered := uxIdeasFor(t, token, "flow=login&status=done")
	assert.Equal(t, resp.Counts, filtered.Counts)
	assert.Equal(t, resp.Flows, filtered.Flows)
	assert.Equal(t, []string{prefix + " login done 2", prefix + " login done"}, uxIdeaTitles(filtered.Ideas))
}

func TestUxIdeasFilters(t *testing.T) {
	prefix := uniquePrefix("uxideafilt")
	uxIdeasClean(t, prefix)
	uxEnsureSurveyTables(t)
	token := uxSupportToken(t, "uxideaflt")

	problem := uxIdeaProblem(t, prefix+" a problem", "problem")
	fix := uxInsertIdea(t, "give", prefix+" fix", "open", 5)
	database.DBConn.Exec("UPDATE ux_ideas SET type = 'fix', problem_id = ? WHERE id = ?", problem, fix.ID)
	uxInsertIdea(t, "give", prefix+" experiment", "open", 4)
	uxInsertIdea(t, "give", prefix+" running", "running", 3)
	uxInsertIdea(t, "ask", prefix+" ask", "open", 2)

	assert.Equal(t, []string{prefix + " fix", prefix + " experiment", prefix + " running"}, uxIdeaTitles(uxIdeasFor(t, token, "flow=give").Ideas))
	assert.Equal(t, []string{prefix + " fix", prefix + " experiment", prefix + " ask"}, uxIdeaTitles(uxIdeasFor(t, token, "status=open").Ideas))
	assert.Equal(t, []string{prefix + " fix"}, uxIdeaTitles(uxIdeasFor(t, token, "type=fix").Ideas))
	assert.Equal(t, []string{prefix + " experiment", prefix + " running", prefix + " ask"}, uxIdeaTitles(uxIdeasFor(t, token, "type=experiment").Ideas))
	assert.Equal(t, []string{prefix + " fix"}, uxIdeaTitles(uxIdeasFor(t, token, fmt.Sprintf("problem_id=%d", problem)).Ideas))
	assert.Equal(t, []string{prefix + " experiment"}, uxIdeaTitles(uxIdeasFor(t, token, "flow=give&status=open&type=experiment").Ideas))
	assert.Empty(t, uxIdeasFor(t, token, "problem_id=999999999").Ideas)
	assert.Equal(t, []string{prefix + " fix"}, uxIdeaTitles(uxIdeasFor(t, token, "limit=1").Ideas))
	assert.Len(t, uxIdeasFor(t, token, "limit=100000").Ideas, 4, "a limit above the most allowed is cut, not refused")

	for _, bad := range []string{
		"status=waiting", "status=OPEN", "type=bug", "problem_id=abc", "problem_id=0", "problem_id=-1",
		"limit=0", "limit=-5", "limit=many", "flow=a%20b",
	} {
		assert.Equal(t, 400, uxGet(t, token, "ideas?"+bad, nil), bad)
	}
}

func TestUxIdeasJoinTheExperimentLive(t *testing.T) {
	prefix := uniquePrefix("uxidealive")
	uxIdeasClean(t, prefix)
	token := uxSupportToken(t, "uxidealv")

	expID := uxIdeaExperiment(t, prefix+"-skip", "give", "running")
	queued := uxInsertIdea(t, "give", prefix+" queued", "scheduled", 4)
	gone := uxInsertIdea(t, "give", prefix+" gone", "scheduled", 3)
	plain := uxInsertIdea(t, "give", prefix+" plain", "open", 2)
	missing := uint64(987654321)
	database.DBConn.Exec("UPDATE ux_ideas SET experiment_id = ? WHERE id = ?", expID, queued.ID)
	database.DBConn.Exec("UPDATE ux_ideas SET experiment_id = ? WHERE id = ?", missing, gone.ID)

	byID := func(resp ux.IdeasResponse) map[uint64]ux.Idea {
		out := map[uint64]ux.Idea{}
		for _, i := range resp.Ideas {
			out[i.ID] = i
		}
		return out
	}

	// The idea row still says scheduled; the experiment is running, and the answer says so.
	ideas := byID(uxIdeasFor(t, token, ""))
	require.NotNil(t, ideas[queued.ID].Experiment)
	assert.Equal(t, "scheduled", ideas[queued.ID].Status)
	assert.Equal(t, expID, ideas[queued.ID].Experiment.ID)
	assert.Equal(t, prefix+"-skip", ideas[queued.ID].Experiment.Name)
	assert.Equal(t, prefix+"-skip title", ideas[queued.ID].Experiment.Title)
	assert.Equal(t, "running", ideas[queued.ID].Experiment.Status)
	assert.Nil(t, ideas[gone.ID].Experiment, "an experiment that is gone gives none")
	assert.Nil(t, ideas[plain.ID].Experiment)

	// Not cached: the next read sees the experiment's end at once, before the hourly sync has run.
	database.DBConn.Exec("UPDATE ux_experiments SET status = 'adopted' WHERE id = ?", expID)
	ideas = byID(uxIdeasFor(t, token, ""))
	assert.Equal(t, "adopted", ideas[queued.ID].Experiment.Status)
	assert.Equal(t, "scheduled", ideas[queued.ID].Status, "the idea row is the batch's to change")
}

func TestUxIdeasJoinTheMemberProblem(t *testing.T) {
	prefix := uniquePrefix("uxideaprob")
	uxIdeasClean(t, prefix)
	uxEnsureSurveyTables(t)
	token := uxSupportToken(t, "uxideapr")

	problem := uxIdeaProblem(t, prefix+" Photos will not upload", "problem")
	tied := uxInsertIdea(t, "give", prefix+" tied", "open", 4)
	own := uxInsertIdea(t, "give", prefix+" own problem", "open", 3)
	none := uxInsertIdea(t, "give", prefix+" none", "open", 2)
	database.DBConn.Exec("UPDATE ux_ideas SET problem_id = ?, problem_title = ? WHERE id = ?", problem, prefix+" Photos will not upload", tied.ID)
	database.DBConn.Exec("UPDATE ux_ideas SET problem_title = ? WHERE id = ?", "The generator found this", own.ID)
	database.DBConn.Exec("UPDATE ux_ideas SET evidence = ? WHERE id = ?", `{"category":"copy","change":"A plain link.","problem":{"where":"Photos step","source":"code"}}`, own.ID)

	resp := uxIdeasFor(t, token, "")
	byTitle := map[string]ux.Idea{}
	for _, i := range resp.Ideas {
		byTitle[i.Title] = i
	}

	tiedIdea := byTitle[prefix+" tied"]
	require.NotNil(t, tiedIdea.Problem)
	assert.Equal(t, problem, tiedIdea.Problem.ID)
	assert.Equal(t, prefix+" Photos will not upload", tiedIdea.Problem.Title)
	require.NotNil(t, tiedIdea.ProblemID)
	assert.Equal(t, problem, *tiedIdea.ProblemID)

	// An idea the generator found a problem for, with no member problem behind it.
	ownIdea := byTitle[prefix+" own problem"]
	assert.Nil(t, ownIdea.Problem)
	assert.Nil(t, ownIdea.ProblemID)
	require.NotNil(t, ownIdea.ProblemTitle)
	assert.Equal(t, "The generator found this", *ownIdea.ProblemTitle)
	assert.JSONEq(t, `{"category":"copy","change":"A plain link.","problem":{"where":"Photos step","source":"code"}}`, string(ownIdea.Evidence))

	noneIdea := byTitle[prefix+" none"]
	assert.Nil(t, noneIdea.Problem)
	assert.Nil(t, noneIdea.ProblemTitle)
	assert.Empty(t, noneIdea.Evidence)
	assert.NotNil(t, noneIdea.CreatedAt)
	assert.Equal(t, "ai", noneIdea.Source)
	assert.NotZero(t, none.ID)
}

func TestUxIdeasWithoutTheProblemTableIsNotAnError(t *testing.T) {
	prefix := uniquePrefix("uxideanotbl")
	uxIdeasClean(t, prefix)
	uxEnsureSurveyTables(t)
	token := uxSupportToken(t, "uxideant")

	problem := uxIdeaProblem(t, prefix+" a problem", "problem")
	expID := uxIdeaExperiment(t, prefix+"-exp", "give", "running")
	idea := uxInsertIdea(t, "give", prefix+" idea", "scheduled", 4)
	database.DBConn.Exec("UPDATE ux_ideas SET problem_id = ?, problem_title = ?, experiment_id = ? WHERE id = ?", problem, "The title the idea kept", expID, idea.ID)

	// With both tables there, both joins are made.
	with := uxIdeasFor(t, token, "")
	require.Len(t, with.Ideas, 1)
	assert.NotNil(t, with.Ideas[0].Problem)
	assert.NotNil(t, with.Ideas[0].Experiment)

	// With neither, the idea is still listed, with the title it kept and no join.
	asked := []string{}
	restore := ux.SetTableCheck(func(table string) bool {
		asked = append(asked, table)
		// The ideas table is the one this route is about, and it is there.
		return table == "ux_ideas"
	})
	defer restore()

	without := uxIdeasFor(t, token, "")
	require.Len(t, without.Ideas, 1)
	assert.Nil(t, without.Ideas[0].Problem, "no problem table, no problem")
	assert.Nil(t, without.Ideas[0].Experiment, "no experiment table, no experiment")
	require.NotNil(t, without.Ideas[0].ProblemTitle)
	assert.Equal(t, "The title the idea kept", *without.Ideas[0].ProblemTitle)
	assert.Equal(t, without.Counts, with.Counts)
	assert.ElementsMatch(t, []string{"ux_ideas", "ux_experiments", "ux_problems"}, asked)

	// A filter on the problem needs no problem table either: the id is on the idea.
	assert.Len(t, uxIdeasFor(t, token, fmt.Sprintf("problem_id=%d", problem)).Ideas, 1)

	// Putting the real question back brings the joins back.
	restore()
	back := uxIdeasFor(t, token, "")
	assert.NotNil(t, back.Ideas[0].Problem)
	assert.NotNil(t, back.Ideas[0].Experiment)
}

func TestUxIdeasRememberWhetherATableExistsForAMinute(t *testing.T) {
	prefix := uniquePrefix("uxideamem")
	uxIdeasClean(t, prefix)
	token := uxSupportToken(t, "uxideamm")

	expID := uxIdeaExperiment(t, prefix+"-exp", "give", "running")
	idea := uxInsertIdea(t, "give", prefix+" idea", "scheduled", 4)
	database.DBConn.Exec("UPDATE ux_ideas SET experiment_id = ? WHERE id = ?", expID, idea.ID)

	asked := map[string]int{}
	restore := ux.SetTableCheck(func(table string) bool {
		asked[table]++
		return true
	})
	defer restore()
	// A clock the test moves, so the minute is passed and not waited for.
	now := time.Date(2031, 3, 1, 12, 0, 0, 0, time.UTC)
	restoreClock := ux.SetTableClock(func() time.Time { return now })
	defer restoreClock()

	for i := 0; i < 3; i++ {
		resp := uxIdeasFor(t, token, "")
		require.NotNil(t, resp.Ideas[0].Experiment)
	}
	assert.Equal(t, map[string]int{"ux_ideas": 1, "ux_experiments": 1}, asked, "each table was asked about once, however many requests came")

	now = now.Add(59 * time.Second)
	uxIdeasFor(t, token, "")
	assert.Equal(t, map[string]int{"ux_ideas": 1, "ux_experiments": 1}, asked, "still remembered 59 seconds on")

	now = now.Add(2 * time.Second)
	uxIdeasFor(t, token, "")
	assert.Equal(t, map[string]int{"ux_ideas": 2, "ux_experiments": 2}, asked, "asked again once the minute is up, and not before")
}

// Without the ideas table the route is not switched on, as for a route that is not there yet. It must not
// be a database error: the section tells a 500 from a 404 or 501 and only the last two say "not switched on".
func TestUxIdeasWithoutTheIdeasTableIsNotSwitchedOn(t *testing.T) {
	prefix := uniquePrefix("uxideano")
	uxIdeasClean(t, prefix)
	token := uxSupportToken(t, "uxideano")
	uxInsertIdea(t, "give", prefix+" an idea", "open", 4)

	asked := map[string]int{}
	restore := ux.SetTableCheck(func(table string) bool {
		asked[table]++
		return table != "ux_ideas"
	})
	defer restore()

	// Every shape the section asks with: the line for each funnel, a list, and the ideas of one problem.
	for _, query := range []string{"", "status=open&limit=1", "status=done", "problem_id=1", "flow=give&type=fix"} {
		assert.Equal(t, 501, uxGet(t, token, "ideas?"+query, nil), query)
	}
	assert.Equal(t, 1, asked["ux_ideas"], "the table was asked about once, however many requests came")

	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/modtools/ux/ideas?jwt="+token, nil), 30000)
	require.NoError(t, err)
	body := string(rsp(resp))
	assert.Equal(t, 501, resp.StatusCode)
	assert.Contains(t, body, "not switched on")
	assert.NotContains(t, body, "an idea", "no ideas are sent")

	// Who may ask is settled first, so nobody learns from this whether the table is there.
	userID := CreateTestUser(t, uniquePrefix("uxideanou"), "User")
	_, userToken := CreateTestSession(t, userID)
	assert.Equal(t, 401, uxGet(t, "", "ideas", nil))
	assert.Equal(t, 403, uxGet(t, userToken, "ideas", nil))

	// With the table there again, the same request is answered.
	restore()
	assert.Equal(t, 200, uxGet(t, token, "ideas", nil))
}

func TestUxIdeasHaveNoMemberSuggestions(t *testing.T) {
	prefix := uniquePrefix("uxideasug")
	uxIdeasClean(t, prefix)
	uxEnsureSurveyTables(t)
	token := uxSupportToken(t, "uxideasg")

	// The surveys file a member's suggested fix as a problem row of kind suggestion. It is shown inside
	// its problem, and it is not an idea.
	uxIdeaProblem(t, prefix+" Add a skip button for photos", "suggestion")
	uxIdeaProblem(t, prefix+" Other", "other")
	uxInsertIdea(t, "give", prefix+" the generator's idea", "open", 4)

	resp := uxIdeasFor(t, token, "")

	assert.Equal(t, []string{prefix + " the generator's idea"}, uxIdeaTitles(resp.Ideas))
	assert.Equal(t, int64(1), resp.Counts["open"])
	for _, i := range resp.Ideas {
		assert.Equal(t, "ai", i.Source)
	}
}

// ideas.go reads the experiment and problem tables and writes none of them. Only ux_ideas is written,
// and by the batch, not here.
func TestUxIdeasBoundary(t *testing.T) {
	files := []string{"../ux/ideas.go"}
	other := []string{"ux_experiments", "ux_experiment_reads", "ux_prompts", "ux_prompt_responses", "ux_problems"}
	write := regexp.MustCompile(`\.(Create|Update|Updates|Delete|Exec|Save|Raw)\(|dbresolver\.Write`)

	offences := func(text string) []string {
		bad := []string{}
		for _, line := range strings.Split(text, "\n") {
			if strings.HasPrefix(strings.TrimSpace(line), "//") {
				continue
			}
			for _, name := range other {
				if strings.Contains(line, name) && write.MatchString(line) {
					bad = append(bad, strings.TrimSpace(line))
				}
			}
		}
		return bad
	}

	// The check sees a write to another part's table and lets a read pass.
	assert.NotEmpty(t, offences(`database.DBConn.Table("ux_problems").Create(&row)`))
	assert.NotEmpty(t, offences(`db.Exec("DELETE FROM ux_experiments WHERE id = ?", id)`))
	assert.NotEmpty(t, offences(`db.Table("ux_prompts").Where("id = ?", 1).Updates(map[string]any{})`))
	assert.Empty(t, offences(`database.DBConn.Table("ux_experiments").Select("id, name").Where("id IN ?", ids).Scan(&found)`))
	assert.Empty(t, offences(`// db.Exec("DELETE FROM ux_problems")`))

	read := 0
	for _, f := range files {
		b, err := os.ReadFile(f)
		require.NoError(t, err, f)
		require.NotEmpty(t, b, f+" is empty")
		read++
		assert.Empty(t, offences(string(b)), f+" writes a table that belongs to another part")
	}
	require.Len(t, files, 1)
	assert.Equal(t, 1, read, "the file was read")
}

// uxInsertManyIdeas adds n waiting ideas of one score, in one funnel, and returns their ids in order.
func uxInsertManyIdeas(t *testing.T, n int, score float64, titlePrefix string) []uint64 {
	t.Helper()
	now := time.Now().UTC()
	rows := make([]uxIdeaRow, 0, n)
	for i := 0; i < n; i++ {
		rows = append(rows, uxIdeaRow{
			Flow: "give", Title: fmt.Sprintf("%s %04d", titlePrefix, i), Rationale: "r", Expected: "e",
			Score: score, Status: "open", Type: "experiment", Source: "ai", CreatedAt: &now,
		})
	}
	require.NoError(t, database.DBConn.CreateInBatches(&rows, 100).Error)
	ids := make([]uint64, 0, n)
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	return ids
}

// The most that can be asked for is 500, whatever number is sent, and 200 when none is.
func TestUxIdeasAskedForMoreThanTheMostAreCutToFiveHundred(t *testing.T) {
	prefix := uniquePrefix("uxideacap")
	uxIdeasClean(t, prefix)
	token := uxSupportToken(t, "uxideacap")
	ids := uxInsertManyIdeas(t, 501, 3, prefix)

	resp := uxIdeasFor(t, token, "limit=100000")
	assert.Len(t, resp.Ideas, 500, "a limit above the most allowed is cut to 500")
	assert.Equal(t, ids[:500], uxIdeaIDs(resp.Ideas), "the first 500 of them")
	assert.Len(t, uxIdeasFor(t, token, "limit=500").Ideas, 500)
	assert.Len(t, uxIdeasFor(t, token, "").Ideas, 200, "200 when no limit is sent")
	assert.Equal(t, int64(501), resp.Counts["open"], "the totals still count every idea")
	assert.Equal(t, int64(501), resp.Matching, "and so does the count of those that match")
}

func uxIdeaIDs(ideas []ux.Idea) []uint64 {
	out := []uint64{}
	for _, i := range ideas {
		out = append(out, i.ID)
	}
	return out
}

// Ideas with one score come back with the lower id first. InnoDB returns them in id order whether or not
// the query says so, so the order cannot be seen in the data: it is checked in the query that is sent.
type uxIdeasSQLRecorder struct {
	gormlogger.Interface
	mu  sync.Mutex
	sql []string
}

func (r *uxIdeasSQLRecorder) Trace(ctx context.Context, begin time.Time, fc func() (string, int64), err error) {
	s, _ := fc()
	r.mu.Lock()
	r.sql = append(r.sql, s)
	r.mu.Unlock()
	r.Interface.Trace(ctx, begin, fc, err)
}

func TestUxIdeasEqualScoresAreOrderedByIDInTheQuery(t *testing.T) {
	prefix := uniquePrefix("uxideatie")
	uxIdeasClean(t, prefix)
	token := uxSupportToken(t, "uxideatie")
	ids := uxInsertManyIdeas(t, 300, 4, prefix)

	original := database.DBConn.Config.Logger
	rec := &uxIdeasSQLRecorder{Interface: original}
	database.DBConn.Config.Logger = rec
	resp := uxIdeasFor(t, token, "")
	database.DBConn.Config.Logger = original

	// 300 with one score and a limit of 200: the 200 lowest ids, lowest first, and the lowest is the best.
	assert.Equal(t, ids[:200], uxIdeaIDs(resp.Ideas))
	require.NotNil(t, resp.Flows[0].Best)
	assert.Equal(t, ids[0], resp.Flows[0].Best.ID)

	rec.mu.Lock()
	defer rec.mu.Unlock()
	ordered := 0
	for _, s := range rec.sql {
		if strings.Contains(s, "FROM `ux_ideas`") && strings.Contains(s, "ORDER BY") {
			ordered++
			assert.Contains(t, s, "ORDER BY score DESC, id ASC", "every ordered read of the backlog breaks a tie by id")
		}
	}
	assert.Equal(t, 2, ordered, "the read for the funnel summary and the read for the list are both ordered")
}

// Matching is how many ideas match the filters, before the limit cuts the list, so the section can say that
// what it shows is the first of more. Counts and Flows are over every idea whatever the filters.
func TestUxIdeasSayHowManyMatchBeforeTheLimitCutsTheList(t *testing.T) {
	prefix := uniquePrefix("uxideamat")
	uxIdeasClean(t, prefix)
	uxEnsureSurveyTables(t)
	token := uxSupportToken(t, "uxideamat")

	problem := uxIdeaProblem(t, prefix+" a problem", "problem")
	fix := uxInsertIdea(t, "give", prefix+" fix", "open", 5)
	database.DBConn.Exec("UPDATE ux_ideas SET type = 'fix', problem_id = ? WHERE id = ?", problem, fix.ID)
	uxInsertIdea(t, "give", prefix+" experiment", "open", 4)
	uxInsertIdea(t, "give", prefix+" tried", "done", 3)
	uxInsertIdea(t, "ask", prefix+" ask", "open", 2)
	uxInsertIdea(t, "ask", prefix+" ask tried", "done", 1)

	cases := []struct {
		query    string
		shown    int
		matching int64
	}{
		{"", 5, 5},
		{"limit=2", 2, 5},
		{"limit=1&status=open", 1, 3},
		{"status=done", 2, 2},
		{"flow=ask", 2, 2},
		{"flow=give&limit=1", 1, 3},
		{"flow=give&status=open&type=experiment", 1, 1},
		{fmt.Sprintf("problem_id=%d&limit=5", problem), 1, 1},
		{"problem_id=999999999", 0, 0},
	}
	for _, c := range cases {
		resp := uxIdeasFor(t, token, c.query)
		assert.Len(t, resp.Ideas, c.shown, c.query)
		assert.Equal(t, c.matching, resp.Matching, c.query)
		assert.Equal(t, int64(5), resp.Counts["open"]+resp.Counts["done"], c.query+": the totals are over every idea")
	}
}

// The holdout of an adopted experiment is worked out twice, once by the experiments route and once for the
// experiment an idea became. They must give one answer, or the two sections word the same experiment
// differently.
func TestUxIdeasGiveTheExperimentTheHoldoutTheExperimentsRouteGives(t *testing.T) {
	prefix := uniquePrefix("uxideahold")
	uxIdeasClean(t, prefix)
	token := uxSupportToken(t, "uxideahold")

	fresh := uxIdeaExperiment(t, prefix+"-fresh", "give", "adopted")
	old := uxIdeaExperiment(t, prefix+"-old", "give", "adopted")
	running := uxIdeaExperiment(t, prefix+"-running", "give", "running")
	undecided := uxIdeaExperiment(t, prefix+"-undecided", "give", "adopted")
	now := time.Now().UTC()
	database.DBConn.Exec("UPDATE ux_experiments SET decided_at = ? WHERE id = ?", now.Add(-time.Hour), fresh)
	database.DBConn.Exec("UPDATE ux_experiments SET decided_at = ? WHERE id = ?", now.Add(-20*24*time.Hour), old)
	database.DBConn.Exec("UPDATE ux_experiments SET decided_at = ? WHERE id = ?", now.Add(-time.Hour), running)

	experiments := []uint64{fresh, old, running, undecided}
	for i, id := range experiments {
		idea := uxInsertIdea(t, "give", fmt.Sprintf("%s idea %d", prefix, i), "done", float64(10-i))
		database.DBConn.Exec("UPDATE ux_ideas SET experiment_id = ? WHERE id = ?", id, idea.ID)
	}

	var fromExperiments ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &fromExperiments))
	holdout := map[uint64]*string{}
	for _, flow := range fromExperiments.Flows {
		for _, e := range append(append([]ux.Experiment{}, flow.Running...), flow.History...) {
			holdout[e.ID] = e.HoldoutUntil
		}
	}

	for _, idea := range uxIdeasFor(t, token, "").Ideas {
		require.NotNil(t, idea.Experiment, idea.Title)
		got := idea.Experiment.HoldoutUntil
		want, found := holdout[idea.Experiment.ID]
		require.True(t, found, "the experiments route lists "+idea.Experiment.Name)
		if want == nil {
			assert.Nil(t, got, idea.Experiment.Name+" is outside its holdout")
		} else if assert.NotNil(t, got, idea.Experiment.Name+" is inside its holdout") {
			assert.Equal(t, *want, *got, idea.Experiment.Name)
		}
	}

	// And the answers are the ones the cases are meant to give.
	byName := map[string]*ux.IdeaExperiment{}
	for _, idea := range uxIdeasFor(t, token, "").Ideas {
		byName[idea.Experiment.Name] = idea.Experiment
	}
	require.NotNil(t, byName[prefix+"-fresh"].HoldoutUntil, "adopted an hour ago: inside the holdout")
	until, err := time.Parse("2006-01-02 15:04:05", *byName[prefix+"-fresh"].HoldoutUntil)
	require.NoError(t, err)
	assert.True(t, until.After(now.Add(12*24*time.Hour)), "about 14 days on: "+until.String())
	assert.Nil(t, byName[prefix+"-old"].HoldoutUntil, "adopted 20 days ago: the holdout is over")
	assert.Nil(t, byName[prefix+"-running"].HoldoutUntil, "only an adopted experiment has one")
	assert.Nil(t, byName[prefix+"-undecided"].HoldoutUntil, "adopted with no date: none to count from")
}
