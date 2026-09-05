package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
)

func TestIsNameExempt(t *testing.T) {
	tests := []struct {
		name           string
		systemrole     string
		membershipRole string
		want           bool
		missingUser    bool
	}{
		{
			name:           "ordinary_user_no_memberships",
			systemrole:     utils.SYSTEMROLE_USER,
			membershipRole: "",
			want:           false,
			missingUser:    false,
		},
		{
			name:           "systemrole_moderator",
			systemrole:     utils.SYSTEMROLE_MODERATOR,
			membershipRole: "",
			want:           true,
			missingUser:    false,
		},
		{
			name:           "systemrole_support",
			systemrole:     utils.SYSTEMROLE_SUPPORT,
			membershipRole: "",
			want:           true,
			missingUser:    false,
		},
		{
			name:           "systemrole_admin",
			systemrole:     utils.SYSTEMROLE_ADMIN,
			membershipRole: "",
			want:           true,
			missingUser:    false,
		},
		{
			name:           "user_with_owner_membership",
			systemrole:     utils.SYSTEMROLE_USER,
			membershipRole: utils.ROLE_OWNER,
			want:           true,
			missingUser:    false,
		},
		{
			name:           "user_with_moderator_membership",
			systemrole:     utils.SYSTEMROLE_USER,
			membershipRole: utils.ROLE_MODERATOR,
			want:           true,
			missingUser:    false,
		},
		{
			name:           "user_with_member_membership_not_exempt",
			systemrole:     utils.SYSTEMROLE_USER,
			membershipRole: utils.ROLE_MEMBER,
			want:           false,
			missingUser:    false,
		},
		{
			name:           "nonexistent_user",
			systemrole:     utils.SYSTEMROLE_USER,
			membershipRole: "",
			want:           false,
			missingUser:    true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			prefix := uniquePrefix(tt.name)
			var userID uint64
			var groupID uint64

			if tt.missingUser {
				userID = 999999999999
			} else {
				userID = CreateTestUser(t, prefix, tt.systemrole)
			}

			if tt.membershipRole != "" {
				groupID = CreateTestGroup(t, prefix)
				CreateTestMembership(t, userID, groupID, tt.membershipRole)
			}

			got := user.IsNameExempt(database.DBConn, userID)
			if got != tt.want {
				t.Errorf("IsNameExempt(%s) = %v, want %v", tt.name, got, tt.want)
			}
		})
	}
}
