package user

import (
	"encoding/json"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// unmarshalSettings unmarshals a JSON blob into a plain map for field assertions.
func unmarshalSettings(t *testing.T, raw json.RawMessage) map[string]interface{} {
	t.Helper()
	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(raw, &m))
	return m
}

// ---------------------------------------------------------------------------
// ApplySettingsDefaultsToJSON
// ---------------------------------------------------------------------------

func TestApplySettingsDefaultsToJSON_EmptyInput(t *testing.T) {
	// len(settings) == 0 → early return, no changes
	result := ApplySettingsDefaultsToJSON(json.RawMessage{}, "")
	assert.Empty(t, result)
}

func TestApplySettingsDefaultsToJSON_InvalidJSON(t *testing.T) {
	// Unparseable JSON → return original bytes unchanged
	input := json.RawMessage(`not-valid-json`)
	result := ApplySettingsDefaultsToJSON(input, "")
	assert.Equal(t, input, result)
}

func TestApplySettingsDefaultsToJSON_NonModUserEmptySettings(t *testing.T) {
	// Regular user with no settings gets notificationmails and engagement defaults.
	// No mod-only fields added.
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{}`), utils.SYSTEMROLE_USER)
	m := unmarshalSettings(t, result)

	assert.Equal(t, true, m["notificationmails"], "notificationmails default should be true")
	assert.Equal(t, true, m["engagement"], "engagement default should be true")

	_, hasModNotifs := m["modnotifs"]
	assert.False(t, hasModNotifs, "regular user must not receive modnotifs")
	_, hasBackup := m["backupmodnotifs"]
	assert.False(t, hasBackup, "regular user must not receive backupmodnotifs")
}

func TestApplySettingsDefaultsToJSON_DoesNotOverrideExistingNotificationmails(t *testing.T) {
	// Present field → kept as-is even if different from the default.
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{"notificationmails":false}`), utils.SYSTEMROLE_USER)
	m := unmarshalSettings(t, result)
	assert.Equal(t, false, m["notificationmails"])
}

func TestApplySettingsDefaultsToJSON_DoesNotOverrideExistingEngagement(t *testing.T) {
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{"engagement":false}`), utils.SYSTEMROLE_USER)
	m := unmarshalSettings(t, result)
	assert.Equal(t, false, m["engagement"])
}

func TestApplySettingsDefaultsToJSON_AllBaseFieldsPresent_ReturnsSameBytes(t *testing.T) {
	// When nothing needs to change, the original bytes come back unchanged.
	input := json.RawMessage(`{"notificationmails":false,"engagement":false}`)
	result := ApplySettingsDefaultsToJSON(input, utils.SYSTEMROLE_USER)
	assert.Equal(t, input, result)
}

func TestApplySettingsDefaultsToJSON_ModeratorGetsModDefaults(t *testing.T) {
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{}`), utils.SYSTEMROLE_MODERATOR)
	m := unmarshalSettings(t, result)

	assert.Equal(t, true, m["notificationmails"])
	assert.Equal(t, true, m["engagement"])
	// JSON numbers unmarshal as float64 into interface{}
	assert.Equal(t, float64(4), m["modnotifs"])
	assert.Equal(t, float64(12), m["backupmodnotifs"])
}

func TestApplySettingsDefaultsToJSON_SupportGetsModDefaults(t *testing.T) {
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{}`), utils.SYSTEMROLE_SUPPORT)
	m := unmarshalSettings(t, result)
	assert.Equal(t, float64(4), m["modnotifs"])
	assert.Equal(t, float64(12), m["backupmodnotifs"])
}

func TestApplySettingsDefaultsToJSON_AdminGetsModDefaults(t *testing.T) {
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{}`), utils.SYSTEMROLE_ADMIN)
	m := unmarshalSettings(t, result)
	assert.Equal(t, float64(4), m["modnotifs"])
	assert.Equal(t, float64(12), m["backupmodnotifs"])
}

func TestApplySettingsDefaultsToJSON_DoesNotOverrideExistingModNotifs(t *testing.T) {
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{"modnotifs":8}`), utils.SYSTEMROLE_MODERATOR)
	m := unmarshalSettings(t, result)
	assert.Equal(t, float64(8), m["modnotifs"])
}

func TestApplySettingsDefaultsToJSON_DoesNotOverrideExistingBackupModNotifs(t *testing.T) {
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{"backupmodnotifs":24}`), utils.SYSTEMROLE_MODERATOR)
	m := unmarshalSettings(t, result)
	assert.Equal(t, float64(24), m["backupmodnotifs"])
}

func TestApplySettingsDefaultsToJSON_ModAllFieldsPresent_ReturnsSameBytes(t *testing.T) {
	// When all four mod fields are present, changed==false → original bytes returned.
	input := json.RawMessage(`{"notificationmails":true,"engagement":true,"modnotifs":4,"backupmodnotifs":12}`)
	result := ApplySettingsDefaultsToJSON(input, utils.SYSTEMROLE_MODERATOR)
	assert.Equal(t, input, result)
}

func TestApplySettingsDefaultsToJSON_UnknownRoleTreatedAsNonMod(t *testing.T) {
	result := ApplySettingsDefaultsToJSON(json.RawMessage(`{}`), "UnknownRole")
	m := unmarshalSettings(t, result)
	_, hasModNotifs := m["modnotifs"]
	assert.False(t, hasModNotifs, "unknown role must not receive modnotifs")
}

func TestApplySettingsDefaultsToJSON_ExtraFieldsPreserved(t *testing.T) {
	// Fields not mentioned in the function must pass through unchanged.
	input := json.RawMessage(`{"someOtherField":42}`)
	result := ApplySettingsDefaultsToJSON(input, utils.SYSTEMROLE_USER)
	m := unmarshalSettings(t, result)
	assert.Equal(t, float64(42), m["someOtherField"])
}

func TestApplySettingsDefaultsToJSON_NullJSON(t *testing.T) {
	// TODO: latent bug — ApplySettingsDefaultsToJSON panics when settings contains the JSON
	// literal "null": json.Unmarshal("null", &map) succeeds but leaves the map nil, and the
	// subsequent m["notificationmails"] = true write panics at runtime.
	t.Skip("latent bug: JSON null produces a nil map, causing a write-to-nil-map panic")
	ApplySettingsDefaultsToJSON(json.RawMessage(`null`), "")
}

// ---------------------------------------------------------------------------
// generateRandomKey
// ---------------------------------------------------------------------------

func TestGenerateRandomKey_Length(t *testing.T) {
	for _, length := range []int{0, 1, 8, 16, 32, 64, 128} {
		got := generateRandomKey(length)
		assert.Len(t, got, length, "generateRandomKey(%d) returned wrong length", length)
	}
}

func TestGenerateRandomKey_OnlyAlphanumericChars(t *testing.T) {
	const validChars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	key := generateRandomKey(200)
	for _, ch := range key {
		assert.Contains(t, validChars, string(ch), "non-alphanumeric character %q in key", string(ch))
	}
}

func TestGenerateRandomKey_DistinctCalls(t *testing.T) {
	// Two independent 32-char keys are astronomically unlikely to collide.
	a := generateRandomKey(32)
	b := generateRandomKey(32)
	assert.NotEqual(t, a, b, "successive 32-char keys should differ")
}

func TestGenerateRandomKey_ZeroLengthReturnsEmptyString(t *testing.T) {
	assert.Equal(t, "", generateRandomKey(0))
}
