package user

import (
	"time"

	"gorm.io/gorm"
)

// DonationAskOptOutDays is how long "don't ask me to donate" lasts. After it the member may be
// asked again, and Settings shows the date while it runs. The server owns the date
// (settings.nodonationasksuntil); clients only send the switch.
const DonationAskOptOutDays = 365

// DonationAskUntilFormat is how nodonationasksuntil is stored: UTC, RFC 3339.
const DonationAskUntilFormat = time.RFC3339

// DonationAsksPaused reports whether a stored opt-out is still running. An opt-out with no date
// predates the lapse and runs until the daily batch job dates it.
func DonationAsksPaused(noDonationAsks bool, until *string, now time.Time) bool {
	if !noDonationAsks {
		return false
	}

	if until == nil || *until == "" || *until == "null" {
		return true
	}

	t, err := time.Parse(DonationAskUntilFormat, *until)
	if err != nil {
		return true
	}

	return now.Before(t)
}

// applyDonationAskLapse sets settings.nodonationasksuntil on a settings save. Turning the switch on
// starts a fresh year unless a dated opt-out is already running, in which case its date is kept:
// some clients send the whole settings object on every save, and an unrelated save must not extend
// it. Turning it off clears the date. A date sent by the client is never stored; it is only used
// to recognise an echo of a lapsed opt-out.
//
// The date is always written back explicitly, because PatchSession replaces settings outright
// rather than merging them.
func applyDonationAskLapse(db *gorm.DB, rawMap map[string]interface{}, targetID uint64, now time.Time) {
	sentUntil, _ := rawMap["nodonationasksuntil"].(string)
	delete(rawMap, "nodonationasksuntil")

	flag, present := rawMap["nodonationasks"]
	if !present {
		return
	}

	if on, _ := flag.(bool); !on {
		rawMap["nodonationasksuntil"] = nil
		return
	}

	var stored struct {
		NoDonationAsks bool
		Until          *string
	}

	if db != nil {
		db.Table("users").
			Select("COALESCE(JSON_EXTRACT(settings, '$.nodonationasks') = CAST('true' AS JSON), 0) AS no_donation_asks, "+
				"JSON_UNQUOTE(JSON_EXTRACT(settings, '$.nodonationasksuntil')) AS until").
			Where("id = ?", targetID).
			Scan(&stored)
	}

	if stored.Until != nil && *stored.Until != "" && *stored.Until != "null" {
		if DonationAsksPaused(stored.NoDonationAsks, stored.Until, now) {
			rawMap["nodonationasksuntil"] = *stored.Until
			return
		}

		// A lapsed opt-out echoed back with its old date is an unrelated save from a client that
		// still holds the old settings, not a new opt-out: let it lapse. A client turning the
		// switch on again sends no date.
		if stored.NoDonationAsks && sentUntil == *stored.Until {
			rawMap["nodonationasks"] = false
			rawMap["nodonationasksuntil"] = nil
			return
		}
	}

	rawMap["nodonationasksuntil"] = now.UTC().AddDate(0, 0, DonationAskOptOutDays).Format(DonationAskUntilFormat)
}
