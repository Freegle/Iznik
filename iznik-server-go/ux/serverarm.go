package ux

import (
	"bytes"
	"encoding/json"
	"hash/crc32"
	"log"
	"math"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
	"gorm.io/plugin/dbresolver"
)

// Server arms: Go code that decides what a member gets from an experiment's arm. The arm is a pure
// function of the member's id and the weights the batch publishes in the `ux_experiments` config
// key, the same function the browser (iznik-nuxt3/composables/useExperiment.js) and the batch
// (iznik-batch/app/Services/Ux/UxArm.php) use. docs/developers/reference/ux-experiments.md,
// "Experiments in mail, push and the server".

const (
	// ExperimentsConfigKey is the config row the batch publishes every experiment's arm ranges in.
	ExperimentsConfigKey = "ux_experiments"

	// ServerArmsConfigKey is the config row that switches every server arm on (1) or off (anything
	// else, or no row). Set it through PATCH /config/admin, so no deploy is needed.
	ServerArmsConfigKey = "ux_server_arms"

	// serverArmCopyFor is how long a copy of the two config rows is believed. Both are read in one
	// query and kept this long.
	serverArmCopyFor = 60 * time.Second

	// serverArmMaxAhead is how far ahead a running entry's `until` may be and still be believed.
	serverArmMaxAhead = 15 * 24 * 60 * 60

	// serverArmMaxArms is the most arms an entry may have, as in useExperiment.js.
	serverArmMaxArms = 8

	// exposureColumnMax is the length of the narrow varchar columns of ux_experiment_exposures. A
	// longer value is skipped, not cut.
	exposureColumnMax = 16
)

