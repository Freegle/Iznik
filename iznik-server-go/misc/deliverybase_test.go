package misc

import (
	"os"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestDeliveryBase_DefaultWhenEnvUnset(t *testing.T) {
	orig, wasSet := os.LookupEnv("IMAGE_DELIVERY")
	os.Unsetenv("IMAGE_DELIVERY")
	t.Cleanup(func() {
		if wasSet {
			os.Setenv("IMAGE_DELIVERY", orig)
		} else {
			os.Unsetenv("IMAGE_DELIVERY")
		}
	})

	assert.Equal(t, "https://delivery.ilovefreegle.org", deliveryBase())
}

func TestDeliveryBase_CustomEnvNoSuffix(t *testing.T) {
	orig, wasSet := os.LookupEnv("IMAGE_DELIVERY")
	os.Setenv("IMAGE_DELIVERY", "https://custom.delivery.test")
	t.Cleanup(func() {
		if wasSet {
			os.Setenv("IMAGE_DELIVERY", orig)
		} else {
			os.Unsetenv("IMAGE_DELIVERY")
		}
	})

	assert.Equal(t, "https://custom.delivery.test", deliveryBase())
}

func TestDeliveryBase_CustomEnvWithUrlSuffixStripped(t *testing.T) {
	orig, wasSet := os.LookupEnv("IMAGE_DELIVERY")
	os.Setenv("IMAGE_DELIVERY", "https://custom.delivery.test?url=")
	t.Cleanup(func() {
		if wasSet {
			os.Setenv("IMAGE_DELIVERY", orig)
		} else {
			os.Unsetenv("IMAGE_DELIVERY")
		}
	})

	assert.Equal(t, "https://custom.delivery.test", deliveryBase())
}

func TestDeliveryBase_EmptyStringEnvTreatedAsUnset(t *testing.T) {
	// os.Setenv("", ...) with an empty value is indistinguishable from unset
	// as far as deliveryBase's len(DELIVERY) == 0 check is concerned.
	orig, wasSet := os.LookupEnv("IMAGE_DELIVERY")
	os.Setenv("IMAGE_DELIVERY", "")
	t.Cleanup(func() {
		if wasSet {
			os.Setenv("IMAGE_DELIVERY", orig)
		} else {
			os.Unsetenv("IMAGE_DELIVERY")
		}
	})

	assert.Equal(t, "https://delivery.ilovefreegle.org", deliveryBase())
}
