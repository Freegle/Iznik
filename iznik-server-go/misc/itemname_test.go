package misc

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestStripCourtesy(t *testing.T) {
	cases := []struct {
		name string
		in   string
		want string
	}{
		{"trailing please", "iron please", "iron"},
		{"capitalised please", "Microwave Please", "Microwave"},
		{"please with punctuation", "Wooden Pallets Please!!", "Wooden Pallets"},
		{"please with full stop", "2nd hand pallets and about 30 old bricks please.", "2nd hand pallets and about 30 old bricks"},
		{"pls", "garden parasol with base pls", "garden parasol with base"},
		{"plz", "black leather sofas plz", "black leather sofas"},
		{"drawn out please", "sheet of aluminium pleaseeeee", "sheet of aluminium"},
		{"please mid-name", "single bed please & mattress", "single bed & mattress"},
		{"leading please", "please can I have a kettle", "can I have a kettle"},
		{"no courtesy word", "wooden chair", "wooden chair"},
		{"only contains please as a prefix of another word", "Pleaser platform boots", "Pleaser platform boots"},
		{"pls inside another word", "duplex printer", "duplex printer"},
		{"nothing left falls back to original", "please", "please"},
		{"nothing left but punctuation falls back", "Please!", "Please!"},
		{"empty stays empty", "", ""},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			assert.Equal(t, c.want, StripCourtesy(c.in))
		})
	}
}
