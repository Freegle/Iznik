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

		// Thanks-family sign-offs, anchored to the end of the name.
		{"trailing thanks", "Anything fitness related thanks", "Anything fitness related"},
		{"thank you in advance", "digital multi meter thank you in advance", "digital multi meter"},
		{"thank you very much", "any old laptops for spares thank you very much", "any old laptops for spares"},
		{"thanks jammed against a comma", "Active speakers for PC,small,thanks.", "Active speakers for PC,small"},
		{"a run of sign-offs", "Working 2 slice toaster please, Thank you", "Working 2 slice toaster"},
		{"plz and thanks together", "3 chairs and 2 sofas plz thanks", "3 chairs and 2 sofas"},
		{"thanks alone falls back", "thanks", "thanks"},

		// Names that only LOOK like courtesy, and must survive intact.
		{"thank you is the item itself", "thank you cards", "thank you cards"},
		{"thank you gift is the item", "thank you gift bags", "thank you gift bags"},
		{"ta is a job title", "SEN TA", "SEN TA"},
		{"ta inside a longer job title", "Early Years Teaching Assistant (TA)", "Early Years Teaching Assistant (TA)"},
		{"tia is Siemens software", "Junior Controls Engineer (TIA Portal)", "Junior Controls Engineer (TIA Portal)"},
		{"thanksgiving is not thanks", "decorations for Thanksgiving", "decorations for Thanksgiving"},

		// Bias words, stripped anywhere like please/pls/plz - regression coverage for
		// Discourse topic 9630/60: "Adult bike" got a medicine/supplement bottle because
		// "adult" biases the image generator toward pharmacy imagery.
		{"leading adult", "Adult bike", "bike"},
		{"adult mid-name", "large adult bike", "large bike"},
		{"trailing adult plural", "mountain bike, adults", "mountain bike"},
		{"adult alone falls back", "Adult", "Adult"},
		{"adult prefix of real word is kept", "Adulting for beginners book", "Adulting for beginners book"},

		// Real ai_images names that a bare word-strip left with the qualifier stranded.
		// Removing only "adult" turned these into "mountain bike, size", "size scooter",
		// "only jigsaw", "and kids", "A bike for" and "An cycle".
		{"qualifier size goes with the word", "mountain bike, adult size", "mountain bike"},
		{"leading qualifier size", "Adult size scooter", "scooter"},
		{"qualifier only", "adults only jigsaw", "jigsaw"},
		{"stranded trailing preposition", "A bike for adult", "A bike"},
		{"stranded leading conjunction", "Adult and kids", "kids"},
		{"stranded leading conjunction plural", "Adults and kids items,home appliance", "kids items,home appliance"},
		{"article agreement repaired", "An adult cycle", "A cycle"},
		{"empty separator collapsed", "Coloured plastic hangers - adult size - will split", "Coloured plastic hangers - will split"},
		{"article untouched when no bias word", "An apple corer", "An apple corer"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			assert.Equal(t, c.want, StripCourtesy(c.in))
		})
	}
}
