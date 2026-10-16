package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"os"
	"strconv"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The member surveys: the four read routes under /modtools/ux (problem-groups, problems, values and
// questions) and the UxPrompt microvolunteering challenge the member card is drawn from. Every test
// names its rows with a unique prefix and removes them afterwards; the problem routes are asked for
// that prefix with q, so rows left by other tests cannot change what is counted.

// uxPromptClean removes the rows of a test: its prompts, their answers and its problems, which are
// all named with the prefix.
func uxPromptClean(t *testing.T, prefix string) {
	t.Helper()
	db := database.DBConn
	cleanup := func() {
		db.Exec("DELETE r FROM ux_prompt_responses r INNER JOIN ux_prompts p ON p.id = r.prompt_id WHERE p.question LIKE ?", prefix+"%")
		db.Exec("DELETE FROM ux_prompts WHERE question LIKE ?", prefix+"%")
		db.Exec("DELETE FROM ux_problems WHERE title LIKE ?", prefix+"%")
	}
	cleanup()
	t.Cleanup(cleanup)
}

func insertPrompt(t *testing.T, kind, question, options string) uint64 {
	db := database.DBConn
	db.Exec("INSERT INTO ux_prompts (kind, question, body, options, images, status, max_responses, created_at, updated_at) VALUES (?, ?, 'Body text', ?, '[{\"url\":\"https://img/a.png\",\"caption\":\"Now\"}]', 'open', 200, NOW(), NOW())", kind, question, options)
	var id uint64
	db.Raw("SELECT id FROM ux_prompts WHERE question = ?", question).Scan(&id)
	require.NotZero(t, id)
	return id
}

// uxInsertProblem adds a ux_problems row. group and parent are nil for NULL.
func uxInsertProblem(t *testing.T, flow *string, kind string, group interface{}, title string, mentions int, weight interface{}, promptID interface{}, parent interface{}) uint64 {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT INTO ux_problems (flow, kind, group_name, title, description, prompt_id, parent_id, mentions, weight, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'desc', ?, ?, ?, ?, 'open', NOW(), NOW())",
		flow, kind, group, title, promptID, parent, mentions, weight)
	var id uint64
	db.Raw("SELECT id FROM ux_problems WHERE title = ?", title).Scan(&id)
	require.NotZero(t, id)
	return id
}

func uxAnswer(t *testing.T, tok string, promptID uint64, answer string) int {
	t.Helper()
	body := `{"ux_prompt_id":` + strconv.Itoa(int(promptID)) + `,"ux_answer":` + answer + `}`
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	r, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	return r.StatusCode
}

func uxChallenge(t *testing.T, tok string) map[string]interface{} {
	t.Helper()
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+tok+"&types=UxPrompt", nil))
	out := map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &out)
	return out
}

func uxStoredAnswer(t *testing.T, promptID, userID uint64) map[string]interface{} {
	t.Helper()
	var answer string
	database.DBConn.Raw("SELECT answer FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", promptID, userID).Scan(&answer)
	out := map[string]interface{}{}
	require.NoError(t, json.Unmarshal([]byte(answer), &out))
	return out
}

// uxPostMicro sends a microvolunteering answer body as it is, as the given token, and returns the status.
func uxPostMicro(t *testing.T, tok, body string) int {
	t.Helper()
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	r, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	return r.StatusCode
}

// ---- the UxPrompt microvolunteering challenge ----

