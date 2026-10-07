package user

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestProtectedSettingsKeys(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "")
	assert.Nil(t, ProtectedSettingsKeys(), "unset switch means no keys")

	t.Setenv("PROTECTED_SETTINGS_KEYS", "  lat_payment , membership,, ")
	assert.Equal(t, []string{"lat_payment", "membership"}, ProtectedSettingsKeys())
}

func TestEnforceProtectedSettingsNoKeysIsUntouched(t *testing.T) {
	in := []byte(`{"lat_payment":{"status":"paid"}}`)
	assert.Equal(t, in, EnforceProtectedSettings(in, []byte(`{}`), nil))
}

func TestEnforceProtectedSettingsKeepsStoredValue(t *testing.T) {
	in := []byte(`{"lat_payment":{"status":"paid"},"mylocation":{"id":1},"other":true}`)
	existing := []byte(`{"lat_payment":{"status":"concession"},"mylocation":{"id":9}}`)
	out := EnforceProtectedSettings(in, existing, []string{"lat_payment"})

	var got map[string]json.RawMessage
	assert.NoError(t, json.Unmarshal(out, &got))
	assert.JSONEq(t, `{"status":"concession"}`, string(got["lat_payment"]), "the member's own write loses")
	assert.JSONEq(t, `{"id":1}`, string(got["mylocation"]), "unprotected keys are the member's to change")
	assert.JSONEq(t, `true`, string(got["other"]))
}

func TestEnforceProtectedSettingsRemovesWhatIsNotStored(t *testing.T) {
	in := []byte(`{"lat_payment":{"status":"paid"},"other":1}`)
	out := EnforceProtectedSettings(in, []byte(`{"other":0}`), []string{"lat_payment"})
	assert.JSONEq(t, `{"other":1}`, string(out), "a member cannot create a protected key either")

	out = EnforceProtectedSettings(in, nil, []string{"lat_payment"})
	assert.JSONEq(t, `{"other":1}`, string(out), "no stored settings at all behaves the same")
}

func TestEnforceProtectedSettingsRestoresAnOmittedKey(t *testing.T) {
	// Omitting the key is not a way to clear it.
	in := []byte(`{"other":1}`)
	out := EnforceProtectedSettings(in, []byte(`{"lat_payment":{"status":"paid"}}`), []string{"lat_payment"})
	assert.JSONEq(t, `{"other":1,"lat_payment":{"status":"paid"}}`, string(out))
}

func TestEnforceProtectedSettingsLeavesNonObjectsAlone(t *testing.T) {
	for _, in := range []string{`null`, `[]`, `"x"`, `not json`} {
		assert.Equal(t, []byte(in), EnforceProtectedSettings([]byte(in), []byte(`{}`), []string{"k"}), in)
	}
}