var (
	serverArmNameRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	serverArmArmRe  = regexp.MustCompile(`^[A-Za-z0-9_-]{1,32}$`)
	serverArmSaltRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,16}$`)
	exposureKeyRe   = regexp.MustCompile(`^[a-z_]{1,32}$`)

	// Names that would be keys on a plain object in surprising ways, as in useExperiment.js.
	serverArmBlocked = map[string]bool{"__proto__": true, "constructor": true, "prototype": true}
)

// ExperimentBucket is the member's bucket, 0 to 99, for an experiment: the CRC-32 of the UTF-8 bytes
// of "<userid>|<name>", or "<userid>|<name>|<salt>" when the experiment has a salt, modulo 100. A
// salt gives a re-run of an experiment different buckets from the run before it. The three runtimes
// pin the same vectors in their tests.
func ExperimentBucket(userid uint64, name, salt string) int {
	key := strconv.FormatUint(userid, 10) + "|" + name
	if salt != "" {
		key += "|" + salt
	}

	return int(crc32.ChecksumIEEE([]byte(key)) % 100)
}

// ServerSwitch is one experiment this code contains a switch for. The list is ServerSwitches. An
// experiment that is not in it is control for every member, whatever the config says. Fields mirror
// iznik-batch/config/ux_switches.php.
type ServerSwitch struct {
	Name      string
	Opened    string // YYYY-MM-DD
	PR        string
	KeepUntil string // YYYY-MM-DD, or empty for none: set it when the experiment is adopted
	Channel   string // email, push or server
	Type      string // the email_type or the push kind
	Varies    string // subject, body, title, timing, order or step
	Arms      []string
	Metric    ServerSwitchMetric
}

// ServerSwitchMetric names the source that counts the experiment and the window it counts in.
type ServerSwitchMetric struct {
	Source      string
	WindowHours int
}

// ServerSwitches lists every experiment Go code reads an arm for, by name. The change that adds a
// ux.ExperimentArm call adds its entry in the same commit, and a test fails when a call has no
// entry, when an entry has no call, or when an entry is older than 30 days unless KeepUntil is
// ahead (docs/developers/reference/ux-experiments.md, "Switch lists and corpse control").
var ServerSwitches = map[string]ServerSwitch{
	"confirm-reply": {
		Name:    "confirm-reply",
		Opened:  "2026-10-20",
		PR:      "https://github.com/Freegle/Iznik/pull/1767",
		Channel: "server",
		Type:    "confirm_reply",
		Varies:  "step",
		Arms:    []string{"control", "ask"},
		Metric:  ServerSwitchMetric{Source: "confirm_reply", WindowHours: 168},
	},
	"interested-button": {
		Name:    "interested-button",
		Opened:  "2026-10-23",
		Channel: "server",
		Type:    "interested_button",
		Varies:  "step",
		Arms:    []string{"control", "button"},
		Metric:  ServerSwitchMetric{Source: "interested_reply", WindowHours: 168},
	},
}

var serverSwitchMu sync.RWMutex

// SetServerSwitchesForTest replaces the switch list and returns a function that puts the old one
// back. For tests, which add and remove their own entries.
func SetServerSwitchesForTest(list map[string]ServerSwitch) func() {
	serverSwitchMu.Lock()
	old := ServerSwitches
	ServerSwitches = list
	serverSwitchMu.Unlock()

	return func() {
		serverSwitchMu.Lock()
		ServerSwitches = old
		serverSwitchMu.Unlock()
	}
}

func serverSwitchListed(name string) bool {
	serverSwitchMu.RLock()
	defer serverSwitchMu.RUnlock()
	_, ok := ServerSwitches[name]

	return ok
}

type armRange struct {
	arm      string
	from, to int
}

type armEntry struct {
	arms    []armRange
	until   *float64
	adopted bool
	salt    string
}

type serverArmState struct {
	at      time.Time
	on      bool
	entries map[string]armEntry
}

var (
	serverArmMu    sync.Mutex
	serverArmCopy  *serverArmState
	serverArmClock = time.Now
)

// FlushServerArmCache forgets the copy of the config rows, so the next call reads them again.
func FlushServerArmCache() {
	serverArmMu.Lock()
	serverArmCopy = nil
	serverArmMu.Unlock()
}

// SetServerArmClockForTest replaces the clock the copy's age and an entry's `until` are judged
// against, and returns a function that puts the real one back.
func SetServerArmClockForTest(now func() time.Time) func() {
	serverArmMu.Lock()
	old := serverArmClock
	serverArmClock = now
	serverArmCopy = nil
	serverArmMu.Unlock()

	return func() {
		serverArmMu.Lock()
		serverArmClock = old
		serverArmCopy = nil
		serverArmMu.Unlock()
	}
}

// ExperimentArm is the member's arm for an experiment, or "" (control). It returns "" unless all of
// these hold:
//   - the config row ux_server_arms is 1. Anything else, or no row, is off, and every caller acts as
//     control within a minute;
//   - the name is in ServerSwitches, the list of experiments this code contains;
//   - the entry in the ux_experiments row is well formed by the browser's own checks;
//   - the entry is adopted (then `until` is ignored), or its `until` is ahead and no more than 15
//     days ahead.
//
// A read error also returns "" and is logged. The same rules are UxArm::for in the batch. Look the
// arm up before the content is built, and keep the arm name out of links and headers.
func ExperimentArm(db *gorm.DB, userid uint64, name string) string {
	if userid == 0 || !serverSwitchListed(name) {
		return ""
	}

	state := loadServerArmState(db)
	if !state.on {
		return ""
	}

	entry, ok := state.entries[name]
	if !ok {
		return ""
	}

	if !entry.adopted {
		now := float64(serverArmNow().Unix())
		if entry.until == nil || *entry.until <= now || *entry.until > now+serverArmMaxAhead {
			return ""
		}
	}

	b := ExperimentBucket(userid, name, entry.salt)
	for _, a := range entry.arms {
		if b >= a.from && b <= a.to {
			return a.arm
		}
	}

	return ""
}

// loadServerArmState returns the two config rows, at most serverArmCopyFor old. A read error is
// remembered for the same time as "off", so a database that is down is not asked again by every call.
func loadServerArmState(db *gorm.DB) serverArmState {
	serverArmMu.Lock()
	defer serverArmMu.Unlock()

	now := serverArmClock()
	if c := serverArmCopy; c != nil {
		age := now.Sub(c.at)
		if age >= 0 && age < serverArmCopyFor {
			return *c
		}
	}

	st := serverArmState{at: now, entries: map[string]armEntry{}}

	var rows []struct {
		Key   string `gorm:"column:key"`
		Value string `gorm:"column:value"`
	}

	if db == nil {
		log.Printf("ux.ExperimentArm: no database, so every arm is control for a minute")
	} else if err := db.Table("config").Select("`key`, `value`").
		Where("`key` IN ?", []string{ExperimentsConfigKey, ServerArmsConfigKey}).Scan(&rows).Error; err != nil {
		log.Printf("ux.ExperimentArm: could not read the experiment config, so every arm is control for a minute: %v", err)
	} else {
		values := map[string]string{}
		for _, r := range rows {
			values[r.Key] = r.Value
		}

		// Trimmed as PHP's trim() does, so the batch and this API read one value the same way.
		if strings.Trim(values[ServerArmsConfigKey], " \t\n\r\x00\x0b") == "1" {
			st.on = true
			st.entries = parseServerArmEntries(values[ExperimentsConfigKey])
		}
	}

	serverArmCopy = &st

	return st
}

// serverArmNow is the clock an entry's `until` is judged against.
func serverArmNow() time.Time {
	serverArmMu.Lock()
	defer serverArmMu.Unlock()

	return serverArmClock()
}

func isJSONNull(raw json.RawMessage) bool {
	return string(bytes.TrimSpace(raw)) == "null"
}

// parseServerArmEntries reads the ux_experiments row. An entry that is not understood is left out,
// which is control: the safe reading. The checks are those of normaliseConfig in useExperiment.js,
// and a salt that is present and not 1 to 16 letters, digits, _ or - drops the entry.
func parseServerArmEntries(raw string) map[string]armEntry {
	out := map[string]armEntry{}

	var top map[string]json.RawMessage
	if err := json.Unmarshal([]byte(raw), &top); err != nil {
		return out
	}

	for name, rawEntry := range top {
		if !serverArmNameRe.MatchString(name) || serverArmBlocked[name] {
			continue
		}

		var obj map[string]json.RawMessage
		if err := json.Unmarshal(rawEntry, &obj); err != nil || obj == nil {
			continue
		}

		armsRaw, has := obj["arms"]
		if !has {
			continue
		}

		arms, ok := parseServerArms(armsRaw)
		if !ok {
			continue
		}

		salt := ""
		if saltRaw, has := obj["salt"]; has && !isJSONNull(saltRaw) {
			var s string
			if err := json.Unmarshal(saltRaw, &s); err != nil || !serverArmSaltRe.MatchString(s) {
				continue
			}
			salt = s
		}

		var until *float64
		if untilRaw, has := obj["until"]; has && !isJSONNull(untilRaw) {
			var f float64
			if err := json.Unmarshal(untilRaw, &f); err == nil {
				until = &f
			}
		}

		adopted := false
		if adoptedRaw, has := obj["adopted"]; has {
			adopted = string(bytes.TrimSpace(adoptedRaw)) == "true"
		}

		out[name] = armEntry{arms: arms, until: until, adopted: adopted, salt: salt}
	}

	return out
}

const (
	rangeBad = iota
	rangeValid
	rangeEmpty
)

// parseServerArms reads an entry's arms in the order they were written, because the first arm that
// holds a bucket wins. At most 8 arms; an arm name that is not 1 to 32 letters, digits, _ or - drops
// the entry; an arm written as from above to holds nobody and is left out; an entry with no arm left
// is dropped.
func parseServerArms(raw json.RawMessage) ([]armRange, bool) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()

	tok, err := dec.Token()
	if err != nil {
		return nil, false
	}
	if d, ok := tok.(json.Delim); !ok || d != '{' {
		return nil, false
	}

	var names []string
	ranges := map[string]json.RawMessage{}

	for dec.More() {
		keyTok, err := dec.Token()
		if err != nil {
			return nil, false
		}
		// The decoder only hands back a string where an object key is expected.
		key, _ := keyTok.(string)

		var value json.RawMessage
		if err := dec.Decode(&value); err != nil {
			return nil, false
		}

		// A repeated name keeps its first place and its last value, as an object does.
		if _, seen := ranges[key]; !seen {
			names = append(names, key)
		}
		ranges[key] = value
	}

	if len(names) == 0 || len(names) > serverArmMaxArms {
		return nil, false
	}

	out := make([]armRange, 0, len(names))
	for _, name := range names {
		if !serverArmArmRe.MatchString(name) {
			return nil, false
		}

		from, to, shape := parseServerRange(ranges[name])
		switch shape {
		case rangeEmpty:
			continue
		case rangeValid:
			out = append(out, armRange{arm: name, from: from, to: to})
		default:
			return nil, false
		}
	}

	if len(out) == 0 {
		return nil, false
	}

	return out, true
}

// parseServerRange reads [from, to]. Both must be whole numbers; a number such as 5.0 counts, a
// string does not. Valid is 0 <= from <= to <= 99. Empty is from above to, with from in 0 to 99 and
// to in -1 to 99.
func parseServerRange(raw json.RawMessage) (int, int, int) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()

	var parts []interface{}
	if err := dec.Decode(&parts); err != nil || len(parts) != 2 {
		return 0, 0, rangeBad
	}

	from, ok1 := wholeNumber(parts[0])
	to, ok2 := wholeNumber(parts[1])
	if !ok1 || !ok2 {
		return 0, 0, rangeBad
	}

	switch {
	case from >= 0 && to <= 99 && from <= to:
		return from, to, rangeValid
	case from >= 0 && from <= 99 && to >= -1 && to <= 99 && from > to:
		return from, to, rangeEmpty
	}

	return from, to, rangeBad
}

// wholeNumber reads a JSON number with no fraction. Values far outside any range are clamped, which
// cannot change a range check.
func wholeNumber(v interface{}) (int, bool) {
	n, ok := v.(json.Number)
	if !ok {
		return 0, false
	}

	f, err := strconv.ParseFloat(string(n), 64)
	if err != nil || math.IsInf(f, 0) || math.IsNaN(f) || f != math.Floor(f) {
		return 0, false
	}

	f = math.Max(-1000, math.Min(1000, f))

	return int(f), true
}

// RecordExposure records that server code used a member's arm to decide what they got: one row per
// member and subject in ux_experiment_exposures, written for every arm, control included, at the
// place of the decision and never where content is drawn. It does nothing for the arm "". A second
// call for the same subject is ignored. tx is the transaction of the thing decided, so the row stands
// or falls with it; it writes on the primary. detail holds ids and numbers only: no text, address or
// copy is ever stored.
//
// A failure is logged and also returned, for tests. The caller must carry on: a failed row must not
// stop a reply or a promise.
func RecordExposure(tx *gorm.DB, name, arm string, userid uint64, subjectType string, subjectID uint64, stratum *string, detail map[string]any) error {
	if arm == "" || userid == 0 {
		return nil
	}

	if utf8.RuneCountInString(name) > 64 || utf8.RuneCountInString(arm) > exposureColumnMax ||
		utf8.RuneCountInString(subjectType) > exposureColumnMax || (stratum != nil && utf8.RuneCountInString(*stratum) > exposureColumnMax) {
		log.Printf("ux.RecordExposure: exposure for %s skipped, a value is longer than its column", name)

		return nil
	}

	var detailJSON interface{}
	if len(detail) > 0 {
		for k, v := range detail {
			if !exposureKeyRe.MatchString(k) {
				log.Printf("ux.RecordExposure: exposure for %s skipped, detail holds more than ids", name)

				return nil
			}
			switch v.(type) {
			case nil, bool, int, int8, int16, int32, int64, uint, uint8, uint16, uint32, uint64, float32, float64:
			default:
				log.Printf("ux.RecordExposure: exposure for %s skipped, detail holds more than ids", name)

				return nil
			}
		}

		b, err := json.Marshal(detail)
		if err != nil {
			log.Printf("ux.RecordExposure: exposure for %s skipped, detail could not be written: %v", name, err)

			return err
		}
		detailJSON = string(b)
	}

	err := tx.Clauses(dbresolver.Write).Table("ux_experiment_exposures").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"experiment":   name,
		"arm":          arm,
		"userid":       userid,
		"subject_type": subjectType,
		"subject_id":   subjectID,
		"stratum":      stratum,
		"exposed_at":   time.Now().UTC().Format("2006-01-02 15:04:05"),
		"detail":       detailJSON,
	}).Error
	if err != nil {
		log.Printf("ux.RecordExposure: could not record the exposure for %s: %v", name, err)

		return err
	}

	return nil
}