func TestUxPromptChallengeAndResponse(t *testing.T) {
	prefix := uniquePrefix("uxprompt")
	uxPromptClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxpromptuser"), "User")
	token := getToken(t, userID)

	// Nothing open: no challenge of this type.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	assert.Equal(t, 200, resp.StatusCode)
	var ch map[string]interface{}
	_ = json.Unmarshal(rsp(resp), &ch)
	assert.NotContains(t, ch, "type")

	awID := insertPrompt(t, "awareness", prefix+" do you know how?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`)
	surveyID := insertPrompt(t, "survey", prefix+" best and worst?", `[{"key":"give","label":"Posting"},{"key":"reply","label":"Replying"},{"key":"login","label":"Signing in"}]`)
	// The survey already has one answer, so the awareness question (fewest answers) comes first.
	other := CreateTestUser(t, uniquePrefix("uxpromptother"), "User")
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, '{\"best\":\"give\",\"worst\":\"login\"}')", surveyID, other)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	var challenge microvolunteering.Challenge
	require.NoError(t, json.Unmarshal(rsp(resp), &challenge))
	require.Equal(t, microvolunteering.ChallengeUxPrompt, challenge.Type)
	require.NotNil(t, challenge.UxPrompt)
	assert.Equal(t, awID, challenge.UxPrompt.ID)
	assert.Equal(t, "awareness", challenge.UxPrompt.Kind)
	assert.Len(t, challenge.UxPrompt.Options, 3)

	// An answer that is not one of the options is refused; a good one is stored once.
	id := strconv.Itoa(int(awID))
	assert.Equal(t, 400, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"meh"}}`))
	assert.Equal(t, 400, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`}`))
	assert.Equal(t, 200, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"no"},"comments":"  Too much on one screen  "}`))
	var answer, comment string
	db.Raw("SELECT answer, comment FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", awID, userID).Row().Scan(&answer, &comment)
	assert.JSONEq(t, `{"choice":"no"}`, answer)
	assert.Equal(t, "Too much on one screen", comment)
	// A second answer does not overwrite the first.
	assert.Equal(t, 200, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"yes"}}`))
	db.Raw("SELECT answer FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", awID, userID).Scan(&answer)
	assert.JSONEq(t, `{"choice":"no"}`, answer)

	// Having answered, the member is not asked again, even though the survey is open.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	ch = map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &ch)
	assert.NotContains(t, ch, "type")

	// A survey answer needs best, and worst only from the options; a closed prompt takes nothing.
	otherToken := getToken(t, other)
	third := CreateTestUser(t, uniquePrefix("uxpromptthird"), "User")
	thirdToken := getToken(t, third)
	sid := strconv.Itoa(int(surveyID))
	assert.Equal(t, 400, uxPostMicro(t, thirdToken, `{"ux_prompt_id":`+sid+`,"ux_answer":{"worst":"give"}}`), "worst on its own is not an answer")
	assert.Equal(t, 400, uxPostMicro(t, thirdToken, `{"ux_prompt_id":`+sid+`,"ux_answer":{"best":"reply","worst":"reply"}}`))
	assert.Equal(t, 200, uxPostMicro(t, thirdToken, `{"ux_prompt_id":`+sid+`,"ux_answer":{"best":"reply","worst":"login"}}`))
	fourth := CreateTestUser(t, uniquePrefix("uxpromptfourth"), "User")
	assert.Equal(t, 200, uxPostMicro(t, getToken(t, fourth), `{"ux_prompt_id":`+sid+`,"ux_answer":{"choice":"dont_understand"}}`))
	db.Exec("UPDATE ux_prompts SET status = 'closed' WHERE id = ?", awID)
	assert.Equal(t, 400, uxPostMicro(t, otherToken, `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"yes"}}`))

	// The survey tallies reach the questions read.
	var all ux.QuestionsResponse
	assert.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxpromptsup"), "questions", &all))
	var survey *ux.Survey
	for i := range all.Questions {
		if all.Questions[i].PromptID == surveyID {
			survey = &all.Questions[i]
		}
	}
	require.NotNil(t, survey)
	assert.Equal(t, int64(3), survey.Responses)
	assert.Equal(t, int64(1), survey.DontUnderstand)
	tally := map[string]ux.SurveyTally{}
	for _, tl := range survey.Tallies {
		tally[tl.Key] = tl
	}
	assert.Equal(t, int64(1), tally["give"].Most)
	assert.Equal(t, int64(1), tally["reply"].Most)
	assert.Equal(t, int64(2), tally["login"].Least)

	// The comment on the awareness question is listed as it was stored, trimmed, though the question
	// has since closed.
	var aware *ux.Survey
	for i := range all.Questions {
		if all.Questions[i].PromptID == awID {
			aware = &all.Questions[i]
		}
	}
	require.NotNil(t, aware)
	assert.Equal(t, []string{"Too much on one screen"}, aware.Comments)
}

// The offer refuses Declined and Excluded members and a prompt at its cap; the answer must refuse
// the same, or anyone who can POST can vote in a ranking or push a question past its cap.
func TestUxPromptAnswerIsRefusedWhereTheOfferWouldBe(t *testing.T) {
	prefix := uniquePrefix("uxelig")
	uxPromptClean(t, prefix)
	db := database.DBConn
	options := `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`
	promptID := insertPrompt(t, "awareness", prefix+" do you know how?", options)

	for _, level := range []string{microvolunteering.TrustDeclined, microvolunteering.TrustExcluded} {
		u := CreateTestUser(t, uniquePrefix("uxelig"+level), "User")
		db.Exec("UPDATE users SET trustlevel = ? WHERE id = ?", level, u)
		assert.Equal(t, 403, uxAnswer(t, getToken(t, u), promptID, `{"choice":"yes"}`), level+" members are not asked, so cannot answer")
		var n int64
		db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", promptID, u).Scan(&n)
		assert.Equal(t, int64(0), n)
	}

	// Up to the cap, and no further.
	db.Exec("UPDATE ux_prompts SET max_responses = 2 WHERE id = ?", promptID)
	var codes []int
	for i := 0; i < 3; i++ {
		u := CreateTestUser(t, uniquePrefix("uxeligcap"), "User")
		codes = append(codes, uxAnswer(t, getToken(t, u), promptID, `{"choice":"yes"}`))
	}
	assert.Equal(t, []int{200, 200, 400}, codes)
	var stored int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ?", promptID).Scan(&stored)
	assert.Equal(t, int64(2), stored)

	// A skip is not an answer and is not held to the cap.
	u := CreateTestUser(t, uniquePrefix("uxeligskip"), "User")
	assert.Equal(t, 200, uxAnswer(t, getToken(t, u), promptID, `{"skipped":true}`))
}

func TestUxPromptOpenQuestion(t *testing.T) {
	prefix := uniquePrefix("uxopen")
	uxPromptClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxopenuser"), "User")
	token := getToken(t, userID)

	openID := insertPrompt(t, "open", prefix+" did you have a problem?", `[]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"preset":"problems","randomise":false,"placeholder":"If yes, what happened?"}`, openID)

	prompt := uxChallenge(t, token)["uxprompt"].(map[string]interface{})
	assert.Equal(t, "open", prompt["kind"])
	keys, labels := []string{}, []string{}
	for _, o := range prompt["options"].([]interface{}) {
		keys = append(keys, o.(map[string]interface{})["key"].(string))
		labels = append(labels, o.(map[string]interface{})["label"].(string))
	}
	assert.Equal(t, []string{"nothing", "dont_understand"}, keys, "an open question always offers both ways out")
	assert.Equal(t, []string{"I can't think of one", "I don't understand"}, labels, "the default way out is worded in plain English")
	settings := prompt["settings"].(map[string]interface{})
	assert.Equal(t, "problems", settings["preset"])
	assert.Equal(t, false, settings["randomise"])
	assert.Equal(t, "If yes, what happened?", settings["placeholder"])

	id := strconv.Itoa(int(openID))
	long := strings.Repeat("x", 1001)
	assert.Equal(t, 400, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{}}`))
	assert.Equal(t, 400, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"  ab  "}}`), "two characters after trimming")
	assert.Equal(t, 400, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"`+long+`"}}`))
	assert.Equal(t, 400, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{"choice":"better"}}`))
	var n int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ?", openID).Scan(&n)
	assert.Zero(t, n, "refused answers store nothing")

	assert.Equal(t, 200, uxPostMicro(t, token, `{"ux_prompt_id":`+id+`,"ux_answer":{"text":"  Photos took ages <b>to</b> load\u0007 "},"comments":"ignored"}`))
	var answer string
	var comment *string
	db.Raw("SELECT answer, comment FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", openID, userID).Row().Scan(&answer, &comment)
	var stored map[string]interface{}
	require.NoError(t, json.Unmarshal([]byte(answer), &stored))
	assert.Equal(t, "Photos took ages <b>to</b> load", stored["text"], "trimmed and control characters dropped; markup is kept as data")
	assert.Nil(t, comment, "an open answer is the text, not a comment")

	// Exactly 3 and exactly 1000 characters are allowed; the escapes are answers in their own right.
	for i, body := range []string{`{"text":"abc"}`, `{"text":"` + strings.Repeat("y", 1000) + `"}`, `{"choice":"nothing"}`, `{"choice":"dont_understand"}`, `{"skipped":true}`} {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxopenuser%d", i+2)), "User")
		assert.Equal(t, 200, uxPostMicro(t, getToken(t, u), `{"ux_prompt_id":`+id+`,"ux_answer":`+body+`}`), body[:10])
	}

	// The overview counts them, lists no text, and leaves the skip out of the responses.
	sup := uxSupportToken(t, "uxopensup")
	var all ux.QuestionsResponse
	require.Equal(t, 200, uxGet(t, sup, "questions", &all))
	var s *ux.Survey
	for i := range all.Questions {
		if all.Questions[i].PromptID == openID {
			s = &all.Questions[i]
		}
	}
	require.NotNil(t, s)
	assert.Equal(t, "open", s.Kind)
	assert.Equal(t, int64(5), s.Responses)
	assert.Equal(t, int64(1), s.Nothing)
	assert.Equal(t, int64(1), s.DontUnderstand)
	assert.Equal(t, int64(1), s.Skipped)
	assert.False(t, s.Analysed)
	assert.Empty(t, s.Comments)
	assert.Empty(t, s.Tallies)
	raw, _ := json.Marshal(s)
	assert.NotContains(t, string(raw), "Photos took ages")

	// Once its answers have been turned into problems it reads as analysed.
	uxInsertProblem(t, nil, "problem", "Photos and posting", prefix+" slow photos", 1, nil, openID, nil)
	all = ux.QuestionsResponse{}
	require.Equal(t, 200, uxGet(t, sup, "questions", &all))
	for i := range all.Questions {
		if all.Questions[i].PromptID == openID {
			assert.True(t, all.Questions[i].Analysed)
		}
	}
}

// The settings of a prompt reach the card, except the ones the batch keeps for itself.
func TestUxPromptSurveySettingsPassThrough(t *testing.T) {
	prefix := uniquePrefix("uxset")
	uxPromptClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxsetuser"), "User")
	token := getToken(t, userID)

	id := insertPrompt(t, "survey", prefix+" which part works best?", `[{"key":"give","label":"Offering something"},{"key":"reply","label":"Replying to a post"}]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"show":4,"worst_label":"Which other part most needs improving? (optional)","none_label":"None of these","randomise":true}`, id)
	var challenge microvolunteering.Challenge
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	require.NoError(t, json.Unmarshal(rsp(resp), &challenge))
	require.NotNil(t, challenge.UxPrompt)
	assert.Equal(t, "Which other part most needs improving? (optional)", challenge.UxPrompt.Settings["worst_label"])
	assert.Equal(t, "None of these", challenge.UxPrompt.Settings["none_label"])
	assert.Equal(t, true, challenge.UxPrompt.Settings["randomise"])
	assert.Equal(t, float64(4), challenge.UxPrompt.Settings["show"])
	assert.NotContains(t, challenge.UxPrompt.Settings, "best_label", "there is no first label any more")

	// No settings is an empty object, never null.
	db.Exec("UPDATE ux_prompts SET settings = NULL WHERE id = ?", id)
	var raw map[string]interface{}
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=UxPrompt", nil))
	require.NoError(t, json.Unmarshal(rsp(resp), &raw))
	settings, ok := raw["uxprompt"].(map[string]interface{})["settings"].(map[string]interface{})
	assert.True(t, ok)
	assert.Empty(t, settings)
}

func TestUxPromptChallengeDoesNotSendServerOnlySettings(t *testing.T) {
	prefix := uniquePrefix("uxserver")
	uxPromptClean(t, prefix)
	db := database.DBConn
	token := getToken(t, CreateTestUser(t, uniquePrefix("uxserveruser"), "User"))

	id := insertPrompt(t, "awareness", prefix+" do you know how?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?",
		`{"followup_of":12,"analysed_answers":100,"decoy":true,"reveal":"Freegle does not do this. Nobody can pay.","link":"/settings","preset":"problems","placeholder":"What happened?","show":3,"worst_label":"Which other?","none_label":"None of these","randomise":true}`, id)

	settings := uxChallenge(t, token)["uxprompt"].(map[string]interface{})["settings"].(map[string]interface{})

	for _, serverOnly := range []string{"followup_of", "analysed_answers", "decoy"} {
		assert.NotContains(t, settings, serverOnly, "the card needs no "+serverOnly)
	}
	for key, want := range map[string]interface{}{
		"reveal": "Freegle does not do this. Nobody can pay.", "link": "/settings", "preset": "problems", "placeholder": "What happened?",
		"show": float64(3), "worst_label": "Which other?", "none_label": "None of these", "randomise": true,
	} {
		assert.Equal(t, want, settings[key], key+" passes through")
	}
}

// The site always names the types it can draw. A caller that names none must not be handed a card it
// cannot draw, so UxPrompt is not in the default list.
func TestUxPromptIsNotInTheDefaultChallengeTypes(t *testing.T) {
	prefix := uniquePrefix("uxdefault")
	uxPromptClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxdefaultuser"), "User")
	token := getToken(t, userID)
	id := insertPrompt(t, "open", prefix+" did you have a problem?", `[]`)

	// Asked for by name, it is served.
	assert.Equal(t, float64(id), uxChallenge(t, token)["uxprompt"].(map[string]interface{})["id"])

	// A caller that names no types is offered the Invite task first, to any member not asked in the
	// last 31 days, and that answer would hide a UxPrompt in the default list. A recent Invite is
	// recorded, as the other microvolunteering tests do, so the request reaches the point where a
	// UxPrompt would be offered if it were in the list.
	db.Exec("INSERT INTO microactions (actiontype, userid, version, comments, timestamp, score_negative) VALUES (?, ?, 4, 'Test block', NOW(), 0)", microvolunteering.ChallengeInvite, userID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM microactions WHERE userid = ? AND actiontype = ?", userID, microvolunteering.ChallengeInvite)
	})

	// Not named, it is not.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var ch map[string]interface{}
	_ = json.Unmarshal(rsp(resp), &ch)
	assert.NotEqual(t, microvolunteering.ChallengeUxPrompt, ch["type"])
	assert.NotContains(t, ch, "uxprompt")
}

func TestUxPromptSkipIsRecordedAndNotOfferedAgain(t *testing.T) {
	prefix := uniquePrefix("uxskip")
	uxPromptClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxskipuser"), "User")
	token := getToken(t, userID)

	first := insertPrompt(t, "survey", prefix+" first?", `[{"key":"give","label":"Posting"},{"key":"reply","label":"Replying"}]`)
	second := insertPrompt(t, "open", prefix+" second?", `[]`)
	db.Exec("UPDATE ux_prompts SET max_responses = 1 WHERE id = ?", first)

	offered := uxChallenge(t, token)["uxprompt"].(map[string]interface{})
	require.Equal(t, float64(first), offered["id"])

	// Skipping is accepted for any kind, whatever the options, and stores a skip with no comment.
	assert.Equal(t, 200, uxPostMicro(t, token, `{"ux_prompt_id":`+strconv.Itoa(int(first))+`,"ux_answer":{"skipped":true},"comments":"nope"}`))
	var answer string
	var comment *string
	db.Raw("SELECT answer, comment FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", first, userID).Row().Scan(&answer, &comment)
	assert.JSONEq(t, `{"skipped":true}`, answer)
	assert.Nil(t, comment)

	// The same member is not handed a question straight away (the week's gap applies to a skip) ...
	assert.NotContains(t, uxChallenge(t, token), "type")
	// ... and when the gap has passed, the skipped prompt stays skipped and the next one is offered.
	db.Exec("UPDATE ux_prompt_responses SET timestamp = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE userid = ?", userID)
	offered = uxChallenge(t, token)["uxprompt"].(map[string]interface{})
	assert.Equal(t, float64(second), offered["id"])

	// A skip is not an answer: it does not use up the prompt's cap of 1, so someone else can still answer it.
	other := CreateTestUser(t, uniquePrefix("uxskipother"), "User")
	otherToken := getToken(t, other)
	offered = uxChallenge(t, otherToken)["uxprompt"].(map[string]interface{})
	assert.Equal(t, float64(first), offered["id"], "the skipped prompt still has room")
	assert.Equal(t, 200, uxPostMicro(t, otherToken, `{"ux_prompt_id":`+strconv.Itoa(int(first))+`,"ux_answer":{"best":"give","worst":"reply"}}`))
	third := CreateTestUser(t, uniquePrefix("uxskipthird"), "User")
	assert.Equal(t, float64(second), uxChallenge(t, getToken(t, third))["uxprompt"].(map[string]interface{})["id"], "now the cap is reached by real answers")

	// Skips are left out of every tally.
	var all ux.QuestionsResponse
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxskipsup"), "questions", &all))
	seen := false
	for _, s := range all.Questions {
		if s.PromptID == first {
			seen = true
			assert.Equal(t, int64(1), s.Responses)
			assert.Equal(t, int64(1), s.Skipped)
			tally := map[string]ux.SurveyTally{}
			for _, tl := range s.Tallies {
				tally[tl.Key] = tl
			}
			assert.Equal(t, int64(1), tally["give"].Most)
			assert.Equal(t, int64(1), tally["reply"].Least)
		}
	}
	assert.True(t, seen)
}

func TestUxAwarenessDontUnderstandRowCarriesTheCount(t *testing.T) {
	prefix := uniquePrefix("uxaw")
	uxPromptClean(t, prefix)
	id := insertPrompt(t, "awareness", prefix+" did you know?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"dont_understand","label":"I don't understand"}]`)
	db := database.DBConn
	for i, a := range []string{`{"choice":"yes"}`, `{"choice":"dont_understand"}`, `{"choice":"dont_understand"}`} {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxawuser%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", id, u, a)
	}
	var all ux.QuestionsResponse
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxawsup"), "questions", &all))
	seen := false
	for _, s := range all.Questions {
		if s.PromptID != id {
			continue
		}
		seen = true
		assert.Equal(t, int64(3), s.Responses)
		assert.Equal(t, int64(2), s.DontUnderstand)
		for _, tl := range s.Tallies {
			if tl.Key == "dont_understand" {
				assert.Equal(t, int64(2), tl.Count, "the table row agrees with the note under it")
			}
		}
	}
	assert.True(t, seen)
}

