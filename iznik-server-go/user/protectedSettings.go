package user

import (
	"encoding/json"
	"os"
	"strings"

	"github.com/freegle/iznik-server-go/database"
)

// Protected settings keys: deployment switch PROTECTED_SETTINGS_KEYS.
//
// A comma-separated list of top-level `settings` keys that a member may not
// change about themselves. Freegle sets none, so nothing changes for Freegle.
// A deployment that records something authoritative in a member's settings
// (for example whether a joining fee has been paid) lists the key here; then
// only a system moderator, or a caller holding a server-minted token (see
// auth.CanWriteProtectedSettings), can write it.
//
// A member's own PATCH keeps whatever is already stored for those keys, so
// the web client's usual read-merge-write of the whole settings blob keeps
// working unchanged: the protected part is simply never theirs to change.

// ProtectedSettingsKeys returns the configured keys, or nil when the switch
// is off.
func ProtectedSettingsKeys() []string {
	raw := os.Getenv("PROTECTED_SETTINGS_KEYS")
	if strings.TrimSpace(raw) == "" {
		return nil
	}
	var keys []string
	for _, k := range strings.Split(raw, ",") {
		if k = strings.TrimSpace(k); k != "" {
			keys = append(keys, k)
		}
	}
	return keys
}

// EnforceProtectedSettings returns `incoming` with every protected key set to
// the value found in `existing`, or removed when `existing` has no such key.
// Input that is not a JSON object is returned untouched; the caller's own
// validation deals with that.
func EnforceProtectedSettings(incoming, existing []byte, keys []string) []byte {
	if len(keys) == 0 {
		return incoming
	}
	var in map[string]json.RawMessage
	if err := json.Unmarshal(incoming, &in); err != nil || in == nil {
		return incoming
	}
	var ex map[string]json.RawMessage
	if len(existing) > 0 {
		_ = json.Unmarshal(existing, &ex)
	}
	for _, k := range keys {
		if v, ok := ex[k]; ok {
			in[k] = v
		} else {
			delete(in, k)
		}
	}
	out, err := json.Marshal(in)
	if err != nil {
		return incoming
	}
	return out
}

// ApplyProtectedSettings is EnforceProtectedSettings against what is stored
// for userID. A no-op when the switch is off.
func ApplyProtectedSettings(incoming []byte, userID uint64) []byte {
	keys := ProtectedSettingsKeys()
	if len(keys) == 0 {
		return incoming
	}
	var existing string
	database.DBConn.Table("users").Select("COALESCE(settings, '')").Where("id = ?", userID).Scan(&existing)
	return EnforceProtectedSettings(incoming, []byte(existing), keys)
}
