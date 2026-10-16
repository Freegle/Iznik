package ux

import (
	"encoding/json"
	"sort"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// The survey side of the UX tab: what members said, coded into problems under a fixed list of
// groups, the things they value, and how each question did. The rows are written by the Laravel
// batch (UxPromptService) and the member card; this file only reads them, and only for Support
// and Admin. docs/developers/reference/ux-member-surveys.md.
//
// Four routes: problem-groups (the headings with their size), problems (the list under one
// heading, or all of them), values and questions. They read ux_problems, ux_prompts and
// ux_prompt_responses and nothing else.

const (
	// otherGroup is the one group name this code knows: what fits none of the others. It sorts
	// last, and a problem stored with no group is counted under it.
	otherGroup = "Other"
	// noFlow, as the value of the flow parameter, means problems tied to no funnel.
	noFlow = "none"
	// maxSearchChars is the longest search text; maxGroupChars the longest group name.
	maxSearchChars = 60
	maxGroupChars  = 40

	defaultProblemLimit = 30
	maxProblemLimit     = 100
	// maxSuggestions bounds the suggested fixes listed under one problem.
	maxSuggestions = 10
	maxValues      = 20
	// maxSurveys bounds the questions listed; an open question, its follow-up and the older
	// surveys all share it.
	maxSurveys  = 30
	maxComments = 20
)

// Quote is words copied from one member's answer that the batch checked really are in it. It is
// shown in ModTools only, escaped, behind a toggle.
type Quote struct {
	AnswerID uint64 `json:"answer_id"`
	Span     string `json:"span"`
}

// GroupBiggest is the problem with the most mentions in a group.
type GroupBiggest struct {
	ID       uint64 `json:"id"`
	Title    string `json:"title"`
	Mentions int64  `json:"mentions"`
	// Answers is how many answers the question it came from was analysed over.
	Answers int64 `json:"answers"`
}

// ProblemGroup is one heading: how many problems are filed under it, how many members raised them
// (the sum of their mentions) and the biggest of them.
type ProblemGroup struct {
	Name     string       `json:"name"`
	Problems int64        `json:"problems"`
	Mentions int64        `json:"mentions"`
	Biggest  GroupBiggest `json:"biggest"`
}

type ProblemGroupsResponse struct {
	// Answers is the largest number of answers any of these problems was coded from.
	Answers int64 `json:"answers"`
	// LeftOver is how many answers fitted no problem (the sum of mentions of the leftover rows).
	LeftOver int64          `json:"left_over"`
	Groups   []ProblemGroup `json:"groups"`
}

// Suggestion is a fix a member suggested for a problem, listed under that problem.
type Suggestion struct {
	ID       uint64 `json:"id"`
	Title    string `json:"title"`
	Mentions int64  `json:"mentions"`
}

// Problem is a problem members told us about. Weight is how often it was picked as the biggest in
// the follow-up when it was shown, once there is one: null means nobody has been asked yet.
type Problem struct {
	ID          uint64  `json:"id"`
	Title       string  `json:"title"`
	Description string  `json:"description"`
	Flow        *string `json:"flow"`
	Group       string  `json:"group"`
	Mentions    int64   `json:"mentions"`
	// Answers is how many answers the question this problem came from was analysed over, the
	// base for Mentions ("14 of 120"): the count stored when it was analysed, not how many have
	// arrived since, because the themes were drawn from the first. It is per problem because the
	// tab can hold several analysed questions, and a number from one question over another's
	// answers is wrong. Zero for a problem that did not come from a question.
	Answers int64 `json:"answers"`
	// Asked is how many members ranked the problems in the follow-up, the base for Weight. Zero
	// until there is a follow-up with answers.
	Asked  int64    `json:"asked"`
	Weight *float64 `json:"weight"`
	// Most and TimesShown are the figures Weight was worked out from, read from the ranking the
	// tick stored in the problem's evidence. They are null until the problem has been shown in a
	// follow-up.
	Most        *int64       `json:"most"`
	TimesShown  *int64       `json:"times_shown"`
	Quotes      []Quote      `json:"quotes"`
	Suggestions []Suggestion `json:"suggestions"`
	Status      string       `json:"status"`
}

type ProblemsResponse struct {
	// Total is how many problems match the filters, counted before the limit is applied.
	Total    int64     `json:"total"`
	Problems []Problem `json:"problems"`
}

// Value is something members said they value about Freegle.
type Value struct {
	ID       uint64 `json:"id"`
	Title    string `json:"title"`
	Mentions int64  `json:"mentions"`
	// Answers is how many answers the question it came from was analysed over (see Problem.Answers).
	Answers int64   `json:"answers"`
	Quotes  []Quote `json:"quotes"`
}

type ValuesResponse struct {
	Values []Value `json:"values"`
}

// SurveyTally is what members said per option: most and least for a ranking, count for an
// awareness question answered with one option. TimesShown is how often the option was on the
// screen (from the shown lists stored with answers), the base for a rate: an option shown 40
// times and picked 10 times is a different story from one shown 400 times. A follow-up has one
// pick, so its Least is 0.
type SurveyTally struct {
	Key        string `json:"key"`
	Label      string `json:"label"`
	Most       int64  `json:"most"`
	Least      int64  `json:"least"`
	TimesShown int64  `json:"times_shown"`
	Count      int64  `json:"count"`
}

// Survey is one question and how it did. Responses never includes a skip. An open question (free
// text) lists no answers here: they are member content and are read one question at a time, not in
// this overview.
type Survey struct {
	PromptID       uint64        `json:"prompt_id"`
	Kind           string        `json:"kind"`
	Flow           string        `json:"flow"`
	Question       string        `json:"question"`
	Status         string        `json:"status"`
	Responses      int64         `json:"responses"`
	Tallies        []SurveyTally `json:"tallies"`
	DontCare       int64         `json:"dont_care"`
	DontUnderstand int64         `json:"dont_understand"`
	Nothing        int64         `json:"nothing"`
	// None is "None of these" on a ranking: a real answer, and the reason a rate of picks is
	// taken over times shown rather than over responses.
	None     int64 `json:"none"`
	Skipped  int64 `json:"skipped"`
	Analysed bool  `json:"analysed"`
	// AnalysedAnswers is how many written answers the themes were drawn from. Answers keep
	// arriving while the question is open, so it can be fewer than the written answers now.
	AnalysedAnswers int64    `json:"analysed_answers"`
	Comments        []string `json:"comments"`
	// Awareness only. Yes, No and NotSure are the answers to "Do you know how to ...?". A Decoy
	// asks about something Freegle does not do, so its right answer is no. RightShare is the
	// share of those three answers that were right (0 until someone answers).
	Yes        int64   `json:"yes"`
	No         int64   `json:"no"`
	NotSure    int64   `json:"not_sure"`
	Decoy      bool    `json:"decoy"`
	RightShare float64 `json:"right_share"`
}

type QuestionsResponse struct {
	Questions []Survey `json:"questions"`
	// DecoyYesRate is the share of answers to decoy awareness questions that said yes. Members
	// who say yes to something that does not exist are agreeing without knowing, so the figure is
	// shown next to the real questions to let a reader discount them. DecoyAnswers is the base.
	DecoyYesRate float64 `json:"decoy_yes_rate"`
	DecoyAnswers int64   `json:"decoy_answers"`
}

// ---- the filters the problem routes share ----

// problemFilter is what the problem routes can be asked to narrow to.
type problemFilter struct {
	flow  string // "" for every funnel, noFlow for none, or a funnel key
	group string // "" for every group
	q     string
	min   int64
}

// escapeLike makes text safe to put inside a LIKE pattern: the characters that mean something in
// one are taken literally.
func escapeLike(s string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
}

// parseProblemFilter reads flow, group, q and min. A bad flow or min is a 400; text that is too
// long is cut.
func parseProblemFilter(c *fiber.Ctx) (problemFilter, error) {
	flow, err := parseFlow(c)
	if err != nil {
		return problemFilter{}, err
	}
	f := problemFilter{flow: flow, group: clip(c.Query("group"), maxGroupChars), q: clip(c.Query("q"), maxSearchChars)}
	if raw := c.Query("min"); raw != "" {
		n, err := strconv.ParseInt(raw, 10, 64)
		if err != nil || n < 0 {
			return problemFilter{}, fiber.NewError(fiber.StatusBadRequest, "invalid min")
		}
		f.min = n
	}
	return f, nil
}

// flowOnly narrows a query on ux_problems to a funnel, or to none.
func flowOnly(q *gorm.DB, flow string) *gorm.DB {
	switch flow {
	case "":
		return q
	case noFlow:
		return q.Where("(flow IS NULL OR flow = '')")
	}
	return q.Where("flow = ?", flow)
}

// apply narrows a query on ux_problems to the problems that match: kind problem, not dismissed,
// then the funnel, the group, the search text and the least mentions.
func (f problemFilter) apply(q *gorm.DB) *gorm.DB {
	q = flowOnly(q.Where("kind = 'problem' AND status != 'dismissed'"), f.flow)
	switch f.group {
	case "":
	case otherGroup:
		q = q.Where("(group_name = ? OR group_name IS NULL OR group_name = '')", otherGroup)
	default:
		q = q.Where("group_name = ?", f.group)
	}
	if f.q != "" {
		pattern := "%" + escapeLike(f.q) + "%"
		q = q.Where("(title LIKE ? OR description LIKE ?)", pattern, pattern)
	}
	if f.min > 0 {
		q = q.Where("mentions >= ?", f.min)
	}
	return q
}

// groupOf is the group a stored problem is shown under: the one it has, else Other.
func groupOf(stored *string) string {
	if stored == nil || strings.TrimSpace(*stored) == "" {
		return otherGroup
	}
	return *stored
}

// orderProblems puts the most mentioned first, then the higher weight (one not yet ranked last),
// then the older.
const orderProblems = "mentions DESC, weight IS NULL, weight DESC, id ASC"

// ---- problem-groups ----

// ProblemGroups lists the headings problems are filed under, each with how many problems it holds
// and how many members raised them, the biggest first and Other last. A heading with no problem is
// left out. Support/Admin only.
func ProblemGroups(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	f, err := parseProblemFilter(c)
	if err != nil {
		return err
	}
	// The group is what this route lists, so it is not a filter here.
	f.group = ""
	db := database.DBConn

	// Ordered so that the first problem seen in each group is its biggest.
	var rows []struct {
		ID       uint64   `gorm:"column:id"`
		Title    string   `gorm:"column:title"`
		Group    *string  `gorm:"column:group_name"`
		Mentions int64    `gorm:"column:mentions"`
		Weight   *float64 `gorm:"column:weight"`
		PromptID *uint64  `gorm:"column:prompt_id"`
	}
	f.apply(db.Table("ux_problems")).Select("id, title, group_name, mentions, weight, prompt_id").Order(orderProblems).Scan(&rows)

	promptIDs := []uint64{}
	for _, r := range rows {
		if r.PromptID != nil {
			promptIDs = append(promptIDs, *r.PromptID)
		}
	}

	var leftOver struct {
		Total int64 `gorm:"column:total"`
	}
	flowOnly(db.Table("ux_problems").Where("kind = 'other' AND status != 'dismissed'"), f.flow).
		Select("COALESCE(SUM(mentions), 0) AS total").Scan(&leftOver)
	var otherPrompts []uint64
	flowOnly(db.Table("ux_problems").Where("kind = 'other' AND status != 'dismissed' AND prompt_id IS NOT NULL"), f.flow).
		Distinct().Pluck("prompt_id", &otherPrompts)
	promptIDs = append(promptIDs, otherPrompts...)

	answers, _ := promptBases(promptIDs)
	out := ProblemGroupsResponse{LeftOver: leftOver.Total, Groups: []ProblemGroup{}}
	for _, n := range answers {
		if n > out.Answers {
			out.Answers = n
		}
	}

	byName := map[string]*ProblemGroup{}
	for _, r := range rows {
		name := groupOf(r.Group)
		g, ok := byName[name]
		if !ok {
			g = &ProblemGroup{Name: name, Biggest: GroupBiggest{ID: r.ID, Title: r.Title, Mentions: r.Mentions, Answers: answerBase(r.PromptID, answers)}}
			byName[name] = g
		}
		g.Problems++
		g.Mentions += r.Mentions
	}
	for _, g := range byName {
		out.Groups = append(out.Groups, *g)
	}
	// Most mentions first; Other last whatever its size; ties by name so the order is stable.
	sort.Slice(out.Groups, func(i, j int) bool {
		a, b := out.Groups[i], out.Groups[j]
		if (a.Name == otherGroup) != (b.Name == otherGroup) {
			return b.Name == otherGroup
		}
		if a.Mentions != b.Mentions {
			return a.Mentions > b.Mentions
		}
		return a.Name < b.Name
	})
	return c.JSON(out)
}

// ---- problems ----

// parseProblemLimit reads limit: 30 by default, at most 100.
func parseProblemLimit(c *fiber.Ctx) (int, error) {
	raw := c.Query("limit")
	if raw == "" {
		return defaultProblemLimit, nil
	}
	n, err := strconv.Atoi(raw)
	if err != nil || n < 1 {
		return 0, fiber.NewError(fiber.StatusBadRequest, "invalid limit")
	}
	if n > maxProblemLimit {
		n = maxProblemLimit
	}
	return n, nil
}

// Problems lists the problems members raised, with the fixes suggested for each, the quotes the
// batch checked and how the follow-up ranked them. It can be narrowed to a group, a funnel (or
// none), a search text and a least number of mentions. Total is the number that match, so a caller
// can say "30 of 45" and ask again with a higher limit. Support/Admin only.
func Problems(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	f, err := parseProblemFilter(c)
	if err != nil {
		return err
	}
	limit, err := parseProblemLimit(c)
	if err != nil {
		return err
	}
	db := database.DBConn

	var total int64
	f.apply(db.Table("ux_problems")).Count(&total)

	var rows []struct {
		ID          uint64   `gorm:"column:id"`
		Flow        *string  `gorm:"column:flow"`
		Group       *string  `gorm:"column:group_name"`
		Title       string   `gorm:"column:title"`
		Description *string  `gorm:"column:description"`
		PromptID    *uint64  `gorm:"column:prompt_id"`
		Mentions    int64    `gorm:"column:mentions"`
		Weight      *float64 `gorm:"column:weight"`
		Status      string   `gorm:"column:status"`
		Evidence    *string  `gorm:"column:evidence"`
	}
	f.apply(db.Table("ux_problems")).
		Select("id, flow, group_name, title, description, prompt_id, mentions, weight, status, evidence").
		Order(orderProblems).Limit(limit).Scan(&rows)

	ids := []uint64{}
	promptIDs := []uint64{}
	for _, r := range rows {
		ids = append(ids, r.ID)
		if r.PromptID != nil {
			promptIDs = append(promptIDs, *r.PromptID)
		}
	}
	answers, asked := promptBases(promptIDs)

	// The fixes members suggested sit under the problem they answer, and are never problems of
	// their own: this is the only place they are listed.
	suggestions := map[uint64][]Suggestion{}
	if len(ids) > 0 {
		var subs []struct {
			ID       uint64 `gorm:"column:id"`
			ParentID uint64 `gorm:"column:parent_id"`
			Title    string `gorm:"column:title"`
			Mentions int64  `gorm:"column:mentions"`
		}
		db.Table("ux_problems").Select("id, parent_id, title, mentions").
			Where("kind = 'suggestion' AND status != 'dismissed' AND parent_id IN ?", ids).
			Order("mentions DESC, id ASC").Scan(&subs)
		for _, s := range subs {
			if len(suggestions[s.ParentID]) < maxSuggestions {
				suggestions[s.ParentID] = append(suggestions[s.ParentID], Suggestion{ID: s.ID, Title: s.Title, Mentions: s.Mentions})
			}
		}
	}

	out := ProblemsResponse{Total: total, Problems: []Problem{}}
	for _, r := range rows {
		ev := parseProblemEvidence(r.Evidence)
		p := Problem{ID: r.ID, Title: r.Title, Group: groupOf(r.Group), Mentions: r.Mentions, Weight: r.Weight, Status: r.Status,
			Answers: answerBase(r.PromptID, answers), Quotes: ev.Quotes, Suggestions: []Suggestion{}}
		if r.Flow != nil && *r.Flow != "" {
			p.Flow = r.Flow
		}
		if r.Description != nil {
			p.Description = *r.Description
		}
		if r.PromptID != nil {
			p.Asked = asked[*r.PromptID]
		}
		if ev.Ranking != nil {
			p.Most, p.TimesShown = &ev.Ranking.Most, &ev.Ranking.TimesShown
		}
		if v, ok := suggestions[r.ID]; ok {
			p.Suggestions = v
		}
		out.Problems = append(out.Problems, p)
	}
	return c.JSON(out)
}

// ---- values ----

// Values lists what members said they value about Freegle, the most mentioned first. Support/Admin
// only.
func Values(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	var rows []struct {
		ID       uint64  `gorm:"column:id"`
		Title    string  `gorm:"column:title"`
		PromptID *uint64 `gorm:"column:prompt_id"`
		Mentions int64   `gorm:"column:mentions"`
		Evidence *string `gorm:"column:evidence"`
	}
	database.DBConn.Table("ux_problems").Select("id, title, prompt_id, mentions, evidence").
		Where("kind = 'value' AND status != 'dismissed'").
		Order("mentions DESC, id ASC").Limit(maxValues).Scan(&rows)

	promptIDs := []uint64{}
	for _, r := range rows {
		if r.PromptID != nil {
			promptIDs = append(promptIDs, *r.PromptID)
		}
	}
	answers, _ := promptBases(promptIDs)

	out := ValuesResponse{Values: []Value{}}
	for _, r := range rows {
		out.Values = append(out.Values, Value{ID: r.ID, Title: r.Title, Mentions: r.Mentions, Answers: answerBase(r.PromptID, answers), Quotes: parseProblemEvidence(r.Evidence).Quotes})
	}
	return c.JSON(out)
}

// ---- the figures behind problems and values ----

func answerBase(promptID *uint64, answers map[uint64]int64) int64 {
	if promptID == nil {
		return 0
	}
	return answers[*promptID]
}

// problemEvidence is the part of ux_problems.evidence the routes read: the quotes the batch
// checked against the answers, and the ranking figures the tick stores beside the weight.
type problemEvidence struct {
	Quotes  []Quote `json:"quotes"`
	Ranking *struct {
		Most       int64 `json:"most"`
		TimesShown int64 `json:"times_shown"`
	} `json:"ranking"`
}

// parseProblemEvidence reads the evidence column, which may be empty or an older shape. Quotes
// is never nil so the JSON carries [] rather than null.
func parseProblemEvidence(raw *string) problemEvidence {
	ev := problemEvidence{}
	if raw != nil && *raw != "" {
		_ = json.Unmarshal([]byte(*raw), &ev)
	}
	if ev.Quotes == nil {
		ev.Quotes = []Quote{}
	}
	return ev
}

// promptBases returns, for each open question that problems came from, how many answers it was
// analysed over and how many members ranked its problems in the follow-up (a pick, or "none of
// these"; "I don't understand" is not a ranking). These are the bases shown next to mentions and
// weights.
//
// The analysed count is the one stored in the prompt's settings when it was analysed, because the
// themes were drawn from those answers and not from ones that arrived later. A prompt with none
// stored falls back to its written answers (a skip, "no problems" and "I don't understand" are
// not words).
func promptBases(promptIDs []uint64) (answers map[uint64]int64, asked map[uint64]int64) {
	answers = map[uint64]int64{}
	asked = map[uint64]int64{}
	if len(promptIDs) == 0 {
		return
	}
	db := database.DBConn

	var stored []struct {
		ID uint64 `gorm:"column:id"`
		N  *int64 `gorm:"column:n"`
	}
	db.Table("ux_prompts").
		Select("id, CAST(JSON_EXTRACT(settings, '$.analysed_answers') AS SIGNED) AS n").
		Where("id IN ?", promptIDs).Scan(&stored)
	missing := []uint64{}
	for _, r := range stored {
		if r.N != nil {
			answers[r.ID] = *r.N
		} else {
			missing = append(missing, r.ID)
		}
	}
	if len(missing) > 0 {
		var a []struct {
			PromptID uint64 `gorm:"column:prompt_id"`
			N        int64  `gorm:"column:n"`
		}
		db.Table("ux_prompt_responses").Select("prompt_id, COUNT(*) AS n").
			Where("prompt_id IN ?", missing).
			Where("JSON_TYPE(JSON_EXTRACT(answer, '$.text')) = 'STRING' AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(answer, '$.text'))) <> ''").
			Group("prompt_id").Scan(&a)
		for _, r := range a {
			answers[r.PromptID] = r.N
		}
	}

	var f []struct {
		FollowupOf uint64 `gorm:"column:followup_of"`
		N          int64  `gorm:"column:n"`
	}
	followupOf := "CAST(JSON_UNQUOTE(JSON_EXTRACT(p.settings, '$.followup_of')) AS UNSIGNED)"
	db.Table("ux_prompts p").
		Select(followupOf+" AS followup_of, COUNT(r.id) AS n").
		Joins("INNER JOIN ux_prompt_responses r ON r.prompt_id = p.id").
		Where("p.kind = 'survey'").
		Where(followupOf+" IN ?", promptIDs).
		Where("(JSON_EXTRACT(r.answer, '$.best') IS NOT NULL OR JSON_EXTRACT(r.answer, '$.worst') IS NOT NULL" +
			" OR JSON_UNQUOTE(JSON_EXTRACT(r.answer, '$.choice')) IN ('dont_care', 'none'))").
		Group("followup_of").Scan(&f)
	for _, r := range f {
		asked[r.FollowupOf] += r.N
	}
	return
}

// ---- questions ----

// Questions lists the survey, awareness and open questions with how each did: what members chose
// per option, the ways out, the comments left on closed questions, and for awareness questions the
// right answers. It can be narrowed to a kind and a status. The decoy figures sit beside the
// questions so a reader can discount how readily this sample says yes. Support/Admin only.
func Questions(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}
	kind, status := c.Query("kind"), c.Query("status")
	if kind != "" && kind != "survey" && kind != "awareness" && kind != "open" {
		return fiber.NewError(fiber.StatusBadRequest, "invalid kind")
	}
	if status != "" && status != "open" && status != "closed" {
		return fiber.NewError(fiber.StatusBadRequest, "invalid status")
	}

	questions := surveyTallies(kind, status)
	out := QuestionsResponse{Questions: questions}
	var decoyYes int64
	for _, s := range questions {
		if s.Kind == "awareness" && s.Decoy {
			decoyYes += s.Yes
			out.DecoyAnswers += s.Yes + s.No + s.NotSure
		}
	}
	if out.DecoyAnswers > 0 {
		out.DecoyYesRate = share(decoyYes, out.DecoyAnswers)
	}
	return c.JSON(out)
}