const uxSixOptions = `[{"key":"a","label":"A"},{"key":"b","label":"B"},{"key":"c","label":"C"},{"key":"d","label":"D"},{"key":"e","label":"E"},{"key":"f","label":"F"}]`

func TestUxSurveyShowsFourAtATimeLeastShownFirst(t *testing.T) {
	prefix := uniquePrefix("uxdisp")
	uxPromptClean(t, prefix)
	db := database.DBConn
	id := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)

	keysOf := func(ch map[string]interface{}) []string {
		out := []string{}
		for _, k := range ch["uxprompt"].(map[string]interface{})["display"].([]interface{}) {
			out = append(out, k.(string))
		}
		return out
	}

	// Nothing shown yet: four distinct option keys, in some order.
	u := CreateTestUser(t, uniquePrefix("uxdispuser"), "User")
	display := keysOf(uxChallenge(t, getToken(t, u)))
	assert.Len(t, display, 4)
	seen := map[string]bool{}
	for _, k := range display {
		assert.Contains(t, []string{"a", "b", "c", "d", "e", "f"}, k)
		seen[k] = true
	}
	assert.Len(t, seen, 4, "no key twice")

	// a, b and c have been shown 5 times and d once; e and f never. Whoever comes next is shown
	// e and f (never seen) and d (once) first, and one of a, b or c to make up the four.
	for i, shown := range []string{`["a","b","c","d"]`, `["a","b","c"]`, `["a","b","c"]`, `["a","b","c"]`, `["a","b","c"]`} {
		other := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxdispo%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", id, other, `{"best":"a","shown":`+shown+`}`)
	}
	for i := 0; i < 5; i++ {
		next := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxdispn%d", i)), "User")
		display = keysOf(uxChallenge(t, getToken(t, next)))
		require.Len(t, display, 4)
		assert.Subset(t, display, []string{"d", "e", "f"}, "the least shown come first")
	}

	// settings.show changes the count, and a survey with fewer options shows them all.
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"show":3}`, id)
	next := CreateTestUser(t, uniquePrefix("uxdispshow"), "User")
	assert.Len(t, keysOf(uxChallenge(t, getToken(t, next))), 3)
	small := insertPrompt(t, "survey", prefix+" small?", `[{"key":"x","label":"X"},{"key":"y","label":"Y"}]`)
	db.Exec("UPDATE ux_prompts SET status = 'closed' WHERE id = ?", id)
	next = CreateTestUser(t, uniquePrefix("uxdispsmall"), "User")
	ch := uxChallenge(t, getToken(t, next))
	assert.Equal(t, float64(small), ch["uxprompt"].(map[string]interface{})["id"])
	assert.ElementsMatch(t, []string{"x", "y"}, keysOf(ch))

	// Other kinds carry no display.
	aw := insertPrompt(t, "awareness", prefix+" do you know how?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`)
	db.Exec("UPDATE ux_prompts SET status = 'closed' WHERE id = ?", small)
	next = CreateTestUser(t, uniquePrefix("uxdispaw"), "User")
	ch = uxChallenge(t, getToken(t, next))
	require.Equal(t, float64(aw), ch["uxprompt"].(map[string]interface{})["id"])
	assert.NotContains(t, ch["uxprompt"], "display")
}

