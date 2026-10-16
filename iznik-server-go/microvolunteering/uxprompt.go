package microvolunteering

import (
	"encoding/json"
	"math/rand"
	"sort"
	"strings"
	"unicode"

	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// The UxPrompt challenge: a plain-English question for microvolunteers about Freegle itself. A
// survey of which parts work best and which most need improving, an awareness question (do you
// know how to ...), or an open question answered in the member's own words. The prompts are rows
// in ux_prompts, written by the batch; answers go to ux_prompt_responses, never to microactions,
// so a new prompt needs no schema change. docs/developers/reference/ux-member-surveys.md.
//
// The answers are a reaction gauge with a known status-quo bias. They say where members feel
// friction and what they have not found; they are shown on the SysAdmin UX tab and decide nothing.

const ChallengeUxPrompt = "UxPrompt"

// A member is asked at most once a week, whatever is open. A skip counts: someone who pressed
// "skip this one" has said they do not want a question right now. Repeated surveys depress later
// answers, so daily is a ceiling we do not aim at.
const uxPromptMinGapHours = 7 * 24

// After two skips in a row the member is left alone for a month: two skips is a clear "not
// now", and asking again sooner only teaches people to ignore the task.
const (
	uxPromptSkipsToBackOff   = 2
	uxPromptSkipBackoffHours = 30 * 24
)

// A survey shows this many options at a time unless its settings say otherwise. A phone screen
// holds three or four; more makes people satisfice.
const uxSurveyDefaultShow = 4

// uxServerOnlySettings are the settings the batch keeps on the prompt row for its own use. The card
// needs none of them, so they are not sent: which prompt a follow-up came from, how many answers
// the problems were coded from, and whether an awareness question is a decoy.
var uxServerOnlySettings = []string{"followup_of", "analysed_answers", "decoy"}

// An open question's free-text answer is this many characters, after trimming.
const (
	uxOpenTextMin = 3
	uxOpenTextMax = 1000
)

// The two ways out of an open question, so nobody has to invent an answer. Their labels come
// from the prompt's own options when it has them.
const (
	uxEscapeNothing        = "nothing"
	uxEscapeDontUnderstand = "dont_understand"
)

type UxPromptOption struct {
	Key   string `json:"key"`
	Label string `json:"label"`
}

type UxPromptImage struct {
	URL     string `json:"url"`
	Caption string `json:"caption"`
}

type UxPromptChallenge struct {
	ID       uint64           `json:"id"`
	Kind     string           `json:"kind"`
	Question string           `json:"question"`
	Body     string           `json:"body"`
	Options  []UxPromptOption `json:"options"`
	Images   []UxPromptImage  `json:"images"`
	// Settings passes the prompt's presentation through: for a survey show, worst_label (the second
	// question of the area survey) and none_label; randomise (show the options in a random order);
	// for an open question preset and placeholder; for an awareness question reveal and link. The
	// keys in uxServerOnlySettings are removed first. Always an object.
	Settings map[string]interface{} `json:"settings"`
	// Display is, for a survey, the option keys to show and the order to show them in: at most
	// settings.show of them, picked so every option is shown about equally often. The client shows
	// exactly these and returns them as shown. Absent for every other kind.
	Display []string `json:"display,omitempty"`
}

// openOptions makes sure an open prompt offers both escapes, whatever the row's options say.
func openOptions(options []UxPromptOption) []UxPromptOption {
	have := map[string]bool{}
	for _, o := range options {
		have[o.Key] = true
	}
	out := options
	if !have[uxEscapeNothing] {
		out = append(out, UxPromptOption{Key: uxEscapeNothing, Label: "I can't think of one"})
	}
	if !have[uxEscapeDontUnderstand] {
		out = append(out, UxPromptOption{Key: uxEscapeDontUnderstand, Label: "I don't understand"})
	}
	return out
}

// publicSettings is the prompt's settings without the keys the card does not need.
func publicSettings(settings map[string]interface{}) map[string]interface{} {
	for _, k := range uxServerOnlySettings {
		delete(settings, k)
	}
	return settings
}

// answeredCount is how many answers a prompt has. A skip is a response row (so the member is not
// asked again) but it is not an answer, so it never counts towards the cap or the ordering.
const answeredCount = "(SELECT COUNT(*) FROM ux_prompt_responses r WHERE r.prompt_id = p.id AND JSON_EXTRACT(r.answer, '$.skipped') IS NULL)"

// skippedRecently is true when the member's last two responses were both skips and the later of
// them is under 30 days old. An answer in between breaks the run, so one engaged answer puts the
// member back on the weekly cadence.
func skippedRecently(db *gorm.DB, userID uint64) bool {
	var last []struct {
		Skipped int `gorm:"column:skipped"`
		Hours   int `gorm:"column:hours"`
	}
	db.Table("ux_prompt_responses").
		Select("JSON_EXTRACT(answer, '$.skipped') IS NOT NULL AS skipped, TIMESTAMPDIFF(HOUR, timestamp, NOW()) AS hours").
		Where("userid = ?", userID).
		Order("timestamp DESC, id DESC").
		Limit(uxPromptSkipsToBackOff).
		Scan(&last)
	if len(last) < uxPromptSkipsToBackOff {
		return false
	}
	for _, r := range last {
		if r.Skipped == 0 {
			return false
		}
	}
	return last[0].Hours < uxPromptSkipBackoffHours
}

// getUxPromptChallenge picks an open prompt this member has not answered or skipped, the one with
// the fewest answers first so every prompt fills evenly, and nothing within a week of their last
// answer or skip (or a month after two skips in a row).
func getUxPromptChallenge(db *gorm.DB, userID uint64) *Challenge {
	var recent int64
	db.Table("ux_prompt_responses").
		Where("userid = ? AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)", userID, uxPromptMinGapHours).
		Count(&recent)
	if recent > 0 {
		return nil
	}
	if skippedRecently(db, userID) {
		return nil
	}

	var row struct {
		ID       uint64  `gorm:"column:id"`
		Kind     string  `gorm:"column:kind"`
		Question string  `gorm:"column:question"`
		Body     string  `gorm:"column:body"`
		Options  string  `gorm:"column:options"`
		Images   *string `gorm:"column:images"`
		Settings *string `gorm:"column:settings"`
	}
	res := db.Table("ux_prompts p").
		Select("p.id, p.kind, p.question, p.body, p.options, p.images, p.settings, "+answeredCount+" AS answered").
		Where("p.status = 'open'").
		Where("NOT EXISTS (SELECT 1 FROM ux_prompt_responses r WHERE r.prompt_id = p.id AND r.userid = ?)", userID).
		Where(answeredCount + " < p.max_responses").
		Order("answered ASC, p.id ASC").
		Limit(1).
		Scan(&row)
	if res.RowsAffected == 0 || row.ID == 0 {
		return nil
	}

	ch := &UxPromptChallenge{ID: row.ID, Kind: row.Kind, Question: row.Question, Body: row.Body, Options: []UxPromptOption{}, Images: []UxPromptImage{}, Settings: map[string]interface{}{}}
	_ = json.Unmarshal([]byte(row.Options), &ch.Options)
	if row.Images != nil {
		_ = json.Unmarshal([]byte(*row.Images), &ch.Images)
	}
	if row.Settings != nil {
		var settings map[string]interface{}
		if json.Unmarshal([]byte(*row.Settings), &settings) == nil && settings != nil {
			ch.Settings = publicSettings(settings)
		}
	}
	if row.Kind == "open" {
		ch.Options = openOptions(ch.Options)
	}
	if len(ch.Options) == 0 {
		return nil
	}
	if row.Kind == "survey" {
		ch.Display = surveyDisplay(db, row.ID, ch.Options, ch.Settings)
		if len(ch.Display) == 0 {
			return nil
		}
	}
	return &Challenge{Type: ChallengeUxPrompt, UxPrompt: ch}
}

// uxSurveyEscape is true for the keys that are ways out of a survey rather than things to rank.
func uxSurveyEscape(key string) bool {
	switch key {
	case "none", "dont_care", uxEscapeDontUnderstand, uxEscapeNothing:
		return true
	}
	return false
}

// surveyShow is how many options a survey shows at once, from settings.show.
func surveyShow(settings map[string]interface{}) int {
	if n, ok := settings["show"].(float64); ok && n >= 2 && n <= 12 {
		return int(n)
	}
	return uxSurveyDefaultShow
}

// surveyDisplay picks the options to show this member. Each option should appear about equally
// often across members, so the options shown fewest times so far (counted from the shown lists
// already stored with answers) come first, ties broken at random. The pick is then shuffled so
// position does not follow how often something was shown. Answers from before this existed have
// no shown list and count as nothing.
func surveyDisplay(db *gorm.DB, promptID uint64, options []UxPromptOption, settings map[string]interface{}) []string {
	keys := []string{}
	for _, o := range options {
		if !uxSurveyEscape(o.Key) {
			keys = append(keys, o.Key)
		}
	}

	times := map[string]int{}
	var rows []struct {
		Shown string `gorm:"column:shown"`
	}
	db.Table("ux_prompt_responses").
		Select("JSON_EXTRACT(answer, '$.shown') AS shown").
		Where("prompt_id = ? AND JSON_EXTRACT(answer, '$.shown') IS NOT NULL", promptID).
		Scan(&rows)
	for _, r := range rows {
		var shown []string
		if json.Unmarshal([]byte(r.Shown), &shown) == nil {
			for _, k := range shown {
				times[k]++
			}
		}
	}

	return pickLeastShown(keys, times, surveyShow(settings))
}

// pickLeastShown returns up to n of keys, fewest times shown first with random tie-breaks, in a
// random order.
func pickLeastShown(keys []string, times map[string]int, n int) []string {
	shuffled := append([]string{}, keys...)
	rand.Shuffle(len(shuffled), func(i, j int) { shuffled[i], shuffled[j] = shuffled[j], shuffled[i] })
	sort.SliceStable(shuffled, func(i, j int) bool { return times[shuffled[i]] < times[shuffled[j]] })
	if len(shuffled) > n {
		shuffled = shuffled[:n]
	}
	rand.Shuffle(len(shuffled), func(i, j int) { shuffled[i], shuffled[j] = shuffled[j], shuffled[i] })
	return shuffled
}

// UxPromptAnswer is what the member chose. An awareness question sends choice yes, no or
// not_sure; a survey sends best (and optionally a different worst, the second pick of the area
// survey), or choice set to none or dont_understand; an open question sends text, or choice set to
// nothing or dont_understand. Any kind can send skipped, which is recorded so the prompt is not
// offered again and is counted as nothing. Every other key must be one of the prompt's options.
//
// Shown is the option keys in the order the member saw them. It is what lets a reader tell "not
// chosen" from "not seen", so it is validated and stored with every closed answer.
type UxPromptAnswer struct {
	Choice  string   `json:"choice,omitempty"`
	Best    string   `json:"best,omitempty"`
	Worst   string   `json:"worst,omitempty"`
	Text    string   `json:"text,omitempty"`
	Shown   []string `json:"shown,omitempty"`
	Skipped bool     `json:"skipped,omitempty"`
}

// An awareness question has exactly these three answers: a "not sure" is not a "no", and the
// reader needs the difference.
var uxAwarenessChoices = map[string]bool{"yes": true, "no": true, "not_sure": true}

// checkShown validates a shown list against the keys that can be shown: each one a known key, no
// duplicates, no more than the options. A nil list means the client did not send one, which is
// allowed; an empty one is present and so cannot contain the member's choice.
func checkShown(shown []string, showable map[string]bool, optionCount int) error {
	if shown == nil {
		return nil
	}
	if len(shown) > optionCount {
		return fiber.NewError(fiber.StatusBadRequest, "shown has more entries than the question has options")
	}
	seen := map[string]bool{}
	for _, k := range shown {
		if !showable[k] {
			return fiber.NewError(fiber.StatusBadRequest, "shown must be option keys of this question")
		}
		if seen[k] {
			return fiber.NewError(fiber.StatusBadRequest, "shown must not repeat an option")
		}
		seen[k] = true
	}
	return nil
}

// requireShown makes sure a chosen option key was one the member was shown, when shown was sent.
func requireShown(shown []string, key string) error {
	if shown == nil || key == "" {
		return nil
	}
	for _, k := range shown {
		if k == key {
			return nil
		}
	}
	return fiber.NewError(fiber.StatusBadRequest, "the chosen option was not in shown")
}

// cleanOpenText trims an open answer and drops control characters other than a line break or tab,
// so the stored text is plain. It is stored as data and shown escaped; nothing here tries to make
// it safe to render, which is the display's job.
func cleanOpenText(in string) string {
	var b strings.Builder
	for _, r := range in {
		if r == '\n' || r == '\t' || !unicode.IsControl(r) {
			b.WriteRune(r)
		}
	}
	return strings.TrimSpace(b.String())
}

// recordUxPromptResponse validates and stores an answer. The prompt must be open; keys must be its
// own options; a second answer from the same member is ignored, not updated, so nobody can rewrite
// their vote after seeing the tally. A skip takes the member's one place too, so a skipped prompt
// is not offered again.
func recordUxPromptResponse(db *gorm.DB, userID uint64, promptID uint64, answer *UxPromptAnswer, comments *string) error {
	if answer == nil {
		return fiber.NewError(fiber.StatusBadRequest, "An answer is required")
	}
	var row struct {
		Kind         string `gorm:"column:kind"`
		Options      string `gorm:"column:options"`
		MaxResponses int64  `gorm:"column:max_responses"`
	}
	res := db.Table("ux_prompts").Select("kind, options, max_responses").Where("id = ? AND status = 'open'", promptID).Scan(&row)
	if res.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "That question is no longer open")
	}

	// The same people the offer leaves out cannot answer either: GetChallenge never shows a
	// Declined or Excluded member a prompt, but anyone who can POST could otherwise vote in a
	// ranking.
	var trustLevel string
	db.Table("users").Select("COALESCE(trustlevel, ?)", TrustBasic).Where("id = ?", userID).Scan(&trustLevel)
	if trustLevel == TrustDeclined || trustLevel == TrustExcluded {
		return fiber.NewError(fiber.StatusForbidden, "That question is not open to you")
	}
	// A prompt takes max_responses answers and no more; the hourly tick closes a full one, but
	// answers posted before it does must not push it past the cap. A skip is not an answer.
	if !answer.Skipped {
		var answered int64
		db.Table("ux_prompt_responses").Where("prompt_id = ? AND JSON_EXTRACT(answer, '$.skipped') IS NULL", promptID).Count(&answered)
		if answered >= row.MaxResponses {
			return fiber.NewError(fiber.StatusBadRequest, "That question has all the answers it needs")
		}
	}
	var options []UxPromptOption
	_ = json.Unmarshal([]byte(row.Options), &options)
	valid := map[string]bool{}
	for _, o := range options {
		valid[o.Key] = true
	}

	stored := map[string]interface{}{}
	hasComment := true
	switch {
	case answer.Skipped:
		stored["skipped"] = true
		hasComment = false
	case row.Kind == "survey":
		// Best, and optionally a different worst (the area survey's second pick, left out by a
		// follow-up), from the options shown; or one of the escape answers, which are answers in
		// their own right and are tallied as such. Escapes are not things to rank, so they are
		// neither best nor worst nor shown.
		showable := map[string]bool{}
		count := 0
		for _, o := range options {
			if !uxSurveyEscape(o.Key) {
				showable[o.Key] = true
				count++
			}
		}
		if err := checkShown(answer.Shown, showable, count); err != nil {
			return err
		}
		switch answer.Choice {
		case "none", "dont_care", uxEscapeDontUnderstand:
			// "None of these" and the two escapes are answers in their own right.
			stored["choice"] = answer.Choice
		case "":
			if !showable[answer.Best] {
				return fiber.NewError(fiber.StatusBadRequest, "best must be one of the options")
			}
			if answer.Worst != "" && (!showable[answer.Worst] || answer.Worst == answer.Best) {
				return fiber.NewError(fiber.StatusBadRequest, "worst must be one of the options, and differ from best")
			}
			// What the member picked must be something they were shown, or the count of how
			// often each option was shown would be wrong.
			if err := requireShown(answer.Shown, answer.Best); err != nil {
				return err
			}
			if err := requireShown(answer.Shown, answer.Worst); err != nil {
				return err
			}
			stored["best"] = answer.Best
			if answer.Worst != "" {
				stored["worst"] = answer.Worst
			}
		default:
			return fiber.NewError(fiber.StatusBadRequest, "choice must be none or dont_understand")
		}
		if len(answer.Shown) > 0 {
			stored["shown"] = answer.Shown
		}
	case row.Kind == "open":
		// Free text, or one of the two ways out. The escapes are always allowed, whatever the
		// prompt's options say, so nobody is forced to invent an answer.
		hasComment = false
		if answer.Choice != "" {
			if answer.Choice != uxEscapeNothing && answer.Choice != uxEscapeDontUnderstand {
				return fiber.NewError(fiber.StatusBadRequest, "choice must be nothing or dont_understand")
			}
			stored["choice"] = answer.Choice
			break
		}
		text := cleanOpenText(answer.Text)
		if n := len([]rune(text)); n < uxOpenTextMin || n > uxOpenTextMax {
			return fiber.NewError(fiber.StatusBadRequest, "Please write between 3 and 1000 characters, or choose one of the other answers")
		}
		stored["text"] = text
	default:
		// An awareness question, or any kind this code has no special rule for: one of the
		// prompt's options. An awareness question is further limited to yes, no and not_sure.
		if !valid[answer.Choice] || (row.Kind == "awareness" && !uxAwarenessChoices[answer.Choice]) {
			return fiber.NewError(fiber.StatusBadRequest, "choice must be one of the options")
		}
		if err := checkShown(answer.Shown, valid, len(options)); err != nil {
			return err
		}
		if err := requireShown(answer.Shown, answer.Choice); err != nil {
			return err
		}
		stored["choice"] = answer.Choice
		if len(answer.Shown) > 0 {
			stored["shown"] = answer.Shown
		}
	}
	encoded, _ := json.Marshal(stored)

	var comment interface{}
	if comments != nil && hasComment {
		c := strings.TrimSpace(*comments)
		if c != "" {
			r := []rune(c)
			if len(r) > 1000 {
				c = string(r[:1000])
			}
			comment = c
		}
	}

	return db.Table("ux_prompt_responses").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"prompt_id": promptID,
		"userid":    userID,
		"answer":    string(encoded),
		"comment":   comment,
	}).Error
}