// answerShape is every key an answer can carry. Open text is deliberately not here: the overview
// counts answers and never reads what they said.
type answerShape struct {
	Choice  string `json:"choice"`
	Best    string `json:"best"`
	Worst   string `json:"worst"`
	Skipped bool   `json:"skipped"`
	// Shown is the option keys the member saw when they answered.
	Shown []string `json:"shown"`
}

// surveyTallies counts, for every survey, awareness and open question, what members said per
// option: {"best": "<key>", "worst": "<key>"} or {"choice": "<key>"}, with the ways out apart and
// skips left out of every figure but their own count. Three queries however many questions.
func surveyTallies(kind, status string) []Survey {
	db := database.DBConn
	var prompts []struct {
		ID       uint64  `gorm:"column:id"`
		Kind     string  `gorm:"column:kind"`
		Flow     *string `gorm:"column:flow"`
		Question string  `gorm:"column:question"`
		Options  string  `gorm:"column:options"`
		Status   string  `gorm:"column:status"`
		Settings *string `gorm:"column:settings"`
	}
	pq := db.Table("ux_prompts").Select("id, kind, flow, question, options, status, settings").Where("kind IN ('survey', 'awareness', 'open')")
	if kind != "" {
		pq = pq.Where("kind = ?", kind)
	}
	if status != "" {
		pq = pq.Where("status = ?", status)
	}
	pq.Order("id DESC").Limit(maxSurveys).Scan(&prompts)
	out := []Survey{}
	if len(prompts) == 0 {
		return out
	}
	promptIDs := make([]uint64, 0, len(prompts))
	for _, p := range prompts {
		promptIDs = append(promptIDs, p.ID)
	}

	// JSON_REMOVE drops the free text on the way out: this overview counts what was said and
	// never carries it.
	var responses []struct {
		PromptID uint64  `gorm:"column:prompt_id"`
		Answer   string  `gorm:"column:answer"`
		Comment  *string `gorm:"column:comment"`
	}
	db.Table("ux_prompt_responses").Select("prompt_id, JSON_REMOVE(answer, '$.text') AS answer, comment").
		Where("prompt_id IN ?", promptIDs).Order("timestamp DESC").Scan(&responses)
	byPrompt := map[uint64][]int{}
	for i, r := range responses {
		byPrompt[r.PromptID] = append(byPrompt[r.PromptID], i)
	}

	// A question counts as analysed once its answers have produced problems.
	var analysedIDs []uint64
	db.Table("ux_problems").Where("prompt_id IN ?", promptIDs).Distinct().Pluck("prompt_id", &analysedIDs)
	analysed := map[uint64]bool{}
	for _, id := range analysedIDs {
		analysed[id] = true
	}

	analysedOver, _ := promptBases(analysedIDs)

	for _, p := range prompts {
		var options []struct {
			Key   string `json:"key"`
			Label string `json:"label"`
		}
		_ = json.Unmarshal([]byte(p.Options), &options)
		idx := map[string]int{}
		s := Survey{PromptID: p.ID, Kind: p.Kind, Question: p.Question, Status: p.Status, Tallies: []SurveyTally{}, Comments: []string{}, Analysed: analysed[p.ID], AnalysedAnswers: analysedOver[p.ID]}
		if p.Flow != nil {
			s.Flow = *p.Flow
		}
		if p.Settings != nil {
			var settings struct {
				Decoy bool `json:"decoy"`
			}
			if json.Unmarshal([]byte(*p.Settings), &settings) == nil {
				s.Decoy = p.Kind == "awareness" && settings.Decoy
			}
		}
		if p.Kind != "open" {
			for _, o := range options {
				idx[o.Key] = len(s.Tallies)
				s.Tallies = append(s.Tallies, SurveyTally{Key: o.Key, Label: o.Label})
			}
		}
		for _, i := range byPrompt[p.ID] {
			r := responses[i]
			var a answerShape
			_ = json.Unmarshal([]byte(r.Answer), &a)
			if a.Skipped {
				s.Skipped++
				continue
			}
			if j, ok := idx[a.Best]; ok {
				s.Tallies[j].Most++
			}
			if j, ok := idx[a.Worst]; ok {
				s.Tallies[j].Least++
			}
			for _, k := range a.Shown {
				if j, ok := idx[k]; ok {
					s.Tallies[j].TimesShown++
				}
			}
			if p.Kind == "awareness" {
				switch a.Choice {
				case "yes":
					s.Yes++
				case "no":
					s.No++
				case "not_sure":
					s.NotSure++
				}
			}
			switch a.Choice {
			case "dont_care":
				s.DontCare++
			case "nothing":
				s.Nothing++
			case "none":
				s.None++
			case "dont_understand":
				s.DontUnderstand++
				// An awareness question lists "I don't understand" as one of its own options, so
				// its row has to carry the figure too or the table shows 0 beside a note that
				// says otherwise.
				if j, ok := idx[a.Choice]; ok {
					s.Tallies[j].Count++
				}
			default:
				if j, ok := idx[a.Choice]; ok {
					s.Tallies[j].Count++
				}
			}
			s.Responses++
			if p.Kind != "open" && r.Comment != nil && strings.TrimSpace(*r.Comment) != "" && len(s.Comments) < maxComments {
				s.Comments = append(s.Comments, clip(*r.Comment, 300))
			}
		}
		if answered := s.Yes + s.No + s.NotSure; answered > 0 {
			// The right answer is yes for something Freegle does, no for a decoy.
			right := s.Yes
			if s.Decoy {
				right = s.No
			}
			s.RightShare = share(right, answered)
		}
		out = append(out, s)
	}
	return out
}