func TestUxSurveyAnswerShapesAndShownValidation(t *testing.T) {
	prefix := uniquePrefix("uxshown")
	uxPromptClean(t, prefix)
	id := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)
	fresh := func(name string) (uint64, string) {
		u := CreateTestUser(t, uniquePrefix(name), "User")
		return u, getToken(t, u)
	}

	_, tok := fresh("uxshownbad")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["a","zzz"]}`), "an unknown key")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["a","a"]}`), "a key twice")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["a","b","c","d","e","f","a"]}`), "more than the options")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"e","shown":["a","b","c","d"]}`), "best that was not shown")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","worst":"e","shown":["a","b","c","d"]}`), "worst that was not shown")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":[]}`), "an empty shown list contains nothing")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","worst":"a","shown":["a","b"]}`), "worst must differ")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"none"}`), "an escape is not an option to rank")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"choice":"banana","shown":["a"]}`))
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"choice":"none","shown":["q"]}`), "none with a bad shown list")
	assert.Equal(t, 400, uxAnswer(t, tok, id, `{"best":"a","shown":["none","a"]}`), "an escape key cannot be shown")

	// Best alone is an answer, and shown is stored as sent, in order.
	u1, t1 := fresh("uxshown1")
	assert.Equal(t, 200, uxAnswer(t, t1, id, `{"best":"c","shown":["d","c","a","b"]}`))
	stored := uxStoredAnswer(t, id, u1)
	assert.Equal(t, "c", stored["best"])
	assert.NotContains(t, stored, "worst")
	assert.Equal(t, []interface{}{"d", "c", "a", "b"}, stored["shown"])

	u2, t2 := fresh("uxshown2")
	assert.Equal(t, 200, uxAnswer(t, t2, id, `{"best":"c","worst":"a","shown":["d","c","a","b"]}`))
	assert.Equal(t, "a", uxStoredAnswer(t, id, u2)["worst"])

	u3, t3 := fresh("uxshown3")
	assert.Equal(t, 200, uxAnswer(t, t3, id, `{"choice":"none","shown":["a","b","c","d"]}`))
	stored = uxStoredAnswer(t, id, u3)
	assert.Equal(t, "none", stored["choice"])
	assert.Equal(t, 4, len(stored["shown"].([]interface{})))

	u4, t4 := fresh("uxshown4")
	assert.Equal(t, 200, uxAnswer(t, t4, id, `{"choice":"dont_understand"}`))
	assert.Equal(t, "dont_understand", uxStoredAnswer(t, id, u4)["choice"])

	// Without shown, an answer is still accepted (older clients).
	_, t5 := fresh("uxshown5")
	assert.Equal(t, 200, uxAnswer(t, t5, id, `{"best":"f","worst":"e"}`))
}

// A follow-up asks for one pick: the biggest problem, three at a time. The answer is the pick and
// the options shown, with no second pick.
func TestUxFollowupAnswerTakesOnePick(t *testing.T) {
	prefix := uniquePrefix("uxfollow")
	uxPromptClean(t, prefix)
	db := database.DBConn
	id := insertPrompt(t, "survey", prefix+" which has been your biggest problem?", `[{"key":"problem:1","label":"Photos are slow"},{"key":"problem:2","label":"Too many emails"},{"key":"problem:3","label":"Chats are hard to find"},{"key":"problem:4","label":"Posts wait too long"}]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"show":3,"none_label":"None of these","followup_of":7}`, id)
	fresh := func(name string) (uint64, string) {
		u := CreateTestUser(t, uniquePrefix(name), "User")
		return u, getToken(t, u)
	}

	// Three are shown, and the card is told there is no second question.
	_, tok := fresh("uxfollowoffer")
	prompt := uxChallenge(t, tok)["uxprompt"].(map[string]interface{})
	assert.Len(t, prompt["display"].([]interface{}), 3)
	settings := prompt["settings"].(map[string]interface{})
	assert.Equal(t, float64(3), settings["show"])
	assert.NotContains(t, settings, "worst_label")
	assert.NotContains(t, settings, "followup_of", "which prompt it came from is the batch's business")

	// One pick, from those shown, is the answer.
	u1, t1 := fresh("uxfollow1")
	assert.Equal(t, 200, uxAnswer(t, t1, id, `{"best":"problem:2","shown":["problem:1","problem:2","problem:3"]}`))
	stored := uxStoredAnswer(t, id, u1)
	assert.Equal(t, "problem:2", stored["best"])
	assert.NotContains(t, stored, "worst", "there is only one pick")
	assert.Equal(t, []interface{}{"problem:1", "problem:2", "problem:3"}, stored["shown"])

	// A pick that was not shown is refused, and so is a second pick that repeats the first.
	_, t2 := fresh("uxfollow2")
	assert.Equal(t, 400, uxAnswer(t, t2, id, `{"best":"problem:4","shown":["problem:1","problem:2","problem:3"]}`))
	assert.Equal(t, 400, uxAnswer(t, t2, id, `{"best":"problem:1","worst":"problem:1","shown":["problem:1","problem:2","problem:3"]}`))
	// "None of these" is an answer too, and counts as shown.
	u3, t3 := fresh("uxfollow3")
	assert.Equal(t, 200, uxAnswer(t, t3, id, `{"choice":"none","shown":["problem:2","problem:3","problem:4"]}`))
	assert.Equal(t, "none", uxStoredAnswer(t, id, u3)["choice"])

	// The read shows the pick and how often each was shown; a follow-up has no least.
	var all ux.QuestionsResponse
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxfollowsup"), "questions?kind=survey", &all))
	for _, s := range all.Questions {
		if s.PromptID != id {
			continue
		}
		tally := map[string]ux.SurveyTally{}
		for _, tl := range s.Tallies {
			tally[tl.Key] = tl
			assert.Equal(t, int64(0), tl.Least, tl.Key)
		}
		assert.Equal(t, int64(1), tally["problem:2"].Most)
		assert.Equal(t, int64(2), tally["problem:2"].TimesShown)
		assert.Equal(t, int64(1), s.None)
	}
}

func TestUxAwarenessAnswers(t *testing.T) {
	prefix := uniquePrefix("uxawans")
	uxPromptClean(t, prefix)
	fresh := func(name string) (uint64, string) {
		u := CreateTestUser(t, uniquePrefix(name), "User")
		return u, getToken(t, u)
	}

	// Awareness: yes, no and not_sure, and nothing else, even if the row lists more.
	aw := insertPrompt(t, "awareness", prefix+" do you know how?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"},{"key":"dont_understand","label":"I don't understand"}]`)
	_, t2 := fresh("uxaw2")
	assert.Equal(t, 400, uxAnswer(t, t2, aw, `{"choice":"maybe"}`))
	assert.Equal(t, 400, uxAnswer(t, t2, aw, `{"choice":"dont_understand"}`))
	assert.Equal(t, 400, uxAnswer(t, t2, aw, `{"best":"yes","worst":"no"}`))
	assert.Equal(t, 200, uxAnswer(t, t2, aw, `{"choice":"not_sure"}`))

	// An awareness prompt whose row lacks not_sure refuses it.
	aw2 := insertPrompt(t, "awareness", prefix+" do you know how 2?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"}]`)
	_, t3 := fresh("uxaw3")
	assert.Equal(t, 400, uxAnswer(t, t3, aw2, `{"choice":"not_sure"}`))
	assert.Equal(t, 200, uxAnswer(t, t3, aw2, `{"choice":"no"}`))

	// The yes, no and unsure answers are stored with the options shown, in the order shown.
	u4, t4 := fresh("uxaw4")
	assert.Equal(t, 200, uxAnswer(t, t4, aw, `{"choice":"yes","shown":["yes","no","not_sure"]}`))
	assert.Equal(t, []interface{}{"yes", "no", "not_sure"}, uxStoredAnswer(t, aw, u4)["shown"])
}

func TestUxPromptCadenceIsWeeklyAndBacksOffAfterTwoSkips(t *testing.T) {
	prefix := uniquePrefix("uxcad")
	uxPromptClean(t, prefix)
	db := database.DBConn
	ids := []uint64{}
	for i := 0; i < 5; i++ {
		ids = append(ids, insertPrompt(t, "open", fmt.Sprintf("%s q%d?", prefix, i), `[]`))
	}
	offered := func(tok string) bool {
		_, ok := uxChallenge(t, tok)["uxprompt"]
		return ok
	}
	age := func(userID uint64, promptID uint64, days int) {
		db.Exec("UPDATE ux_prompt_responses SET timestamp = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE userid = ? AND prompt_id = ?", days, userID, promptID)
	}

	// An answer blocks a prompt for a week, not a day.
	u := CreateTestUser(t, uniquePrefix("uxcadu"), "User")
	tok := getToken(t, u)
	require.True(t, offered(tok))
	require.Equal(t, 200, uxAnswer(t, tok, ids[0], `{"text":"something"}`))
	assert.False(t, offered(tok))
	age(u, ids[0], 3)
	assert.False(t, offered(tok), "three days on is still inside the week")
	age(u, ids[0], 6)
	assert.False(t, offered(tok), "six days on is still inside the week")
	age(u, ids[0], 8)
	assert.True(t, offered(tok))

	// Two skips in a row: nothing for 30 days after the later one.
	s := CreateTestUser(t, uniquePrefix("uxcads"), "User")
	stok := getToken(t, s)
	require.Equal(t, 200, uxAnswer(t, stok, ids[0], `{"skipped":true}`))
	age(s, ids[0], 8)
	assert.True(t, offered(stok), "one skip only costs the week")
	require.Equal(t, 200, uxAnswer(t, stok, ids[1], `{"skipped":true}`))
	age(s, ids[1], 8)
	assert.False(t, offered(stok), "two skips back off")
	age(s, ids[0], 40)
	age(s, ids[1], 29)
	assert.False(t, offered(stok), "29 days after the later skip is still inside the month")
	age(s, ids[1], 31)
	assert.True(t, offered(stok), "a month after the second skip they are asked again")

	// An answer between skips breaks the run.
	m := CreateTestUser(t, uniquePrefix("uxcadm"), "User")
	mtok := getToken(t, m)
	require.Equal(t, 200, uxAnswer(t, mtok, ids[0], `{"skipped":true}`))
	age(m, ids[0], 20)
	require.Equal(t, 200, uxAnswer(t, mtok, ids[1], `{"text":"an answer"}`))
	age(m, ids[1], 15)
	require.Equal(t, 200, uxAnswer(t, mtok, ids[2], `{"skipped":true}`))
	age(m, ids[2], 8)
	assert.True(t, offered(mtok), "skip, answer, skip is not two skips in a row")
}

// An awareness question is a question, not a tip, so the usual one has no reveal in its settings.
// It must still be served, with its three answers, and answered, with nothing missing to refuse it.
func TestUxAwarenessWithoutRevealIsServedAndAnswered(t *testing.T) {
	prefix := uniquePrefix("uxawnoreveal")
	uxPromptClean(t, prefix)
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("uxawnorevealuser"), "User")
	token := getToken(t, userID)

	awID := insertPrompt(t, "awareness", prefix+" Do you know how to promise an item to someone?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"decoy":false}`, awID)

	prompt := uxChallenge(t, token)["uxprompt"].(map[string]interface{})
	assert.Equal(t, "awareness", prompt["kind"])
	settings := prompt["settings"].(map[string]interface{})
	assert.NotContains(t, settings, "reveal", "no reveal is made up when there is none")
	assert.NotContains(t, settings, "decoy", "the card needs no decoy flag; a decoy's reveal already says so")
	keys := []string{}
	for _, o := range prompt["options"].([]interface{}) {
		keys = append(keys, o.(map[string]interface{})["key"].(string))
	}
	assert.Equal(t, []string{"yes", "no", "not_sure"}, keys)

	assert.Equal(t, 200, uxAnswer(t, token, awID, `{"choice":"yes"}`))
	var n int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE prompt_id = ? AND userid = ?", awID, userID).Scan(&n)
	assert.Equal(t, int64(1), n)
}

// ---- problems, values and groups ----

func TestUxSurveyRoutesRequireSupport(t *testing.T) {
	member := getToken(t, CreateTestUser(t, uniquePrefix("uxsurvmember"), "User"))
	support := uxSupportToken(t, "uxsurvsup")

	for _, path := range []string{"problem-groups", "problems", "values", "questions"} {
		assert.Equal(t, 401, uxGet(t, "", path, nil), path+" without a login")
		assert.Equal(t, 403, uxGet(t, member, path, nil), path+" for an ordinary member")
		assert.Equal(t, 200, uxGet(t, support, path, nil), path+" for Support")
	}
}

