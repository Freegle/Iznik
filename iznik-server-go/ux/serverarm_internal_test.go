package ux

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
)

// parseServerArms is handed text that encoding/json has already read once, so the config can never
// give it an object that stops part way. Its own error returns are what stop the decoder being asked
// for tokens that are not there. These tests give it text that ends at each place it reads.
func TestParseServerArmsRefusesTextThatEndsBeforeItIsWhole(t *testing.T) {
	for name, raw := range map[string]string{
		"nothing at all":                 ``,
		"an object that stops after one": `{"control":[0,49],`,
		"an arm with no range":           `{"control":`,
	} {
		arms, ok := parseServerArms(json.RawMessage(raw))

		assert.False(t, ok, name)
		assert.Nil(t, arms, name)
	}
}

func TestParseServerArmsStillReadsAWholeObjectInTheOrderItWasWritten(t *testing.T) {
	arms, ok := parseServerArms(json.RawMessage(`{"items":[50,99],"control":[0,49]}`))

	assert.True(t, ok)
	assert.Equal(t, []armRange{{arm: "items", from: 50, to: 99}, {arm: "control", from: 0, to: 49}}, arms)
}
