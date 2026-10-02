package message

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// A masked AI illustration has a blank externaluid; left in the list it gets a legacy
// img_<id>.jpg URL that does not exist, which both ModTools and the public page draw as a
// blank "Photo" box.
func TestDropMaskedAttachments(t *testing.T) {
	atts := []MessageAttachment{
		{ID: 1, Masked: true},
		{ID: 2, Externaluid: "freegletusd-abc"},
		{ID: 3, Masked: true},
	}

	kept := dropMaskedAttachments(atts)

	assert.Len(t, kept, 1)
	assert.Equal(t, uint64(2), kept[0].ID)
	assert.Empty(t, dropMaskedAttachments([]MessageAttachment{{ID: 4, Masked: true}}))
}