func TestUxSurveyRoutesRefuseBadParameters(t *testing.T) {
	support := uxSupportToken(t, "uxsurvbad")

	for _, path := range []string{"problem-groups", "problems"} {
		assert.Equal(t, 400, uxGet(t, support, path+"?min=-1", nil), path+" min below zero")
		assert.Equal(t, 400, uxGet(t, support, path+"?min=lots", nil), path+" min not a number")
		assert.Equal(t, 400, uxGet(t, support, path+"?flow="+url.QueryEscape("bad flow!"), nil), path+" flow that is not a name")
		assert.Equal(t, 200, uxGet(t, support, path+"?q="+strings.Repeat("x", 500), nil), path+" search text is cut, not refused")
	}
	for _, limit := range []string{"0", "-3", "many"} {
		assert.Equal(t, 400, uxGet(t, support, "problems?limit="+limit, nil), "limit "+limit)
	}
	assert.Equal(t, 400, uxGet(t, support, "questions?kind=mystery", nil))
	assert.Equal(t, 400, uxGet(t, support, "questions?status=half", nil))
}

func TestUxProblemGroupsAreOrderedByMentionsWithOtherLast(t *testing.T) {
	prefix := uniquePrefix("uxgrp")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxgrpsup")
	give, reply := "give", "reply"

	q1 := insertPrompt(t, "open", prefix+" did you have a problem?", `[]`)
	db := database.DBConn
	db.Exec("UPDATE ux_prompts SET settings = JSON_OBJECT('analysed_answers', 120) WHERE id = ?", q1)

	// How much is left over before this test adds to it. The figure ignores q, so rows other tests
	// leave behind are in it, and the test reads how much it grew.
	leftOver := func(path string) int64 {
		var r ux.ProblemGroupsResponse
		require.Equal(t, 200, uxGet(t, token, path, &r), path)
		return r.LeftOver
	}
	allBefore, giveBefore := leftOver("problem-groups"), leftOver("problem-groups?flow=give")
	noneBefore, replyBefore := leftOver("problem-groups?flow=none"), leftOver("problem-groups?flow=reply")

	// Photos and posting: three problems, 21 mentions. The biggest has 14.
	big := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" photos are slow", 14, 0.4, q1, nil)
	uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" description cut off", 5, nil, q1, nil)
	uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" repost is hidden", 2, nil, q1, nil)
	// Email and notifications: one problem, 30 mentions, so it is the largest group.
	uxInsertProblem(t, &reply, "problem", "Email and notifications", prefix+" too many emails", 30, nil, q1, nil)
	// Messages and chat: a tie on mentions between two problems, so the higher weight is the biggest.
	uxInsertProblem(t, &reply, "problem", "Messages and chat", prefix+" chats are hard to find", 6, 0.1, nil, nil)
	heavier := uxInsertProblem(t, &reply, "problem", "Messages and chat", prefix+" replies get lost", 6, 0.5, nil, nil)
	// Other has the most of all but still comes last.
	uxInsertProblem(t, nil, "problem", "Other", prefix+" something odd", 50, nil, nil, nil)
	// None of these is a problem: a value, a suggestion, a dismissed problem and two leftover rows, the
	// answers that fitted no problem. A leftover row carries a group so that only its kind keeps it out.
	uxInsertProblem(t, nil, "value", nil, prefix+" it is free", 99, nil, nil, nil)
	uxInsertProblem(t, &give, "suggestion", nil, prefix+" retry a photo", 99, nil, nil, big)
	dismissed := uxInsertProblem(t, &give, "problem", "Finding things", prefix+" dismissed", 99, nil, nil, nil)
	db.Exec("UPDATE ux_problems SET status = 'dismissed' WHERE id = ?", dismissed)
	leftGive := uxInsertProblem(t, &give, "other", "Other", prefix+" fits no problem", 9, nil, q1, nil)
	leftNone := uxInsertProblem(t, nil, "other", "Other", prefix+" fits nothing either", 4, nil, q1, nil)

	var out ux.ProblemGroupsResponse
	require.Equal(t, 200, uxGet(t, token, "problem-groups?q="+prefix, &out))

	names := []string{}
	for _, g := range out.Groups {
		names = append(names, g.Name)
	}
	assert.Equal(t, []string{"Email and notifications", "Photos and posting", "Messages and chat", "Other"}, names,
		"most mentions first, Other last whatever its size, and a group with no problem left out")
	photos := out.Groups[1]
	assert.Equal(t, int64(3), photos.Problems)
	assert.Equal(t, int64(21), photos.Mentions)
	assert.Equal(t, big, photos.Biggest.ID)
	assert.Equal(t, prefix+" photos are slow", photos.Biggest.Title)
	assert.Equal(t, int64(14), photos.Biggest.Mentions)
	assert.Equal(t, int64(120), photos.Biggest.Answers, "the base of the question it came from")
	assert.Equal(t, heavier, out.Groups[2].Biggest.ID, "a tie on mentions goes to the higher weight")
	assert.Equal(t, int64(0), out.Groups[2].Biggest.Answers, "a problem not from a question has no base")
	assert.Equal(t, int64(50), out.Groups[3].Mentions)
	assert.GreaterOrEqual(t, out.Answers, int64(120), "the largest base of any of them")
	assert.NotNil(t, out.Groups)

	// The leftover rows are counted as left over, whatever their funnel, and are never a problem or part
	// of a group: the Other group above holds only the problem named Other.
	assert.Equal(t, allBefore+13, out.LeftOver, "the answers that fitted no problem")
	assert.Equal(t, giveBefore+9, leftOver("problem-groups?flow=give"), "a funnel narrows the figure to its own")
	assert.Equal(t, noneBefore+4, leftOver("problem-groups?flow=none"), "none narrows it to the rows tied to no funnel")
	assert.Equal(t, replyBefore, leftOver("problem-groups?flow=reply"), "a funnel with no leftover row gains nothing")

	var list ux.ProblemsResponse
	require.Equal(t, 200, uxGet(t, token, "problems?limit=100&q="+prefix, &list))
	assert.Equal(t, int64(7), list.Total, "seven problems, with no value, suggestion, dismissed or leftover row among them")
	for _, p := range list.Problems {
		assert.NotContains(t, []uint64{leftGive, leftNone}, p.ID, "a leftover row is not a problem")
	}

	// A search that finds nothing is an empty list, not null.
	var none ux.ProblemGroupsResponse
	raw := map[string]interface{}{}
	require.Equal(t, 200, uxGet(t, token, "problem-groups?q="+prefix+"nothing", &none))
	require.Equal(t, 200, uxGet(t, token, "problem-groups?q="+prefix+"nothing", &raw))
	assert.Equal(t, []interface{}{}, raw["groups"])
	assert.Empty(t, none.Groups)
}

func TestUxProblemGroupsFlowNoneAndMin(t *testing.T) {
	prefix := uniquePrefix("uxgrpflow")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxgrpflowsup")
	give, reply := "give", "reply"

	uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" a give problem", 3, nil, nil, nil)
	uxInsertProblem(t, &reply, "problem", "Messages and chat", prefix+" a reply problem", 5, nil, nil, nil)
	uxInsertProblem(t, nil, "problem", "Email and notifications", prefix+" tied to none", 7, nil, nil, nil)
	uxInsertProblem(t, nil, "problem", "Email and notifications", prefix+" tied to none too", 1, nil, nil, nil)

	total := func(path string) (problems, mentions int64) {
		var out ux.ProblemGroupsResponse
		require.Equal(t, 200, uxGet(t, token, path, &out), path)
		for _, g := range out.Groups {
			problems += g.Problems
			mentions += g.Mentions
		}
		return
	}

	p, m := total("problem-groups?q=" + prefix)
	assert.Equal(t, [2]int64{4, 16}, [2]int64{p, m}, "omitted means every funnel and none")
	p, m = total("problem-groups?flow=none&q=" + prefix)
	assert.Equal(t, [2]int64{2, 8}, [2]int64{p, m}, "none means problems tied to no funnel")
	p, m = total("problem-groups?flow=give&q=" + prefix)
	assert.Equal(t, [2]int64{1, 3}, [2]int64{p, m}, "a funnel key means that funnel")
	p, m = total("problem-groups?flow=checkout&q=" + prefix)
	assert.Equal(t, [2]int64{0, 0}, [2]int64{p, m}, "a funnel nobody has is empty, not an error")
	p, m = total("problem-groups?min=4&q=" + prefix)
	assert.Equal(t, [2]int64{2, 12}, [2]int64{p, m}, "min is the least number of mentions")
	p, m = total("problem-groups?min=4&flow=none&q=" + prefix)
	assert.Equal(t, [2]int64{1, 7}, [2]int64{p, m})
	p, m = total("problem-groups?min=100&q=" + prefix)
	assert.Equal(t, [2]int64{0, 0}, [2]int64{p, m})
}

