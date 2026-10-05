package volunteering

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestTableNames(t *testing.T) {
	assert.Equal(t, "volunteering", Volunteering{}.TableName())
	assert.Equal(t, "volunteering_dates", VolunteeringDate{}.TableName())
	assert.Equal(t, "volunteering_images", VolunteeringImage{}.TableName())
}
