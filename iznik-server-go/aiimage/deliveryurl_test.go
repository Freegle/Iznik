package aiimage

import "testing"

func TestGetDeliveryURL(t *testing.T) {
	cases := []struct {
		name, delivery, uploads, uid, want string
	}{
		{"empty uid", "", "", "", ""},
		{"defaults short uid", "", "", "abc", "https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/abc"},
		{"defaults strips 12 char prefix", "", "", "freegletusd-xyz123", "https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/xyz123"},
		{"exactly 12 chars kept", "", "", "freegletusd-", "https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/freegletusd-"},
		{"custom hosts", "https://d.example", "https://u.example/", "freegletusd-q", "https://d.example?url=https://u.example/q"},
		{"delivery with url suffix trimmed", "https://d.example?url=", "https://u.example/", "freegletusd-q", "https://d.example?url=https://u.example/q"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			t.Setenv("IMAGE_DELIVERY", c.delivery)
			t.Setenv("UPLOADS", c.uploads)
			if got := getDeliveryURL(c.uid); got != c.want {
				t.Errorf("got %q want %q", got, c.want)
			}
		})
	}
}
