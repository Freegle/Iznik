package group

import (
	"encoding/json"
	"log"
	"math"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
)

// RepostConfigKey is the `config` row that holds the repost settings for every community:
// {"offer":3,"wanted":7,"max":3,"chaseups":5}. offer and wanted are the days an OFFER or WANTED
// waits before it is reposted, max is how many times, chaseups is the days between chase-up emails.
// A community's own settings.reposts is ignored. The batch (RepostSettings.php) and the frontend
// (GROUP_REPOSTS in constants.js) hold the same four fallback values; change all three together.
const RepostConfigKey = "reposts"

// repostSettingsCopyFor is how long a copy of the row is believed. The row changes by hand, rarely.
const repostSettingsCopyFor = 5 * time.Minute

type RepostSettings struct {
	Offer    int `json:"offer"`
	Wanted   int `json:"wanted"`
	Max      int `json:"max"`
	Chaseups int `json:"chaseups"`
}

// DefaultRepostSettings is the built-in fallback, used when the config row is missing or a value in
// it is unusable.
func DefaultRepostSettings() RepostSettings {
	return RepostSettings{Offer: 3, Wanted: 7, Max: 3, Chaseups: 5}
}

var (
	repostSettingsMu      sync.Mutex
	repostSettingsCopy    RepostSettings
	repostSettingsFetched time.Time
)

// SystemRepostSettings returns the repost settings that apply to every community. The row is read
// at most once every few minutes, and a failure to read it is not cached, so the next call tries again.
func SystemRepostSettings() RepostSettings {
	repostSettingsMu.Lock()
	defer repostSettingsMu.Unlock()

	if !repostSettingsFetched.IsZero() && time.Since(repostSettingsFetched) < repostSettingsCopyFor {
		return repostSettingsCopy
	}

	if database.DBConn == nil {
		return DefaultRepostSettings()
	}

	var rows []string
	res := database.DBConn.Table("config").Select("value").Where("`key` = ?", RepostConfigKey).Limit(1).Scan(&rows)

	if res.Error != nil {
		// Not cached: the next call tries again. Until then the last good copy, or the built-in values.
		log.Printf("Repost settings: could not read the `%s` config row: %v", RepostConfigKey, res.Error)

		if !repostSettingsFetched.IsZero() {
			return repostSettingsCopy
		}

		return DefaultRepostSettings()
	}

	settings := DefaultRepostSettings()

	if len(rows) == 0 {
		log.Printf("Repost settings: no `%s` row in config; using the built-in values.", RepostConfigKey)
	} else {
		settings = parseRepostSettings(rows[0])
	}

	repostSettingsCopy = settings
	repostSettingsFetched = time.Now()

	return settings
}

// ResetSystemRepostSettingsCache forgets the copy, so the next call reads the row. For tests.
func ResetSystemRepostSettingsCache() {
	repostSettingsMu.Lock()
	defer repostSettingsMu.Unlock()
	repostSettingsFetched = time.Time{}
}

// parseRepostSettings reads the row's JSON. A value that is not a whole, non-negative number falls
// back to the built-in value for that key alone, with a warning; 0 is kept because it means off.
func parseRepostSettings(raw string) RepostSettings {
	settings := DefaultRepostSettings()

	var decoded map[string]interface{}
	if err := json.Unmarshal([]byte(raw), &decoded); err != nil || decoded == nil {
		log.Printf("Repost settings: the `%s` config row is not a JSON object; using the built-in values.", RepostConfigKey)
		return settings
	}

	targets := map[string]*int{
		"offer":    &settings.Offer,
		"wanted":   &settings.Wanted,
		"max":      &settings.Max,
		"chaseups": &settings.Chaseups,
	}

	for key, target := range targets {
		v, ok := decoded[key].(float64)

		if !ok || v < 0 || v != math.Floor(v) || v > math.MaxInt32 {
			log.Printf("Repost settings: `%s` in the `%s` config row is missing or not a whole number; using %d.", key, RepostConfigKey, *target)
			continue
		}

		*target = int(v)
	}

	return settings
}
