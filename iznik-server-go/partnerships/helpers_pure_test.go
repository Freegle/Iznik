package partnerships

import (
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func sp(s string) *string { return &s }

func ff(f float64) *utils.FlexFloat64 {
	v := utils.FlexFloat64(f)

	return &v
}

func bp(b bool) *bool { return &b }

func TestCommittedStatuses(t *testing.T) {
	cases := map[string]bool{
		StatusConfirmed:   true,
		StatusPaid:        true,
		StatusOverdue:     true,
		StatusQuoted:      false,
		StatusInPrinciple: false,
		"":                false,
		"confirmed":       false,
		"Unknown":         false,
	}

	for status, want := range cases {
		assert.Equal(t, want, Committed(status), "status %q", status)
	}
}

func TestCanUseZeroUserRefusedWithoutLookup(t *testing.T) {
	assert.False(t, CanUse(0))
}

func TestCreateRequestValidate(t *testing.T) {
	cases := []struct {
		name string
		req  createRequest
		want string
	}{
		{"empty is valid", createRequest{}, ""},
		{"known status", createRequest{Status: sp(StatusPaid)}, ""},
		{"unknown status", createRequest{Status: sp("Bogus")}, "Unknown status"},
		{"empty status rejected", createRequest{Status: sp("")}, "Unknown status"},
		{"known renewal", createRequest{Renewal: sp("Likely")}, ""},
		{"empty renewal clears", createRequest{Renewal: sp("")}, ""},
		{"unknown renewal", createRequest{Renewal: sp("Maybe")}, "Unknown renewal"},
		{"contacts nil", createRequest{Contacts: nil}, ""},
		{"contacts empty", createRequest{Contacts: &[]contactRequest{}}, ""},
		{"contact blank role ok", createRequest{Contacts: &[]contactRequest{{Role: ""}}}, ""},
		{"contact good roles", createRequest{Contacts: &[]contactRequest{{Role: "Waste"}, {Role: "Finance"}, {Role: "Other"}}}, ""},
		{"contact bad role", createRequest{Contacts: &[]contactRequest{{Role: "Waste"}, {Role: "CEO"}}}, "Unknown contact role"},
		{"status checked first", createRequest{Status: sp("x"), Renewal: sp("y")}, "Unknown status"},
		{"renewal before contacts", createRequest{Renewal: sp("y"), Contacts: &[]contactRequest{{Role: "z"}}}, "Unknown renewal"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			assert.Equal(t, tc.want, tc.req.validate())
		})
	}
}

func TestSetString(t *testing.T) {
	cases := []struct {
		name string
		v    *string
		want interface{}
		set  bool
	}{
		{"nil leaves alone", nil, nil, false},
		{"empty leaves alone", sp(""), nil, false},
		{"value set", sp("x"), "x", true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			u := map[string]interface{}{}
			setString(u, "col", tc.v)
			got, ok := u["col"]
			assert.Equal(t, tc.set, ok)
			if tc.set {
				assert.Equal(t, tc.want, got)
			}
		})
	}
}

func TestSetNullableString(t *testing.T) {
	cases := []struct {
		name string
		v    *string
		want interface{}
		set  bool
	}{
		{"nil leaves alone", nil, nil, false},
		{"empty clears", sp(""), nil, true},
		{"value set", sp("x"), "x", true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			u := map[string]interface{}{}
			setNullableString(u, "col", tc.v)
			got, ok := u["col"]
			assert.Equal(t, tc.set, ok)
			if tc.set {
				assert.Equal(t, tc.want, got)
			}
		})
	}
}

func TestNullableHelpers(t *testing.T) {
	assert.Nil(t, nullableDate(nil))
	assert.Nil(t, nullableDate(sp("")))
	assert.Equal(t, "2026-04-01", nullableDate(sp("2026-04-01")))

	assert.Nil(t, nullableString(nil))
	assert.Nil(t, nullableString(sp("")))
	assert.Equal(t, "hi", nullableString(sp("hi")))

	assert.Nil(t, nullablePositive(nil))
	assert.Nil(t, nullablePositive(ff(0)))
	assert.Nil(t, nullablePositive(ff(-5)))
	assert.Equal(t, 12.5, nullablePositive(ff(12.5)))
}

func TestOrHelpers(t *testing.T) {
	assert.Equal(t, "fb", stringOr(nil, "fb"))
	assert.Equal(t, "", stringOr(sp(""), "fb"))
	assert.Equal(t, "v", stringOr(sp("v"), "fb"))

	assert.Equal(t, 1.5, floatOr(nil, 1.5))
	assert.Equal(t, 0.0, floatOr(ff(0), 1.5))
	assert.Equal(t, 9.0, floatOr(ff(9), 1.5))

	assert.True(t, boolOr(nil, true))
	assert.False(t, boolOr(bp(false), true))
	assert.True(t, boolOr(bp(true), false))
}