// A problem stored with no group is shown under Other, and a filter for Other finds it.
func TestUxProblemGroupsCountANullGroupAsOther(t *testing.T) {
	prefix := uniquePrefix("uxgrpnull")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxgrpnullsup")
	db := database.DBConn

	uxInsertProblem(t, nil, "problem", "Other", prefix+" named other", 2, nil, nil, nil)
	nullGroup := uxInsertProblem(t, nil, "problem", nil, prefix+" no group at all", 3, nil, nil, nil)
	blank := uxInsertProblem(t, nil, "problem", "", prefix+" a blank group", 4, nil, nil, nil)
	uxInsertProblem(t, nil, "problem", "Finding things", prefix+" a real group", 1, nil, nil, nil)

	var out ux.ProblemGroupsResponse
	require.Equal(t, 200, uxGet(t, token, "problem-groups?q="+prefix, &out))
	byName := map[string]ux.ProblemGroup{}
	for _, g := range out.Groups {
		byName[g.Name] = g
	}
	assert.Len(t, out.Groups, 2, "Other and Finding things; no group of its own for a missing name")
	assert.Equal(t, int64(3), byName["Other"].Problems)
	assert.Equal(t, int64(9), byName["Other"].Mentions)
	assert.Equal(t, blank, byName["Other"].Biggest.ID, "the biggest of them counts the unnamed ones")
	assert.Equal(t, "Other", out.Groups[len(out.Groups)-1].Name)

	var list ux.ProblemsResponse
	require.Equal(t, 200, uxGet(t, token, "problems?group=Other&q="+prefix, &list))
	assert.Equal(t, int64(3), list.Total)
	var got []uint64
	for _, p := range list.Problems {
		got = append(got, p.ID)
		assert.Equal(t, "Other", p.Group, "an unnamed group is shown as Other")
	}
	assert.Contains(t, got, nullGroup)
	assert.Contains(t, got, blank)

	// The stored rows are untouched: the name is only the way they are read.
	var unnamed int64
	db.Raw("SELECT COUNT(*) FROM ux_problems WHERE id = ? AND group_name IS NULL", nullGroup).Scan(&unnamed)
	assert.Equal(t, int64(1), unnamed)
}

