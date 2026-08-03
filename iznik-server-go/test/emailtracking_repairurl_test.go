package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/emailtracking"
	"github.com/stretchr/testify/assert"
)

func TestRepairDoubledSiteURL(t *testing.T) {
	cases := []struct {
		name string
		in   string
		want string
	}{
		{
			name: "doubled site from ChaseUpMail bug is stripped",
			in:   "https://www.ilovefreegle.orghttps://www.ilovefreegle.org/stories",
			want: "https://www.ilovefreegle.org/stories",
		},
		{
			name: "doubled site with http inner",
			in:   "https://www.ilovefreegle.orghttp://www.ilovefreegle.org/stories",
			want: "http://www.ilovefreegle.org/stories",
		},
		{
			name: "normal absolute URL untouched",
			in:   "https://www.ilovefreegle.org/stories",
			want: "https://www.ilovefreegle.org/stories",
		},
		{
			name: "URL embedded in query string untouched",
			in:   "https://www.ilovefreegle.org/login?next=https://www.ilovefreegle.org/stories",
			want: "https://www.ilovefreegle.org/login?next=https://www.ilovefreegle.org/stories",
		},
		{
			name: "URL embedded in path untouched",
			in:   "https://www.ilovefreegle.org/redirect/https://example.com/x",
			want: "https://www.ilovefreegle.org/redirect/https://example.com/x",
		},
		{
			name: "relative path untouched",
			in:   "/microvolunteering/message/123",
			want: "/microvolunteering/message/123",
		},
		{
			name: "empty string untouched",
			in:   "",
			want: "",
		},
		{
			name: "scheme-only host followed by fragment untouched",
			in:   "https://www.ilovefreegle.org#https://evil.example",
			want: "https://www.ilovefreegle.org#https://evil.example",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			assert.Equal(t, tc.want, emailtracking.RepairDoubledSiteURL(tc.in))
		})
	}
}