func TestUxProblemsFilterByGroupAndSearch(t *testing.T) {
	prefix := uniquePrefix("uxsrch")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxsrchsup")
	db := database.DBConn
	give := "give"

	photos := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" photos are slow", 9, nil, nil, nil)
	email := uxInsertProblem(t, &give, "problem", "Email and notifications", prefix+" too many emails", 4, nil, nil, nil)
	hidden := uxInsertProblem(t, &give, "problem", "Email and notifications", prefix+" the wording is unclear", 2, nil, nil, nil)
	db.Exec("UPDATE ux_problems SET description = 'It only happens on a phone camera' WHERE id = ?", hidden)
	percent := uxInsertProblem(t, nil, "problem", "Finding things", prefix+" a 100% rule", 3, nil, nil, nil)
	plain := uxInsertProblem(t, nil, "problem", "Finding things", prefix+" a 100 rule", 3, nil, nil, nil)
	snake := uxInsertProblem(t, nil, "problem", "Finding things", prefix+" snake_case", 1, nil, nil, nil)
	camel := uxInsertProblem(t, nil, "problem", "Finding things", prefix+" snakeXcase", 1, nil, nil, nil)

	ids := func(path string) []uint64 {
		var out ux.ProblemsResponse
		require.Equal(t, 200, uxGet(t, token, path, &out), path)
		got := []uint64{}
		for _, p := range out.Problems {
			got = append(got, p.ID)
		}
		return got
	}
	esc := url.QueryEscape

	// A group is an exact name.
	assert.Equal(t, []uint64{photos}, ids("problems?group="+esc("Photos and posting")+"&q="+prefix))
	assert.Equal(t, []uint64{email, hidden}, ids("problems?group="+esc("Email and notifications")+"&q="+prefix), "most mentioned first")
	assert.Empty(t, ids("problems?group="+esc("Photos")+"&q="+prefix), "not a prefix match")
	assert.Empty(t, ids("problems?group="+esc("Account and settings")+"&q="+prefix), "a group with none is an empty list")

	// The search looks in the title and in the description, and ignores case.
	assert.Equal(t, []uint64{photos}, ids("problems?q="+esc(prefix+" PHOTOS")))
	assert.Equal(t, []uint64{hidden}, ids("problems?q="+esc("phone camera")+"&group="+esc("Email and notifications")), "found in the description")
	assert.Equal(t, []uint64{hidden}, ids("problems?q="+esc(prefix+" the wording")))

	// A % in the search means a percent sign, and an underscore means an underscore.
	assert.Equal(t, []uint64{percent}, ids("problems?q="+esc(prefix+" a 100%")), "% is not a wildcard")
	assert.ElementsMatch(t, []uint64{percent, plain}, ids("problems?q="+esc(prefix+" a 100")))
	assert.Equal(t, []uint64{snake}, ids("problems?q="+esc(prefix+" snake_case")), "_ is not a wildcard")
	assert.NotContains(t, ids("problems?q="+esc(prefix+" snake_case")), camel)
	assert.Equal(t, []uint64{}, ids("problems?q="+esc(prefix+"%")), "a search for the prefix and then a percent sign finds nothing")
	assert.Empty(t, ids("problems?q="+esc(`\`+prefix)), "a backslash is taken as it is")

	// Group, search and the least mentions all apply together.
	assert.Equal(t, []uint64{percent, plain}, ids("problems?group="+esc("Finding things")+"&min=3&q="+esc(prefix+" a 100")))
	assert.Equal(t, []uint64{photos}, ids("problems?min=9&q="+prefix))
	assert.Equal(t, []uint64{photos, email, percent, plain}, ids("problems?min=3&q="+prefix), "most mentioned first, then the older")
}

// The fixes members suggested sit under the problem they answer. They are never problems of their
// own and are listed nowhere else.
func TestUxProblemsCarrySuggestionsUnderTheirParent(t *testing.T) {
	prefix := uniquePrefix("uxsugg")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxsuggsup")
	db := database.DBConn
	give := "give"

	parent := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" photos are slow", 5, nil, nil, nil)
	other := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" description cut off", 2, nil, nil, nil)
	fewer := uxInsertProblem(t, &give, "suggestion", nil, prefix+" retry a failed photo", 3, nil, nil, parent)
	more := uxInsertProblem(t, &give, "suggestion", nil, prefix+" shrink photos first", 7, nil, nil, parent)
	elsewhere := uxInsertProblem(t, &give, "suggestion", nil, prefix+" show the whole description", 4, nil, nil, other)
	uxInsertProblem(t, &give, "suggestion", nil, prefix+" an orphan with no parent", 50, nil, nil, nil)
	gone := uxInsertProblem(t, &give, "suggestion", nil, prefix+" a dismissed fix", 60, nil, nil, parent)
	db.Exec("UPDATE ux_problems SET status = 'dismissed' WHERE id = ?", gone)

	var out ux.ProblemsResponse
	require.Equal(t, 200, uxGet(t, token, "problems?q="+prefix, &out))

	assert.Equal(t, int64(2), out.Total, "suggestions are not problems")
	require.Len(t, out.Problems, 2)
	assert.Equal(t, parent, out.Problems[0].ID)
	require.Len(t, out.Problems[0].Suggestions, 2)
	assert.Equal(t, more, out.Problems[0].Suggestions[0].ID, "the most mentioned fix first")
	assert.Equal(t, int64(7), out.Problems[0].Suggestions[0].Mentions)
	assert.Equal(t, prefix+" shrink photos first", out.Problems[0].Suggestions[0].Title)
	assert.Equal(t, fewer, out.Problems[0].Suggestions[1].ID)
	assert.Equal(t, []ux.Suggestion{{ID: elsewhere, Title: prefix + " show the whole description", Mentions: 4}}, out.Problems[1].Suggestions)

	// A suggestion is not counted as a problem in the groups either.
	var groups ux.ProblemGroupsResponse
	require.Equal(t, 200, uxGet(t, token, "problem-groups?q="+prefix, &groups))
	require.Len(t, groups.Groups, 1)
	assert.Equal(t, int64(2), groups.Groups[0].Problems)
	assert.Equal(t, int64(7), groups.Groups[0].Mentions)

	// A problem with none has an empty list, not null, and no more than ten are listed.
	lonely := uxInsertProblem(t, &give, "problem", "Other", prefix+" lonely", 1, nil, nil, nil)
	for i := 0; i < 12; i++ {
		uxInsertProblem(t, &give, "suggestion", nil, fmt.Sprintf("%s many fixes %02d", prefix, i), 1+i, nil, nil, lonely)
	}
	var raw map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "problems?group=Other&q="+prefix+"+lonely", &raw))
	problems := raw["problems"].([]interface{})
	require.Len(t, problems, 1)
	assert.Len(t, problems[0].(map[string]interface{})["suggestions"], 10)
	require.Equal(t, 200, uxGet(t, token, "problems?q="+prefix+"+description", &raw))
	assert.Equal(t, 1, len(raw["problems"].([]interface{})[0].(map[string]interface{})["suggestions"].([]interface{})))
	uxInsertProblem(t, &give, "problem", "Other", prefix+" nobody suggested", 1, nil, nil, nil)
	require.Equal(t, 200, uxGet(t, token, "problems?q="+prefix+"+nobody", &raw))
	assert.Equal(t, []interface{}{}, raw["problems"].([]interface{})[0].(map[string]interface{})["suggestions"])
}

// 45 problems match and 30 are asked for: 30 come back, and total says 45 so a caller can say
// "Showing 30 of 45". A higher limit returns them all; no limit goes past 100.
func TestUxProblemsTotalIsCountedBeforeTheLimit(t *testing.T) {
	prefix := uniquePrefix("uxtotal")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxtotalsup")
	give := "give"

	for i := 0; i < 45; i++ {
		uxInsertProblem(t, &give, "problem", "Photos and posting", fmt.Sprintf("%s problem %03d", prefix, i), 100-i, nil, nil, nil)
	}
	count := func(path string) (int, int64) {
		var out ux.ProblemsResponse
		require.Equal(t, 200, uxGet(t, token, path, &out), path)
		return len(out.Problems), out.Total
	}

	n, total := count("problems?q=" + prefix)
	assert.Equal(t, [2]int64{30, 45}, [2]int64{int64(n), total}, "30 by default, and the total before the limit")
	n, total = count("problems?limit=60&q=" + prefix)
	assert.Equal(t, [2]int64{45, 45}, [2]int64{int64(n), total})
	n, total = count("problems?limit=5&q=" + prefix)
	assert.Equal(t, [2]int64{5, 45}, [2]int64{int64(n), total})

	var first ux.ProblemsResponse
	require.Equal(t, 200, uxGet(t, token, "problems?limit=3&q="+prefix, &first))
	assert.Equal(t, prefix+" problem 000", first.Problems[0].Title, "the most mentioned first")

	for i := 45; i < 105; i++ {
		uxInsertProblem(t, &give, "problem", "Photos and posting", fmt.Sprintf("%s problem %03d", prefix, i), 1, nil, nil, nil)
	}
	n, total = count("problems?limit=60&q=" + prefix)
	assert.Equal(t, [2]int64{60, 105}, [2]int64{int64(n), total})
	n, total = count("problems?limit=500&q=" + prefix)
	assert.Equal(t, [2]int64{100, 105}, [2]int64{int64(n), total}, "a limit above 100 is cut to 100")
	n, total = count("problems?limit=100&q=" + prefix)
	assert.Equal(t, [2]int64{100, 105}, [2]int64{int64(n), total})

	// The groups route counts every one of them: it is not limited.
	var groups ux.ProblemGroupsResponse
	require.Equal(t, 200, uxGet(t, token, "problem-groups?q="+prefix, &groups))
	assert.Equal(t, int64(105), groups.Groups[0].Problems)
}

func TestUxProblemsAndValuesAreListed(t *testing.T) {
	prefix := uniquePrefix("uxprob")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxprobsup")
	give := "give"

	p1 := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" photos are slow", 14, 0.4, nil, nil)
	p2 := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" unclear wording", 2, nil, nil, nil)
	gen := uxInsertProblem(t, nil, "problem", "Email and notifications", prefix+" too many emails", 5, nil, nil, nil)
	val := uxInsertProblem(t, nil, "value", nil, prefix+" it is free", 30, nil, nil, nil)
	_ = p2

	var list ux.ProblemsResponse
	require.Equal(t, 200, uxGet(t, token, "problems?q="+prefix, &list))
	titles := []string{}
	byID := map[uint64]ux.Problem{}
	for _, p := range list.Problems {
		titles = append(titles, p.Title)
		byID[p.ID] = p
	}
	assert.Equal(t, []string{prefix + " photos are slow", prefix + " too many emails", prefix + " unclear wording"}, titles, "most mentioned first, and the value is not a problem")
	found := byID[p1]
	assert.Equal(t, int64(14), found.Mentions)
	require.NotNil(t, found.Weight)
	assert.InDelta(t, 0.4, *found.Weight, 0.001)
	require.NotNil(t, found.Flow)
	assert.Equal(t, "give", *found.Flow)
	assert.Equal(t, "Photos and posting", found.Group)
	assert.Equal(t, "desc", found.Description)
	assert.Equal(t, "open", found.Status)
	assert.Equal(t, []ux.Suggestion{}, found.Suggestions)
	assert.Nil(t, byID[gen].Flow, "a problem tied to no funnel has a null flow")
	assert.Nil(t, byID[p2].Weight, "a problem nobody ranked has no weight")

	var values ux.ValuesResponse
	require.Equal(t, 200, uxGet(t, token, "values", &values))
	valOK := false
	for _, v := range values.Values {
		assert.NotEqual(t, p1, v.ID, "a problem is not a value")
		if v.ID == val {
			valOK = true
			assert.Equal(t, int64(30), v.Mentions)
			assert.Equal(t, prefix+" it is free", v.Title)
			assert.Equal(t, []ux.Quote{}, v.Quotes)
		}
	}
	assert.True(t, valOK, "value listed")
	assert.LessOrEqual(t, len(values.Values), 20)
}

// Each problem carries the answer count of its OWN question, so two analysed questions do not
// share a base, and the ranking line carries how many members were asked.
func TestUxProblemsCarryTheBaseOfTheirOwnQuestion(t *testing.T) {
	prefix := uniquePrefix("uxbase")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxbasesup")
	db := database.DBConn
	give := "give"

	q1 := insertPrompt(t, "open", prefix+" problems?", `[]`)
	q2 := insertPrompt(t, "open", prefix+" values?", `[]`)
	follow := insertPrompt(t, "survey", prefix+" follow-up", `[]`)
	db.Exec("UPDATE ux_prompts SET settings = JSON_OBJECT('followup_of', ?) WHERE id = ?", q1, follow)
	// q1 was analysed when it had 3 answers; more have come since. q2 has no stored count.
	db.Exec("UPDATE ux_prompts SET settings = JSON_OBJECT('analysed_answers', 3) WHERE id = ?", q1)

	add := func(prompt uint64, answer string) {
		u := CreateTestUser(t, uniquePrefix("uxbaseuser"), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", prompt, u, answer)
	}
	for i := 0; i < 3; i++ {
		add(q1, `{"text":"slow"}`)
	}
	add(q1, `{"choice":"nothing"}`)
	add(q1, `{"skipped":true}`)
	for i := 0; i < 2; i++ {
		add(q2, `{"text":"free"}`)
	}
	add(follow, `{"best":"problem:1"}`)
	add(follow, `{"choice":"dont_care"}`)
	add(follow, `{"choice":"none","shown":["problem:1","problem:2"]}`)
	add(follow, `{"choice":"dont_understand"}`)

	p := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" photos are slow", 3, 0.5, q1, nil)
	v := uxInsertProblem(t, nil, "value", nil, prefix+" it is free", 2, nil, q2, nil)
	loose := uxInsertProblem(t, &give, "problem", "Other", prefix+" not from a question", 1, nil, nil, nil)

	var list ux.ProblemsResponse
	require.Equal(t, 200, uxGet(t, token, "problems?q="+prefix, &list))
	found := map[uint64]ux.Problem{}
	for _, pr := range list.Problems {
		found[pr.ID] = pr
	}
	assert.Equal(t, int64(3), found[p].Answers, "the count it was analysed over, not the answers that arrived since")
	assert.Equal(t, int64(3), found[p].Asked, "a pick, don't care or none of these; don't understand is not one")
	assert.Equal(t, int64(0), found[loose].Answers, "a problem not from a question has no base")
	assert.Equal(t, int64(0), found[loose].Asked)

	var questions ux.QuestionsResponse
	require.Equal(t, 200, uxGet(t, token, "questions", &questions))
	for _, s := range questions.Questions {
		if s.PromptID == q1 {
			assert.True(t, s.Analysed)
			assert.Equal(t, int64(3), s.AnalysedAnswers, "the question says how many answers the themes were drawn from")
		}
	}

	var values ux.ValuesResponse
	require.Equal(t, 200, uxGet(t, token, "values", &values))
	checked := false
	for _, val := range values.Values {
		if val.ID == v {
			checked = true
			assert.Equal(t, int64(2), val.Answers, "no stored count: its written answers, not the other question's")
		}
	}
	assert.True(t, checked)
}

func TestUxQuestionsCarryTimesShownAndAwarenessFigures(t *testing.T) {
	prefix := uniquePrefix("uxread")
	uxPromptClean(t, prefix)
	db := database.DBConn
	survey := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)
	answers := []string{
		`{"best":"a","worst":"b","shown":["a","b","c","d"]}`,
		`{"best":"a","shown":["a","c","d","e"]}`,
		`{"best":"c","worst":"a","shown":["a","b","c","e"]}`,
		`{"choice":"none","shown":["b","d","e","f"]}`,
	}
	for i, a := range answers {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxreadu%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", survey, u, a)
	}

	opts := `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`
	real := insertPrompt(t, "awareness", prefix+" real?", opts)
	decoy := insertPrompt(t, "awareness", prefix+" decoy?", opts)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"decoy":true,"reveal":"Freegle does not do this."}`, decoy)
	db.Exec("UPDATE ux_prompts SET settings = ? WHERE id = ?", `{"decoy":false,"reveal":"Yes."}`, real)
	for i, c := range []string{"yes", "yes", "no", "not_sure"} {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxreadr%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", real, u, `{"choice":"`+c+`"}`)
	}
	for i, c := range []string{"yes", "no", "no", "no", "skipped"} {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxreadd%d", i)), "User")
		answer := `{"choice":"` + c + `"}`
		if c == "skipped" {
			answer = `{"skipped":true}`
		}
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", decoy, u, answer)
	}

	var all ux.QuestionsResponse
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxreadsup"), "questions", &all))
	by := map[uint64]ux.Survey{}
	for _, s := range all.Questions {
		by[s.PromptID] = s
	}

	tally := map[string]ux.SurveyTally{}
	for _, tl := range by[survey].Tallies {
		tally[tl.Key] = tl
	}
	assert.Equal(t, int64(3), tally["a"].TimesShown)
	assert.Equal(t, int64(2), tally["a"].Most)
	assert.Equal(t, int64(1), tally["a"].Least)
	assert.Equal(t, int64(3), tally["b"].TimesShown)
	assert.Equal(t, int64(1), tally["b"].Least)
	assert.Equal(t, int64(1), tally["c"].Most)
	assert.Equal(t, int64(3), tally["e"].TimesShown)
	assert.Equal(t, int64(1), tally["f"].TimesShown)
	assert.Equal(t, int64(1), by[survey].None, "none of these is counted on its own")
	assert.Equal(t, int64(4), by[survey].Responses)

	r := by[real]
	assert.False(t, r.Decoy)
	assert.Equal(t, [3]int64{2, 1, 1}, [3]int64{r.Yes, r.No, r.NotSure})
	assert.InDelta(t, 0.5, r.RightShare, 0.001, "two of four said yes, which is right for a real feature")
	assert.Equal(t, int64(4), r.Tallies[0].Count+r.Tallies[1].Count+r.Tallies[2].Count)

	d := by[decoy]
	assert.True(t, d.Decoy)
	assert.Equal(t, [3]int64{1, 3, 0}, [3]int64{d.Yes, d.No, d.NotSure})
	assert.Equal(t, int64(1), d.Skipped)
	assert.InDelta(t, 0.75, d.RightShare, 0.001, "three of four said no, which is right for a decoy")

	// The top-level rate is yes over all answers to every decoy in the read.
	var yes, answered int64
	for _, s := range all.Questions {
		if s.Decoy {
			yes += s.Yes
			answered += s.Yes + s.No + s.NotSure
		}
	}
	require.NotZero(t, answered)
	assert.Equal(t, answered, all.DecoyAnswers)
	assert.InDelta(t, float64(yes)/float64(answered), all.DecoyYesRate, 0.001)
}

// The questions can be narrowed to a kind and a status.
func TestUxQuestionsFilterByKindAndStatus(t *testing.T) {
	prefix := uniquePrefix("uxqfilter")
	uxPromptClean(t, prefix)
	db := database.DBConn
	survey := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)
	aw := insertPrompt(t, "awareness", prefix+" do you know how?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`)
	open := insertPrompt(t, "open", prefix+" did you have a problem?", `[]`)
	db.Exec("UPDATE ux_prompts SET status = 'closed' WHERE id = ?", aw)
	token := uxSupportToken(t, "uxqfiltersup")

	mine := func(path string) []uint64 {
		var out ux.QuestionsResponse
		require.Equal(t, 200, uxGet(t, token, path, &out), path)
		got := []uint64{}
		for _, s := range out.Questions {
			if strings.HasPrefix(s.Question, prefix) {
				got = append(got, s.PromptID)
			}
		}
		return got
	}

	assert.ElementsMatch(t, []uint64{survey, aw, open}, mine("questions"))
	assert.Equal(t, []uint64{aw}, mine("questions?kind=awareness"))
	assert.Equal(t, []uint64{survey}, mine("questions?kind=survey"))
	assert.Equal(t, []uint64{open}, mine("questions?kind=open"))
	assert.Equal(t, []uint64{aw}, mine("questions?status=closed"))
	assert.ElementsMatch(t, []uint64{survey, open}, mine("questions?status=open"))
	assert.Empty(t, mine("questions?kind=awareness&status=open"))
}

// A tally carries most, least, times shown and count, and no best or worst: nothing reads them.
func TestUxQuestionsHaveNoBestAndWorstAliases(t *testing.T) {
	prefix := uniquePrefix("uxalias")
	uxPromptClean(t, prefix)
	db := database.DBConn
	id := insertPrompt(t, "survey", prefix+" which most?", uxSixOptions)
	u := CreateTestUser(t, uniquePrefix("uxaliasuser"), "User")
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, ?)", id, u, `{"best":"a","worst":"b","shown":["a","b"]}`)

	var raw struct {
		Questions []map[string]interface{} `json:"questions"`
	}
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxaliassup"), "questions", &raw))
	var question map[string]interface{}
	for _, q := range raw.Questions {
		if q["prompt_id"] == float64(id) {
			question = q
		}
	}
	require.NotNil(t, question)

	tallies := question["tallies"].([]interface{})
	require.NotEmpty(t, tallies)
	for _, tl := range tallies {
		keys := []string{}
		for k := range tl.(map[string]interface{}) {
			keys = append(keys, k)
		}
		assert.ElementsMatch(t, []string{"key", "label", "most", "least", "times_shown", "count"}, keys)
	}
	// The question itself still carries the fields the tab reads.
	for _, k := range []string{"prompt_id", "kind", "flow", "question", "status", "responses", "tallies", "dont_care", "dont_understand", "nothing", "none",
		"skipped", "analysed", "analysed_answers", "comments", "yes", "no", "not_sure", "decoy", "right_share"} {
		assert.Contains(t, question, k)
	}
}

// The comments left on a question with options are listed with its figures, newest first: trimmed, cut
// at 300 characters and at most 20 of them, and a blank one is left out. An open question lists none,
// even from a row that holds a comment, because what a member wrote there is the answer.
func TestUxQuestionsListCommentsOnQuestionsWithOptionsOnly(t *testing.T) {
	prefix := uniquePrefix("uxcomments")
	uxPromptClean(t, prefix)
	db := database.DBConn
	aware := insertPrompt(t, "awareness", prefix+" do you know how?", `[{"key":"yes","label":"Yes"},{"key":"no","label":"No"},{"key":"not_sure","label":"Not sure"}]`)
	open := insertPrompt(t, "open", prefix+" what went wrong?", `[]`)

	// Newest first: one over the cut, a blank one, one with spaces round it, then 21 plain ones.
	comments := []string{strings.Repeat("x", 400), "   ", "  padded  "}
	for i := 3; i < 24; i++ {
		comments = append(comments, fmt.Sprintf("comment %02d", i))
	}
	for i, c := range comments {
		u := CreateTestUser(t, uniquePrefix(fmt.Sprintf("uxcommentu%d", i)), "User")
		db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer, comment, timestamp) VALUES (?, ?, '{\"choice\":\"yes\"}', ?, NOW() - INTERVAL ? MINUTE)", aware, u, c, i)
	}
	u := CreateTestUser(t, uniquePrefix("uxcommentopen"), "User")
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer, comment) VALUES (?, ?, '{\"text\":\"it broke\"}', 'not for the list')", open, u)

	var all ux.QuestionsResponse
	require.Equal(t, 200, uxGet(t, uxSupportToken(t, "uxcommentssup"), "questions", &all))
	by := map[uint64]ux.Survey{}
	for _, s := range all.Questions {
		by[s.PromptID] = s
	}

	got := by[aware].Comments
	require.Len(t, got, 20, "no more than 20 are listed")
	assert.Equal(t, strings.Repeat("x", 300), got[0], "a long comment is cut at 300 characters")
	assert.Equal(t, "padded", got[1], "spaces are trimmed, and the blank comment before it is not listed")
	assert.Equal(t, "comment 03", got[2])
	assert.Equal(t, "comment 20", got[19], "the 20th is the last, so comment 21 is not listed")
	assert.NotContains(t, got, "comment 21")
	assert.Equal(t, int64(24), by[aware].Responses, "every answer is still counted")

	assert.Empty(t, by[open].Comments, "an open question lists no comment")
	assert.Equal(t, int64(1), by[open].Responses)
}

// The verified quotes and the ranking figures are stored in the problem's evidence by the batch;
// the tab can only show them if the routes send them.
func TestUxProblemsCarryQuotesAndTheRankingFigures(t *testing.T) {
	prefix := uniquePrefix("uxquote")
	uxPromptClean(t, prefix)
	token := uxSupportToken(t, "uxquotesup")
	db := database.DBConn
	give := "give"

	ranked := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" photos are slow", 3, 0.25, nil, nil)
	value := uxInsertProblem(t, nil, "value", nil, prefix+" it is free", 2, nil, nil, nil)
	bare := uxInsertProblem(t, &give, "problem", "Photos and posting", prefix+" nothing stored", 1, nil, nil, nil)
	db.Exec("UPDATE ux_problems SET evidence = ? WHERE id = ?",
		`{"answer_ids":[1,2],"quotes":[{"answer_id":1,"span":"photos took ages"},{"answer_id":2,"span":"it froze"}],"grade":4,"ranking":{"prompt_id":9,"most":12,"times_shown":40}}`, ranked)
	db.Exec("UPDATE ux_problems SET evidence = ? WHERE id = ?", `{"answer_ids":[3],"quotes":[{"answer_id":3,"span":"free is the point"}]}`, value)

	var raw map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "problems?q="+prefix, &raw))
	find := func(id uint64, items []interface{}) map[string]interface{} {
		for _, i := range items {
			m := i.(map[string]interface{})
			if uint64(m["id"].(float64)) == id {
				return m
			}
		}
		return nil
	}
	problems := raw["problems"].([]interface{})

	r := find(ranked, problems)
	require.NotNil(t, r)
	quotes := r["quotes"].([]interface{})
	require.Len(t, quotes, 2)
	assert.Equal(t, "photos took ages", quotes[0].(map[string]interface{})["span"])
	assert.Equal(t, float64(1), quotes[0].(map[string]interface{})["answer_id"])
	assert.Equal(t, float64(12), r["most"])
	assert.Equal(t, float64(40), r["times_shown"])
	assert.NotContains(t, r, "least", "a follow-up has one pick")

	b := find(bare, problems)
	require.NotNil(t, b)
	assert.Equal(t, []interface{}{}, b["quotes"], "no quotes is an empty list, not null")
	assert.Nil(t, b["most"], "a problem never ranked has no figures to send")
	assert.Nil(t, b["times_shown"])
	assert.Nil(t, b["weight"])

	require.Equal(t, 200, uxGet(t, token, "values", &raw))
	v := find(value, raw["values"].([]interface{}))
	require.NotNil(t, v)
	assert.Equal(t, "free is the point", v["quotes"].([]interface{})[0].(map[string]interface{})["span"])
}

// The survey routes read their own three tables and nothing else. The routes and the card's server
// code name no table that belongs to another part of the UX work.
func TestUxSurveysBoundary(t *testing.T) {
	files := []string{"../ux/surveys.go", "../microvolunteering/uxprompt.go"}
	read := 0
	for _, f := range files {
		b, err := os.ReadFile(f)
		require.NoError(t, err, f)
		require.NotEmpty(t, b, f+" is empty")
		read++
		for _, name := range []string{"ux_experiments", "ux_experiment_reads", "ux_ideas"} {
			assert.NotContains(t, string(b), name, f)
		}
	}
	require.Len(t, files, 2)
	assert.Equal(t, 2, read, "both files were read")
}
